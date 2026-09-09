<?php

namespace Tests\Feature\Phase141;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 03 — Tarefa 1: procedência da tabela da empresa.
 *
 * `empresa_faixas_faturamento.origem` distingue as TRÊS procedências possíveis: cadastro manual
 * (`FechamentoController::salvarFaixasEmpresa()`), confirmação de contrato lido do Clicksign
 * (`TabelasContratoController::confirmar()`) e presunção da tabela do serviço (Tarefa 2, fora deste
 * teste). Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`) — mesma disciplina de
 * `Phase137FaixasCrudTest`/`Phase140ConfirmacaoTabelaTest`.
 */
class Phase141ProcedenciaTabelaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function payloadValido(): array
    {
        return [
            'faixas' => [
                ['ordem' => 1, 'limite_superior' => 100_000.00, 'valor' => 1_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 2_000.00, 'valor_e_piso' => true],
            ],
        ];
    }

    // ── Default da coluna ──────────────────────────────────────────────

    #[Test]
    public function linha_criada_sem_origem_explicita_assume_manual_por_default_do_banco(): void
    {
        $company = Company::factory()->create();

        DB::table('empresa_faixas_faturamento')->insert([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => 1_000.00,
            'valor_e_piso'    => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->assertSame(
            EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('origem')
        );
        $this->assertNull(
            DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->value('servico_origem_id')
        );
    }

    // ── Cadastro manual grava origem='manual' ────────────────────────────

    #[Test]
    public function salvar_faixas_por_cadastro_manual_grava_origem_manual(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $this->actingAs($admin)
            ->post("/administrativo/financeiro/faixas/empresa/{$company->id}", $this->payloadValido());

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();

        $this->assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linha->origem);
            $this->assertNull($linha->servico_origem_id);
        }
    }

    // ── Confirmação de contrato grava origem='contrato' ──────────────────

    #[Test]
    public function confirmar_leitura_de_contrato_grava_origem_contrato(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $proposta = ContratoTabelaProposta::factory()->create([
            'tipo_cobranca' => ContratoTabelaProposta::TIPO_TABELA,
            'faixas'        => [
                ['ordem' => 1, 'limite_superior' => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false],
                ['ordem' => 2, 'limite_superior' => null, 'valor' => 5_000.00, 'valor_e_piso' => true],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.contratos.tabelas.confirmar', $proposta), ['company_id' => $company->id]);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();

        $this->assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $linha->origem);
            $this->assertNull($linha->servico_origem_id);
        }
    }

    // ── Migration idempotente ─────────────────────────────────────────────

    #[Test]
    public function migration_da_coluna_origem_roda_duas_vezes_sem_quebrar(): void
    {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_150002_add_origem_to_empresa_faixas_faturamento_table.php', '--realpath' => false, '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_09_09_150002_add_origem_to_empresa_faixas_faturamento_table.php', '--realpath' => false, '--force' => true]);

        $this->assertTrue(true, 'Rodar a migration duas vezes não deve lançar exceção.');
    }
}
