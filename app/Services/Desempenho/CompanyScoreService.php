<?php

namespace App\Services\Desempenho;

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
