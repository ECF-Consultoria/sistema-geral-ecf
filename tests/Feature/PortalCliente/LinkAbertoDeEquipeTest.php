<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Support\Portal\PortalContexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O link ABERTO de equipe — o portal de uma loja de TESTE sem login.
 *
 * É a exceção à regra que o `EquipeNoPortalTest` protege: lá a equipe entra
 * com a identidade dela; aqui quem tiver o link entra em nome do dono
 * configurado. O que estes testes seguram é o confinamento da exceção: só
 * empresa listada em `portal.link_equipe`, só com assinatura nossa, e tirar
 * da lista derruba até quem já estava dentro.
 */
class LinkAbertoDeEquipeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function empresa(string $nome = 'Loja Teste'): Company
    {
        return Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => false, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function naLista(Company $empresa, User $dono): void
    {
        config(['portal.link_equipe' => [$empresa->id => $dono->id]]);
    }

    private function link(Company $empresa): string
    {
        return app(PortalEquipeService::class)->urlDoLink($empresa);
    }

    // ─── Entrada ────────────────────────────────────────────────────────

    #[Test]
    public function o_link_da_empresa_listada_abre_o_portal_em_nome_do_dono(): void
    {
        $empresa = $this->empresa();
        $dono = $this->admin();
        $this->naLista($empresa, $dono);

        $this->get($this->link($empresa))->assertRedirect(route('portal.auth.inicio'));

        $this->assertSame($dono->id, session(PortalContexto::SESSAO_EQUIPE));
        $this->assertSame($empresa->id, session('portal_empresa_id'));
        $this->assertTrue(session(PortalContexto::SESSAO_LINK));

        $this->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('usuario.equipe', true)
                ->where('usuario.nome', $dono->name)
            );
    }

    /** Sem a assinatura, o endereço é adivinhável — por isso não vale nada. */
    #[Test]
    public function sem_assinatura_nao_entra(): void
    {
        $empresa = $this->empresa();
        $this->naLista($empresa, $this->admin());

        $this->get('/equipe/link/'.$empresa->id)->assertForbidden();

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    /** A assinatura cobre o id: trocar o número na URL quebra o link. */
    #[Test]
    public function a_assinatura_de_uma_empresa_nao_abre_outra(): void
    {
        $listada = $this->empresa();
        $outra = $this->empresa('Cliente Real');
        $dono = $this->admin();
        config(['portal.link_equipe' => [$listada->id => $dono->id, $outra->id => $dono->id]]);

        $adulterado = str_replace('/equipe/link/'.$listada->id.'?', '/equipe/link/'.$outra->id.'?', $this->link($listada));

        $this->get($adulterado)->assertForbidden();

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    /**
     * Assinatura válida não basta: a empresa precisa estar na lista. É o que
     * impede um link antigo de reabrir o portal de quem já saiu dela.
     */
    #[Test]
    public function empresa_fora_da_lista_nao_entra_mesmo_com_assinatura_valida(): void
    {
        $empresa = $this->empresa();
        config(['portal.link_equipe' => []]);

        $assinado = URL::signedRoute('portal.equipe.link', ['empresa' => $empresa->id], null, false);

        $this->get($assinado)->assertRedirect(route('portal.entrada'));

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    #[Test]
    public function dono_desativado_na_ecf_nao_entra(): void
    {
        $empresa = $this->empresa();
        $dono = $this->admin();
        $this->naLista($empresa, $dono);
        $dono->update(['active' => false]);

        $this->get($this->link($empresa))->assertRedirect(route('portal.entrada'));

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    // ─── Revogação ──────────────────────────────────────────────────────

    /**
     * Para o dono admin, `podeEntrar()` responde "sim" sempre — sem a
     * reconferência da lista no middleware, a sessão de 30 dias do domínio do
     * cliente sobreviveria à revogação.
     */
    #[Test]
    public function tirar_da_lista_derruba_quem_ja_estava_dentro(): void
    {
        $empresa = $this->empresa();
        $this->naLista($empresa, $this->admin());

        $this->get($this->link($empresa));
        $this->get(route('portal.auth.inicio'))->assertOk();

        config(['portal.link_equipe' => []]);

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
        $this->assertNull(session(PortalContexto::SESSAO_LINK));
    }

    /** A reconferência é só da sessão do link — a passagem normal não muda. */
    #[Test]
    public function a_passagem_normal_de_equipe_nao_depende_da_lista(): void
    {
        $empresa = $this->empresa();
        $membro = $this->admin();
        config(['portal.link_equipe' => []]);

        $token = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');

        $this->get(route('portal.equipe.entrar', ['t' => $token]));

        $this->assertNull(session(PortalContexto::SESSAO_LINK));
        $this->get(route('portal.auth.inicio'))->assertOk();
    }

    // ─── Rastro ─────────────────────────────────────────────────────────

    /**
     * Evento próprio: no histórico, "entrou pelo link" não pode se passar por
     * "o Admin entrou" — quem clicou pode ser qualquer um com o link.
     */
    #[Test]
    public function a_entrada_pelo_link_fica_registrada_como_link(): void
    {
        $empresa = $this->empresa();
        $dono = $this->admin();
        $this->naLista($empresa, $dono);

        $this->get($this->link($empresa));

        $log = DB::table('activity_log')->where('log_name', 'portal')->orderByDesc('id')->first();

        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame('equipe_entrou_por_link', $props['evento']);
        $this->assertSame($empresa->id, $props['company_id']);
        $this->assertSame($dono->id, (int) $log->causer_id);
    }

    // ─── Domínio do cliente ─────────────────────────────────────────────

    /**
     * Em produção o link vive no domínio do cliente, onde o
     * `RestringeDominioDoPortal` responde 404 para tudo fora da allowlist. A
     * assinatura é relativa justamente para sobreviver à troca de host.
     */
    #[Test]
    public function no_dominio_do_cliente_o_link_abre_e_o_sair_volta_para_a_entrada(): void
    {
        config(['portal.dominio_cliente' => 'cliente.ecf.test']);

        $empresa = $this->empresa();
        $this->naLista($empresa, $this->admin());

        $link = $this->link($empresa);
        $this->assertSame('cliente.ecf.test', parse_url($link, PHP_URL_HOST));

        $this->get($link)->assertRedirect();
        $this->assertNotNull(session(PortalContexto::SESSAO_EQUIPE));

        // Quem entrou pelo link pode não ter login no admin: sair leva à
        // entrada do portal, e não a `/companies` do sistema interno.
        $destino = $this->post('http://cliente.ecf.test/equipe/sair')->headers->get('Location');

        $this->assertStringEndsWith('/entrar', $destino);
    }

    // ─── Comando ────────────────────────────────────────────────────────

    #[Test]
    public function o_comando_imprime_um_link_que_abre(): void
    {
        $empresa = $this->empresa();
        $this->naLista($empresa, $this->admin());

        $this->assertSame(0, Artisan::call('portal:link-equipe', ['empresa' => $empresa->id]));

        $linhas = array_values(array_filter(explode("\n", trim(Artisan::output()))));
        $link = end($linhas);

        $this->get($link)->assertRedirect(route('portal.auth.inicio'));
    }

    #[Test]
    public function o_comando_recusa_empresa_fora_da_lista(): void
    {
        config(['portal.link_equipe' => []]);

        $this->assertSame(1, Artisan::call('portal:link-equipe', ['empresa' => $this->empresa()->id]));
    }
}
