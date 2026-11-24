<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\Servico;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Virada do Sistema ECF V4 (24/11/2026) — cliente novo do Pipeline
 * Consultoria entra pelo V4. O Admin deixa de criar empresa a partir desse
 * pipeline e não gera contrato para as empresas que o V4 assumiu; Polos e
 * os demais pipelines seguem iguais.
 */
class ViradaV4ConsultoriaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hubspot.client_secret'              => self::SECRET,
            'services.hubspot.access_token'               => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id'     => '1352209026,closedwon',
            'services.hubspot.pipeline_consultoria_id'    => 'default',
            'services.hubspot.stage_contrato_pendente_id' => '1470731063',
            'services.hubspot.consultoria_entrada_v4'     => true,
        ]);
    }

    private function postarEvento(string $stage, int $dealId): void
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

    private function fakeDeal(int $dealId, string $pipeline, string $stage): void
    {
        Http::fake([
            "api.hubapi.com/crm/v3/objects/deals/{$dealId}*" => Http::response([
                'id'         => (string) $dealId,
                'properties' => ['dealname' => "Cliente {$dealId} LTDA", 'dealstage' => $stage, 'pipeline' => $pipeline],
            ]),
            '*' => Http::response(['results' => []]),
        ]);
    }

    public function test_contrato_pendente_da_consultoria_nao_cria_empresa_com_a_chave_ligada(): void
    {
        $this->fakeDeal(5001, 'default', '1470731063');

        $this->postarEvento('1470731063', 5001);

        $this->assertSame(0, Company::count());
        $evento = HubspotEvento::first();
        $this->assertSame('ignorado', $evento->status);
        $this->assertStringContainsString('V4', (string) $evento->erro_msg);
    }

    public function test_ganho_da_consultoria_tambem_nao_cria_empresa(): void
    {
        $this->fakeDeal(5002, 'default', 'closedwon');

        $this->postarEvento('closedwon', 5002);

        $this->assertSame(0, Company::count());
        $this->assertSame('ignorado', HubspotEvento::first()->status);
    }

    public function test_ganho_do_polos_continua_criando_empresa(): void
    {
        $this->fakeDeal(5003, '894770343', '1352209026');

        $this->postarEvento('1352209026', 5003);

        $this->assertSame(1, Company::count());
        $this->assertSame('processado', HubspotEvento::first()->status);
    }

    public function test_chave_desligada_mantem_a_entrada_da_consultoria(): void
    {
        config(['services.hubspot.consultoria_entrada_v4' => false]);
        $this->fakeDeal(5004, 'default', '1470731063');

        $this->postarEvento('1470731063', 5004);

        $this->assertSame(1, Company::count());
    }

    public function test_deal_que_ja_virou_empresa_no_admin_segue_com_o_admin(): void
    {
        // Empresa nasceu ANTES da virada; o Ganho que chega depois cai na
        // idempotência (ignorado, sem empresa nova) — nada muda para ela.
        config(['services.hubspot.consultoria_entrada_v4' => false]);
        $this->fakeDeal(5005, 'default', '1470731063');
        $this->postarEvento('1470731063', 5005);
        $this->assertSame(1, Company::count());

        config(['services.hubspot.consultoria_entrada_v4' => true]);
        $this->postarEvento('closedwon', 5005);

        $this->assertSame(1, Company::count());
        $this->assertStringContainsString('idempotencia', (string) HubspotEvento::orderByDesc('id')->first()->erro_msg);
    }

    // ─── Empresas que o V4 assumiu: contrato bloqueado ───────────────────────

    private function empresaElegivel(): Company
    {
        $company = Company::factory()->create([
            'email_cliente' => 'cliente@empresa.com.br',
            'cnpj'          => '12.345.678/0001-95',
            'nome_contato'  => 'Fulano de Tal',
            'razao_social'  => 'Fulano de Tal LTDA',
            'endereco'      => 'Rua de Teste, 123',
            'bairro'        => 'Bairro de Teste',
            'cidade'        => 'Cidade de Teste',
            'estado'        => 'TS',
            'cep'           => '00000-000',
        ]);
        $servico = Servico::create([
            'nome'           => 'Gestão V4 ' . uniqid(),
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
        ContratoServico::withoutEvents(fn () => ContratoServico::create([
            'company_id'            => $company->id,
            'servico_id'            => $servico->id,
            'valor_contratado'      => 150.5,
            'data_contratacao'      => '2026-01-10',
            'data_vencimento'       => '2027-01-10',
            'ativo'                 => true,
            'data_primeira_parcela' => '2026-02-05',
            'dia_vencimento'        => 5,
        ]));

        return $company;
    }

    public function test_empresa_assumida_pelo_v4_nao_gera_contrato_por_caminho_nenhum(): void
    {
        Queue::fake();
        Http::fake();
        $company = $this->empresaElegivel();
        $gatilho = app(GatilhoContratoAdministrativoService::class);

        $this->assertSame('elegivel', $gatilho->avaliar($company)['status'], 'pré-condição: sem a lista a empresa é elegível');

        config(['services.hubspot.consultoria_v4_empresas' => [$company->id]]);

        $this->assertSame('consultoria_v4', $gatilho->avaliar($company)['status']);
        $this->assertSame('consultoria_v4', $gatilho->dispararSeElegivel($company)['status']);
        $this->assertSame(0, ContratoAssinatura::count());
    }
}
