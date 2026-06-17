<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 35 Plan 35-02 (HubSpot v2 — contato + MlbEmpresa).
 *
 * Cobre:
 *  T1. Servico=Polos → cria Company + MlbEmpresa(POLO) + MlbImplementacao (D-05)
 *  T2. Servico=Assessoria Premium → cria Company + MlbEmpresa(ASSESSORIA)
 *  T3. Servico=Publicidade → so Company (helper retorna null, sem MlbEmpresa)
 *  T4. Contato.email preenche email_cliente quando Company.email vazio (D-04)
 *  T5. Contato.phone preenche telefone quando Company.phone vazio (D-04)
 *  T6. Sem contato associado → fluxo completa sem erro (resiliencia)
 *  T7. Nome do contato (firstname + lastname) anexado em notes (D-04)
 *
 * Usa Http::fake para mockar HubSpot API. HMAC v3 calculado da mesma forma
 * que Phase34HubspotWebhookTest (POST + URL + body + ts).
 */
class Phase35HubspotV2Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        // Forca config previsivel — independente do .env.testing local.
        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => '1352209026,closedwon',
            'services.hubspot.props.deal' => [
                'nicho'              => 'nicho',
                'dor'                => 'dor',
                'vende_ml'           => 'vende_ml',
                'faturamento_mensal' => 'faturamento_mensal',
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

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessario quando
     * usamos $this->call() direto.
     */
    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    /**
     * Evento HubSpot padrao (overridable via $overrides).
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
     * Helper p/ disparar o webhook com payload padrao + signature valida.
     */
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
     * Mock padrao dos GETs HubSpot.
     *
     * @param  array  $dealProps     properties do deal (override de defaults)
     * @param  array  $companyProps  properties do company (override; null = company nao associado)
     * @param  array|false  $contactProps  properties do contato; false = sem contato associado
     */
    private function mockaHubSpot(array $dealProps, ?array $companyProps = [], array|false $contactProps = []): void
    {
        $deal = array_merge([
            'dealname'           => 'Cliente Teste',
            'amount'             => '1500.00',
            'dealstage'          => 'closedwon',
            'nicho'              => 'Moda',
            'dor'                => 'Vendas baixas',
            'vende_ml'           => 'true',
            'faturamento_mensal' => '50000.00',
            'servico_ecf'        => 'Mentoria Avançada',
        ], $dealProps);

        $fakes = [];

        // IMPORTANTE: Http::fake matcheia pelo primeiro padrao que bate. As
        // chaves MAIS especificas devem vir primeiro (associations) e o
        // catch-all do deal (deals/9876*) por ultimo. Patterns terminam com
        // * para casar query-string ?properties=... que o client envia.

        // Associations: companies
        if ($companyProps === null) {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
                'results' => [],
            ]);
        } else {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/companies'] = Http::response([
                'results' => [['toObjectId' => 55501]],
            ]);
            $fakes['api.hubapi.com/crm/v3/objects/companies/55501*'] = Http::response([
                'id'         => '55501',
                'properties' => array_merge([
                    'name'  => 'Cliente Teste',
                    'cnpj'  => '12345678000199',
                    'email' => 'company@cliente.com',
                    'phone' => '11999998888',
                ], $companyProps),
            ]);
        }

        // Associations: contacts
        if ($contactProps === false) {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
                'results' => [],
            ]);
        } else {
            $fakes['api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'] = Http::response([
                'results' => [['toObjectId' => 77701]],
            ]);
            $fakes['api.hubapi.com/crm/v3/objects/contacts/77701*'] = Http::response([
                'id'         => '77701',
                'properties' => array_merge([
                    'firstname' => 'João',
                    'lastname'  => 'Silva',
                    'email'     => 'contato@cliente.com',
                    'phone'     => '11888887777',
                ], $contactProps),
            ]);
        }

        // Deal — catch-all DEPOIS das associacoes (pattern 'deals/9876*'
        // matchearia tambem '/associations/...' se viesse antes).
        $fakes['api.hubapi.com/crm/v3/objects/deals/9876*'] = Http::response([
            'id'         => '9876',
            'properties' => $deal,
        ]);

        Http::fake($fakes);
    }

    // ─── Testes ───────────────────────────────────────────────────────────────

    public function test_polos_cria_mlb_empresa_e_implementacao(): void
    {
        Servico::create([
            'nome'          => 'Polos SP',
            'valor_padrao'  => 2500.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Polos SP']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        // Company criada
        $this->assertSame(1, Company::count());
        $company = Company::first();

        // Servico dispara implementacao Polos → MlbEmpresa(POLO) + MlbImplementacao
        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa POLO deveria ter sido criada');
        $this->assertSame('POLO', $mlbEmp->tipo);
        $this->assertSame('POLOS', $mlbEmp->projeto);
        $this->assertSame($company->name, $mlbEmp->nome);

        $impl = MlbImplementacao::where('empresa_id', $mlbEmp->id)->first();
        $this->assertNotNull($impl, 'MlbImplementacao deveria ter sido criada pela factory');
        $this->assertNotEmpty($impl->token, 'Token publico deve ser gerado');
        $this->assertIsArray($impl->dados, 'dados padrao deve ser array');
    }

    public function test_assessoria_cria_mlb_empresa(): void
    {
        Servico::create([
            'nome'          => 'Assessoria Premium',
            'valor_padrao'  => 1800.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Assessoria Premium']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $mlbEmp  = MlbEmpresa::where('company_id', $company->id)->first();
        $this->assertNotNull($mlbEmp, 'MlbEmpresa ASSESSORIA deveria existir');
        $this->assertSame('ASSESSORIA', $mlbEmp->tipo);

        // Assessoria NAO cria MlbImplementacao (so Polos cria).
        $this->assertSame(0, MlbImplementacao::count(), 'Assessoria nao deve criar MlbImplementacao');
    }

    public function test_publicidade_nao_cria_mlb_empresa(): void
    {
        Servico::create([
            'nome'          => 'Publicidade Mercado Livre',
            'valor_padrao'  => 1200.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $this->mockaHubSpot(['servico_ecf' => 'Publicidade Mercado Livre']);

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $this->assertSame(1, Company::count(), 'Company deve ser criada normalmente');
        $this->assertSame(0, MlbEmpresa::count(), 'Publicidade nao deve criar MlbEmpresa');
        $this->assertSame(0, MlbImplementacao::count(), 'Publicidade nao deve criar MlbImplementacao');
    }

    public function test_contato_email_fallback_quando_company_email_vazio(): void
    {
        $this->mockaHubSpot(
            dealProps: ['servico_ecf' => 'Servico Inexistente'],
            companyProps: ['email' => ''],            // company.email vazia
            contactProps: ['email' => 'contato@fallback.com'],
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertNotNull($company);
        $this->assertSame(
            'contato@fallback.com',
            $company->email_cliente,
            'Quando company.email e vazio, contato.email deve ser usado como fallback'
        );
    }

    public function test_contato_telefone_fallback_quando_company_phone_vazio(): void
    {
        $this->mockaHubSpot(
            dealProps: ['servico_ecf' => 'Servico Inexistente'],
            companyProps: ['phone' => ''],
            contactProps: ['phone' => '11777776666'],
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertSame('11777776666', $company->telefone);
    }

    public function test_sem_contato_associado_fluxo_completa_sem_erro(): void
    {
        $this->mockaHubSpot(
            dealProps: ['servico_ecf' => 'Servico Inexistente'],
            companyProps: [],
            contactProps: false, // sem contato vinculado ao deal
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        $company = Company::first();
        $this->assertNotNull($company);
        // Sem fallback de contato — campos da company vem direto do mock.
        $this->assertSame('company@cliente.com', $company->email_cliente);
        $this->assertSame('11999998888', $company->telefone);

        // Sem contato → nada em notes (servico Inexistente vai pra notes
        // como 'Serviço (HubSpot)...', mas sem linha 'Contato (HubSpot)').
        $this->assertStringNotContainsString('Contato (HubSpot):', (string) $company->notes);
    }

    public function test_nome_contato_anexado_em_notes(): void
    {
        $this->mockaHubSpot(
            dealProps: ['servico_ecf' => 'Servico Inexistente'],
            companyProps: [],
            contactProps: [
                'firstname' => 'Maria',
                'lastname'  => 'Souza',
            ],
        );

        $r = $this->disparaWebhook([$this->eventoPadrao()]);
        $r->assertStatus(200);

        $company = Company::first();
        $this->assertStringContainsString(
            'Contato (HubSpot): Maria Souza',
            (string) $company->notes,
            'Notes deve conter linha do contato HubSpot'
        );
    }
}
