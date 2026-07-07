<?php

namespace App\Contracts;

use App\Models\Company;
use App\Services\Metrics\UnifiedMetricsDto;
use Carbon\Carbon;

/**
 * Contract de provider de leitura unificada de métricas por empresa.
 *
 * Phase 60 (base multi-fonte backend ML+Adman unificado): separa o "como
 * ler métricas" (`adman_metrics` local no path Adman, `MercadoLivreService`
 * no path ML) do "como reconciliar precedência" (`UnifiedMetricsService` a
 * ser implementado no Plan 60-04).
 *
 * Estrutura análoga ao {@see \App\Contracts\SugadoresAdsProvider} (Phase 39),
 * que provou funcionar como base para o pattern "contract + factory + provider"
 * nesta code base. Toda implementação deste contract DEVE retornar um
 * {@see \App\Services\Metrics\UnifiedMetricsDto} — o `UnifiedMetricsService`
 * (Plan 60-04) confia neste shape para aplicar precedência campo-a-campo.
 *
 * Regra canônica de precedência: veja ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — tabela campo-a-campo
 * e vocabulário do enum `source` (4 valores: `'adman'`, `'ml'`,
 * `'unified'`, `'none'`).
 *
 * Implementações previstas:
 *  - {@see \App\Services\Metrics\AdmanMetricsProvider} (Plan 60-02) — lê
 *    exclusivamente da tabela `adman_metrics` local, sem chamar API Adman
 *    em runtime.
 *  - `App\Services\Metrics\MlMetricsProvider` (Plan 60-03) — envelopa
 *    `MercadoLivreService::fetchOrdersSummary` + `fetchAdsSummary`.
 *
 * Nenhuma implementação deste contract deve lançar exceção quando a fonte
 * está vazia — retornar DTO com campos numéricos `null` é o sinal esperado
 * de "fonte não expõe dados neste período" (permite composição sem quebra).
 */
interface MetricsProvider
{
    /**
     * Retorna true se este provider sabe lidar com a empresa.
     *
     * Exemplos:
     *  - `AdmanMetricsProvider::supports()` === true quando
     *    `$company->adman_account_id` não é vazio (independente de existir
     *    row em `adman_metrics` — histórico vazio ainda é caso válido).
     *  - `MlMetricsProvider::supports()` === true quando
     *    `$company->mlToken?->status === 'active'` (accessor
     *    `Company::is_ml_driven`).
     *
     * O factory (a ser criado no Plan 60-04) chama este método para
     * auto-resolução dos 3 casos (só-Adman / só-ML / ambos) definidos
     * no ADR DATA-04.
     */
    public function supports(Company $company): bool;

    /**
     * Identificador estável do provider para logs, chaves de cache e
     * badges de UI (DATA-05, Phase 61).
     *
     * Valores conhecidos: `'adman'` (AdmanMetricsProvider), `'ml'`
     * (MlMetricsProvider). Não traduzir — usado como chave em cache
     * (`unified_metrics:{company_id}:{from}:{to}:{source}`) e como valor
     * do campo `source` do DTO retornado.
     */
    public function name(): string;

    /**
     * Leitura agregada de métricas no período `[from, to]` (inclusive).
     *
     * Contrato:
     *  - Empresa sem dados no período: retornar DTO com campos numéricos
     *    `null` (NÃO lançar exception). Metadados (`company_id`, `source`,
     *    `period_from`, `period_to`) sempre preenchidos.
     *  - Empresa não suportada (`supports()` === false): comportamento
     *    indefinido — o caller deve verificar `supports()` antes de chamar.
     *  - Falha de I/O ou erro estrutural: propagar como Exception.
     *
     * Precedência entre providers (quando `UnifiedMetricsService` compõe
     * ambos): tabela campo-a-campo no ADR DATA-04.
     */
    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto;
}
