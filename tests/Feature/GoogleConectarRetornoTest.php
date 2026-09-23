<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conectar o Google e voltar para onde se estava (16/09/2026).
 *
 * Conectar deixou de ser assunto só do Perfil: quem marca reunião conecta de
 * dentro da ficha do onboarding. Sem `retorno`, a volta do consentimento largava
 * a pessoa no Perfil, longe do que ela estava fazendo.
 *
 * O destino é guardado na SESSÃO — o callback do Google não devolve query
 * nossa — e só aceita caminho interno: a tela de consentimento não pode virar
 * trampolim para fora do sistema.
 *
 * 23/09/2026 — a volta passou a exigir o `state` que ESTA sessão pediu. Sem
 * ele, o token ia para quem estivesse logado na volta: trocar de usuário no
 * meio do consentimento ligava o Google de uma pessoa à conta de outra.
 */
class GoogleConectarRetornoTest extends TestCase
{
    use RefreshDatabase;

    private function googleTrocaOCodigo(array $token = [], ?string $emailDaConta = null): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(array_merge([
                'access_token'  => 'token-novo',
                'refresh_token' => 'refresh-novo',
                'expires_in'    => 3600,
            ], $token)),
            'https://www.googleapis.com/oauth2/v2/userinfo' => $emailDaConta
                ? Http::response(['email' => $emailDaConta])
                : Http::response([], 500),
        ]);
    }

    /** Começa a conexão como `$user` e devolve o `state` que o Google mandaria de volta. */
    private function conectar(User $user, array $query = []): string
    {
        $this->actingAs($user)
            ->get(route('google.connect', $query))
            ->assertRedirectContains('accounts.google.com');

        return session('google_oauth')['state'];
    }

    public function test_conectar_a_partir_da_ficha_guarda_o_destino_e_volta_para_ela(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();

        $state = $this->conectar($user, ['retorno' => '/onboarding/30']);

        $this->assertSame('/onboarding/30', session('google_retorno'));

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'codigo-de-teste', 'state' => $state]))
            ->assertRedirect('/onboarding/30');

        $this->assertDatabaseHas('google_tokens', ['user_id' => $user->id]);
    }

    /**
     * Sem destino guardado, nada muda: quem conecta pelo Perfil continua
     * voltando para o Perfil.
     */
    public function test_sem_retorno_a_volta_continua_sendo_o_perfil(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();
        $state = $this->conectar($user);

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'codigo-de-teste', 'state' => $state]))
            ->assertRedirect(route('profile.edit'));
    }

    /**
     * O que impede a tela de consentimento do Google de virar trampolim: `//`
     * é URL de protocolo relativo — o navegador a lê como outro site.
     */
    public function test_destino_fora_do_sistema_e_recusado(): void
    {
        $user = User::factory()->create();

        foreach (['https://site-de-fora.test/x', '//site-de-fora.test/x', 'javascript:alert(1)'] as $tentativa) {
            $this->actingAs($user)
                ->get(route('google.connect', ['retorno' => $tentativa]))
                ->assertRedirectContains('accounts.google.com');

            $this->assertNull(session('google_retorno'), "aceitou destino externo: {$tentativa}");
        }
    }

    /**
     * O destino vale UMA volta. Deixá-lo na sessão faria a conexão seguinte,
     * vinda do Perfil, cair no onboarding de ontem.
     */
    public function test_o_destino_e_consumido_na_volta(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();
        $state = $this->conectar($user, ['retorno' => '/onboarding/30']);

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'codigo-de-teste', 'state' => $state]))
            ->assertRedirect('/onboarding/30');

        $this->assertNull(session('google_retorno'));
    }

    // ─── state (23/09/2026) ─────────────────────────────────────────────────

    public function test_url_do_google_leva_state_login_hint_e_escolha_de_conta(): void
    {
        $user = User::factory()->create(['email' => 'analista@ecf.test']);

        $destino = $this->actingAs($user)->get(route('google.connect'))->headers->get('Location');
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);

        $this->assertSame(session('google_oauth')['state'], $query['state']);
        $this->assertSame('analista@ecf.test', $query['login_hint']);
        $this->assertStringContainsString('select_account', $query['prompt']);
        $this->assertStringContainsString('consent', $query['prompt']);
    }

    public function test_volta_sem_state_nao_grava_token(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();
        $this->conectar($user);

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'codigo-de-teste']))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('google_tokens', ['user_id' => $user->id]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_state_forjado_nao_grava_token(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();
        $this->conectar($user);

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'codigo-de-teste', 'state' => 'outro-valor']))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('google_tokens', ['user_id' => $user->id]);
    }

    /**
     * O cenário da troca de usuário: A começa a conectar, alguém entra como B
     * no mesmo navegador, e a volta do Google chega com o `state` de A. O
     * token de A NÃO pode ir para B.
     */
    public function test_troca_de_usuario_no_meio_da_conexao_nao_liga_o_google_de_um_ao_outro(): void
    {
        $this->googleTrocaOCodigo();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $state = $this->conectar($a);

        $this->actingAs($b)
            ->get(route('google.callback', ['code' => 'codigo-de-a', 'state' => $state]))
            ->assertSessionHas('error');

        $this->assertSame(0, GoogleToken::count());
    }

    public function test_volta_sem_sessao_vai_para_o_login_em_vez_de_quebrar(): void
    {
        $this->get(route('google.callback', ['code' => 'x', 'state' => 'y']))
            ->assertRedirect(route('login'));
    }

    public function test_reconexao_sem_refresh_token_mantem_o_anterior(): void
    {
        $this->googleTrocaOCodigo(['refresh_token' => null]);
        $user = User::factory()->create();
        GoogleToken::create([
            'user_id' => $user->id, 'access_token' => 'velho', 'refresh_token' => 'refresh-antigo', 'expires_at' => now()->subHour(),
        ]);

        $state = $this->conectar($user);
        $this->actingAs($user)->get(route('google.callback', ['code' => 'c', 'state' => $state]));

        $token = GoogleToken::where('user_id', $user->id)->first();
        $this->assertSame('token-novo', $token->access_token);
        $this->assertSame('refresh-antigo', $token->refresh_token);
    }

    /** Navegador logado no Google de outra pessoa: conecta, mas avisa com o endereço à vista. */
    public function test_conta_google_diferente_do_email_do_sistema_avisa(): void
    {
        $this->googleTrocaOCodigo(emailDaConta: 'Outra.Pessoa@gmail.com');
        $user = User::factory()->create(['email' => 'analista@ecf.test']);

        $state = $this->conectar($user);
        $resposta = $this->actingAs($user)->get(route('google.callback', ['code' => 'c', 'state' => $state]));

        $resposta->assertSessionHas('error', fn ($m) => str_contains($m, 'outra.pessoa@gmail.com'));
        $this->assertDatabaseHas('google_tokens', ['user_id' => $user->id]);
    }

    public function test_mesma_conta_nao_avisa(): void
    {
        $this->googleTrocaOCodigo(emailDaConta: 'analista@ecf.test');
        $user = User::factory()->create(['email' => 'Analista@ecf.test']);

        $state = $this->conectar($user);

        $this->actingAs($user)
            ->get(route('google.callback', ['code' => 'c', 'state' => $state]))
            ->assertSessionHas('success');
    }

    // ─── Conexão morta (23/09/2026) ─────────────────────────────────────────

    /**
     * `invalid_grant` é definitivo. Antes o token ficava no banco e todo lugar
     * que pergunta "está conectado?" seguia dizendo que sim, para sempre.
     */
    public function test_refresh_recusado_com_invalid_grant_apaga_a_conexao(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);
        $user = User::factory()->create();
        $token = GoogleToken::create([
            'user_id' => $user->id, 'access_token' => 'velho', 'refresh_token' => 'r', 'expires_at' => now()->subMinute(),
        ]);

        try {
            app(GoogleCalendarService::class)->fetchEvents($token);
            $this->fail('deveria ter lançado');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('renovar token', $e->getMessage());
        }

        $this->assertSame(0, GoogleToken::where('user_id', $user->id)->count());
    }

    /** 401 com o token ainda "válido" pelo relógio: renova à força e tenta de novo, uma vez. */
    public function test_401_renova_e_tenta_de_novo(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renovado', 'expires_in' => 3600]),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)
                ->push(['items' => [['id' => 'e1']]]),
        ]);
        $user = User::factory()->create();
        $token = GoogleToken::create([
            'user_id' => $user->id, 'access_token' => 'revogado', 'refresh_token' => 'r', 'expires_at' => now()->addHour(),
        ]);

        $itens = app(GoogleCalendarService::class)->fetchEvents($token);

        $this->assertSame('e1', $itens[0]['id']);
        $this->assertSame('renovado', $token->fresh()->access_token);
    }
}
