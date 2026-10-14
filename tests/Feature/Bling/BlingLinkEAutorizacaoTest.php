<?php

namespace Tests\Feature\Bling;

use App\Http\Controllers\BlingOAuthController;
use App\Models\BlingConexao;
use App\Models\Company;
use App\Models\User;
use App\Services\Bling\BlingOAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 184-07 (BLING-17, BLING-18, BLING-19, BLING-29) — o caminho que o
 * cliente percorre para conectar o Bling: link assinado de 7 dias (Task 1).
 * O callback com conferência de CNPJ (Task 2) e a landing em blade com
 * quatro estados (Task 3) chegam nos próximos commits deste mesmo plano.
 */
class BlingLinkEAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.client_id' => 'client-id-de-teste',
            'services.bling.client_secret' => 'client-secret-de-teste',
            'services.bling.redirect' => 'https://portal.exemplo.com.br/bling/callback',
        ]);
    }

    protected function tearDown(): void
    {
        // Carbon::setTestNow() é estático — não reseta entre testes por
        // conta própria. Sem isto, um teste que avança o relógio contamina
        // os próximos.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function servico(): BlingOAuthService
    {
        return app(BlingOAuthService::class);
    }

    // ═══ Task 1 — link assinado e rota pública da landing ═══════════════════

    public function test_gerar_link_cria_conexao_aguardando_cliente(): void
    {
        $company = Company::factory()->create();

        $resultado = $this->servico()->gerarLinkDeAutorizacao($company, null);

        $this->assertNotEmpty($resultado['url']);
        $this->assertNotEmpty($resultado['gerado_em']);
        $this->assertNotEmpty($resultado['expira_em']);

        $conexao = BlingConexao::where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_AGUARDANDO_CLIENTE, $conexao->status);
        $this->assertSame($resultado['url'], $conexao->link_url);
        $this->assertNotNull($conexao->link_gerado_em);
    }

    public function test_link_expira_depois_de_7_dias(): void
    {
        $company = Company::factory()->create();
        $resultado = $this->servico()->gerarLinkDeAutorizacao($company, null);

        Carbon::setTestNow(now()->addDays(7)->addMinute());

        $this->get($resultado['url'])->assertStatus(403);
    }

    public function test_link_aberto_dentro_do_prazo_devolve_200(): void
    {
        $company = Company::factory()->create();
        $resultado = $this->servico()->gerarLinkDeAutorizacao($company, null);

        $this->get($resultado['url'])->assertStatus(200);
    }

    public function test_assinatura_adulterada_devolve_403(): void
    {
        // Asserção NEGATIVA: é o que impede que um link vazado e editado
        // conecte um Bling qualquer a outra empresa (T-184-39).
        $company = Company::factory()->create();
        $resultado = $this->servico()->gerarLinkDeAutorizacao($company, null);

        $ultimoCaractere = substr($resultado['url'], -1);
        $urlAdulterada = substr($resultado['url'], 0, -1).($ultimoCaractere === 'a' ? 'b' : 'a');

        $this->get($urlAdulterada)->assertStatus(403);
    }

    public function test_regerar_grava_link_diferente_e_o_antigo_fica_orfao(): void
    {
        $company = Company::factory()->create();
        $servico = $this->servico();

        $primeiro = $servico->gerarLinkDeAutorizacao($company, null);

        // Garante um segundo de diferença real: `temporarySignedRoute` arredonda
        // a expiração ao segundo, e duas chamadas na mesma fração de segundo
        // gerariam a MESMA assinatura.
        Carbon::setTestNow(now()->addSecond());
        $segundo = $servico->gerarLinkDeAutorizacao($company, null);

        $this->assertNotSame($primeiro['url'], $segundo['url']);

        // O link antigo ainda abre (a assinatura dele não expirou — não há
        // revogação de HMAC), mas cai na tela de erro amigável: o link
        // gravado na empresa já é outro.
        $respostaAntiga = $this->get($primeiro['url']);
        $respostaAntiga->assertStatus(200);
        $respostaAntiga->assertSee('substituído');

        $respostaNova = $this->get($segundo['url']);
        $respostaNova->assertStatus(200);
        $respostaNova->assertDontSee('substituído');
    }

    public function test_usuario_sem_papel_admin_nao_gera_link(): void
    {
        // A rota autenticada real fica no plano 11 (`routes/mlb_anuncios.php`,
        // grupo `role:admin`). Aqui provamos a mesma proteção com uma rota de
        // teste, chamando o controller direto — sem disputar o arquivo do
        // plano 11.
        Route::post('/_teste/bling/gerar-link/{company}', [BlingOAuthController::class, 'gerarLink'])
            ->middleware(['web', 'auth', 'role:admin']);

        $company = Company::factory()->create();
        $consultor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($consultor)
            ->postJson("/_teste/bling/gerar-link/{$company->id}")
            ->assertStatus(403);
    }

    public function test_usuario_admin_gera_link(): void
    {
        // Par positivo do teste anterior — sem ele, a asserção negativa não
        // provaria que a rota FUNCIONA para quem tem o papel certo.
        Route::post('/_teste/bling/gerar-link/{company}', [BlingOAuthController::class, 'gerarLink'])
            ->middleware(['web', 'auth', 'role:admin']);

        $company = Company::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $resposta = $this->actingAs($admin)->postJson("/_teste/bling/gerar-link/{$company->id}");

        $resposta->assertStatus(200);
        $resposta->assertJsonStructure(['url', 'gerado_em', 'expira_em']);
    }

    public function test_empresa_sem_cnpj_ainda_gera_link_com_aviso(): void
    {
        $company = Company::factory()->create(['cnpj' => null]);

        $resultado = $this->servico()->gerarLinkDeAutorizacao($company, null);

        $this->assertNotEmpty($resultado['url']);

        $conexao = BlingConexao::where('company_id', $company->id)->first();
        $this->assertStringContainsString('CNPJ', (string) $conexao->last_error);
    }

    public function test_gerar_link_sem_credenciais_devolve_422_e_nao_500(): void
    {
        config([
            'services.bling.client_id' => null,
            'services.bling.client_secret' => null,
            'services.bling.redirect' => null,
        ]);

        Route::post('/_teste/bling/gerar-link-sem-credenciais/{company}', [BlingOAuthController::class, 'gerarLink'])
            ->middleware(['web', 'auth']);

        $company = Company::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $resposta = $this->actingAs($admin)
            ->postJson("/_teste/bling/gerar-link-sem-credenciais/{$company->id}");

        $resposta->assertStatus(422);
        $this->assertStringContainsString('BLING_CLIENT_ID', (string) $resposta->json('message'));
    }
}
