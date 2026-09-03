<?php

namespace Tests\Feature\Phase138;

use App\Http\Controllers\Api\HubspotWebhookController;
use App\Models\Company;
use App\Models\CompanyEtapaTransicao;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Models\User;
use App\Services\HubspotApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Fase 138 Plano 07 (COMERC-01/D-13/D-17).
 *
 * Prova que os DOIS caminhos de produção do webhook (`processar()` via
 * `receive()` e `reprocessarEvento()` via `hubspot:reprocess-event`) fazem a
 * empresa nascer na etapa `aguardando_administrativo`, pelo ator de sistema:
 *
 *  1. deal GANHO com `HUBSPOT_WEBHOOK_USER_ID` configurado cria a empresa já
 *     na etapa 1, sem cadastro manual.
 *  2. `company_etapa_transicoes.user_id` é o da conta de sistema.
 *  3. reprocessar o MESMO evento (mecanismo de "reentrega"/reprocessamento)
 *     devolve `recusado` internamente (empresa já na etapa), não lança, o
 *     evento não fica em `erro` e a etapa permanece.
 *  4. ator ausente do config → empresa criada normalmente, resposta HTTP de
 *     sucesso, evento `processado`, etapa `null`.
 *  5. ator apontando pra id inexistente → mesmo comportamento do caso 4.
 *  6. `reprocessarEvento()` sobre um evento cuja empresa (match forte por
 *     `existing_company_id`) ainda não tem etapa transiciona pra etapa 1.
 */
class ComercEtapaNascimentoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'          => self::SECRET,
            'services.hubspot.access_token'            => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id'  => 'closedwon',
            'services.hubspot.props.deal' => [
                'servico'   => 'servico_ecf',
                'owner_id'  => 'hubspot_owner_id',
                'closedate' => 'closedate',
            ],
            'services.hubspot.props.company' => [
                'name'  => 'name',
                'cnpj'  => 'cnpj',
                'email' => 'email',
                'phone' => 'phone',
            ],
        ]);

        Servico::create([
            'nome'          => 'Mentoria Avançada',
            'valor_padrao'  => 999.99,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);
    }

    private function contaSistema(): User
    {
        return User::factory()->create([
            'name'   => 'Sistema HubSpot',
            'role'   => 'consultor',
            'active' => false,
        ]);
    }

    private function assinatura(string $body, string $ts): string
    {
        $url = url(self::URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::SECRET, true));
    }

    private function servidor(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    private function eventoPadrao(int $objectId, array $overrides = []): array
    {
        return array_merge([
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => $objectId,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => 'closedwon',
        ], $overrides);
    }

    private function enviar(int $objectId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([$this->eventoPadrao($objectId, $overrides)]);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::URL, [], [], [], $this->servidor([
            'X-HubSpot-Signature-v3'      => $this->assinatura($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    private function fakesDeal(int $dealId, int $companyId, string $nome, ?string $ownerId = '778899'): void
    {
        $propsDeal = [
            'dealname'    => $nome,
            'amount'      => '2000.00',
            'dealstage'   => 'closedwon',
            'servico_ecf' => 'Mentoria Avançada',
            'closedate'   => '2026-08-24',
        ];
        if ($ownerId !== null) {
            $propsDeal['hubspot_owner_id'] = $ownerId;
        }

        Http::fake([
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}/associations/companies" => Http::response([
                'results' => [['toObjectId' => $companyId]],
            ]),
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}*" => Http::response([
                'id'         => (string) $dealId,
                'properties' => $propsDeal,
            ]),
            "api.hubapi.com/crm/v3/objects/companies/{$companyId}*" => Http::response([
                'id'         => (string) $companyId,
                'properties' => [
                    'name'  => $nome,
                    'cnpj'  => '11222333000144',
                    'email' => 'contato@empresa.com',
                    'phone' => '11988887777',
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/*' => Http::response([
                'id'        => $ownerId,
                'email'     => 'ana@ecfconsultoria.com.br',
                'firstName' => 'Ana',
                'lastName'  => 'Souza',
            ]),
        ]);
    }

    public function test_deal_ganho_cria_empresa_ja_na_etapa_1_sem_cadastro_manual(): void
    {
        $porSistema = $this->contaSistema();
        config(['services.hubspot.webhook_user_id' => $porSistema->id]);

        $this->fakesDeal(9910, 66010, 'Empresa Etapa1 LTDA');

        $resposta = $this->enviar(9910);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa);
    }

    public function test_linha_de_historico_tem_user_id_da_conta_de_sistema(): void
    {
        $porSistema = $this->contaSistema();
        config(['services.hubspot.webhook_user_id' => $porSistema->id]);

        $this->fakesDeal(9911, 66011, 'Empresa Historico LTDA');

        $resposta = $this->enviar(9911);
        $resposta->assertStatus(200);

        $evento  = HubspotEvento::first();
        $company = Company::find($evento->company_id_criada);

        $transicao = CompanyEtapaTransicao::where('company_id', $company->id)->first();
        $this->assertNotNull($transicao);
        $this->assertSame($porSistema->id, $transicao->user_id);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $transicao->etapa_nova);
    }

    public function test_reprocessar_o_mesmo_evento_devolve_recusado_sem_lancar_e_mantem_etapa(): void
    {
        $porSistema = $this->contaSistema();
        config(['services.hubspot.webhook_user_id' => $porSistema->id]);

        $this->fakesDeal(9912, 66012, 'Empresa Reentrega LTDA');

        $resposta = $this->enviar(9912);
        $resposta->assertStatus(200);

        $evento  = HubspotEvento::first();
        $company = Company::find($evento->company_id_criada);
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa);

        // "Reentrega"/reprocessamento do MESMO evento — bypassa a idempotência
        // de INGESTÃO de propósito (é o mecanismo real de replay do admin, ou
        // de uma redelivery do HubSpot já processada por dentro). A empresa já
        // está na etapa 1: transicionar() devolve 'recusado', nunca lança.
        $resumo = app(HubspotWebhookController::class)->reprocessarEvento($evento->fresh(), app(HubspotApiClient::class));

        $this->assertSame($company->id, $resumo['company_id']);

        $evento->refresh();
        $this->assertSame('processado', $evento->status, 'Reprocessamento nao pode marcar o evento como erro');

        $company->refresh();
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa, 'Etapa deve permanecer inalterada apos recusado');

        // Só uma linha de histórico foi criada — a segunda chamada foi recusada,
        // não gerou uma segunda linha em company_etapa_transicoes.
        $this->assertSame(1, CompanyEtapaTransicao::where('company_id', $company->id)->count());
    }

    public function test_sem_hubspot_webhook_user_id_configurado_empresa_fica_sem_etapa_e_evento_processado(): void
    {
        config(['services.hubspot.webhook_user_id' => null]);

        $this->fakesDeal(9913, 66013, 'Empresa Sem Ator LTDA');

        $resposta = $this->enviar(9913);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status, 'Ator ausente nao pode virar erro no webhook');

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertNull($company->etapa);
    }

    public function test_hubspot_webhook_user_id_apontando_para_id_inexistente_mesmo_comportamento(): void
    {
        config(['services.hubspot.webhook_user_id' => 999999]);

        $this->fakesDeal(9914, 66014, 'Empresa Ator Inexistente LTDA');

        $resposta = $this->enviar(9914);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status, 'Ator inexistente nao pode virar erro no webhook');

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertNull($company->etapa);
    }

    public function test_reprocessar_evento_sobre_empresa_sem_etapa_transiciona_para_etapa_1(): void
    {
        $porSistema = $this->contaSistema();
        config(['services.hubspot.webhook_user_id' => $porSistema->id]);

        // Empresa legada: já existia (ex.: criada antes desta fase), sem etapa.
        $company = Company::factory()->create([
            'hubspot_company_id' => '66015',
            'etapa'               => null,
        ]);

        $evento = HubspotEvento::create([
            'signature_valid'    => true,
            'portal_id'          => '12345',
            'object_type'        => 'DEAL',
            'object_id'          => '9915',
            'subscription_type'  => 'deal.propertyChange',
            'property_name'      => 'dealstage',
            'property_value'     => 'closedwon',
            'payload'            => $this->eventoPadrao(9915),
            'status'             => 'processado',
            'company_id_criada'  => $company->id,
        ]);

        $this->fakesDeal(9915, 66015, 'Empresa Legada LTDA');

        $resumo = app(HubspotWebhookController::class)->reprocessarEvento($evento->fresh(), app(HubspotApiClient::class));

        $this->assertSame($company->id, $resumo['company_id']);

        $company->refresh();
        $this->assertSame(Company::ETAPA_AGUARDANDO_ADMINISTRATIVO, $company->etapa);
    }
}
