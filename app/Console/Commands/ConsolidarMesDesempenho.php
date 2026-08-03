<?php

namespace App\Console\Commands;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 74 D-08 / DESEMP-09 — Consolida snapshot MENSAL FECHADO do módulo
 * Desempenho.
 *
 * v18.0 · Fase 105 (D2, NPSWIN-03): roda no ÚLTIMO DIA de cada mês às 14:00
 * BRT (não mais no dia 1) — após a coleta de NPS do mês corrente (M+1)
 * fechar. A competência congelada é sempre `today->subMonthNoOverflow()`
 * (mês anterior ao hoje = M), cujo NPS acabou de terminar de ser coletado
 * em M+1. Rodar no dia 1 (timing antigo) congelava a competência M no
 * INÍCIO da coleta de M+1, quando quase nenhuma resposta existia ainda —
 * pior que o bug original de janela (105-CONTEXT.md, Pitfall 3 do
 * RESEARCH). Ver `routes/console.php` (bloco `desempenho-consolidar-mes`).
 *
 * Diferente do `desempenho:snapshot-scores` (diário, mes_referencia=NULL),
 * este comando grava rows com `mes_referencia = YYYY-MM-01` e representa
 * o resultado FINAL do mês encerrado — insumo canônico do ranking mensal
 * do `/performance`, do widget "Desempenho da equipe" do Dashboard admin
 * e da regra DESEMP-08 (promoção por 2 meses consecutivos em
 * `intermediario` → `maximo`).
 *
 * Idempotência: `updateOrCreate(['user_id', 'mes_referencia'])` — rerun no
 * mesmo mês NÃO duplica rows. `--mes=YYYY-MM` permite reprocessamento
 * manual para catch-up pós-incident (DESEMP-09).
 *
 * DESEMP-10 (Sem carteira): users cuja carteira esteja vazia no mês são
 * PULADOS — não grava row, `Log::info` estruturado registra o evento.
 *
 * Constraint SPEC (memory): batch mensal itera ~15-20 users × ~30 empresas
 * × queries de metrics/NPS/margem → `ini_set('memory_limit','512M')` no
 * handle preserva margem contra PHP-FPM 256MB default.
 *
 * Após o lote: popula `ranking_pos` do mês via ROW_NUMBER() OVER ORDER BY
 * score DESC — filtrando `mes_referencia = ?` (não `ref_date`) para isolar
 * o ranking mensal do diário coexistente na mesma tabela.
 *
 * Fase 110 (Plan 02 · FIXMARG-03) — gate de qualidade da amostra de margem:
 * este comando é o que PAGA o bônus, e roda em UMA passada `compute()` sem
 * retry. Se essa passada coincidir com rate-limit 429 concorrente na Adman
 * (root cause documentado em `.planning/debug/margem-adman-diff-instavel.md`),
 * o resultado pode vir com margem degradada — sem o gate, isso SOBRESCREVE
 * o snapshot bom com um número envenenado. Antes do `updateOrCreate`, a
 * cobertura de `compute()['margem_amostra']` é checada contra
 * `MARGEM_COBERTURA_MINIMA_CONGELAMENTO`; abaixo do limiar, a persistência é
 * RECUSADA (preserva o snapshot já congelado) e um `Log::error` de alerta é
 * emitido. A recusa quando NÃO existe snapshot anterior para preservar é
 * INTENCIONALMENTE barulhenta — deixa o mês vazio, o que zera silenciosamente
 * a cadeia de promoção DESEMP-08 (`promoverPor2MesesConsecutivos` lê o
 * mensal de M-1) — por isso o `Log::error` desse sub-caso é acionável,
 * nomeando o impacto e a instrução operacional de re-rodar o comando quando
 * o rate-limit passar. Nenhuma row placeholder/degradada é criada como
 * contorno (reintroduziria o número envenenado que o gate existe para
 * evitar). Ver `<design_decision>` do `110-02-PLAN.md`.
 *
 * Fase 122 (SNAP-03/D-122-05/D-122-06) — persistência por empresa e base do
 * gate:
 * - Depois de cada `updateOrCreate` bem-sucedido, o comando chama
 *   `CompanyScoreSnapshotWriter::sync()` com `origem = 'consolidar_mes'`,
 *   gravando uma linha por empresa da carteira naquela competência
 *   (`desempenho_company_score_snapshots`). A origem `consolidar_mes` IGNORA
 *   a trava de congelamento do writer de propósito — reconsolidar uma
 *   competência já fechada é o caminho OFICIAL deste comando (SNAP-06,
 *   comprovado nesta sessão: 2026-06 foi reconsolidada duas vezes na
 *   prática, ver `122-CONTEXT.md` item 3).
 * - D-122-06: quando o gate FIXMARG-03 recusa congelar (abaixo), o writer
 *   NÃO é chamado — gravar linhas por empresa de um resumo recusado criaria
 *   detalhe órfão contradizendo o snapshot preservado.
 * - D-122-05: a BASE do gate FIXMARG-03 (qual cobertura de amostra decide
 *   recusar) segue a feature flag `metrics.performance_company_first_score`,
 *   não o shape do relatório. Com a flag desligada (estado desta fase e de
 *   produção), o gate lê `margem_amostra['legado']` — os mesmos números de
 *   sempre, zero mudança de comportamento. Só quando a flag ligar (fase
 *   futura, pós GATE MPP-04) o gate passa a ler `margem_amostra` (pp), que é
 *   a grandeza que `nota_final` passaria a usar. Comentário completo no
 *   bloco do gate abaixo.
 * - O texto de `Empresas: N linhas` na linha final de `info()` é
 *   CONVENIÊNCIA OPERACIONAL, não critério de verificação — a conferência
 *   oficial é por reconsulta a `desempenho_company_score_snapshots`
 *   (122-CONTEXT.md item 5: "qualquer verificação que dependa de stdout é
 *   verificação inválida").
 */
class ConsolidarMesDesempenho extends Command
{
    protected $signature = 'desempenho:consolidar-mes
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}';

    /**
     * Cobertura mínima (fração de empresas Adman elegíveis com margem real)
     * exigida para o congelamento ACEITAR a amostra — Fase 110 · FIXMARG-03.
     * Abaixo disso, a persistência é recusada (ver docblock da classe).
     */
    private const MARGEM_COBERTURA_MINIMA_CONGELAMENTO = 0.7;

    protected $description = 'Consolida snapshot mensal fechado do mês passado (último dia do mês, após a coleta de NPS de M+1 fechar — v18.0 D2).';

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private CompanyScoreSnapshotWriter $snapshotWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Constraint SPEC: batch mensal costuma exigir mais RAM do que os 256MB
        // padrão do PHP-FPM (queries agregadas por empresa + provider ML/Adman).
        // Bump conservador de 512M evita OOM na cascata do último dia do mês
        // 14:00 BRT (v18.0 D2 — antes rodava dia 1).
        ini_set('memory_limit', '512M');

        // ── Deriva $mes (Carbon do 1º dia do mês alvo) ───────────────────────
        $mesOption = $this->option('mes');
        if ($mesOption) {
            try {
                // Rule 1 (bug pré-existente exposto pela v18.0 D2): NÃO usar
                // createFromFormat('Y-m', ...) — sem o dia explícito, o PHP
                // preenche o dia com o de "hoje" (agora tipicamente o último
                // dia do mês, 28-31) e ESTOURA para o mês seguinte quando o
                // mês alvo tem menos dias (ex.: hoje=31, --mes=2026-06 vira
                // 2026-07-01, não 2026-06-01). Ancorar no dia 1 explícito
                // elimina o overflow.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption . '-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[Desempenho Mensal] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");
                return self::FAILURE;
            }
        } else {
            // Default: mês ANTERIOR ao hoje (consolidação do mês encerrado).
            // subMonthNoOverflow evita edge case do dia 31 caindo em mês curto.
            $mes = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        }

        $mesStr = $mes->toDateString();
        $mesLabel = $mes->format('Y-m');

        // ── Users elegíveis (mesmo filtro do SnapshotDesempenhoScores) ───────
        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->get();

        $this->info("[Desempenho Mensal] Consolidando mês {$mesLabel} — {$users->count()} users elegíveis.");

        $ok = 0;
        $fail = 0;
        $semCarteira = 0;
        $degradado = 0;
        // Fase 122 (SNAP-03) — totalizadores da persistência por empresa.
        $empresasUpserted = 0;
        $empresasPruned   = 0;

        foreach ($users as $user) {
            try {
                // Fase 120 (AGRE-02/D-04): `incluirEmpresasScore: true` liga o
                // SHADOW — sinal independente da feature flag (C-01), que só
                // decide qual número vira `nota_final`. Este comando chama
                // `compute()` direto, sem `Cache::remember`, então o shadow
                // roda com garantia em toda execução — é o registro canônico
                // mensal. `empresas_score` passa a ser persistido no
                // `breakdown_json` de graça; a persistência estruturada fica
                // pra Fase 122.
                $result = $this->scoreService->compute($user, $mes, null, incluirEmpresasScore: true);

                // DESEMP-10 — sem carteira: pula (não grava row).
                if (($result['sem_carteira'] ?? false) === true) {
                    Log::info('[Desempenho Mensal] User sem carteira — pulando snapshot', [
                        'user_id'        => $user->id,
                        'user_name'      => $user->name,
                        'mes_referencia' => $mesStr,
                        'motivo'         => $result['motivo'] ?? null,
                    ]);
                    $semCarteira++;
                    continue;
                }

                // Fase 110 (Plan 02 · FIXMARG-03) — gate de qualidade da
                // amostra de margem ANTES do updateOrCreate: rede de
                // segurança contra rate-limit 429 no instante do cron. Só
                // gateia quando existe empresa Adman elegível (n_elegivel>0)
                // — ausência de margem Adman (só-Shopee/sem carteira
                // financeira) NÃO é degradação (cobertura=1.0 nesse caso,
                // ver `DesempenhoScoreService::compute()`).
                //
                // Fase 122 (SNAP-05/D-122-05) — a BASE do gate segue a
                // feature flag, não o shape do relatório: o gate existe para
                // impedir que uma leitura degradada da Adman sobrescreva o
                // snapshot que paga bônus, então tem que medir a amostra da
                // grandeza que ALIMENTA `nota_final` NESTA execução. Flag
                // desligada (estado desta fase e de produção) → lê
                // `margem_amostra['legado']`, os mesmos números de sempre —
                // ZERO mudança de comportamento no fechamento. Flag ligada
                // (fase futura, depois do GATE MPP-04) → lê `margem_amostra`
                // (pp), a grandeza que a nota passaria a usar. Fallback
                // quando a sub-chave `legado` não existe (payload de cache
                // antigo, shadow desligado): usa o próprio `margem_amostra`,
                // o shape de hoje. Cautela documentada no Plano 03:
                // cobertura_prev = 0.6415 medida pelo probe MPP-04 em
                // 2026-07-29, abaixo do limiar de 0,7 — trocar a base sem
                // decisão explícita reprovaria quase todo mundo já no
                // primeiro fechamento.
                $amostra    = $result['margem_amostra'] ?? ['n_real' => 0, 'n_elegivel' => 0, 'cobertura' => 1.0];
                $flagLigada = (bool) config('metrics.performance_company_first_score');
                $base       = $flagLigada ? $amostra : ($amostra['legado'] ?? $amostra);
                $nElegivel  = (int) ($base['n_elegivel'] ?? 0);
                $cobertura  = (float) ($base['cobertura'] ?? 1.0);

                if ($nElegivel > 0 && $cobertura < self::MARGEM_COBERTURA_MINIMA_CONGELAMENTO) {
                    $temAnterior = DesempenhoScoreSnapshot::mensal()
                        ->where('user_id', $user->id)
                        ->whereDate('mes_referencia', $mesStr)
                        ->exists();

                    // As duas coberturas (pp e legada) são sempre registradas
                    // — mesmo quando o gate decidiu pela legada — para o
                    // rollout enxergar quanto o veredito mudaria ao ligar a
                    // flag, sem precisar rodar nada de novo.
                    $logContext = [
                        'user_id'               => $user->id,
                        'user_name'             => $user->name,
                        'mes_referencia'        => $mesStr,
                        'cobertura'             => $cobertura,
                        'n_real'                => (int) ($base['n_real'] ?? 0),
                        'n_elegivel'            => $nElegivel,
                        'sem_snapshot_anterior' => ! $temAnterior,
                        'base_gate'             => $flagLigada ? 'margem_var_pp' : 'var_margem_pct',
                        'cobertura_pp'          => $amostra['cobertura'] ?? null,
                        'cobertura_legado'      => $amostra['legado']['cobertura'] ?? $amostra['cobertura'] ?? null,
                    ];

                    if ($temAnterior) {
                        Log::error(
                            '[Desempenho Mensal] Amostra de margem degradada — congelamento RECUSADO (snapshot anterior PRESERVADO)',
                            $logContext
                        );
                    } else {
                        // Sub-caso INTENCIONALMENTE barulhento (design_decision
                        // do 110-02-PLAN.md) — recusar deixa o user SEM row
                        // neste mês, o que zera silenciosamente a cadeia de
                        // promoção DESEMP-08 (promoverPor2MesesConsecutivos lê
                        // o mensal de M-1). Reconciliação é OPERACIONAL: re-rodar
                        // o comando manualmente quando o rate-limit passar (foi
                        // assim que o snapshot de 22/07 foi gerado).
                        Log::error(
                            '[Desempenho Mensal] Amostra de margem degradada — congelamento RECUSADO e SEM snapshot anterior para preservar: '
                                . 'o user fica SEM row neste mês. Impacto DESEMP-08: promoverPor2MesesConsecutivos lê o mensal de M-1 e a cadeia '
                                . 'de promoção por 2 meses consecutivos zera. Ação: re-rodar `php artisan desempenho:consolidar-mes` manualmente '
                                . 'quando o rate-limit da Adman passar.',
                            $logContext + ['impacto_desemp08' => true]
                        );
                    }

                    $degradado++;
                    continue;
                }

                DesempenhoScoreSnapshot::updateOrCreate(
                    [
                        'user_id'        => $user->id,
                        'mes_referencia' => $mesStr,
                    ],
                    [
                        'ref_date'             => $mes,
                        'score'                => (int) round(($result['nota_final'] ?? 0.0) * 20),
                        'classificacao'        => $result['faixa_bonus'] ?? '',
                        'tem_base_comparativa' => $result['nota_final'] !== null,
                        'empresas_carteira'    => (int) ($result['empresas_carteira'] ?? 0),
                        'empresas_eligiveis'   => (int) ($result['empresas_com_baseline'] ?? 0),
                        'breakdown_json'       => $result,
                    ]
                );

                // Fase 122 (SNAP-03/D-122-06) — persistência por empresa
                // SÓ acontece depois do updateOrCreate bem-sucedido (nunca no
                // caminho de recusa do gate acima, nem no `sem_carteira`).
                // `array_key_exists('score_status_por_empresa', $result)` é
                // o sinal canônico de que o shadow rodou (D-05 da Fase 121)
                // — este comando sempre passa `incluirEmpresasScore: true`,
                // então a ausência aqui não deveria acontecer; se acontecer,
                // é melhor não gravar do que podar as linhas boas com uma
                // coleção vazia.
                if (array_key_exists('score_status_por_empresa', $result)) {
                    $syncResult = $this->snapshotWriter->sync(
                        $user,
                        $mes,
                        $result['empresas_score'] ?? [],
                        CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
                    );
                    $empresasUpserted += $syncResult['upserted'];
                    $empresasPruned   += $syncResult['pruned'];
                } else {
                    Log::warning(
                        '[Desempenho Mensal] Shadow não rodou — persistência por empresa PULADA (esperado sempre rodar aqui, pois o compute() acima já pede incluirEmpresasScore: true)',
                        [
                            'user_id'        => $user->id,
                            'user_name'      => $user->name,
                            'mes_referencia' => $mesStr,
                        ]
                    );
                }

                $ok++;
            } catch (\Throwable $e) {
                Log::error("[Desempenho Mensal] Falha user {$user->id} ({$user->name}) mês {$mesLabel}: {$e->getMessage()}");
                $fail++;
                continue;
            }
        }

        // 2º passo — popular ranking_pos do mês (apenas rows mensais).
        $this->popularRankingPosMensal($mesStr);

        $this->info("[Desempenho Mensal] Mes {$mesLabel} — OK: {$ok} · Falhas: {$fail} · Sem carteira: {$semCarteira} · Degradados: {$degradado} · Empresas: {$empresasUpserted} linhas (podadas: {$empresasPruned})");
        return self::SUCCESS;
    }

    /**
     * Popula `ranking_pos` para todos os snapshots MENSAIS de um mês
     * (mes_referencia = $mesStr), ordenando por `score` DESC (1 = melhor).
     *
     * MariaDB/MySQL 8: ROW_NUMBER() nativo, 1 query.
     * SQLite (testes) + fallback portável: N updates iterativos.
     *
     * Filtro por `mes_referencia = ?` (não `ref_date`) — em rows mensais
     * `ref_date = mes_referencia` mas o índice canonical (Plan 74-01 D-03)
     * é sobre `mes_referencia` e isola o ranking mensal do diário.
     *
     * @param string $mesStr Data no formato 'Y-m-01'
     */
    private function popularRankingPosMensal(string $mesStr): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(<<<SQL
                UPDATE desempenho_score_snapshots ds
                JOIN (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id ASC) AS pos
                    FROM desempenho_score_snapshots
                    WHERE DATE(mes_referencia) = ?
                ) r ON r.id = ds.id
                SET ds.ranking_pos = r.pos
                WHERE DATE(ds.mes_referencia) = ?
            SQL, [$mesStr, $mesStr]);
            return;
        }

        // Fallback portável (SQLite testes).
        $snaps = DesempenhoScoreSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->orderByDesc('score')
            ->orderBy('id')
            ->get(['id']);

        foreach ($snaps as $idx => $snap) {
            DB::table('desempenho_score_snapshots')
                ->where('id', $snap->id)
                ->update(['ranking_pos' => $idx + 1]);
        }
    }
}
