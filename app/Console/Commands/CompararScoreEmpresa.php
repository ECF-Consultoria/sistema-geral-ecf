<?php

namespace App\Console\Commands;

use App\Models\BonusFaixa;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoComparadorEmpresa;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
 *
 * Fase 121, Plano 03 (D-02) — na competência ALVO, o comando também
 * decompõe o delta em três parcelas (margem pp × relativa, régua-por-
 * empresa × régua-da-média, denominador) mais o resíduo da interação, e
 * classifica `faixa_antiga_inicial`/`faixa_nova_inicial` (pré-promoção,
 * D-06) via `BonusFaixa::classificar()` direto. Ver `calcularDecomposicao()`.
 */
class CompararScoreEmpresa extends Command
{
    protected $signature = 'desempenho:comparar-score-empresa
        {--mes= : competência FECHADA fixa no formato YYYY-MM (OBRIGATÓRIA — nunca competência implícita — exceto com --run=)}
        {--historico=2 : quantas competências anteriores coletar para o histograma da D-03 (0 desliga, máximo 6)}
        {--force : re-executa mesmo já havendo rodada de hoje para a mesma competência}
        {--run= : reimprime o relatório de um run_id já persistido, sem tocar a Adman e sem gravar nada — --mes deixa de ser obrigatório, a competência sai das próprias linhas}';

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
        // ── Modo --run=: reimpressão pura (Fase 121, Plano 04) ────────────
        // Nenhuma chamada a compute()/dispatcher, nenhuma gravação — só
        // reconsulta às duas tabelas do comparador para o run_id informado.
        // --mes NÃO é exigido aqui: a competência sai das próprias linhas.
        $runOption = $this->option('run');
        if (is_string($runOption) && $runOption !== '') {
            return $this->imprimirRelatorio($runOption, modoReimpressao: true);
        }

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

                    [$empresasTotal, $empresasStatus, $linhasEmpresa] = $this->persistirEmpresas(
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

                    // Faixas PRÉ-promoção (D-06 — correção pós-plan-check):
                    // BonusFaixa::classificar() direto, nunca uma função
                    // espelho. `faixa_antiga_oficial` (persistida acima, já
                    // pós-promoção DESEMP-08) não entra nesta comparação —
                    // comparar pós-promoção contra pré-promoção fabricaria
                    // mudança de faixa que não existe (T-121-22).
                    $faixaAntigaInicial = $notaAntiga !== null ? BonusFaixa::classificar($notaAntiga)?->slug : null;
                    $faixaNovaInicial   = $notaNova !== null ? BonusFaixa::classificar($notaNova)?->slug : null;
                    $mudouFaixa         = $faixaAntigaInicial !== null
                        && $faixaNovaInicial !== null
                        && $faixaAntigaInicial !== $faixaNovaInicial;

                    // A decomposição do delta só é calculada na competência
                    // ALVO — as históricas existem só para o histograma da
                    // D-03 (Plano 04).
                    if ($competencia['alvo']) {
                        [$decomposicao, $maiorCausaDelta] = $this->calcularDecomposicao($linhasEmpresa, $notaAntiga, $notaNova, $delta);
                    } else {
                        $decomposicao      = null;
                        $maiorCausaDelta   = null;
                    }

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
                        'faixa_antiga_inicial' => $faixaAntigaInicial,
                        'faixa_nova_inicial'   => $faixaNovaInicial,
                        'mudou_faixa'          => $mudouFaixa,
                        'empresas_total'       => $empresasTotal,
                        'empresas_complete'    => $empresasStatus['complete'],
                        'empresas_partial'     => $empresasStatus['partial'],
                        'empresas_sem_fonte'   => $empresasStatus['sem_fonte'],
                        'empresas_sem_dados'   => $empresasStatus['sem_dados'],
                        'decomposicao'         => $decomposicao,
                        'maior_causa_delta'    => $maiorCausaDelta,
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

        // ── Fase 121, Plano 04 — fase de apresentação, reconsultando o banco
        // (nunca os arrays acumulados acima) para o mesmo run_id gravado
        // nesta execução. `imprimirRelatorio()` é a MESMA rotina usada pelo
        // modo `--run=`.
        return $this->imprimirRelatorio($runId, modoReimpressao: false);
    }

    /**
     * Fase 121, Plano 04 — fase de apresentação (D-04: o comando APRESENTA,
     * quem DECIDE é o usuário). Toda leitura vem do banco — nunca dos arrays
     * acumulados durante a coleta — o que prova reconsultabilidade e é o
     * MESMO caminho usado tanto ao final da coleta quanto pelo modo
     * `--run=` (reimpressão pura, sem tocar a Adman, sem gravar nada).
     */
    private function imprimirRelatorio(string $runId, bool $modoReimpressao): int
    {
        $linhasProfissionais = DesempenhoComparadorProfissional::query()
            ->where('run_id', $runId)
            ->get();

        if ($linhasProfissionais->isEmpty()) {
            // Modo --run=: run_id inexistente/typo é erro do usuário — falha
            // ruidosamente. Ao final de uma coleta real, run_id vazio é
            // possível e legítimo (ex.: todos os profissionais elegíveis
            // ficaram sem carteira na competência) — nunca falhar a rodada
            // por isso, mesmo comportamento de antes do Plano 04.
            if ($modoReimpressao) {
                $this->error("[ComparadorScoreEmpresa] nenhuma linha encontrada para run_id={$runId}. Nada para reimprimir — confira o run_id.");

                return self::FAILURE;
            }

            $this->warn("[ComparadorScoreEmpresa] nenhuma linha persistida nesta rodada (run_id={$runId}) — todos os profissionais elegíveis ficaram sem carteira, ou a lista de elegíveis estava vazia. Nada para relatar.");

            return self::SUCCESS;
        }

        $linhasEmpresas = DesempenhoComparadorEmpresa::query()
            ->where('run_id', $runId)
            ->get();

        $mesAlvoKey = $linhasProfissionais->firstWhere('competencia_alvo', true)?->periodo_key
            ?? $linhasProfissionais->sortBy('periodo_key')->first()->periodo_key;

        $geradoEm = $linhasProfissionais->first()->gerado_em;

        $competenciasHistoricas = $linhasProfissionais
            ->where('competencia_alvo', false)
            ->pluck('periodo_key')
            ->unique()
            ->sort()
            ->values();

        $linhasProfissionaisAlvo = $linhasProfissionais->where('periodo_key', $mesAlvoKey)->values();
        $linhasEmpresasAlvo      = $linhasEmpresas->where('periodo_key', $mesAlvoKey)->values();

        $processados = $linhasProfissionaisAlvo->where('falhou', false)->count();
        $falhados    = $linhasProfissionaisAlvo->where('falhou', true)->count();

        // "sem carteira" NUNCA é persistido (o usuário que não tem carteira
        // não gera linha nenhuma) — recalculado no momento da IMPRESSÃO a
        // partir da elegibilidade ATUAL (mesmo filtro de
        // `resolverProfissionaisElegiveis()`), reconsultando o banco. Pode
        // divergir do momento da coleta se a composição de setores mudou
        // entre as duas leituras — limitação explícita no relatório, nunca
        // escondida.
        $elegiveisAgora = $this->resolverProfissionaisElegiveis()->count();
        $semCarteira    = max(0, $elegiveisAgora - $processados - $falhados);

        $this->imprimirCabecalho($runId, $geradoEm, $mesAlvoKey, $competenciasHistoricas, $processados, $falhados, $semCarteira);
        $this->imprimirTabelaProfissionais($linhasProfissionaisAlvo);
        $this->imprimirSecaoMudouFaixa($linhasProfissionaisAlvo);
        $this->imprimirSecaoDeltaZeroStatusMudou($linhasProfissionaisAlvo);
        $this->imprimirSecaoDecomposicao($linhasProfissionaisAlvo);
        $this->imprimirAmostrasDeRisco($linhasProfissionaisAlvo, $linhasEmpresasAlvo, $mesAlvoKey);
        $this->imprimirHistograma($linhasEmpresas, $mesAlvoKey, $competenciasHistoricas);

        $this->warn("[ComparadorScoreEmpresa] AVISO: este console é um ESPELHO. A conferência OFICIAL é por reconsulta ao banco (desempenho_comparador_profissionais / desempenho_comparador_empresas, run_id={$runId}), nunca por este stdout — mesma disciplina que o gate FIXMARG-03 já exige neste projeto. Este comando NÃO APROVA NEM REPROVA nada (D-04) — quem decide é o usuário.");

        return self::SUCCESS;
    }

    /** Cabeçalho de rastreabilidade (T-121-30) — sempre a PRIMEIRA coisa impressa. */
    private function imprimirCabecalho(
        string $runId,
        Carbon $geradoEm,
        string $mesAlvoKey,
        Collection $competenciasHistoricas,
        int $processados,
        int $falhados,
        int $semCarteira
    ): void {
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info(sprintf('[ComparadorScoreEmpresa] RELATÓRIO — run_id=%s', $runId));
        $this->info(sprintf('  Gerado em: %s', $geradoEm->format('d/m/Y H:i:s')));
        $this->info(sprintf('  Competência alvo: %s', $mesAlvoKey));
        $this->info(sprintf(
            '  Competências históricas incluídas: %s',
            $competenciasHistoricas->isNotEmpty() ? $competenciasHistoricas->implode(', ') : 'nenhuma'
        ));
        $this->info(sprintf(
            '  Profissionais (competência alvo) — processados: %d, falhados: %d, sem carteira: %d (recalculado no momento da impressão a partir da elegibilidade atual)',
            $processados,
            $falhados,
            $semCarteira
        ));
        $this->info('════════════════════════════════════════════════════════════════');
    }

    /**
     * Tabela por profissional da competência alvo, ordenada pelo delta mais
     * negativo primeiro (nulos por último). Profissionais com `falhou=true`
     * aparecem numa seção separada, com o erro — nunca silenciados.
     */
    private function imprimirTabelaProfissionais(Collection $linhas): void
    {
        $ok = $linhas->where('falhou', false)
            ->sortBy(fn ($l) => $l->delta ?? PHP_INT_MAX)
            ->values();

        $nomes = $this->nomesPorUserId($ok->pluck('user_id'));

        $rows = [];
        foreach ($ok as $l) {
            $rows[] = [
                $nomes[$l->user_id] ?? "user #{$l->user_id}",
                $l->nota_antiga !== null ? number_format((float) $l->nota_antiga, 2) : '—',
                $l->nota_nova !== null ? number_format((float) $l->nota_nova, 2) : '—',
                $l->delta !== null ? number_format((float) $l->delta, 2) : '—',
                ($l->status_antigo ?? '—') . ' → ' . ($l->status_novo ?? '—'),
                ($l->faixa_antiga_inicial ?? '—') . ' → ' . ($l->faixa_nova_inicial ?? '—'),
                sprintf('%d/%d/%d', $l->empresas_total, $l->empresas_complete, $l->empresas_partial),
                $l->maior_causa_delta ?? '—',
            ];
        }

        $this->info('── Tabela por profissional (competência alvo, delta mais negativo primeiro) ──');
        $this->table(
            ['Profissional', 'Nota antiga', 'Nota nova', 'Delta', 'Status (antigo → novo)', 'Faixa pré-promoção (antiga → nova)', 'Empresas (total/complete/partial)', 'Maior causa do delta'],
            $rows
        );

        $falhados = $linhas->where('falhou', true)->values();
        if ($falhados->isNotEmpty()) {
            $nomesFalha = $this->nomesPorUserId($falhados->pluck('user_id'));

            $this->warn('── Profissionais que FALHARAM ao processar (nunca silenciados) ──');
            foreach ($falhados as $l) {
                $this->line(sprintf('  - %s: %s', $nomesFalha[$l->user_id] ?? "user #{$l->user_id}", $l->erro ?? '(sem mensagem de erro)'));
            }
        }
    }

    /** Seção "mudou de faixa" — só profissionais com `mudou_faixa=true` (D-04). */
    private function imprimirSecaoMudouFaixa(Collection $linhas): void
    {
        $this->info('── Mudou de faixa (pré-promoção) ──');

        $mudou = $linhas->where('mudou_faixa', true)->values();

        if ($mudou->isEmpty()) {
            $this->line('  Nenhum profissional mudou de faixa nesta competência.');

            return;
        }

        $nomes = $this->nomesPorUserId($mudou->pluck('user_id'));
        foreach ($mudou as $l) {
            $this->line(sprintf('  - %s: %s → %s', $nomes[$l->user_id] ?? "user #{$l->user_id}", $l->faixa_antiga_inicial ?? '—', $l->faixa_nova_inicial ?? '—'));
        }
    }

    /**
     * Seção "delta zero mas comportamento mudou" — caso concreto (Fase 120,
     * cenários "Misto" e "Invalidação"): delta igual a zero (ou nulo nos
     * dois lados) com `status_antigo` diferente de `status_novo`. "Sem delta
     * de nota" não significa "sem mudança".
     */
    private function imprimirSecaoDeltaZeroStatusMudou(Collection $linhas): void
    {
        $this->info('── Delta zero mas comportamento mudou ──');

        $casos = $linhas->filter(function ($l) {
            $deltaZeroOuNulo = ($l->delta !== null && (float) $l->delta === 0.0)
                || ($l->nota_antiga === null && $l->nota_nova === null);

            return $deltaZeroOuNulo && $l->status_antigo !== $l->status_novo;
        })->values();

        if ($casos->isEmpty()) {
            $this->line('  Nenhum caso nesta competência.');

            return;
        }

        $nomes = $this->nomesPorUserId($casos->pluck('user_id'));
        foreach ($casos as $l) {
            $this->line(sprintf(
                '  - %s: status %s → %s (delta=%s) — "sem delta de nota" não significa "sem mudança".',
                $nomes[$l->user_id] ?? "user #{$l->user_id}",
                $l->status_antigo ?? '—',
                $l->status_novo ?? '—',
                $l->delta !== null ? number_format((float) $l->delta, 2) : 'null'
            ));
        }
    }

    /**
     * Seção da decomposição por pessoa (D-02, Plano 03) — as três parcelas,
     * o resíduo e a maior causa, com a nota fixa em pt-BR explicando por que
     * as parcelas não somam o delta sozinhas.
     */
    private function imprimirSecaoDecomposicao(Collection $linhas): void
    {
        $this->info('── Decomposição do delta por profissional (P1 margem pp×relativa / P2 régua-por-empresa×régua-da-média / P3 denominador / resíduo) ──');
        $this->line('  As parcelas não somam o delta sozinhas porque os efeitos interagem entre si — o resíduo é a parte da mudança não decomposta por nenhuma das três variáveis testadas, nunca omitido da impressão.');

        $comDecomposicao = $linhas
            ->filter(fn ($l) => is_array($l->decomposicao) && array_key_exists('parcelas', $l->decomposicao))
            ->values();

        if ($comDecomposicao->isEmpty()) {
            $this->line('  Nenhuma decomposição disponível nesta competência.');

            return;
        }

        $nomes = $this->nomesPorUserId($comDecomposicao->pluck('user_id'));

        $rows = [];
        foreach ($comDecomposicao as $l) {
            $d = $l->decomposicao;
            $rows[] = [
                $nomes[$l->user_id] ?? "user #{$l->user_id}",
                $this->fmtNullable($d['parcelas']['margem_pp_vs_relativa'] ?? null),
                $this->fmtNullable($d['parcelas']['regua_por_empresa_vs_regua_da_media'] ?? null),
                $this->fmtNullable($d['parcelas']['denominador'] ?? null),
                $this->fmtNullable($d['residuo'] ?? null),
                $l->maior_causa_delta ?? '—',
            ];
        }

        $this->table(['Profissional', 'P1 margem pp×relativa', 'P2 régua-por-empresa×régua-da-média', 'P3 denominador', 'Resíduo', 'Maior causa'], $rows);
    }

    /**
     * As 7 amostras de risco (ROLL-02) — cada uma identificada
     * PROGRAMATICAMENTE sobre as linhas persistidas da competência alvo,
     * nunca por nome/ID chumbado no código. Uma amostra sem candidato
     * imprime "nenhum candidato nesta competência", nunca some da saída.
     */
    private function imprimirAmostrasDeRisco(Collection $linhasProfissionaisAlvo, Collection $linhasEmpresasAlvo, string $mesAlvoKey): void
    {
        $this->info('── Amostras de risco (ROLL-02) — 7 candidatos para conferência manual ──');

        $processados = $linhasProfissionaisAlvo->where('falhou', false)->values();

        // 1/2. Profissional com poucas/muitas empresas.
        $this->imprimirAmostraProfissional('1. Profissional com poucas empresas', $processados->sortBy('empresas_total')->first());
        $this->imprimirAmostraProfissional('2. Profissional com muitas empresas', $processados->sortByDesc('empresas_total')->first());

        // 3. Empresa com queda grande de faturamento — mais negativa,
        // destacando também quantas estão em queda severa (nota 1 na régua
        // de faturamento, corte de -6%/-1%/1%/5% — DesempenhoScoreService::reguaFaturamento()).
        $piorFaturamento = $linhasEmpresasAlvo->whereNotNull('faturamento_var_pct')->sortBy('faturamento_var_pct')->first();
        $quedaSevera     = $linhasEmpresasAlvo->where('faturamento_pontos', 1.0)->count();

        if ($piorFaturamento !== null) {
            $nome = $this->nomeCompany($piorFaturamento->company_id);
            $this->line(sprintf(
                '  3. Empresa com queda grande de faturamento: %s (faturamento_var_pct=%s%%). Empresas em queda severa (nota 1 na régua de faturamento): %d.',
                $nome,
                number_format((float) $piorFaturamento->faturamento_var_pct, 2),
                $quedaSevera
            ));
        } else {
            $this->line('  3. Empresa com queda grande de faturamento: nenhum candidato nesta competência.');
        }

        // 4. Empresa com pp positivo — o maior margem_var_pp acima de zero.
        $melhorMargem = $linhasEmpresasAlvo->filter(fn ($l) => $l->margem_var_pp !== null && (float) $l->margem_var_pp > 0)->sortByDesc('margem_var_pp')->first();

        if ($melhorMargem !== null) {
            $this->line(sprintf('  4. Empresa com pp positivo: %s (margem_var_pp=%s pp).', $this->nomeCompany($melhorMargem->company_id), number_format((float) $melhorMargem->margem_var_pp, 2)));
        } else {
            $this->line('  4. Empresa com pp positivo: nenhum candidato nesta competência.');
        }

        // 5. Empresa sem baseline — faturamento_pontos nulo com o motivo
        // faturamento_sem_baseline presente em quality_motivos.
        $semBaseline = $linhasEmpresasAlvo->first(fn ($l) => $l->faturamento_pontos === null && in_array('faturamento_sem_baseline', $l->quality_motivos ?? [], true));

        if ($semBaseline !== null) {
            $this->line(sprintf('  5. Empresa sem baseline: %s.', $this->nomeCompany($semBaseline->company_id)));
        } else {
            $this->line('  5. Empresa sem baseline: nenhum candidato nesta competência.');
        }

        // 6. Empresa invalidada — PROVA DE AUSÊNCIA. Cruza
        // BonusInvalidacao::companyIdsInvalidadas() com os company_id
        // persistidos do run nesta competência. Esperado: zero presentes.
        $mesAlvoCarbon  = Carbon::createFromFormat('Y-m-d', "{$mesAlvoKey}-01")->startOfMonth();
        $idsInvalidados = BonusInvalidacao::companyIdsInvalidadas($mesAlvoCarbon);
        $idsPersistidos = $linhasEmpresasAlvo->pluck('company_id')->unique();
        $presentes      = $idsInvalidados->intersect($idsPersistidos)->values();

        if ($presentes->isEmpty()) {
            $this->line(sprintf(
                '  6. Empresa invalidada: %d empresa(s) invalidada(s) na competência %s — VEREDITO: ausência confirmada (0 presentes nas linhas persistidas, como esperado).',
                $idsInvalidados->count(),
                $mesAlvoKey
            ));
        } else {
            $this->error(sprintf(
                '  6. Empresa invalidada: ANOMALIA — %d empresa(s) invalidada(s) PRESENTE(S) nas linhas persistidas (company_id: %s). Isso seria sinal de que a invalidação parou de ser respeitada.',
                $presentes->count(),
                $presentes->implode(', ')
            ));
        }

        // 7. Profissional com Shopee — pelo menos uma linha com
        // fonte_financeira='shopee'.
        $shopeeUserIds = $linhasEmpresasAlvo->where('fonte_financeira', 'shopee')->pluck('user_id')->unique();

        if ($shopeeUserIds->isNotEmpty()) {
            $userId      = $shopeeUserIds->first();
            $nome        = $this->nomesPorUserId(collect([$userId]))[$userId] ?? "user #{$userId}";
            $qtdShopee   = $linhasEmpresasAlvo->where('user_id', $userId)->where('fonte_financeira', 'shopee')->count();
            $this->line(sprintf(
                '  7. Profissional com Shopee: %s (%d empresa(s) Shopee na carteira) — o placeholder de margem 1.0 puxa a nota para baixo em carteira só-Shopee.',
                $nome,
                $qtdShopee
            ));
        } else {
            $this->line('  7. Profissional com Shopee: nenhum candidato nesta competência.');
        }

        $this->info('  Referência de sanidade (CONTEXT): a leitura da carteira do Luiz deu por volta de −0,59 pp, que na régua reusada é nota 3 — contra régua 5 no snapshot congelado e régua 1 no cálculo local revertido.');
    }

    /** Ajuda `imprimirAmostrasDeRisco()` para os blocos 1/2 (profissional). */
    private function imprimirAmostraProfissional(string $label, $linha): void
    {
        if ($linha === null) {
            $this->line("  {$label}: nenhum candidato nesta competência.");

            return;
        }

        $nome = $this->nomesPorUserId(collect([$linha->user_id]))[$linha->user_id] ?? "user #{$linha->user_id}";
        $this->line(sprintf('  %s: %s (%d empresas).', $label, $nome, $linha->empresas_total));
    }

    /**
     * Gate nº 3 (D-03) — histograma de `margem_var_pp` por competência,
     * deduplicado por `company_id` DENTRO de cada competência, só linhas com
     * `fonte_financeira` não nula e `margem_var_pp` não nulo. Buckets vêm da
     * régua-espelho pública (`$this->scoreService->reguaMargem()`, D-07) —
     * nunca uma segunda cópia dos cortes.
     */
    private function imprimirHistograma(Collection $linhasEmpresasTodas, string $mesAlvoKey, Collection $competenciasHistoricas): void
    {
        $this->info('── Distribuição de margem_var_pp (ROLL-03) ──');

        $periodosOrdenados = collect([$mesAlvoKey])->concat($competenciasHistoricas)->unique()->sort()->values();

        if ($periodosOrdenados->count() < 3) {
            $this->warn('  AVISO: menos de três competências coletadas nesta rodada — uma (ou duas) competência(s) só não distingue(m) "a régua comprime" de "o mês foi atípico". Rode com --historico >= 2 para uma leitura completa.');
        }

        $consolidadoContagens = array_fill(1, 5, 0);
        $consolidadoTotal     = 0;

        foreach ($periodosOrdenados as $periodoKey) {
            $linhasPeriodo = $linhasEmpresasTodas
                ->where('periodo_key', $periodoKey)
                ->whereNotNull('fonte_financeira')
                ->whereNotNull('margem_var_pp');

            // Dedupe por company_id DENTRO da competência — a mesma empresa
            // em duas carteiras (Fase 76) conta uma vez só.
            $deduplicadas = $linhasPeriodo->unique('company_id')->values();

            $contagens = array_fill(1, 5, 0);
            $ppValues  = [];
            foreach ($deduplicadas as $l) {
                $nota            = (int) round((float) $this->scoreService->reguaMargem((float) $l->margem_var_pp));
                $contagens[$nota] = ($contagens[$nota] ?? 0) + 1;
                $ppValues[]      = (float) $l->margem_var_pp;
            }

            $totalEmpresas = $deduplicadas->count();
            $somaContagens = array_sum($contagens);

            // Sanidade (T-121-32): a soma das contagens tem que ser
            // exatamente o número de empresas distintas elegíveis.
            if ($somaContagens !== $totalEmpresas) {
                $this->error(sprintf(
                    '  ANOMALIA de sanidade em %s: soma das contagens do histograma (%d) difere do número de empresas distintas elegíveis (%d).',
                    $periodoKey,
                    $somaContagens,
                    $totalEmpresas
                ));
            }

            $this->line(sprintf('  Competência %s — %d empresa(s) distinta(s) elegível(is):', $periodoKey, $totalEmpresas));

            if ($totalEmpresas === 0) {
                $this->line('    Nenhuma empresa elegível nesta competência.');
            } else {
                for ($nota = 1; $nota <= 5; $nota++) {
                    $pct = round(($contagens[$nota] / $totalEmpresas) * 100, 1);
                    $this->line(sprintf('    Nota %d: %d (%.1f%%)', $nota, $contagens[$nota], $pct));
                }

                $pct34 = round((($contagens[3] + $contagens[4]) / $totalEmpresas) * 100, 1);
                $this->info(sprintf('    >>> Percentual nas notas 3+4: %.1f%% <<< (a pergunta que a D-03 manda este histograma responder)', $pct34));

                sort($ppValues);
                $min       = min($ppValues);
                $max       = max($ppValues);
                $mediana   = $this->mediana($ppValues);
                $positivos = count(array_filter($ppValues, fn ($v) => $v > 0));

                $this->line(sprintf(
                    '    Estatísticas: mínimo %.2f pp, máximo %.2f pp, mediana %.2f pp, %d empresa(s) com pp positivo.',
                    $min,
                    $max,
                    $mediana,
                    $positivos
                ));
            }

            for ($nota = 1; $nota <= 5; $nota++) {
                $consolidadoContagens[$nota] += $contagens[$nota];
            }
            $consolidadoTotal += $totalEmpresas;
        }

        if ($consolidadoTotal > 0) {
            $this->line('  Consolidado (todas as competências coletadas — cada empresa conta uma vez POR competência):');
            for ($nota = 1; $nota <= 5; $nota++) {
                $pct = round(($consolidadoContagens[$nota] / $consolidadoTotal) * 100, 1);
                $this->line(sprintf('    Nota %d: %d (%.1f%%)', $nota, $consolidadoContagens[$nota], $pct));
            }

            $pct34Consolidado = round((($consolidadoContagens[3] + $consolidadoContagens[4]) / $consolidadoTotal) * 100, 1);
            $this->info(sprintf('    >>> Percentual consolidado nas notas 3+4: %.1f%% <<<', $pct34Consolidado));

            // Interpretação em pt-BR, SEM veredito (D-04) — o comando não
            // conclui nada sozinho.
            if ($pct34Consolidado >= 50.0) {
                $this->line('  Interpretação (sem veredito): a concentração em 3-4 está alta — se isso se confirmar nas três competências, a compressão que o usuário aceitou conscientemente ao reusar a régua (D2 da milestone) está confirmada em número, e recalibrar a régua vira pauta de diretoria (fora do escopo desta milestone). Este comando NÃO CONCLUI NADA SOZINHO — quem decide é o usuário.');
            } else {
                $this->line('  Interpretação (sem veredito): a concentração em 3-4 não aparenta ser dominante nesta leitura. Este comando NÃO CONCLUI NADA SOZINHO — quem decide é o usuário.');
            }
        }
    }

    /** Mediana simples de um array de floats já pode vir desordenado. */
    private function mediana(array $values): float
    {
        sort($values);
        $count  = count($values);
        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2 === 0) {
            return round(($values[$middle] + $values[$middle + 1]) / 2, 2);
        }

        return round($values[$middle], 2);
    }

    /** @return array<int, string> user_id => name */
    private function nomesPorUserId(Collection $userIds): array
    {
        return User::query()->whereIn('id', $userIds->unique())->pluck('name', 'id')->all();
    }

    private function nomeCompany(int $companyId): string
    {
        return Company::query()->whereKey($companyId)->value('name') ?? "empresa #{$companyId}";
    }

    private function fmtNullable(?float $v): string
    {
        return $v !== null ? number_format($v, 2) : '—';
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
     * @return array{0: int, 1: array{complete: int, partial: int, sem_fonte: int, sem_dados: int}, 2: array<int, array{company_id: int, status: string, fonte_financeira: ?string, nps_pontos: ?float, faturamento_var_pct: ?float, faturamento_pontos: ?float, margem_var_pp: ?float, margem_diff_pct: ?float, margem_pontos: ?float, nota_empresa: ?float, nota_empresa_parcial: ?float}>}
     *   O 3º elemento é o insumo cru — mesmos campos já persistidos em
     *   `desempenho_comparador_empresas` — para o Plano 03 (Task 1) calcular
     *   a decomposição do delta sem reconsultar o banco.
     */
    private function persistirEmpresas(
        User $user,
        array $competencia,
        array $payload,
        string $runId,
        Carbon $geradoEm,
        array &$companiesCache
    ): array {
        $total          = 0;
        $status         = ['complete' => 0, 'partial' => 0, 'sem_fonte' => 0, 'sem_dados' => 0];
        $linhasCapturadas = [];

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

            // Mesmos campos persistidos acima, guardados por referência para
            // a decomposição do delta (Plano 03, Task 1) — nunca reconsultar
            // o banco dentro do mesmo request só para reobter o que acabou
            // de ser gravado.
            $linhasCapturadas[] = [
                'company_id'           => $linhaEmpresa->company_id,
                'status'               => $statusEmpresa,
                'fonte_financeira'     => $fonteFinanceira,
                'nps_pontos'           => $linhaEmpresa->nps_pontos ?? null,
                'faturamento_var_pct'  => $linhaEmpresa->faturamento_var_pct ?? null,
                'faturamento_pontos'   => $linhaEmpresa->faturamento_pontos ?? null,
                'margem_var_pp'        => $linhaEmpresa->margem_var_pp ?? null,
                'margem_diff_pct'      => $margemDiffPct,
                'margem_pontos'        => $linhaEmpresa->margem_pontos ?? null,
                'nota_empresa'         => $linhaEmpresa->nota_empresa ?? null,
                'nota_empresa_parcial' => $linhaEmpresa->nota_empresa_parcial ?? null,
            ];
        }

        return [$total, $status, $linhasCapturadas];
    }

    /**
     * Decomposição do delta (D-02, `121-03-PLAN.md`) — atribui a mudança de
     * nota a três causas isoladas uma variável por vez, mais o resíduo da
     * interação entre elas. Chamado SÓ na competência alvo.
     *
     * CORREÇÃO PÓS-PLAN-CHECK (D-06/D-07, `<correcao_pos_plan_check>` do
     * plano): nenhuma função "espelho" é criada aqui. `reguaMargem()` é
     * chamada direto em `$this->scoreService` (tornada pública pela Fase 121
     * Plano 03) e a faixa usa `BonusFaixa::classificar()` direto (chamado no
     * `handle()`, não aqui). O blend ponderado por contagem da margem (P2) é
     * a MESMA fórmula de `DesempenhoScoreService::margemPontos()` — que
     * continua privada e intocada — implementada aqui apenas como aritmética
     * simples sobre médias, não como cópia de método.
     *
     * P1 · margem em pp × relativa — recalcula `margem_pontos` de cada
     * empresa de C usando `reguaMargem(margem_diff_pct)` no lugar de
     * `reguaMargem(margem_var_pp)`. Shopee mantém o placeholder 1.0.
     * P2 · régua-por-empresa × régua-da-média — UMA nota calculada sobre as
     * médias dos insumos de C.
     * P3 · denominador — média de `nota_empresa_parcial` de TODAS as linhas
     * (não só C) onde ela não é null.
     * Resíduo — `delta − (P1 + P2 + P3)`, NUNCA escondido (T-121-21).
     *
     * @param  array<int, array{company_id: int, status: string, fonte_financeira: ?string, nps_pontos: ?float, faturamento_var_pct: ?float, faturamento_pontos: ?float, margem_var_pp: ?float, margem_diff_pct: ?float, margem_pontos: ?float, nota_empresa: ?float, nota_empresa_parcial: ?float}>  $linhasEmpresa
     * @return array{0: array<string, mixed>, 1: ?string}  [decomposicao, maior_causa_delta]
     */
    private function calcularDecomposicao(array $linhasEmpresa, ?float $notaAntiga, ?float $notaNova, ?float $delta): array
    {
        // Guard — delta indefinido: NUNCA tratar como zero. Shape mínimo,
        // exatamente como o plano descreve.
        if ($delta === null) {
            return [['motivo' => 'delta_indefinido'], null];
        }

        // Conjunto C — mesmo denominador que produz nota_nova.
        $conjuntoC = array_values(array_filter($linhasEmpresa, fn (array $l) => $l['status'] === 'complete'));

        if (empty($conjuntoC)) {
            return [[
                'motivo'               => 'conjunto_c_vazio',
                'parcelas'             => [
                    'margem_pp_vs_relativa'                => null,
                    'regua_por_empresa_vs_regua_da_media'   => null,
                    'denominador'                           => null,
                ],
                'residuo'              => null,
                'delta_total'          => $delta,
                'notas_contrafactuais' => [
                    'alt1_margem_relativa'      => null,
                    'alt2_regua_da_media'       => null,
                    'alt3_denominador_ampliado' => null,
                ],
                'avisos' => ['p1_empresas_sem_diff_pct' => 0],
            ], null];
        }

        // ── P1 · margem em pp × relativa ────────────────────────────────
        $p1EmpresasSemDiffPct = 0;
        $notasRecalculadasP1  = [];

        foreach ($conjuntoC as $linha) {
            if ($linha['fonte_financeira'] === 'shopee') {
                // D-02 — Shopee não tem margem; diff_pct é null por
                // construção. Mantém o placeholder 1.0, nunca aplica régua.
                $margemAlt1 = 1.0;
            } elseif ($linha['margem_diff_pct'] === null) {
                // Empresa Adman sem diff_pct sai de P1 — contada, nunca
                // escondida (o relatório informa, não finge inexistência).
                $p1EmpresasSemDiffPct++;

                continue;
            } else {
                $margemAlt1 = $this->scoreService->reguaMargem($linha['margem_diff_pct']);
            }

            $notasRecalculadasP1[] = ($linha['nps_pontos'] + $linha['faturamento_pontos'] + $margemAlt1) / 3;
        }

        $notaAlt1 = ! empty($notasRecalculadasP1) ? round(array_sum($notasRecalculadasP1) / count($notasRecalculadasP1), 2) : null;
        $p1       = $notaAlt1 !== null ? round($notaNova - $notaAlt1, 2) : null;

        // ── P2 · régua-por-empresa × régua-da-média ─────────────────────
        // NPS — média dos pontos de C, clamp [1,5] como o legado faz.
        $mediaNps = collect($conjuntoC)->avg('nps_pontos');
        $npsAlt2  = max(1.0, min(5.0, $mediaNps));

        // Faturamento — régua sobre a média de faturamento_var_pct de C.
        $mediaFaturamentoVarPct = collect($conjuntoC)->avg('faturamento_var_pct');
        $faturamentoAlt2        = $this->scoreService->reguaFaturamento($mediaFaturamentoVarPct);

        // Margem — o MESMO blend ponderado por contagem de
        // `DesempenhoScoreService::margemPontos()` (Fase 109, intocado),
        // só que sobre a média de C em vez de sobre a carteira inteira.
        $empresasAdmanC   = collect($conjuntoC)->where('fonte_financeira', 'adman')->values();
        $empresasShopeeC  = collect($conjuntoC)->where('fonte_financeira', 'shopee')->values();
        $nComMargemReal   = $empresasAdmanC->count();
        $nShopeePlaceholder = $empresasShopeeC->count();
        $mediaMargemVarPp = $nComMargemReal > 0 ? $empresasAdmanC->avg('margem_var_pp') : null;
        $pRealAlt2        = $mediaMargemVarPp !== null ? $this->scoreService->reguaMargem($mediaMargemVarPp) : null;
        $numeradorMargem  = ($pRealAlt2 !== null ? $pRealAlt2 * $nComMargemReal : 0.0) + 1.0 * $nShopeePlaceholder;
        $denominadorMargem = $nComMargemReal + $nShopeePlaceholder;
        $margemAlt2       = $denominadorMargem > 0 ? round($numeradorMargem / $denominadorMargem, 2) : null;

        $notaAlt2 = $margemAlt2 !== null
            ? round(($npsAlt2 + $faturamentoAlt2 + $margemAlt2) / 3, 2)
            : null;
        $p2 = $notaAlt2 !== null ? round($notaNova - $notaAlt2, 2) : null;

        // ── P3 · denominador ─────────────────────────────────────────────
        // TODAS as linhas (não só C) com nota_empresa_parcial não-null —
        // inclui partial e sem_fonte, denominador ampliado.
        $notasParciais = collect($linhasEmpresa)->pluck('nota_empresa_parcial')->reject(fn ($v) => $v === null);
        $notaAlt3      = $notasParciais->isNotEmpty() ? round($notasParciais->avg(), 2) : null;
        $p3            = $notaAlt3 !== null ? round($notaNova - $notaAlt3, 2) : null;

        // ── Resíduo — NUNCA escondido, mesmo quando diferente de zero ────
        $parcelasConhecidas = collect([$p1, $p2, $p3])->reject(fn ($v) => $v === null);
        $residuo            = $parcelasConhecidas->isNotEmpty()
            ? round($delta - $parcelasConhecidas->sum(), 2)
            : null;

        // ── Maior causa — maior magnitude entre as 3 parcelas + resíduo;
        // empate resolve pela ordem determinística margem, régua,
        // denominador, resíduo (ordem de iteração abaixo).
        $candidatos = [
            'margem_pp_vs_relativa'              => $p1,
            'regua_por_empresa_vs_regua_da_media' => $p2,
            'denominador'                         => $p3,
            'interacao_nao_decomposta'            => $residuo,
        ];

        $maiorCausaDelta = null;
        $maiorMagnitude  = -1.0;
        foreach ($candidatos as $label => $valor) {
            if ($valor === null) {
                continue;
            }

            $magnitude = abs($valor);
            if ($magnitude > $maiorMagnitude) {
                $maiorMagnitude  = $magnitude;
                $maiorCausaDelta = $label;
            }
        }

        $avisos = ['p1_empresas_sem_diff_pct' => $p1EmpresasSemDiffPct];
        if ($p1 === null) {
            $avisos['p1_indisponivel'] = 'todas_as_empresas_de_c_sem_diff_pct_ou_shopee';
        }

        $decomposicao = [
            'parcelas' => [
                'margem_pp_vs_relativa'               => $p1,
                'regua_por_empresa_vs_regua_da_media'  => $p2,
                'denominador'                          => $p3,
            ],
            'residuo'              => $residuo,
            'delta_total'          => $delta,
            'notas_contrafactuais' => [
                'alt1_margem_relativa'      => $notaAlt1,
                'alt2_regua_da_media'       => $notaAlt2,
                'alt3_denominador_ampliado' => $notaAlt3,
            ],
            'avisos' => $avisos,
        ];

        return [$decomposicao, $maiorCausaDelta];
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
