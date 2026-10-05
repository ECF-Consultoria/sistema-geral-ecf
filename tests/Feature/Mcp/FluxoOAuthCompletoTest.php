<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O caminho inteiro que o conector do claude.ai faz, com token DE VERDADE (sem
 * Passport::actingAs): registra o cliente, o usuário loga e autoriza, o código
 * vira token com PKCE e o token abre o `/mcp`.
 */
class FluxoOAuthCompletoTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    private const RETORNO = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->prepararChavesDoPassport();
    }

    public function test_registro_login_autorizacao_token_e_chamada(): void
    {
        $verificador = str_repeat('verificador-pkce-', 4);
        $desafio     = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');

        // 1. O claude.ai se registra (DCR).
        $clientId = $this->postJson('/oauth/register', [
            'client_name'   => 'Claude',
            'redirect_uris' => [self::RETORNO],
        ])->assertCreated()->json('client_id');

        // 2. O usuário, logado no Admin, abre a autorização e aprova.
        $admin = $this->admin();
        $tela  = $this->actingAs($admin)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId, 'redirect_uri' => self::RETORNO, 'response_type' => 'code',
            'scope' => 'mcp:use', 'state' => 'estado-1',
            'code_challenge' => $desafio, 'code_challenge_method' => 'S256',
        ]))->assertOk();

        $authToken = $tela->viewData('authToken');
        $aprovado  = $this->post(route('passport.authorizations.approve'), [
            'state' => 'estado-1', 'client_id' => $clientId, 'auth_token' => $authToken,
        ]);
        $aprovado->assertRedirect();

        $destino = $aprovado->headers->get('Location');
        $this->assertStringStartsWith(self::RETORNO, $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);
        $this->assertSame('estado-1', $query['state']);

        // 3. O código vira token (cliente público + PKCE, sem segredo).
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId,
            'redirect_uri' => self::RETORNO, 'code' => $query['code'], 'code_verifier' => $verificador,
        ])->assertOk()->json();

        $this->assertArrayHasKey('refresh_token', $token);
        // Validade curta (1 dia) — o conector renova pelo refresh token.
        $this->assertLessThanOrEqual(86400, $token['expires_in']);

        // 4. Com o token, o /mcp responde como o usuário que autorizou.
        $this->app['auth']->forgetGuards();
        $lista = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], [
            'Accept'        => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token['access_token'],
        ])->assertOk();

        $this->assertContains('listar_empresas', collect($lista->json('result.tools'))->pluck('name')->all());

        // 5. Token inválido não entra.
        $this->app['auth']->forgetGuards();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
            'Accept'        => 'application/json, text/event-stream',
            'Authorization' => 'Bearer token-forjado',
        ])->assertUnauthorized();
    }

    public function test_token_revogado_pelo_comando_para_de_funcionar(): void
    {
        $admin = $this->admin();
        app(\Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Teste', 'users');
        $token = $admin->createToken('teste', ['mcp:use'])->accessToken;

        $chamar = fn () => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
            'Accept'        => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
        ]);

        $chamar()->assertOk();

        $this->artisan('mcp:revogar', ['usuario' => (string) $admin->id])->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $chamar()->assertUnauthorized();
    }
}
