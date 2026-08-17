<?php

namespace Tests\Feature\Phase128;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoAssinatura;
use App\Models\MlbEmpresa;
use App\Models\Servico;
use App\Models\User;
use App\Services\Contratos\GatilhoContratoAdministrativoService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 128 Plano 04 — ESTA SUÍTE É O GATE 2 DA FASE (`<gates_desta_fase>` do
 * CONTEXT): prova o invariante mais importante da fase — a empresa continua
 * indo para o operacional na mesma hora, independentemente do que o gate
 * administrativo decida, incluindo o caso de o gate EXPLODIR.
 *
 * Reusa a fixture dos testes-molde da Fase 124
 * (`Phase124KillSwitchTest`/`Phase124RegressaoComercialTest`) — copiada aqui
 * (teste-irmão), não editada nos arquivos originais.
 *
 * O cenário mais importante é `test_falha_do_gate_nao_desfaz_o_cadastro_*`:
 * como `GatilhoContratoAdministrativoService::dispararSeElegivel()` não
 * lança em produção (plano 03), a ÚNICA forma de provar que a chamada está
 * FORA da `DB::transaction()` é mockar o service para lançar de propósito.
 * Se este teste falhar, o gate está dentro da transação errada — o "sinal
 * de alerta" do Pitfall 1 da pesquisa da fase.
 */
class InvarianteRoteamentoTest extends TestCase
{
    use RefreshDatabase;

    private const HUBSPOT_SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const HUBSPOT_URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        // 'Assessoria' dispara MlbEmpresa (tipo ASSESSORIA) via
        // EmpresaOperacionalRouter E exige contrato por padrão (só 'Polos' é
        // isento, plano 128-01) — serviço certo para provar os dois portões
        // (operacional + administrativo) na mesma empresa.
        Servico::firstOrCreate(
            ['nome' => 'Assessoria'],
            [
                'valor_padrao'   => 500,
                'tipo_cobranca'  => Servico::TIPO_MENSAL,
                'ativo'          => true,
                'setor'          => Servico::SETOR_PUBLICACAO,
                'exige_contrato' => true,
            ],
        );

        // Config previsível p/ o webhook HubSpot — mesma convenção de
        // Phase124KillSwitchTest::setUp().
        config([
            'services.hubspot.client_secret'          => self::HUBSPOT_SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => '1352209026,closedwon',
            'services.hubspot.props.deal'             => ['servico' => 'servico_ecf'],
            'services.hubspot.props.company'          => [
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

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoAssessoria(): Servico
    {
        return Servico::where('nome', 'Assessoria')->firstOrFail();
    }

    private function cadastrarPeloComercial(string $nome): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userAdmin())->post('/comercial/empresas', [
            'nome'     => $nome,
            // Sem nome_contato: ComercialController::store() nunca persiste
            // esse campo (é ignorado por design, ver docblock de update()) —
            // toda empresa cadastrada pelo Comercial nasce com nome_contato
            // vazio, que é exatamente a pendência de propósito (sem_contato)
            // que este cenário precisa.
            'servicos' => [
                ['servico_id' => $this->servicoAssessoria()->id],
            ],
        ]);
    }

    // ─── Cópia da fixture de webhook da Fase 124 (teste-irmão) ─────────────

    private function assinaturaHubspot(string $body, string $ts): string
    {
        $url           = url(self::HUBSPOT_URL);
        $methodUriBody = 'POST' . $url . $body . $ts;
        return base64_encode(hash_hmac('sha256', $methodUriBody, self::HUBSPOT_SECRET, true));
    }

    private function servidorHubspot(array $headers): array
    {
        $out = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $key                 = strtoupper(str_replace('-', '_', $name));
            $out['HTTP_' . $key] = $value;
        }
        return $out;
    }

    private function eventoHubspotPadrao(array $overrides = []): array
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

    private function disparaWebhookHubspot(array $eventos): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($eventos);
        $ts   = (string) (int) (microtime(true) * 1000);

        return $this->call('POST', self::HUBSPOT_URL, [], [], [], $this->servidorHubspot([
            'X-HubSpot-Signature-v3'      => $this->assinaturaHubspot($body, $ts),
            'X-HubSpot-Request-Timestamp' => $ts,
        ]), $body);
    }

    private function mockaHubSpot(array $dealProps): void
    {
        $deal = array_merge([
            'dealname'    => 'Cliente Teste Invariante Roteamento',
            'amount'      => '1500.00',
            'dealstage'   => 'closedwon',
            'servico_ecf' => 'Assessoria',
        ], $dealProps);

        Http::fake([
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/companies' => Http::response(['results' => []]),
            'api.hubapi.com/crm/v3/objects/deals/9876/associations/contacts'  => Http::response(['results' => []]),
            'api.hubapi.com/crm/v3/objects/deals/9876*'                       => Http::response([
                'id'         => '9876',
                'properties' => $deal,
            ]),
        ]);
    }

    // ─── Cenário 1 — Comercial, pendência de propósito ─────────────────────

    /**
     * Cadastro pelo Comercial com serviço que exige contrato E pendência de
     * propósito (nome_contato vazio): o roteamento operacional acontece
     * (MlbEmpresa nasce) mas nenhum ContratoAssinatura é gerado — o gate
     * administrativo bloqueia no 1º portão (aguardando_comercial), o
     * roteamento nem sabe que o gate existe.
     */
    public function test_cadastro_comercial_com_pendencia_roteia_mas_nao_gera_contrato(): void
    {
        $response = $this->cadastrarPeloComercial('Empresa Invariante Comercial');

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Invariante Comercial')->firstOrFail();

        $this->assertTrue(
            MlbEmpresa::where('company_id', $company->id)->exists(),
            'O roteamento operacional não pode parar por causa do gate administrativo',
        );
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    // ─── Cenário 2 — mesmo cenário pelo webhook HubSpot ─────────────────────

    /**
     * Mesmo cenário do teste acima, pelo caminho do webhook HubSpot (sem
     * contato associado ao deal → nome_contato vazio → mesma pendência
     * sem_contato). Prova que os dois pontos de entrada convergem no mesmo
     * comportamento (SC2), inclusive quando o gate NÃO dispara.
     */
    public function test_cadastro_via_webhook_hubspot_com_pendencia_roteia_mas_nao_gera_contrato(): void
    {
        $this->mockaHubSpot([]);

        $r = $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $r->assertStatus(200);

        $company = Company::where('name', 'Cliente Teste Invariante Roteamento')->firstOrFail();

        $this->assertTrue(
            MlbEmpresa::where('company_id', $company->id)->exists(),
            'O roteamento operacional não pode parar por causa do gate administrativo',
        );
        $this->assertSame(0, ContratoAssinatura::where('company_id', $company->id)->count());
    }

    // ─── Cenário 3 — gate explode, cadastro e roteamento sobrevivem ────────

    /**
     * O cenário mais importante desta suíte. Com o gate mockado no
     * container lançando \RuntimeException, o cadastro pelo Comercial
     * continua de pé: Company e MlbEmpresa sobrevivem à requisição — a
     * chamada ao gate está FORA da DB::transaction() que os criou, então
     * uma exceção ali não desfaz nada. Como dispararSeElegivel() nunca
     * lança de verdade em produção (plano 03), este mock é a ÚNICA forma
     * de provar o posicionamento correto — não é um teste "mais fácil"
     * alternativo, é o único capaz de provar isto.
     */
    public function test_falha_do_gate_nao_desfaz_o_cadastro_comercial_nem_o_roteamento(): void
    {
        $mock = \Mockery::mock(GatilhoContratoAdministrativoService::class);
        $mock->shouldReceive('dispararSeElegivel')->andThrow(new \RuntimeException('falha simulada do gate'));
        $this->app->instance(GatilhoContratoAdministrativoService::class, $mock);

        // Sem try/catch no controller (de propósito, plano 04): a exceção
        // propaga até o handler padrão do Laravel, que converte em 500 —
        // mas isso acontece DEPOIS de Company/ContratoServico/MlbEmpresa já
        // terem sido commitados pela DB::transaction() anterior.
        $this->cadastrarPeloComercial('Empresa Invariante Gate Explode');

        $company = Company::where('name', 'Empresa Invariante Gate Explode')->first();

        $this->assertNotNull($company, 'A falha do gate não pode desfazer o cadastro da Company');
        $this->assertTrue(
            MlbEmpresa::where('company_id', $company->id)->exists(),
            'A falha do gate não pode desfazer o roteamento operacional já commitado',
        );
    }

    // ─── Cenário 4 — a fase não liga o interruptor de emergência ───────────

    /**
     * O interruptor de emergência (`EmpresaOperacionalRouter::CHAVE_BLOQUEIO`,
     * Fase 124) continua DESLIGADO ('0') do início ao fim de todos os
     * cenários desta fase — plano 128 não é a fase que liga essa chave
     * (isso é decisão da Fase 133). Roda os três caminhos de entrada
     * (Comercial, webhook, gate explodindo) na mesma execução e confirma
     * que nenhum deles tocou a configuração.
     */
    public function test_interruptor_de_emergencia_continua_desligado_apos_todos_os_cenarios(): void
    {
        $this->assertSame('0', Configuracao::get(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0'));

        $this->cadastrarPeloComercial('Empresa Invariante Flag Comercial');
        $this->assertSame('0', Configuracao::get(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0'));

        $this->mockaHubSpot([]);
        $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $this->assertSame('0', Configuracao::get(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0'));

        $mock = \Mockery::mock(GatilhoContratoAdministrativoService::class);
        $mock->shouldReceive('dispararSeElegivel')->andThrow(new \RuntimeException('falha simulada do gate'));
        $this->app->instance(GatilhoContratoAdministrativoService::class, $mock);
        $this->cadastrarPeloComercial('Empresa Invariante Flag Gate Explode');
        $this->assertSame('0', Configuracao::get(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0'));
    }
}
