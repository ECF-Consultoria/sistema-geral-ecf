<?php

namespace Tests\Feature\Phase133;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 133 Plano 03 (D-04) — a prop `bloqueio_ativo` em
 * `ContratoAdminController::index()`.
 *
 * A "lista de empresas retidas" já existe desde a Fase 131. Esta suíte
 * prova apenas o dado NOVO desta fase: um booleano calculado no servidor,
 * lido do único ponto autorizado (`EmpresaOperacionalRouter::bloqueioAtivo()`),
 * nos três estados possíveis da chave em `configuracoes` — e que nenhuma
 * outra prop da tela mudou por causa disso.
 *
 * Conferência por RECONSULTA às props Inertia, nunca por stdout — mesma
 * disciplina da Fase 131/132/133.
 */
class FaixaBloqueioContratosTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers (copiados do molde de Phase131\ContratoAdminListaTest) ───

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoComContrato(string $nome = 'Gestão de Tráfego (faixa bloqueio)'): Servico
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

    /** Garante que a listagem não venha vazia — cada cenário precisa de ao menos uma empresa retida. */
    private function comUmaEmpresaRetida(): void
    {
        $servico = $this->servicoComContrato();
        $empresa = $this->empresa(['name' => 'Empresa Faixa Bloqueio']);
        $this->vincularServico($empresa, $servico);
    }

    // ─── Testes ─────────────────────────────────────────────────────────

    public function test_prop_bloqueio_ativo_e_verdadeira_com_a_chave_ligada(): void
    {
        $this->comUmaEmpresaRetida();

        \App\Models\Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Contratos')
            ->where('bloqueio_ativo', true)
        );
    }

    public function test_prop_bloqueio_ativo_e_falsa_com_a_chave_desligada(): void
    {
        $this->comUmaEmpresaRetida();

        \App\Models\Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '0');

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Contratos')
            ->where('bloqueio_ativo', false)
        );
    }

    public function test_prop_bloqueio_ativo_e_falsa_quando_a_chave_nunca_foi_gravada(): void
    {
        $this->comUmaEmpresaRetida();

        // Nenhuma linha da chave em `configuracoes` — estado atual de produção.

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Contratos')
            ->where('bloqueio_ativo', false)
        );
    }

    public function test_as_demais_props_da_tela_continuam_intactas(): void
    {
        $this->comUmaEmpresaRetida();

        \App\Models\Configuracao::set(EmpresaOperacionalRouter::CHAVE_BLOQUEIO, '1');

        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('linhas', $props);
        $this->assertArrayHasKey('filters', $props);
        $this->assertArrayHasKey('resumo', $props);
        $this->assertArrayHasKey('sem_contrato_count', $props);
        $this->assertArrayHasKey('bloqueio_ativo', $props);
        $this->assertTrue($props['bloqueio_ativo']);
    }
}
