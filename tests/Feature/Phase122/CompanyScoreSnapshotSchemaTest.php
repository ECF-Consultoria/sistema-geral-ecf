<?php

namespace Tests\Feature\Phase122;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova de schema da tabela `desempenho_company_score_snapshots` (SNAP-02) —
 * colunas, chave única, precisão decimal e ausência de tipo restrito por
 * lista fixa (armadilha do CHECK enforçado no SQLite dos testes).
 */
class CompanyScoreSnapshotSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tabela_existe_com_todas_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('desempenho_company_score_snapshots'));

        $this->assertTrue(Schema::hasColumns('desempenho_company_score_snapshots', [
            'id',
            'user_id',
            'company_id',
            'mes_referencia',
            'company_name',
            'fonte_financeira',
            'status',
            'nps_pontos',
            'faturamento_atual',
            'faturamento_anterior',
            'faturamento_var_pct',
            'faturamento_pontos',
            'margem_pct_atual',
            'margem_pct_anterior',
            'margem_var_pp',
            'margem_pontos',
            'componentes_presentes',
            'nota_empresa',
            'nota_empresa_parcial',
            'quality',
            'origem',
            'gerado_em',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function chave_unica_barra_duplicata_de_user_company_mes(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        DB::table('desempenho_company_score_snapshots')->insert([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('desempenho_company_score_snapshots')->insert([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'origem'         => 'warm_cache',
            'gerado_em'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    #[Test]
    public function mudar_so_a_competencia_nao_lanca_excecao(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        DB::table('desempenho_company_score_snapshots')->insert([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('desempenho_company_score_snapshots')->insert([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-07-01',
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->assertSame(2, DB::table('desempenho_company_score_snapshots')
            ->where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->count());
    }

    #[Test]
    public function round_trip_de_precisao_na_margem_var_pp(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        DesempenhoCompanyScoreSnapshot::create([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'margem_var_pp'  => 0.004321,
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
        ]);

        $row = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->first();

        $this->assertEqualsWithDelta(0.004321, $row->margem_var_pp, 1e-6);
    }

    #[Test]
    public function round_trip_de_quality_via_cast_array(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        $quality = [
            'revenue_diff_source' => 'native',
            'margin_diff_source'  => 'fallback',
            'margin_source'       => 'placeholder_shopee',
            'motivos'             => ['faturamento_sem_baseline', 'margem_pp_indisponivel'],
        ];

        DesempenhoCompanyScoreSnapshot::create([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'quality'        => $quality,
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
        ]);

        $row = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->where('company_id', $company->id)
            ->first();

        $this->assertSame($quality, $row->quality);
    }

    #[Test]
    public function status_aceita_string_arbitraria_sem_tipo_restrito(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        $row = DesempenhoCompanyScoreSnapshot::create([
            'user_id'        => $user->id,
            'company_id'     => $company->id,
            'mes_referencia' => '2026-06-01',
            'status'         => 'um_status_qualquer_nao_previsto',
            'origem'         => 'consolidar_mes',
            'gerado_em'      => now(),
        ]);

        $this->assertSame('um_status_qualquer_nao_previsto', $row->fresh()->status);
    }
}
