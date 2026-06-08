<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\EcfDriveService;
use App\Services\ForecastService;
use Inertia\Inertia;

/**
 * Concentração de Receita e Forecast 90d (Phase 27).
 *
 * Método show() orquestra:
 *   1) Matriz programa × cluster (carteiraSegmentacao)
 *   2) Histórico 12 meses + regressão linear (carteiraHistorico + ForecastService)
 *   3) Grants expirando em 90d (grantsExpirandoEm)
 *   4) Ranking top 50 sellers por tgmv_lc (ranking)
 *   5) Métricas mensais × 50 sellers em loop (sellerMetricasMensal)
 *   6) Cálculo de CV per-seller (ForecastService::coeficienteVariacao)
 *   7) Lookup batch Company (sem N+1)
 *   8) Top 20 vacas leiteiras (CV ASC)
 *   9) Forecast 3 cenários (GMV em risco distribuído uniformemente)
 *
 * try/catch global garante que ECF Drive offline não quebra pageload (D-07 CONTEXT).
 * Cache NO controller — wrapper EcfDriveService já cacheia (D-08 CONTEXT).
 */
class ConcentracaoController extends Controller
{
    public function __construct(
        private EcfDriveService $ecf,
        private ForecastService $forecast,
    ) {}

    /**
     * Renderiza a aba Concentração de Receita e Forecast 90d.
     *
     * Só admin pode acessar — gateado pela rota via middleware role:admin (CONTEXT D-01).
     */
    public function show()
    {
        try {
            // ─── 1) Matriz programa × cluster ──────────────────────────────
            $segResp     = $this->ecf->carteiraSegmentacao('programa,cluster');
            // Shape: ['segmentacao' => [...]] ou, se vier paginado, ['data' => [...]]
            $segmentacao = $segResp['segmentacao'] ?? $segResp['data'] ?? [];

            // Extrai linhas/colunas únicas preservando ordem de aparição
            $linhas   = [];
            $colunas  = [];
            $celulas  = [];
            $gmvTotal = 0;
            foreach ($segmentacao as $celula) {
                $programa = $celula['programa'] ?? 'SEM_PROGRAMA';
                $cluster  = $celula['cluster']  ?? 'SEM_CLUSTER';
                $gmv      = (float) ($celula['gmv']     ?? 0);
                $sellers  = (int)   ($celula['sellers'] ?? 0);

                if (!in_array($programa, $linhas))  $linhas[]  = $programa;
                if (!in_array($cluster,  $colunas)) $colunas[] = $cluster;

                $celulas[] = compact('programa', 'cluster', 'gmv', 'sellers');
                $gmvTotal += $gmv;
            }

            // Calcula pct de cada célula e determina top 5
            foreach ($celulas as &$c) {
                $c['pct'] = $gmvTotal > 0 ? ($c['gmv'] / $gmvTotal) * 100 : 0;
            }
            unset($c);

            $top5 = collect($celulas)->sortByDesc('pct')->take(5)->values()->all();

            $matriz = [
                'linhas'   => $linhas,
                'colunas'  => $colunas,
                'celulas'  => $celulas,
                'top5'     => $top5,
                'gmvTotal' => $gmvTotal,
            ];

            // ─── 2) Histórico 12 meses + regressão linear ──────────────────
            $histResp = $this->ecf->carteiraHistorico('mensal');
            // Shape PAGINADO: ['data' => [...]] — EXTRAIR antes de processar (pitfall recorrente Phase 25)
            $historico = $histResp['data'] ?? [];

            // Série Y pra regressão: só pontos confiáveis.
            //   1) Mantém shape original em $historico (chart visual usa)
            //   2) Pra regressão: usa SEMPRE gmv (consistente com carteiraResumo do Painel Exec).
            //   3) Exclui zeros (mês sem dado consolidado — distorce a linha)
            //   4) Exclui mês corrente parcial (em construção pelo ETL D-1 ao longo do dia,
            //      puxa regressão pra baixo artificialmente)
            $mesAtual = now()->format('Ym');
            $serieY = [];
            foreach ($historico as $mes) {
                $periodo = (string) ($mes['periodo'] ?? $mes['timMonthId'] ?? '');
                $gmv     = (float) ($mes['gmv'] ?? 0);

                // Exclui mês corrente parcial — só vira ponto de regressão depois de fechado
                if ($periodo === $mesAtual) continue;

                // Exclui zeros (sem dado — não é "zero faturou", é "ainda não populado")
                if ($gmv <= 0) continue;

                $serieY[] = $gmv;
            }

            $coef     = $this->forecast->regressaoLinear($serieY);
            // startX = quantos meses fechados temos. Projeção começa NO PRÓXIMO ponto.
            $projecao = $this->forecast->projetar($coef, 3, count($serieY));

            // ─── 3) Grants expirando em 90d ────────────────────────────────
            $grantsResp = $this->ecf->grantsExpirandoEm(90);
            // Shape PAGINADO: ['data' => [...]] — EXTRAIR antes de iterar (pitfall Phase 25-03/05)
            $grants = $grantsResp['data'] ?? [];

            // ─── 4) Ranking top 50 sellers por tgmv_lc ─────────────────────
            $rankResp = $this->ecf->ranking('tgmv_lc', 50);
            // Shape PAGINADO: ['data' => [...]] — EXTRAIR antes de processar
            $ranking = $rankResp['data'] ?? [];

            // ─── 5) sellerMetricasMensal × 50 sellers + cálculo CV ──────────
            // 50 chamadas em cold cache (~30s primeira vez); wrapper cacheia 1h.
            // 1 falha por seller não derruba o lote — mesmo padrão Phase 25.
            // Plano 02 (2026-06-06): API ECF Drive retorna CAMELCASE em todas as
            // chaves (custId, razaoSocial, valor, etc) — bug recorrente nas Phases
            // 24/25. Controller lia snake_case (cust_id, razao_social, tgmv_lc) e
            // sempre dava null → todas as 50 chamadas de seller eram puladas e
            // vacas_leiteiras voltava vazia. Fix: camelCase em todas as leituras.
            $sellersCalculados = [];
            foreach ($ranking as $sellerRank) {
                $custId = $sellerRank['custId'] ?? null;
                if (!$custId) continue;

                try {
                    $metResp = $this->ecf->sellerMetricasMensal($custId);
                    // Shape PAGINADO: EXTRAIR ['data'] antes de processar (pitfall crítico)
                    $met = $metResp['data'] ?? [];
                } catch (\Throwable) {
                    // 1 falha de seller não derruba o lote
                    $met = [];
                }

                // Extrai série tgmvLc com cast string→float defensivo (pitfall CONTEXT).
                // FILTRA zeros e mês corrente parcial — zero geralmente é "sem dado consolidado",
                // não "vendeu zero". E o mês corrente ainda está em construção pelo ETL.
                // Manter zeros distorce CV (variância inflada) e média mensal (puxada pra baixo),
                // derrubando sellers legitimamente previsíveis no ranking de vacas leiteiras.
                $serieMetricas = [];
                foreach ($met as $m) {
                    $periodo = (string) ($m['periodo'] ?? $m['timMonthId'] ?? '');
                    $valor   = (float) ($m['tgmvLc'] ?? 0);
                    if ($periodo === $mesAtual) continue;
                    if ($valor <= 0) continue;
                    $serieMetricas[] = $valor;
                }

                $cv     = $this->forecast->coeficienteVariacao($serieMetricas);
                $mediaM = count($serieMetricas) > 0
                    ? array_sum($serieMetricas) / count($serieMetricas)
                    : 0;

                $sellersCalculados[$custId] = [
                    'cust_id'       => $custId,                                              // mantém snake_case no shape interno (output Inertia)
                    'razao_social'  => $sellerRank['razaoSocial']    ?? null,                // API agora retorna razão social real (parceiro corrigiu 2026-06-06)
                    'cnpj'          => $sellerRank['cnpj']             ?? null,                // fallback final pro JSX
                    'cluster'       => $sellerRank['cluster']         ?? null,
                    'programa'      => $sellerRank['programa']        ?? null,
                    'tgmv_lc_total' => (float) ($sellerRank['valor']  ?? $sellerRank['gmv'] ?? 0), // ranking usa 'valor'/'gmv'
                    'media_mensal'  => $mediaM,
                    'cv'            => $cv,
                    'meses_ativos'  => count($serieMetricas),                                // pra UI mostrar "média sobre N meses ativos"
                ];
            }

            // ─── 6) Lookup batch company por cust_id (SEM N+1) ─────────────
            // 1 única query — NÃO loop. Pattern validado Phase 23/25.
            // Plano 03 (2026-06-06): inclui cust_ids dos GRANTS também — a API
            // ECF Drive retorna razaoSocial="ECF CONSULTORIA & ASSESSORIA" para
            // todos os grants (campo do parceiro, não do lojista). Lookup local
            // é a única forma de exibir o nome correto da empresa.
            $custIdsVacas  = array_keys($sellersCalculados);
            $custIdsGrants = collect($grants)->pluck('custId')->filter()->all();
            $custIds       = array_unique(array_merge($custIdsVacas, $custIdsGrants));

            $companies = Company::where('active', true)
                ->where(fn ($q) => $q
                    ->whereIn('adman_account_id', $custIds)
                    ->orWhereIn('ml_store_id', $custIds))
                ->get(['id', 'name', 'adman_account_id', 'ml_store_id']);

            $companyByCustId = [];
            foreach ($companies as $c) {
                if ($c->adman_account_id && in_array($c->adman_account_id, $custIds)) {
                    $companyByCustId[$c->adman_account_id] = ['id' => $c->id, 'name' => $c->name];
                }
                if ($c->ml_store_id && in_array($c->ml_store_id, $custIds)) {
                    $companyByCustId[$c->ml_store_id] = ['id' => $c->id, 'name' => $c->name];
                }
            }

            // ─── 7) Vacas leiteiras: top 20 por CV ASC (menor variação = mais previsível)
            $vacas = collect($sellersCalculados)
                ->filter(fn ($s) => $s['cv'] !== null)    // exclui sellers com CV indefinido
                ->sortBy('cv')
                ->take(20)
                ->values()
                ->map(function ($s) use ($companyByCustId) {
                    $match = $companyByCustId[$s['cust_id']] ?? null;
                    return array_merge($s, [
                        'company_id'   => $match['id']   ?? null,
                        'company_name' => $match['name'] ?? null,
                    ]);
                })
                ->all();

            // ─── 8) GMV em risco — enriquece grants com média mensal do seller
            // Sellers fora do top 50 ranking não têm dados → contribuem com 0 (degradação aceita, R-04)
            $gmvRiscoTotal       = 0;
            $grantsEnriquecidos  = [];
            // API ECF Drive grants retorna CAMELCASE: custId, razaoSocial, grantFim,
            // diasParaExpirar. Mantemos snake_case no shape interno (output Inertia)
            // mas LEMOS camelCase da API.
            // Plano 04 (2026-06-06): parceiro ECF Drive corrigiu o bug de
            // razaoSocial que retornava sempre "ECF CONSULTORIA & ASSESSORIA"
            // (campo NOMBRE_SOLUCION do parceiro, não do lojista). Agora vem
            // razão social real via BrasilAPI quando o CNPJ resolve.
            // Fallback chain no JSX: company_name (local) → razao_social (API) → CNPJ.
            foreach ($grants as $g) {
                $custId = $g['custId'] ?? null;
                $mediaM = ($custId && isset($sellersCalculados[$custId]))
                    ? $sellersCalculados[$custId]['media_mensal']
                    : 0;

                $diasParaExpirar = (int) ($g['diasParaExpirar'] ?? 0);

                // GMV em risco do seller = média_mensal × meses até o grant expirar
                // (cap em 3 meses pra alinhar com horizonte do forecast 90d).
                // Antes: assumia sempre 3 meses, superestimando grants que expiram cedo
                // (ex: grant que expira em 10 dias contribuía com media×3 em vez de media×0,33).
                $mesesAteFim     = min(3.0, max(0.0, $diasParaExpirar / 30.0));
                $gmvRiscoSeller  = $mediaM * $mesesAteFim;
                $gmvRiscoTotal  += $gmvRiscoSeller;

                $match = $custId ? ($companyByCustId[$custId] ?? null) : null;

                $grantsEnriquecidos[] = [
                    'cust_id'           => $custId,
                    'company_id'        => $match['id']                  ?? null,
                    'company_name'      => $match['name']                ?? null,
                    'razao_social'      => $g['razaoSocial']             ?? null, // ← API agora confiável
                    'cnpj'              => $g['cnpj']                    ?? null,
                    'grant_fim'         => $g['grantFim']                ?? null,
                    'dias_para_expirar' => $diasParaExpirar,
                    'media_mensal'      => $mediaM,
                    'gmv_risco'         => $gmvRiscoSeller,
                ];
            }

            // Top 10 grants ordenados por GMV em risco DESC
            $grantsTop10 = collect($grantsEnriquecidos)
                ->sortByDesc('gmv_risco')
                ->take(10)
                ->values()
                ->all();

            // ─── 9) Forecast — 3 cenários (D-F: GMV em risco distribuído uniformemente nos 3 meses)
            // Otimista: renovação 100% (projeção pura da regressão)
            // Base:     renovação 80% (20% do GMV em risco não renova)
            // Pessimista: renovação 60% (40% do GMV em risco não renova)
            $riscoPorMes = $gmvRiscoTotal > 0 ? $gmvRiscoTotal / 3 : 0;

            $cenarios = array_map(function ($p) use ($riscoPorMes) {
                $base       = $p['y'];
                $otimista   = $base;                                    // 100% renovação
                $cenarioBase = max(0.0, $base - $riscoPorMes * 0.20);  // 80% renovação (R-06 clamp)
                $pessimista  = max(0.0, $base - $riscoPorMes * 0.40);  // 60% renovação

                return [
                    'x'          => $p['x'],
                    'otimista'   => $otimista,
                    'base'       => $cenarioBase,
                    'pessimista' => $pessimista,
                ];
            }, $projecao);

            $forecast = [
                'historico'   => $historico,            // shape original do parceiro para o chart consumir
                'projecao'    => $cenarios,              // 3 cenários projetados
                'gmvRisco'    => $gmvRiscoTotal,
                'grantsTop10' => $grantsTop10,
                'coef'        => $coef,                  // {a, b, r2} — para card R² na ForecastChart
                'totalGrants' => count($grants),         // "Mostrando 10 de N" na ForecastChart
            ];

            return Inertia::render('Concentracao/Index', [
                'matriz'   => $matriz,
                'forecast' => $forecast,
                'vacas'    => $vacas,
                'erro'     => null,
            ]);

        } catch (\Throwable $e) {
            // ECF Drive offline ou qualquer falha não-recuperável.
            // Logar para diagnóstico sem quebrar o pageload (D-07 CONTEXT).
            report($e);

            return Inertia::render('Concentracao/Index', [
                'matriz' => [
                    'linhas'   => [],
                    'colunas'  => [],
                    'celulas'  => [],
                    'top5'     => [],
                    'gmvTotal' => 0,
                ],
                'forecast' => [
                    'historico'   => [],
                    'projecao'    => [],
                    'gmvRisco'    => 0,
                    'grantsTop10' => [],
                    'coef'        => ['a' => 0, 'b' => 0, 'r2' => 0],
                    'totalGrants' => 0,
                ],
                'vacas' => [],
                'erro'  => 'Não foi possível buscar dados de concentração agora. Tente em alguns segundos.',
            ]);
        }
    }
}
