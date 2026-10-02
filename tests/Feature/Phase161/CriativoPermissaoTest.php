<?php

namespace Tests\Feature\Phase161;

use App\Models\Configuracao;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\Creative\CreativePermissao;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CreativePermissao` — prova de OPS-04 (Fase 161): permissão explícita de
 * planejar/gerar/regenerar/aprovar, CONFERIDA no servidor, além do gate
 * `role:admin` do grupo de rotas.
 *
 * O caso mais importante desta suíte é o do admin barrado por uma lista não
 * vazia que não o contém — é esta asserção que prova que `role:admin` NÃO
 * basta (sem ela, `User::hasPermission()` curto-circuitaria `true` e
 * qualquer admin passaria, tornando OPS-04 inoperante).
 */
class CriativoPermissaoTest extends TestCase
{
    use RefreshDatabase;

    private function permissao(): CreativePermissao
    {
        return app(CreativePermissao::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada (molde Phase131). */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Criativos Teste',
            'slug'   => 'criativos-teste-' . uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    public function test_nao_admin_com_a_chave_concedida_pode_gerar(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::MLB_CRIATIVOS_IA);

        $this->assertTrue($this->permissao()->podeGerar($user));
    }

    public function test_nao_admin_sem_a_chave_nao_pode_gerar(): void
    {
        $user = $this->naoAdmin();

        $this->assertFalse($this->permissao()->podeGerar($user));
    }

    public function test_admin_pode_gerar_com_lista_ausente(): void
    {
        $admin = $this->admin();

        $this->assertFalse(Configuracao::where('chave', CreativePermissao::CHAVE_LISTA)->exists());
        $this->assertTrue($this->permissao()->podeGerar($admin));
    }

    public function test_admin_pode_gerar_com_lista_vazia(): void
    {
        $admin = $this->admin();
        Configuracao::set(CreativePermissao::CHAVE_LISTA, '');

        $this->assertTrue($this->permissao()->podeGerar($admin));
    }

    /**
     * A prova de OPS-04: `role:admin` sozinho NÃO basta — a lista, quando
     * preenchida, barra até o admin que estiver de fora dela.
     */
    public function test_admin_fora_da_lista_nao_pode_gerar(): void
    {
        $admin = $this->admin();
        $outroAdmin = User::factory()->create(['role' => 'admin']);
        Configuracao::set(CreativePermissao::CHAVE_LISTA, (string) $outroAdmin->id);

        $this->assertFalse($this->permissao()->podeGerar($admin));
        $this->assertTrue($this->permissao()->podeGerar($outroAdmin));
    }

    public function test_admin_dentro_da_lista_pode_gerar(): void
    {
        $admin = $this->admin();
        Configuracao::set(CreativePermissao::CHAVE_LISTA, "99, {$admin->id} ,123");

        $this->assertTrue($this->permissao()->podeGerar($admin));
    }

    public function test_os_quatro_metodos_aplicam_a_mesma_regra(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::MLB_CRIATIVOS_IA);

        $this->assertTrue($this->permissao()->podePlanejar($user));
        $this->assertTrue($this->permissao()->podeGerar($user));
        $this->assertTrue($this->permissao()->podeRegenerar($user));
        $this->assertTrue($this->permissao()->podeAprovar($user));
    }

    public function test_exigir_aborta_com_403_e_mensagem_pt_br_quando_nao_autorizado(): void
    {
        $user = $this->naoAdmin();

        try {
            $this->permissao()->exigir($user, 'gerar');
            $this->fail('Esperava HttpException 403.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('permissão', $e->getMessage());
        }
    }

    public function test_exigir_nao_aborta_quando_autorizado(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::MLB_CRIATIVOS_IA);

        $this->permissao()->exigir($user, 'planejar');

        $this->assertTrue(true);
    }
}
