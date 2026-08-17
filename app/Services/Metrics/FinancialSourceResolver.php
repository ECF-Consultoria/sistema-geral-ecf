<?php

namespace App\Services\Metrics;

use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * Fase 136 (D-10) — fonte ÚNICA da regra de desempate de fonte financeira.
 *
 * Até esta fase, a regra de desempate ("'adman' vence se estiver presente,
 * senão pega a primeira fonte") estava duplicada byte-a-byte em 3 call-sites:
 * `CompanyScoreService::
 * computeEmpresasScore()`, `DesempenhoScoreService::computeUniverso()` e
 * `PortfolioController::fontesFinanceirasPorEmpresa()`. Nos três, 'adman'
 * vencia sobre 'shopee' **sem checar se a empresa tinha conta Adman de
 * fato** — a mesma empresa, no mesmo mês, aparecia com faturamento para um
 * profissional (vínculo shopee, lê `shopee_metrics`) e em branco para outro
 * (vínculo performance, resolve 'adman', tenta ler uma conta que não existe).
 * Caso medido em produção: Interior Magazine, 2026-08-11 (ver
 * `.planning/learnings/desempenho-bonificacao.md` §0.04).
 *
 * Corrigir só o call-site vivo faria a MESMA empresa resolver fonte
 * diferente em dois lugares do mesmo payload — a Carteira exibindo
 * marketplace divergente do Desempenho. Por isso a regra vive aqui, e os 3
 * call-sites passam a delegar a esta classe em vez de repetir a lógica.
 *
 * **Critério de "tem Adman de fato": `Company::cust_id`, NUNCA a coluna
 * crua do ID de conta Adman.** O accessor (`app/Models/Company.php:94-98`)
 * devolve o ID de conta Adman ou, na ausência dele, o `ml_store_id`,
 * tratando string vazia como `null`. Ler a coluna crua trataria como "sem
 * Adman" as empresas que só têm `ml_store_id` preenchido — regressão nova.
 * É o mesmo sinal que `AdmanMetricDiffService::compute()`/`isCached()` já
 * usam para decidir se vale tentar a chamada HTTP.
 *
 * O terceiro ramo do `match` preserva DE PROPÓSITO o comportamento anterior
 * quando não existe fonte alternativa (vínculo só performance, mesmo sem
 * `cust_id`, continua resolvendo 'adman'): o objetivo de D-10 é corrigir o
 * DESEMPATE entre fontes concorrentes, não inventar um quarto estado "sem
 * fonte nenhuma" — isso já é responsabilidade de D-03 (`sem_fonte`) nos
 * chamadores, fora desta classe.
 *
 * Esta classe **não consulta banco** — recebe `$companiesById` já carregado
 * pelo chamador, decisão deliberada para não esconder I/O dentro de uma
 * classe que parece pura (o molde do namespace, `MetricDiffDispatcher`,
 * segue a mesma convenção).
 */
class FinancialSourceResolver
{
    /**
     * Resolve a fonte financeira vencedora por empresa.
     *
     * Ordem exata do desempate:
     *  1. `'adman'` — quando o grupo contém 'adman' E a empresa tem `cust_id`;
     *  2. `'shopee'` — quando o grupo contém 'shopee' (inclui o caso em que
     *     'adman' está presente mas a empresa não tem `cust_id`);
     *  3. `$sources->first()` — default, preserva o comportamento atual
     *     quando não há fonte alternativa real.
     *
     * @param  Collection<int, array{company_id: int, financial_source: string}>  $vinculosElegiveis
     *   Já filtrado por `financial_metrics_eligible = true` pelo chamador.
     * @param  Collection<int, Company>  $companiesById  `keyBy('id')`, já carregado pelo chamador.
     * @return Collection<int, string>  company_id => 'adman'|'shopee'
     */
    public function resolverPorEmpresa(Collection $vinculosElegiveis, Collection $companiesById): Collection
    {
        return $vinculosElegiveis
            ->groupBy('company_id')
            ->map(function (Collection $grupo, $companyId) use ($companiesById) {
                $sources = $grupo->pluck('financial_source');

                /** @var Company|null $company */
                $company     = $companiesById->get((int) $companyId);
                $admanValido = $company !== null && $company->cust_id !== null;

                return match (true) {
                    $sources->contains('adman') && $admanValido => 'adman',
                    $sources->contains('shopee')                 => 'shopee',
                    default                                       => $sources->first(),
                };
            });
    }
}
