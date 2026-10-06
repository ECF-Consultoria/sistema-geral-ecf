<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EcfAdminServer;
use App\Models\McpAcesso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Tests\Feature\DemandasDev\LiberaModulosDev;
use Tests\TestCase;

/**
 * O servidor MCP do ECF Admin (`/mcp`) como um todo: autenticação, descoberta
 * OAuth, registro de cliente, chave liga/desliga, log de acesso e a garantia
 * de que só as ferramentas de gravação declaradas gravam (decisão de
 * 06/10/2026; antes disso o MCP era só leitura).
 */
class ServidorMcpTest extends TestCase
{
    use ChamaMcp, LiberaModulosDev, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->prepararChavesDoPassport();
        // Em produção o módulo dev.demandas existe; no banco de teste, não.
        $this->liberarModulosDev();
    }

    public function test_sem_token_responde_401_com_o_endereco_da_descoberta_oauth(): void
    {
        $resposta = $this->rpc(null, 'tools/list');

        $resposta->assertStatus(401);
        // É esse cabeçalho que leva o conector do claude.ai até o login.
        $this->assertStringContainsString('resource_metadata=', (string) $resposta->headers->get('WWW-Authenticate'));
    }

    public function test_descoberta_oauth_aponta_para_o_passport(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);

        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'));
    }

    public function test_registro_de_cliente_aceita_claude_ai_e_recusa_outro_dominio(): void
    {
        $this->postJson('/oauth/register', [
            'client_name'   => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()->assertJsonStructure(['client_id']);

        // Localhost: MCP Inspector e Claude Code.
        $this->postJson('/oauth/register', [
            'client_name'   => 'Inspector',
            'redirect_uris' => ['http://localhost:6274/oauth/callback'],
        ])->assertCreated();

        // Qualquer outro site não cadastra cliente para pedir o login da equipe.
        $this->postJson('/oauth/register', [
            'client_name'   => 'Golpe',
            'redirect_uris' => ['https://site-qualquer.example.com/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_fluxo_de_autorizacao_passa_pelo_login_do_admin(): void
    {
        $retorno  = 'https://claude.ai/api/mcp/auth_callback';
        $clientId = $this->postJson('/oauth/register', [
            'client_name'   => 'Claude',
            'redirect_uris' => [$retorno],
        ])->assertCreated()->json('client_id');

        $autorizar = '/oauth/authorize?'.http_build_query([
            'client_id'             => $clientId,
            'redirect_uri'          => $retorno,
            'response_type'         => 'code',
            'scope'                 => 'mcp:use',
            'state'                 => 'abc',
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', 'verificador-de-teste-com-43-caracteres-ok', true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        // Sem sessão: cai na tela de login normal do Admin...
        $this->get($autorizar)->assertRedirect(route('login'));

        // ...e, logado, mostra a tela de autorizar em pt-BR, com quem está logado.
        $admin = $this->admin();
        $this->actingAs($admin)->get($autorizar)
            ->assertOk()
            ->assertSee('Autorizar Claude?')
            ->assertSee($admin->email)
            // Desde 06/10/2026 o conector também grava: a tela tem de dizer.
            ->assertSee('Preencher e alterar em seu nome')
            ->assertDontSee('só de leitura');
    }

    /** As que gravam (06/10/2026). `listar_acoes` só lê, mas existe para a gravação. */
    private const FERRAMENTAS_DE_ESCRITA = [
        'abrir_ticket',
        'atuar_no_ticket',
        'enviar_formulario',
        'registrar_atualizacao_demanda',
        'salvar_demanda',
    ];

    public function test_admin_ve_as_dezesseis_ferramentas(): void
    {
        $this->assertSame([
            'abrir_ticket',
            'alertas_estrategicos',
            'atuar_no_ticket',
            'demandas_dev',
            'enviar_formulario',
            'ler_tela',
            'ler_ticket',
            'listar_acoes',
            'listar_empresas',
            'listar_telas',
            'onboarding_polos',
            'painel_executivo',
            'ppa',
            'registrar_atualizacao_demanda',
            'salvar_demanda',
            'sugadores',
        ], $this->ferramentasVisiveis($this->admin()));
    }

    public function test_usuario_sem_perfil_nao_ve_ferramenta_de_fora_do_perfil(): void
    {
        // Consultor sem nenhuma permissão de setor: o PPA (a tela /ppa só
        // exige login), os alertas (rota role:admin,consultor,mentor), os
        // tickets (qualquer logado, com o módulo liberado) e as genéricas —
        // que abrem e enviam só o que o perfil dele abre e envia. Demanda dev
        // não: cadastrar é de admin e o diário é de quem tem demanda.
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->assertSame(
            ['abrir_ticket', 'alertas_estrategicos', 'atuar_no_ticket', 'enviar_formulario', 'ler_tela', 'ler_ticket', 'listar_acoes', 'listar_telas', 'ppa'],
            $this->ferramentasVisiveis($consultor)
        );

        // E chamar à força uma ferramenta de fora do perfil não devolve dado:
        // para o servidor ela nem existe para este usuário.
        $this->assertStringContainsString('not found', $this->erroDaFerramenta($consultor, 'painel_executivo'));
        $this->rpc($consultor, 'tools/call', ['name' => 'painel_executivo', 'arguments' => (object) []])
            ->assertJsonMissingPath('result');
    }

    public function test_so_as_ferramentas_de_gravacao_declaram_que_gravam(): void
    {
        $lista       = $this->rpc($this->admin(), 'tools/list')->assertOk();
        $ferramentas = $lista->json('result.tools');

        $this->assertCount(16, $ferramentas);
        // Tudo numa página: o pacote pagina de 15 em 15 e nem todo cliente busca a 2ª.
        $this->assertNull($lista->json('result.nextCursor'));
        foreach ($ferramentas as $f) {
            $grava = in_array($f['name'], self::FERRAMENTAS_DE_ESCRITA, true);
            $this->assertSame(! $grava, $f['annotations']['readOnlyHint'] ?? null, "{$f['name']}: readOnlyHint errado");
            // Só a genérica pode excluir (DELETE); o cliente a trata como destrutiva.
            $this->assertSame($f['name'] === 'enviar_formulario', $f['annotations']['destructiveHint'] ?? null, "{$f['name']}: destructiveHint errado");
        }

        // Fora das declaradas, nenhuma ferramenta de escrita existe — nem com nome de ação da tela.
        foreach (['marcar_visto', 'ack_alerta', 'atualizar_status', 'resolver_sugador'] as $acao) {
            $resposta = $this->rpc($this->admin(), 'tools/call', ['name' => $acao, 'arguments' => (object) []]);
            $this->assertNotNull($resposta->json('error'), "A ação {$acao} existe no MCP");
        }

        // E o servidor só aceita POST (GET/DELETE dão 405, sem efeito).
        $this->getJson('/mcp')->assertStatus(405);
        $this->deleteJson('/mcp')->assertStatus(405);
    }

    public function test_cada_chamada_fica_no_log_de_acesso(): void
    {
        $admin = $this->admin();

        $this->ferramenta($admin, 'listar_empresas', ['busca' => 'nada']);
        $this->erroDaFerramenta($admin, 'listar_empresas', ['cursor' => 'lixo']);

        $linhas = McpAcesso::orderBy('id')->get();
        $this->assertCount(2, $linhas);

        $this->assertSame($admin->id, $linhas[0]->user_id);
        $this->assertSame('listar_empresas', $linhas[0]->ferramenta);
        $this->assertSame(['busca' => 'nada'], $linhas[0]->argumentos);
        $this->assertTrue($linhas[0]->sucesso);

        $this->assertFalse($linhas[1]->sucesso);
        $this->assertStringContainsString('Cursor inválido', (string) $linhas[1]->erro);
    }

    public function test_chave_desligada_tira_o_mcp_do_ar(): void
    {
        config(['mcp.ecf_habilitado' => false]);

        $this->rpc($this->admin(), 'tools/list')->assertNotFound();
    }

    public function test_limite_de_uso_por_usuario(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < 60; $i++) {
            $this->rpc($admin, 'tools/list')->assertOk();
        }

        $this->rpc($admin, 'tools/list')->assertStatus(429);
    }

    public function test_servidor_declara_so_ferramentas_que_estendem_a_base(): void
    {
        $prop = (new \ReflectionClass(EcfAdminServer::class))->getProperty('tools');
        $classes = $prop->getDefaultValue();

        foreach ($classes as $classe) {
            $this->assertTrue(is_subclass_of($classe, \App\Mcp\Tools\FerramentaEcf::class), "{$classe} não estende FerramentaEcf (perderia log e perfil)");
            $this->assertTrue(is_subclass_of($classe, Tool::class));
        }
    }
}
