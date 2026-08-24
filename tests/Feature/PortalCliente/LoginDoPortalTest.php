<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\PortalCodigoAcesso;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Notifications\PortalCodigoDeAcesso;
use App\Services\Portal\PortalLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O login do Portal do Cliente — identidade por pessoa, código por e-mail.
 *
 * ### O que estes testes protegem
 * 1. **Que vazar o link não vaza o acesso.** Era o objetivo declarado da
 *    mudança: a URL deixa de ser credencial.
 * 2. **Que o código encaminhado não serve.** O código é amarrado à sessão que o
 *    pediu — foi a objeção do dono do produto, e é a asserção mais importante
 *    deste arquivo.
 * 3. **Que autenticar não é autorizar.** Um cliente logado não vê a empresa de
 *    outro, nem trocando id.
 * 4. **Que a tela de login não vira verificador de clientes da ECF** (resposta
 *    idêntica para e-mail que existe e que não existe).
 */
class LoginDoPortalTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $nome = 'Empresa'): Company
    {
        return Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function usuario(Company $empresa, string $email = null, bool $ativo = true): PortalUsuario
    {
        $u = PortalUsuario::create([
            'nome' => 'Cliente '.uniqid(),
            'email' => $email ?? 'cliente.'.uniqid().'@empresa.test',
            'ativo' => $ativo,
        ]);
        $u->empresas()->attach($empresa->id, ['principal' => true]);

        return $u->fresh();
    }

    /** Pede o código e devolve o que foi gerado, lendo da notificação. */
    private function pedirCodigo(PortalUsuario $usuario): string
    {
        Notification::fake();

        $this->post(route('portal.codigo'), ['email' => $usuario->email])->assertRedirect();

        $codigo = null;
        Notification::assertSentTo($usuario, PortalCodigoDeAcesso::class, function ($n) use (&$codigo) {
            $codigo = (new \ReflectionProperty($n, 'codigo'))->getValue($n);

            return true;
        });

        return $codigo;
    }

    // ─── O fluxo que precisa funcionar ──────────────────────────────────────

    #[Test]
    public function cliente_entra_com_email_e_codigo(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuario($empresa);

        $codigo = $this->pedirCodigo($usuario);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo])
            ->assertRedirect(route('portal.auth.inicio'));

        $this->assertAuthenticatedAs($usuario, 'portal');
        $this->assertSame($empresa->id, session('portal_empresa_id'));
    }

    #[Test]
    public function depois_de_entrar_o_portal_abre_sem_token_na_url(): void
    {
        $usuario = $this->usuario($this->empresa());
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->get(route('portal.auth.inicio'))->assertOk();
        $this->get(route('portal.auth.ppa'))->assertOk();
        $this->get(route('portal.auth.onboarding'))->assertOk();
    }

    #[Test]
    public function o_primeiro_e_o_ultimo_acesso_ficam_registrados(): void
    {
        $usuario = $this->usuario($this->empresa());
        $this->assertNull($usuario->primeiro_acesso_em);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $usuario->refresh();
        $this->assertNotNull($usuario->primeiro_acesso_em);
        $this->assertNotNull($usuario->ultimo_acesso_em);
    }

    // ─── A objeção do dono do produto: e se ele repassar? ───────────────────

    /**
     * O teste mais importante deste arquivo. O código é amarrado à sessão que o
     * pediu: encaminhar o e-mail não dá acesso a ninguém.
     */
    #[Test]
    public function codigo_encaminhado_nao_serve_em_outra_sessao(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        // Outra pessoa, outro navegador: sessão nova.
        $this->flushSession();

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest('portal');
    }

    // ─── Força bruta e enumeração ───────────────────────────────────────────

    #[Test]
    public function codigo_morre_depois_de_cinco_tentativas_erradas(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        for ($i = 0; $i < PortalCodigoAcesso::MAX_TENTATIVAS; $i++) {
            $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => '000000']);
        }

        // Agora nem o código CERTO entra — o registro está queimado.
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest('portal');
    }

    #[Test]
    public function codigo_expirado_nao_serve(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        PortalCodigoAcesso::where('portal_usuario_id', $usuario->id)
            ->update(['expira_em' => now()->subMinute()]);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo])
            ->assertSessionHasErrors('codigo');
    }

    #[Test]
    public function codigo_so_vale_uma_vez(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo]);
        $this->post(route('portal.sair'));

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo])
            ->assertSessionHasErrors('codigo');
    }

    /** Pedir código novo mata o anterior — senão dez pedidos dariam dez chances. */
    #[Test]
    public function pedir_codigo_novo_invalida_o_anterior(): void
    {
        $usuario = $this->usuario($this->empresa());
        $primeiro = $this->pedirCodigo($usuario);
        $this->pedirCodigo($usuario);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $primeiro])
            ->assertSessionHasErrors('codigo');
    }

    /** O código nunca fica em claro no banco. */
    #[Test]
    public function o_codigo_e_guardado_com_hash(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        $registro = PortalCodigoAcesso::where('portal_usuario_id', $usuario->id)->first();

        $this->assertNotSame($codigo, $registro->codigo_hash);
        $this->assertStringNotContainsString($codigo, $registro->codigo_hash);
    }

    /**
     * A tela de login não pode virar um verificador de quem é cliente da ECF.
     */
    #[Test]
    public function resposta_e_identica_para_email_que_existe_e_que_nao_existe(): void
    {
        Notification::fake();
        $usuario = $this->usuario($this->empresa());

        $existe    = $this->post(route('portal.codigo'), ['email' => $usuario->email]);
        $naoExiste = $this->post(route('portal.codigo'), ['email' => 'ninguem'.uniqid().'@teste.test']);

        $this->assertSame($existe->getStatusCode(), $naoExiste->getStatusCode());
        $existe->assertSessionHasNoErrors();
        $naoExiste->assertSessionHasNoErrors();
    }

    // ─── Autorização: autenticar não basta ──────────────────────────────────

    /** O IDOR clássico: trocar a empresa no formulário. */
    #[Test]
    public function cliente_nao_alcanca_empresa_de_outro_trocando_o_id(): void
    {
        $minha = $this->empresa('Minha');
        $alheia = $this->empresa('Alheia');
        $usuario = $this->usuario($minha);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->post(route('portal.auth.empresa'), ['company_id' => $alheia->id])->assertForbidden();

        $this->assertSame($minha->id, session('portal_empresa_id'));
    }

    /** Revogar precisa valer na requisição SEGUINTE, não quando a sessão expirar. */
    #[Test]
    public function desativar_o_usuario_corta_o_acesso_na_hora(): void
    {
        $usuario = $this->usuario($this->empresa());
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);
        $this->get(route('portal.auth.inicio'))->assertOk();

        $usuario->update(['ativo' => false]);

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
        $this->assertGuest('portal');
    }

    /** Tirar o vínculo com a empresa também corta, mesmo com o usuário ativo. */
    #[Test]
    public function remover_o_vinculo_com_a_empresa_corta_o_acesso(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuario($empresa);
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $usuario->empresas()->detach($empresa->id);

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
    }

    #[Test]
    public function usuario_desativado_nao_recebe_codigo(): void
    {
        Notification::fake();
        $usuario = $this->usuario($this->empresa(), null, ativo: false);

        $this->post(route('portal.codigo'), ['email' => $usuario->email])->assertRedirect();

        Notification::assertNothingSent();
    }

    #[Test]
    public function rota_do_portal_exige_login(): void
    {
        foreach (['portal.auth.inicio', 'portal.auth.ppa', 'portal.auth.onboarding'] as $rota) {
            $this->get(route($rota))->assertRedirect(route('portal.entrada'));
        }
    }

    // ─── Mover tarefa autenticado ───────────────────────────────────────────

    /**
     * O cliente logado move a tarefa pela rota SEM token.
     *
     * A tela tem duas portas para a mesma ação e precisa escolher a certa. Na
     * primeira versão ela chamava sempre a rota do token — e no modo
     * autenticado, sem token, o Ziggy lançava por parâmetro faltando. O cliente
     * via só "Não foi possível salvar".
     */
    #[Test]
    public function cliente_logado_move_tarefa_do_ppa(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuario($empresa);

        $ppa = \App\Models\Ppa::create([
            'escopo' => \App\Models\Ppa::ESCOPO_GERAL, 'company_id' => $empresa->id,
            'mentor_id' => \App\Models\User::create([
                'name' => 'M', 'email' => 'm.'.uniqid().'@ecf.test',
                'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
            ])->id,
            'title' => 'Plano', 'status' => 'sent',
        ]);
        $task = \App\Models\PpaTask::create(['ppa_id' => $ppa->id, 'title' => 'Tarefa', 'status' => 'todo', 'order' => 0]);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->patchJson(route('portal.auth.ppa.tarefa', $task), ['status' => 'doing'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'doing']);

        $this->assertDatabaseHas('ppa_tasks', ['id' => $task->id, 'status' => 'doing']);
    }

    /** E não move a de outra empresa, mesmo logado. */
    #[Test]
    public function cliente_logado_nao_move_tarefa_de_outra_empresa(): void
    {
        $usuario = $this->usuario($this->empresa('Minha'));
        $alheia  = $this->empresa('Alheia');

        $ppaAlheio = \App\Models\Ppa::create([
            'escopo' => \App\Models\Ppa::ESCOPO_GERAL, 'company_id' => $alheia->id,
            'mentor_id' => \App\Models\User::create([
                'name' => 'M', 'email' => 'm2.'.uniqid().'@ecf.test',
                'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
            ])->id,
            'title' => 'Alheio', 'status' => 'sent',
        ]);
        $task = \App\Models\PpaTask::create(['ppa_id' => $ppaAlheio->id, 'title' => 'X', 'status' => 'todo', 'order' => 0]);

        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->patchJson(route('portal.auth.ppa.tarefa', $task), ['status' => 'done'])->assertForbidden();
        $this->assertDatabaseHas('ppa_tasks', ['id' => $task->id, 'status' => 'todo']);
    }

    // ─── Sessão ─────────────────────────────────────────────────────────────

    /** Sem regeneração, um id de sessão plantado antes do login continuaria valendo. */
    #[Test]
    public function a_sessao_e_regenerada_no_login(): void
    {
        $usuario = $this->usuario($this->empresa());
        $codigo = $this->pedirCodigo($usuario);

        $antes = session()->getId();
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $codigo]);

        $this->assertNotSame($antes, session()->getId());
    }

    #[Test]
    public function sair_encerra_a_sessao(): void
    {
        $usuario = $this->usuario($this->empresa());
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->post(route('portal.sair'))->assertRedirect(route('portal.entrada'));

        $this->assertGuest('portal');
        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
    }

    /** O guard do cliente e o do time da ECF são independentes. */
    #[Test]
    public function login_de_cliente_nao_autentica_no_sistema_interno(): void
    {
        $usuario = $this->usuario($this->empresa());
        $this->post(route('portal.validar'), ['email' => $usuario->email, 'codigo' => $this->pedirCodigo($usuario)]);

        $this->assertAuthenticatedAs($usuario, 'portal');
        $this->assertGuest('web');
    }

    #[Test]
    public function usuario_interno_logado_nao_vira_cliente(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);

        $this->actingAs($admin);

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
        $this->assertGuest('portal');
    }
}
