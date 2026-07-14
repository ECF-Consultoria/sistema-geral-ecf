<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GATE bloqueante da Phase 76 (DEC-A1 a).
 *
 * Prova, via migrate:fresh (RefreshDatabase roda a migration nova em SQLite),
 * que a pivot `company_users` ganhou a coluna `servico_id`, que o unique passou
 * a 4 colunas e — crítico — que múltiplos NULL coexistem no unique (NULL ≠ NULL),
 * enquanto duas linhas com o MESMO `servico_id` não-NULL colidem.
 */
class MigrationCompanyUsersServicoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    public function test_coluna_servico_id_existe_apos_migrate(): void
    {
        $this->assertTrue(
            Schema::hasColumn('company_users', 'servico_id'),
            'A coluna company_users.servico_id deveria existir após a migration.'
        );
    }

    public function test_multiplos_null_do_mesmo_par_coexistem_no_unique(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        // Duas linhas idênticas (company, user, role) ambas com servico_id NULL.
        // Em MySQL/MariaDB E SQLite, NULL ≠ NULL no índice unique → coexistem.
        $this->inserirPivot($company->id, $user->id, 'consultor', null);
        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $this->assertSame(
            2,
            DB::table('company_users')
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('role', 'consultor')
                ->whereNull('servico_id')
                ->count(),
            'Duas linhas consolidadas (servico_id NULL) deveriam coexistir sem violar o unique.'
        );
    }

    public function test_mesmo_servico_id_nao_nulo_viola_unique(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();
        $servico = $this->criarServico('performance', true);

        $this->inserirPivot($company->id, $user->id, 'consultor', $servico);

        // Segunda linha idêntica com o MESMO servico_id não-NULL → viola o unique 4-col.
        $this->expectException(QueryException::class);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servico);
    }
}
