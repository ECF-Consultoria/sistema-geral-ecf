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
 * Suite Feature — Phase 114 Plan 03 (HUB-REPLAY-01).
 *
 * Cobre o comando `php artisan hubspot:reprocess-event {id}` (classe
 * `ReprocessHubspotEvent`) que reusa `HubspotWebhookController::
 * reprocessarEvento()` para reprocessar um `HubspotEvento` já recebido.
 *
 * Cenário âncora (efeito prático): um deal chega via webhook com um line
 * item SEM `HubspotLineItemMapping` cadastrado — a empresa é criada mas o
 * contrato correspondente NÃO é (fica como pendência). O admin cadastra o
 * mapping faltante e roda o replay — o contrato passa a existir.
 *
 * Idempotência: rodar o replay 2x não duplica company nem contrato (reusa
 * HubspotCompanyMatcher + guard hubspot_line_item_id).
 *
 * IMPORTANTE: Http::fake em TODOS os cenários — nenhuma chamada real ao
 * HubSpot (T-114-03-SC / regra do projeto).
 */
class Phase114HubspotReplayTest extends TestCase
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

        // RefreshDatabase pode aplicar seeds — isola os cenarios.
        DB::table('hubspot_line_item_mapping')->delete();
        DB::table('contratos_servico')->delete();
        DB::table('servicos')->delete();
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
     * Mocka deal + company + 1 line item ("Serviço X") + ausência de
     * contatos — mesmo shape usado nas fakes de replay (fetches são os
     * MESMOS que o webhook original usa, pois reprocessarEvento refetch).
     */
    private function mockHubspotServicoX(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response([
                'results' => [['toObjectId' => 77001]],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/77001*' => Http::response([
                'id'         => '77001',
                'properties' => [
                    'name'  => 'Cliente Replay LTDA',
                    'cnpj'  => '12312312000112',
                    'email' => 'replay@cliente.com',
                    'phone' => '11999991234',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts' => Http::response([
                'results' => [],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/line_items' => Http::response([
                'results' => [['id' => '31001']],
            ]),
            'api.hubapi.com/crm/v3/objects/line_items/31001*' => Http::response([
                'id'         => '31001',
                'properties' => [
                    'name'                      => 'Serviço X',
                    'price'                     => '1200',
                    'recurringbillingfrequency' => 'monthly',
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => [
                    'dealname'  => 'Cliente Replay',
                    'dealstage' => 'closedwon',
                ],
            ]),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Efeito prático: mapping cadastrado depois → replay cria o contrato
    //
    //  Rastreabilidade Fase 115 Plano 02 — SC4 (HUB-TEST-04) → método:
    //  test_replay_cria_contrato_faltante_apos_mapping_cadastrado
    // ══════════════════════════════════════════════════════════════════

    public function test_replay_cria_contrato_faltante_apos_mapping_cadastrado(): void
    {
        $this->mockHubspotServicoX();

        // 1) Webhook original: line item "Serviço X" SEM mapping — empresa
        // criada, contrato NAO criado (pendência).
        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $this->assertSame(1, Company::count());
        $this->assertSame(0, ContratoServico::count(), 'Sem mapping, nenhum contrato deve existir ainda');

        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
        $this->assertNotNull($evento->company_id_criada);

        $payload = $evento->payload;
        $this->assertArrayHasKey('line_items_nao_mapeados', $payload);

        // 2) Admin cadastra o Servico + HubspotLineItemMapping faltante.
        $servico = Servico::create([
            'nome'          => 'Serviço X',
            'valor_padrao'  => 1200,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        HubspotLineItemMapping::create([
            'line_item_name' => 'Serviço X',
            'servico_id'     => $servico->id,
            'ativo'          => true,
        ]);

        // 3) Roda o replay — deve refetch e materializar o contrato faltante.
        $this->artisan('hubspot:reprocess-event', ['id' => $evento->id])
            ->assertExitCode(0);

        $this->assertSame(1, Company::count(), 'Replay nao deve duplicar company');
        $this->assertSame(1, ContratoServico::count(), 'Replay deve criar o contrato faltante');

        $contrato = ContratoServico::first();
        $this->assertSame($servico->id, $contrato->servico_id);
        $this->assertSame($evento->company_id_criada, $contrato->company_id);

        $evento->refresh();
        $this->assertSame('processado', $evento->status);

        // Reforço: a pendência foi MATERIALIZADA (não só o contrato existe) —
        // "Serviço X" não pode mais aparecer em line_items_nao_mapeados após
        // o replay, senão o efeito prático da pendência não sumiu de verdade.
        $naoMapeadosApos = $evento->payload['line_items_nao_mapeados'] ?? [];
        $nomesNaoMapeados = array_column($naoMapeadosApos, 'name');
        $this->assertNotContains(
            'Serviço X',
            $nomesNaoMapeados,
            'Apos o replay materializar o contrato, a pendencia nao pode mais constar em line_items_nao_mapeados'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  Idempotência: 2 execuções do replay não duplicam nada
    // ══════════════════════════════════════════════════════════════════

    public function test_replay_e_idempotente_rodando_2x(): void
    {
        $this->mockHubspotServicoX();

        $resp = $this->dispararWebhook([$this->eventoPadrao()]);
        $resp->assertStatus(200);

        $evento = HubspotEvento::first();

        $servico = Servico::create([
            'nome'          => 'Serviço X',
            'valor_padrao'  => 1200,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        HubspotLineItemMapping::create([
            'line_item_name' => 'Serviço X',
            'servico_id'     => $servico->id,
            'ativo'          => true,
        ]);

        // 1a execucao do replay — cria o contrato faltante.
        $this->artisan('hubspot:reprocess-event', ['id' => $evento->id])
            ->assertExitCode(0);

        $companyCountApos1a  = Company::count();
        $contratoCountApos1a = ContratoServico::count();
        $this->assertSame(1, $companyCountApos1a);
        $this->assertSame(1, $contratoCountApos1a);

        // 2a execucao do replay — NAO deve duplicar nada.
        $this->artisan('hubspot:reprocess-event', ['id' => $evento->id])
            ->assertExitCode(0);

        $this->assertSame($companyCountApos1a, Company::count(), 'Replay 2x nao pode duplicar company');
        $this->assertSame($contratoCountApos1a, ContratoServico::count(), 'Replay 2x nao pode duplicar contrato');
    }

    // ══════════════════════════════════════════════════════════════════
    //  Comando: id inexistente retorna erro amigavel
    // ══════════════════════════════════════════════════════════════════

    public function test_comando_com_evento_inexistente_falha_amigavel(): void
    {
        $this->artisan('hubspot:reprocess-event', ['id' => 999999])
            ->assertExitCode(1);
    }
}
