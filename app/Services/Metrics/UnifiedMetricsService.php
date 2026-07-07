<?php

namespace App\Services\Metrics;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador multi-fonte de leitura de métricas por empresa+período.
 *
 * Phase 60 Plan 60-04 (Wave 4 final):
 * Resolve os 3 casos do ADR DATA-04 usando o `MetricsProviderFactory`
 * (Plan 60-03):
 *  - `so-adman` → retorna o DTO do `AdmanMetricsProvider` direto
 *    (source='adman').
 *  - `so-ml`    → retorna o DTO do `MlMetricsProvider` direto
 *    (source='ml').
 *  - `ambos`    → funde os 2 DTOs campo-a-campo respeitando a tabela de
 *    precedência do ADR e emite DTO com source='unified'.
 *  - `none`     → DTO sentinela com todos os campos numéricos `null` e
 *    source='none' (permite consumidores distinguirem "empresa sem
 *    integração" de "erro na leitura").
 *
 * ### Regra de precedência (tabela ADR DATA-04)
 *
 * **ML dita** — fallback pro Adman via `??` quando ML retorna null:
 *   `revenue`, `ad_spend`, `sold_quantity`, `sales_fee`, `orders_count`,
 *   `tacos`.
 *
 * **ML canonical** — sem fallback (Adman não expõe):
 *   `acos`, `roas`, `clicks`, `impressions`.
 *
 * **Adman canonical** — sem fallback (ML não expõe):
 *   `net_billing`, `taxes`, `shipping_cost`, `product_cost`,
 *   `contribution_margin`.
 *
 * ### Divergência de TACOS
 *
 * No caso `ambos`, quando ambos providers reportam TACOS e a divergência
 * relativa é > 5%, emite `Log::warning` estruturado com company_id, valores
 * ML/Adman e delta_pct. **Nunca falha o cálculo** — ML vence, log fica
 * auditável (endereça memory `project_adman_data_sources`).
 *
 * Consumidor esperado: dashboards da Phase 61 (multi-fonte com badge de
 * origem visual — DATA-05).
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md`.
 */
class UnifiedMetricsService
{
    /**
     * Threshold relativo para logar divergência de TACOS entre ML e Adman.
     *
     * 5% conforme ADR DATA-04 tabela campo-a-campo. Valor deliberadamente
     * baixo (não catastrófico) — o log serve pra auditoria, não bloqueia
     * o cálculo.
     */
    private const TACOS_DIVERGENCE_THRESHOLD = 0.05;

    public function __construct(private MetricsProviderFactory $factory)
    {
    }

    /**
     * Leitura unificada respeitando ADR DATA-04.
     *
     * Fluxo:
     *  1. `caseFor($c)` classifica em 'ambos' | 'so-ml' | 'so-adman' | 'none'.
     *  2. `forCompany($c)` retorna array ordenado de providers (ML primeiro
     *     no caso ambos).
     *  3. Delega ao provider único (casos 1-provider) ou aplica fusão
     *     campo-a-campo (caso "ambos").
     *
     * Contrato de erro: NUNCA lança exception — providers internos já
     * absorvem falhas de I/O (ADR + convenção Plan 60-02/03).
     */
    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        $case      = $this->factory->caseFor($company);
        $providers = $this->factory->forCompany($company);

        return match ($case) {
            'none'     => $this->noneDto($company, $from, $to),
            'so-adman' => $providers[0]->readForCompany($company, $from, $to),
            'so-ml'    => $providers[0]->readForCompany($company, $from, $to),
            'ambos'    => $this->readAmbos($company, $providers, $from, $to),
        };
    }

    /**
     * Alias conveniente — equivalente a `readForCompany`. Mantido pela
     * paridade nominal com o texto do PLAN 60-04 ("`for(...)`") e com a
     * chamada mais curta que consumidores futuros preferirem
     * (`$svc->for($c, $from, $to)`).
     */
    public function for(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        return $this->readForCompany($company, $from, $to);
    }

    /**
     * Passa direto para o factory. Útil pra Phase 61 (dashboards) decidir
     * qual badge exibir na UI sem chamar `readForCompany` inteiro.
     *
     * @return array<int, \App\Contracts\MetricsProvider>
     */
    public function available(Company $company): array
    {
        return $this->factory->forCompany($company);
    }

    // ─────────────────────── Caso "ambos" ───────────────────────

    /**
     * Executa os 2 providers e aplica fusão campo-a-campo.
     *
     * `$providers` vem ordenado pelo factory como `[ml, adman]` (ADR
     * DATA-04 — ML primeiro). Aqui destrinchamos por `name()` para não
     * depender de posição (defensivo — se factory mudar ordem, este método
     * ainda funciona).
     */
    private function readAmbos(Company $company, array $providers, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        $ml    = null;
        $adman = null;

        foreach ($providers as $provider) {
            $dto = $provider->readForCompany($company, $from, $to);
            if ($provider->name() === 'ml') {
                $ml = $dto;
            } elseif ($provider->name() === 'adman') {
                $adman = $dto;
            }
        }

        // Guarda defensiva — o factory garante ambos presentes no caso "ambos",
        // mas se um retornar null (bug futuro), degradamos para o outro sem
        // vazar exception.
        if ($ml === null && $adman === null) {
            return $this->noneDto($company, $from, $to);
        }
        if ($ml === null) {
            return $adman;
        }
        if ($adman === null) {
            return $ml;
        }

        return $this->mergeFields($company, $ml, $adman);
    }

    /**
     * Fusão campo-a-campo — implementa a tabela de precedência do ADR
     * DATA-04. Emite `Log::warning` na divergência de TACOS antes de
     * retornar (para que o log capture os valores originais dos 2 providers).
     */
    private function mergeFields(Company $company, UnifiedMetricsDto $ml, UnifiedMetricsDto $adman): UnifiedMetricsDto
    {
        // Log de divergência ANTES de resolver ML-vence — captura valores
        // originais dos 2 providers para auditoria (mitigação threat
        // T-60-04-04: Repudiation).
        $this->logTacosDivergenteSeExcede($company, $ml, $adman);

        return new UnifiedMetricsDto(
            company_id:  $company->id,
            source:      'unified',
            period_from: $ml->period_from,
            period_to:   $ml->period_to,

            // ─── ML dita, Adman é fallback quando ML retorna null ───
            revenue:       $ml->revenue       ?? $adman->revenue,
            ad_spend:      $ml->ad_spend      ?? $adman->ad_spend,
            sold_quantity: $ml->sold_quantity ?? $adman->sold_quantity,
            tacos:         $ml->tacos         ?? $adman->tacos,
            sales_fee:     $ml->sales_fee     ?? $adman->sales_fee,
            orders_count:  $ml->orders_count  ?? $adman->orders_count,

            // ─── Adman canonical (ML não expõe) ───
            net_billing:         $adman->net_billing,
            taxes:               $adman->taxes,
            shipping_cost:       $adman->shipping_cost,
            product_cost:        $adman->product_cost,
            contribution_margin: $adman->contribution_margin,

            // ─── ML canonical (Adman não expõe) ───
            acos:        $ml->acos,
            roas:        $ml->roas,
            clicks:      $ml->clicks,
            impressions: $ml->impressions,
        );
    }

    /**
     * Emite `Log::warning` quando |ML.tacos - Adman.tacos| / max(Adman.tacos, 0.01)
     * excede 5%.
     *
     * Trailer de contexto sanitizado — inclui APENAS `company_id`, `ml`,
     * `adman`, `delta_pct` e o período. NUNCA inclui token, email, telefone
     * ou payload bruto (mitigação threat T-60-04-01: Information Disclosure).
     */
    private function logTacosDivergenteSeExcede(Company $company, UnifiedMetricsDto $ml, UnifiedMetricsDto $adman): void
    {
        // Se qualquer um dos valores for null, não há divergência a comparar.
        if ($ml->tacos === null || $adman->tacos === null) {
            return;
        }

        $deltaAbs   = abs((float) $ml->tacos - (float) $adman->tacos);
        $baseAdman  = max((float) $adman->tacos, 0.01);
        $deltaRatio = $deltaAbs / $baseAdman;

        if ($deltaRatio <= self::TACOS_DIVERGENCE_THRESHOLD) {
            return;
        }

        Log::warning('[UnifiedMetrics] TACOS divergente', [
            'company_id'  => $company->id,
            'period_from' => $ml->period_from->toDateString(),
            'period_to'   => $ml->period_to->toDateString(),
            'ml'          => (float) $ml->tacos,
            'adman'       => (float) $adman->tacos,
            'delta_pct'   => round($deltaRatio * 100, 2),
        ]);
    }

    // ─────────────────────── Caso "none" ───────────────────────

    /**
     * DTO sentinela — nenhuma integração ativa. Todos numéricos `null` +
     * `source='none'` (ADR DATA-04 vocabulário).
     */
    private function noneDto(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        return new UnifiedMetricsDto(
            company_id:          $company->id,
            source:              'none',
            period_from:         $from,
            period_to:           $to,
            revenue:             null,
            ad_spend:            null,
            sold_quantity:       null,
            tacos:               null,
            net_billing:         null,
            sales_fee:           null,
            taxes:               null,
            shipping_cost:       null,
            product_cost:        null,
            contribution_margin: null,
            acos:                null,
            roas:                null,
            clicks:              null,
            impressions:         null,
            orders_count:        null,
        );
    }
}
