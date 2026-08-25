<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\PortalTicketEquipe;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Portal\PortalContexto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A equipe da ECF entrando no portal de um cliente.
 *
 * Os dois caminhos ruins que isto substitui:
 *
 *  - **pedir o código do cliente** — uma pessoa usando a credencial de outra;
 *    depois disso o histórico não distingue mais quem fez o quê;
 *  - **manter o link por token para uso interno** — que é justamente o link
 *    repassável que o login veio substituir.
 *
 * O que estes testes protegem é o que separa este caminho daqueles: a
 * identidade de quem entrou continua sendo a dela, a passagem vale quase nada,
 * e a permissão é reconferida a cada requisição.
 */
class EquipeNoPortalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    /** Analista/estrategista: nao-admin, com `core.onboarding`. */
    private function analista(): User
    {
        $user = User::create([
            'name' => 'Analista '.uniqid(), 'email' => 'an.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor '.uniqid(), 'slug' => 'setor-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([Permissions::CORE_ONBOARDING, Permissions::CORE_EMPRESAS] as $key) {
            DB::table('setor_permissoes')->insert([
                'setor_id' => $setorId, 'permission_key' => $key,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('user_setores')->insert([
            'user_id' => $user->id, 'setor_id' => $setorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function empresa(string $nome = 'Empresa'): Company
    {
        return Company::create([
            'name' => $nome.' '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function naCarteira(User $user, Company $empresa): void
    {
        DB::table('company_users')->insert([
            'company_id' => $empresa->id, 'user_id' => $user->id, 'role' => 'consultor',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** O token em claro que a URL de entrada carrega. */
    private function ticketPara(User $membro, Company $empresa): string
    {
        return app(\App\Services\Portal\PortalEquipeService::class)
            ->emitir($membro, $empresa, '127.0.0.1');
    }

    /** Pede o codigo de acesso do cliente e le o que foi gerado. */
    private function codigoDe(PortalUsuario $usuario): string
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->post(route('portal.codigo'), ['email' => $usuario->email])->assertRedirect();

        $codigo = null;
        \Illuminate\Support\Facades\Notification::assertSentTo(
            $usuario,
            \App\Notifications\PortalCodigoDeAcesso::class,
            function ($n) use (&$codigo) {
                $codigo = (new \ReflectionProperty($n, 'codigo'))->getValue($n);

                return true;
            }
        );

        return $codigo;
    }

    // ─── Emissao ────────────────────────────────────────────────────────

    #[Test]
    public function admin_abre_o_portal_de_qualquer_empresa(): void
    {
        $empresa = $this->empresa();

        $this->actingAs($this->admin())
            ->get(route('companies.portal.abrir', $empresa))
            ->assertRedirect();

        $this->assertDatabaseCount('portal_tickets_equipe', 1);
    }

    #[Test]
    public function analista_da_carteira_abre_o_portal(): void
    {
        $empresa = $this->empresa();
        $analista = $this->analista();
        $this->naCarteira($analista, $empresa);

        $this->actingAs($analista)
            ->get(route('companies.portal.abrir', $empresa))
            ->assertRedirect();
    }

    /** A regua e a mesma do onboarding: fora da carteira, nao entra. */
    #[Test]
    public function analista_de_fora_da_carteira_nao_abre(): void
    {
        $this->actingAs($this->analista())
            ->get(route('companies.portal.abrir', $this->empresa()))
            ->assertForbidden();

        $this->assertDatabaseCount('portal_tickets_equipe', 0);
    }

    /** O token nao e guardado em claro — vazar a tabela nao devolve passagens. */
    #[Test]
    public function a_tabela_guarda_o_hash_e_nao_o_token(): void
    {
        $token = $this->ticketPara($this->admin(), $this->empresa());

        $this->assertDatabaseMissing('portal_tickets_equipe', ['token_hash' => $token]);
        $this->assertDatabaseHas('portal_tickets_equipe', ['token_hash' => hash('sha256', $token)]);
    }

    // ─── Entrada ────────────────────────────────────────────────────────

    #[Test]
    public function a_passagem_valida_abre_uma_sessao_de_equipe(): void
    {
        $membro = $this->admin();
        $empresa = $this->empresa();

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]))
            ->assertRedirect(route('portal.auth.inicio'));

        $this->assertSame($membro->id, session(PortalContexto::SESSAO_EQUIPE));
        $this->assertSame($empresa->id, session('portal_empresa_id'));
    }

    #[Test]
    public function a_passagem_serve_uma_vez_so(): void
    {
        $token = $this->ticketPara($this->admin(), $this->empresa());

        $this->get(route('portal.equipe.entrar', ['t' => $token]))
            ->assertRedirect(route('portal.auth.inicio'));

        $this->flushSession();

        $this->get(route('portal.equipe.entrar', ['t' => $token]))
            ->assertRedirect(route('portal.entrada'));

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    #[Test]
    public function passagem_vencida_nao_entra(): void
    {
        $token = $this->ticketPara($this->admin(), $this->empresa());

        PortalTicketEquipe::query()->update(['expira_em' => now()->subMinute()]);

        $this->get(route('portal.equipe.entrar', ['t' => $token]))
            ->assertRedirect(route('portal.entrada'));

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    #[Test]
    public function passagem_inventada_nao_entra(): void
    {
        $this->get(route('portal.equipe.entrar', ['t' => str_repeat('a', 64)]))
            ->assertRedirect(route('portal.entrada'));

        $this->assertNull(session(PortalContexto::SESSAO_EQUIPE));
    }

    /**
     * Entrar como equipe derruba a sessao de cliente que estivesse aberta.
     *
     * Sem isto, o analista que testou com a conta de um cliente ficaria com as
     * duas sessoes sobrepostas — e a proxima acao sairia no nome errado, que e
     * o problema inteiro que este mecanismo existe para evitar.
     */
    #[Test]
    public function entrar_como_equipe_derruba_a_sessao_do_cliente(): void
    {
        $empresa = $this->empresa();

        $cliente = PortalUsuario::create([
            'nome' => 'Cliente', 'email' => 'c.'.uniqid().'@empresa.test', 'ativo' => true,
        ]);
        $cliente->empresas()->attach($empresa->id, ['principal' => true]);

        // Login REAL, pelo fluxo do cliente. `be()`/`actingAs()` nao servem
        // aqui: os dois trocam o guard PADRAO da aplicacao para `portal`, e ai
        // `$request->user()` do sistema interno passaria a devolver um
        // PortalUsuario — um estado que nao existe em producao, e que faria o
        // teste medir outra coisa.
        $codigo = $this->codigoDe($cliente);
        $this->post(route('portal.validar'), ['email' => $cliente->email, 'codigo' => $codigo])
            ->assertRedirect();

        $membro = $this->admin();
        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]));

        // A sessao do guard morreu: `Auth::logout()` apaga a chave `login_portal_*`.
        // Conferir isso, e nao `auth('portal')->user()`, porque o `be()` do teste
        // mantem o usuario resolvido EM MEMORIA — ele responderia mesmo com a
        // sessao ja invalidada, e o teste passaria por engano.
        $chavesDeLogin = array_filter(
            array_keys(session()->all()),
            fn ($k) => str_starts_with($k, 'login_portal')
        );

        $this->assertSame([], $chavesDeLogin);
        $this->assertNotNull(session(PortalContexto::SESSAO_EQUIPE));

        // E a proxima requisicao e tratada como EQUIPE, nao como o cliente.
        $this->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('usuario.equipe', true)
                ->where('usuario.nome', $membro->name)
            );
    }

    /** Nao vira cliente: a lista de acessos do portal nao muda. */
    #[Test]
    public function a_equipe_nao_vira_um_acesso_do_portal(): void
    {
        $this->get(route('portal.equipe.entrar', [
            't' => $this->ticketPara($this->admin(), $this->empresa()),
        ]));

        $this->assertDatabaseCount('portal_usuarios', 0);
    }

    // ─── Revalidacao a cada requisicao ──────────────────────────────────

    /**
     * Tirar a empresa da carteira derruba a sessao na requisicao SEGUINTE.
     *
     * E a mesma disciplina do lado do cliente, e pela mesma razao: uma sessao
     * de 30 dias que so confere o vinculo no login e uma permissao que ninguem
     * consegue revogar.
     */
    #[Test]
    public function perder_a_empresa_da_carteira_derruba_a_sessao(): void
    {
        $empresa = $this->empresa();
        $analista = $this->analista();
        $this->naCarteira($analista, $empresa);

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($analista, $empresa)]));
        $this->get(route('portal.auth.inicio'))->assertOk();

        DB::table('company_users')->where('user_id', $analista->id)->delete();

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
    }

    #[Test]
    public function usuario_desativado_na_ecf_perde_a_sessao(): void
    {
        $empresa = $this->empresa();
        $membro = $this->admin();

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]));
        $this->get(route('portal.auth.inicio'))->assertOk();

        $membro->update(['active' => false]);

        $this->get(route('portal.auth.inicio'))->assertRedirect(route('portal.entrada'));
    }

    // ─── O que a equipe faz sai no nome dela ────────────────────────────

    #[Test]
    public function a_equipe_ve_o_portal_e_a_tela_sabe_que_e_equipe(): void
    {
        $empresa = $this->empresa();
        $membro = $this->admin();

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]));

        $this->get(route('portal.auth.inicio'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('usuario.equipe', true)
                ->where('usuario.nome', $membro->name)
                ->where('empresas_disponiveis', [])
            );
    }

    /**
     * Card movido pela equipe fica marcado como movido pela EQUIPE.
     *
     * E o que responde "o cliente mexeu nisso ou fomos nos?" — a pergunta que
     * some quando alguem usa a credencial de outra pessoa.
     */
    #[Test]
    public function tarefa_movida_pela_equipe_registra_quem_moveu(): void
    {
        $empresa = $this->empresa();
        $membro = $this->admin();

        $ppa = \App\Models\Ppa::create([
            'escopo' => \App\Models\Ppa::ESCOPO_GERAL, 'company_id' => $empresa->id,
            'mentor_id' => $membro->id, 'title' => 'Plano', 'status' => 'sent',
        ]);
        $task = \App\Models\PpaTask::create([
            'ppa_id' => $ppa->id, 'title' => 'Tarefa', 'status' => 'todo', 'order' => 0,
        ]);

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]));

        $this->patchJson(route('portal.auth.ppa.tarefa', $task), ['status' => 'doing'])->assertOk();

        $log = DB::table('activity_log')->where('log_name', 'ppa')->orderByDesc('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('equipe', json_decode($log->properties, true)['origem']);
        $this->assertStringContainsString('equipe ECF', $log->description);
    }

    /** E a entrada fica registrada, com nome e empresa. */
    #[Test]
    public function entrar_no_portal_de_um_cliente_fica_registrado(): void
    {
        $empresa = $this->empresa();
        $membro = $this->admin();

        $this->get(route('portal.equipe.entrar', ['t' => $this->ticketPara($membro, $empresa)]));

        $log = DB::table('activity_log')
            ->where('log_name', 'portal')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame('equipe_entrou', $props['evento']);
        $this->assertSame($empresa->id, $props['company_id']);
        $this->assertStringContainsString($membro->name, $log->description);
    }
}
