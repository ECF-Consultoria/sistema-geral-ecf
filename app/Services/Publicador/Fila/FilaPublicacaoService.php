<?php

namespace App\Services\Publicador\Fila;

use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubValidacao;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A fila de publicação em lote de uma conta (10/10/2026, learnings publicador-ml §20): agendar os prontos,
 * pausar, retomar, cancelar, tirar um produto — e o PAINEL (progresso, próximo horário, "termina por volta de").
 * Quem anda a fila é o `AgendadorDaFila` (comando `publicador:fila-publicacao`, todo minuto).
 *
 * Regras que não se deduzem do resto:
 * - só entra o que o `iniciar()` aceitaria AGORA (`ResumoRapidoService::prontidaoDoLote`): conta liberada,
 *   conferência com o ML (L3) OK/AVISOS da versão atual, com plano; AVISOS só com "Estou ciente";
 * - o item guarda o que foi conferido (`validacao_id`, `plano_hash`, `revisao`, `ciente`) e o `digital` do que
 *   vem do Portal (título e preço efetivos) — a fila nunca publica coisa diferente;
 * - UMA fila viva por conta (`conta_ativa` unique) e um produto em UMA fila (`produto_ativo` unique): agendar
 *   de novo na mesma conta ACRESCENTA ao fim da fila viva (e o tamanho da rodada, o intervalo e a janela novos
 *   valem para ela);
 * - quem agenda é o ATOR das publicações (`criada_por` → `AtorDoPortal::daEquipe`).
 */
final class FilaPublicacaoService
{
    /** Quanto dura, em média, a publicação de um produto (só para a previsão do painel). */
    private const DURACAO_ESTIMADA_MIN = 2;

    public function __construct(private ResumoRapidoService $resumo) {}

    /** A fila viva da conta (ativa ou pausada), se houver. */
    public function viva(string $contaChave): ?PubFilaPublicacao
    {
        return PubFilaPublicacao::query()->where('conta_ativa', $contaChave)->first();
    }

    /** A fila que o painel mostra: a viva, senão a última que terminou nos últimos dias. */
    public function doPainel(string $contaChave): ?PubFilaPublicacao
    {
        return $this->viva($contaChave) ?? PubFilaPublicacao::query()->where('conta_chave', $contaChave)
            ->where('updated_at', '>=', now()->subDays((int) config('publicador.fila_publicacao.mostrar_concluida_dias', 3)))
            ->latest('id')->first();
    }

    /**
     * Agenda os produtos PRONTOS pedidos. Os outros voltam em `recusados` com o motivo.
     *
     * @param  array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?\App\Models\Company, chave: string}  $alvo
     * @param  list<int>  $produtoIds
     * @param  array{intervalo_minutos?: ?int, produtos_por_rodada?: ?int, janela_inicio?: ?string, janela_fim?: ?string, ciente?: bool}  $opcoes
     * @return array{fila: ?PubFilaPublicacao, agendados: list<int>, recusados: array<int, string>}
     */
    public function agendar(array $alvo, array $produtoIds, User $quem, array $opcoes = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $produtoIds)));
        $linhas = collect($this->resumo->linhas($alvo, $ids))->keyBy('produto_id');
        $ciente = (bool) ($opcoes['ciente'] ?? false);

        $aceitos = [];
        $recusados = [];
        foreach ($ids as $id) {
            $l = $linhas->get($id);
            if ($l === null) {
                $recusados[$id] = 'Este produto não é desta conta ou já foi publicado.';
            } elseif (! $l['pronto']) {
                $recusados[$id] = (string) $l['motivo'];
            } elseif ($l['avisos_ml'] && ! $ciente) {
                $recusados[$id] = 'O Mercado Livre deu avisos na conferência: marque "Estou ciente" para agendar.';
            } else {
                $aceitos[$id] = $l;
            }
        }
        if ($aceitos === []) {
            return ['fila' => $this->viva($alvo['chave']), 'agendados' => [], 'recusados' => $recusados];
        }

        $validacoes = PubValidacao::query()->whereIn('id', array_map(fn ($l) => (int) $l['conferencia']['id'], $aceitos))
            ->get(['id', 'rascunho_id', 'revisao', 'plano_hash', 'resultado'])->keyBy('id');

        $agendados = [];
        $fila = DB::transaction(function () use ($alvo, $quem, $opcoes, $aceitos, $validacoes, $ciente, &$agendados, &$recusados) {
            $fila = $this->vivaOuNova($alvo, $quem, $opcoes);
            $posicao = (int) $fila->itens()->max('posicao');

            foreach ($aceitos as $id => $l) {
                $v = $validacoes->get((int) $l['conferencia']['id']);
                if ($v === null || (int) $v->rascunho_id !== (int) $l['rascunho_id']) {
                    $recusados[$id] = 'A conferência deste produto não foi achada: confira de novo.';

                    continue;
                }
                try {
                    $fila->itens()->create([
                        'produto_id' => $id,
                        'rascunho_id' => $l['rascunho_id'],
                        'produto_ativo' => $id,
                        'posicao' => ++$posicao,
                        'status' => PubFilaPublicacaoItem::AGENDADO,
                        'validacao_id' => $v->id,
                        'plano_hash' => $v->plano_hash,
                        'revisao' => (int) $v->revisao,
                        'ciente' => $ciente && $l['avisos_ml'],
                        'resumo' => self::resumoDoItem($l),
                    ]);
                    $agendados[] = $id;
                } catch (QueryException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    // Corrida no `pubfilai_produto_ativo_uq`: outro clique pôs o produto numa fila agora há pouco.
                    $recusados[$id] = 'Já está na fila de publicação.';
                }
            }
            if ($fila->proximo_em === null) {
                $fila->update(['proximo_em' => now()]);
            }

            return $fila->fresh();
        });

        Log::info("[Publicador] Fila de publicação {$fila->id} da conta {$alvo['chave']}: ".count($agendados).' produto(s) agendado(s) por '.$quem->name.', '.count($recusados).' recusado(s).');

        return ['fila' => $fila, 'agendados' => $agendados, 'recusados' => $recusados];
    }

    public function pausar(PubFilaPublicacao $fila, User $quem): PubFilaPublicacao
    {
        if ($fila->status !== PubFilaPublicacao::ATIVA) {
            throw new RegraViolada('FILA-01', 'Esta fila não está andando.');
        }
        $fila->update(['status' => PubFilaPublicacao::PAUSADA, 'motivo_pausa' => mb_substr("Pausada por {$quem->name} às ".now()->format('H:i').'.', 0, 500)]);

        return $fila->fresh();
    }

    /** Volta a andar. Sem quem agendou (apagado ou inativo), quem retoma passa a ser o autor das publicações. */
    public function retomar(PubFilaPublicacao $fila, User $quem): PubFilaPublicacao
    {
        if ($fila->status !== PubFilaPublicacao::PAUSADA) {
            throw new RegraViolada('FILA-02', 'Esta fila não está pausada.');
        }
        $fila->update([
            'status' => PubFilaPublicacao::ATIVA,
            'motivo_pausa' => null,
            'criada_por' => self::autorValido($fila) !== null ? $fila->criada_por : $quem->id,
            'proximo_em' => $fila->proximo_em !== null && $fila->proximo_em->isFuture() ? $fila->proximo_em : now(),
        ]);
        $this->concluirSeVazia($fila->fresh());

        return $fila->fresh();
    }

    /** Cancela o que ainda não começou. O produto que está publicando termina (o agendador fecha o item). */
    public function cancelar(PubFilaPublicacao $fila, User $quem): PubFilaPublicacao
    {
        if (! $fila->viva()) {
            throw new RegraViolada('FILA-03', 'Esta fila já terminou.');
        }
        DB::transaction(function () use ($fila, $quem) {
            $fila->itens()->where('status', PubFilaPublicacaoItem::AGENDADO)->update([
                'status' => PubFilaPublicacaoItem::CANCELADO, 'produto_ativo' => null, 'concluido_em' => now(),
                'motivo' => mb_substr("Fila cancelada por {$quem->name}.", 0, 500), 'updated_at' => now(),
            ]);
            $fila->update(['status' => PubFilaPublicacao::CANCELADA, 'conta_ativa' => null, 'concluida_em' => now(),
                'motivo_pausa' => mb_substr("Cancelada por {$quem->name} às ".now()->format('H:i').'.', 0, 500)]);
        });
        Log::info("[Publicador] Fila de publicação {$fila->id} cancelada por {$quem->name}.");

        return $fila->fresh();
    }

    /** Tira UM produto agendado da fila (o que já começou não sai). */
    public function remover(PubFilaPublicacaoItem $item, User $quem): void
    {
        // UPDATE condicional: o agendador pode ter começado este produto agora há pouco.
        $n = PubFilaPublicacaoItem::query()->whereKey($item->id)->where('status', PubFilaPublicacaoItem::AGENDADO)->update([
            'status' => PubFilaPublicacaoItem::CANCELADO, 'produto_ativo' => null, 'concluido_em' => now(),
            'motivo' => mb_substr("Tirado da fila por {$quem->name}.", 0, 500), 'updated_at' => now(),
        ]);
        if ($n !== 1) {
            throw new RegraViolada('FILA-04', $item->fresh()?->status === PubFilaPublicacaoItem::PUBLICANDO
                ? 'Este produto já está sendo publicado e não sai mais da fila.'
                : 'Este produto já saiu da fila.');
        }
        $this->concluirSeVazia($item->fila()->first());
    }

    /** Sem nada agendado nem publicando, a fila viva termina (e a conta fica livre para outra). */
    public function concluirSeVazia(?PubFilaPublicacao $fila): void
    {
        if ($fila === null || ! $fila->viva() || $fila->itens()->whereIn('status', PubFilaPublicacaoItem::VIVOS)->exists()) {
            return;
        }
        $fila->update(['status' => PubFilaPublicacao::CONCLUIDA, 'conta_ativa' => null, 'concluida_em' => now()]);
        Log::info("[Publicador] Fila de publicação {$fila->id} da conta {$fila->conta_chave} concluída.");
    }

    // ═══ O painel ════════════════════════════════════════════════════════════

    /**
     * O aviso da fila VIVA na lista de Produtos (sem os itens): situação, quantos já foram, o próximo horário e o
     * link da tela. Sem fila viva (ou sem a tabela, entre o deploy e o `migrate`), null.
     */
    public function aviso(string $contaChave): ?array
    {
        try {
            $fila = $this->viva($contaChave);
        } catch (QueryException) {
            return null;
        }
        $painel = $this->painel($fila);
        if ($painel === null) {
            return null;
        }
        unset($painel['itens']);

        return $painel + ['url' => route('mlb.anuncios.publicador.lote.index', ['conta' => $contaChave])];
    }

    /** O que a tela mostra da fila: situação, contagens, previsão e os itens com os MLBs criados. */
    public function painel(?PubFilaPublicacao $fila): ?array
    {
        if ($fila === null) {
            return null;
        }
        $itens = $fila->itens()->get();
        $contagens = array_fill_keys([
            PubFilaPublicacaoItem::AGENDADO, PubFilaPublicacaoItem::PUBLICANDO, PubFilaPublicacaoItem::PUBLICADO, PubFilaPublicacaoItem::PARCIAL,
            PubFilaPublicacaoItem::FALHOU, PubFilaPublicacaoItem::PRECISA_REVISAR, PubFilaPublicacaoItem::CANCELADO, PubFilaPublicacaoItem::PULADO,
        ], 0);
        foreach ($itens as $i) {
            $contagens[$i->status] = ($contagens[$i->status] ?? 0) + 1;
        }
        $previstos = $this->previsao($fila, $itens);
        $total = $itens->whereNotIn('status', [PubFilaPublicacaoItem::CANCELADO])->count();
        $feitos = $contagens[PubFilaPublicacaoItem::PUBLICADO] + $contagens[PubFilaPublicacaoItem::PARCIAL];
        $andados = $total - $contagens[PubFilaPublicacaoItem::AGENDADO] - $contagens[PubFilaPublicacaoItem::PUBLICANDO];

        return [
            'id' => (int) $fila->id,
            'status' => $fila->status,
            'viva' => $fila->viva(),
            'intervalo_minutos' => (int) $fila->intervalo_minutos,
            'produtos_por_rodada' => $fila->porRodada(),
            'janela' => $fila->janela(),
            'proximo_em' => $fila->status === PubFilaPublicacao::ATIVA ? $fila->proximo_em?->toIso8601String() : null,
            'motivo_pausa' => $fila->motivo_pausa,
            'criada_por' => $fila->criadaPor?->name,
            'iniciada_em' => $fila->iniciada_em?->toIso8601String(),
            'concluida_em' => $fila->concluida_em?->toIso8601String(),
            'contagens' => $contagens,
            'progresso' => ['total' => $total, 'feitos' => $feitos, 'andados' => $andados, 'pct' => $total === 0 ? 0 : (int) floor($andados / $total * 100)],
            'termina_em' => $previstos === [] ? null : CarbonImmutable::parse(end($previstos))->addMinutes(self::DURACAO_ESTIMADA_MIN)->toIso8601String(),
            'itens' => $itens->map(fn (PubFilaPublicacaoItem $i) => [
                'id' => (int) $i->id,
                'posicao' => (int) $i->posicao,
                'produto_id' => $i->produto_id !== null ? (int) $i->produto_id : null,
                'nome' => (string) ($i->resumo['nome'] ?? ('Produto #'.$i->produto_id)),
                'sku' => (string) ($i->resumo['sku'] ?? ''),
                'anuncios' => (int) ($i->resumo['anuncios'] ?? 0),
                'status' => $i->status,
                'motivo' => $i->motivo,
                'iniciado_em' => $i->iniciado_em?->toIso8601String(),
                'concluido_em' => $i->concluido_em?->toIso8601String(),
                'previsto_em' => $previstos[$i->id] ?? null,
                'mlbs' => array_values(array_map(fn ($m) => [
                    'ml_item_id' => (string) ($m['ml_item_id'] ?? ''),
                    'listing_type_id' => (string) ($m['listing_type_id'] ?? ''),
                    'permalink' => is_string($m['permalink'] ?? null) ? $m['permalink'] : null,
                ], (array) ($i->resumo['mlbs'] ?? []))),
                'tarefa_url' => isset($i->resumo['tarefa_id'])
                    ? route('mlb.anuncios.publicador.tarefas.index', ['tarefa' => (int) $i->resumo['tarefa_id']]) : null,
                'url_editor' => $i->produto_id !== null ? route('mlb.anuncios.publicador.editor', ['produto' => $i->produto_id]) : null,
            ])->values()->all(),
        ];
    }

    /**
     * Quando cada produto agendado deve começar — o mesmo passo do agendador: as vagas que sobram na rodada em curso
     * primeiro, depois rodadas de `produtos_por_rodada`, uma a cada `intervalo_minutos`, no máximo
     * `teto_inicios_por_minuto` inícios por minuto, só dentro da janela. É estimativa: não sabe quanto cada publicação
     * demora (a rodada nova espera a anterior terminar) nem quanto do teto as outras contas vão usar. Fila pausada não
     * tem previsão.
     *
     * @return array<int, string> item_id → ISO
     */
    public function previsao(PubFilaPublicacao $fila, $itens): array
    {
        if ($fila->status !== PubFilaPublicacao::ATIVA) {
            return [];
        }
        $intervalo = max(1, (int) $fila->intervalo_minutos);
        $porRodada = $fila->porRodada();
        $teto = max(1, (int) config('publicador.fila_publicacao.teto_inicios_por_minuto', 2));
        $agora = CarbonImmutable::now();
        $agendados = $itens->where('status', PubFilaPublicacaoItem::AGENDADO)->sortBy([['posicao', 'asc'], ['id', 'asc']])->values();
        $total = $agendados->count();
        $quando = fn (CarbonImmutable $inicioDaRodada, int $k) => AgendadorDaFila::proximoNaJanela($fila, $inicioDaRodada->addMinutes(intdiv($k, $teto)))->toIso8601String();

        $saida = [];
        $i = 0;
        if (AgendadorDaFila::rodadaEmCurso($fila, $agora)) {
            $vagas = max(0, $porRodada - (int) $fila->rodada_inicios);
            for ($k = 0; $k < $vagas && $i < $total; $k++, $i++) {
                $saida[(int) $agendados[$i]->id] = $quando($agora, $k);
            }
        }
        $inicio = $fila->proximo_em !== null && $fila->proximo_em->greaterThan($agora) ? CarbonImmutable::instance($fila->proximo_em) : $agora;
        while ($i < $total) {
            $rodada = AgendadorDaFila::proximoNaJanela($fila, $inicio);
            for ($k = 0; $k < $porRodada && $i < $total; $k++, $i++) {
                $saida[(int) $agendados[$i]->id] = $quando($rodada, $k);
            }
            $inicio = $rodada->addMinutes($intervalo);
        }

        return $saida;
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /**
     * Quem publica em nome da fila: quem agendou, se ainda existe (o `User` usa SoftDeletes) e está ativo.
     * Null = a fila não anda até alguém retomá-la (e assumir as publicações).
     */
    public static function autorValido(PubFilaPublicacao $fila): ?User
    {
        $u = $fila->criada_por !== null ? User::query()->find($fila->criada_por) : null;

        return $u !== null && $u->active !== false ? $u : null;
    }

    /** A fila viva da conta, travada; sem ela, nasce uma (corrida no unique = a do outro clique). */
    private function vivaOuNova(array $alvo, User $quem, array $opcoes): PubFilaPublicacao
    {
        $config = array_filter([
            'intervalo_minutos' => isset($opcoes['intervalo_minutos']) ? (int) $opcoes['intervalo_minutos'] : null,
            'produtos_por_rodada' => isset($opcoes['produtos_por_rodada']) ? max(1, (int) $opcoes['produtos_por_rodada']) : null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('janela_inicio', $opcoes) || array_key_exists('janela_fim', $opcoes)) {
            $inicio = PubFilaPublicacao::horaCurta($opcoes['janela_inicio'] ?? null);
            $fim = PubFilaPublicacao::horaCurta($opcoes['janela_fim'] ?? null);
            $config['janela_inicio'] = $inicio !== null && $fim !== null && $inicio !== $fim ? "{$inicio}:00" : null;
            $config['janela_fim'] = $inicio !== null && $fim !== null && $inicio !== $fim ? "{$fim}:00" : null;
        }

        $viva = PubFilaPublicacao::query()->where('conta_ativa', $alvo['chave'])->lockForUpdate()->first();
        if ($viva !== null) {
            if ($config !== []) {
                $viva->update($config);
            }

            return $viva;
        }

        try {
            return DB::transaction(fn () => PubFilaPublicacao::create([
                'conta_chave' => $alvo['chave'],
                'conta_ativa' => $alvo['chave'],
                'company_id' => $alvo['company']?->id,
                'mlb_empresa_id' => $alvo['mlb_empresa']?->id,
                'status' => PubFilaPublicacao::ATIVA,
                'intervalo_minutos' => (int) config('publicador.fila_publicacao.intervalo_minutos', 20),
                'produtos_por_rodada' => max(1, (int) config('publicador.fila_publicacao.produtos_por_rodada', 5)),
                'criada_por' => $quem->id,
                'proximo_em' => now(),
                ...$config,
            ]));
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $viva = PubFilaPublicacao::query()->where('conta_ativa', $alvo['chave'])->lockForUpdate()->firstOrFail();
            if ($config !== []) {
                $viva->update($config);
            }

            return $viva;
        }
    }

    /** O que o painel precisa do produto, congelado no agendamento (e o `digital` que a fila confere na hora). */
    private static function resumoDoItem(array $l): array
    {
        return [
            'nome' => $l['nome'],
            'sku' => $l['sku'],
            'anuncios' => (int) $l['anuncios'],
            'variacoes' => (int) $l['variacoes'],
            'titulos' => array_map(fn ($t) => $t['texto'] ?? null, (array) $l['titulos']),
            'precos' => array_map(fn ($p) => ['min' => $p['min'] ?? null, 'max' => $p['max'] ?? null], (array) $l['precos']),
            'digital' => $l['digital'],
        ];
    }
}
