<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 37 Plan 37-04 (Webhook HubSpot + Line Items → ContratoServico).
 *
 * Cobre o coracao da Phase 37: deal closedwon que vem com line items eh
 * materializado em ContratoServico via HubspotLineItemMapping::paraNome,
 * preservando compatibilidade total com o fluxo legado (Phase 34/35) quando
 * o deal vem SEM line items.
 *
 * Cenarios:
 *  T1.  Deal com 1 line item mapeado (MAP) -> Company + 1 ContratoServico
 *  T2.  Deal com 2 line items mapeados (Gestao + Polo) -> Company + 2 ContratoServico
 *  T3.  Deal com line item NAO mapeado -> Company SEM ContratoServico + warning no payload
 *  T4.  Deal SEM line items -> fluxo legado Phase 34/35 (Servico::where nome + amount)
 *  T5.  recurringbillingfrequency=monthly anotado em ContratoServico.observacoes
 *  T6.  recurringbillingfrequency ausente -> ContratoServico.observacoes='tipo_cobranca: unica'
 *  T7.  Idempotencia: 2o webhook do mesmo deal NAO duplica contratos
 *  T8.  Line item mapeado para 'Polos' cria MlbEmpresa(tipo=POLO)
 *  T9.  Mapping inativo (ativo=false) trata como nao-mapeado (paraNome filtra ativo)
 *  T10. Zero regressao Phase 34: deal closedwon Servico nome batendo + amount
 *       continua funcionando (replica do T4 da Phase34HubspotWebhookTest)
 *
 * IMPORTANTE: Http::fake em TODOS os testes para impedir tentativas reais
 * de chegar a api.hubapi.com.
 */
class Phase37WebhookLineItemsTest extends TestCase
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

        // RefreshDatabase recria as tabelas mas pode aplicar seeds — limpamos
        // mapeamentos e servicos previamente seedados para isolar os cenarios.
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
     * Helper: cria um Servico no catalogo (ativo por default).
     */
    private function criarServico(string $nome, string $setor = Servico::SETOR_OUTROS, float $valorPadrao = 0): Servico
    {
        return Servico::create([
            'nome'          => $nome,
            'valor_padrao'  => $valorPadrao,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
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
     * Helper: mocka as respostas HubSpot padrao para um deal,
     * com line_items opcional (lista de IDs) e propriedades dos line items.
     *
     * @param  array<int, array<string, mixed>>  $lineItems  cada item:
     *     ['id' => '111', 'name' => '...', 'price' => '500', 'recurring' => 'monthly']
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

        // ── Cada line_item individual ────────────────────────────────────────
        foreach ($lineItems as $li) {
            $props = [
                'name'                      => $li['name']                      ?? null,
                'price'                     => $li['price']                     ?? null,
                'quantity'                  => $li['quantity']                  ?? null,
                'hs_product_id'             => $li['hs_product_id']             ?? null,
                'recurringbillingfrequency' => $li['recurringbillingfrequency'] ?? null,
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

    public function test_deal_com_1_line_item_mapeado_cria_contrato_servico(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        $this->criarMapping('MAP', $gestao);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente 1 Item',
                'dealstage' => 'closedwon',
            ],
            companyId: 55501,
            companyProps: [
                'name'  => 'Cliente 1 Item LTDA',
                'cnpj'  => '11111111000111',
                'email' => 'c1@cliente.com',
                'phone' => '11999990001',
            ],
            lineItems: [
                ['id' => 1001, 'name' => 'MAP', 'price' => '500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(1, ContratoServico::count());

        $company = Company::first();
        $contrato = ContratoServico::first();
        $this->assertSame($company->id, $contrato->company_id);
        $this->assertSame($gestao->id, $contrato->servico_id);
        $this->assertEqualsWithDelta(500.0, (float) $contrato->valor_contratado, 0.01);
        $this->assertTrue((bool) $contrato->ativo);
    }

    public function test_deal_com_2_line_items_mapeados_cria_2_contratos(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        $polos  = $this->criarServico('Polos', Servico::SETOR_OUTROS, 2000);
        $this->criarMapping('MAP', $gestao);
        $this->criarMapping('Polo', $polos);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente 2 Itens',
                'dealstage' => 'closedwon',
            ],
            companyId: 55502,
            companyProps: [
                'name'  => 'Cliente 2 Itens LTDA',
                'cnpj'  => '22222222000122',
                'email' => 'c2@cliente.com',
                'phone' => '11999990002',
            ],
            lineItems: [
                ['id' => 2001, 'name' => 'MAP',  'price' => '500',  'recurringbillingfrequency' => 'monthly'],
                ['id' => 2002, 'name' => 'Polo', 'price' => '1500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(2, ContratoServico::count());

        $servicoIds = ContratoServico::pluck('servico_id')->sort()->values()->all();
        $this->assertSame(
            collect([$gestao->id, $polos->id])->sort()->values()->all(),
            $servicoIds,
            'Esperado contratos para os dois servicos distintos',
        );
    }

    public function test_deal_com_line_item_nao_mapeado_grava_em_payload(): void
    {
        // Nenhum mapping para 'XYZ Premium' — vai cair em "nao mapeado".
        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Sem Mapping',
                'dealstage' => 'closedwon',
            ],
            companyId: 55503,
            companyProps: [
                'name'  => 'Cliente Sem Mapping LTDA',
                'cnpj'  => '33333333000133',
                'email' => 'c3@cliente.com',
                'phone' => '11999990003',
            ],
            lineItems: [
                ['id' => 3001, 'name' => 'XYZ Premium', 'price' => '999', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count(), 'Empresa deve ser criada mesmo sem mapping');
        $this->assertSame(0, ContratoServico::count(), 'Nenhum contrato sem mapping');

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status, 'Status final processado (warning, nao erro)');

        $payload = $evento->payload;
        $this->assertArrayHasKey('line_items_nao_mapeados', $payload, 'Warning deve ir para o payload do evento');
        $this->assertCount(1, $payload['line_items_nao_mapeados']);
        $this->assertSame('XYZ Premium', $payload['line_items_nao_mapeados'][0]['name']);
    }

    public function test_deal_sem_line_items_cai_no_fluxo_legado(): void
    {
        // Fluxo legado Phase 34/35: deal sem line items + Servico nome bate + amount.
        $servico = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 999);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'    => 'Cliente Legado',
                'dealstage'   => 'closedwon',
                'amount'      => '1000',
                'servico_ecf' => 'Gestão',
            ],
            companyId: 55504,
            companyProps: [
                'name'  => 'Cliente Legado LTDA',
                'cnpj'  => '44444444000144',
                'email' => 'c4@cliente.com',
                'phone' => '11999990004',
            ],
            lineItems: [],
            associationsLineItemsRetornaVazio: true,
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(1, ContratoServico::count(), 'Fluxo legado deve criar contrato pelo servico_ecf + amount');

        $contrato = ContratoServico::first();
        $this->assertSame($servico->id, $contrato->servico_id);
        $this->assertEqualsWithDelta(1000.0, (float) $contrato->valor_contratado, 0.01);
    }

    public function test_recurringbillingfrequency_monthly_anota_observacoes(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        $this->criarMapping('MAP', $gestao);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Monthly',
                'dealstage' => 'closedwon',
            ],
            companyId: 55505,
            companyProps: [
                'name'  => 'Cliente Monthly LTDA',
                'cnpj'  => '55555555000155',
                'email' => 'c5@cliente.com',
                'phone' => '11999990005',
            ],
            lineItems: [
                ['id' => 5001, 'name' => 'MAP', 'price' => '500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato);
        $this->assertStringContainsString('mensal', (string) $contrato->observacoes, 'tipo_cobranca derivada deve registrar mensal em observacoes');
    }

    public function test_recurringbillingfrequency_ausente_vira_unica(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        $this->criarMapping('MAP', $gestao);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Unica',
                'dealstage' => 'closedwon',
            ],
            companyId: 55506,
            companyProps: [
                'name'  => 'Cliente Unica LTDA',
                'cnpj'  => '66666666000166',
                'email' => 'c6@cliente.com',
                'phone' => '11999990006',
            ],
            lineItems: [
                // Sem recurringbillingfrequency → tipo_cobranca='unica'
                ['id' => 6001, 'name' => 'MAP', 'price' => '500'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato);
        $this->assertStringContainsString('unica', (string) $contrato->observacoes, 'Sem recurring deve registrar unica em observacoes');
    }

    public function test_idempotencia_segundo_webhook_nao_duplica_contratos(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        $this->criarMapping('MAP', $gestao);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Idempo',
                'dealstage' => 'closedwon',
            ],
            companyId: 55507,
            companyProps: [
                'name'  => 'Cliente Idempo LTDA',
                'cnpj'  => '77777777000177',
                'email' => 'c7@cliente.com',
                'phone' => '11999990007',
            ],
            lineItems: [
                ['id' => 7001, 'name' => 'MAP', 'price' => '500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        // 1a entrega
        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);
        $this->assertSame(1, Company::count());
        $this->assertSame(1, ContratoServico::count());

        // 2a entrega — mesmo object_id, sig regenerada
        $this->dispararWebhook([$this->eventoPadrao()])->assertStatus(200);

        $this->assertSame(1, Company::count(), 'Reentrega NAO deve duplicar empresa');
        $this->assertSame(1, ContratoServico::count(), 'Reentrega NAO deve duplicar contratos');

        $this->assertSame(1, HubspotEvento::where('status', 'processado')->count());
        $this->assertSame(1, HubspotEvento::where('status', 'ignorado')->count());
    }

    public function test_line_item_polos_cria_mlb_empresa_polo(): void
    {
        $polos = $this->criarServico('Polos', Servico::SETOR_OUTROS, 2000);
        $this->criarMapping('Polo', $polos);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Polo',
                'dealstage' => 'closedwon',
            ],
            companyId: 55508,
            companyProps: [
                'name'  => 'Cliente Polo LTDA',
                'cnpj'  => '88888888000188',
                'email' => 'c8@cliente.com',
                'phone' => '11999990008',
            ],
            lineItems: [
                ['id' => 8001, 'name' => 'Polo', 'price' => '1500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(1, MlbEmpresa::count(), 'Servico Polos deve disparar MlbEmpresa POLO');

        $mlb = MlbEmpresa::first();
        $this->assertSame('POLO', $mlb->tipo);
        $this->assertSame(Company::first()->id, $mlb->company_id);
    }

    public function test_line_item_com_mapping_inativo_trata_como_nao_mapeado(): void
    {
        $gestao = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 1000);
        // Mapping com ativo=false → paraNome filtra ativo() → retorna null.
        $this->criarMapping('MAP', $gestao, ativo: false);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'  => 'Cliente Map Inativo',
                'dealstage' => 'closedwon',
            ],
            companyId: 55509,
            companyProps: [
                'name'  => 'Cliente Map Inativo LTDA',
                'cnpj'  => '99999999000199',
                'email' => 'c9@cliente.com',
                'phone' => '11999990009',
            ],
            lineItems: [
                ['id' => 9001, 'name' => 'MAP', 'price' => '500', 'recurringbillingfrequency' => 'monthly'],
            ],
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(0, ContratoServico::count(), 'Mapping inativo NAO deve criar contrato');

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
        $payload = $evento->payload;
        $this->assertArrayHasKey('line_items_nao_mapeados', $payload);
        $this->assertSame('MAP', $payload['line_items_nao_mapeados'][0]['name']);
    }

    public function test_zero_regressao_phase_34_fluxo_legado_servico_amount(): void
    {
        // Replica do test_evento_processado_cria_company da Phase34HubspotWebhookTest:
        // deal sem line items + servico nome bate + amount → ContratoServico.
        $servico = $this->criarServico('Mentoria Avançada', Servico::SETOR_PERFORMANCE, 999.99);

        $this->mockHubspot(
            dealId: 9876,
            dealProps: [
                'dealname'           => 'Cliente Phase 34',
                'amount'             => '1500.00',
                'dealstage'          => 'closedwon',
                'servico_ecf'        => 'Mentoria Avançada',
            ],
            companyId: 55510,
            companyProps: [
                'name'  => 'Cliente Phase 34 LTDA',
                'cnpj'  => '10101010000110',
                'email' => 'phase34@cliente.com',
                'phone' => '11999990010',
            ],
            lineItems: [],
            associationsLineItemsRetornaVazio: true,
        );

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status);
        $this->assertNotNull($evento->company_id_criada);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertSame('Cliente Phase 34 LTDA', $company->name);

        $contrato = $company->contratosServico()->first();
        $this->assertNotNull($contrato, 'Phase 34 legacy: contrato deve ser criado');
        $this->assertSame($servico->id, $contrato->servico_id);
        $this->assertEqualsWithDelta(1500.00, (float) $contrato->valor_contratado, 0.01);
    }
}
