<?php

namespace Tests\Feature\Phase128;

use App\Jobs\GerarContratoAssinaturaJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\HubspotLineItemMapping;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 128 Plano 04 (SC1 estrutural / SC2) — prova, pelo caminho HTTP REAL
 * do webhook HubSpot (`POST /api/webhooks/hubspot`), que a empresa criada
 * ali chega ao MESMO gate administrativo (`GatilhoContratoAdministrativoService`)
 * que os demais pontos de entrada.
 *
 * ⚠️ Esta suíte NÃO é a prova do Success Criteria 1. `Http::fake()` confirma
 * alegremente um payload errado — cinco bugs desta milestone nasceram
 * exatamente disso. O que estas suítes provam é a FIAÇÃO: o gate é chamado,
 * na ordem certa, com o resultado certo. A prova do envelope real é o gate
 * humano do plano 06, contra o sandbox.
 */
class GatilhoContratoHubspotTest extends TestCase
{
    use RefreshDatabase;

    private const HUBSPOT_SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const HUBSPOT_URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'          => self::HUBSPOT_SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => '1352209026,closedwon',
            'services.hubspot.props.deal'             => ['servico' => 'servico_ecf'],
            'services.hubspot.props.company'          => [
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
    }

    private function fakeSignatariosEcf(): void
    {
        config(['services.clicksign.signatarios_ecf' => [
            ['nome' => 'Socio Um', 'email' => 'socio1@example.com', 'papel' => 'contratada'],
            ['nome' => 'Socio Dois', 'email' => 'socio2@example.com', 'papel' => 'contratada'],
            ['nome' => 'Comercial', 'email' => 'comercial@example.com', 'papel' => 'testemunha'],
        ]]);
    }

    private function assinaturaHubspot(string $body, string $ts): string
    {
        $url           = url(self::HUBSPOT_URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::HUBSPOT_SECRET, true));
    }

    private function servidorHubspot(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    private function eventoHubspotPadrao(array $overrides = []): array
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

    private function disparaWebhookHubspot(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::HUBSPOT_URL, [], [], [], $this->servidorHubspot([
            'X-HubSpot-Signature-v3'      => $this->assinaturaHubspot($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Fluxo LEGADO (sem line items) — deal.servico_ecf + amount, com company
     * e contato completos (dados mínimos do gate administrativo, plano
     * 127-03: e-mail, CNPJ 14 dígitos, nome de quem assina). Reusa a forma
     * de `Phase124RegressaoHubspotTest::mockaHubSpot()`.
     */
    private function mockaHubSpotLegado(array $dealProps = []): void
    {
        $deal = array_merge([
            'dealname'    => 'Cliente Teste Gate 128',
            'amount'      => '1500.00',
            'dealstage'   => 'closedwon',
            'servico_ecf' => 'Gestão',
        ], $dealProps);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 55501]],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/55501*' => Http::response([
                'id'         => '55501',
                'properties' => [
                    'name'  => 'Cliente Teste Gate 128',
                    'cnpj'  => '12345678000199',
                    'email' => 'company@clientegate128.com',
                    'phone' => '11999998888',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response([
                'results' => [['toObjectId' => 77701]],
            ]),
            'api.hubapi.com/crm/v3/objects/contacts/77701*' => Http::response([
                'id'         => '77701',
                'properties' => [
                    'firstname' => 'João',
                    'lastname'  => 'Silva',
                    'email'     => 'contato@clientegate128.com',
                    'phone'     => '11888887777',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items' => Http::response(['results' => []]),
            'api.hubapi.com/crm/v3/objects/deals/9876*'                        => Http::response([
                'id'         => '9876',
                'properties' => $deal,
            ]),
        ]);
    }

    /**
     * Fluxo com LINE ITEMS (Fase 37) — dois itens no mesmo deal, mapeados
     * para dois `Servico` distintos. Fixture copiada do padrão de
     * `Phase37WebhookLineItemsTest::mockHubspot()`, com company+contato
     * completos adicionados (esta suíte precisa de "sem pendência").
     */
    private function mockaHubSpotComLineItems(array $lineItems): void
    {
        $fakes = [
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 55502]],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/55502*' => Http::response([
                'id'         => '55502',
                'properties' => [
                    'name'  => 'Cliente Teste Gate 128 Line Items',
                    'cnpj'  => '98765432000188',
                    'email' => 'company@clientegate128li.com',
                    'phone' => '11999997777',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response([
                'results' => [['toObjectId' => 77702]],
            ]),
            'api.hubapi.com/crm/v3/objects/contacts/77702*' => Http::response([
                'id'         => '77702',
                'properties' => [
                    'firstname' => 'Maria',
                    'lastname'  => 'Souza',
                    'email'     => 'contato@clientegate128li.com',
                    'phone'     => '11888886666',
                ],
            ]),
        ];

        $lineItemsAssocResults = [];
        foreach ($lineItems as $li) {
            $lineItemsAssocResults[] = ['id' => (string) $li['id']];
        }
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items'] = Http::response([
            'results' => $lineItemsAssocResults,
        ]);

        foreach ($lineItems as $li) {
            $fakes["api.hubapi.com/crm/v3/objects/line_items/{$li['id']}*"] = Http::response([
                'id'         => (string) $li['id'],
                'properties' => [
                    'name'                      => $li['name'],
                    'price'                     => $li['price'],
                    'quantity'                  => $li['quantity']                  ?? null,
                    'hs_product_id'             => $li['hs_product_id']             ?? null,
                    'recurringbillingfrequency' => $li['recurringbillingfrequency'] ?? 'monthly',
                ],
            ]);
        }

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => [
                'dealname'  => 'Cliente Teste Gate 128 Line Items',
                'amount'    => '2000.00',
                'dealstage' => 'closedwon',
            ],
        ]);

        Http::fake($fakes);
    }

    // ─── Cenário 1 — dados completos, 1 serviço que exige contrato ─────────

    /**
     * Empresa criada pelo webhook, serviço exige contrato, SEM pendência e
     * com dados mínimos completos (e-mail, CNPJ, nome de quem assina) →
     * ContratoAssinatura criado (1 por ContratoServico que exige contrato)
     * e GerarContratoAssinaturaJob despachado.
     */
    public function test_webhook_com_dados_completos_dispara_contrato_e_job(): void
    {
        $this->fakeSignatariosEcf();
        Bus::fake();

        Servico::create([
            'nome'           => 'Gestão',
            'valor_padrao'   => 1000,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);

        $this->mockaHubSpotLegado();

        $r = $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $r->assertStatus(200);

        $company = Company::where('name', 'Cliente Teste Gate 128')->firstOrFail();

        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->count());
        Bus::assertDispatched(GerarContratoAssinaturaJob::class, 1);
    }

    // ─── Cenário 2 — Gestão + Polos no mesmo deal ───────────────────────────

    /**
     * Mesma empresa (agora via line items, Fase 37) com serviço 'Polos'
     * junto de um serviço que exige contrato: nenhum ContratoAssinatura é
     * criado para Polos (isenção do plano 128-01, aplicada dentro do
     * ContratoClicksignService), mas o serviço que exige contrato dispara
     * normalmente.
     */
    public function test_webhook_com_gestao_e_polos_no_mesmo_deal_pula_polos(): void
    {
        $this->fakeSignatariosEcf();
        Bus::fake();

        $gestao = Servico::create([
            'nome'           => 'Gestão',
            'valor_padrao'   => 1000,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
        $polos = Servico::create([
            'nome'           => 'Polos',
            'valor_padrao'   => 500,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_OUTROS,
            'exige_contrato' => false,
        ]);
        HubspotLineItemMapping::create(['line_item_name' => 'MAP-GESTAO', 'servico_id' => $gestao->id, 'ativo' => true]);
        HubspotLineItemMapping::create(['line_item_name' => 'MAP-POLOS', 'servico_id' => $polos->id, 'ativo' => true]);

        $this->mockaHubSpotComLineItems([
            ['id' => 3001, 'name' => 'MAP-GESTAO', 'price' => '1000', 'recurringbillingfrequency' => 'monthly'],
            ['id' => 3002, 'name' => 'MAP-POLOS', 'price' => '500', 'recurringbillingfrequency' => 'monthly'],
        ]);

        $r = $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $r->assertStatus(200);

        $company = Company::where('name', 'Cliente Teste Gate 128 Line Items')->firstOrFail();

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $polos->id)->count());
        $this->assertSame(1, ContratoAssinatura::where('company_id', $company->id)->where('servico_id', $gestao->id)->count());
    }

    // ─── Cenário 3 — só Polos ────────────────────────────────────────────

    /**
     * Empresa cujo único serviço é 'Polos': zero ContratoAssinatura, zero
     * job — a isenção corta ANTES do 1º portão de pendências (SC0, o
     * orquestrador nem avalia) — mas o roteamento operacional (MlbEmpresa)
     * continua acontecendo normalmente, porque é um portão independente.
     */
    public function test_webhook_com_apenas_polos_nao_gera_contrato_mas_roteia(): void
    {
        Bus::fake();

        Servico::firstOrCreate(
            ['nome' => 'Polos'],
            [
                'valor_padrao'   => 500,
                'tipo_cobranca'  => Servico::TIPO_MENSAL,
                'ativo'          => true,
                'setor'          => Servico::SETOR_OUTROS,
                'exige_contrato' => false,
            ],
        );

        $this->mockaHubSpotLegado(['servico_ecf' => 'Polos']);

        $r = $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $r->assertStatus(200);

        $company = Company::where('name', 'Cliente Teste Gate 128')->firstOrFail();

        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
        Bus::assertNotDispatched(GerarContratoAssinaturaJob::class);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'O roteamento operacional continua criando a ficha POLO, mesmo sem contrato');
    }
}
