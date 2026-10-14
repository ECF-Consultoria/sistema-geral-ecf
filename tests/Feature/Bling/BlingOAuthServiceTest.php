<?php

namespace Tests\Feature\Bling;

use App\Models\Company;
use App\Services\Bling\BlingOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlingFixtures;
use Tests\TestCase;

/**
 * Fase 184-04 Task 1 (BLING-09, BLING-10) — `state` de uso único e `code`
 * trocado uma vez só, com as credenciais do Bling só no header.
 */
class BlingOAuthServiceTest extends TestCase
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

    // ═══ Task 1 — state, URL de autorização e troca do code ═════════════════

    public function test_url_de_autorizacao_comeca_com_authorize_e_tem_state_nao_vazio(): void
    {
        $empresa = Company::factory()->create();

        $url = app(BlingOAuthService::class)->urlDeAutorizacao($empresa, null);

        $this->assertStringStartsWith('https://www.bling.com.br/Api/v3/oauth/authorize', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('state', $query);
        $this->assertNotSame('', $query['state']);
    }

    public function test_url_de_autorizacao_nao_contem_redirect_uri_nem_scope(): void
    {
        $empresa = Company::factory()->create();

        $url = app(BlingOAuthService::class)->urlDeAutorizacao($empresa, null);

        // Asserção NEGATIVA: o Bling ignora os dois na URL (valem os do
        // cadastro do app) — mandá-los esconderia um erro de cadastro.
        $this->assertStringNotContainsString('redirect_uri', $url);
        $this->assertStringNotContainsString('scope', $url);
    }

    public function test_duas_chamadas_devolvem_states_diferentes(): void
    {
        $empresa = Company::factory()->create();
        $servico = app(BlingOAuthService::class);

        $url1 = $servico->urlDeAutorizacao($empresa, null);
        $url2 = $servico->urlDeAutorizacao($empresa, null);

        parse_str((string) parse_url($url1, PHP_URL_QUERY), $q1);
        parse_str((string) parse_url($url2, PHP_URL_QUERY), $q2);

        $this->assertNotSame($q1['state'], $q2['state']);
    }

    public function test_consumir_state_devolve_dados_e_e_de_uso_unico(): void
    {
        $empresa = Company::factory()->create();
        $servico = app(BlingOAuthService::class);

        $url = $servico->urlDeAutorizacao($empresa, 42, 'configuracoes');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $dados = $servico->consumirState($query['state']);

        $this->assertSame($empresa->id, $dados['company_id']);
        $this->assertSame(42, $dados['ator']);
        $this->assertSame('configuracoes', $dados['destino']);

        // Uso único: a segunda chamada com o MESMO state devolve null — é esta
        // função que impede o reuso do code (reusar um code ainda válido revoga o cliente).
        $this->assertNull($servico->consumirState($query['state']));
    }

    public function test_trocar_code_manda_basic_enable_jwt_e_url_com_api_v3(): void
    {
        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        app(BlingOAuthService::class)->trocarCode('um-code-qualquer');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.bling.com.br/Api/v3/oauth/token'
                && str_starts_with((string) $request->header('Authorization')[0], 'Basic ')
                && $request->header('enable-jwt')[0] === '1';
        });
    }

    public function test_trocar_code_nao_manda_credenciais_no_corpo(): void
    {
        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        app(BlingOAuthService::class)->trocarCode('um-code-qualquer');

        Http::assertSent(function ($request) {
            // Asserção NEGATIVA: o Bling só aceita client_id/client_secret no
            // header Authorization — no corpo, ou falha ou vaza em log de proxy.
            return ! array_key_exists('client_id', $request->data())
                && ! array_key_exists('client_secret', $request->data());
        });
    }

    public function test_trocar_code_com_erro_400_lanca_excecao_sem_segunda_requisicao(): void
    {
        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        try {
            app(BlingOAuthService::class)->trocarCode('code-ja-usado');
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        // Reusar um code ainda válido revoga o cliente — por isso zero retentativa.
        Http::assertSentCount(1);
    }

    public function test_url_de_autorizacao_sem_credenciais_lanca_excecao_de_dominio(): void
    {
        config([
            'services.bling.client_id' => null,
            'services.bling.client_secret' => null,
            'services.bling.redirect' => null,
        ]);

        $empresa = Company::factory()->create();

        $this->expectException(\RuntimeException::class);

        app(BlingOAuthService::class)->urlDeAutorizacao($empresa, null);
    }
}
