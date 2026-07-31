<?php

namespace Tests\Feature\Phase121;

use App\Models\Company;
use App\Models\DesempenhoComparadorEmpresa;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prova das duas tabelas insert-only do comparador da Fase 121: existência
 * pós-migration, round-trip de precisão decimal(14,6) na margem (a mesma
 * armadilha do probe da Fase 117 — 2 casas fabricariam falsa estabilidade),
 * round-trip de array em coluna JSON, e `status` aceitando string arbitrária
 * (prova de que não virou `enum()` com CHECK enforçado no SQLite).
 */
class ComparadorTabelasTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_as_duas_tabelas_existem_apos_a_migration(): void
    {
        $this->assertTrue(Schema::hasTable('desempenho_comparador_profissionais'));
        $this->assertTrue(Schema::hasTable('desempenho_comparador_empresas'));
    }

    #[Test]
    public function test_desempenho_comparador_profissional_persiste_e_rele_com_precisao(): void
    {
        $user = User::factory()->create();

        $registro = DesempenhoComparadorProfissional::create([
            'run_id'               => '11111111-1111-1111-1111-111111111111',
            'user_id'               => $user->id,
            'periodo_key'           => '2026-07',
            'competencia_alvo'      => true,
            'gerado_em'             => Carbon::parse('2026-07-31 10:00:00'),
            'nota_antiga'           => 3.50,
            'nota_nova'             => 3.28,
            'delta'                 => -0.22,
            'status_antigo'         => 'official',
            'status_novo'           => 'partial',
            'faixa_antiga_oficial'  => 'intermediario',
            'faixa_antiga_inicial'  => 'intermediario',
            'faixa_nova_inicial'    => 'basico',
            'mudou_faixa'           => true,
            'empresas_total'        => 10,
            'empresas_complete'     => 7,
            'empresas_partial'      => 2,
            'empresas_sem_fonte'    => 1,
            'empresas_sem_dados'    => 0,
            'decomposicao'          => ['margem' => -0.30, 'nps' => 0.08],
            'maior_causa_delta'     => 'margem_pp',
            'falhou'                => false,
            'erro'                  => null,
        ]);

        $relido = DesempenhoComparadorProfissional::query()->findOrFail($registro->id);

        $this->assertSame(3.50, $relido->nota_antiga);
        $this->assertSame(3.28, $relido->nota_nova);
        $this->assertSame(-0.22, $relido->delta);
        $this->assertTrue($relido->competencia_alvo);
        $this->assertTrue($relido->mudou_faixa);
        $this->assertFalse($relido->falhou);
        $this->assertSame(['margem' => -0.30, 'nps' => 0.08], $relido->decomposicao);
        $this->assertSame('margem_pp', $relido->maior_causa_delta);
    }

    #[Test]
    public function test_desempenho_comparador_empresa_persiste_e_rele_margem_var_pp_com_precisao_14_6(): void
    {
        $user    = User::factory()->create();
        $company = Company::factory()->create();

        $registro = DesempenhoComparadorEmpresa::create([
            'run_id'               => '22222222-2222-2222-2222-222222222222',
            'user_id'               => $user->id,
            'company_id'            => $company->id,
            'periodo_key'           => '2026-07',
            'competencia_alvo'      => true,
            'gerado_em'             => Carbon::parse('2026-07-31 10:00:00'),
            'fonte_financeira'      => 'adman',
            'status'                => 'uma-string-qualquer-fora-de-lista-fixa',
            'nps_pontos'            => 4.20,
            'faturamento_var_pct'   => 3.123456,
            'faturamento_pontos'    => 4.00,
            'margem_var_pp'         => -0.594321,
            'margem_diff_pct'       => -1.987654,
            'margem_pontos'         => 2.00,
            'nota_empresa'          => 3.40,
            'nota_empresa_parcial'  => 3.40,
            'quality_motivos'       => ['sem_baseline_anterior', 'margem_placeholder'],
        ]);

        $relido = DesempenhoComparadorEmpresa::query()->findOrFail($registro->id);

        // Precisão 14,6 preservada — NÃO arredondada para 2 casas. Se a
        // coluna tivesse sido criada como decimal(10,2), este valor viraria
        // -0.59, fabricando falsa estabilidade (mesma armadilha do probe da
        // Fase 117).
        $this->assertSame(-0.594321, $relido->margem_var_pp);
        $this->assertSame(3.123456, $relido->faturamento_var_pct);
        $this->assertSame(-1.987654, $relido->margem_diff_pct);

        // `status` aceita string arbitrária — prova de que não virou enum()
        // com CHECK enforçado no SQLite dos testes.
        $this->assertSame('uma-string-qualquer-fora-de-lista-fixa', $relido->status);

        // Round-trip de array em coluna JSON.
        $this->assertSame(['sem_baseline_anterior', 'margem_placeholder'], $relido->quality_motivos);
    }
}
