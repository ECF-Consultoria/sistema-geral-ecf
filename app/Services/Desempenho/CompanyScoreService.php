<?php

namespace App\Services\Desempenho;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\User;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Agregador de LEITURA que devolve a linha de fato por empresa
 * `(user_id, company_id)` — Fase 119 (EMPS-01..07), consumindo o componente
 * de NPS já pronto da Fase 118 (`NpsPorEmpresaService`) e o dispatcher
 * financeiro (`MetricDiffDispatcher`) já existentes desde a Fase 109/117.
 *
 * Troca a UNIDADE do motor de bonificação: a régua de faturamento/margem
 * passa a rodar POR EMPRESA, antes de qualquer média — diferente do
 * `DesempenhoScoreService::compute()` atual, que tira a média da carteira e
 * só então aplica a régua uma única vez sobre essa média.
 *
 * ─── Regras travadas (119-CONTEXT.md) ─────────────────────────────────────
 *  - D-01 · a linha reporta DOIS números: `nota_empresa` (estrita — `null`
 *    se faltar qualquer um dos 3 componentes) e `nota_empresa_parcial`
 *    (média dos presentes), mais `componentes_presentes` e `quality.motivos`.
 *  - D-02 · empresa Shopee entra como `status='complete'`, com
 *    `margem_pontos=1.0` fixo (placeholder da Fase 109) e
 *    `quality.margin_source='placeholder_shopee'` — nunca aplica a régua de
 *    margem sobre Shopee.
 *  - D-03 · empresa sem fonte financeira elegível permanece listada, com
 *    todos os campos financeiros `null`, `status='sem_fonte'` e
 *    `quality.motivos=['sem_fonte_financeira']`.
 *  - D-04 · `DesempenhoScoreService::margemPontos()` (o blend ponderado por
 *    contagem da Fase 109) fica INTOCADO — é o caminho vivo enquanto a flag
 *    da Fase 120 estiver desligada. O caminho novo não o usa.
 *  - D-05 · `$periodo` chega SEMPRE já resolvido por quem chama (nunca
 *    resolvido internamente) — garante janela byte-idêntica à que
 *    `DesempenhoScoreService::compute()` usou. `$mesFechado` deriva de
 *    `$periodo['is_closed']`, nunca é parâmetro próprio.
 *  - C-01 · não existe gate de cobertura de margem ativo a respeitar aqui
 *    (`fallbackMargemPct()`/`coberturaMargem()` são código morto) — não
 *    inventar patamar de cobertura.
 *  - C-02 · os guards de dias-comuns/diff já operam POR EMPRESA dentro do
 *    `AdmanMetricDiffService` — herdados de graça. O único trecho agregado
 *    que NÃO é portado é `$vars->avg()`/o blend `margemPontos()`.
 *  - C-03 · `reguaFaturamento()`/`reguaMargem()` são duplicadas BYTE A BYTE
 *    do `DesempenhoScoreService` (privadas lá, gate de aditividade proíbe
 *    torná-las públicas) — teste de equivalência via Reflection cobre a
 *    divergência. Unificação real fica para a Fase 120, quando o gate sair.
 *  - C-04 · `MetricDiffDispatcher::compute()` lança `InvalidArgumentException`
 *    para fonte inválida — o guard de fonte não-nula vem ANTES da chamada,
 *    nunca dentro de um `catch`.
 *
 * ─── Aditividade ───────────────────────────────────────────────────────────
 * `DesempenhoScoreService` é ESPELHADO, não substituído — nenhum número de
 * produção muda nesta fase. Nenhum consumidor de produção referencia esta
 * classe; ela é exercitada só por testes até a Fase 120 ligar a flag.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 * @see app/Services/DesempenhoScoreService.php:1290,1311 (réguas duplicadas — NÃO reescritas)
 * @see app/Services/Desempenho/NpsPorEmpresaService.php (componente NPS consumido, não reimplementado)
 */
class CompanyScoreService
{
    public function __construct(
        private CarteiraContextService $carteiraContext,
        private MetricDiffDispatcher $diffDispatcher,
        private NpsPorEmpresaService $npsPorEmpresaService,
    ) {
    }

    /**
     * Linha de fato por `(user_id, company_id)` — NPS, faturamento e margem
     * já pontuados pela régua (por empresa, D3 da milestone), mais
     * `nota_empresa`/`nota_empresa_parcial` (D-01).
     *
     * D-05 (assinatura): `$periodo` chega SEMPRE já resolvido por quem chama
     * (nunca resolvido internamente) — garante janela byte-idêntica à que
     * `DesempenhoScoreService::compute()` usou para a MESMA competência,
     * senão a Fase 121 compararia notas divergentes por causa da janela, não
     * da fórmula. `$mesFechado` NÃO é parâmetro próprio — deriva de
     * `(bool) ($periodo['is_closed'] ?? false)`, o mesmo sinal canônico que
     * `DesempenhoScoreService::compute()` usa ao chamar `computeNpsWindow()`.
     * `$invalidadas` nulo resolve via `BonusInvalidacao::companyIdsInvalidadas()`
     * (NUNCA `collect()` vazio, que silenciaria a invalidação) e o MESMO
     * objeto é repassado ao `NpsPorEmpresaService` — garante universos
     * idênticos entre os dois serviços.
     *
     * Ordem de execução (Resposta 7 do RESEARCH — invalidação ANTES de
     * qualquer resolução de fonte ou chamada HTTP): 1) resolve invalidadas;
     * 2) monta o universo via `CarteiraContextService::forUser()`, rejeitando
     * invalidadas; 3) resolve a fonte financeira vencedora por empresa
     * (universo COMPLETO, nunca pré-filtrado por elegibilidade); 4) UMA
     * chamada ao `NpsPorEmpresaService`; 5) carrega só os `Company` models
     * das empresas com fonte não-nula; 6) monta a linha por empresa, com o
     * guard C-04 (nunca chamar o dispatcher com fonte nula) e UMA única
     * chamada ao `MetricDiffDispatcher` por empresa (EMPS-05).
     *
     * `status` (Resposta 6 do RESEARCH — `blocked`/`invalidada`/
     * `sem_baseline` são deliberadamente inexistentes): `sem_fonte` quando
     * não há fonte financeira elegível (D-03); `complete` quando os 3
     * componentes estão presentes; `sem_dados` quando nenhum componente está
     * presente; `partial` no meio-termo.
     *
     * @return Collection<int, object{
     *   company_id: int, company_name: string, fonte_financeira: ?string,
     *   nps_pontos: ?float,
     *   faturamento_atual: ?float, faturamento_anterior: ?float,
     *   faturamento_var_pct: ?float, faturamento_pontos: ?float,
     *   margem_pct_atual: ?float, margem_pct_anterior: ?float,
     *   margem_var_pp: ?float, margem_pontos: ?float,
     *   componentes_presentes: int, nota_empresa: ?float, nota_empresa_parcial: ?float,
     *   status: string,
     *   quality: array{revenue_diff_source: ?string, margin_diff_source: ?string, margin_source: ?string, motivos: array<int,string>},
     * }> chaveada por `company_id`.
     */
    public function computeEmpresasScore(User $user, Carbon $mes, array $periodo, ?Collection $invalidadas = null): Collection
    {
        // 1. Empresas invalidadas na competência M — NUNCA collect() vazio
        //    por default (silenciaria a invalidação, T-119-03).
        $invalidadas = $invalidadas ?? BonusInvalidacao::companyIdsInvalidadas($mes);

        // Sinal canônico de mês fechado — o MESMO que DesempenhoScoreService
        // usa ao chamar computeNpsWindow(). Fonte única de verdade (D-05).
        $mesFechado = (bool) ($periodo['is_closed'] ?? false);

        // 2. Universo vivo — SEMPRE via forUser(), nunca join direto em
        //    company_users nem $user->companies() (T-119-02).
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true])
            ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

        // forUser() NÃO colapsa vínculos — deduplicar aqui.
        $companiesUniverso = $vinculos->pluck('company_id')->unique()->values();

        if ($companiesUniverso->isEmpty()) {
            return collect();
        }

        // 4. Mapa company_id => company_name, a partir do primeiro vínculo
        //    de cada empresa (múltiplos vínculos da mesma empresa repetem o
        //    mesmo nome).
        $nomesPorEmpresa = [];
        foreach ($vinculos as $vinculo) {
            $nomesPorEmpresa[$vinculo['company_id']] ??= $vinculo['company_name'];
        }

        // 5. Fonte financeira vencedora — SÓ entre os vínculos elegíveis
        //    (financial_metrics_eligible=true). 'adman' vence sobre 'shopee'
        //    quando a MESMA empresa tem os dois vínculos elegíveis. Empresa
        //    sem NENHUM vínculo elegível fica FORA do mapa (D-03) — o
        //    universo permanece o COMPLETO, nunca pré-filtrado.
        $fontesPorEmpresa = $vinculos
            ->where('financial_metrics_eligible', true)
            ->groupBy('company_id')
            ->map(function (Collection $grupo) {
                $sources = $grupo->pluck('financial_source');

                return $sources->contains('adman') ? 'adman' : $sources->first();
            });

        // 6. UMA chamada cobrindo toda a carteira — nunca em loop por
        //    empresa. O MESMO $invalidadas repassado garante universo
        //    idêntico ao deste método.
        $notasNps = $this->npsPorEmpresaService->notasNpsPorEmpresa($user, $mes, $mesFechado, $invalidadas);

        // 7. Só as empresas com fonte financeira não-nula precisam do
        //    dispatcher — carrega os models de uma vez, fora do loop.
        $companyIdsComFonte = $fontesPorEmpresa->keys()->all();
        $companies = Company::whereIn('id', $companyIdsComFonte)->get()->keyBy('id');

        // 8. Monta a linha por empresa.
        return $companiesUniverso->mapWithKeys(function (int $companyId) use (
            $user,
            $mes,
            $periodo,
            $nomesPorEmpresa,
            $fontesPorEmpresa,
            $notasNps,
            $companies,
        ): array {
            $companyName     = $nomesPorEmpresa[$companyId] ?? '';
            $fonteFinanceira = $fontesPorEmpresa[$companyId] ?? null;

            // NPS — defensivo: a chave DEVERIA sempre existir (os dois
            // serviços partem do mesmo forUser()), mas nunca lança exceção
            // se faltar (T-119-01 — log só com IDs/competência).
            $npsMotivo = null;
            if ($notasNps->has($companyId)) {
                $npsPontos = $notasNps->get($companyId)->nota ?? null;
                if ($npsPontos === null) {
                    $npsMotivo = 'nps_janela_aberta';
                }
            } else {
                $npsPontos = null;
                $npsMotivo = 'nps_indisponivel';
                Log::warning('[Score por Empresa] company_id ausente no retorno do NPS', [
                    'user_id'     => $user->id,
                    'company_id'  => $companyId,
                    'competencia' => $mes->format('Y-m'),
                ]);
            }

            // D-03 — sem fonte financeira elegível: linha SEM ir ao
            // dispatcher (guard C-04, aplicado ANTES de qualquer chamada).
            if ($fonteFinanceira === null) {
                $motivos = ['sem_fonte_financeira'];
                if ($npsMotivo !== null) {
                    $motivos[] = $npsMotivo;
                }

                return [$companyId => $this->linhaSemFonte($companyId, $companyName, $npsPontos, $motivos)];
            }

            // EMPS-05 — UMA única chamada ao dispatcher por empresa,
            // alimentando faturamento E margem.
            $company   = $companies->get($companyId);
            $resultado = $this->diffDispatcher->compute($company, $periodo, $fonteFinanceira);

            $faturamentoAtual    = $resultado['metrics']['revenue']['value'] ?? null;
            $faturamentoAnterior = $resultado['metrics']['revenue']['prev_value'] ?? null;
            $faturamentoVarPct   = $resultado['metrics']['revenue']['diff_pct'] ?? null;
            $revenueDiffSource   = $resultado['metrics']['revenue']['diff_source'] ?? null;

            $margemPctAtual    = $resultado['metrics']['contribution_margin_pct']['value'] ?? null;
            $margemPctAnterior = $resultado['metrics']['contribution_margin_pct']['prev_value'] ?? null;
            // EMPS-03 — SEMPRE diff_pp (pontos percentuais), JAMAIS diff_pct.
            $margemVarPp      = $resultado['metrics']['contribution_margin_pct']['diff_pp'] ?? null;
            $margemDiffSource = $resultado['metrics']['contribution_margin_pct']['diff_source'] ?? null;

            $faturamentoPontos = $this->reguaFaturamento($faturamentoVarPct);

            // D-02 — Shopee nunca aplica a régua de margem: placeholder 1.0 fixo.
            $margemPontos = match (true) {
                $fonteFinanceira === 'shopee' => 1.0,
                $margemVarPp === null         => null,
                default                       => $this->reguaMargem($margemVarPp),
            };

            $motivos = [];
            if ($faturamentoPontos === null) {
                $motivos[] = 'faturamento_sem_baseline';
            }
            if ($fonteFinanceira === 'adman' && $margemVarPp === null) {
                $motivos[] = 'margem_pp_indisponivel';
            }
            if ($npsMotivo !== null) {
                $motivos[] = $npsMotivo;
            }

            // D-01 — os DOIS números: nota_empresa (estrita) e
            // nota_empresa_parcial (média dos presentes).
            $componentes = collect([$npsPontos, $faturamentoPontos, $margemPontos])
                ->reject(fn ($v) => $v === null);

            $componentesPresentes = $componentes->count();
            $notaEmpresaParcial    = $componentes->isEmpty() ? null : round($componentes->avg(), 2);
            $notaEmpresa           = $componentesPresentes === 3 ? $notaEmpresaParcial : null;

            $status = match (true) {
                $componentesPresentes === 3 => 'complete',
                $componentesPresentes === 0 => 'sem_dados',
                default                     => 'partial',
            };

            return [$companyId => (object) [
                'company_id'            => $companyId,
                'company_name'          => $companyName,
                'fonte_financeira'      => $fonteFinanceira,
                'nps_pontos'            => $npsPontos,
                'faturamento_atual'     => $faturamentoAtual,
                'faturamento_anterior'  => $faturamentoAnterior,
                'faturamento_var_pct'   => $faturamentoVarPct,
                'faturamento_pontos'    => $faturamentoPontos,
                'margem_pct_atual'      => $margemPctAtual,
                'margem_pct_anterior'   => $margemPctAnterior,
                'margem_var_pp'         => $margemVarPp,
                'margem_pontos'         => $margemPontos,
                'componentes_presentes' => $componentesPresentes,
                'nota_empresa'          => $notaEmpresa,
                'nota_empresa_parcial'  => $notaEmpresaParcial,
                'status'                => $status,
                'quality'               => [
                    'revenue_diff_source' => $revenueDiffSource,
                    'margin_diff_source'  => $margemDiffSource,
                    'margin_source'       => $fonteFinanceira === 'shopee' ? 'placeholder_shopee' : null,
                    'motivos'             => $motivos,
                ],
            ]];
        });
    }

    /**
     * Monta a linha de retorno para empresa SEM fonte financeira elegível
     * (D-03) — nunca chega ao `MetricDiffDispatcher` (guard C-04). Único
     * componente possível é o NPS; os 3 campos financeiros ficam `null` e
     * `nota_empresa` também (D-01 — só fecha com os 3 componentes).
     *
     * @param  array<int, string>  $motivos  já montados na ordem determinística
     *                                        (sem_fonte_financeira primeiro).
     */
    private function linhaSemFonte(int $companyId, string $companyName, ?float $npsPontos, array $motivos): object
    {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => $companyName,
            'fonte_financeira'      => null,
            'nps_pontos'            => $npsPontos,
            'faturamento_atual'     => null,
            'faturamento_anterior'  => null,
            'faturamento_var_pct'   => null,
            'faturamento_pontos'    => null,
            'margem_pct_atual'      => null,
            'margem_pct_anterior'   => null,
            'margem_var_pp'         => null,
            'margem_pontos'         => null,
            'componentes_presentes' => $npsPontos !== null ? 1 : 0,
            'nota_empresa'          => null,
            'nota_empresa_parcial'  => $npsPontos,
            'status'                => 'sem_fonte',
            'quality'               => [
                'revenue_diff_source' => null,
                'margin_diff_source'  => null,
                'margin_source'       => null,
                'motivos'             => $motivos,
            ],
        ];
    }

    /**
     * Régua de FATURAMENTO — aplica pontuação 1-5 pts à % de variação de
     * faturamento vs mês anterior POR EMPRESA (Fase 119 — diferente do
     * original, que aplica sobre a média da carteira).
     *
     * DUPLICAÇÃO INTENCIONAL E TEMPORÁRIA (C-03/119-CONTEXT.md): o gate de
     * aditividade proíbe tornar `DesempenhoScoreService::reguaFaturamento()`
     * `protected`/pública nesta fase. Corpo copiado BYTE A BYTE de
     * `DesempenhoScoreService.php:1290-1298` — não "melhorar", não reordenar
     * comparações, não trocar `<=` por `<`. A unificação real (extrair para
     * uma classe compartilhada) fica para a Fase 120, quando o gate sair. A
     * equivalência com o original é provada por
     * `CompanyScoreServiceReguasTest` via Reflection, em todos os boundaries.
     *
     * Ancorada no SPEC-04 "Régua de Faturamento" da diretoria, adaptada à
     * interpretação vs-mês-anterior escolhida em spec-phase Q1:
     *   ≤ -6%  → 1 pt (queda severa)
     *   ≤ -1%  → 2 pts (queda leve)
     *   <  1%  → 3 pts (estável / meta)
     *   ≤  5%  → 4 pts (crescimento saudável)
     *   >  5%  → 5 pts (crescimento excelente)
     */
    private function reguaFaturamento(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -6)    return 1.0;
        if ($pct <= -1)    return 2.0;
        if ($pct <   1)    return 3.0;
        if ($pct <=  5)    return 4.0;
        return 5.0;
    }

    /**
     * Régua de MARGEM DE CONTRIBUIÇÃO — aplica pontuação 1-5 pts à variação
     * de margem POR EMPRESA.
     *
     * DUPLICAÇÃO INTENCIONAL E TEMPORÁRIA (C-03/119-CONTEXT.md) — mesmos
     * termos da nota de `reguaFaturamento()` acima. Corpo copiado BYTE A
     * BYTE de `DesempenhoScoreService.php:1311-1319` — cortes numéricos
     * IDÊNTICOS.
     *
     * NOTA (Fase 119, D2 da milestone v21.0): no `DesempenhoScoreService`
     * original, esta função é chamada sobre uma % de variação RELATIVA
     * (`diff_pct`, agregada da carteira). Aqui, a MESMA função (cortes
     * idênticos) deve receber `margem_var_pp` (pontos percentuais, `diff_pp`,
     * por empresa) — NUNCA `diff_pct`. Ver EMPS-03.
     *
     * Ancorada no SPEC-05 "Régua de Margem" da diretoria:
     *   ≤ -5%  → 1 pt
     *   ≤ -2%  → 2 pts
     *   ≤  1%  → 3 pts
     *   ≤  4%  → 4 pts
     *   >  4%  → 5 pts
     */
    private function reguaMargem(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -5)    return 1.0;
        if ($pct <= -2)    return 2.0;
        if ($pct <=  1)    return 3.0;
        if ($pct <=  4)    return 4.0;
        return 5.0;
    }
}
