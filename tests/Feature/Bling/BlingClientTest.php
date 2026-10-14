<?php

namespace Tests\Feature\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use App\Services\Bling\BlingClient;
use App\Services\Bling\LimitadorDoBling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\BlingFixtures;
use Tests\TestCase;

/**
 * Fase 184-06 (BLING-15, BLING-16) — o cliente HTTP só de leitura da API v3
 * do Bling: Bearer + enable-jwt em toda chamada, o limitador chamado antes
 * de cada requisição, 401 renovando uma única vez, 429 e demais erros sem
 * retentativa, e nenhum log carregando o token.
 */
class BlingClientTest extends TestCase
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

    private function conexaoAtiva(array $extra = []): BlingConexao
    {
        $empresa = Company::factory()->create();

        return BlingConexao::create(array_merge([
            'company_id' => $empresa->id,
            'bling_empresa_id' => 987654321,
            'access_token' => 'access-valido-de-teste',
            'refresh_token' => 'refresh-valido-de-teste',
            'status' => BlingConexao::STATUS_ATIVA,
            'expires_at' => now()->addHours(6),
            'last_refreshed_at' => now()->subMinutes(10),
        ], $extra));
    }

    private function cliente(): BlingClient
    {
        return app(BlingClient::class);
    }

    /** Substitui o `LimitadorDoBling` do container por um espião que só CONTA chamadas, sem alterar o comportamento. */
    private function instalarEspiaoDoLimitador(): Mockery\MockInterface
    {
        $real = app(LimitadorDoBling::class);
        $espiao = Mockery::mock($real)->makePartial();
        $this->app->instance(LimitadorDoBling::class, $espiao);

        return $espiao;
    }

    // ═══ Teste 1 ══════════════════════════════════════════════════════════

    public function test_toda_requisicao_leva_bearer_enable_jwt_e_url_da_api_v3(): void
    {
        $conexao = $this->conexaoAtiva();

        Http::fake([
            'https://api.bling.com.br/Api/v3/*' => Http::response(BlingFixtures::ler('dados-basicos'), 200),
        ]);

        $this->cliente()->dadosBasicos($conexao);

        Http::assertSent(function ($request) use ($conexao) {
            return str_starts_with($request->url(), 'https://api.bling.com.br/Api/v3/')
                && $request->header('Authorization')[0] === 'Bearer '.$conexao->access_token
                && $request->header('enable-jwt')[0] === '1';
        });
    }

    // ═══ Teste 2 ══════════════════════════════════════════════════════════

    public function test_aguardar_vez_e_chamado_antes_de_cada_requisicao(): void
    {
        $conexao = $this->conexaoAtiva();

        $espiao = $this->instalarEspiaoDoLimitador();
        $espiao->shouldReceive('aguardarVez')->twice()->andReturnUsing(fn () => true);

        Http::fake([
            'https://api.bling.com.br/Api/v3/produtos*' => Http::response(['data' => []], 200),
            'https://api.bling.com.br/Api/v3/depositos' => Http::response(BlingFixtures::ler('depositos'), 200),
        ]);

        $cliente = $this->cliente();
        $cliente->produtos($conexao, 1);
        $cliente->depositos($conexao);

        // A verificação real é a expectativa `->twice()` acima — uma chamada
        // por requisição, nunca zero, nunca mais de uma.
        $this->assertTrue(true);
    }

    // ═══ Teste 3 ══════════════════════════════════════════════════════════

    public function test_401_renova_uma_vez_e_repete_a_requisicao_uma_vez(): void
    {
        $conexao = $this->conexaoAtiva();

        Http::fake([
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::sequence()
                ->push(BlingFixtures::ler('erro-401'), 401)
                ->push(BlingFixtures::ler('dados-basicos'), 200),
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        $dados = $this->cliente()->dadosBasicos($conexao);

        $this->assertSame(987654321, $dados['id']);

        // 2 chamadas ao endpoint (401 + repetição depois de renovar) + 1 ao
        // /oauth/token (a renovação) = 3 no total.
        Http::assertSentCount(3);
    }

    // ═══ Teste 4 ══════════════════════════════════════════════════════════

    public function test_segundo_401_apos_renovar_lanca_excecao_sem_terceira_tentativa(): void
    {
        $conexao = $this->conexaoAtiva();

        Http::fake([
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::response(BlingFixtures::ler('erro-401'), 401),
            'https://api.bling.com.br/Api/v3/oauth/token' => Http::response(BlingFixtures::ler('oauth-token'), 200),
        ]);

        try {
            $this->cliente()->dadosBasicos($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $chamadas = collect(Http::recorded())->map(fn ($par) => $par[0]->url());

        // Contagem EXATA, não "pelo menos": sem ela, um laço de 3 voltas de
        // renovação também passaria. 20 chamadas a /oauth/token em 60s
        // bloqueiam o IP da VPS por 60 min, para TODOS os clientes.
        $this->assertSame(2, $chamadas->filter(fn ($url) => $url === 'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos')->count());
        $this->assertSame(1, $chamadas->filter(fn ($url) => $url === 'https://api.bling.com.br/Api/v3/oauth/token')->count());
        Http::assertSentCount(3);
    }

    // ═══ Teste 5 ══════════════════════════════════════════════════════════

    public function test_429_lanca_excecao_registra_erro_e_nao_faz_segunda_requisicao(): void
    {
        $conexao = $this->conexaoAtiva();

        $espiao = $this->instalarEspiaoDoLimitador();
        $espiao->shouldReceive('registrarErro')->once();

        Http::fake([
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::response(BlingFixtures::ler('erro-429'), 429),
        ]);

        try {
            $this->cliente()->dadosBasicos($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TOO_MANY_REQUESTS', $e->getMessage());
            $this->assertStringContainsString('1 segundo', $e->getMessage());
        }

        // Asserção NEGATIVA: 600 req/10s bloqueiam o IP para todos os
        // clientes — zero retentativa depois de um 429.
        Http::assertSentCount(1);
    }

    // ═══ Teste 6 ══════════════════════════════════════════════════════════

    public function test_403_insufficient_scope_explica_que_nao_e_pra_corrigir_sozinho(): void
    {
        $conexao = $this->conexaoAtiva();

        Http::fake([
            'https://api.bling.com.br/Api/v3/produtos*' => Http::response([
                'error' => ['type' => 'insufficient_scope', 'message' => 'Escopo insuficiente.'],
            ], 403),
        ]);

        try {
            $this->cliente()->produtos($conexao, 1);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException $e) {
            $mensagem = mb_strtolower($e->getMessage());
            $this->assertStringContainsString('escopo', $mensagem);
            $this->assertStringContainsString('revoga', $mensagem);
        }
    }

    // ═══ Teste 7 ══════════════════════════════════════════════════════════

    public function test_500_lanca_excecao_registra_erro_e_nao_altera_status_da_conexao(): void
    {
        $conexao = $this->conexaoAtiva();
        $statusAntes = $conexao->status;

        $espiao = $this->instalarEspiaoDoLimitador();
        $espiao->shouldReceive('registrarErro')->once();

        Http::fake([
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::response(['error' => 'internal'], 500),
        ]);

        try {
            $this->cliente()->dadosBasicos($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame($statusAntes, $conexao->fresh()->status);
    }

    // ═══ Teste 8 ══════════════════════════════════════════════════════════

    public function test_log_de_erro_nao_contem_o_access_token(): void
    {
        $tokenSecreto = 'token-secreto-que-nunca-pode-aparecer-0123456789abcdef';
        $conexao = $this->conexaoAtiva(['access_token' => $tokenSecreto]);

        Log::spy();

        Http::fake([
            // O corpo de erro ecoa o token de propósito, simulando um proxy
            // ruidoso — é exatamente o cenário que `semSegredo()` existe para cobrir.
            'https://api.bling.com.br/Api/v3/empresas/me/dados-basicos' => Http::response([
                'error' => 'internal',
                'echo' => $tokenSecreto,
            ], 500),
        ]);

        try {
            $this->cliente()->dadosBasicos($conexao);
            $this->fail('Esperava RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $mensagem) => ! str_contains($mensagem, $tokenSecreto)
        );
    }
}
