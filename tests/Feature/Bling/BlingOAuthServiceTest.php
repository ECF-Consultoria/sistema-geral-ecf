<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlingFixtures;
use Tests\TestCase;

/**
 * Fase 184-04 (BLING-09 a BLING-13) — as quatro travas do ciclo OAuth do
 * Bling: `state` de uso único, `code` trocado uma vez só, renovação
 * serializada por conexão e revogação que apaga o token local mesmo com o
 * Bling fora do ar.
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

    private function conexaoAtiva(Company $empresa, array $extra = []): BlingConexao
    {
        return BlingConexao::create(array_merge([
            'company_id' => $empresa->id,
            'access_token' => 'access-antigo',
            'refresh_token' => 'refresh-antigo',
            'status' => BlingConexao::STATUS_ATIVA,
            'expires_at' => now()->addHours(6),
            // Longe dos últimos 30s — não pode disparar o atalho "renovado agora há pouco".
            'last_refreshed_at' => now()->subMinutes(10),
        ], $extra));
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

    // ═══ Task 2 — renovação travada por conta e revogação ═══════════════════

    public function test_renovar_grava_o_novo_refresh_token_devolvido_pelo_bling(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        $fixture = BlingFixtures::ler('oauth-token');
        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response($fixture, 200),
        ]);

        $renovada = app(BlingOAuthService::class)->renovar($conexao);

        $this->assertSame($fixture['refresh_token'], $renovada->refresh_token);
        $this->assertNotSame('refresh-antigo', $renovada->refresh_token);
        $this->assertSame($fixture['access_token'], $renovada->access_token);
    }

    public function test_duas_chamadas_seguidas_fazem_uma_unica_requisicao(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $servico = app(BlingOAuthService::class);
        $servico->renovar($conexao);
        $servico->renovar($conexao);

        // Prova do atalho "renovado há menos de 30s" — a segunda chamada nem
        // precisa do fake porque ela nunca fala com o Bling.
        Http::assertSentCount(1);
    }

    public function test_lock_e_por_conexao_nao_global(): void
    {
        $empresaA = Company::factory()->create();
        $empresaB = Company::factory()->create();
        $conexaoA = $this->conexaoAtiva($empresaA);
        $conexaoB = $this->conexaoAtiva($empresaB);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $servico = app(BlingOAuthService::class);
        $servico->renovar($conexaoA);
        $servico->renovar($conexaoB);

        // Lock por conexão (ids diferentes) → duas requisições, não uma.
        Http::assertSentCount(2);
    }

    public function test_400_invalid_grant_revoga_a_conexao(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        try {
            app(BlingOAuthService::class)->renovar($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(BlingConexao::STATUS_REVOGADA, $conexao->fresh()->status);
        $this->assertNotNull($conexao->fresh()->last_error);
    }

    public function test_500_mantem_a_conexao_ativa_e_lanca_excecao(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(['error' => 'internal'], 500),
        ]);

        try {
            app(BlingOAuthService::class)->renovar($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        // Asserção NEGATIVA: erro transitório não pode obrigar o cliente a reautorizar.
        $this->assertSame('ativa', $conexao->fresh()->status);
    }

    public function test_429_mantem_a_conexao_ativa_sem_segunda_requisicao(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response([
                'error' => ['type' => 'TOO_MANY_REQUESTS', 'description' => ['limit' => 20, 'period' => 60]],
            ], 429),
        ]);

        try {
            app(BlingOAuthService::class)->renovar($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame('ativa', $conexao->fresh()->status);
        $this->assertStringContainsString('TOO_MANY_REQUESTS', (string) $conexao->fresh()->last_error);

        // 600 req/10s e 20 chamadas a /oauth/token em 60s bloqueiam o IP da VPS
        // para TODOS os clientes — nenhuma retentativa imediata.
        Http::assertSentCount(1);
    }

    public function test_revogar_chama_o_endpoint_e_zera_os_tokens_locais(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa, ['access_token' => 'token-pra-revogar']);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/revoke' => Http::response([], 200),
        ]);

        app(BlingOAuthService::class)->revogar($conexao);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.bling.com.br/Api/v3/oauth/revoke'
                && $request->data()['token'] === 'token-pra-revogar'
                && $request->data()['token_type_hint'] === 'access_token';
        });

        // Asserção NEGATIVA na tabela: a linha crua não tem mais token nenhum.
        $linhaCrua = DB::table('bling_conexoes')->where('id', $conexao->id)->first();
        $this->assertNull($linhaCrua->access_token);
        $this->assertNull($linhaCrua->refresh_token);
        $this->assertSame(BlingConexao::STATUS_REVOGADA, $linhaCrua->status);
    }

    public function test_revogar_com_bling_fora_do_ar_ainda_apaga_os_tokens_locais(): void
    {
        $empresa = Company::factory()->create();
        $conexao = $this->conexaoAtiva($empresa);

        Http::fake([
            'https://api.bling.com.br/Api/v3/oauth/revoke' => Http::response(['error' => 'internal'], 500),
        ]);

        app(BlingOAuthService::class)->revogar($conexao);

        // Desconectar não pode depender do ERP estar no ar.
        $fresca = $conexao->fresh();
        $this->assertNull($fresca->access_token);
        $this->assertNull($fresca->refresh_token);
        $this->assertSame(BlingConexao::STATUS_REVOGADA, $fresca->status);
    }
}
