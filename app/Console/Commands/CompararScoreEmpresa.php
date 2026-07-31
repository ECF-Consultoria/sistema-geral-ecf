<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DesempenhoComparadorEmpresa;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 121, Plano 02 — comando de COLETA da comparação nota antiga × nota
 * nova (régua-da-média × média-das-réguas) por competência FIXA.
 *
 * O QUE MEDE: quanto muda a nota de bônus de cada profissional se a flag
 * da Fase 120 for ligada — sem ligar a flag em produção. O comando só lê e
 * persiste; nenhuma decisão é tomada aqui (D-04 — informa, o usuário
 * decide via `121-VALIDATION.md`).
 *
 * D-01 (invariante nº 1 — comparação justa): o par (nota antiga, nota
 * nova) tem que sair do MESMO payload, senão qualquer diferença poderia
 * ser ruído de uma segunda chamada à API em vez de mudança de fórmula.
 * Por isso este comando chama o serviço de composição do payload UMA e
 * só UMA vez por (profissional, competência), sempre com o shadow ligado
 * — os dois números (`nota_final`/`nota_final_por_empresa`) vêm do mesmo
 * resultado, nunca de duas chamadas separadas.
 *
 * Invariante nº 2 (interleaving obrigatório): a releitura do `diff_pct`
 * nativo da margem (só existe na Adman, `CompanyScoreService` descarta
 * essa chave e guarda só `diff_pp`) precisa acontecer IMEDIATAMENTE
 * depois de processar cada empresa, dentro do mesmo laço — nunca numa
 * segunda passada depois de processar todos os profissionais.
 * `AdmanMetricDiffService` cacheia por `(marketplace, custId, período,
 * dia)`: leitura `complete` fica até 24h em cache, mas leitura `partial`
 * (rate-limit, instabilidade transitória) fica só ~10 minutos. Uma
 * segunda passada levaria minutos — tempo suficiente para o `partial`
 * expirar e a releitura virar uma segunda leitura AO VIVO, reintroduzindo
 * o ruído que a D-01 existe para eliminar.
 *
 * A releitura só acontece na competência ALVO — as competências
 * históricas (`--historico`) existem só para o histograma da D-03 (Plano
 * 04), que usa `margem_var_pp` (já presente em `empresas_score`), não
 * `diff_pct`.
 *
 * ADVERTÊNCIA DE CUSTO: as competências históricas NÃO são pré-aquecidas
 * por nenhum cron (`adman:warm-diff` só aquece o mês em curso e o último
 * mês fechado) — cada execução deste comando com `--historico>0` gera
 * chamadas reais à Adman para competências antigas.
 */
class CompararScoreEmpresa extends Command
{
    protected $signature = 'desempenho:comparar-score-empresa
        {--mes= : competência FECHADA fixa no formato YYYY-MM (OBRIGATÓRIA — nunca competência implícita)}
        {--historico=2 : quantas competências anteriores coletar para o histograma da D-03 (0 desliga, máximo 6)}
        {--force : re-executa mesmo já havendo rodada de hoje para a mesma competência}';

    protected $description = 'Compara nota antiga x nota nova (regua-da-media x media-das-reguas) por competencia fixa. Nao liga flag nenhuma e nao altera calculo nenhum -- so mede.';

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricDiffDispatcher $diffDispatcher,
        private MetricPeriodResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mes = $this->option('mes');

        if (! is_string($mes) || preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
            $this->error('[ComparadorScoreEmpresa] --mes é obrigatório e precisa ser uma competência FIXA no formato YYYY-MM (ex.: --mes=2026-06). A competência nunca é implícita — a comparação precisa saber exatamente qual mês está sendo medido.');

            return self::FAILURE;
        }

        $historicoRaw = $this->option('historico');
        $historico    = is_numeric($historicoRaw) ? (int) $historicoRaw : null;

        if ($historico === null || $historico < 0 || $historico > 6) {
            $this->error("[ComparadorScoreEmpresa] --historico inválido ('{$historicoRaw}') — precisa ser um inteiro entre 0 e 6 (trava de volume contra uso indevido como vetor de carga na Adman).");

            return self::FAILURE;
        }

        // ── Resolução das competências (alvo + históricas) ────────────────
        $mesAlvoCarbon = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->startOfMonth();
        $competencias  = [];

        for ($i = 0; $i <= $historico; $i++) {
            $mesCarbon  = $mesAlvoCarbon->copy()->subMonthsNoOverflow($i);
            $periodoKey = $mesCarbon->format('Y-m');
            $periodo    = $this->resolver->resolve(['period_key' => $periodoKey]);

            // Competência aberta invalidaria a comparação inteira — mesma
            // asserção de runtime do probe da Fase 117.
            if ($periodo['comparison_mode'] !== 'previous_equal_length_window') {
                $this->error("[ComparadorScoreEmpresa] comparison_mode inesperado ('{$periodo['comparison_mode']}') para a competência {$periodoKey}. Esperado previous_equal_length_window — abortando para não comparar contra uma janela aberta.");

                return self::FAILURE;
            }

            $competencias[] = [
                'mes'         => $mesCarbon,
                'periodo_key' => $periodoKey,
                'periodo'     => $periodo,
                'alvo'        => $i === 0,
            ];
        }

        $mesAlvoKey = $competencias[0]['periodo_key'];

        // ── Guard de re-execução (T-121-13) ────────────────────────────────
        $existente = DesempenhoComparadorProfissional::query()
            ->where('competencia_alvo', true)
            ->where('periodo_key', $mesAlvoKey)
            ->whereDate('gerado_em', now()->toDateString())
            ->first();

        if ($existente !== null && ! $this->option('force')) {
            $this->error("[ComparadorScoreEmpresa] já existe uma rodada de hoje para a competência {$mesAlvoKey} (run_id={$existente->run_id}). Use --force para gravar uma nova rodada, ou reconsulte a rodada existente pelo run_id.");

            return self::FAILURE;
        }

        if ($existente !== null && $this->option('force')) {
            Log::warning('[ComparadorScoreEmpresa] --force: nova rodada mesmo já havendo execução hoje para a mesma competência', [
                'periodo_key'        => $mesAlvoKey,
                'run_id_anterior'    => $existente->run_id,
            ]);
        }

        // ── Identidade da rodada (T-121-12) ────────────────────────────────
        $runId    = Str::uuid()->toString();
        $geradoEm = now();

        $usuarios = $this->resolverProfissionaisElegiveis();

        // Memoização de Company por escopo da rodada inteira — empresas se
        // repetem entre carteiras, evita reconsultar.
        $companiesCache = [];

        foreach ($competencias as $competencia) {
            $ok          = 0;
            $falhas      = 0;
            $semCarteira = 0;

            foreach ($usuarios as $user) {
                try {
                    // ── D-01: UMA e só uma chamada por (profissional, competência) ──
                    $payload = $this->scoreService->compute($user, $competencia['mes'], $competencia['periodo'], incluirEmpresasScore: true);

                    if (($payload['sem_carteira'] ?? false) === true) {
                        $semCarteira++;

                        continue;
                    }

                    [$empresasTotal, $empresasStatus] = $this->persistirEmpresas(
                        $user,
                        $competencia,
                        $payload,
                        $runId,
                        $geradoEm,
                        $companiesCache
                    );

                    $notaAntiga = $payload['nota_final'] ?? null;
                    $notaNova   = $payload['nota_final_por_empresa'] ?? null;
                    // Nunca tratar null como zero — delta só existe quando as
                    // duas notas existem.
                    $delta = ($notaAntiga !== null && $notaNova !== null)
                        ? round($notaNova - $notaAntiga, 2)
                        : null;

                    DesempenhoComparadorProfissional::create([
                        'run_id'               => $runId,
                        'user_id'              => $user->id,
                        'periodo_key'          => $competencia['periodo_key'],
                        'competencia_alvo'     => $competencia['alvo'],
                        'gerado_em'            => $geradoEm,
                        'nota_antiga'          => $notaAntiga,
                        'nota_nova'            => $notaNova,
                        'delta'                => $delta,
                        'status_antigo'        => $payload['score_status'] ?? null,
                        'status_novo'          => $payload['score_status_por_empresa'] ?? null,
                        'faixa_antiga_oficial' => $payload['faixa_bonus'] ?? null,
                        // Classificação de faixa pré-promoção fica pro Plano 03
                        // (BonusFaixa::classificar() direto, D-06) — não inventar
                        // corte de faixa aqui.
                        'faixa_antiga_inicial' => null,
                        'faixa_nova_inicial'   => null,
                        'mudou_faixa'          => false,
                        'empresas_total'       => $empresasTotal,
                        'empresas_complete'    => $empresasStatus['complete'],
                        'empresas_partial'     => $empresasStatus['partial'],
                        'empresas_sem_fonte'   => $empresasStatus['sem_fonte'],
                        'empresas_sem_dados'   => $empresasStatus['sem_dados'],
                        'decomposicao'         => null,
                        'maior_causa_delta'    => null,
                        'falhou'               => false,
                        'erro'                 => null,
                    ]);

                    $ok++;
                } catch (\Throwable $e) {
                    // Fail-open por profissional — uma exceção NUNCA aborta a
                    // rodada inteira.
                    Log::warning('[ComparadorScoreEmpresa] falhou ao processar profissional', [
                        'user_id'     => $user->id,
                        'periodo_key' => $competencia['periodo_key'],
                        'error'       => $e->getMessage(),
                    ]);

                    DesempenhoComparadorProfissional::create([
                        'run_id'           => $runId,
                        'user_id'          => $user->id,
                        'periodo_key'      => $competencia['periodo_key'],
                        'competencia_alvo' => $competencia['alvo'],
                        'gerado_em'        => $geradoEm,
                        'falhou'           => true,
                        'erro'             => $e->getMessage(),
                    ]);

                    $falhas++;
                }
            }

            // Espelho no console/log — SÓ contadores agregados (T-121-10);
            // nota, delta e faixa por profissional nunca vão para log
            // estruturado, só para a tabela persistida.
            $msg = sprintf(
                '[ComparadorScoreEmpresa] competencia=%s alvo=%s OK=%d falhas=%d sem_carteira=%d',
                $competencia['periodo_key'],
                $competencia['alvo'] ? 'sim' : 'nao',
                $ok,
                $falhas,
                $semCarteira
            );
            Log::info($msg);
            $this->info($msg);
        }

        $this->warn('[ComparadorScoreEmpresa] AVISO: este console é um ESPELHO. A conferência OFICIAL é por reconsulta ao banco (desempenho_comparador_profissionais / desempenho_comparador_empresas), nunca por este stdout — mesma disciplina que o gate FIXMARG-03 já exige neste projeto.');

        return self::SUCCESS;
    }

    /**
     * Loop por empresa da competência: quando é a competência ALVO e a
     * empresa tem fonte financeira elegível, releitura interleaved do
     * `diff_pct` nativo da margem IMEDIATAMENTE antes de persistir aquela
     * empresa (invariante nº 2) — nunca uma segunda passada depois de
     * processar todas.
     *
     * @param  array{mes: Carbon, periodo_key: string, periodo: array, alvo: bool}  $competencia
     * @param  array<int, ?Company>  $companiesCache  passado por referência, escopo da rodada inteira
     * @return array{0: int, 1: array{complete: int, partial: int, sem_fonte: int, sem_dados: int}}
     */
    private function persistirEmpresas(
        User $user,
        array $competencia,
        array $payload,
        string $runId,
        Carbon $geradoEm,
        array &$companiesCache
    ): array {
        $total  = 0;
        $status = ['complete' => 0, 'partial' => 0, 'sem_fonte' => 0, 'sem_dados' => 0];

        foreach (collect($payload['empresas_score']) as $linhaEmpresa) {
            $total++;

            $statusEmpresa = $linhaEmpresa->status ?? 'sem_dados';
            if (array_key_exists($statusEmpresa, $status)) {
                $status[$statusEmpresa]++;
            }

            $fonteFinanceira = $linhaEmpresa->fonte_financeira ?? null;
            $qualityMotivos  = $linhaEmpresa->quality['motivos'] ?? [];
            $margemDiffPct   = null;

            // Releitura SÓ na competência alvo e SÓ com fonte financeira
            // elegível (guard C-04 — nunca chamar o dispatcher com fonte
            // nula). Para fonte 'shopee' o campo nativo é sempre null por
            // construção — resultado válido, não erro.
            if ($competencia['alvo'] && $fonteFinanceira !== null) {
                try {
                    if (! array_key_exists($linhaEmpresa->company_id, $companiesCache)) {
                        $companiesCache[$linhaEmpresa->company_id] = Company::find($linhaEmpresa->company_id);
                    }

                    $company = $companiesCache[$linhaEmpresa->company_id];

                    // A LINHA que executa a releitura interleaved (invariante
                    // nº 2) — imediatamente antes de persistir esta empresa.
                    $resultadoDiff = $this->diffDispatcher->compute($company, $competencia['periodo'], $fonteFinanceira);
                    $margemDiffPct = $resultadoDiff['metrics']['contribution_margin_pct']['diff_pct'] ?? null;
                } catch (\Throwable $e) {
                    // Fail-open por empresa — a exceção de UMA empresa nunca
                    // derruba as demais nem o profissional inteiro.
                    $margemDiffPct    = null;
                    $qualityMotivos[] = 'releitura_diff_pct_falhou';

                    Log::warning('[ComparadorScoreEmpresa] releitura de diff_pct falhou', [
                        'user_id'     => $user->id,
                        'company_id'  => $linhaEmpresa->company_id,
                        'periodo_key' => $competencia['periodo_key'],
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            DesempenhoComparadorEmpresa::create([
                'run_id'               => $runId,
                'user_id'              => $user->id,
                'company_id'           => $linhaEmpresa->company_id,
                'periodo_key'          => $competencia['periodo_key'],
                'competencia_alvo'     => $competencia['alvo'],
                'gerado_em'            => $geradoEm,
                'fonte_financeira'     => $fonteFinanceira,
                'status'               => $statusEmpresa,
                'nps_pontos'           => $linhaEmpresa->nps_pontos ?? null,
                'faturamento_var_pct'  => $linhaEmpresa->faturamento_var_pct ?? null,
                'faturamento_pontos'   => $linhaEmpresa->faturamento_pontos ?? null,
                'margem_var_pp'        => $linhaEmpresa->margem_var_pp ?? null,
                'margem_diff_pct'      => $margemDiffPct,
                'margem_pontos'        => $linhaEmpresa->margem_pontos ?? null,
                'nota_empresa'         => $linhaEmpresa->nota_empresa ?? null,
                'nota_empresa_parcial' => $linhaEmpresa->nota_empresa_parcial ?? null,
                'quality_motivos'      => $qualityMotivos,
            ]);
        }

        return [$total, $status];
    }

    /**
     * Mesmo filtro de `ConsolidarMesDesempenho` — users ativos com cargo
     * analista/estrategista em algum setor.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolverProfissionaisElegiveis(): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->get();
    }
}
