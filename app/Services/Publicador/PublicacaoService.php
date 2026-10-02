<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\PortalUsuario;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\Payload\ItemPlano;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Publicar (E13, `09` §4–5). Duas metades:
 *
 * `iniciar()` — no clique, só banco: a última conferência tem de valer para a
 * revisão atual (RN-90, D6), avisos precisam do "Estou ciente" (`08` §3), uma
 * publicação por rascunho de cada vez. Cria a publicação e o Job.
 *
 * `executarFatia()` — no Job, em fatias de ~45 s (D9):
 *   1ª fatia: conta e modelo de novo, schema, fotos, e o plano refeito tem de
 *   ter o MESMO hash da conferência (o preço da Precificação pode ter mudado);
 *   então nasce um item por payload — menos os que já foram criados antes
 *   (RN-94: "retentar" envia só o que falta).
 *   Cada item: SENT gravado ANTES do POST (uma reentrega do Job cai na
 *   reconciliação, nunca num POST novo); 201 → CREATED com o MLB na hora
 *   (RN-92); 4xx → FAILED; timeout/5xx → UNKNOWN → reconciliação por SKU
 *   (RN-93), reenvio no máximo uma vez e só depois de `reconciliar_apos_segundos`.
 *   Depois do item: descrição (RN-72/73) e `GET /items/{id}` (E14).
 *   Fim: status da publicação e do rascunho, variantes `publicada`, e o MLB
 *   na aba Anúncios (o item da 1ª variante de cada tipo).
 *
 * D11: conta multidepósito com estoque por depósito vai por
 * `/items/multiwarehouse`; recusa 4xx (nada criado) cai no plano B — `/items`
 * comum + aviso. Payload e resposta crus de cada item ficam guardados (V11).
 */
class PublicacaoService
{
    public function __construct(
        private ConferenciaService $conferencia,
        private ContaMlService $contas,
        private CategorySchemaRepository $schemas,
        private ClienteMlPublicador $cliente,
        private ImagemAssetService $imagens,
        private EstruturaAnuncioService $anuncios,
    ) {}

    // ═══ Clique ══════════════════════════════════════════════════════════════

    public function iniciar(PubRascunho $r, AtorDoPortal $ator, bool $cienteDosAvisos = false): PubPublicacao
    {
        $piloto = (array) config('publicador.empresas_piloto', []);
        if ($piloto !== [] && ! in_array((int) $r->oferta->company_id, $piloto, true)) {
            throw new RegraViolada('PILOTO', 'O Publicador novo ainda está em teste e não publica para esta empresa.');
        }

        $p = DB::transaction(function () use ($r, $ator, $cienteDosAvisos) {
            $r = PubRascunho::whereKey($r->id)->lockForUpdate()->firstOrFail();

            if ($r->publicacoes()->where('status', PubPublicacao::RUNNING)->exists()) {
                throw new RegraViolada('RN-93', 'Este anúncio já está sendo publicado — acompanhe o andamento.');
            }

            /** @var ?PubValidacao $v */
            $v = $r->validacoes()->where('camada', 'L3')->latest('id')->first();
            if (! $v || ! in_array($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], true) || $v->revisao !== $r->revisao || ! $v->plano_hash) {
                throw new RegraViolada('RN-90', 'Confira com o Mercado Livre antes de publicar: a última conferência não vale para esta versão do anúncio.');
            }
            if ($v->resultado === ConferenciaService::AVISOS && ! $cienteDosAvisos) {
                throw new RegraViolada('RN-90', 'Há avisos do Mercado Livre: marque "Estou ciente" para publicar.');
            }

            $p = $r->publicacoes()->create([
                'revisao' => $r->revisao,
                'modelo_publicacao' => (string) $r->modelo_publicacao,
                'plano_hash' => $v->plano_hash,
                'status' => PubPublicacao::RUNNING,
                'chave_idempotencia' => (string) Str::uuid(),
                'iniciada_em' => now(),
                'ator' => self::atorParaGravar($ator),
            ]);
            $r->update(['status' => PubRascunho::PUBLISHING]);

            return $p;
        });

        PublicarRascunhoJob::dispatch($p->id);

        return $p;
    }

    // ═══ Job ═════════════════════════════════════════════════════════════════

    /** @return bool terminou (senão o Job se redespacha) */
    public function executarFatia(PubPublicacao $p, int $segundos): bool
    {
        if ($p->status !== PubPublicacao::RUNNING) {
            return true;
        }
        $r = PubRascunho::findOrFail($p->rascunho_id);
        $inicio = microtime(true);

        try {
            if ($p->conta_snapshot === null) {
                $this->prepararItens($p, $r);
                if ($p->fresh()->status !== PubPublicacao::RUNNING) {
                    return true;
                }
            }

            $conta = $p->fresh()->conta_snapshot;
            foreach ($p->itens()->whereIn('status', [PubPublicacaoItem::PENDING, PubPublicacaoItem::SENT, PubPublicacaoItem::UNKNOWN])->get() as $item) {
                if (microtime(true) - $inicio > $segundos) {
                    return false;
                }
                $this->processar($item, $r, $conta);
            }
        } catch (RegraViolada $e) {
            // Conta desconectada no meio: para tudo, nada é marcado como enviado à toa.
            $this->encerrar($p, $r, $e->getMessage());

            return true;
        }

        // Sobrou UNKNOWN esperando a hora de reconciliar: volta depois.
        if ($p->itens()->whereIn('status', [PubPublicacaoItem::PENDING, PubPublicacaoItem::SENT, PubPublicacaoItem::UNKNOWN])->exists()) {
            return false;
        }

        $this->concluir($p, $r);

        return true;
    }

    /** O Job morreu de vez: a publicação não pode ficar "publicando" para sempre. */
    public function abandonar(PubPublicacao $p, string $motivo): void
    {
        if ($p->status === PubPublicacao::RUNNING) {
            $this->encerrar($p, PubRascunho::findOrFail($p->rascunho_id), $motivo);
        }
    }

    /**
     * Os erros do ML de cada item que falhou, no campo certo da tela (`09` §3,
     * TC-80) — mapeados sobre o payload QUE FOI ENVIADO (N-17), guardado no item.
     *
     * @return list<Problema>
     */
    public function problemas(PubPublicacao $p): array
    {
        $r = PubRascunho::findOrFail($p->rascunho_id);
        try {
            $eixos = $r->eixos()->whereNotNull('attribute_id')->pluck('attribute_id')->all();
            $schema = (new ClassificadorAtributos())->classificar($this->schemas->obter((string) $r->categoria_id), new ContextoClassificacao($r->condicao, $eixos));
        } catch (RegraViolada) {
            $schema = null;
        }

        $dicionario = (array) config('publicador_erros', []);
        $saida = [];
        foreach ($p->itens()->where('status', PubPublicacaoItem::FAILED)->get() as $i) {
            $resp = new RespostaMl((int) ($i->resposta['status'] ?? 0), $i->resposta['corpo'] ?? null);
            $plano = new ItemPlano($i->indice, $i->listing_type_id, $i->variante_chave, '', (array) $i->payload, []);
            foreach ($resp->causas as $causa) {
                $saida[] = MapeadorErrosMl::problema($causa, $plano, $schema, $dicionario);
            }
            if ($resp->causas === []) {
                $saida[] = Problema::bloqueio('V-REM-01', (string) ($i->avisos['mensagem'] ?? 'O Mercado Livre recusou este item sem dizer o motivo.'), ['etapa' => 'E13', 'itens' => [$i->indice]], 'L3');
            }
        }

        return MapeadorErrosMl::agrupar($saida);
    }

    /** Reenvia a descrição de um item já criado (`09` §4: o item NÃO é recriado). */
    public function reenviarDescricao(PubPublicacaoItem $item): PubPublicacaoItem
    {
        $p = PubPublicacao::findOrFail($item->publicacao_id);
        $r = PubRascunho::findOrFail($p->rascunho_id);
        if ($item->status === PubPublicacaoItem::CREATED && $item->ml_item_id && $r->descricao) {
            $this->descricao($item, $r->oferta->company, $this->textoDaDescricao($r));
        }

        return $item->fresh();
    }

    // ═══ 1ª fatia ════════════════════════════════════════════════════════════

    private function prepararItens(PubPublicacao $p, PubRascunho $r): void
    {
        $empresa = $r->oferta->company;
        $conta = $this->contas->contexto($empresa);
        if ($conta->modelo !== $p->modelo_publicacao) {
            $this->encerrar($p, $r, 'A conta do Mercado Livre mudou de modelo de publicação. Revise o anúncio e confira de novo.');

            return;
        }

        $revalidado = $this->schemas->revalidar((string) $r->categoria_id, $r->schema_hash);
        if ($revalidado['mudou']) {
            $this->encerrar($p, $r, 'A categoria mudou no Mercado Livre desde a conferência. Revise os campos e confira de novo.');

            return;
        }

        $this->imagens->enviarPendentes($r);
        $prep = $this->conferencia->preparar($r, $revalidado['schema'], $conta, $this->conferencia->condicionaisGuardados($r));
        if ($prep['plano'] === null || $prep['plano']->hash() !== $p->plano_hash) {
            // RN-91/D6: o que se publica é o que foi conferido — preço ou título efetivo mudou no meio.
            $this->encerrar($p, $r, 'O anúncio mudou desde a conferência (preço, título ou fotos). Confira com o Mercado Livre de novo antes de publicar.');

            return;
        }

        $anteriores = $this->itensAnteriores($r, $p);
        $variantes = collect($prep['snapshot']->variantes)->keyBy('chave');

        DB::transaction(function () use ($p, $prep, $anteriores, $variantes, $conta) {
            foreach ($prep['plano']->itens as $ip) {
                $chave = $ip->listingTypeId.'|'.$ip->varianteChave;
                $antes = $anteriores[$chave] ?? null;
                if ($antes?->status === PubPublicacaoItem::CREATED) {
                    continue; // já publicado numa tentativa anterior (RN-94)
                }

                [$caminho, $payload] = $this->caminhoEPayload($ip, $conta->paraSnapshot(), (array) ($variantes[$ip->varianteChave]?->dados['estoque_depositos'] ?? []));
                $incerto = in_array($antes?->status, [PubPublicacaoItem::SENT, PubPublicacaoItem::UNKNOWN], true);
                $p->itens()->create([
                    'indice' => $ip->indice,
                    'listing_type_id' => $ip->listingTypeId,
                    'variante_chave' => $ip->varianteChave,
                    'caminho' => $caminho,
                    'payload' => $payload,
                    'payload_hash' => hash('sha256', json_encode($payload)),
                    // Envio anterior sem confirmação: reconcilia ANTES de qualquer POST novo (RN-93).
                    'status' => $incerto ? PubPublicacaoItem::UNKNOWN : PubPublicacaoItem::PENDING,
                    'tentativas' => $incerto ? $antes->tentativas : 0,
                    'enviado_em' => $incerto ? $antes->enviado_em : null,
                ]);
            }
            $p->update(['conta_snapshot' => $conta->paraSnapshot()]);
        });
    }

    /** @return array<string, PubPublicacaoItem> "tipo|variante" → o item mais recente das publicações anteriores */
    private function itensAnteriores(PubRascunho $r, PubPublicacao $atual): array
    {
        $itens = PubPublicacaoItem::query()
            ->whereIn('publicacao_id', $r->publicacoes()->where('id', '!=', $atual->id)->select('id'))
            ->orderBy('id')->get();

        $saida = [];
        foreach ($itens as $i) {
            $chave = $i->listing_type_id.'|'.$i->variante_chave;
            // Um CREATED nunca é desfeito por uma tentativa posterior.
            if (($saida[$chave] ?? null)?->status !== PubPublicacaoItem::CREATED) {
                $saida[$chave] = $i;
            }
        }

        return $saida;
    }

    /**
     * D11: com estoque por depósito numa conta multidepósito, o caminho próprio
     * com o estoque de cada um. `available_quantity` continua no corpo (sem ele,
     * 369 — N-19).
     *
     * @return array{0: string, 1: array}
     */
    private function caminhoEPayload(ItemPlano $ip, array $conta, array $porDeposito): array
    {
        $depositos = collect((array) ($conta['depositos'] ?? []))->keyBy('store_id');
        $multi = in_array('warehouse_management', (array) ($conta['tags'] ?? []), true);
        $locais = [];
        foreach ($porDeposito as $storeId => $quantidade) {
            if ((int) $quantidade > 0 && $depositos->has((string) $storeId)) {
                $locais[] = array_filter([
                    'store_id' => (string) $storeId,
                    'network_node_id' => $depositos[(string) $storeId]['network_node_id'] ?? null,
                    'quantity' => (int) $quantidade,
                ], fn ($v) => $v !== null);
            }
        }

        if (! $multi || $locais === []) {
            return ['items', $ip->payload];
        }

        return ['items_multiwarehouse', [...$ip->payload, (string) config('publicador.multideposito.campo', 'stock_locations') => $locais]];
    }

    // ═══ Um item ═════════════════════════════════════════════════════════════

    private function processar(PubPublicacaoItem $item, PubRascunho $r, array $conta): void
    {
        $empresa = $r->oferta->company;

        // SENT achado aqui = o Job anterior morreu entre gravar SENT e saber a resposta.
        if (in_array($item->status, [PubPublicacaoItem::SENT, PubPublicacaoItem::UNKNOWN], true)) {
            if (! $this->reconciliar($item, $empresa, (string) $conta['sellerId'])) {
                return;
            }
            $item->refresh();
            if ($item->status !== PubPublicacaoItem::PENDING) {
                $this->depoisDeCriar($item, $r);

                return;
            }
        }

        $this->enviar($item, $empresa);
        $item->refresh();

        if ($item->status === PubPublicacaoItem::UNKNOWN && $this->reconciliar($item, $empresa, (string) $conta['sellerId'])) {
            $item->refresh();
        }
        if ($item->status === PubPublicacaoItem::CREATED) {
            $this->depoisDeCriar($item, $r);
        }
    }

    private function enviar(PubPublicacaoItem $item, Company $empresa): void
    {
        // ANTES do POST (D9): se o processo morrer daqui em diante, o item vai para a reconciliação.
        $item->update(['status' => PubPublicacaoItem::SENT, 'enviado_em' => now(), 'tentativas' => $item->tentativas + 1]);

        $caminho = $item->caminho === 'items_multiwarehouse' ? (string) config('publicador.multideposito.caminho', '/items/multiwarehouse') : '/items';
        $resp = $this->cliente->daConta($empresa, 'POST', $caminho, corpo: $item->payload, repetir: false);

        if ($item->caminho === 'items_multiwarehouse' && ! $resp->ok() && ! $resp->podeTerCriado() && in_array($resp->classe, [RespostaMl::VALIDATION, RespostaMl::UNKNOWN_FORMAT, RespostaMl::PERMISSION], true)) {
            // Plano B (D11): a recusa 4xx não criou nada — vai pelo /items comum e avisa.
            $base = $item->payload;
            unset($base[(string) config('publicador.multideposito.campo', 'stock_locations')]);
            $item->update(['caminho' => 'plano_b', 'payload' => $base, 'payload_hash' => hash('sha256', json_encode($base)),
                'avisos' => ['plano_b' => ['status' => $resp->status, 'corpo' => $resp->corpo]]]);
            Log::warning("[Publicador] item {$item->id}: o ML recusou a criação por depósito (HTTP {$resp->status}) — plano B pelo /items.");

            $resp = $this->cliente->daConta($empresa, 'POST', '/items', corpo: $base, repetir: false);
        }

        $this->registrarResposta($item, $resp);
    }

    private function registrarResposta(PubPublicacaoItem $item, RespostaMl $resp): void
    {
        $bruta = ['status' => $resp->status, 'corpo' => $resp->corpo];
        $avisos = [...(array) $item->avisos, 'causas' => $resp->avisos()];

        if ($resp->ok() && is_array($resp->corpo) && ! empty($resp->corpo['id'])) {
            $item->update([
                'status' => PubPublicacaoItem::CREATED,
                'ml_item_id' => (string) $resp->corpo['id'],
                'ml_user_product_id' => isset($resp->corpo['user_product_id']) ? (string) $resp->corpo['user_product_id'] : null,
                'http_status' => $resp->status, 'resposta' => $bruta, 'avisos' => $avisos, 'criado_em' => now(),
            ]);
            Log::info("[Publicador] {$resp->corpo['id']} criado — publicação {$item->publicacao_id}, item {$item->indice} ({$item->listing_type_id}).");

            return;
        }

        $status = match (true) {
            $resp->podeTerCriado() => PubPublicacaoItem::UNKNOWN,
            // 429 que sobrou das tentativas: nada foi criado; volta para a fila.
            $resp->classe === RespostaMl::RATE_LIMIT => PubPublicacaoItem::PENDING,
            default => PubPublicacaoItem::FAILED,
        };
        $item->update(['status' => $status, 'http_status' => $resp->status, 'resposta' => $bruta, 'avisos' => $avisos]);
        Log::warning("[Publicador] item {$item->indice} da publicação {$item->publicacao_id} → {$status} (HTTP {$resp->status}).");
    }

    /**
     * RN-93 / `09` §5: procura o anúncio pelo SKU. Achou um criado depois do
     * envio, do mesmo tipo e da mesma família, que ninguém adotou → CREATED.
     * Não achou: reenvia UMA vez, e só depois de dar tempo à busca do ML.
     *
     * @return bool decidiu (false = ainda é cedo; tentar na próxima fatia)
     */
    private function reconciliar(PubPublicacaoItem $item, Company $empresa, string $sellerId): bool
    {
        $sku = collect((array) ($item->payload['attributes'] ?? []))->firstWhere('id', 'SELLER_SKU')['value_name'] ?? null;
        $enviado = $item->enviado_em ?? now();

        if ($sku !== null) {
            $busca = $this->cliente->daConta($empresa, 'GET', "/users/{$sellerId}/items/search", ['seller_sku' => $sku]);
            $ids = $busca->ok() && is_array($busca->corpo) ? array_map('strval', (array) ($busca->corpo['results'] ?? [])) : [];
            $adotados = $ids === [] ? [] : PubPublicacaoItem::whereIn('ml_item_id', $ids)->pluck('ml_item_id')->all();
            $candidatos = array_values(array_diff($ids, $adotados));

            if ($candidatos !== []) {
                $lote = $this->cliente->daConta($empresa, 'GET', '/items', ['ids' => implode(',', array_slice($candidatos, 0, 20))]);
                foreach (is_array($lote->corpo) ? $lote->corpo : [] as $linha) {
                    $ml = (array) ($linha['body'] ?? []);
                    if (($linha['code'] ?? null) === 200 && $this->eOMesmo($ml, $item, $enviado)) {
                        $item->update(['status' => PubPublicacaoItem::CREATED, 'ml_item_id' => (string) $ml['id'],
                            'ml_user_product_id' => isset($ml['user_product_id']) ? (string) $ml['user_product_id'] : null,
                            'resposta' => ['reconciliado' => true, 'corpo' => $ml], 'criado_em' => now()]);
                        Log::info("[Publicador] item {$item->indice} da publicação {$item->publicacao_id} reconciliado: {$ml['id']}.");

                        return true;
                    }
                }
            }
        }

        if ($enviado->gt(now()->subSeconds((int) config('publicador.reconciliar_apos_segundos', 180)))) {
            if ($item->status === PubPublicacaoItem::SENT) {
                $item->update(['status' => PubPublicacaoItem::UNKNOWN]);
            }

            return false;
        }

        if ($item->tentativas >= 2) {
            $item->update(['status' => PubPublicacaoItem::FAILED, 'avisos' => [...(array) $item->avisos,
                'mensagem' => 'Não foi possível confirmar se este anúncio foi criado. Confira no Mercado Livre antes de publicar de novo.']]);

            return true;
        }

        $item->update(['status' => PubPublicacaoItem::PENDING]);

        return true;
    }

    private function eOMesmo(array $ml, PubPublicacaoItem $item, \DateTimeInterface $enviado): bool
    {
        $criado = isset($ml['date_created']) ? \Carbon\Carbon::parse($ml['date_created']) : null;
        $familia = $ml['family_name'] ?? null;

        return ($ml['listing_type_id'] ?? null) === $item->listing_type_id
            && ($ml['category_id'] ?? null) === ($item->payload['category_id'] ?? null)
            && ($familia === null || $familia === ($item->payload['family_name'] ?? null))
            && $criado !== null && $criado->gte(\Carbon\Carbon::instance($enviado)->subMinutes(2));
    }

    private function depoisDeCriar(PubPublicacaoItem $item, PubRascunho $r): void
    {
        $empresa = $r->oferta->company;
        if ($r->descricao && $item->descricao_status !== 'OK') {
            $this->descricao($item, $empresa, $this->textoDaDescricao($r));
        }

        // E14: o estado real logo depois de criar (under_review, pausado, qualidade).
        $estado = $this->cliente->daConta($empresa, 'GET', "/items/{$item->ml_item_id}");
        if ($estado->ok() && is_array($estado->corpo)) {
            $item->update(['avisos' => [...(array) $item->fresh()->avisos, 'estado' => [
                'status' => $estado->corpo['status'] ?? null,
                'sub_status' => $estado->corpo['sub_status'] ?? [],
                'tags' => array_values(array_intersect((array) ($estado->corpo['tags'] ?? []), ['poor_quality_thumbnail', 'incomplete_technical_specs'])),
                'permalink' => $estado->corpo['permalink'] ?? null,
            ]]]);
        }
    }

    private function descricao(PubPublicacaoItem $item, Company $empresa, ?string $texto): void
    {
        if ($texto === null) {
            return;
        }
        $resp = $this->cliente->daConta($empresa, 'POST', "/items/{$item->ml_item_id}/description", corpo: ['plain_text' => $texto]);
        $item->update(['descricao_status' => $resp->ok() ? 'OK' : 'FAILED']);
        if (! $resp->ok()) {
            Log::warning("[Publicador] descrição de {$item->ml_item_id} falhou (HTTP {$resp->status}).");
        }
    }

    private function textoDaDescricao(PubRascunho $r): ?string
    {
        // A mesma normalização do plano (RN-72): texto puro.
        $limpo = trim(preg_replace("/\r\n?/", "\n", strip_tags((string) $r->descricao)));

        return $limpo === '' ? null : $limpo;
    }

    // ═══ Fim ═════════════════════════════════════════════════════════════════

    private function concluir(PubPublicacao $p, PubRascunho $r): void
    {
        $itens = $p->itens()->get();
        $criados = $itens->where('status', PubPublicacaoItem::CREATED);
        $jaExistiam = $this->itensAnteriores($r, $p);

        $status = match (true) {
            $itens->isEmpty() || $criados->count() === $itens->count() => PubPublicacao::PUBLISHED,
            $criados->isNotEmpty() || collect($jaExistiam)->contains('status', PubPublicacaoItem::CREATED) => PubPublicacao::PARTIALLY_PUBLISHED,
            default => PubPublicacao::FAILED,
        };

        $chaves = [...$criados->pluck('variante_chave')->all(), ...collect($jaExistiam)->where('status', PubPublicacaoItem::CREATED)->pluck('variante_chave')->all()];
        foreach (array_unique($chaves) as $chave) {
            $r->variantes()->where('combinacao_hash', ChaveCanonica::hash($chave))->update(['publicada' => true]);
        }

        $this->cadastrarNaRegua($p, $r, $criados->sortBy('indice'));

        $p->update(['status' => $status, 'concluida_em' => now()]);
        $r->update(['status' => [PubPublicacao::PUBLISHED => PubRascunho::PUBLISHED, PubPublicacao::PARTIALLY_PUBLISHED => PubRascunho::PARTIALLY_PUBLISHED, PubPublicacao::FAILED => PubRascunho::FAILED][$status]]);
        Log::info("[Publicador] publicação {$p->id} do rascunho {$r->id}: {$status} ({$criados->count()} de {$itens->count()} item(ns) criados).");
    }

    /** O MLB volta para a linha dele na aba Anúncios: o 1º item criado de cada tipo completa o planejado. */
    private function cadastrarNaRegua(PubPublicacao $p, PubRascunho $r, $criados): void
    {
        $ator = self::atorGravado((array) $p->ator);
        $tipoDoListing = array_flip(\App\Models\EstruturaPublicacao::LISTING_TYPES);
        // D16: sem oferta não há aba Anúncios para cadastrar (inclusive produto solto de oferta apagada — D27).
        $produto = $r->produto;
        if ($produto === null || $produto->oferta_id === null) {
            return;
        }
        $oferta = $produto->oferta;

        foreach ($criados->groupBy('listing_type_id') as $listingType => $doTipo) {
            $tipo = $tipoDoListing[$listingType] ?? null;
            if ($tipo === null || $ator === null) {
                continue;
            }
            // Idempotente: um MLB deste rascunho já na régua para este tipo basta.
            $doRascunho = PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))->whereNotNull('ml_item_id')->pluck('ml_item_id')->all();
            if ($oferta->anuncios()->where('tipo', $tipo)->whereIn('codigo_mlb', $doRascunho)->exists()) {
                continue;
            }

            $primeiro = $doTipo->first();
            try {
                $this->anuncios->cadastrar($oferta, ['tipo' => $tipo, 'codigo_mlb' => $primeiro->ml_item_id,
                    'titulo' => $primeiro->payload['family_name'] ?? null, 'status' => EstruturaAnuncio::STATUS_ATIVO], $ator);
            } catch (\Throwable $e) {
                // O item existe no ML: falhar aqui não pode desfazer nem repetir a publicação.
                $mensagem = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
                $primeiro->update(['avisos' => [...(array) $primeiro->avisos, 'regua' => "Publicado como {$primeiro->ml_item_id}, mas não entrou na aba Anúncios: {$mensagem}"]]);
                Log::error("[Publicador] {$primeiro->ml_item_id} publicado mas não cadastrado na régua — oferta {$oferta->id}: {$mensagem}");
            }
        }
    }

    private function encerrar(PubPublicacao $p, PubRascunho $r, string $motivo): void
    {
        // O que ficou SENT/UNKNOWN segue incerto: a próxima publicação reconcilia antes de reenviar.
        $p->itens()->where('status', PubPublicacaoItem::SENT)->update(['status' => PubPublicacaoItem::UNKNOWN]);
        $p->update(['status' => PubPublicacao::FAILED, 'concluida_em' => now(), 'conta_snapshot' => [...(array) $p->conta_snapshot, 'motivo' => $motivo]]);
        $this->concluirParcialSeHouver($p, $r);
        Log::warning("[Publicador] publicação {$p->id} do rascunho {$r->id} interrompida: {$motivo}");
    }

    private function concluirParcialSeHouver(PubPublicacao $p, PubRascunho $r): void
    {
        $algum = PubPublicacaoItem::whereIn('publicacao_id', $r->publicacoes()->select('id'))->where('status', PubPublicacaoItem::CREATED)->exists();
        $r->update(['status' => $algum ? PubRascunho::PARTIALLY_PUBLISHED : PubRascunho::DRAFT]);
    }

    // ═══ Quem publicou ═══════════════════════════════════════════════════════

    private static function atorParaGravar(AtorDoPortal $ator): array
    {
        return ['equipe' => $ator->equipe, 'id' => $ator->modelo->getKey(), 'nome' => $ator->nome];
    }

    private static function atorGravado(array $a): ?AtorDoPortal
    {
        if (! isset($a['id'])) {
            return null;
        }
        if (! empty($a['equipe'])) {
            $u = User::find($a['id']);

            return $u ? AtorDoPortal::daEquipe($u) : null;
        }
        $u = PortalUsuario::find($a['id']);

        return $u ? AtorDoPortal::cliente($u) : null;
    }
}
