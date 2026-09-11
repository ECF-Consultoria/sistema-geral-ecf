<?php

namespace Tests\Feature\Phase151;

use App\Models\Company;
use App\Models\HubspotEvento;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Fase 151 Plano 04 (COMERC-02, D-08/D-09).
 *
 * Prova que o webhook do HubSpot grava `hubspot_owner_id`, `hubspot_owner_nome`
 * e `data_venda` no MESMO update final de `criarEmpresa()` — atravessado pelos
 * DOIS ramos (criação e match forte via `enriquecerEmpresaExistente()`):
 *
 *  1. Empresa NOVA nascida do webhook tem as três colunas preenchidas.
 *  2. Empresa que JÁ EXISTIA (match forte por `hubspot_company_id`) também
 *     tem as três preenchidas — não só o caminho de criação.
 *  3. Deal sem a property de owner (chave ausente no payload) deixa
 *     `hubspot_owner_id`/`hubspot_owner_nome` em `null`, empresa criada
 *     normalmente, evento `processado` (nunca `erro`).
 *  4. Rota de owners devolvendo 403 (escopo `crm.objects.owners.read`
 *     ausente) deixa `hubspot_owner_nome` em `null` mas mantém
 *     `hubspot_owner_id` preenchido; evento continua `processado`.
 *
 * `data_venda` como `'Y-m-d'` lido de volta como data está coberto dentro do
 * cenário 1 (mesmo padrão do teste de migration do plano 151-03).
 *
 * `Http::fake()` cobre deals/companies/owners — nenhum teste chama a API real
 * (mesmo padrão de `tests/Feature/Phase34HubspotWebhookTest.php`).
 */
class ComercOwnerDataVendaPersistidosTest extends TestCase
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

    public function test_empresa_nova_tem_as_tres_colunas_preenchidas(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9901/associations/companies' => Http::response([
                'results' => [['toObjectId' => 66001]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9901*' => Http::response([
                'id'         => '9901',
                'properties' => [
                    'dealname'         => 'Empresa Nova LTDA',
                    'amount'           => '2000.00',
                    'dealstage'        => 'closedwon',
                    'servico_ecf'      => 'Mentoria Avançada',
                    'hubspot_owner_id' => '778899',
                    'closedate'        => '2026-08-24',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/66001*' => Http::response([
                'id'         => '66001',
                'properties' => [
                    'name'  => 'Empresa Nova LTDA',
                    'cnpj'  => '11222333000144',
                    'email' => 'contato@empresanova.com',
                    'phone' => '11988887777',
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/778899*' => Http::response([
                'id'        => '778899',
                'email'     => 'ana@ecfconsultoria.com.br',
                'firstName' => 'Ana',
                'lastName'  => 'Souza',
            ]),
        ]);

        $resposta = $this->enviar(9901);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status);

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);

        $this->assertSame('778899', $company->hubspot_owner_id);
        $this->assertSame('Ana Souza', $company->hubspot_owner_nome);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->data_venda);
        $this->assertSame('2026-08-24', $company->data_venda->format('Y-m-d'));
    }

    public function test_empresa_existente_no_ramo_de_match_forte_tambem_recebe_as_tres_colunas(): void
    {
        $existente = Company::factory()->create([
            'hubspot_company_id' => '66002',
            'hubspot_owner_id'   => null,
            'hubspot_owner_nome' => null,
            'data_venda'         => null,
        ]);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9902/associations/companies' => Http::response([
                'results' => [['toObjectId' => 66002]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9902*' => Http::response([
                'id'         => '9902',
                'properties' => [
                    'dealname'         => 'Empresa Existente LTDA',
                    'amount'           => '3000.00',
                    'dealstage'        => 'closedwon',
                    'servico_ecf'      => 'Mentoria Avançada',
                    'hubspot_owner_id' => '112233',
                    'closedate'        => '2026-08-30',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/66002*' => Http::response([
                'id'         => '66002',
                'properties' => [
                    'name'  => 'Empresa Existente LTDA',
                    'cnpj'  => null,
                    'email' => null,
                    'phone' => null,
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/112233*' => Http::response([
                'id'        => '112233',
                'email'     => 'bruno@ecfconsultoria.com.br',
                'firstName' => 'Bruno',
                'lastName'  => 'Lima',
            ]),
        ]);

        $resposta = $this->enviar(9902);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status);
        $this->assertSame($existente->id, $evento->company_id_criada, 'Deveria cair no ramo de match forte, não criar empresa nova');

        $existente->refresh();
        $this->assertSame('112233', $existente->hubspot_owner_id);
        $this->assertSame('Bruno Lima', $existente->hubspot_owner_nome);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $existente->data_venda);
        $this->assertSame('2026-08-30', $existente->data_venda->format('Y-m-d'));
    }

    public function test_deal_sem_property_de_owner_deixa_owner_null_e_evento_processado(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9903/associations/companies' => Http::response([
                'results' => [['toObjectId' => 66003]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9903*' => Http::response([
                'id'         => '9903',
                'properties' => [
                    'dealname'    => 'Empresa Sem Owner LTDA',
                    'amount'      => '1000.00',
                    'dealstage'   => 'closedwon',
                    'servico_ecf' => 'Mentoria Avançada',
                    // Sem 'hubspot_owner_id' — chave ausente no payload do deal.
                    'closedate'   => '2026-08-15',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/66003*' => Http::response([
                'id'         => '66003',
                'properties' => [
                    'name'  => 'Empresa Sem Owner LTDA',
                    'cnpj'  => '55666777000188',
                    'email' => 'contato@semowner.com',
                    'phone' => '11977776666',
                ],
            ]),
        ]);

        $resposta = $this->enviar(9903);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status, 'Owner ausente não pode virar erro no webhook');

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertNull($company->hubspot_owner_id);
        $this->assertNull($company->hubspot_owner_nome);
        $this->assertSame('2026-08-15', $company->data_venda->format('Y-m-d'));
    }

    public function test_owner_403_deixa_nome_null_mas_mantem_owner_id_e_evento_processado(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9904/associations/companies' => Http::response([
                'results' => [['toObjectId' => 66004]],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9904*' => Http::response([
                'id'         => '9904',
                'properties' => [
                    'dealname'         => 'Empresa Owner 403 LTDA',
                    'amount'           => '1200.00',
                    'dealstage'        => 'closedwon',
                    'servico_ecf'      => 'Mentoria Avançada',
                    'hubspot_owner_id' => '445566',
                    'closedate'        => '2026-08-20',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/66004*' => Http::response([
                'id'         => '66004',
                'properties' => [
                    'name'  => 'Empresa Owner 403 LTDA',
                    'cnpj'  => '99888777000122',
                    'email' => 'contato@owner403.com',
                    'phone' => '11966665555',
                ],
            ]),
            'api.hubapi.com/crm/v3/owners/445566*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $resposta = $this->enviar(9904);
        $resposta->assertStatus(200);

        $evento = HubspotEvento::first();
        $this->assertNotNull($evento);
        $this->assertSame('processado', $evento->status, '403 no owner não pode virar erro no webhook');

        $company = Company::find($evento->company_id_criada);
        $this->assertNotNull($company);
        $this->assertSame('445566', $company->hubspot_owner_id);
        $this->assertNull($company->hubspot_owner_nome);
    }
}
