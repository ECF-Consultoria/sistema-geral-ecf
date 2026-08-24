<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\PortalCodigoAcesso;
use App\Models\PortalUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Excluir um acesso do portal — a via definitiva, ao lado de desativar.
 *
 * As duas coexistem porque respondem a coisas diferentes:
 *  - **desativar** tira o acesso e devolve depois, preservando o vínculo entre a
 *    pessoa e o que ela fez;
 *  - **excluir** é para o cadastro feito por engano, ou para quem nunca deveria
 *    ter entrado na lista.
 *
 * O que estes testes protegem é o que sobra depois do delete: a trilha de
 * auditoria não pode sumir junto.
 */
class ExcluirAcessoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'Empresa '.uniqid(),
            'cnpj' => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function usuario(Company $empresa): PortalUsuario
    {
        $u = PortalUsuario::create([
            'nome' => 'Cliente '.uniqid(), 'email' => 'c.'.uniqid().'@empresa.test', 'ativo' => true,
        ]);
        $u->empresas()->attach($empresa->id, ['principal' => true]);

        return $u->fresh();
    }

    #[Test]
    public function admin_exclui_o_acesso_de_vez(): void
    {
        $usuario = $this->usuario($this->empresa());

        $this->actingAs($this->admin())
            ->delete(route('portal.usuarios.destroy', $usuario))
            ->assertRedirect();

        $this->assertDatabaseMissing('portal_usuarios', ['id' => $usuario->id]);
    }

    /** Os vínculos e os códigos vão junto — senão sobrariam órfãos. */
    #[Test]
    public function excluir_leva_vinculos_e_codigos_junto(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuario($empresa);

        PortalCodigoAcesso::create([
            'portal_usuario_id' => $usuario->id, 'codigo_hash' => 'x',
            'sessao_id' => 's', 'expira_em' => now()->addMinutes(10),
        ]);

        $this->actingAs($this->admin())->delete(route('portal.usuarios.destroy', $usuario));

        $this->assertDatabaseMissing('portal_usuario_empresa', ['portal_usuario_id' => $usuario->id]);
        $this->assertDatabaseMissing('portal_codigos_acesso', ['portal_usuario_id' => $usuario->id]);
    }

    /**
     * A trilha sobrevive ao sujeito.
     *
     * O registro é escrito ANTES do delete, com nome, e-mail e empresas nas
     * propriedades — sem isso, a resposta a "quem tinha acesso a esta empresa em
     * agosto?" sumiria junto com a linha.
     */
    #[Test]
    public function a_auditoria_guarda_quem_foi_excluido_e_de_onde(): void
    {
        $empresa = $this->empresa();
        $usuario = $this->usuario($empresa);
        $nome = $usuario->nome;
        $email = $usuario->email;

        $this->actingAs($this->admin())->delete(route('portal.usuarios.destroy', $usuario));

        $log = DB::table('activity_log')
            ->where('log_name', 'portal')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString($nome, $log->description);

        $props = json_decode($log->properties, true);
        $this->assertSame('exclusao', $props['evento']);
        $this->assertSame($email, $props['email']);
        $this->assertContains($empresa->name, $props['empresas']);
    }

    /** Quem foi excluído não entra mais — nem pedindo código novo. */
    #[Test]
    public function excluido_nao_consegue_mais_pedir_codigo(): void
    {
        $usuario = $this->usuario($this->empresa());
        $email = $usuario->email;

        $this->actingAs($this->admin())->delete(route('portal.usuarios.destroy', $usuario));

        $this->post(route('portal.codigo'), ['email' => $email])->assertRedirect();

        $this->assertSame(0, PortalCodigoAcesso::count());
    }

    /** Desativar continua sendo o caminho reversível — não apaga nada. */
    #[Test]
    public function desativar_continua_preservando_o_cadastro(): void
    {
        $usuario = $this->usuario($this->empresa());

        $this->actingAs($this->admin())
            ->put(route('portal.usuarios.update', $usuario), ['ativo' => false]);

        $this->assertDatabaseHas('portal_usuarios', ['id' => $usuario->id, 'ativo' => false]);
        $this->assertDatabaseHas('portal_usuario_empresa', ['portal_usuario_id' => $usuario->id]);
    }

    #[Test]
    public function nao_admin_nao_exclui(): void
    {
        $usuario = $this->usuario($this->empresa());

        $consultor = User::create([
            'name' => 'Consultor', 'email' => 'c.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);

        $this->actingAs($consultor)
            ->delete(route('portal.usuarios.destroy', $usuario))
            ->assertForbidden();

        $this->assertDatabaseHas('portal_usuarios', ['id' => $usuario->id]);
    }
}
