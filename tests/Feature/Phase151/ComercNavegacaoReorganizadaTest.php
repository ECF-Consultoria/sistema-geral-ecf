<?php

namespace Tests\Feature\Phase151;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Fase 151 Plano 08 (COMERC-02) — regressão da reorganização de navegação:
 * `Administrativo › Empresas` saiu do menu (D-16) e `Administrativo ›
 * Contratos` virou o módulo `Contrato` dentro do Comercial (D-15). Nenhuma
 * das duas decisões mexeu em rota/controller/página — só no `NAV_TREE` de
 * `resources/js/Layouts/AppLayout.jsx`. Esta suíte prova o que a mudança de
 * menu NÃO pode ter quebrado.
 *
 * É a cobertura que `151-VALIDATION.md` marcou como "⚠ conferir cobertura
 * atual" para D-16 — depois deste arquivo a linha da tabela tem teste próprio.
 */
class ComercNavegacaoReorganizadaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada — mesmo helper de ContratoAdminPermissaoTest. */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Navegação Teste',
            'slug'   => 'nav-teste-'.uniqid(),
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

    // ─── D-16 — a tela de Empresas do Administrativo sobrevive fora do menu ───

    /** D-16: a rota nomeada admin.empresas continua registrada mesmo sem item de menu apontando para ela. */
    public function test_rota_admin_empresas_continua_registrada(): void
    {
        $rota = Route::getRoutes()->getByName('admin.empresas');

        $this->assertNotNull($rota, 'A rota admin.empresas precisa continuar existindo (D-16) — o item saiu só do menu.');
    }

    /** D-16: um admin autenticado continua recebendo 200 por URL direta, mesmo com o item fora do menu. */
    public function test_admin_acessa_empresas_por_url_direta(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.empresas'));

        $response->assertOk();
    }

    /** D-16: a rota de update continua existindo e sob os mesmos middlewares de antes (role:admin). */
    public function test_rota_admin_empresas_update_continua_sob_role_admin(): void
    {
        $rota = Route::getRoutes()->getByName('admin.empresas.update');

        $this->assertNotNull($rota, 'A rota admin.empresas.update precisa continuar existindo (D-16).');

        $middlewares = $rota->gatherMiddleware();
        $this->assertContains('role:admin', $middlewares, 'admin.empresas.update precisa continuar sob role:admin, como antes da reorganização.');
    }

    /** D-16: AdminController ainda declara os métodos e a página React ainda existe no disco — nada foi apagado junto com a mudança de menu. */
    public function test_controller_e_pagina_de_empresas_nao_foram_apagados(): void
    {
        $reflection = new ReflectionClass(\App\Http\Controllers\AdminController::class);

        $this->assertTrue($reflection->hasMethod('empresas'), 'AdminController::empresas() precisa continuar existindo (D-16).');
        $this->assertTrue($reflection->hasMethod('updateEmpresa'), 'AdminController::updateEmpresa() precisa continuar existindo (D-16) — ele zera campos omitidos, por isso a remoção real é trabalho próprio.');

        $pagina = base_path('resources/js/Pages/Admin/Empresas.jsx');
        $this->assertFileExists($pagina, 'Pages/Admin/Empresas.jsx precisa continuar existindo no disco (D-16) — só o item de menu saiu.');
    }

    // ─── D-15 — a permission de Contrato sobreviveu à mudança de grupo de menu ───

    /** D-15: admin.contratos.index continua com permission:admin.contratos e nunca role:admin, mesmo tendo mudado de grupo no menu. */
    public function test_admin_contratos_index_continua_com_a_permission_certa_e_sem_role_admin(): void
    {
        $rota = Route::getRoutes()->getByName('admin.contratos.index');

        $this->assertNotNull($rota, 'A rota admin.contratos.index precisa existir.');

        $middlewares = $rota->gatherMiddleware();

        $temPermissionCerta = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:'.Permissions::ADMIN_CONTRATOS
        );
        $temRoleAdmin = collect($middlewares)->contains(
            fn (string $m) => $m === 'role:admin'
        );

        $this->assertTrue($temPermissionCerta, 'admin.contratos.index precisa continuar sob permission:admin.contratos (D-15) — middlewares: '.implode(', ', $middlewares));
        $this->assertFalse($temRoleAdmin, 'admin.contratos.index NUNCA pode ficar sob role:admin (D-15) — middlewares: '.implode(', ', $middlewares));
    }

    /** D-15: a mudança de grupo de menu não pode ter trocado a permission por comercial.cadastrar_empresa. */
    public function test_admin_contratos_index_nao_usa_a_permission_do_cadastro_comercial(): void
    {
        $rota = Route::getRoutes()->getByName('admin.contratos.index');
        $middlewares = $rota->gatherMiddleware();

        $temPermissionErrada = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:comercial.cadastrar_empresa'
        );

        $this->assertFalse($temPermissionErrada, 'admin.contratos.index não pode ter herdado permission:comercial.cadastrar_empresa por ter entrado no grupo Comercial do menu — a rota não mudou de lugar, só o item do menu.');
    }

    /** D-15: usuário com admin.contratos via setor (sem role:admin) continua recebendo 200 — a permissão por setor continua valendo depois da mudança de menu. */
    public function test_usuario_com_admin_contratos_via_setor_continua_acessando(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $response = $this->actingAs($user)->get(route('admin.contratos.index'));

        $response->assertOk();
    }

    // ─── Item novo — Entrada ───

    /** comercial.entrada.index existe e aponta para ComercialEntradaController@index. */
    public function test_rota_comercial_entrada_index_existe_e_aponta_pro_controller_certo(): void
    {
        $rota = Route::getRoutes()->getByName('comercial.entrada.index');

        $this->assertNotNull($rota, 'A rota comercial.entrada.index precisa existir.');
        $this->assertSame(
            \App\Http\Controllers\ComercialEntradaController::class.'@index',
            $rota->getActionName(),
            'comercial.entrada.index precisa apontar para ComercialEntradaController@index.'
        );
    }
}
