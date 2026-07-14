<?php

namespace Tests\Feature\Phase75;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature — Phase 75 Plan 75-02 (DEC-3).
 *
 * Prova o registro da permission key `shopee.empresas` no catálogo estático
 * `App\Support\Permissions`. Essa key é o único gate da aba "Empresas" da
 * Shopee (rota + menu), atribuível ao Setor Shopee e herdada por admin.
 *
 * Cenários cobertos:
 *  A. all()/isValid() reconhecem 'shopee.empresas'
 *  B. catalog() expõe o grupo 'Shopee' com a entrada da key
 *  C. Admin herda 'shopee.empresas' via short-circuit isAdmin() (sem atribuição
 *     explícita a nenhum setor)
 */
class Phase75PermissaoCatalogoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Teste A — a lista plana de keys válidas agrega a nova key.
     */
    public function test_all_e_isvalid_reconhecem_shopee_empresas(): void
    {
        // all() agrega automaticamente qualquer grupo novo de catalog()
        $this->assertContains('shopee.empresas', Permissions::all());
        // isValid() valida contra all() — a key entra na lista sozinha
        $this->assertTrue(Permissions::isValid('shopee.empresas'));
    }

    /**
     * Teste B — o catálogo agrupado tem o grupo 'Shopee' com a key.
     */
    public function test_catalog_tem_grupo_shopee_com_a_key(): void
    {
        $catalog = Permissions::catalog();

        // O novo grupo "Shopee" deve existir no catálogo
        $this->assertArrayHasKey('Shopee', $catalog);

        // O grupo deve ter ao menos uma entrada cujo key === 'shopee.empresas'
        $keys = array_column($catalog['Shopee'], 'key');
        $this->assertContains('shopee.empresas', $keys);
    }

    /**
     * Teste C — herança do admin: recebe a key sem atribuição explícita a setor.
     */
    public function test_admin_herda_shopee_empresas_sem_atribuicao_explicita(): void
    {
        $admin = User::create([
            'name'     => 'Admin Phase75-02 ' . uniqid(),
            'email'    => 'admin.p75-02.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);

        // short-circuit isAdmin() concede TODAS as keys — sem tocar setor_permissoes
        $this->assertTrue($admin->hasPermission('shopee.empresas'));
    }
}
