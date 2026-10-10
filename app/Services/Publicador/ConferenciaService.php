<?php

namespace App\Services\Publicador;

use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\Payload\PayloadPlan;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Validacao\ContextoValidacao;
use App\Support\Publicador\Validacao\Problema;
use App\Support\Publicador\Validacao\ResultadoValidacao;
use App\Support\Publicador\Validacao\ValidadorRascunho;
use App\Support\Publicador\Variacao\Eixo;

/**
 * A conferência com o Mercado Livre — L3 (`08` §4), na ordem da spec:
 *
 *   1. conta lida de novo (token renovado se preciso) e modelo igual ao do
 *      rascunho (V-ACC-01, V-ACC-02); conta não liberada ou sem token ativo
 *      confere só local (D26, WR-B04);
 *   2. schema revalidado (V-CAT-03);
 *   3. fotos pendentes sobem ANTES — assim o validate também confere as fotos;
 *   4. L1/L2 sem bloqueio, senão para aqui (economiza chamadas e dá mensagem melhor);
 *   5. `/attributes/conditional` com o payload da 1ª variante ativa (H-08);
 *      o que ele exigir e faltar vira V-ATT-08;
 *   6. tipos de anúncio disponíveis para a conta na categoria (V-SAL-01);
 *   7. SKU já usado em outro anúncio ativo da conta (V-REM-02, aviso);
 *   8. `POST /items/validate` para CADA item do PayloadPlan (V-REM-01).
 *
 * Grava UM `pub_validacoes` por conferência, com a revisão, o `plano_hash`
 * (D6: a publicação exige o mesmo plano) e as respostas brutas do ML. Aprovado
 * = nenhuma causa `type=error` — o validate devolve 400 mesmo só com avisos
 * (N-16, 01/10).
 *
 * Nunca publica: o único POST de item que sai daqui é o `/items/validate`.
 */
class ConferenciaService
{
    public const OK = 'OK';
    public const AVISOS = 'AVISOS';
    public const BLOQUEADO = 'BLOQUEADO';
    public const ERRO = 'ERRO';

    /**
     * Conferência só local (D26): a conta não está liberada, então nada foi ao Mercado Livre.
     * Não libera publicar — `prontidao()` e `PublicacaoService::iniciar()` só aceitam OK/AVISOS.
     */
    public const LOCAL = 'LOCAL';

    /** Status que a conferência aprovada pode virar VALIDATED; publicando/publicado nunca (WR-B03). */
    private const PODE_FICAR_VALIDADO = [PubRascunho::DRAFT, PubRascunho::VALIDATED, PubRascunho::FAILED];

    /** Classes de resposta que impedem concluir a conferência (o ML não respondeu de fato). */
    private const SEM_RESPOSTA = [RespostaMl::SERVER, RespostaMl::NETWORK, RespostaMl::RATE_LIMIT, RespostaMl::AUTH];

    public function __construct(
        private RascunhoRepository $repo,
        private ContaMlService $contas,
        private CategorySchemaRepository $schemas,
        private ClienteMlPublicador $cliente,
        private ImagemAssetService $imagens,
        private DadosEfetivosService $efetivos,
    ) {}

    public function conferir(PubRascunho $r): PubValidacao
    {
        $revisao = $r->revisao;
        $brutas = [];

        try {
            // 1. Conta e modelo. D26: conta não liberada não recebe nada nosso — nem validate, nem
            // foto, nem as leituras da conferência; só a conferência local contra o schema da categoria.
            // WR-B04: sem token ativo é o mesmo caso (nenhuma chamada); o V-ACC-01 — reconectar — é
            // exigido ao publicar (`PublicacaoService::iniciar`), não aqui.
            $ancora = $r->produto->contaOuNula();
            if ($ancora === null || ! ContasLiberadas::libera($ancora)) {
                return $this->conferirLocal($r, $revisao, $ancora === null ? 'V-ACC-01' : 'CONTA-LIB');
            }

            $conta = $this->contas->contexto($ancora);
            $brutas['conta'] = $conta->paraSnapshot();
            if ($r->modelo_publicacao !== null && $r->modelo_publicacao !== $conta->modelo) {
                return $this->gravar($r, $revisao, self::BLOQUEADO, [Problema::bloqueio('V-ACC-02',
                    'A conta do Mercado Livre mudou de modelo de publicação desde o rascunho. Revise as variações antes de conferir de novo.', ['etapa' => 'E0'], 'L3')], $brutas);
            }
            $r->update(['modelo_publicacao' => $conta->modelo, 'conta_checada_em' => now()]);

            // 2. Schema.
            if (! $r->categoria_id) {
                return $this->gravar($r, $revisao, self::BLOQUEADO, [Problema::bloqueio('V-CAT-01', 'Escolha a categoria do produto.', ['etapa' => 'E2'], 'L3')], $brutas);
            }
            $categoria = $this->schemas->revalidar($r->categoria_id, $r->schema_hash)['schema'];

            // 3. Fotos que ainda não subiram.
            $this->imagens->enviarPendentes($r);

            // 4. L1/L2 com os condicionais que já se conhecem.
            $guardados = $this->condicionaisGuardados($r);
            $prep = $this->preparar($r, $categoria, $conta, $guardados);
            if ($prep['l2']->temBloqueio()) {
                return $this->gravar($r, $revisao, self::BLOQUEADO, $prep['l2']->problemas, $brutas);
            }

            // 5. Condicionais (H-08: o payload da primeira variante ativa).
            $cond = $this->perguntarCondicionais($r, $prep['plano']);
            $brutas['condicionais'] = $cond['bruta'];
            if ($cond['ids'] !== null && array_diff($cond['ids'], $guardados) !== []) {
                $prep = $this->preparar($r, $categoria, $conta, $cond['ids']);
                if ($prep['l2']->temBloqueio()) {
                    return $this->gravar($r, $revisao, self::BLOQUEADO, self::comoCondicional($prep['l2']->problemas, $cond['ids']), $brutas);
                }
            }
            $problemas = $prep['l2']->problemas;

            // 6. Tipos de anúncio disponíveis.
            $tipos = $this->cliente->daConta($ancora, 'GET', "/users/{$conta->sellerId}/available_listing_types", ['category_id' => $r->categoria_id]);
            $brutas['tipos'] = ['status' => $tipos->status, 'corpo' => $tipos->corpo];
            $problemas = [...$problemas, ...self::tiposIndisponiveis($tipos, $prep['snapshot'])];
            if ((new ResultadoValidacao($problemas))->temBloqueio()) {
                return $this->gravar($r, $revisao, self::BLOQUEADO, $problemas, $brutas);
            }

            // 7. SKU repetido em outro anúncio da conta.
            $problemas = [...$problemas, ...$this->skusEmOutrosAnuncios($r, $conta->sellerId, $prep['plano'], $prep['mlbs'], $brutas)];

            // 8. Um validate por item.
            $doMl = [];
            $dicionario = (array) config('publicador_erros', []);
            foreach ($prep['plano']->itens as $item) {
                $resp = $this->cliente->daConta($ancora, 'POST', '/items/validate', corpo: $item->payload);
                $brutas['itens'][] = ['indice' => $item->indice, 'listing_type' => $item->listingTypeId, 'variante' => $item->varianteChave,
                    'payload' => $item->payload, 'status' => $resp->status, 'corpo' => $resp->corpo];

                if (in_array($resp->classe, self::SEM_RESPOSTA, true)) {
                    return $this->gravar($r, $revisao, self::ERRO, [Problema::bloqueio('V-REM-01',
                        'O Mercado Livre não respondeu à conferência. Tente de novo em instantes.', ['etapa' => 'E11'], 'L3')], $brutas, $prep['plano']);
                }
                if ($resp->classe === RespostaMl::PERMISSION && $resp->causas === []) {
                    $doMl[] = Problema::bloqueio('V-REM-01', 'O Mercado Livre não permite esta publicação nesta conta.', ['etapa' => 'E0', 'itens' => [$item->indice]], 'L3');
                }
                foreach ($resp->causas as $causa) {
                    if (! MapeadorErrosMl::ehRuido($causa)) {
                        $doMl[] = MapeadorErrosMl::problema($causa, $item, $prep['schema'], $dicionario);
                    }
                }
            }
            $problemas = [...$problemas, ...MapeadorErrosMl::agrupar($doMl)];

            $resultado = new ResultadoValidacao($problemas);
            $status = $resultado->temBloqueio() ? self::BLOQUEADO
                : (array_filter($problemas, fn (Problema $p) => $p->severidade === Problema::AVISO) ? self::AVISOS : self::OK);
            $validacao = $this->gravar($r, $revisao, $status, $problemas, $brutas, $prep['plano']);

            // Só marca VALIDATED se ninguém editou enquanto o ML respondia — e NUNCA rebaixa um
            // rascunho publicando/publicado/parcialmente publicado (WR-B03: "conferir" de um anúncio
            // no ar o devolvia a VALIDATED e o abria à IA). Um UPDATE condicional só: sem corrida
            // com o clique em Publicar nem com a edição.
            if ($status !== self::BLOQUEADO) {
                $marcou = PubRascunho::whereKey($r->id)->where('revisao', $revisao)
                    ->whereIn('status', self::PODE_FICAR_VALIDADO)
                    ->update(['status' => PubRascunho::VALIDATED, 'updated_at' => now()]);
                if ($marcou > 0) {
                    $r->status = PubRascunho::VALIDATED;
                    $r->syncOriginalAttribute('status');
                }
            }

            return $validacao;
        } catch (RegraViolada $e) {
            $etapa = ['V-ACC-01' => 'E0', 'D10' => 'E0', 'V-CAT-03' => 'E2', 'H-04' => 'E3'][$e->regra] ?? 'E11';

            return $this->gravar($r, $revisao, self::ERRO, [Problema::bloqueio($e->regra, $e->getMessage(), ['etapa' => $etapa], 'L3')], $brutas);
        }
    }

    /**
     * D26: a conferência de uma conta NÃO liberada — ou sem token ativo (WR-B04). Só L1/L2 contra
     * o schema da categoria (leitura pública, token do app) — nenhuma chamada com o token do
     * cliente. Fica gravada como camada L2 com resultado LOCAL/BLOQUEADO e nunca deixa o rascunho
     * VALIDATED.
     *
     * @param  string  $motivo  `CONTA-LIB` (fora da lista) ou `V-ACC-01` (sem token ativo), em `respostas_ml.motivo`
     */
    private function conferirLocal(PubRascunho $r, int $revisao, string $motivo = 'CONTA-LIB'): PubValidacao
    {
        $brutas = ['local' => true, 'motivo' => $motivo];

        if (! $r->categoria_id) {
            return $this->gravar($r, $revisao, self::BLOQUEADO, [Problema::bloqueio('V-CAT-01', 'Escolha a categoria do produto.', ['etapa' => 'E2'], 'L2')], $brutas, null, 'L2');
        }

        try {
            $categoria = $this->schemas->revalidar($r->categoria_id, $r->schema_hash)['schema'];
        } catch (RegraViolada $e) {
            return $this->gravar($r, $revisao, self::ERRO, [Problema::bloqueio($e->regra, $e->getMessage(), ['etapa' => 'E2'], 'L2')], $brutas, null, 'L2');
        }

        // A conta que a abertura já leu; sem ela, os mesmos padrões do estado da tela.
        $lida = (array) ($r->step_state['conta'] ?? []);
        $conta = new ContextoConta(
            sellerId: (string) ($lida['sellerId'] ?? ''),
            modelo: (string) ($r->modelo_publicacao ?? $lida['modelo'] ?? MontadorDePlano::UP),
            tags: (array) ($lida['tags'] ?? []),
            modosEnvio: $lida['modosEnvio'] ?? null,
            depositos: $lida['depositos'] ?? null,
            lidaEm: (string) ($lida['lidaEm'] ?? now()->toIso8601String()),
        );

        ['snapshot' => $snapshot] = $this->comEfetivos($r);
        $schema = $this->classificar($categoria, $snapshot, $this->condicionaisGuardados($r));
        // Fotos que não sobem de propósito não bloqueiam (V-IMG-08 só vale ao publicar).
        $l2 = (new ValidadorRascunho())->validar($snapshot, $schema, $this->contextoValidacao($r, $conta, $schema, paraPublicar: false));

        return $this->gravar($r, $revisao, $l2->temBloqueio() ? self::BLOQUEADO : self::LOCAL, $l2->problemas, $brutas, null, 'L2');
    }

    /**
     * Só o `/attributes/conditional`, para a tela chamar com espera de
     * digitação ao concluir E3 ou mudar um atributo de produto (`03` §6).
     * Guarda a resposta com a revisão (TC-33).
     *
     * @return list<string>|null  ids exigidos; nulo = o ML não respondeu (nada muda)
     */
    public function consultarCondicionais(PubRascunho $r): ?array
    {
        if (! $r->categoria_id) {
            return [];
        }
        // D26: o `/attributes/conditional` vai com o payload e o token do cliente; conta não liberada = nada muda.
        if (! ContasLiberadas::libera($r->produto->contaOuNula())) {
            return null;
        }

        try {
            $categoria = $this->schemas->obter($r->categoria_id);
            $snapshot = $this->comEfetivos($r)['snapshot'];
            $schema = $this->classificar($categoria, $snapshot, $this->condicionaisGuardados($r));
            $plano = MontadorDePlano::montar($snapshot, $schema, $r->modelo_publicacao ?? MontadorDePlano::UP, $this->repo->fotosNoMl($r));
        } catch (RegraViolada) {
            return null;
        }

        return $this->perguntarCondicionais($r, $plano)['ids'];
    }

    /**
     * Rascunho + efetivos + schema classificado + L1/L2 + plano: o mesmo
     * caminho para conferir e para publicar (RN-91). O plano só é montado
     * quando a L2 passa — sem isso o montador pode recusar (D10, H-04).
     *
     * @param  list<string>  $condicionais
     * @return array{snapshot: RascunhoSnapshot, schema: SchemaClassificado, l2: ResultadoValidacao, plano: ?PayloadPlan, mlbs: list<string>}
     */
    public function preparar(PubRascunho $r, CategorySchema $categoria, ContextoConta $conta, array $condicionais): array
    {
        ['snapshot' => $snapshot, 'mlbs' => $mlbs] = $this->comEfetivos($r);
        $schema = $this->classificar($categoria, $snapshot, $condicionais);

        $l2 = (new ValidadorRascunho())->validar($snapshot, $schema, $this->contextoValidacao($r, $conta, $schema));
        $plano = $l2->temBloqueio() ? null : MontadorDePlano::montar($snapshot, $schema, $conta->modelo, $this->repo->fotosNoMl($r));

        return ['snapshot' => $snapshot, 'schema' => $schema, 'l2' => $l2, 'plano' => $plano, 'mlbs' => $mlbs];
    }

    /** @return list<string> */
    public function condicionaisGuardados(PubRascunho $r): array
    {
        return array_values((array) ($r->step_state['condicionais']['ids'] ?? []));
    }

    // ═══ Passos ══════════════════════════════════════════════════════════════

    /**
     * `comEfetivosDe` (10/10/2026): leva junto o mínimo da promoção e a marca de preço do Portal sem
     * frete — é por aqui que a conferência e a publicação (`preparar`) aplicam o V-SAL-08.
     *
     * @return array{snapshot: RascunhoSnapshot, mlbs: list<string>}
     */
    private function comEfetivos(PubRascunho $r): array
    {
        $e = $this->efetivos->daProduto($r->produto);

        return ['snapshot' => $this->repo->snapshot($r)->comEfetivosDe($e), 'mlbs' => $e['mlbs']];
    }

    private function classificar(CategorySchema $categoria, RascunhoSnapshot $s, array $condicionais): SchemaClassificado
    {
        $eixos = array_values(array_filter(array_map(fn (Eixo $e) => $e->attributeId(), $s->eixos)));

        return (new ClassificadorAtributos())->classificar($categoria, new ContextoClassificacao($s->condicao, $eixos, $condicionais));
    }

    private function contextoValidacao(PubRascunho $r, ContextoConta $conta, SchemaClassificado $schema, bool $paraPublicar = true): ContextoValidacao
    {
        return new ContextoValidacao(
            modelo: $conta->modelo,
            tagsDaConta: $conta->tags,
            modosEnvio: $conta->modosEnvio,
            imagens: $this->repo->metadadosDasImagens($r),
            paraPublicar: $paraPublicar,
            plausibilidade: (array) (config('publicador.plausibilidade')[$schema->dominio] ?? []),
            termosProibidosTitulo: (array) config('publicador.termos_proibidos_titulo', ContextoValidacao::TERMOS_PROIBIDOS_TITULO),
            hashSchemaDoRascunho: $r->schema_hash,
            limiteFamilyName: config('publicador.limite_family_name'),
            avisarAcimaDeItens: (int) config('publicador.avisar_acima_de_itens', 20),
        );
    }

    /** @return array{ids: ?list<string>, bruta: ?array} */
    private function perguntarCondicionais(PubRascunho $r, ?PayloadPlan $plano): array
    {
        $primeiro = $plano?->itens[0] ?? null;
        if ($primeiro === null) {
            return ['ids' => null, 'bruta' => null];
        }

        $resp = $this->cliente->daConta($r->conta(), 'POST', "/categories/{$r->categoria_id}/attributes/conditional", corpo: $primeiro->payload);
        $bruta = ['status' => $resp->status, 'corpo' => $resp->corpo];
        if (! $resp->ok() || ! is_array($resp->corpo)) {
            // Sem resposta, vale o que se sabia: o validate ainda confere tudo.
            return ['ids' => null, 'bruta' => $bruta];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($a) => is_array($a) ? ($a['id'] ?? null) : null, (array) ($resp->corpo['required_attributes'] ?? []),
        ))));
        $r->update(['step_state' => [...(array) $r->step_state, 'condicionais' => ['revisao' => $r->revisao, 'ids' => $ids, 'em' => now()->toIso8601String()]]]);

        return ['ids' => $ids, 'bruta' => $bruta];
    }

    /**
     * O obrigatório que só passou a valer pelo `/attributes/conditional` é
     * V-ATT-08 (`08`), com o motivo dito — senão a pessoa não entende por que
     * o campo virou obrigatório agora.
     *
     * @param  list<Problema>  $problemas
     * @return list<Problema>
     */
    private static function comoCondicional(array $problemas, array $condicionais): array
    {
        return array_map(fn (Problema $p) => $p->regra === 'V-ATT-01' && in_array($p->alvo['atributo'] ?? null, $condicionais, true)
            ? new Problema('V-ATT-08', Problema::BLOQUEIO, $p->mensagem.' O Mercado Livre passou a exigir este campo para este produto.', 'L3', $p->alvo)
            : $p, $problemas);
    }

    /** @return list<Problema> V-SAL-01 por tipo ativo que a conta não pode usar nesta categoria */
    private static function tiposIndisponiveis(RespostaMl $tipos, RascunhoSnapshot $s): array
    {
        if (! $tipos->ok() || ! is_array($tipos->corpo) || ! isset($tipos->corpo['available'])) {
            return []; // Sem a lista, o validate de cada item ainda recusa o tipo que não servir.
        }

        $disponiveis = array_column((array) $tipos->corpo['available'], 'id');
        $nomes = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];
        $problemas = [];
        foreach ($s->alvosAtivos() as $alvo) {
            if (! in_array($alvo->listingTypeId, $disponiveis, true)) {
                $nome = $nomes[$alvo->listingTypeId] ?? $alvo->listingTypeId;
                $problemas[] = Problema::bloqueio('V-SAL-01', "Esta conta não pode publicar como {$nome} nesta categoria.",
                    ['etapa' => 'E10', 'campo' => 'tipo', 'listing_type' => $alvo->listingTypeId], 'L3');
            }
        }

        return $problemas;
    }

    /**
     * V-REM-02 [HIP·H-19, confirmada]: o SKU já está em OUTRO anúncio ativo
     * da conta. Os anúncios que a régua conhece desta oferta e os que este
     * rascunho já criou não contam — o par Clássico + Premium divide o SKU.
     *
     * @return list<Problema>
     */
    private function skusEmOutrosAnuncios(PubRascunho $r, string $sellerId, PayloadPlan $plano, array $mlbsDaOferta, array &$brutas): array
    {
        $proprios = array_flip([...$mlbsDaOferta, ...PubPublicacaoItem::query()
            ->whereIn('publicacao_id', $r->publicacoes()->select('id'))->whereNotNull('ml_item_id')->pluck('ml_item_id')->all()]);

        $skus = [];
        foreach ($plano->itens as $item) {
            foreach ((array) ($item->payload['attributes'] ?? []) as $a) {
                if (($a['id'] ?? null) === 'SELLER_SKU' && trim((string) ($a['value_name'] ?? '')) !== '') {
                    $skus[trim((string) $a['value_name'])][] = $item->varianteChave;
                }
            }
        }

        $problemas = [];
        foreach ($skus as $sku => $variantes) {
            $resp = $this->cliente->daConta($r->conta(), 'GET', "/users/{$sellerId}/items/search", ['seller_sku' => $sku, 'status' => 'active']);
            $brutas['skus'][$sku] = ['status' => $resp->status, 'corpo' => $resp->corpo];
            if (! $resp->ok() || ! is_array($resp->corpo)) {
                continue;
            }
            $outros = array_values(array_filter((array) ($resp->corpo['results'] ?? []), fn ($mlb) => ! isset($proprios[(string) $mlb])));
            if ($outros !== []) {
                $problemas[] = Problema::aviso('V-REM-02', "O SKU «{$sku}» já está em outro anúncio ativo desta conta (".implode(', ', array_slice($outros, 0, 3)).'). Confira se não é o mesmo produto.',
                    ['etapa' => 'E5', 'campo' => 'sku', 'variante' => $variantes[0]], 'L3');
            }
        }

        return $problemas;
    }

    /** @param list<Problema> $problemas */
    private function gravar(PubRascunho $r, int $revisao, string $resultado, array $problemas, array $brutas, ?PayloadPlan $plano = null, string $camada = 'L3'): PubValidacao
    {
        return $r->validacoes()->create([
            'revisao' => $revisao,
            'camada' => $camada,
            'plano_hash' => $plano?->hash(),
            'resultado' => $resultado,
            'issues' => array_map(fn (Problema $p) => [
                'regra' => $p->regra, 'severidade' => $p->severidade, 'mensagem' => $p->mensagem,
                'camada' => $p->camada, 'alvo' => $p->alvo, 'ml_causa' => $p->mlCausa,
            ], $problemas),
            'respostas_ml' => $brutas,
        ]);
    }
}
