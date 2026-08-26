<?php

namespace App\Console\Commands;

use App\Models\BonusFaixa;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsImputedAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fase 116 · Plano 06 — comando de OPERAÇÃO da regra "NPS não respondido
 * conta como nota mínima (1)": materializa em lote (reusa
 * `NpsImputationService::materializarLote`), produz o relatório de impacto
 * ANTES/DEPOIS por pessoa e competência (requisito D1), reconsolida o
 * registro AUTORITATIVO do bônus (`DesempenhoScoreSnapshot`, via reuso de
 * `desempenho:consolidar-mes`) das competências efetivamente backfilladas, e
 * CONFERE por re-consulta se o congelamento de fato aconteceu — nunca confia
 * na saída agregada do comando reconsolidado.
 *
 * ─── Por que a reconsolidação é parte do backfill, não passo opcional ────
 * Em competências FECHADAS, o ranking `/performance`, o widget do dashboard,
 * o Relatório de Bonificação e a auditoria de bônus leem o registro CONGELADO
 * (`DesempenhoScoreSnapshot` mensal) — nunca `computeCached()`. Bumpar cache
 * (Plan 116-02, v11→v12) só muda o cálculo AO VIVO (mês aberto). Sem
 * reconsolidar, o operador aprovaria um relatório antes/depois que não mudaria
 * NADA nas telas oficiais. Por isso este comando SEMPRE reconsolida (nunca é
 * opcional) as competências financeiras (competência do disparo NPS MENOS 1
 * mês, NPSWIN-03) efetivamente backfilladas — reusando
 * `Artisan::call('desempenho:consolidar-mes', ['--mes' => 'YYYY-MM'])`, NUNCA
 * reimplementando a gravação direta do snapshot (o `updateOrCreate` interno
 * daquele comando) nem o gate de margem FIXMARG-03 (Fase 110).
 *
 * ─── Por que "reconsolidar" ≠ "ter reconsolidado" (NPSFLOOR-08c) ──────────
 * O relatório antes/depois é calculado com `compute()` PURO, sem passar pelo
 * gate de margem `FIXMARG-03` de `ConsolidarMesDesempenho`. Se a amostra de
 * margem de alguém estiver degradada no momento da reconsolidação (rate-limit
 * Adman), a persistência daquela pessoa é RECUSADA e o congelado permanece
 * com o número pré-backfill — o operador acharia ter aprovado a mudança para
 * todo mundo. A saída do `desempenho:consolidar-mes` só carrega uma CONTAGEM
 * agregada de amostras rejeitadas, sem nomes — por isso este comando NUNCA
 * parseia essa saída: depois de reconsolidar, RE-CONSULTA o
 * `DesempenhoScoreSnapshot` de cada (pessoa, competência) do relatório e
 * NOMEIA as divergências.
 *
 * @see app/Services/Nps/NpsImputationService.php
 * @see app/Console/Commands/ConsolidarMesDesempenho.php
 * @see .planning/phases/116-.../116-06-PLAN.md
 */
class NpsMaterializarNaoRespondidos extends Command
{
    protected $signature = 'nps:materializar-nao-respondidos
        {--dry-run : Só mostra o impacto, sem gravar}
        {--force : Pula a confirmação interativa}
        {--mes= : Restringe a uma competência YYYY-MM (competência do DISPARO do NPS)}
        {--desfazer : Apaga as linhas materializadas da competência informada (rollback)}';

    protected $description = 'Materializa NPS não respondido como nota mínima (1) — relatório antes/depois, reconsolidação verificada do snapshot mensal do bônus e rollback (Fase 116).';

    /**
     * Marcador fixo da mensagem de conferência — usado no comando E no teste
     * (`NpsMaterializarNaoRespondidosCommandTest`). Nunca reformular um sem
     * atualizar o outro.
     */
    private const MARCADOR_SNAPSHOT_NAO_ATUALIZADO = 'snapshot NÃO foi atualizado';

    /** Marcador de controle usado SÓ internamente para desfazer a transação de cálculo do relatório. */
    private const ROLLBACK_MARKER = '__NPS_MATERIALIZAR_IMPACTO_ROLLBACK__';

    public function __construct(
        private NpsImputationService $imputationService,
        private DesempenhoScoreService $scoreService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesOption = $this->option('mes');
        $mes = null;

        if ($mesOption) {
            if (! preg_match('/^\d{4}-\d{2}$/', (string) $mesOption)) {
                $this->error("[NPS Imputação] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }

            try {
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption . '-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[NPS Imputação] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");

                return self::FAILURE;
            }
        }

        if ($this->option('desfazer')) {
            return $this->desfazer($mes, (bool) $this->option('force'));
        }

        return $this->fluxoPadrao($mes, (bool) $this->option('dry-run'), (bool) $this->option('force'));
    }

    // ═══ Fluxo padrão (materialização + relatório + reconsolidação) ════════

    private function fluxoPadrao(?Carbon $mes, bool $dryRun, bool $force): int
    {
        $this->info('[NPS Imputação] Analisando surveys não respondidos' . ($dryRun ? ' (DRY-RUN)' : '') . '...');

        // Passo 1 — preview SEMPRE read-only.
        $preview = $this->imputationService->materializarLote($mes, dryRun: true);
        $this->exibirDiff($preview['detalhe']);
        $this->exibirStats($preview);

        $totalMudancas = $preview['criados'] + $preview['promovidos_definitivo'] + $preview['removidos'];
        if ($totalMudancas === 0) {
            $this->info('[NPS Imputação] Nada a materializar — nenhum snapshot foi reconsolidado.');

            return self::SUCCESS;
        }

        // Passo 3/4 (D1) — relatório de impacto por pessoa/competência +
        // plano de reconsolidação, calculados ANTES de qualquer confirmação.
        [$impacto, $plano] = $this->calcularImpactoEPlano($mes);
        $this->exibirImpacto($impacto);
        $this->exibirPlanoDeReconsolidacao($plano);

        if ($dryRun) {
            $this->info('[NPS Imputação] DRY-RUN — nada foi gravado e nenhum snapshot foi reconsolidado.');

            return self::SUCCESS;
        }

        if (! $force) {
            $confirmado = $this->confirm(
                'Aplicar a materialização e reconsolidar o snapshot das competências afetadas?',
                false
            );

            if (! $confirmado) {
                $this->warn('[NPS Imputação] Operação cancelada pelo operador — nada foi gravado, nenhum snapshot reconsolidado.');

                return self::SUCCESS;
            }
        }

        // Passo 2 — gravação real.
        $statsReais = $this->imputationService->materializarLote($mes, dryRun: false);
        $this->info('[NPS Imputação] Concluído:');
        $this->exibirStats($statsReais);

        $this->bustarCache($impacto);

        $competencias = $this->competenciasDistintas($impacto);
        $this->reconsolidarSnapshots($competencias);

        $divergencias = $this->conferirSnapshotsReconsolidados($impacto);

        Log::info('[NPS Imputação] execução concluída', [
            'stats'                       => $statsReais,
            'mes'                         => $mes?->format('Y-m'),
            'competencias_reconsolidadas' => collect($competencias)->map(fn (Carbon $c) => $c->format('Y-m'))->all(),
            'nao_materializados'          => $divergencias,
            'usuario_so'                  => get_current_user(),
            'dry_run'                     => false,
        ]);

        if (! empty($divergencias)) {
            Log::error('[NPS Imputação] snapshot não materializado para parte das pessoas', [
                'divergencias' => $divergencias,
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ═══ --desfazer (rollback) ══════════════════════════════════════════

    private function desfazer(?Carbon $mes, bool $force): int
    {
        if (! $mes) {
            $this->error('[NPS Imputação] --desfazer exige --mes=YYYY-MM (evita apagar o histórico inteiro por engano).');

            return self::FAILURE;
        }

        $inicio = $mes->copy()->startOfMonth();
        $fim    = $mes->copy()->endOfMonth();

        // A âncora é o survey individual OU o link de grupo (2026-08-26).
        // Buscar só por `survey` deixaria as linhas de grupo para trás em
        // silêncio — o comando diria "apaguei tudo" e o piso continuaria
        // pesando na nota da competência que o operador quis desfazer.
        $linhas = NpsImputedAssignment::where(function ($ancora) use ($inicio, $fim) {
            $ancora->whereHas('survey', function ($q) use ($inicio, $fim) {
                $q->where(function ($qq) use ($inicio, $fim) {
                    $qq->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()])
                        ->orWhere(function ($qqq) use ($inicio, $fim) {
                            $qqq->whereNull('month_reference')->whereBetween('created_at', [$inicio, $fim]);
                        });
                });
            })->orWhereHas('groupSurvey', function ($q) use ($inicio, $fim) {
                // `month_reference` do link de grupo é sempre preenchido —
                // sem o fallback `created_at` do individual.
                $q->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()]);
            });
        })->get();

        if ($linhas->isEmpty()) {
            $this->info("[NPS Imputação] nada a desfazer em {$mes->format('Y-m')}.");

            return self::SUCCESS;
        }

        $this->info("[NPS Imputação] {$linhas->count()} linha(s) serão apagadas de {$mes->format('Y-m')} (inclusive definitivo):");
        $this->table(
            ['Pessoa', 'Empresa', 'Dimensão', 'Status'],
            $linhas->map(fn (NpsImputedAssignment $l) => [
                $l->user_id ? (User::find($l->user_id)?->name ?? "user #{$l->user_id}") : '(empresa)',
                $l->company_id,
                $l->dimensao,
                $l->status,
            ])->all()
        );

        if (! $force) {
            $confirmado = $this->confirm('Apagar estas linhas, bustar o cache e reconsolidar o snapshot?', false);

            if (! $confirmado) {
                $this->warn('[NPS Imputação] Rollback cancelado — nada foi apagado.');

                return self::SUCCESS;
            }
        }

        // $esperado é o valor PRÉ-backfill (regra desligada), calculado ANTES
        // de apagar — é o estado ao qual o snapshot deve voltar depois do
        // rollback (mesma régua do relatório de impacto, só que invertida).
        $pares = $linhas->whereNotNull('user_id')
            ->map(fn (NpsImputedAssignment $l) => [
                'user_id'     => $l->user_id,
                'competencia' => $l->competencia_nps->copy()->subMonthNoOverflow()->startOfMonth(),
            ])
            ->unique(fn (array $p) => $p['user_id'] . '|' . $p['competencia']->format('Y-m'))
            ->values();

        $esperado = [];
        foreach ($pares as $par) {
            $user = User::find($par['user_id']);
            if (! $user) {
                continue;
            }

            $this->scoreService->setIncluirImputadas(false);
            $antes = $this->scoreService->compute($user, $par['competencia']);
            $this->scoreService->setIncluirImputadas(true);

            $esperado[] = [
                'user_id'            => $user->id,
                'user_name'          => $user->name,
                'competencia'        => $par['competencia'],
                'nota_final_depois'  => $antes['nota_final'],
                'faixa_bonus_depois' => $antes['faixa_bonus'],
            ];
        }

        foreach ($pares as $par) {
            Cache::forget($this->scoreService->cacheKey($par['user_id'], $par['competencia']));
        }

        NpsImputedAssignment::whereIn('id', $linhas->pluck('id'))->delete();

        $competencias = collect($esperado)->pluck('competencia')
            ->unique(fn (Carbon $c) => $c->format('Y-m'))->values()->all();
        $this->reconsolidarSnapshots($competencias);

        $divergencias = $this->conferirSnapshotsReconsolidados($esperado);

        Log::warning('[NPS Imputação] rollback executado', [
            'mes'                         => $mes->format('Y-m'),
            'linhas_removidas'            => $linhas->count(),
            'competencias_reconsolidadas' => collect($competencias)->map(fn (Carbon $c) => $c->format('Y-m'))->all(),
            'nao_materializados'          => $divergencias,
        ]);

        return empty($divergencias) ? self::SUCCESS : self::FAILURE;
    }

    // ═══ Relatório de impacto (D1) + plano de reconsolidação ═══════════════

    /**
     * Calcula o relatório ANTES/DEPOIS por (pessoa, competência financeira) e
     * o plano de reconsolidação — SEM gravar nada de fato.
     *
     * `compute()` só enxerga notas imputadas que já existem no banco
     * (`notasImputadas()` delega para `NpsImputationService`, que lê
     * `nps_imputed_assignments`) — não há como simular o "depois" sem as
     * linhas existirem de verdade durante o cálculo. Por isso materializamos
     * DE VERDADE dentro de uma transação com rollback de controle: a escrita
     * real só acontece no Passo 2 do fluxo padrão, depois da confirmação do
     * operador — aqui é só cálculo de relatório.
     *
     * @return array{0: array<string, array>, 1: array<string, array>}
     */
    private function calcularImpactoEPlano(?Carbon $mes): array
    {
        $impacto = [];
        $plano   = [];

        try {
            DB::transaction(function () use ($mes, &$impacto, &$plano) {
                $this->imputationService->materializarLote($mes, dryRun: false);

                $pares = $this->paresAfetados($mes);

                foreach ($pares as $par) {
                    $user = User::find($par['user_id']);
                    if (! $user) {
                        continue;
                    }
                    $competencia = $par['competencia'];

                    $this->scoreService->setIncluirImputadas(false);
                    $antes = $this->scoreService->compute($user, $competencia);
                    $this->scoreService->setIncluirImputadas(true);
                    $depois = $this->scoreService->compute($user, $competencia);

                    // BonusFaixa::classificar() dá a classificação PURA da nota
                    // (sem a promoção DESEMP-08) — usada só para o relatório
                    // antes/depois; a conferência do snapshot usa faixa_bonus
                    // (compute(), COM a promoção), que é o valor que
                    // `ConsolidarMesDesempenho` de fato persiste.
                    $faixaAntes  = $antes['nota_final'] !== null
                        ? BonusFaixa::classificar((float) $antes['nota_final'])?->slug
                        : null;
                    $faixaDepois = $depois['nota_final'] !== null
                        ? BonusFaixa::classificar((float) $depois['nota_final'])?->slug
                        : null;

                    $chave = $par['user_id'] . '|' . $competencia->format('Y-m');
                    $impacto[$chave] = [
                        'user_id'            => $user->id,
                        'user_name'          => $user->name,
                        'competencia'        => $competencia,
                        'nps_antes'          => $antes['componentes']['nps_medio'],
                        'nps_depois'         => $depois['componentes']['nps_medio'],
                        'nota_final_antes'   => $antes['nota_final'],
                        'nota_final_depois'  => $depois['nota_final'],
                        'faixa_antes'        => $faixaAntes,
                        'faixa_depois'       => $faixaDepois,
                        'faixa_bonus_depois' => $depois['faixa_bonus'],
                        'muda_faixa'         => $faixaAntes !== $faixaDepois,
                    ];

                    $mesYm = $competencia->format('Y-m');
                    if (! isset($plano[$mesYm])) {
                        $plano[$mesYm] = [
                            'competencia'         => $competencia,
                            'tem_snapshot'        => DesempenhoScoreSnapshot::mensal()
                                ->whereDate('mes_referencia', $competencia->toDateString())
                                ->exists(),
                            'pessoas_mudam_faixa' => 0,
                        ];
                    }

                    $classificacaoAtual = DesempenhoScoreSnapshot::mensal()
                        ->where('user_id', $user->id)
                        ->whereDate('mes_referencia', $competencia->toDateString())
                        ->value('classificacao');

                    if ($classificacaoAtual !== null && $classificacaoAtual !== $depois['faixa_bonus']) {
                        $plano[$mesYm]['pessoas_mudam_faixa']++;
                    }
                }

                // Desfaz a escrita real feita acima — este passo é só cálculo
                // de relatório, nunca persiste de verdade.
                throw new \RuntimeException(self::ROLLBACK_MARKER);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== self::ROLLBACK_MARKER) {
                throw $e;
            }
        }

        return [$impacto, $plano];
    }

    /**
     * Pares (user_id, competência FINANCEIRA = competência do survey MENOS 1
     * mês, NPSWIN-03) distintos das linhas imputadas — filtrado por `$mes`
     * (competência do DISPARO) quando informado.
     *
     * @return array<int, array{user_id:int, competencia:Carbon}>
     */
    private function paresAfetados(?Carbon $mes): array
    {
        $query = NpsImputedAssignment::vigentes()->whereNotNull('user_id');

        if ($mes) {
            $inicio = $mes->copy()->startOfMonth();
            $fim    = $mes->copy()->endOfMonth();
            $query->whereBetween('competencia_nps', [$inicio->toDateString(), $fim->toDateString()]);
        }

        return $query->get()
            ->map(fn (NpsImputedAssignment $linha) => [
                'user_id'     => $linha->user_id,
                'competencia' => $linha->competencia_nps->copy()->subMonthNoOverflow()->startOfMonth(),
            ])
            ->unique(fn (array $p) => $p['user_id'] . '|' . $p['competencia']->format('Y-m'))
            ->values()
            ->all();
    }

    /** @return array<int, Carbon> */
    private function competenciasDistintas(array $impacto): array
    {
        return collect($impacto)
            ->pluck('competencia')
            ->unique(fn (Carbon $c) => $c->format('Y-m'))
            ->values()
            ->all();
    }

    // ═══ Reconsolidação (REUSO — nunca reimplementação) ═════════════════════

    /**
     * Reconsolida o snapshot mensal do bônus das competências informadas,
     * reusando `desempenho:consolidar-mes` — NUNCA reimplementando o
     * `updateOrCreate`, o gate de margem FIXMARG-03 nem o repopulamento de
     * `ranking_pos`. Falha isolada de UMA competência NÃO desfaz as linhas já
     * gravadas (o backfill em si é válido) — só é gritada na saída com a
     * instrução de re-rodar manualmente.
     *
     * @param  array<int, Carbon>  $competencias  competências FINANCEIRAS
     */
    private function reconsolidarSnapshots(array $competencias): void
    {
        foreach ($competencias as $competencia) {
            $mesYm = $competencia->format('Y-m');
            try {
                Artisan::call('desempenho:consolidar-mes', ['--mes' => $mesYm]);
                $this->line("  ↳ reconsolidado {$mesYm}: " . trim(Artisan::output()));
            } catch (\Throwable $e) {
                Log::error('[NPS Imputação] falha ao reconsolidar snapshot', [
                    'mes'  => $mesYm,
                    'erro' => $e->getMessage(),
                ]);
                $this->error("[NPS Imputação] falha ao reconsolidar {$mesYm} — re-rodar manualmente: php artisan desempenho:consolidar-mes --mes={$mesYm}");
            }
        }
    }

    /**
     * Confere, por RE-CONSULTA ao banco (NUNCA parseando a saída agregada do
     * `desempenho:consolidar-mes`, que só traz uma contagem de rejeições sem
     * nomes — NPSFLOOR-08c), se cada (pessoa, competência) do relatório aprovado teve
     * o `DesempenhoScoreSnapshot` de fato atualizado. Divergência → nomeia
     * pessoa + competência, nunca uma contagem agregada.
     *
     * @param  array<string, array>  $itens  chave = "user_id|Y-m", valores com
     *   user_id/user_name/competencia/nota_final_depois/faixa_bonus_depois
     * @return array<int, array> divergências (vazio = tudo conferiu)
     */
    private function conferirSnapshotsReconsolidados(array $itens): array
    {
        $divergencias = [];

        foreach ($itens as $item) {
            $snap = DesempenhoScoreSnapshot::mensal()
                ->where('user_id', $item['user_id'])
                ->whereDate('mes_referencia', $item['competencia']->toDateString())
                ->first();

            $faixaEsperada = $item['faixa_bonus_depois'];
            $scoreEsperado = $item['nota_final_depois'] !== null
                ? (int) round($item['nota_final_depois'] * 20)
                : null;

            $divergente = $snap === null
                || $snap->classificacao !== $faixaEsperada
                || ($scoreEsperado !== null && abs($snap->score - $scoreEsperado) > 1);

            if ($divergente) {
                $divergencias[] = [
                    'user_id'           => $item['user_id'],
                    'user_name'         => $item['user_name'],
                    'competencia'       => $item['competencia']->format('Y-m'),
                    'faixa_esperada'    => $faixaEsperada,
                    'faixa_no_snapshot' => $snap->classificacao ?? null,
                ];
            }
        }

        if (empty($divergencias)) {
            $this->info(sprintf(
                'Conferência OK — todas as %d pessoa(s) do relatório tiveram o snapshot atualizado.',
                count($itens)
            ));
        } else {
            $this->warn(sprintf(
                'Atenção: %d pessoa(s) foram aprovadas no relatório mas o %s — motivo provável: cobertura de margem '
                    . '< 0,7 (gate FIXMARG-03 do desempenho:consolidar-mes, ver Log::error de [Desempenho Mensal]). '
                    . 'Ação: re-rodar `php artisan desempenho:consolidar-mes --mes=YYYY-MM` quando o rate-limit da Adman passar.',
                count($divergencias),
                self::MARCADOR_SNAPSHOT_NAO_ATUALIZADO
            ));
            $this->table(
                ['Pessoa', 'Competência', 'Faixa esperada', 'Faixa no snapshot'],
                collect($divergencias)->map(fn ($d) => [
                    $d['user_name'],
                    $d['competencia'],
                    $d['faixa_esperada'] ?? '-',
                    $d['faixa_no_snapshot'] ?? '-',
                ])->all()
            );
        }

        return $divergencias;
    }

    // ═══ Cache-bust ═════════════════════════════════════════════════════

    private function bustarCache(array $impacto): void
    {
        foreach ($impacto as $item) {
            Cache::forget($this->scoreService->cacheKey($item['user_id'], $item['competencia']));
        }
    }

    // ═══ Saída / relatórios de console ═════════════════════════════════════

    private function exibirDiff(array $detalhe): void
    {
        if (empty($detalhe)) {
            return;
        }

        $competenciaPorSurvey = [];

        $this->info('[NPS Imputação] Diff (ANTES de gravar):');
        $this->table(
            ['survey_id', 'company_id', 'competencia_nps', 'dimensao', 'role', 'user_id'],
            collect($detalhe)->map(function (array $d) use (&$competenciaPorSurvey) {
                if (! array_key_exists($d['survey_id'], $competenciaPorSurvey)) {
                    $survey = NpsSurvey::find($d['survey_id']);
                    $competenciaPorSurvey[$d['survey_id']] = $survey
                        ? ($survey->month_reference ?? $survey->created_at)->format('Y-m')
                        : '-';
                }

                return [
                    $d['survey_id'],
                    $d['company_id'],
                    $competenciaPorSurvey[$d['survey_id']],
                    $d['dimensao'],
                    $d['role'] ?? '-',
                    $d['user_id'] ?? '-',
                ];
            })->all()
        );
    }

    private function exibirStats(array $stats): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            collect($stats)->except('detalhe')->map(fn ($v, $k) => [$k, $v])->values()->all()
        );
    }

    /**
     * O RELATÓRIO ANTES/DEPOIS por pessoa e competência (requisito D1) —
     * entregável mais forte deste comando: é por ele que o operador decide
     * aplicar ou não o retroativo.
     */
    private function exibirImpacto(array $impacto): void
    {
        if (empty($impacto)) {
            return;
        }

        $this->info('[NPS Imputação] Impacto por pessoa e competência (requisito D1):');
        $this->table(
            ['Pessoa', 'Competência', 'NPS antes', 'NPS depois', 'Nota antes', 'Nota depois', 'Faixa antes', 'Faixa depois', 'Muda faixa?'],
            collect($impacto)->map(fn (array $i) => [
                $i['user_name'],
                $i['competencia']->format('Y-m'),
                $i['nps_antes'] !== null ? number_format($i['nps_antes'], 2) : '-',
                $i['nps_depois'] !== null ? number_format($i['nps_depois'], 2) : '-',
                $i['nota_final_antes'] !== null ? number_format($i['nota_final_antes'], 2) : '-',
                $i['nota_final_depois'] !== null ? number_format($i['nota_final_depois'], 2) : '-',
                $i['faixa_antes'] ?? '-',
                $i['faixa_depois'] ?? '-',
                $i['muda_faixa'] ? 'SIM' : 'não',
            ])->all()
        );

        $mudam = collect($impacto)->where('muda_faixa', true)->count();
        $this->info("{$mudam} pessoa(s) mudam de faixa de bônus com este backfill.");
    }

    /**
     * Deixa explícito qual é o gate humano real: estas competências terão o
     * registro CONGELADO do bônus reescrito (não só o cálculo ao vivo).
     */
    private function exibirPlanoDeReconsolidacao(array $plano): void
    {
        if (empty($plano)) {
            return;
        }

        $this->info('[NPS Imputação] Plano de reconsolidação — estas competências terão o registro CONGELADO do bônus reescrito:');
        $this->table(
            ['Competência', 'Snapshot existe?', 'Pessoas que mudam de faixa NO SNAPSHOT'],
            collect($plano)->map(fn (array $p, string $mesYm) => [
                $mesYm,
                $p['tem_snapshot'] ? 'sim' : 'mês aberto — sem snapshot a reconsolidar',
                $p['pessoas_mudam_faixa'],
            ])->values()->all()
        );
    }
}
