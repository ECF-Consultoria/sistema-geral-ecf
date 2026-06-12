<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Phase 34 Plan 34-04 (Webhook HubSpot).
 *
 * Cobre:
 *  T1. POST sem assinatura ou com assinatura invalida → 401 + HubspotEvento(signature_valid=false)
 *  T2. Timestamp > 5min antigo → 401 + HubspotEvento(signature_valid=false)
 *  T3. Evento com subscriptionType diferente → status=ignorado (200)
 *  T4. Evento dealstage=closedwon → fetchDeal+fetchCompany mockados → cria Company + ContratoServico
 *  T5. Idempotencia: mesmo objectId 2x → 2o vira ignorado (nao duplica company)
 *  T6. Erro no fetch (Http 500) → status=erro + erro_msg, mas controller retorna 200
 *
 * IMPORTANTE: todas as chamadas usam Http::fake para impedir tentativas reais
 * de chegar a api.hubapi.com durante a suite.
 */
class Phase34HubspotWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        // Forca config previsivel — independente do .env.testing do dev.
        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            // Hotfix multi-pipeline: aceita CSV. Testa com 1 ID custom + closedwon.
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
        ]);
    }

    /**
     * Calcula assinatura v3 da mesma forma que o controller:
     *   base64(hmac_sha256(secret, METHOD + URI + body + timestamp))
     */
    private function assinatura(string $body, string $ts, string $secret = self::SECRET): string
    {
        $url = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));
    }

    /**
     * Converte headers em formato $_SERVER (HTTP_X_*) — necessario quando
     * usamos $this->call() direto (que NAO aplica defaultHeaders do
     * withHeaders()). Equivalente ao transformHeadersToServerVars da framework.
     */
    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key       = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    /**
     * Helper: monta um evento HubSpot padrao (com overrides opcionais).
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

    public function test_signature_invalida_retorna_401(): void
    {
        Http::fake(); // garante zero chamadas reais

        $body = json_encode([$this->eventoPadrao()]);
        $ts   = (string) (int) (microtime(true) * 1000);

        // Sem header X-HubSpot-Signature-v3.
        $resposta = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        $resposta->assertStatus(401);

        // Com header errado.
        $resposta2 = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => 'assinatura-invalida',
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        $resposta2->assertStatus(401);

        // Auditoria: 2 HubspotEvento(signature_valid=false) gravados.
        $this->assertSame(2, HubspotEvento::where('signature_valid', false)->count());
        $this->assertSame(0, Company::count(), 'Nenhuma empresa deve ser criada em request invalida');
    }

    public function test_timestamp_antigo_retorna_401(): void
    {
        Http::fake();

        $body  = json_encode([$this->eventoPadrao()]);
        // 10 minutos no passado (alem da janela de 5 min)
        $tsOld = (string) ((int) (microtime(true) * 1000) - 10 * 60 * 1000);

        // Calcula assinatura corretamente para esse timestamp — mesmo assim deve cair
        // na validacao de timestamp ANTES da assinatura.
        $assinatura = $this->assinatura($body, $tsOld);

        $resposta = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $assinatura,
            'X-HubSpot-Request-Timestamp' => $tsOld,
        ]), $body);

        $resposta->assertStatus(401);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertFalse($evento->signature_valid);
        $this->assertSame('erro', $evento->status);
        $this->assertStringContainsString('timestamp', strtolower($evento->erro_msg));
    }

    public function test_evento_ignorado_quando_propriedade_irrelevante(): void
    {
        Http::fake(); // garante que nenhum fetch acontece

        $body = json_encode([
            $this->eventoPadrao([
                'subscriptionType' => 'contact.creation', // fora do filtro
                'propertyName'     => null,
                'propertyValue'    => null,
            ]),
        ]);
        $ts   = (string) (int) (microtime(true) * 1000);

        $resposta = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        $resposta->assertStatus(200);
        $resposta->assertJson(['ok' => true]);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertTrue($evento->signature_valid);
        $this->assertSame('ignorado', $evento->status);
        $this->assertNull($evento->company_id_criada);
        $this->assertSame(0, Company::count());

        // Confirma que nenhum GET ao HubSpot foi disparado.
        Http::assertNothingSent();
    }

    public function test_evento_processado_cria_company(): void
    {
        // Cataloga um Servico que ira casar com a prop do deal.
        $servico = Servico::create([
            'nome'          => 'Mentoria Avançada',
            'valor_padrao'  => 999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        // Mocka as 3 chamadas ao HubSpot.
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 55501]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => [
                    'dealname'           => 'Cliente Teste LTDA',
                    'amount'             => '1500.00',
                    'dealstage'          => 'closedwon',
                    'nicho'              => 'Moda feminina',
                    'dor'                => 'Vendas estagnadas',
                    'vende_ml'           => 'true',
                    'faturamento_mensal' => '80000.50',
                    'servico_ecf'        => 'Mentoria Avançada',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/55501*' => Http::response([
                'id'         => '55501',
                'properties' => [
                    'name'  => 'Cliente Teste LTDA',
                    'cnpj'  => '12345678000199',
                    'email' => 'contato@cliente.com',
                    'phone' => '11999998888',
                ],
            ]),
        ]);

        $body = json_encode([$this->eventoPadrao()]);
        $ts   = (string) (int) (microtime(true) * 1000);

        $resposta = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertTrue($evento->signature_valid);
        $this->assertSame('processado', $evento->status);
        $this->assertNotNull($evento->company_id_criada);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertSame('Cliente Teste LTDA', $company->name);
        $this->assertSame('12345678000199', $company->cnpj);
        $this->assertSame('contato@cliente.com', $company->email_cliente);
        $this->assertSame('11999998888', $company->telefone);
        $this->assertSame('Moda feminina', $company->nicho);
        $this->assertSame('Vendas estagnadas', $company->dor);
        $this->assertTrue($company->vende_ml);
        $this->assertEqualsWithDelta(80000.50, (float) $company->faturamento_mensal, 0.01);
        $this->assertTrue($company->empresa_nova);
        $this->assertSame('pendente', $company->status);

        // Contrato criado com o Servico do catalogo + valor do amount.
        $contrato = $company->contratosServico()->first();
        $this->assertNotNull($contrato, 'ContratoServico deveria ter sido criado');
        $this->assertSame($servico->id, $contrato->servico_id);
        $this->assertEqualsWithDelta(1500.00, (float) $contrato->valor_contratado, 0.01);
    }

    public function test_idempotencia_nao_duplica_company(): void
    {
        Servico::create([
            'nome'          => 'Mentoria Avançada',
            'valor_padrao'  => 999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 55501]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => [
                    'dealname'    => 'Cliente Idempo',
                    'amount'      => '1000',
                    'dealstage'   => 'closedwon',
                    'servico_ecf' => 'Mentoria Avançada',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/55501*' => Http::response([
                'id'         => '55501',
                'properties' => [
                    'name'  => 'Cliente Idempo',
                    'cnpj'  => '99988877000166',
                    'email' => 'idempo@cliente.com',
                    'phone' => '1133334444',
                ],
            ]),
        ]);

        $body = json_encode([$this->eventoPadrao()]);
        $ts   = (string) (int) (microtime(true) * 1000);
        $sig  = $this->assinatura($body, $ts);

        // 1a entrega → cria
        $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $sig,
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body)->assertStatus(200);

        $this->assertSame(1, Company::count());

        // 2a entrega (mesmo objectId, novo timestamp/sig pra passar HMAC)
        $ts2  = (string) (int) (microtime(true) * 1000);
        $sig2 = $this->assinatura($body, $ts2);

        $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $sig2,
            'X-HubSpot-Request-Timestamp' => $ts2,
        ]), $body)->assertStatus(200);

        // Mesmo objectId — sem nova empresa
        $this->assertSame(1, Company::count(), 'Reentrega nao deve duplicar Company');

        // 2 HubspotEventos: 1 processado, 1 ignorado
        $this->assertSame(1, HubspotEvento::where('status', 'processado')->count());
        $this->assertSame(1, HubspotEvento::where('status', 'ignorado')->count());
    }

    public function test_erro_no_fetch_marca_status_erro_e_retorna_200(): void
    {
        // 500 do HubSpot — HubspotApiClient::fetchDeal vai chamar $res->throw() →
        // controller captura e marca evento.status=erro, MAS retorna 200 (D-04).
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/*' => Http::response(
                ['status' => 'error', 'message' => 'Internal Server Error'],
                500
            ),
        ]);

        $body = json_encode([$this->eventoPadrao()]);
        $ts   = (string) (int) (microtime(true) * 1000);

        $resposta = $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);

        // HubSpot nao retenta — sempre 200, salvo HMAC invalido (D-04).
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertTrue($evento->signature_valid);
        $this->assertSame('erro', $evento->status);
        $this->assertNotNull($evento->erro_msg, 'Mensagem do erro deve ser persistida');
        $this->assertNull($evento->company_id_criada);

        // Garante que nenhuma Company foi criada apesar do 200.
        $this->assertSame(0, Company::count());
    }
}
