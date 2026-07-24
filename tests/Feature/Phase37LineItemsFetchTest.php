<?php

namespace Tests\Feature;

use App\Services\HubspotApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-03 (HubspotApiClient::fetchDealLineItems).
 *
 * Cobre o wrapper que encadeia 2 chamadas a API HubSpot CRM v3:
 *   1) GET /crm/v3/objects/deals/{dealId}/associations/line_items — lista IDs
 *   2) GET /crm/v3/objects/line_items/{id}?properties=...        — detalhes
 *
 * Estrategia de teste:
 *  - Http::fake mocka os 2 endpoints com URL patterns
 *  - HubspotApiClient instanciado com token explicito 'fake-token'
 *  - NAO usa banco (sem RefreshDatabase) — eh um HTTP wrapper puro
 *
 * Cenarios cobertos (Task 2 do 37-03):
 *  T1. Deal com 2 line items: shape completo + cast price/quantity
 *  T2. Deal sem associations (results vazio): retorna []
 *  T3. Associations 404: resiliente (retorna [])
 *  T4. Associations 500: resiliente (retorna [])
 *  T5. Um line_item individual 500: loga warning + pula, segue com o outro
 *  T6. recurringbillingfrequency ausente: preserva null
 *  T7. price string '1234.56' converte para float
 *  T8. quantity string '3' converte para int
 *  T9. Log warning de falha individual NAO vaza o Bearer token
 */
class Phase37LineItemsFetchTest extends TestCase
{
    private const DEAL_ID  = '123';
    private const ASSOC_URL = 'https://api.hubapi.com/crm/v3/objects/deals/123/associations/line_items';

    /**
     * Helper: instancia o client com token explicito para isolar dos envs.
     */
    private function client(): HubspotApiClient
    {
        return new HubspotApiClient(token: 'fake-token');
    }

    public function test_deal_com_2_line_items_retorna_lista_completa(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [
                    ['id' => '111', 'type' => 'deal_to_line_item'],
                    ['id' => '222', 'type' => 'deal_to_line_item'],
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/111*' => Http::response([
                'id' => '111',
                'properties' => [
                    'name'                              => 'Mapeamento',
                    'description'                       => 'Servico de mapeamento inicial',
                    'price'                              => '500',
                    'amount'                             => '500',
                    'quantity'                           => '1',
                    'hs_product_id'                      => 'P1',
                    'hs_sku'                              => 'SKU-1',
                    'recurringbillingfrequency'          => 'monthly',
                    'hs_recurring_billing_period'        => 'P1M',
                    'hs_recurring_billing_start_date'    => '2026-01-01',
                    'hs_recurring_billing_end_date'      => '2026-12-31',
                    'hs_line_item_currency_code'         => 'BRL',
                    'hs_mrr'                              => '500',
                    'hs_arr'                              => '6000',
                    'hs_tcv'                              => '6000',
                    'hs_acv'                              => '6000',
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/222*' => Http::response([
                'id' => '222',
                'properties' => [
                    'name'                      => 'Polos',
                    'price'                     => '1200',
                    'quantity'                  => '1',
                    'hs_product_id'             => 'P2',
                    'recurringbillingfrequency' => null,
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertIsArray($out);
        $this->assertCount(2, $out);
        $this->assertEquals(
            [
                'id',
                'name',
                'description',
                'price',
                'amount',
                'quantity',
                'hs_product_id',
                'hs_sku',
                'recurringbillingfrequency',
                'hs_recurring_billing_period',
                'hs_recurring_billing_start_date',
                'hs_recurring_billing_end_date',
                'hs_line_item_currency_code',
                'hs_mrr',
                'hs_arr',
                'hs_tcv',
                'hs_acv',
            ],
            array_keys($out[0])
        );

        $this->assertEquals('111', $out[0]['id']);
        $this->assertEquals('Mapeamento', $out[0]['name']);
        $this->assertEquals('Servico de mapeamento inicial', $out[0]['description']);
        $this->assertEquals(500.0, $out[0]['price']);
        $this->assertEquals(500.0, $out[0]['amount']);
        $this->assertIsFloat($out[0]['amount']);
        $this->assertEquals(1, $out[0]['quantity']);
        $this->assertEquals('P1', $out[0]['hs_product_id']);
        $this->assertEquals('SKU-1', $out[0]['hs_sku']);
        $this->assertEquals('monthly', $out[0]['recurringbillingfrequency']);
        $this->assertEquals('P1M', $out[0]['hs_recurring_billing_period']);
        $this->assertEquals('2026-01-01', $out[0]['hs_recurring_billing_start_date']);
        $this->assertEquals('2026-12-31', $out[0]['hs_recurring_billing_end_date']);
        $this->assertEquals('BRL', $out[0]['hs_line_item_currency_code']);
        $this->assertEquals(500.0, $out[0]['hs_mrr']);
        $this->assertIsFloat($out[0]['hs_mrr']);
        $this->assertEquals(6000.0, $out[0]['hs_arr']);
        $this->assertEquals(6000.0, $out[0]['hs_tcv']);
        $this->assertEquals(6000.0, $out[0]['hs_acv']);

        $this->assertEquals('222', $out[1]['id']);
        $this->assertEquals('Polos', $out[1]['name']);
        $this->assertEquals(1200.0, $out[1]['price']);
        $this->assertNull($out[1]['recurringbillingfrequency']);
        // Item sem as props novas nas properties do HubSpot -> tudo null (nao presente).
        $this->assertNull($out[1]['description']);
        $this->assertNull($out[1]['amount']);
        $this->assertNull($out[1]['hs_sku']);
        $this->assertNull($out[1]['hs_mrr']);
    }

    public function test_deal_sem_line_items_retorna_array_vazio(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response(['results' => []], 200),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertSame([], $out);
    }

    public function test_associations_404_retorna_array_vazio(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response(['status' => 'error', 'message' => 'Not found'], 404),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        // Resiliente: 404 nao lanca excecao, retorna [] (mesmo padrao de
        // fetchAssociatedCompanyId da Phase 34).
        $this->assertSame([], $out);
    }

    public function test_associations_500_retorna_array_vazio(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response(['status' => 'error'], 500),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertSame([], $out);
    }

    public function test_line_item_individual_500_pula_item_e_loga_warning(): void
    {
        // Mock o logger do canal ecf-webhooks para confirmar que warning() foi
        // chamado com deal/item ids + status (sem o token).
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                '[HubSpot Webhook] Falha ao buscar line item',
                \Mockery::on(function ($context) {
                    return is_array($context)
                        && ($context['deal_id'] ?? null) === self::DEAL_ID
                        && ($context['line_item_id'] ?? null) === '222'
                        && ($context['status'] ?? null) === 500;
                })
            );
        // Hotfix 2026-06-19 — fetchDealLineItems agora loga info() de "associations OK"
        // com IDs encontrados; mock aceita silenciosamente para nao quebrar o spy strict.
        $logger->shouldReceive('info')->andReturnNull();

        Log::shouldReceive('channel')
            ->with('ecf-webhooks')
            ->andReturn($logger);

        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [
                    ['id' => '111'],
                    ['id' => '222'],
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/111*' => Http::response([
                'id' => '111',
                'properties' => [
                    'name'                      => 'Mapeamento',
                    'price'                     => '500',
                    'quantity'                  => '1',
                    'hs_product_id'             => 'P1',
                    'recurringbillingfrequency' => 'monthly',
                ],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/222*' => Http::response([
                'status' => 'error',
            ], 500),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        // Apenas o item OK deve estar no retorno.
        $this->assertCount(1, $out);
        $this->assertEquals('111', $out[0]['id']);
    }

    public function test_recurringbillingfrequency_ausente_preserva_null(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [['id' => '111']],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/111*' => Http::response([
                'id' => '111',
                // Sem 'recurringbillingfrequency' nas properties.
                'properties' => [
                    'name'          => 'Servico Avulso',
                    'price'         => '300',
                    'quantity'      => '1',
                    'hs_product_id' => 'P9',
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('recurringbillingfrequency', $out[0]);
        $this->assertNull($out[0]['recurringbillingfrequency']);
    }

    public function test_price_string_converte_para_float(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [['id' => '111']],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/111*' => Http::response([
                'id' => '111',
                'properties' => [
                    'name'                      => 'Teste',
                    'price'                     => '1234.56',
                    'quantity'                  => '1',
                    'hs_product_id'             => 'P1',
                    'recurringbillingfrequency' => null,
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertEquals(1234.56, $out[0]['price']);
        $this->assertIsFloat($out[0]['price']);
    }

    public function test_quantity_string_converte_para_int(): void
    {
        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [['id' => '111']],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/111*' => Http::response([
                'id' => '111',
                'properties' => [
                    'name'                      => 'Teste',
                    'price'                     => '100',
                    'quantity'                  => '3',
                    'hs_product_id'             => 'P1',
                    'recurringbillingfrequency' => null,
                ],
            ], 200),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertSame(3, $out[0]['quantity']);
        $this->assertIsInt($out[0]['quantity']);
    }

    public function test_log_de_falha_individual_nao_vaza_o_bearer_token(): void
    {
        // Mock o logger especifico do canal ecf-webhooks para inspecionar o
        // contexto da chamada warning() — confirma que o token NAO eh logado.
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->withArgs(function ($message, $context = []) {
                // Mensagem nao contem o token
                if (str_contains((string) $message, 'fake-token')) {
                    return false;
                }
                if (str_contains((string) $message, 'Bearer')) {
                    return false;
                }
                // Contexto serializado tambem nao
                $ctxJson = json_encode($context);
                if (str_contains($ctxJson, 'fake-token')) {
                    return false;
                }
                if (str_contains($ctxJson, 'Bearer ')) {
                    return false;
                }
                return true;
            })
            ->atLeast()->once();
        // Hotfix 2026-06-19 — info() de "associations OK" tambem precisa nao vazar.
        $logger->shouldReceive('info')
            ->withArgs(function ($message, $context = []) {
                $ctxJson = json_encode($context);
                return !str_contains((string) $message, 'fake-token')
                    && !str_contains((string) $message, 'Bearer')
                    && !str_contains($ctxJson, 'fake-token')
                    && !str_contains($ctxJson, 'Bearer ');
            })
            ->andReturnNull();

        Log::shouldReceive('channel')
            ->with('ecf-webhooks')
            ->andReturn($logger);

        Http::fake([
            self::ASSOC_URL => Http::response([
                'results' => [['id' => '222']],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/line_items/222*' => Http::response([
                'status' => 'error',
            ], 500),
        ]);

        $out = $this->client()->fetchDealLineItems(self::DEAL_ID);

        $this->assertSame([], $out);
    }
}
