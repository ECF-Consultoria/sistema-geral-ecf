<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite E2E — Phase 112 Plan 03 (controller fino delega ao HubspotDealHandoffService).
 *
 * Prova o critério de aceite âncore da milestone v20.0: deal fechado ganho com
 * line item mensal R$3.000 + amount/ARR R$36.000 grava valor_contratado=3000
 * (nunca 36000), com a proveniência (R$36.000, tipo, confiança, warning)
 * persistida nas colunas hubspot_* do ContratoServico.
 *
 * Cenários (behavior do plano 112-03):
 *  1. Line item mensal (price 3000, monthly) → valor_contratado=3000, confidence=high.
 *  2. Line item annually (price 36000, P1Y, hs_arr 36000) → valor_contratado=3000,
 *     hubspot_valor_original=36000, billing_frequency=annually, billing_period=P1Y.
 *  3. Multi-line-item (2 mapeados) → 2 ContratoServico com hubspot_line_item_id distintos.
 *  4. observacoes legada (Phase 37) continua contendo 'mensal'.
 *  5. Serviço tipo_cobranca=unica, amount 36000 → valor_contratado=36000 (NÃO divide por 12).
 *  6. Fluxo legado (sem line item), deal.amount=36000 + valor_padrao=3000 → valor_contratado=3000
 *     com hubspot_valor_warning preenchido (inferência) e evento status=processado.
 *
 * IMPORTANTE: Http::fake em TODOS os cenários — nenhuma chamada real a api.hubapi.com.
 */
class Phase112HubspotHandoffWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal' => [
                'servico'            => 'servico_ecf',
            ],
            'services.hubspot.props.company' => [
                'name'  => 'name',
                'cnpj'  => 'cnpj',
                'email' => 'email',
                'phone' => 'phone',
            ],
            'services.hubspot.props.contact' => [
                'firstname' => 'firstname',
                'lastname'  => 'lastname',
                'email'     => 'email',
                'phone'     => 'phone',
            ],
        ]);

        // RefreshDatabase pode aplicar seeds — isola os cenarios limpando previamente.
        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('contratos_servico')->delete();
        DB::table('servicos')->delete();
    }

    /**
     * Calcula assinatura v3 conforme o controller:
     *   base64(hmac_sha256(secret, METHOD + URI + body + timestamp))
     */
    private function assinatura(string $body, string $ts, string $secret = self::SECRET): string
    {
        $url = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));
    }

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessario para
     * $this->call() (que NAO aplica defaultHeaders do withHeaders()).
     */
    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    /**
     * Helper: cria um Servico no catalogo (ativo, mensal por default).
     */
    private function criarServico(
        string $nome,
        string $setor = Servico::SETOR_OUTROS,
        float $valorPadrao = 0,
        string $tipoCobranca = Servico::TIPO_MENSAL,
    ): Servico {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => $tipoCobranca,
            'ativo'         => true,
            'setor'         => $setor,
        ]);
    }

    /**
     * Helper: cria mapping line_item.name -> Servico (ativo por default).
     */
    private function criarMapping(string $lineItemName, Servico $servico, bool $ativo = true): HubspotLineItemMapping
    {
        return HubspotLineItemMapping::create([
            'line_item_name' => $lineItemName,
            'servico_id'     => $servico->id,
            'ativo'          => $ativo,
        ]);
    }

    /**
     * Helper: evento HubSpot padrao closedwon (overrides opcionais).
     */
    private function eventoPadrao(array $overrides = []): array
    {
        return array_merge([
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => 9876,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ], $overrides);
    }

    /**
     * Helper: mocka as respostas HubSpot padrao para um deal, com line_items
     * opcional e propriedades ESTENDIDAS (Fase 111/112 — hs_recurring_billing_period,
     * hs_arr, hs_mrr, hs_line_item_currency_code, amount) alem das ja usadas
     * pela Phase 37 (name/price/quantity/hs_product_id/recurringbillingfrequency).
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    private function mockHubspot(
        int $dealId,
        array $dealProps,
        ?int $companyId = null,
        ?array $companyProps = null,
        array $lineItems = [],
        bool $associationsLineItemsRetornaVazio = false,
    ): void {
        $fakes = [];

        // IMPORTANTE: Http::fake usa first-match-wins. URLs mais especificas
        // PRIMEIRO; o glob /deals/{id}* casaria com /deals/{id}/associations/*
        // se viesse antes.

        // ── Associations companies ───────────────────────────────────────────
        $companiesResults = $companyId !== null
            ? [['toObjectId' => $companyId]]
            : [];
        $fakes["api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/companies"] = Http::response([
            'results' => $companiesResults,
        ]);

        // ── Associations contacts ────────────────────────────────────────────
        $fakes["api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/contacts"] = Http::response([
            'results' => [],
        ]);

        // ── Associations line_items ──────────────────────────────────────────
        $lineItemsAssocResults = [];
        if (!$associationsLineItemsRetornaVazio) {
            foreach ($lineItems as $li) {
                $lineItemsAssocResults[] = ['id' => (string) $li['id']];
            }
        }
        $fakes["api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/line_items"] = Http::response([
            'results' => $lineItemsAssocResults,
        ]);

        // ── Cada line_item individual (props estendidas Fase 111/112) ───────
        foreach ($lineItems as $li) {
            $props = [
                'name'                          => $li['name']                          ?? null,
                'price'                         => $li['price']                         ?? null,
                'amount'                        => $li['amount']                        ?? null,
                'quantity'                      => $li['quantity']                      ?? null,
                'hs_product_id'                 => $li['hs_product_id']                 ?? null,
                'recurringbillingfrequency'     => $li['recurringbillingfrequency']     ?? null,
                'hs_recurring_billing_period'   => $li['hs_recurring_billing_period']   ?? null,
                'hs_line_item_currency_code'    => $li['hs_line_item_currency_code']    ?? null,
                'hs_mrr'                        => $li['hs_mrr']                        ?? null,
                'hs_arr'                        => $li['hs_arr']                        ?? null,
            ];
            $fakes["api.hubapi.com/crm/v3/objects/line_items/{$li['id']}*"] = Http::response([
                'id'         => (string) $li['id'],
                'properties' => $props,
            ]);
        }

        // ── Company endpoint (se associado) ──────────────────────────────────
        if ($companyId !== null && $companyProps !== null) {
            $fakes["api.hubapi.com/crm/v3/objects/companies/{$companyId}*"] = Http::response([
                'id'         => (string) $companyId,
                'properties' => $companyProps,
            ]);
        }

        // ── Deal endpoint (catchall — fica POR ULTIMO) ──────────────────────
        $fakes["api.hubapi.com/crm/v3/objects/deals/{$dealId}*"] = Http::response([
            'id'         => (string) $dealId,
            'properties' => $dealProps,
        ]);

        Http::fake($fakes);
    }

    /**
     * Envia o POST do webhook com HMAC valido para um payload de eventos.
     */
    private function dispararWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  TESTES
    // ══════════════════════════════════════════════════════════════════════════

    public function test_line_item_monthly_price_3000_grava_valor_contratado_3000_high(): void
    {
        $servico = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 3000);
        $this->criarMapping('MAP', $servico);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Monthly 3k',
                'dealstage' => 'closedwon',
                'amount'    => '36000',
            ],
            companyId: 66001,
            companyProps: [
                'name'  => 'Cliente Monthly 3k LTDA',
                'cnpj'  => '11111111000101',
                'email' => 'monthly3k@cliente.com',
                'phone' => '11999990101',
            ],
            lineItems: [
                ['id' => 11001, 'name' => 'MAP', 'price' => '3000', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, ContratoServico::count());

        $contrato = ContratoServico::first();
        $this->assertEqualsWithDelta(3000.0, (float) $contrato->valor_contratado, 0.01);
        $this->assertSame('high', $contrato->hubspot_valor_confidence);
    }

    public function test_line_item_annually_p1y_normaliza_36000_para_3000_mensal_com_auditoria(): void
    {
        $servico = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 3000);
        $this->criarMapping('MAP', $servico);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Annually 36k',
                'dealstage' => 'closedwon',
            ],
            companyId: 66002,
            companyProps: [
                'name'  => 'Cliente Annually 36k LTDA',
                'cnpj'  => '22222222000102',
                'email' => 'annually36k@cliente.com',
                'phone' => '11999990102',
            ],
            lineItems: [
                [
                    'id'                          => 12001,
                    'name'                        => 'MAP',
                    'price'                       => '36000',
                    'recurringbillingfrequency'   => 'annually',
                    'hs_recurring_billing_period' => 'P1Y',
                    'hs_arr'                      => '36000',
                ],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, ContratoServico::count());

        $contrato = ContratoServico::first();
        $this->assertEqualsWithDelta(3000.0, (float) $contrato->valor_contratado, 0.01, 'Valor operacional deve ser mensal (36000/12), nunca o bruto anual');
        $this->assertEqualsWithDelta(36000.0, (float) $contrato->hubspot_valor_original, 0.01, 'R$36.000 deve ficar preservado na coluna de auditoria');
        $this->assertNotNull($contrato->hubspot_valor_original_tipo);
        $this->assertSame('annually', $contrato->hubspot_billing_frequency);
        $this->assertSame('P1Y', $contrato->hubspot_billing_period);
    }

    public function test_multi_line_item_cria_contratos_separados_por_line_item_id(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 3000);
        $polos  = $this->criarServico('Polos', Servico::SETOR_OUTROS, 2000);
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Multi Item',
                'dealstage' => 'closedwon',
            ],
            companyId: 66003,
            companyProps: [
                'name'  => 'Cliente Multi Item LTDA',
                'cnpj'  => '33333333000103',
                'email' => 'multi@cliente.com',
                'phone' => '11999990103',
            ],
            lineItems: [
                ['id' => 13001, 'name' => 'MAP',  'price' => '3000', 'recurringbillingfrequency' => 'monthly'],
                ['id' => 13002, 'name' => 'Polo', 'price' => '2000', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(2, ContratoServico::count());

        $lineItemIds = ContratoServico::pluck('hubspot_line_item_id')->sort()->values()->all();
        $this->assertSame(['13001', '13002'], $lineItemIds, 'Cada contrato deve ter hubspot_line_item_id distinto (multi-item nunca consolida)');

        foreach (ContratoServico::all() as $contrato) {
            $this->assertNotNull($contrato->hubspot_valor_original, 'Auditoria de valor deve ser preenchida em CADA contrato');
        }
    }

    public function test_observacoes_legada_mensal_preservada(): void
    {
        $servico = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 3000);
        $this->criarMapping('MAP', $servico);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Observacoes Mensal',
                'dealstage' => 'closedwon',
            ],
            companyId: 66004,
            companyProps: [
                'name'  => 'Cliente Observacoes Mensal LTDA',
                'cnpj'  => '44444444000104',
                'email' => 'obsmensal@cliente.com',
                'phone' => '11999990104',
            ],
            lineItems: [
                ['id' => 14001, 'name' => 'MAP', 'price' => '3000', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato);
        $this->assertStringContainsString('mensal', (string) $contrato->observacoes, 'Linha legada Phase 37 (tipo_cobranca em observacoes) deve continuar sendo gravada');
    }

    public function test_tipo_unica_nao_divide_por_12(): void
    {
        $servico = $this->criarServico('Consultoria Pontual', Servico::SETOR_OUTROS, 0, Servico::TIPO_UNICA);
        $this->criarMapping('MAP-UNICA', $servico);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Tipo Unica',
                'dealstage' => 'closedwon',
            ],
            companyId: 66005,
            companyProps: [
                'name'  => 'Cliente Tipo Unica LTDA',
                'cnpj'  => '55555555000105',
                'email' => 'unica@cliente.com',
                'phone' => '11999990105',
            ],
            lineItems: [
                ['id' => 15001, 'name' => 'MAP-UNICA', 'amount' => '36000'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato);
        $this->assertEqualsWithDelta(36000.0, (float) $contrato->valor_contratado, 0.01, 'Servico tipo_cobranca=unica NUNCA divide por 12');
        $this->assertSame('high', $contrato->hubspot_valor_confidence);
        $this->assertStringContainsString('unica', (string) $contrato->observacoes);
    }

    public function test_legado_sem_line_item_deal_amount_anual_com_warning(): void
    {
        $servico = $this->criarServico('Mentoria', Servico::SETOR_PERFORMANCE, 3000);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'    => 'Cliente Legado Warning',
                'dealstage'   => 'closedwon',
                'amount'      => '36000',
                'servico_ecf' => 'Mentoria',
            ],
            companyId: 66006,
            companyProps: [
                'name'  => 'Cliente Legado Warning LTDA',
                'cnpj'  => '66666666000106',
                'email' => 'legadowarning@cliente.com',
                'phone' => '11999990106',
            ],
            lineItems: [],
            associationsLineItemsRetornaVazio: true,
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato, 'Fluxo legado deve criar contrato pelo servico_ecf + amount');
        $this->assertSame($servico->id, $contrato->servico_id);
        $this->assertEqualsWithDelta(3000.0, (float) $contrato->valor_contratado, 0.01, 'amount anual 36000 deve ser normalizado para 3000 mensal');
        $this->assertEqualsWithDelta(36000.0, (float) $contrato->hubspot_valor_original, 0.01);
        $this->assertNotNull($contrato->hubspot_valor_warning, 'Inferencia amount/12 deve deixar rastro de warning');

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
    }
}
