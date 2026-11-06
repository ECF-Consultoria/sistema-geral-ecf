<?php

namespace Tests\Feature\Phase202;

use App\Http\Controllers\Api\HubspotWebhookController;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoRenovacao;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use App\Services\HubspotApiClient;
use App\Services\Renovacao\RenovacaoDetector;
use App\Services\Renovacao\RenovacaoWebhookHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 202 (REN-05) — webhook HubSpot: renovação fora do fluxo de venda.
 */
class RenovacaoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    private const FECHADO    = '1480135341';
    private const NEGOCIANDO = '1480135342';
    private const INICIAL    = '1480135340';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01');

        config([
            'services.hubspot.client_secret'             => self::SECRET,
            'services.hubspot.access_token'              => 'token-fake',
            // CSV contaminado de propósito: o ID de renovação está no Fechado Ganho.
            'services.hubspot.stage_fechado_ganho_id'    => self::FECHADO . ',closedwon',
            'services.hubspot.renovacao.stage_fechado_id'    => self::FECHADO,
            'services.hubspot.renovacao.stage_negociando_id' => self::NEGOCIANDO,
            'services.hubspot.renovacao.stage_inicial_id'    => self::INICIAL,
            'services.hubspot.renovacao.stages_perdido_ids'  => ['999'],
            'services.hubspot.renovacao.propriedade_chave'   => 'ecf_renovacao_chave',
            'services.hubspot.props.deal'    => ['servico' => 'servico_ecf'],
            'services.hubspot.props.company' => ['name' => 'name', 'cnpj' => 'cnpj', 'email' => 'email', 'phone' => 'phone'],
            'services.hubspot.props.contact' => ['firstname' => 'firstname', 'lastname' => 'lastname', 'email' => 'email', 'phone' => 'phone'],
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function servico(): Servico
    {
        return Servico::create([
            'nome' => 'Gestao', 'valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo' => true, 'setor' => Servico::SETOR_PERFORMANCE, 'exige_contrato' => true,
        ]);
    }

    /** @return array{0: Company, 1: ContratoServico, 2: ContratoRenovacao} */
    private function cenario(?string $negocioId = 'D-REN-1'): array
    {
        $c  = Company::factory()->create(['active' => true]);
        $s  = $this->servico();
        $cs = ContratoServico::factory()->create([
            'company_id' => $c->id, 'servico_id' => $s->id,
            'data_contratacao' => '2026-01-10', 'data_vencimento' => '2027-01-10',
            'hubspot_billing_period' => 'P12M',
        ]);
        $ciclo = app(RenovacaoDetector::class)->garantirCiclo($c, $s->id);
        if ($negocioId) {
            $ciclo->update(['negocio_renovacao_id' => $negocioId]);
        }

        return [$c, $cs, $ciclo->fresh()];
    }

    private function evento(string $dealId, string $stage): HubspotEvento
    {
        return HubspotEvento::create([
            'signature_valid' => true, 'portal_id' => '1', 'object_type' => 'DEAL',
            'object_id' => $dealId, 'subscription_type' => 'deal.propertyChange',
            'property_name' => 'dealstage', 'property_value' => $stage,
            'payload' => [], 'status' => 'recebido',
        ]);
    }

    private function handler(): RenovacaoWebhookHandler
    {
        return app(RenovacaoWebhookHandler::class);
    }

    private function api(): HubspotApiClient
    {
        return new HubspotApiClient('token-fake');
    }

    private function disparar(array $eventos)
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);
        $sig  = base64_encode(hash_hmac('sha256', 'POST' . url(self::URL) . $body . $ts, self::SECRET, true));

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE'                       => 'application/json',
            'HTTP_X_HUBSPOT_SIGNATURE_V3'        => $sig,
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP'   => $ts,
        ], $body);
    }

    private function eventoHttp(string $dealId, string $stage): array
    {
        return [
            'portalId' => 12345, 'objectType' => 'DEAL', 'objectId' => (int) $dealId,
            'subscriptionType' => 'deal.propertyChange', 'propertyName' => 'dealstage', 'propertyValue' => $stage,
        ];
    }

    // ─── Handler isolado ─────────────────────────────────────────────────────

    public function test_etapas_de_renovacao(): void
    {
        $h = $this->handler();
        $this->assertTrue($h->ehEtapaDeRenovacao(self::FECHADO));
        $this->assertTrue($h->ehEtapaDeRenovacao(self::INICIAL));
        $this->assertTrue($h->ehEtapaDeRenovacao(self::NEGOCIANDO));
        $this->assertTrue($h->ehEtapaDeRenovacao('999'));
        $this->assertFalse($h->ehEtapaDeRenovacao('closedwon'));
        $this->assertFalse($h->ehEtapaDeRenovacao(null));
        $this->assertFalse($h->ehEtapaDeRenovacao(''));
    }

    public function test_ganho_estende_e_nao_cria_empresa(): void
    {
        [, $cs, $ciclo] = $this->cenario();
        $evento = $this->evento('D-REN-1', self::FECHADO);

        $this->handler()->tratar($evento, $this->api());

        $this->assertSame('processado', $evento->fresh()->status);
        $this->assertNull($evento->fresh()->company_id_criada);
        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('renovado', $ciclo->fresh()->status_comercial);
        $this->assertSame(self::FECHADO, $ciclo->fresh()->etapa_observada);
    }

    public function test_termino_corrigido_depois_da_deteccao_parte_do_novo_termino(): void
    {
        [, $cs] = $this->cenario();
        $cs->gravarTermino(Carbon::parse('2027-01-20'), ContratoServico::ORIGEM_TERMINO_MANUAL);

        $this->handler()->tratar($this->evento('D-REN-1', self::FECHADO), $this->api());

        $this->assertSame('2028-01-20', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_ganho_estende_com_automacao_desligada(): void
    {
        Configuracao::set('renovacao_automatica_ativa', '0');
        [, $cs] = $this->cenario();

        $this->handler()->tratar($this->evento('D-REN-1', self::FECHADO), $this->api());

        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_acha_ciclo_pela_chave_de_correlacao_e_vincula(): void
    {
        [, $cs, $ciclo] = $this->cenario(null);
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/777*' => Http::response([
                'id' => '777', 'properties' => ['ecf_renovacao_chave' => $ciclo->chave_correlacao],
            ]),
        ]);

        $evento = $this->evento('777', self::FECHADO);
        $this->handler()->tratar($evento, $this->api());

        $this->assertSame('processado', $evento->fresh()->status);
        $this->assertSame('777', $ciclo->fresh()->negocio_renovacao_id);
        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_sem_ciclo_vira_ignorado_com_mensagem(): void
    {
        $this->cenario();
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/555*' => Http::response(['id' => '555', 'properties' => []]),
        ]);

        $evento = $this->evento('555', self::FECHADO);
        $this->handler()->tratar($evento, $this->api());

        $this->assertSame('ignorado', $evento->fresh()->status);
        $this->assertSame('Negócio de renovação sem ciclo no ECF', $evento->fresh()->erro_msg);
    }

    public function test_negociando_marca_em_negociacao_e_nao_regride_renovado(): void
    {
        [, , $ciclo] = $this->cenario();

        $this->handler()->tratar($this->evento('D-REN-1', self::NEGOCIANDO), $this->api());
        $this->assertSame('em_negociacao', $ciclo->fresh()->status_comercial);

        $this->handler()->tratar($this->evento('D-REN-1', self::FECHADO), $this->api());
        $this->assertSame('renovado', $ciclo->fresh()->status_comercial);

        // Evento atrasado de "negociando" depois do ganho.
        $this->handler()->tratar($this->evento('D-REN-1', self::NEGOCIANDO), $this->api());
        $this->assertSame('renovado', $ciclo->fresh()->status_comercial);
    }

    public function test_perdido_marca_nao_renovado(): void
    {
        [, , $ciclo] = $this->cenario();

        $this->handler()->tratar($this->evento('D-REN-1', '999'), $this->api());

        $this->assertSame('nao_renovado', $ciclo->fresh()->status_comercial);
    }

    // ─── Ponta a ponta (POST assinado) ───────────────────────────────────────

    public function test_post_de_renovacao_nao_cria_empresa_nem_contrato_e_nao_estende_duas_vezes(): void
    {
        [, $cs] = $this->cenario('4001');
        $empresas  = Company::count();
        $contratos = ContratoServico::count();

        $this->disparar([$this->eventoHttp('4001', self::FECHADO)])->assertStatus(200);

        $this->assertSame($empresas, Company::count());
        $this->assertSame($contratos, ContratoServico::count());
        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame('processado', HubspotEvento::latest('id')->first()->status);

        // (b) mesmo evento reenviado: não estende de novo.
        $this->disparar([$this->eventoHttp('4001', self::FECHADO)])->assertStatus(200);

        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
        $this->assertSame($empresas, Company::count());
        $this->assertSame($contratos, ContratoServico::count());
    }

    public function test_post_de_venda_normal_continua_criando_empresa_com_termino(): void
    {
        DB::table('hubspot_line_item_mapping')->delete();
        $servico = $this->servico();
        HubspotLineItemMapping::create(['line_item_name' => 'MAP', 'servico_id' => $servico->id, 'ativo' => true]);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/8800/associations/companies' => Http::response(['results' => [['id' => 66001]]]),
            'api.hubapi.com/crm/v3/objects/deals/8800/associations/contacts'  => Http::response(['results' => []]),
            'api.hubapi.com/crm/v3/objects/deals/8800/associations/line_items' => Http::response(['results' => [['id' => '11001']]]),
            'api.hubapi.com/crm/v3/objects/line_items/11001*' => Http::response([
                'id' => '11001',
                'properties' => ['name' => 'MAP', 'price' => '3000', 'recurringbillingfrequency' => 'monthly', 'hs_recurring_billing_period' => 'P12M'],
            ]),
            'api.hubapi.com/crm/v3/objects/companies/66001*' => Http::response([
                'id' => '66001',
                'properties' => ['name' => 'Cliente Venda LTDA', 'cnpj' => '11111111000101', 'email' => 'v@c.com', 'phone' => '11999990101'],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/8800*' => Http::response([
                'id' => '8800', 'properties' => ['dealname' => 'Cliente Venda', 'dealstage' => 'closedwon'],
            ]),
            'api.hubapi.com/*' => Http::response(['results' => []]),
        ]);

        $this->disparar([$this->eventoHttp('8800', 'closedwon')])->assertStatus(200);

        $this->assertSame(1, Company::where('name', 'like', '%Cliente Venda%')->count());
        $contrato = ContratoServico::first();
        $this->assertNotNull($contrato, 'contrato deve ser criado pelo fluxo de venda');
        $this->assertNotNull($contrato->data_vencimento, 'término calculado na entrada (REN-01)');
        $this->assertNotNull($contrato->data_vencimento_origem);
    }

    public function test_replay_de_evento_de_renovacao_nao_cria_empresa(): void
    {
        [, $cs] = $this->cenario();
        $empresas = Company::count();
        $evento   = $this->evento('D-REN-1', self::FECHADO);

        $resumo = app(HubspotWebhookController::class)->reprocessarEvento($evento, $this->api());

        $this->assertNull($resumo['company_id']);
        $this->assertSame(0, $resumo['contratos_criados']);
        $this->assertSame($empresas, Company::count());
        $this->assertSame('2028-01-10', $cs->fresh()->data_vencimento->toDateString());
    }

    public function test_replay_de_negocio_com_chave_de_correlacao_vira_renovacao(): void
    {
        [, , $ciclo] = $this->cenario(null);
        $empresas = Company::count();
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/321*' => Http::response([
                'id' => '321', 'properties' => ['dealstage' => 'closedwon', 'ecf_renovacao_chave' => $ciclo->chave_correlacao],
            ]),
        ]);

        // Etapa de venda (closedwon) mas com a property de correlação: é renovação.
        $evento = $this->evento('321', 'closedwon');
        $resumo = app(HubspotWebhookController::class)->reprocessarEvento($evento, $this->api());

        $this->assertNull($resumo['company_id']);
        $this->assertSame($empresas, Company::count());
        $this->assertSame('321', $ciclo->fresh()->negocio_renovacao_id);
    }
}
