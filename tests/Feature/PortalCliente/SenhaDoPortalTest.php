<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Services\Portal\PortalLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Senha OPCIONAL do cliente.
 *
 * O caminho padrao do portal continua sendo o codigo por e-mail. A senha e uma
 * segunda porta, para quem entra com frequencia.
 *
 * O que estes testes protegem, em ordem de importancia:
 *
 *  1. **a tela nao vira um verificador de quem e cliente da ECF** — e-mail
 *     desconhecido, conta sem senha e senha errada respondem igual;
 *  2. **conta sem senha nao entra por senha** — `null` no banco nao pode virar
 *     "qualquer senha serve", que e o modo classico de essa coluna dar errado;
 *  3. **da para voltar atras** — quem definiu senha consegue remover.
 */
class SenhaDoPortalTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function cliente(?Company $empresa = null, bool $ativo = true): PortalUsuario
    {
        $empresa ??= $this->empresa();

        $u = PortalUsuario::create([
            'nome' => 'Cliente '.uniqid(),
            'email' => 'c.'.uniqid().'@empresa.test',
            'ativo' => $ativo,
        ]);
        $u->empresas()->attach($empresa->id, ['principal' => true]);

        return $u->fresh();
    }

    private function comSenha(PortalUsuario $u, string $senha = 'senha-boa-123'): PortalUsuario
    {
        app(PortalLoginService::class)->definirSenha($u, $senha);

        return $u->fresh();
    }

    // ─── Entrar ─────────────────────────────────────────────────────────

    #[Test]
    public function cliente_com_senha_entra_direto(): void
    {
        $cliente = $this->comSenha($this->cliente());

        $this->post(route('portal.senha.entrar'), [
            'email' => $cliente->email, 'senha' => 'senha-boa-123',
        ])->assertRedirect(route('portal.auth.inicio'));

        $this->assertSame($cliente->id, auth('portal')->id());
    }

    /**
     * Conta SEM senha nao entra por senha, nem com string vazia.
     *
     * E o modo classico de uma coluna de senha nullable dar errado: o hash
     * vazio bate com qualquer coisa e a conta inteira fica aberta.
     */
    #[Test]
    public function conta_sem_senha_nao_entra_por_senha(): void
    {
        $cliente = $this->cliente();

        foreach (['', 'qualquer-coisa', 'null'] as $tentativa) {
            $this->post(route('portal.senha.entrar'), [
                'email' => $cliente->email, 'senha' => $tentativa,
            ]);

            $this->assertNull(auth('portal')->user(), "entrou com '{$tentativa}'");
        }
    }

    #[Test]
    public function senha_errada_nao_entra(): void
    {
        $cliente = $this->comSenha($this->cliente());

        $this->post(route('portal.senha.entrar'), [
            'email' => $cliente->email, 'senha' => 'senha-errada-123',
        ])->assertSessionHasErrors('senha');

        $this->assertNull(auth('portal')->user());
    }

    #[Test]
    public function cliente_desativado_nao_entra_por_senha(): void
    {
        $cliente = $this->comSenha($this->cliente(ativo: false));

        $this->post(route('portal.senha.entrar'), [
            'email' => $cliente->email, 'senha' => 'senha-boa-123',
        ])->assertSessionHasErrors('senha');

        $this->assertNull(auth('portal')->user());
    }

    /** Autenticar nao basta: sem empresa vinculada, nao entra. */
    #[Test]
    public function sem_empresa_vinculada_nao_entra(): void
    {
        $cliente = $this->comSenha($this->cliente());
        $cliente->empresas()->detach();

        $this->post(route('portal.senha.entrar'), [
            'email' => $cliente->email, 'senha' => 'senha-boa-123',
        ])->assertSessionHasErrors('senha');

        $this->assertNull(auth('portal')->user());
    }

    /**
     * A MESMA mensagem para todos os motivos.
     *
     * Se "e-mail nao existe" e "senha errada" respondessem diferente, esta tela
     * responderia de graca a pergunta "fulano e cliente da ECF?".
     */
    #[Test]
    public function a_recusa_e_identica_para_todos_os_motivos(): void
    {
        $cliente = $this->comSenha($this->cliente());
        $semSenha = $this->cliente();

        $mensagens = [];

        foreach ([
            ['email' => $cliente->email,          'senha' => 'errada-mesmo-123'],
            ['email' => $semSenha->email,         'senha' => 'errada-mesmo-123'],
            ['email' => 'ninguem@lugar-nenhum.test', 'senha' => 'errada-mesmo-123'],
        ] as $tentativa) {
            $this->post(route('portal.senha.entrar'), $tentativa);
            $mensagens[] = session('errors')->first('senha');
            $this->flushSession();
        }

        $this->assertCount(1, array_unique($mensagens), 'as recusas diferem entre si');
    }

    // ─── Definir, trocar, remover ───────────────────────────────────────

    #[Test]
    public function cliente_logado_define_a_propria_senha(): void
    {
        $cliente = $this->cliente();
        $this->entrarComoCliente($cliente);

        $this->put(route('portal.auth.senha'), [
            'senha' => 'minha-senha-nova', 'senha_confirmation' => 'minha-senha-nova',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($cliente->fresh()->temSenha());
        $this->assertNotNull($cliente->fresh()->senha_definida_em);
    }

    /** A senha e guardada como HASH — nunca em claro. */
    #[Test]
    public function a_senha_nao_e_guardada_em_claro(): void
    {
        $cliente = $this->comSenha($this->cliente(), 'texto-puro-1234');

        $this->assertDatabaseMissing('portal_usuarios', [
            'id' => $cliente->id, 'password' => 'texto-puro-1234',
        ]);

        $hash = DB::table('portal_usuarios')->where('id', $cliente->id)->value('password');
        $this->assertTrue(password_verify('texto-puro-1234', $hash));
    }

    #[Test]
    public function remover_a_senha_devolve_o_login_por_codigo(): void
    {
        $cliente = $this->comSenha($this->cliente());
        $this->entrarComoCliente($cliente);

        $this->put(route('portal.auth.senha'), ['senha' => null])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($cliente->fresh()->temSenha());
        $this->assertNull($cliente->fresh()->senha_definida_em);

        // Sai de verdade: `flushSession()` limpa a sessao, mas o guard mantem
        // o usuario resolvido EM MEMORIA e continuaria respondendo.
        auth('portal')->logout();
        $this->flushSession();

        $this->post(route('portal.senha.entrar'), [
            'email' => $cliente->email, 'senha' => 'senha-boa-123',
        ])->assertSessionHasErrors('senha');

        $this->assertNull(auth('portal')->user());
    }

    #[Test]
    public function senha_curta_e_recusada(): void
    {
        $cliente = $this->cliente();
        $this->entrarComoCliente($cliente);

        $this->put(route('portal.auth.senha'), [
            'senha' => 'curta', 'senha_confirmation' => 'curta',
        ])->assertSessionHasErrors('senha');

        $this->assertFalse($cliente->fresh()->temSenha());
    }

    #[Test]
    public function confirmacao_diferente_e_recusada(): void
    {
        $cliente = $this->cliente();
        $this->entrarComoCliente($cliente);

        $this->put(route('portal.auth.senha'), [
            'senha' => 'senha-boa-123', 'senha_confirmation' => 'outra-coisa-123',
        ])->assertSessionHasErrors('senha');

        $this->assertFalse($cliente->fresh()->temSenha());
    }

    /** Sessao de equipe nao mexe na senha do cliente: a conta nao e dela. */
    #[Test]
    public function sessao_de_equipe_nao_muda_a_senha_do_cliente(): void
    {
        $empresa = $this->empresa();
        $membro = User::create([
            'name' => 'Admin', 'email' => 'a.'.uniqid().'@ecf.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'active' => true,
        ]);

        $token = app(\App\Services\Portal\PortalEquipeService::class)->emitir($membro, $empresa);
        $this->get(route('portal.equipe.entrar', ['t' => $token]));

        $this->put(route('portal.auth.senha'), [
            'senha' => 'invadindo-1234', 'senha_confirmation' => 'invadindo-1234',
        ])->assertForbidden();
    }

    /** O hash nunca chega ao navegador, nem no payload da pagina. */
    #[Test]
    public function o_payload_da_tela_nao_carrega_o_hash(): void
    {
        $cliente = $this->comSenha($this->cliente());
        $this->entrarComoCliente($cliente);

        $this->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('usuario.tem_senha', true)
                ->missing('usuario.password')
            );
    }

    /** Entra pelo fluxo real de codigo — o login sem senha. */
    private function entrarComoCliente(PortalUsuario $cliente): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->post(route('portal.codigo'), ['email' => $cliente->email]);

        $codigo = null;
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $cliente,
            \App\Notifications\PortalCodigoDeAcesso::class,
            function ($n) use (&$codigo) {
                $codigo = (new \ReflectionProperty($n, 'codigo'))->getValue($n);

                return true;
            }
        );

        $this->post(route('portal.validar'), ['email' => $cliente->email, 'codigo' => $codigo])
            ->assertRedirect(route('portal.auth.inicio'));
    }
}
