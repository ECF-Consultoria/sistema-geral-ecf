<?php

namespace Tests\Feature\Bling;

use App\Http\Controllers\BlingOAuthController;
use App\Models\BlingConexao;
use App\Models\Company;
use App\Models\User;
use App\Services\Bling\BlingOAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\BlingFixtures;
use Tests\TestCase;

/**
 * Fase 184-07 (BLING-17, BLING-18, BLING-19, BLING-29) — o caminho que o
 * cliente percorre para conectar o Bling: link assinado de 7 dias (Task 1),
 * callback com conferência de CNPJ (Task 2) e a landing em blade com quatro
 * estados, amigável no sucesso e no erro (Task 3).
 */
class BlingLinkEAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    /** Regex de sigilo — nenhum texto que o cliente lê cita a plataforma de venda (CONTEXT §4.6). */
    private const PROIBIDO = '/mercado\s*livre|mercadolivre|\bml\b|shopee|magalu|amazon/i';

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

    private function extrairState(string $urlDeAutorizacao): string
    {
        parse_str((string) parse_url($urlDeAutorizacao, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /** Monta o `Http::fake` da troca de code + leitura de dados básicos, com o CNPJ do Bling customizável. */
    private function fakeOAuthCompleto(?string $cnpjBling): void
    {
        $basicos = BlingFixtures::ler('dados-basicos');
        $basicos['data']['cnpj'] = $cnpjBling;

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::response($basicos, 200),
        ]);
    }

    private function assertSemOrigem(string $texto, string $onde): void
    {
        $texto = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $texto);
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $texto, "a origem vazou em {$onde}: {$texto}");
    }

    private function renderizarEstado(string $estado, array $extra = []): string
    {
        return view('oauth.bling-conectar', array_merge([
            'estado' => $estado,
            'empresa_nome' => 'Empresa Teste',
            'button_url' => null,
            'button_label' => 'Autorizar acesso',
            'mensagem' => null,
        ], $extra))->render();
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

    // ═══ Task 2 — callback com conferência de CNPJ ═══════════════════════════

    public function test_callback_com_cnpj_igual_grava_conexao_ativa(): void
    {
        $company = Company::factory()->create(['cnpj' => '12.345.678/0001-90']);
        $this->fakeOAuthCompleto('12.345.678/0001-90');

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");

        $resposta->assertStatus(200);
        $resposta->assertSee('concluída');

        $conexao = BlingConexao::where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_ATIVA, $conexao->status);
        $this->assertNotNull($conexao->access_token);
        $this->assertNotNull($conexao->refresh_token);
        $this->assertSame('987654321', $conexao->bling_empresa_id);
        $this->assertSame('12.345.678/0001-90', $conexao->bling_cnpj);
        $this->assertNotNull($conexao->bling_nome);
        $this->assertNotNull($conexao->connected_at);
    }

    public function test_callback_com_cnpj_diferente_fica_divergente_sem_token(): void
    {
        // Asserção NEGATIVA: é a falha exata do OAuth do ML nos Polos.
        $company = Company::factory()->create(['cnpj' => '99.888.777/0001-10']);
        $this->fakeOAuthCompleto('12.345.678/0001-90');

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");
        $resposta->assertStatus(200);

        $linhaCrua = DB::table('bling_conexoes')->where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_DIVERGENTE, $linhaCrua->status);
        $this->assertNull($linhaCrua->access_token);
        $this->assertNull($linhaCrua->refresh_token);
    }

    public function test_callback_matriz_filial_fica_divergente_sem_token(): void
    {
        // Mesma raiz de 8 dígitos (12345678), final diferente — matriz/filial.
        $company = Company::factory()->create(['cnpj' => '12.345.678/0002-71']);
        $this->fakeOAuthCompleto('12.345.678/0001-90');

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");
        $resposta->assertStatus(200);
        $resposta->assertSee('matriz/filial');

        $linhaCrua = DB::table('bling_conexoes')->where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_DIVERGENTE, $linhaCrua->status);
        $this->assertNull($linhaCrua->access_token);
        $this->assertNull($linhaCrua->refresh_token);
    }

    public function test_callback_empresa_sem_cnpj_fica_divergente_sem_token(): void
    {
        $company = Company::factory()->create(['cnpj' => null]);
        $this->fakeOAuthCompleto('12.345.678/0001-90');

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");
        $resposta->assertStatus(200);

        $linhaCrua = DB::table('bling_conexoes')->where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_DIVERGENTE, $linhaCrua->status);
        $this->assertNull($linhaCrua->access_token);
        $this->assertNull($linhaCrua->refresh_token);
    }

    public function test_callback_bling_sem_cnpj_fica_divergente_sem_token(): void
    {
        $company = Company::factory()->create(['cnpj' => '12.345.678/0001-90']);
        $this->fakeOAuthCompleto(''); // Bling não informou CNPJ utilizável

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");
        $resposta->assertStatus(200);

        $linhaCrua = DB::table('bling_conexoes')->where('company_id', $company->id)->first();
        $this->assertSame(BlingConexao::STATUS_DIVERGENTE, $linhaCrua->status);
        $this->assertNull($linhaCrua->access_token);
        $this->assertNull($linhaCrua->refresh_token);
    }

    public function test_state_ja_consumido_nao_chama_o_bling(): void
    {
        Http::fake(); // qualquer chamada feita quebra o assertNothingSent abaixo

        $company = Company::factory()->create();
        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        // Simula um callback anterior já processado (duplo clique, retry do navegador).
        $this->servico()->consumirState($state);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-de-teste");

        $resposta->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_callback_sem_state_ou_code_nao_chama_o_bling(): void
    {
        Http::fake();

        $this->get('/oauth/bling/callback')->assertStatus(200);

        Http::assertNothingSent();
    }

    public function test_falha_ao_trocar_code_cai_em_erro_amigavel_sem_500(): void
    {
        $company = Company::factory()->create();
        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $url = $this->servico()->urlDeAutorizacao($company, null);
        $state = $this->extrairState($url);

        $resposta = $this->get("/oauth/bling/callback?state={$state}&code=codigo-secreto-de-teste");

        $resposta->assertStatus(200);
        $html = $resposta->getContent();
        $this->assertStringNotContainsString('Exception', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('app/', $html);
        $this->assertStringNotContainsString('codigo-secreto-de-teste', $html);
    }

    public function test_html_dos_quatro_estados_nao_cita_a_origem(): void
    {
        foreach (['convite', 'concluido', 'erro', 'nao_configurado'] as $estado) {
            $html = $this->renderizarEstado($estado, [
                'button_url' => 'https://www.bling.com.br/Api/v3/oauth/authorize?state=x',
                'mensagem' => 'Mensagem de erro de teste.',
            ]);

            $this->assertSemOrigem($html, "estado {$estado}");
        }
    }

    // ═══ Task 3 — a blade da landing ══════════════════════════════════════

    public function test_estado_convite_renderiza_nome_botao_e_explicacao(): void
    {
        $html = $this->renderizarEstado('convite', ['button_url' => 'https://bling.example.com/authorize']);

        $this->assertStringContainsString('Empresa Teste', $html);
        $this->assertStringContainsString('https://bling.example.com/authorize', $html);
        $this->assertStringContainsString('Autorizar acesso', $html);
    }

    public function test_estado_concluido_nao_renderiza_botao(): void
    {
        $html = $this->renderizarEstado('concluido');

        $this->assertStringContainsString('concluída', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
    }

    public function test_estado_erro_renderiza_mensagem_e_proximo_passo_sem_botao(): void
    {
        $html = $this->renderizarEstado('erro', ['mensagem' => 'Mensagem de teste para a tela de erro.']);

        $this->assertStringContainsString('Mensagem de teste para a tela de erro.', $html);
        $this->assertStringContainsString('tentar novamente', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
    }

    public function test_estado_nao_configurado_e_neutro_sem_detalhe_tecnico(): void
    {
        $html = $this->renderizarEstado('nao_configurado');

        $this->assertStringNotContainsString('BLING_CLIENT', $html);
        $this->assertStringNotContainsString('.env', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
    }

    public function test_nenhum_dos_quatro_estados_cita_plataforma_de_venda(): void
    {
        foreach (['convite', 'concluido', 'erro', 'nao_configurado'] as $estado) {
            $html = $this->renderizarEstado($estado, [
                'button_url' => 'https://bling.example.com/authorize',
                'mensagem' => 'Mensagem de erro de teste.',
            ]);

            $this->assertSemOrigem($html, "estado {$estado} (Task 3)");
        }
    }

    public function test_nenhum_dos_quatro_estados_vaza_detalhe_tecnico(): void
    {
        foreach (['convite', 'concluido', 'erro', 'nao_configurado'] as $estado) {
            $html = $this->renderizarEstado($estado, [
                'button_url' => 'https://bling.example.com/authorize',
                'mensagem' => 'Mensagem de erro de teste.',
            ]);

            $this->assertStringNotContainsString('Exception', $html);
            $this->assertStringNotContainsString('Stack trace', $html);
            $this->assertStringNotContainsString('vendor/', $html);
            $this->assertStringNotContainsString('app/', $html);
            $this->assertStringNotContainsString('BLING_CLIENT', $html);
        }
    }
}
