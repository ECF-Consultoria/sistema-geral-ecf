<?php

namespace Tests\Feature;

use App\Models\User;
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
 */
class GoogleConectarRetornoTest extends TestCase
{
    use RefreshDatabase;

    private function googleTrocaOCodigo(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'token-novo',
                'refresh_token' => 'refresh-novo',
                'expires_in'    => 3600,
            ]),
        ]);
    }

    public function test_conectar_a_partir_da_ficha_guarda_o_destino_e_volta_para_ela(): void
    {
        $this->googleTrocaOCodigo();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('google.connect', ['retorno' => '/onboarding/30']))
            ->assertRedirectContains('accounts.google.com');

        $this->assertSame('/onboarding/30', session('google_retorno'));

        $this->actingAs($user)
            ->withSession(['google_retorno' => '/onboarding/30'])
            ->get(route('google.callback', ['code' => 'codigo-de-teste']))
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

        $this->actingAs(User::factory()->create())
            ->get(route('google.callback', ['code' => 'codigo-de-teste']))
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

        $this->actingAs(User::factory()->create())
            ->withSession(['google_retorno' => '/onboarding/30'])
            ->get(route('google.callback', ['code' => 'codigo-de-teste']))
            ->assertRedirect('/onboarding/30');

        $this->assertNull(session('google_retorno'));
    }
}
