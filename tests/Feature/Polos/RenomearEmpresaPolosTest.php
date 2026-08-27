<?php

namespace Tests\Feature\Polos;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Renomear empresa inline no Painel Polos e no Onboarding (/mlb/implementacao).
 *
 * Endpoint DEDICADO (`mlb.empresas.nome`) pelo mesmo motivo do cust_id: o
 * `updateEmpresa` espalha o payload validado e ZERA os campos omitidos — renomear
 * por lá exigiria reenviar a ficha inteira e apagaria o resto no caminho.
 *
 * @group polos
 */
class RenomearEmpresaPolosTest extends TestCase
{
    use RefreshDatabase;

    private function empresaPolos(array $opts = []): MlbEmpresa
    {
        return MlbEmpresa::create(array_merge([
            'nome'    => 'Nome Antigo ' . Str::random(4),
            'tipo'    => 'POLO',
            'projeto' => 'POLOS',
            'fase'    => 'M2',
            'polo'    => 'Arapongas',
            'estagio' => 'Não Listado',
        ], $opts));
    }

    /**
     * User não-admin membro de um setor com a permission indicada — mesmo padrão
     * de `tests/Feature/Phase135/OnboardingPainelAcoesTest.php`.
     */
    private function userComPermissao(string $permission): User
    {
        $slug = 'setor-' . Str::random(6);

        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Setor ' . $slug,
            'slug'       => $slug,
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => $permission,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }

    // ─── Caminho feliz ───────────────────────────────────────────────────────

    public function test_admin_renomeia_a_empresa(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => 'Loja Nova'])
            ->assertRedirect();

        $this->assertSame('Loja Nova', $empresa->fresh()->nome);
    }

    /**
     * Quem tem `mlb.implementacao` já CRIA MlbEmpresa em `implementacao.criar` —
     * negar só o nome deixaria o lápis da listagem de Onboarding tomando 403.
     */
    public function test_usuario_do_onboarding_renomeia_sem_ser_admin(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha']);
        $user    = $this->userComPermissao('mlb.implementacao');

        $this->assertTrue($user->hasPermission('mlb.implementacao'), 'setup: permission não concedida');

        $this->actingAs($user)
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => 'Loja Nova'])
            ->assertRedirect();

        $this->assertSame('Loja Nova', $empresa->fresh()->nome);
    }

    public function test_espacos_nas_pontas_sao_removidos(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => '  Loja Nova  ']);

        $this->assertSame('Loja Nova', $empresa->fresh()->nome);
    }

    // ─── Guardas ─────────────────────────────────────────────────────────────

    public function test_nome_vazio_nao_apaga_o_nome_atual(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha']);
        $admin   = User::factory()->create(['role' => 'admin']);

        // Vazio de verdade: barrado pela validação (`required`).
        $this->actingAs($admin)
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => ''])
            ->assertSessionHasErrors('nome');

        // Só-espaços: passa no `required`, mas o trim no controller recusa.
        $this->actingAs($admin)
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => '   '])
            ->assertSessionHasErrors('nome');

        $this->assertSame('Loja Velha', $empresa->fresh()->nome);
    }

    /**
     * A empresa do Polos é a MlbEmpresa. Quando existe `company_id`, os dois nomes
     * são independentes de propósito — renomear no Painel não mexe em /companies.
     */
    public function test_nao_altera_o_nome_da_company_vinculada(): void
    {
        $company = Company::factory()->create(['name' => 'Razão Social LTDA']);
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha', 'company_id' => $company->id]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => 'Loja Nova']);

        $this->assertSame('Loja Nova', $empresa->fresh()->nome);
        $this->assertSame('Razão Social LTDA', $company->fresh()->name);
    }

    /** Renomear NÃO pode encostar nos demais campos (o bug que motiva o endpoint dedicado). */
    public function test_nao_zera_os_demais_campos_da_empresa(): void
    {
        $empresa = $this->empresaPolos([
            'nome'    => 'Loja Velha',
            'cust_id' => '123456789',
            'gmail'   => 'loja@ecf.com',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => 'Loja Nova']);

        $fresco = $empresa->fresh();
        $this->assertSame('Loja Nova', $fresco->nome);
        $this->assertSame('123456789', $fresco->cust_id);
        $this->assertSame('loja@ecf.com', $fresco->gmail);
        $this->assertSame('M2', $fresco->fase);
        $this->assertSame('Arapongas', $fresco->polo);
    }

    public function test_usuario_sem_acesso_ao_modulo_mlb_recebe_403(): void
    {
        $empresa = $this->empresaPolos(['nome' => 'Loja Velha']);

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->patch(route('mlb.empresas.nome', $empresa->id), ['nome' => 'Loja Nova'])
            ->assertForbidden();

        $this->assertSame('Loja Velha', $empresa->fresh()->nome);
    }
}
