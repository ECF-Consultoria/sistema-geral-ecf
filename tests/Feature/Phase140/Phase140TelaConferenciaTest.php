<?php

namespace Tests\Feature\Phase140;

use App\Models\ContratoTabelaProposta;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 140 Plano 05 (TAB-08/TAB-09) — Tarefa 1: tela de conferência das tabelas lidas do
 * Clicksign (`GET /administrativo/contratos/tabelas`).
 *
 * Mesmo molde de `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` para o gate de
 * permissão — a rota vive no MESMO grupo `permission:admin.contratos` (T-140-19).
 */
class Phase140TelaConferenciaTest extends TestCase
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

    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Tabelas Teste',
            'slug'   => 'tabelas-teste-'.uniqid(),
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

    #[Test]
    public function usuario_sem_a_permissao_recebe_403(): void
    {
        $response = $this->actingAs($this->naoAdmin())->get(route('admin.contratos.tabelas.index'));

        $response->assertForbidden();
    }

    #[Test]
    public function admin_recebe_200_via_short_circuit(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.tabelas.index'));

        $response->assertOk();
    }

    #[Test]
    public function usuario_com_a_permissao_via_setor_recebe_a_pagina_com_as_props_esperadas(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        ContratoTabelaProposta::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.contratos.tabelas.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/TabelasContrato')
            ->has('propostas')
            ->has('propostas.data')
            ->has('filters')
            ->where('filters.situacao', 'pendente')
            ->has('resumo.total')
            ->has('resumo.casaram_seguranca')
            ->has('resumo.duvidosos')
            ->has('resumo.valor_fixo')
            ->has('resumo.ilegiveis')
            ->has('empresas')
        );
    }

    #[Test]
    public function filtro_por_situacao_funciona(): void
    {
        $admin = $this->admin();

        ContratoTabelaProposta::factory()->count(2)->create();
        ContratoTabelaProposta::factory()->confirmada()->create();

        $pendentes = $this->actingAs($admin)->get(route('admin.contratos.tabelas.index', ['situacao' => 'pendente']));
        $pendentes->assertInertia(fn (Assert $page) => $page->has('propostas.data', 2));

        $confirmadas = $this->actingAs($admin)->get(route('admin.contratos.tabelas.index', ['situacao' => 'confirmada']));
        $confirmadas->assertInertia(fn (Assert $page) => $page->has('propostas.data', 1));
    }

    #[Test]
    public function situacao_fora_da_whitelist_cai_no_default_pendente(): void
    {
        $admin = $this->admin();

        ContratoTabelaProposta::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.contratos.tabelas.index', ['situacao' => 'algo-invalido']));

        $response->assertInertia(fn (Assert $page) => $page->where('filters.situacao', 'pendente'));
    }

    #[Test]
    public function proposta_ambigua_chega_com_a_marca_de_ambiguidade(): void
    {
        $admin = $this->admin();

        ContratoTabelaProposta::factory()->create([
            'ambiguo'    => true,
            'confianca'  => ContratoTabelaProposta::CONFIANCA_INCERTO,
            'candidatos' => [
                ['company_id' => 1, 'nome' => 'Empresa A', 'pontuacao' => 61.0],
                ['company_id' => 2, 'nome' => 'Empresa B', 'pontuacao' => 60.0],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.tabelas.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('propostas.data.0.ambiguo', true)
            ->where('propostas.data.0.confianca', 'incerto')
        );
    }

    #[Test]
    public function nenhuma_confianca_certo_vira_check_sem_avisar_ambiguidade_quando_nao_e_ambigua(): void
    {
        // Guarda de honestidade (D-05, UI-06): confiança 'certo' (CNPJ batendo) não precisa de
        // aviso de ambiguidade — só a incerta/provável ambígua precisa.
        $admin = $this->admin();

        ContratoTabelaProposta::factory()->comEmpresaCasada()->create(['ambiguo' => false]);

        $response = $this->actingAs($admin)->get(route('admin.contratos.tabelas.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('propostas.data.0.confianca', 'certo')
            ->where('propostas.data.0.ambiguo', false)
        );
    }
}
