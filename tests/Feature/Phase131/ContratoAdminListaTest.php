<?php

namespace Tests\Feature\Phase131;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 131 Plano 03 (UI-01/D-04) — ContratoAdminController::index().
 *
 * Nasce na Task 1 (200 + componente, resumo com 7 chaves, sem_contrato_count
 * fora dele — o núcleo que a Task 1 entrega) e é COMPLETADO na Task 3
 * (filtros, busca, ordenação, ausência de dado de signatário), no MESMO
 * arquivo — regra do "teste nasce na mesma task do código que ele prova"
 * (armadilha do `--filter` sem match que sai 0 e varre a suíte).
 *
 * Mesma disciplina do resto da fase: conferência por RECONSULTA às props
 * Inertia + banco, nunca por stdout.
 */
class ContratoAdminListaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (lista admin)'): Servico
    {
        return Servico::create([
            'nome'           => $nome,
            'valor_padrao'   => 100,
            'tipo_cobranca'  => Servico::TIPO_MENSAL,
            'ativo'          => true,
            'setor'          => Servico::SETOR_PERFORMANCE,
            'exige_contrato' => true,
        ]);
    }

    private function empresa(array $overrides = []): Company
    {
        return Company::factory()->create(array_merge(['active' => true], $overrides));
    }

    private function vincularServico(Company $c, Servico $s): ContratoServico
    {
        return ContratoServico::create([
            'company_id'       => $c->id,
            'servico_id'       => $s->id,
            'valor_contratado' => 100,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    // ─── Task 1: o núcleo — 200 + componente, resumo de 7 chaves ──────────

    public function test_admin_recebe_200_e_o_componente_admin_contratos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Contratos'));
    }

    public function test_resumo_tem_exatamente_7_chaves_iguais_a_status_todos(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertIsArray($props['resumo']);
        $this->assertCount(7, $props['resumo']);
        $this->assertSame(
            ContratoAssinatura::STATUS_TODOS,
            array_keys($props['resumo']),
            'O resumo deve ter EXATAMENTE as 7 chaves de STATUS_TODOS, na mesma ordem.'
        );
    }

    public function test_sem_contrato_count_existe_e_fica_fora_do_resumo(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Sem Contrato Ainda']);
        $this->vincularServico($empresa, $servico);

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('sem_contrato_count', $props);
        $this->assertIsInt($props['sem_contrato_count']);
        $this->assertGreaterThanOrEqual(1, $props['sem_contrato_count']);
        $this->assertArrayNotHasKey('aguardando_administrativo', $props['resumo']);
    }
}
