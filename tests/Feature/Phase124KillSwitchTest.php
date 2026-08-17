<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 124 Plano 04 — prova o interruptor de emergência da milestone v22.0
 * (REDE-01) nos DOIS lados, exercitando `EmpresaOperacionalRouter`
 * DIRETAMENTE, sem HTTP.
 *
 * Ligado, o roteamento automático PARA (nenhuma `MlbEmpresa` nem
 * `MlbImplementacao` nasce). Desligado, o roteamento é o de sempre. A
 * chave está desligada em produção e continua assim até a Fase 133 — o
 * objetivo deste arquivo é que o mecanismo esteja PROVADO antes de a
 * operação passar a depender dele.
 *
 * Fase 124 Plano 05 estendeu este arquivo com 2 testes que provam a FIAÇÃO
 * ponta a ponta pelos dois caminhos HTTP de entrada (`POST /comercial/empresas`
 * e `POST /api/webhooks/hubspot`) — comportamento que só passa a existir
 * DEPOIS de os controllers religarem no router (tasks 1 e 2 deste plano).
 *
 * Este arquivo NÃO entra no gate de regressão de 6 arquivos (o baseline
 * congelado dos planos 124-03/124-05): ele testa comportamento NOVO, que
 * por definição não existia antes da refatoração — incluí-lo faria o diff
 * nominal acusar diferença legítima como se fosse regressão.
 */
class Phase124KillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const HUBSPOT_SECRET = 'segredo-hubspot-testes-32-chars-zzz';
    private const HUBSPOT_URL    = '/api/webhooks/hubspot';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Polos', 'Assessoria', 'Incubadora'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => 'mensal', 'ativo' => true],
            );
        }

        // Config previsivel p/ o webhook HubSpot — mesma convenção de
        // Phase124RegressaoHubspotTest::setUp().
        config([
            'services.hubspot.client_secret'          => self::HUBSPOT_SECRET,
            'services.hubspot.access_token'           => 'token-fake',
            'services.hubspot.stage_fechado_ganho_id' => '1352209026,closedwon',
            'services.hubspot.props.deal' => [
                'servico' => 'servico_ecf',
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

    private function criarEmpresa(string $nome = 'Empresa Teste Interruptor'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function router(): EmpresaOperacionalRouter
    {
        return app(EmpresaOperacionalRouter::class);
    }

    private function userAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Calcula assinatura v3 do mesmo jeito do controller — copiado de
     * Phase124RegressaoHubspotTest (convenção do projeto: cada arquivo de
     * teste de webhook tem sua própria cópia, sem trait compartilhada).
     */
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
            'dealname'    => 'Cliente Teste Interruptor',
            'amount'      => '1500.00',
            'dealstage'   => 'closedwon',
            'servico_ecf' => 'Mentoria Avançada',
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

    /**
     * O nome da chave é contrato entre as Fases 124, 128, 131 e 133.
     * Mudá-lo em silêncio quebraria a tela do admin (Fase 131), que aciona
     * o botão por este literal.
     */
    public function test_chave_do_interruptor_tem_o_nome_acordado(): void
    {
        $this->assertSame('administrativo_bloqueio_ativo', EmpresaOperacionalRouter::CHAVE_BLOQUEIO);
    }

    /**
     * Sem nenhuma linha gravada na tabela `configuracoes`, o interruptor
     * está desligado. Este é o "default false" do D-04 do CONTEXT: não há
     * migration de seed — o desligado vem do default de `Configuracao::get`
     * dentro do próprio router.
     */
    public function test_interruptor_nasce_desligado(): void
    {
        $router = $this->router();

        $this->assertFalse($router->bloqueioAtivo());
    }

    /**
     * Com a chave LIGADA, `rotearCadastro()` (mecânica do Comercial) não
     * cria NADA — nem `MlbEmpresa`, nem `MlbImplementacao`. Este é o teste
     * que prova que o interruptor funciona de verdade, não decorativamente.
     */
    public function test_interruptor_ligado_impede_o_roteamento_do_cadastro(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos']);

        $this->assertSame(0, MlbEmpresa::count());
        $this->assertSame(0, MlbImplementacao::count());
    }

    /**
     * Mesma chave ligada, mas pelo segundo ponto de entrada —
     * `rotearServico()` (mecânica do webhook HubSpot). A leitura do
     * interruptor é única (D-05), mas os dois caminhos públicos precisam
     * parar igualmente.
     */
    public function test_interruptor_ligado_impede_o_roteamento_por_servico(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $company = $this->criarEmpresa();

        $this->router()->rotearServico($company, 'Polos');

        $this->assertSame(0, MlbEmpresa::count());
        $this->assertSame(0, MlbImplementacao::count());
    }

    /**
     * Com a chave DESLIGADA (estado de produção), o roteamento continua
     * criando a ficha normalmente — prova que o `return` do bloqueio não
     * vazou nem afetou o caminho desligado.
     */
    public function test_interruptor_desligado_roteia_como_sempre(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $company = $this->criarEmpresa();

        $this->router()->rotearCadastro($company, ['Polos']);

        $mlbEmp = MlbEmpresa::where('company_id', $company->id)->where('tipo', 'POLO')->first();
        $this->assertNotNull($mlbEmp, 'Com o interruptor desligado, a ficha POLO deve ser criada');
        $this->assertSame('POLOS', $mlbEmp->projeto);

        $this->assertNotNull(
            MlbImplementacao::where('empresa_id', $mlbEmp->id)->first(),
            'Com o interruptor desligado, a MlbImplementacao deve ser criada',
        );
    }

    // ─── Fiação ponta a ponta (Plano 124-05) ─────────────────────────────────

    /**
     * Prova que o interruptor alcança o caminho Comercial DEPOIS de
     * `ComercialController::store()` ter sido religado a
     * `EmpresaOperacionalRouter::rotearCadastro()` (task 1 deste plano).
     *
     * Com a chave ligada, o cadastro comercial NÃO quebra — a `Company` e o
     * `ContratoServico` continuam sendo criados normalmente, exatamente como
     * hoje. Só o roteamento para: nenhuma `MlbEmpresa` nem `MlbImplementacao`
     * nasce. Em produção a chave está '0' e nada disso acontece — o valor
     * deste teste é provar o mecanismo de emergência ANTES de a Fase 133
     * depender dele.
     */
    public function test_interruptor_ligado_impede_o_cadastro_manual_de_criar_ficha(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $admin = $this->userAdmin();
        $polos = Servico::where('nome', 'Polos')->firstOrFail();

        $response = $this->actingAs($admin)->post('/comercial/empresas', [
            'nome'     => 'Empresa Teste Interruptor Ligado Comercial',
            'servicos' => [
                ['servico_id' => $polos->id],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $company = Company::where('name', 'Empresa Teste Interruptor Ligado Comercial')->firstOrFail();
        $this->assertNotNull($company, 'O cadastro comercial não quebra — a Company é criada normalmente');
        $this->assertSame(1, $company->contratosServico()->count(), 'O contrato também é criado normalmente');

        $this->assertSame(0, MlbEmpresa::count(), 'Com o interruptor ligado, nenhuma MlbEmpresa nasce');
        $this->assertSame(0, MlbImplementacao::count(), 'Com o interruptor ligado, nenhuma MlbImplementacao nasce');
    }

    /**
     * Prova que o interruptor alcança o caminho webhook HubSpot DEPOIS de
     * `HubspotWebhookController::criarEmpresa()` ter sido religado a
     * `EmpresaOperacionalRouter::rotearServico()` (task 2 deste plano).
     *
     * Com a chave ligada, o webhook responde 200 e a `Company` é criada
     * normalmente (persistência de contrato/handoff não depende do router) —
     * só o roteamento para: nenhuma `MlbEmpresa` nem `MlbImplementacao` nasce.
     */
    public function test_interruptor_ligado_impede_o_webhook_de_criar_ficha(): void
    {
        Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $this->mockaHubSpot(['servico_ecf' => 'Polos']);

        $r = $this->disparaWebhookHubspot([$this->eventoHubspotPadrao()]);
        $r->assertStatus(200);

        $this->assertSame(1, Company::count(), 'O webhook não quebra — a Company é criada normalmente');

        $this->assertSame(0, MlbEmpresa::count(), 'Com o interruptor ligado, nenhuma MlbEmpresa nasce');
        $this->assertSame(0, MlbImplementacao::count(), 'Com o interruptor ligado, nenhuma MlbImplementacao nasce');
    }
}
