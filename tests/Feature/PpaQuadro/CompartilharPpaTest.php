<?php

namespace Tests\Feature\PpaQuadro;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\PortalUsuario;
use App\Models\Ppa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O botão "Compartilhar" da lista interna de PPA (23/09/2026).
 *
 * ### O que estes testes protegem
 * 1. **Que PPA de empresa NUNCA ganha token.** O link por token de Company foi
 *    aposentado em 15/09/2026 porque a posse dele dava leitura e escrita
 *    permanentes; compartilhar tem de passar pelo Portal, com login.
 * 2. **Que o link do Portal chega ao plano depois do login.** Sem isto o
 *    cliente entraria no Início e o link não serviria para nada.
 * 3. **Que o destino pós-login não vira redirecionamento aberto** nem manda o
 *    cliente para uma URL do sistema interno.
 * 4. **Que rascunho não tem link** — o portal o esconde.
 */
class CompartilharPpaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function ppaDeEmpresa(User $mentor, string $status = 'sent', ?Company $empresa = null): Ppa
    {
        return Ppa::create([
            'escopo' => Ppa::ESCOPO_GERAL, 'company_id' => ($empresa ?? $this->empresa())->id,
            'mentor_id' => $mentor->id, 'title' => 'Plano da carteira', 'status' => $status,
        ]);
    }

    private function ppaDePolos(User $mentor, string $status = 'sent'): Ppa
    {
        $empresa = MlbEmpresa::create([
            'nome' => 'Polo '.Str::random(4), 'tipo' => 'POLO', 'projeto' => 'POLOS',
            'fase' => 'M2', 'polo' => 'Arapongas', 'estagio' => 'Não Listado',
        ]);

        return Ppa::create([
            'escopo' => Ppa::ESCOPO_POLOS, 'mlb_empresa_id' => $empresa->id,
            'mentor_id' => $mentor->id, 'title' => 'Plano do polo', 'status' => $status,
        ]);
    }

    // ─── O link que a lista entrega ─────────────────────────────────────────

    #[Test]
    public function ppa_de_empresa_enviado_aponta_para_o_plano_no_portal(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppaDeEmpresa($admin);

        $this->actingAs($admin)->get(route('ppa.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('ppas.data.0.compartilhar.disponivel', true)
                ->where('ppas.data.0.compartilhar.via', 'portal')
                ->where('ppas.data.0.compartilhar.url', route('portal.auth.ppa', ['plano' => $ppa->id]))
            );
    }

    #[Test]
    public function rascunho_nao_tem_link(): void
    {
        $admin = $this->admin();
        $this->ppaDeEmpresa($admin, 'draft');
        $this->ppaDePolos($admin, 'draft');

        $this->actingAs($admin)->get(route('ppa.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('ppas.data.0.compartilhar.disponivel', false)
                ->where('ppas.data.0.compartilhar.url', null)
            );

        $this->actingAs($admin)->get(route('mlb.polos-ppa.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('ppas.data.0.compartilhar.disponivel', false)
                ->where('ppas.data.0.compartilhar.url', null)
            );
    }

    #[Test]
    public function ppa_de_polos_gera_o_link_do_quadro_no_clique(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppaDePolos($admin);

        // Abrir a lista não grava token nenhum.
        $this->actingAs($admin)->get(route('mlb.polos-ppa.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('ppas.data.0.compartilhar.via', 'link')
                ->where('ppas.data.0.compartilhar.url', null)
            );
        $this->assertNull($ppa->fresh()->workspace_token);

        $resposta = $this->actingAs($admin)
            ->postJson(route('mlb.polos-ppa.workspace.generate', $ppa))
            ->assertOk()
            ->assertJsonPath('disponivel', true)
            ->assertJsonPath('via', 'link');

        $token = $ppa->fresh()->workspace_token;
        $this->assertNotNull($token);
        $this->assertSame(route('ppa.workspace', $token), $resposta->json('url'));

        // O link abre sem login.
        auth()->logout();
        $this->get(route('ppa.workspace', $token))->assertOk();
    }

    #[Test]
    public function gerar_link_de_ppa_de_empresa_devolve_o_portal_e_nao_cria_token(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppaDeEmpresa($admin);

        $this->actingAs($admin)
            ->postJson(route('ppa.workspace.generate', $ppa))
            ->assertOk()
            ->assertJsonPath('via', 'portal')
            ->assertJsonPath('url', route('portal.auth.ppa', ['plano' => $ppa->id]));

        $this->assertNull($ppa->fresh()->workspace_token);
    }

    #[Test]
    public function marcar_como_enviado_pela_lista_nao_apaga_a_descricao(): void
    {
        $admin = $this->admin();
        $ppa = $this->ppaDeEmpresa($admin, 'draft');
        $ppa->update(['description' => 'Análise da conta']);

        $this->actingAs($admin)->put(route('ppa.update', $ppa), ['status' => 'sent'])->assertRedirect();

        $ppa->refresh();
        $this->assertSame('sent', $ppa->status);
        $this->assertSame('Análise da conta', $ppa->description);
        $this->assertNotNull($ppa->sent_at);
    }

    // ─── O cliente chega ao plano ───────────────────────────────────────────

    private function cliente(Company $empresa): PortalUsuario
    {
        $u = PortalUsuario::create([
            'nome' => 'Cliente '.uniqid(), 'email' => 'cliente.'.uniqid().'@empresa.test', 'ativo' => true,
        ]);
        $u->empresas()->attach($empresa->id, ['principal' => true]);
        $u->update(['password' => 'Senha@2026!', 'senha_definida_em' => now()]);

        return $u->fresh();
    }

    #[Test]
    public function cliente_sem_sessao_entra_e_volta_para_o_plano_do_link(): void
    {
        $empresa = $this->empresa();
        $ppa = $this->ppaDeEmpresa($this->admin(), 'sent', $empresa);
        $cliente = $this->cliente($empresa);

        $link = route('portal.auth.ppa', ['plano' => $ppa->id]);

        $this->get($link)->assertRedirect(route('portal.entrada'));

        $this->post(route('portal.senha.entrar'), ['email' => $cliente->email, 'senha' => 'Senha@2026!'])
            ->assertRedirect($link);

        $this->get($link)->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Portal/Ppa')->where('ppas.0.id', $ppa->id));
    }

    #[Test]
    public function sem_link_pendente_o_login_continua_indo_para_o_inicio(): void
    {
        $cliente = $this->cliente($this->empresa());

        $this->post(route('portal.senha.entrar'), ['email' => $cliente->email, 'senha' => 'Senha@2026!'])
            ->assertRedirect(route('portal.auth.inicio'));
    }

    #[Test]
    public function destino_fora_do_portal_e_ignorado(): void
    {
        $cliente = $this->cliente($this->empresa());

        // Uma URL do sistema interno e uma de outro domínio: nenhuma das duas
        // pode virar o destino do cliente.
        foreach (['http://localhost/companies', 'https://golpe.example/portal/ppa'] as $pretendido) {
            $this->flushSession();
            auth('portal')->logout();

            $resposta = $this->withSession(['url.intended' => $pretendido])
                ->post(route('portal.senha.entrar'), ['email' => $cliente->email, 'senha' => 'Senha@2026!']);

            $this->assertStringNotContainsString('golpe.example', $resposta->headers->get('Location'));
            $this->assertStringNotContainsString('/companies', $resposta->headers->get('Location'));
        }
    }
}
