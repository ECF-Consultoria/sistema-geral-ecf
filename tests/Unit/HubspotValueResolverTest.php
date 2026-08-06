<?php

namespace Tests\Unit;

use App\Models\Servico;
use App\Services\Hubspot\HubspotValueResolver;
use Tests\TestCase;

/**
 * Suite Unit — Phase 112 Plan 112-01 (HUB-VAL-01).
 *
 * Cobre os 6 casos-âncora do prompt canônico (Fase 10) + paridade de
 * não-regressão + os dois lados do limite de tolerância de 5%. Teste PURO:
 * SEM RefreshDatabase, Servico é instanciado em memória com `new Servico([...])`
 * (nenhuma query de banco é feita pelo resolver nem pelo teste).
 *
 * Casos:
 *  1.  Paridade (INVARIANTE): mensal + monthly + price numérico → price*quantity, high.
 *  1b. Paridade qty>1: mensal + monthly, price 500 qty 2 → 1000, high.
 *  2.  mensal + annually + P1Y: price 36000 → 3000 (normalizado mensal), high.
 *  3.  mensal + hs_mrr prevalece sobre hs_arr → 3000, tipo 'mrr', high.
 *  4.  Servico único: amount 36000 NÃO divide por 12 → 36000, high.
 *  5.  Sem line item, deal.amount 36000 + valor_padrao 3000 (tolerância exata) → 3000, medium, warning.
 *  6.  Sem line item, deal.amount 35000 + valor_padrao 5000 incompatível → low, warning 'valor_revisar'.
 *  5b. Tolerância DENTRO de 5% (valor_padrao 3100, diff 3,2%) → aceita 3000, medium.
 *  5c. Tolerância FORA de 5% (valor_padrao 3200, diff 6,25%) → NÃO aceita, low, 'valor_revisar'.
 *
 * Rastreabilidade SC ROADMAP Fase 115 nº1 (6 casos-âncora) → método:
 *  SC1 monthly                    → test_line_item_mensal_monthly_usa_price_vezes_quantity_high
 *  SC2 annually P1Y                → test_line_item_mensal_annually_36000_p1y_vira_3000
 *  SC3 MRR×ARR                     → test_line_item_mensal_hs_mrr_prevalece_sobre_arr
 *  SC4 serviço único               → test_servico_unica_amount_36000_nao_divide_por_12
 *  SC5 inferência por tolerância   → test_tolerancia_dentro_de_5pct_aceita_com_medium
 *                                     + test_tolerancia_fora_de_5pct_cai_conservador_low
 *  SC6 valor_revisar               → test_sem_line_item_valor_padrao_incompativel_marca_valor_revisar
 */
class HubspotValueResolverTest extends TestCase
{
    private function resolver(): HubspotValueResolver
    {
        return new HubspotValueResolver();
    }

    /**
     * Helper: Servico em memória (sem persistir) com tipo_cobranca/valor_padrao.
     */
    private function servico(string $tipoCobranca, float $valorPadrao = 0.0): Servico
    {
        return new Servico([
            'tipo_cobranca' => $tipoCobranca,
            'valor_padrao'  => $valorPadrao,
        ]);
    }

    // ── Caso 1 — paridade / INVARIANTE de não-regressão ──────────────────────

    public function test_line_item_mensal_monthly_usa_price_vezes_quantity_high(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 3000);
        $lineItem = [
            'price'                     => 3000,
            'quantity'                  => 1,
            'recurringbillingfrequency' => 'monthly',
        ];
        $dealProps = ['amount' => '36000'];

        $out = $this->resolver()->resolve($servico, $lineItem, $dealProps);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $out['valor_original'], 0.01);
        $this->assertSame('unit_price', $out['valor_original_tipo']);
        $this->assertSame('monthly', $out['billing_frequency']);
        $this->assertSame('high', $out['confidence']);
        $this->assertNull($out['warning']);
    }

    // ── Caso 1b — paridade com quantity > 1 ──────────────────────────────────

    public function test_paridade_monthly_price_500_qty_2(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 1000);
        $lineItem = [
            'price'                     => 500,
            'quantity'                  => 2,
            'recurringbillingfrequency' => 'monthly',
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(1000.0, $out['valor_operacional'], 0.01);
        $this->assertSame('high', $out['confidence']);
    }

    // ── Caso 2 — annually + P1Y vira mensal ──────────────────────────────────

    public function test_line_item_mensal_annually_36000_p1y_vira_3000(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 3000);
        $lineItem = [
            'price'                        => 36000,
            'quantity'                     => 1,
            'recurringbillingfrequency'    => 'annually',
            'hs_recurring_billing_period'  => 'P1Y',
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertEqualsWithDelta(36000.0, $out['valor_original'], 0.01);
        $this->assertEqualsWithDelta(3000.0, $out['normalizado_mensal'], 0.01);
        $this->assertSame('annually', $out['billing_frequency']);
        $this->assertSame('P1Y', $out['billing_period']);
        $this->assertSame('high', $out['confidence']);
    }

    // ── Caso 3 — hs_mrr prevalece sobre hs_arr ───────────────────────────────

    public function test_line_item_mensal_hs_mrr_prevalece_sobre_arr(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 3000);
        $lineItem = [
            'price'                     => 36000,
            'hs_mrr'                    => 3000,
            'hs_arr'                    => 36000,
            'recurringbillingfrequency' => 'annually',
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertSame('mrr', $out['valor_original_tipo']);
        $this->assertSame('high', $out['confidence']);
    }

    // ── Caso 4 — serviço único NÃO divide por 12 ─────────────────────────────

    public function test_servico_unica_amount_36000_nao_divide_por_12(): void
    {
        $servico = $this->servico(Servico::TIPO_UNICA, 0);
        $lineItem = [
            'amount'   => 36000,
            'price'    => 36000,
            'quantity' => 1,
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(36000.0, $out['valor_operacional'], 0.01);
        $this->assertSame('high', $out['confidence']);
        $this->assertNull($out['normalizado_mensal'], 'Serviço único não tem equivalente mensal');
    }

    // ── Caso 5 — sem line item, deal.amount 36000 infere 3000 com warning ───

    public function test_sem_line_item_deal_amount_36000_infere_3000_com_warning(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 3000);

        $out = $this->resolver()->resolve($servico, [], ['amount' => '36000']);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertEqualsWithDelta(36000.0, $out['valor_original'], 0.01);
        $this->assertSame('deal_amount_annual', $out['valor_original_tipo']);
        $this->assertSame('medium', $out['confidence']);
        $this->assertNotNull($out['warning'], 'Inferência de amount/12 deve deixar rastro em warning');
    }

    // ── Caso 6 — valor_padrao incompatível (não-degenerado) marca valor_revisar

    public function test_sem_line_item_valor_padrao_incompativel_marca_valor_revisar(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 5000);

        $out = $this->resolver()->resolve($servico, [], ['amount' => '35000']);

        $this->assertSame('low', $out['confidence']);
        $this->assertNotNull($out['warning']);
        $this->assertStringContainsString('valor_revisar', $out['warning']);
    }

    // ── Caso 5b — tolerância DENTRO de 5% (3,2%) aceita com medium ───────────

    public function test_tolerancia_dentro_de_5pct_aceita_com_medium(): void
    {
        // amount/12 = 3000; valor_padrao = 3100 → diferença de 3,2% (<= 5%)
        $servico = $this->servico(Servico::TIPO_MENSAL, 3100);

        $out = $this->resolver()->resolve($servico, [], ['amount' => '36000']);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertSame('medium', $out['confidence']);
        $this->assertNotNull($out['warning']);
    }

    // ── Caso 5c — tolerância FORA de 5% (6,25%) cai conservador (low) ────────

    public function test_tolerancia_fora_de_5pct_cai_conservador_low(): void
    {
        // amount/12 = 3000; valor_padrao = 3200 → diferença de 6,25% (> 5%)
        $servico = $this->servico(Servico::TIPO_MENSAL, 3200);

        $out = $this->resolver()->resolve($servico, [], ['amount' => '36000']);

        $this->assertSame('low', $out['confidence']);
        $this->assertNotNull($out['warning']);
        $this->assertStringContainsString('valor_revisar', $out['warning']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // fast-260806 — line item NAO recorrente (hs_mrr/hs_arr = 0)
    //
    // Caso real de producao: empresa #410 "Empresa Completa", deal
    // 63434160016, line item "MAP" a R$ 3.000. Chegou no sistema como
    // R$ 0,00 com confidence 'high' e sem warning. Duas causas encadeadas:
    //   1) hs_mrr = 0 lido como MRR autoritativo (0 no HubSpot significa
    //      "nao e recorrente", nunca "de graca");
    //   2) sem frequencia declarada, o fluxo caia no ramo amount/12 e
    //      devolveria R$ 250 mesmo depois de corrigir (1).
    // ═══════════════════════════════════════════════════════════════════════

    /** Line item nao recorrente com price: vale o price, nao 0 e nem price/12. */
    public function test_line_item_map_nao_recorrente_vale_o_price_e_nao_zero(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 0);
        $lineItem = [
            'name'                        => 'MAP',
            'price'                       => 3000,
            'amount'                      => 3000,
            'quantity'                    => 1,
            'hs_mrr'                      => 0,
            'hs_arr'                      => 0,
            'hs_tcv'                      => 3000,
            'hs_acv'                      => 3000,
            'recurringbillingfrequency'   => null,
            'hs_recurring_billing_period' => null,
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, ['amount' => '3000']);

        // O numero e o que importa: nem 0 (bug do hs_mrr), nem 250 (amount/12).
        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertNotEqualsWithDelta(0.0, $out['valor_operacional'], 0.01);
        $this->assertNotEqualsWithDelta(250.0, $out['valor_operacional'], 0.01);
        $this->assertSame('unit_price', $out['valor_original_tipo']);
        $this->assertEqualsWithDelta(3000.0, $out['normalizado_mensal'], 0.01);
        // Sem recorrencia declarada, pede conferencia humana — mas nao e chute.
        $this->assertSame('medium', $out['confidence']);
        $this->assertNotNull($out['warning']);
    }

    /** hs_mrr = 0 nao pode mais ser a proveniencia do valor. */
    public function test_hs_mrr_zero_nao_vira_proveniencia_mrr(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 0);
        $lineItem = [
            'price'                     => 1500,
            'quantity'                  => 1,
            'hs_mrr'                    => 0,
            'hs_arr'                    => 0,
            'recurringbillingfrequency' => null,
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertNotSame('mrr', $out['valor_original_tipo']);
        $this->assertEqualsWithDelta(1500.0, $out['valor_operacional'], 0.01);
    }

    /** NAO-REGRESSAO: hs_mrr > 0 continua sendo fonte forte, com 'high'. */
    public function test_hs_mrr_positivo_continua_prevalecendo_com_high(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 0);
        $lineItem = [
            'price'                     => 9999,
            'quantity'                  => 1,
            'hs_mrr'                    => 500,
            'hs_arr'                    => 6000,
            'recurringbillingfrequency' => 'monthly',
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(500.0, $out['valor_operacional'], 0.01);
        $this->assertSame('mrr', $out['valor_original_tipo']);
        $this->assertEqualsWithDelta(6000.0, $out['valor_original'], 0.01);
        $this->assertSame('high', $out['confidence']);
        $this->assertNull($out['warning']);
    }

    /** Contrato legitimamente zerado continua chegando a 0, sem explodir. */
    public function test_line_item_genuinamente_zerado_resulta_em_zero(): void
    {
        $servico = $this->servico(Servico::TIPO_MENSAL, 0);
        $lineItem = [
            'price'                     => 0,
            'amount'                    => 0,
            'quantity'                  => 1,
            'hs_mrr'                    => 0,
            'hs_arr'                    => 0,
            'recurringbillingfrequency' => null,
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(0.0, $out['valor_operacional'], 0.01);
    }

    /** NAO-REGRESSAO: o ramo amount/12 segue valendo quando NAO ha price. */
    public function test_ramo_amount_dividido_por_12_so_vale_sem_price(): void
    {
        // amount 36000 / 12 = 3000, que bate com valor_padrao 3000 → medium.
        $servico = $this->servico(Servico::TIPO_MENSAL, 3000);
        $lineItem = [
            'amount'                    => 36000,
            'quantity'                  => 1,
            'recurringbillingfrequency' => null,
        ];

        $out = $this->resolver()->resolve($servico, $lineItem, []);

        $this->assertEqualsWithDelta(3000.0, $out['valor_operacional'], 0.01);
        $this->assertSame('deal_amount_annual', $out['valor_original_tipo']);
        $this->assertSame('medium', $out['confidence']);
    }
}
