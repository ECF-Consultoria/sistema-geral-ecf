<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GATE bloqueante da Phase 76 (DEC-A1 b).
 *
 * Prova a data-migration `migrarLinhasExistentes()`:
 *  - empresa COM contrato performance ativo → a linha consolidada (servico_id NULL)
 *    recebe o servico_id desse serviço;
 *  - empresa SEM contrato performance (ou com contrato INATIVO) → permanece NULL
 *    (nunca inventar serviço — DEC-A1);
 *  - idempotência: rodar 2× não altera nada (filtro whereNull) → retorna 0 afetadas.
 *
 * O método vive na classe anônima retornada pelo arquivo da migration e é PÚBLICO
 * justamente para ser reinvocável aqui (prova de idempotência sem re-migrate).
 */
class DataMigrationServicoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * Carrega a classe anônima da migration para chamar migrarLinhasExistentes().
     */
    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_14_000001_add_servico_id_to_company_users.php'
        );
    }

    public function test_backfill_preenche_servico_id_do_performance_ativo(): void
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $user = User::factory()->create();
        // Linha consolidada legada (servico_id NULL) — estado pré-migração.
        $rowId = $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $this->migration()->migrarLinhasExistentes();

        $this->assertSame(
            $servicoPerf,
            (int) DB::table('company_users')->where('id', $rowId)->value('servico_id'),
            'A linha da empresa com performance ativo deveria receber o servico_id do performance.'
        );
    }

    public function test_empresa_sem_performance_permanece_null(): void
    {
        // Empresa só com Shopee (sem contrato performance) → NULL preservado.
        $company       = Company::factory()->create();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);

        $user  = User::factory()->create();
        $rowId = $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $this->migration()->migrarLinhasExistentes();

        $this->assertNull(
            DB::table('company_users')->where('id', $rowId)->value('servico_id'),
            'Empresa sem contrato performance ativo deveria manter servico_id NULL.'
        );
    }

    public function test_contrato_performance_inativo_nao_migra(): void
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        // Contrato INATIVO — não entra no mapa da data-migration.
        $this->criarContrato($company->id, $servicoPerf, false);

        $user  = User::factory()->create();
        $rowId = $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $this->migration()->migrarLinhasExistentes();

        $this->assertNull(
            DB::table('company_users')->where('id', $rowId)->value('servico_id'),
            'Contrato performance INATIVO não deveria migrar a linha (permanece NULL).'
        );
    }

    public function test_data_migration_e_idempotente(): void
    {
        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $user = User::factory()->create();
        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $m = $this->migration();

        // 1ª passada preenche as linhas consolidadas.
        $primeira = $m->migrarLinhasExistentes();
        $this->assertGreaterThan(0, $primeira, 'A 1ª passada deveria migrar ao menos 1 linha.');

        // 2ª passada: whereNull não casa mais nada → 0 linhas afetadas.
        $segunda = $m->migrarLinhasExistentes();
        $this->assertSame(0, $segunda, 'A data-migration deveria ser idempotente (0 linhas na 2ª passada).');
    }
}
