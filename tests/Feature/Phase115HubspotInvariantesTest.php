<?php

namespace Tests\Feature;

use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

/**
 * Suite Feature — Phase 115 Plano 02 (HUB-TEST-05 — invariantes transversais
 * da milestone v20.0).
 *
 * Fecha a LACUNA identificada na auditoria da Fase 115: os dois invariantes
 * de segurança do critério de aceite ("nenhuma chamada HubSpot real vaza" +
 * "token/segredo nunca aparece no log") tinham comportamento implícito
 * (Http::fake usado em toda suite), mas nenhum teste-guarda DEDICADO provava
 * isso de forma explícita e transversal (cobrindo webhook E replay).
 *
 * Padrão de setUp/HMAC/fakes replicado de Phase113HubspotEnrichmentTest /
 * Phase114HubspotReplayTest — não reinventa o disparo do webhook.
 *
 * Rastreabilidade Fase 115 Plano 02 — SC5 (HUB-TEST-05, transversais) → método:
 *  zero rede real (webhook + replay)      → test_nenhuma_chamada_hubspot_real_no_processamento_do_webhook
 *  zero token/segredo no log ecf-webhooks → test_tokens_e_segredos_nunca_aparecem_no_log_ecf_webhooks
 */
class Phase115HubspotInvariantesTest extends TestCase
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
     * Calcula assinatura v3 conforme o controller:
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

    private function dispararWebhook(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    /**
     * Mocka deal + company + 1 contato + 1 line item mapeado — SOMENTE hosts
     * api.hubapi.com. Usado nos 2 testes (webhook e, quando aplicável, replay
     * reusam os MESMOS fetches por object_id).
     */
    private function mockHubspotCompleto(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 91001]],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/91001*' => Http::response([
                'id'         => '91001',
                'properties' => [
                    'name'  => 'Cliente Invariantes LTDA',
                    'cnpj'  => '11122233000144',
                    'email' => 'invariantes@cliente.com',
                    'phone' => '11988887777',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response([
                'results' => [['toObjectId' => 401]],
            ]),
            'api.hubapi.com/crm/v3/objects/contacts/401*' => Http::response([
                'id'         => '401',
                'properties' => [
                    'firstname' => 'Beatriz',
                    'lastname'  => 'Guarda',
                    'email'     => 'beatriz@cliente.com',
                    'jobtitle'  => 'Financeiro',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items' => Http::response([
                'results' => [['id' => '51001']],
            ]),
            'api.hubapi.com/crm/v3/objects/line_items/51001*' => Http::response([
                'id'         => '51001',
                'properties' => [
                    'name'                      => 'Servico Invariantes',
                    'price'                     => '900',
                    'recurringbillingfrequency' => 'monthly',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => [
                    'dealname'  => 'Cliente Invariantes',
                    'dealstage' => 'closedwon',
                ],
            ]),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Invariante 1: zero requisição HubSpot real (webhook + replay)
    // ══════════════════════════════════════════════════════════════════

    public function test_nenhuma_chamada_hubspot_real_no_processamento_do_webhook(): void
    {
        // Guarda: QUALQUER requisicao HTTP nao mockada explicitamente lanca
        // StrayRequestException — prova que 100% das chamadas sao fake.
        Http::preventStrayRequests();
        $this->mockHubspotCompleto();

        Servico::create([
            'nome'          => 'Servico Invariantes',
            'valor_padrao'  => 900,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        HubspotLineItemMapping::create([
            'line_item_name' => 'Servico Invariantes',
            'servico_id'     => Servico::where('nome', 'Servico Invariantes')->value('id'),
            'ativo'          => true,
        ]);

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);

        // Se alguma chamada tivesse escapado para um host fora do fake,
        // StrayRequestException teria sido lancada e o response nao seria 200.
        $resp->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);

        // Toda requisicao enviada tem host api.hubapi.com (nenhuma outra
        // origem foi contatada durante o processamento do webhook).
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.hubapi.com');
        });

        // Cobre tambem o caminho de REPLAY sob a MESMA guarda de rede real —
        // o comando refaz os mesmos fetches (deal/company/contatos/line_items).
        $this->artisan('hubspot:reprocess-event', ['id' => $evento->id])
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.hubapi.com');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  Invariante 2: token/segredo HubSpot nunca aparece no log ecf-webhooks
    // ══════════════════════════════════════════════════════════════════

    public function test_tokens_e_segredos_nunca_aparecem_no_log_ecf_webhooks(): void
    {
        $this->mockHubspotCompleto();

        // Handler de teste captura TODOS os registros emitidos no canal
        // ecf-webhooks durante o processamento do webhook.
        $handler = new TestHandler();
        Log::channel('ecf-webhooks')->getLogger()->pushHandler($handler);

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $registros = $handler->getRecords();
        $this->assertNotEmpty($registros, 'O canal ecf-webhooks deve emitir ao menos 1 registro durante o processamento');

        $accessToken  = (string) config('services.hubspot.access_token'); // 'token-fake'
        $clientSecret = (string) config('services.hubspot.client_secret'); // SECRET de teste

        foreach ($registros as $registro) {
            $mensagemSerializada = json_encode([
                'message' => (string) $registro->message,
                'context' => $registro->context,
            ]);

            $this->assertStringNotContainsString(
                $accessToken,
                $mensagemSerializada,
                'Log do canal ecf-webhooks NAO pode conter o access_token HubSpot'
            );
            $this->assertStringNotContainsString(
                $clientSecret,
                $mensagemSerializada,
                'Log do canal ecf-webhooks NAO pode conter o client_secret (segredo HMAC)'
            );
        }
    }
}
