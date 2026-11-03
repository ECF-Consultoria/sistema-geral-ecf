<?php

namespace Tests\Feature;

use App\Jobs\AvancarDealHubspotJob;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\HubspotEvento;
use App\Services\HubspotApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quick 261103-hsf (DEV-55) — novo fluxo do Pipeline Consultoria.
 *
 *  - "Contrato Pendente" cria a empresa (webhook de entrada);
 *  - contrato enviado → deal vai para "Contrato Enviado";
 *  - contrato assinado → deal vai para "Ganho";
 *  - só avança, só no Pipeline Consultoria, chave de desligar.
 */
class Quick261103HubspotPipelineConsultoriaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'                => self::SECRET,
            'services.hubspot.access_token'                 => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id'       => '1352209026,closedwon',
            'services.hubspot.pipeline_consultoria_id'      => 'default',
            'services.hubspot.stage_contrato_pendente_id'   => '1470731063',
            'services.hubspot.stage_contrato_enviado_id'    => 'contractsent',
            'services.hubspot.stage_ganho_consultoria_id'   => 'closedwon',
            'services.hubspot.stage_perdido_consultoria_id' => 'closedlost',
            'services.hubspot.avancar_deal_ativo'           => true,
        ]);
    }

    // ─── Observer ────────────────────────────────────────────────────────────

    public function test_contrato_enviado_e_assinado_despacham_o_job_com_o_destino_certo(): void
    {
        Queue::fake();

        $contrato = ContratoAssinatura::factory()->create();

        $contrato->update(['status' => ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS]);
        $contrato->update(['status' => ContratoAssinatura::STATUS_ASSINADO]);

        Queue::assertPushed(AvancarDealHubspotJob::class, 2);
        Queue::assertPushed(AvancarDealHubspotJob::class, fn ($job) => $job->destino === AvancarDealHubspotJob::DESTINO_CONTRATO_ENVIADO
            && $job->companyId === $contrato->company_id
            && $job->queue === 'high');
        Queue::assertPushed(AvancarDealHubspotJob::class, fn ($job) => $job->destino === AvancarDealHubspotJob::DESTINO_GANHO);
    }

    public function test_outros_status_e_salvar_sem_mudar_status_nao_despacham(): void
    {
        Queue::fake();

        $contrato = ContratoAssinatura::factory()->create();
        $contrato->update(['status' => ContratoAssinatura::STATUS_CANCELADO]);
        $contrato->update(['erro_mensagem' => 'qualquer coisa']);

        Queue::assertNotPushed(AvancarDealHubspotJob::class);
    }

    // ─── Job ─────────────────────────────────────────────────────────────────

    private function fakeDeal(string $dealId, string $pipeline, string $stage): void
    {
        Http::fake([
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}*" => function (Request $req) use ($dealId, $pipeline, $stage) {
                if ($req->method() === 'PATCH') {
                    return Http::response(['id' => $dealId]);
                }

                return Http::response(['id' => $dealId, 'properties' => ['pipeline' => $pipeline, 'dealstage' => $stage]]);
            },
        ]);
    }

    private function rodar(int $companyId, string $destino): void
    {
        (new AvancarDealHubspotJob($companyId, $destino))->handle(new HubspotApiClient('token-fake'));
    }

    private function patchesEnviados(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => $par[0]->method() === 'PATCH')
            ->map(fn ($par) => [$par[0]->url(), $par[0]->data()['properties']['dealstage'] ?? null])
            ->values()
            ->all();
    }

    public function test_contrato_pendente_vai_para_contrato_enviado(): void
    {
        $company = Company::factory()->create(['hubspot_deal_id' => '111']);
        $this->fakeDeal('111', 'default', '1470731063');

        $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_CONTRATO_ENVIADO);

        $this->assertSame([['https://api.hubapi.com/crm/v3/objects/deals/111', 'contractsent']], $this->patchesEnviados());
    }

    public function test_contrato_enviado_vai_para_ganho(): void
    {
        $company = Company::factory()->create(['hubspot_deal_id' => '111']);
        $this->fakeDeal('111', 'default', 'contractsent');

        $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_GANHO);

        $this->assertSame([['https://api.hubapi.com/crm/v3/objects/deals/111', 'closedwon']], $this->patchesEnviados());
    }

    public function test_nunca_volta_nem_tira_de_ganho_ou_perdido(): void
    {
        $company = Company::factory()->create(['hubspot_deal_id' => '111']);

        foreach (['closedwon', 'closedlost', 'contractsent'] as $atual) {
            $this->fakeDeal('111', 'default', $atual);
            $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_CONTRATO_ENVIADO);
        }
        foreach (['closedwon', 'closedlost'] as $atual) {
            $this->fakeDeal('111', 'default', $atual);
            $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_GANHO);
        }

        $this->assertSame([], $this->patchesEnviados());
    }

    public function test_deal_de_outro_pipeline_nao_e_mexido(): void
    {
        $company = Company::factory()->create(['hubspot_deal_id' => '222']);
        $this->fakeDeal('222', '894770343', '1352209020');

        $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_GANHO);

        $this->assertSame([], $this->patchesEnviados());
    }

    public function test_usa_o_deal_do_ultimo_evento_processado_e_nao_o_da_coluna(): void
    {
        // Cliente antigo: a coluna guarda o deal VELHO ("só preenche se vazio").
        $company = Company::factory()->create(['hubspot_deal_id' => 'velho']);
        HubspotEvento::create(['signature_valid' => true, 'object_id' => 'velho', 'status' => 'processado', 'company_id_criada' => $company->id, 'payload' => []]);
        HubspotEvento::create(['signature_valid' => true, 'object_id' => '333', 'status' => 'processado', 'company_id_criada' => $company->id, 'payload' => []]);
        $this->fakeDeal('333', 'default', '1470731063');

        $this->rodar($company->id, AvancarDealHubspotJob::DESTINO_CONTRATO_ENVIADO);

        $this->assertSame([['https://api.hubapi.com/crm/v3/objects/deals/333', 'contractsent']], $this->patchesEnviados());
    }

    public function test_sem_deal_ou_desligado_nao_chama_o_hubspot(): void
    {
        Http::fake();
        $semDeal = Company::factory()->create(['hubspot_deal_id' => null]);
        $this->rodar($semDeal->id, AvancarDealHubspotJob::DESTINO_GANHO);

        config(['services.hubspot.avancar_deal_ativo' => false]);
        $comDeal = Company::factory()->create(['hubspot_deal_id' => '111']);
        $this->rodar($comDeal->id, AvancarDealHubspotJob::DESTINO_GANHO);

        Http::assertNothingSent();
    }

    // ─── Webhook de entrada ──────────────────────────────────────────────────

    private function postarEvento(string $stage, int $dealId = 9876): void
    {
        $body = json_encode([[
            'portalId'         => 12345,
            'objectType'       => 'DEAL',
            'objectId'         => $dealId,
            'subscriptionType' => 'deal.propertyChange',
            'propertyName'     => 'dealstage',
            'propertyValue'    => $stage,
        ]]);
        $ts  = (string) (int) (microtime(true) * 1000);
        $sig = base64_encode(hash_hmac('sha256', 'POST' . url(self::URL) . $body . $ts, self::SECRET, true));

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE'                     => 'application/json',
            'HTTP_X_HUBSPOT_SIGNATURE_V3'      => $sig,
            'HTTP_X_HUBSPOT_REQUEST_TIMESTAMP' => $ts,
        ], $body)->assertStatus(200);
    }

    public function test_contrato_pendente_cria_a_empresa_e_o_ganho_depois_nao_duplica(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876*' => Http::response([
                'id'         => '9876',
                'properties' => ['dealname' => 'Cliente Pendente LTDA', 'dealstage' => '1470731063', 'pipeline' => 'default'],
            ]),
            '*' => Http::response(['results' => []]),
        ]);

        $this->postarEvento('1470731063');

        $this->assertSame(1, Company::count());
        $evento = HubspotEvento::first();
        $this->assertSame('processado', $evento->status);
        $this->assertNotNull($evento->company_id_criada);

        // O próprio sistema move o deal para Ganho ao assinar → evento volta.
        $this->postarEvento('closedwon');

        $this->assertSame(1, Company::count(), 'Ganho depois de Contrato Pendente não pode criar outra empresa');
        $this->assertSame('ignorado', HubspotEvento::orderByDesc('id')->first()->status);
    }

    public function test_contrato_enviado_vindo_do_hubspot_nao_cria_empresa(): void
    {
        Http::fake();

        $this->postarEvento('contractsent');

        $this->assertSame(0, Company::count());
        $this->assertSame('ignorado', HubspotEvento::first()->status);
    }
}
