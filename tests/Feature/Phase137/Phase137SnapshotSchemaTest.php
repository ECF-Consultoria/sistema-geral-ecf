<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoReconsolidacao;
use App\Models\FechamentoSnapshot;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 137 Plano 02 — cobre as três tabelas novas de congelamento do
 * fechamento mensal: schema, unique por competência, cascade de exclusão
 * e round-trip de JSON. Nenhuma lógica de cálculo é testada aqui — isso é
 * do writer do plano 05.
 */
class Phase137SnapshotSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_tres_tabelas_existem_com_as_colunas_declaradas(): void
    {
        $this->assertTrue(Schema::hasTable('fechamento_snapshots'));
        $this->assertTrue(Schema::hasColumns('fechamento_snapshots', [
            'id', 'company_id', 'mes_referencia', 'company_name',
            'faturamento_ml', 'faturamento_shopee', 'faturamento_total',
            'company_group_id', 'servico_id', 'tabela_origem',
            'faixa_ordem', 'faixa_aplicada', 'valor_faixa', 'valor_faixa_e_piso',
            'faixa_limite_inferior', 'faixa_limite_superior', 'cobranca_mensal',
            'evolucao', 'estado', 'origem', 'gerado_em', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('fechamento_grupo_snapshots'));
        $this->assertTrue(Schema::hasColumns('fechamento_grupo_snapshots', [
            'id', 'company_group_id', 'mes_referencia', 'grupo_name',
            'faturamento_ml', 'faturamento_shopee', 'faturamento_total',
            'servico_id', 'tabela_origem', 'faixa_ordem', 'faixa_aplicada',
            'valor_faixa', 'valor_faixa_e_piso', 'faixa_limite_inferior',
            'faixa_limite_superior', 'cobranca_mensal', 'evolucao', 'estado',
            'empresas_count', 'empresa_ancora_id', 'tabelas_divergentes',
            'origem', 'gerado_em', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('fechamento_reconsolidacoes'));
        $this->assertTrue(Schema::hasColumns('fechamento_reconsolidacoes', [
            'id', 'mes_referencia', 'reconsolidado_por', 'motivo',
            'snapshot_anterior', 'origem', 'created_at', 'updated_at',
        ]));
    }

    public function test_unique_por_empresa_e_mes_estoura_query_exception(): void
    {
        $company = Company::create(['name' => 'Empresa Fechamento', 'cnpj' => '90000000000001', 'active' => true]);
        $mes     = Carbon::now()->startOfMonth()->toDateString();

        FechamentoSnapshot::create([
            'company_id'     => $company->id,
            'mes_referencia' => $mes,
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        $this->expectException(QueryException::class);

        FechamentoSnapshot::create([
            'company_id'     => $company->id,
            'mes_referencia' => $mes,
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);
    }

    public function test_unique_por_grupo_e_mes_estoura_query_exception(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Fechamento', 'color' => '#000000']);
        $mes   = Carbon::now()->startOfMonth()->toDateString();

        FechamentoGrupoSnapshot::create([
            'company_group_id' => $grupo->id,
            'mes_referencia'   => $mes,
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'empresas_count'   => 2,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'        => now(),
        ]);

        $this->expectException(QueryException::class);

        FechamentoGrupoSnapshot::create([
            'company_group_id' => $grupo->id,
            'mes_referencia'   => $mes,
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'empresas_count'   => 2,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'        => now(),
        ]);
    }

    public function test_apagar_a_empresa_apaga_o_snapshot_dela_por_cascade(): void
    {
        $company = Company::create(['name' => 'Empresa Cascade', 'cnpj' => '90000000000002', 'active' => true]);
        $mes     = Carbon::now()->startOfMonth()->toDateString();

        FechamentoSnapshot::create([
            'company_id'     => $company->id,
            'mes_referencia' => $mes,
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        Company::destroy($company->id);

        // Reconsulta ao banco, nunca confiança em stdout/contador em
        // memória (disciplina registrada em
        // .planning/learnings/desempenho-bonificacao.md §4).
        $this->assertSame(
            0,
            DB::table('fechamento_snapshots')->where('company_id', $company->id)->count()
        );
    }

    public function test_snapshot_anterior_faz_round_trip_de_array_aninhado(): void
    {
        $payload = [
            'empresas' => [
                ['company_id' => 1, 'cobranca_mensal' => 3000.00],
            ],
            'grupos' => [
                ['company_group_id' => 1, 'cobranca_mensal' => 6000.00],
            ],
        ];

        $reconsolidacao = FechamentoReconsolidacao::create([
            'mes_referencia'    => Carbon::now()->startOfMonth()->toDateString(),
            'reconsolidado_por' => null,
            'motivo'            => 'Adman corrigiu faturamento depois do fechamento',
            'snapshot_anterior' => $payload,
            'origem'            => 'reconsolidacao_manual',
        ]);

        $recarregada = FechamentoReconsolidacao::findOrFail($reconsolidacao->id);

        $this->assertIsArray($recarregada->snapshot_anterior);
        $this->assertArrayHasKey('empresas', $recarregada->snapshot_anterior);
        $this->assertArrayHasKey('grupos', $recarregada->snapshot_anterior);
        $this->assertEquals(3000.00, $recarregada->snapshot_anterior['empresas'][0]['cobranca_mensal']);
    }

    public function test_estado_aceita_valor_arbitrario_sem_estourar_check_provando_que_nao_e_enum(): void
    {
        $company = Company::create(['name' => 'Empresa Estado Livre', 'cnpj' => '90000000000003', 'active' => true]);

        $snapshot = FechamentoSnapshot::create([
            'company_id'     => $company->id,
            'mes_referencia' => Carbon::now()->startOfMonth()->toDateString(),
            'estado'         => 'valor_arbitrario_que_nao_existe_na_lista',
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
        ]);

        $this->assertSame(
            'valor_arbitrario_que_nao_existe_na_lista',
            $snapshot->fresh()->estado
        );
    }
}
