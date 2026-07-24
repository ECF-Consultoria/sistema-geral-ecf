<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use App\Services\Hubspot\HubspotCompanyMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 113 Plan 113-03 (HUB-DEDUP-01/02).
 *
 * Tarefa 1 (unit-style, RefreshDatabase): cobre `HubspotCompanyMatcher::encontrar()`
 * isoladamente — ordem de precedência hubspot_company_id > cnpj > email > domain >
 * nome normalizado, classificação forte/fraco e anti-falso-positivo.
 *
 * Tarefa 3 (E2E via webhook, adicionada depois): dedup real disparada pelo
 * `HubspotWebhookController` — match forte (cnpj/hubspot_company_id) enriquece
 * sem duplicar; match fraco (nome) cria empresa nova + grava warning
 * `possivel_duplicidade` no payload do evento.
 */
class Phase113HubspotDedupTest extends TestCase
{
    use RefreshDatabase;

    // ─── Tarefa 1 — HubspotCompanyMatcher (unit-style) ─────────────────────────

    public function test_nenhum_criterio_bate_retorna_sem_match(): void
    {
        Company::factory()->create(['name' => 'Empresa Qualquer', 'cnpj' => '11222333000144']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-999',
            'cnpj'               => '99988877000166',
            'email'              => 'naoexiste@teste.com',
            'domain'             => 'naoexiste.com.br',
            'name'               => 'Nome Completamente Diferente',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
        $this->assertNull($resultado['via']);
    }

    public function test_match_por_hubspot_company_id_e_forte(): void
    {
        $company = Company::factory()->create(['hubspot_company_id' => 'hs-12345']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-12345',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('hubspot_company_id', $resultado['via']);
    }

    public function test_match_por_cnpj_e_forte_com_formatacao_diferente(): void
    {
        $company = Company::factory()->create(['cnpj' => '11.222.333/0001-44']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'cnpj' => '11222333000144', // mesmos digitos, sem formatacao
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('cnpj', $resultado['via']);
    }

    public function test_match_por_email_e_fraco(): void
    {
        $company = Company::factory()->create(['email_cliente' => 'contato@empresateste.com']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'email' => 'contato@empresateste.com',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('email', $resultado['via']);
    }

    public function test_match_por_domain_e_fraco(): void
    {
        $company = Company::factory()->create(['hubspot_domain' => 'empresateste.com.br']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'domain' => 'empresateste.com.br',
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('domain', $resultado['via']);
    }

    public function test_match_por_nome_normalizado_e_fraco(): void
    {
        $company = Company::factory()->create(['name' => 'Padaria do José Ltda.']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'name' => 'PADARIA DO JOSE LTDA', // sem acento/pontuacao/caixa diferente
        ]);

        $this->assertSame($company->id, $resultado['company']->id);
        $this->assertSame('fraco', $resultado['match']);
        $this->assertSame('nome', $resultado['via']);
    }

    public function test_nomes_distintos_nao_casam_por_nome(): void
    {
        Company::factory()->create(['name' => 'Padaria do Zé']);
        Company::factory()->create(['name' => 'Padaria da Ana']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'name' => 'Padaria do João',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
    }

    public function test_precedencia_hubspot_company_id_sobre_cnpj(): void
    {
        $viaId   = Company::factory()->create(['hubspot_company_id' => 'hs-77', 'cnpj' => '11111111000111']);
        $viaCnpj = Company::factory()->create(['cnpj' => '22222222000122']);

        // Critérios batem em AMBAS (hs-77 na primeira, cnpj só existe na segunda,
        // mas o cnpj informado é o da PRIMEIRA — garante que hubspot_company_id
        // vence antes mesmo de avaliar o cnpj).
        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => 'hs-77',
            'cnpj'               => '11111111000111',
        ]);

        $this->assertSame($viaId->id, $resultado['company']->id);
        $this->assertSame('hubspot_company_id', $resultado['via']);
    }

    public function test_precedencia_cnpj_sobre_email(): void
    {
        $viaCnpj = Company::factory()->create(['cnpj' => '33333333000133', 'email_cliente' => 'x@x.com']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'cnpj'  => '33333333000133',
            'email' => 'x@x.com',
        ]);

        $this->assertSame($viaCnpj->id, $resultado['company']->id);
        $this->assertSame('forte', $resultado['match']);
        $this->assertSame('cnpj', $resultado['via']);
    }

    public function test_criterios_vazios_nao_geram_match_espurio(): void
    {
        // Empresa sem cnpj/email/domain preenchidos (null) — critérios vazios
        // no lado do HubSpot NUNCA devem "casar" com colunas vazias no banco.
        Company::factory()->create(['cnpj' => null, 'email_cliente' => null, 'hubspot_domain' => null, 'name' => 'Empresa X']);

        $resultado = app(HubspotCompanyMatcher::class)->encontrar([
            'hubspot_company_id' => null,
            'cnpj'               => '',
            'email'              => '',
            'domain'             => '',
            'name'               => '',
        ]);

        $this->assertNull($resultado['company']);
        $this->assertNull($resultado['match']);
    }

    // ─── Tarefa 3 — E2E via webhook (match forte enriquece / match fraco warning) ──

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    private function configHubspot(): void
    {
        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => 'closedwon',
            'services.hubspot.props.deal' => [
                'nicho'              => 'nicho',
                'dor'                => 'dor',
                'vende_ml'           => 'vende_ml',
                'faturamento_mensal' => 'faturamento_mensal',
                'servico'            => 'servico_ecf',
                'observacao'         => 'observacao',
            ],
            'services.hubspot.props.company' => [
                'name'   => 'name',
                'cnpj'   => 'cnpj',
                'email'  => 'email',
                'phone'  => 'phone',
                'domain' => 'domain',
            ],
            'services.hubspot.props.contact' => [
                'firstname'   => 'firstname',
                'lastname'    => 'lastname',
                'email'       => 'email',
                'phone'       => 'phone',
                'mobilephone' => 'mobilephone',
                'jobtitle'    => 'jobtitle',
            ],
        ]);
    }

    /**
     * Calcula assinatura v3 do mesmo jeito do controller:
     *   base64(hmac_sha256(secret, METHOD + URI + body + timestamp))
     */
    private function assinatura(string $body, string $ts): string
    {
        $url           = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
    }

    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

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

    private function disparaWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Mocka deal + company (sem line items nem contatos) — foco no dedup, nao
     * no fluxo de valor/contrato (ja coberto pelas suites 112/113-02).
     */
    private function mockaHubSpotSemLineItemsNemContatos(array $dealProps, array $companyProps): void
    {
        $fakes = [];

        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
            'results' => [['toObjectId' => 90001]],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/companies/90001*'] = Http::response([
            'id'         => '90001',
            'properties' => $companyProps,
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
            'results' => [],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items'] = Http::response([
            'results' => [],
        ]);
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => array_merge([
                'dealname'  => 'Cliente Dedup',
                'dealstage' => 'closedwon',
            ], $dealProps),
        ]);

        Http::fake($fakes);
    }

    public function test_match_forte_por_cnpj_enriquece_sem_duplicar(): void
    {
        $this->configHubspot();

        // Empresa ja existe com cnpj (dedup por cnpj, dígitos) e um campo
        // MANUAL ja preenchido — nao pode ser sobrescrito pelo webhook.
        $existente = Company::factory()->create([
            'cnpj'          => '77.788.899/0001-11',
            'dor'           => 'Dor preenchida manualmente pelo Comercial',
            'email_cliente' => null, // vazio — deve ser ENRIQUECIDO
            'empresa_nova'  => false, // ja "vista" pelo Comercial — nao pode ser remarcada
        ]);

        $this->mockaHubSpotSemLineItemsNemContatos(
            dealProps: [
                'nicho' => 'Nicho vindo do HubSpot',
                // 'dor' OMITIDO no deal — nao interfere na asserção do campo manual
            ],
            companyProps: [
                'name'   => 'Cliente Dedup CNPJ LTDA',
                'cnpj'   => '77788899000111', // mesmos digitos, sem formatacao
                'email'  => 'novo@clientededup.com',
                'phone'  => '11966665555',
                'domain' => 'clientededup.com.br',
            ],
        );

        $resp = $this->disparaWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        // NAO duplica.
        $this->assertSame(1, Company::count());

        $existente->refresh();

        // Campo manual intocado.
        $this->assertSame('Dor preenchida manualmente pelo Comercial', $existente->dor);

        // Campo vazio enriquecido.
        $this->assertSame('novo@clientededup.com', $existente->email_cliente);
        $this->assertSame('11966665555', $existente->telefone);
        $this->assertSame('Nicho vindo do HubSpot', $existente->nicho);
        $this->assertSame('90001', $existente->hubspot_company_id);
        $this->assertSame('clientededup.com.br', $existente->hubspot_domain);

        // empresa_nova NAO remarcada (a empresa ja existia).
        $this->assertFalse((bool) $existente->empresa_nova);

        // Snapshot reescrito com o shape completo do evento novo.
        $snapshot = $existente->hubspot_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('contacts', $snapshot);
        $this->assertArrayHasKey('line_items', $snapshot);
        $this->assertArrayHasKey('captured_at', $snapshot);
        $this->assertNotEmpty($snapshot['captured_at']);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
        $this->assertSame($existente->id, $evento->company_id_criada);
    }

    public function test_match_forte_por_hubspot_company_id_guard_contrato_line_item_inedito(): void
    {
        $this->configHubspot();

        $servico = Servico::create([
            'nome'          => 'Gestão Dedup',
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
        HubspotLineItemMapping::create(['line_item_name' => 'MAP1', 'servico_id' => $servico->id, 'ativo' => true]);
        HubspotLineItemMapping::create(['line_item_name' => 'MAP2', 'servico_id' => $servico->id, 'ativo' => true]);

        $existente = Company::factory()->create(['hubspot_company_id' => '90001']);
        ContratoServico::create([
            'company_id'            => $existente->id,
            'servico_id'            => $servico->id,
            'valor_contratado'      => 3000,
            'data_contratacao'      => now()->toDateString(),
            'ativo'                 => true,
            'hubspot_line_item_id'  => '21001', // L1 — ja existe (reentrega do MESMO line item)
        ]);

        $fakes = [
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 90001]],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/90001*' => Http::response([
                'id'         => '90001',
                'properties' => ['name' => 'Cliente Dedup Contrato LTDA'],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response(['results' => []]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items' => Http::response([
                'results' => [['id' => '21001'], ['id' => '21002']],
            ]),
            'api.hubapi.com/crm/v3/objects/line_items/21001*' => Http::response([
                'id'         => '21001',
                'properties' => ['name' => 'MAP1', 'price' => '3000', 'recurringbillingfrequency' => 'monthly'],
            ]),
            'api.hubapi.com/crm/v3/objects/line_items/21002*' => Http::response([
                'id'         => '21002',
                'properties' => ['name' => 'MAP2', 'price' => '3000', 'recurringbillingfrequency' => 'monthly'],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => ['dealname' => 'Cliente Dedup Contrato', 'dealstage' => 'closedwon'],
            ]),
        ];
        Http::fake($fakes);

        $resp = $this->disparaWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        // NAO duplica empresa.
        $this->assertSame(1, Company::count());

        // So o line item INEDITO (21002) vira contrato novo; 21001 permanece 1x so.
        $this->assertSame(1, ContratoServico::where('hubspot_line_item_id', '21001')->count());
        $this->assertSame(1, ContratoServico::where('hubspot_line_item_id', '21002')->count());
        $this->assertSame(2, ContratoServico::where('company_id', $existente->id)->count());
    }

    public function test_match_fraco_por_nome_cria_empresa_nova_e_grava_warning(): void
    {
        $this->configHubspot();

        $candidata = Company::factory()->create([
            'name'          => 'Padaria Silva',
            'cnpj'          => '11111111000199', // NAO bate com o cnpj do webhook
            'email_cliente' => 'candidata@padariasilva.com', // NAO bate com o email do webhook
        ]);

        $this->mockaHubSpotSemLineItemsNemContatos(
            dealProps: [],
            companyProps: [
                'name'  => 'Padaria Silva', // so o NOME bate — match fraco
                'cnpj'  => '22222222000188',
                'email' => 'outroemail@padariasilva.com',
            ],
        );

        $resp = $this->disparaWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        // Match fraco NAO bloqueia a criacao — cria empresa NOVA (nao perde o handoff).
        $this->assertSame(2, Company::count());

        // Candidata original NAO foi mesclada/alterada.
        $candidata->refresh();
        $this->assertSame('candidata@padariasilva.com', $candidata->email_cliente);
        $this->assertSame('11111111000199', $candidata->cnpj);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $payload = $evento->payload;
        $this->assertArrayHasKey('possivel_duplicidade', $payload);
        $this->assertSame($candidata->id, $payload['possivel_duplicidade']['candidate_company_id']);
        $this->assertSame('nome', $payload['possivel_duplicidade']['via']);

        // Empresa nova recebe o warning tambem no proprio snapshot.
        $novaCompany = Company::where('id', '!=', $candidata->id)->first();
        $this->assertNotNull($novaCompany);
        $this->assertTrue((bool) $novaCompany->empresa_nova);
        $warningsSnapshot = $novaCompany->hubspot_snapshot['warnings'] ?? [];
        $tiposWarning = array_column($warningsSnapshot, 'tipo');
        $this->assertContains('possivel_duplicidade', $tiposWarning);
    }

    public function test_db_fresco_sem_match_cria_normalmente_sem_warning(): void
    {
        $this->configHubspot();

        $this->mockaHubSpotSemLineItemsNemContatos(
            dealProps: [],
            companyProps: [
                'name'  => 'Cliente Totalmente Novo LTDA',
                'cnpj'  => '33333333000177',
                'email' => 'novo@clientetotalmentenovo.com',
            ],
        );

        $resp = $this->disparaWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
        $this->assertArrayNotHasKey('possivel_duplicidade', $evento->payload);
    }
}
