<?php

namespace Tests\Feature\Phase139;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Services\Fechamento\FechamentoComparativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 139 Plano 02 (D-01/D-04, item 3) — a prop `totais` que alimenta o
 * widget "Total a receber": soma de `cobranca_mensal`, contagem de empresas
 * com cobrança, mês passado, variação, faturamento gerado e os números do
 * widget de upgrades.
 *
 * Duas frentes:
 *  (a) Tarefa 1 — `FechamentoComparativoService::totalCobrancaDoMesAnterior()`
 *      isolado, com snapshots criados na mão.
 *  (b) Tarefa 3 — a prop `totais` via HTTP em `/administrativo/financeiro`,
 *      nos dois ramos (ao vivo e congelado).
 *
 * ⚠️ `cobranca_mensal_grupo` NÃO EXISTE — nenhum teste aqui usa essa chave
 * morta. O total é sempre somado sobre `cobranca_mensal` das linhas com
 * `conta_no_total !== false`.
 */
class Phase139TotaisFechamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarEmpresaSnapshot(Carbon $mes, array $overrides = []): FechamentoSnapshot
    {
        $company = Company::factory()->create();

        return FechamentoSnapshot::create(array_merge([
            'company_id'       => $company->id,
            'company_name'     => $company->name,
            'company_group_id' => null,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 2,
            'faixa_aplicada'   => 'faixa_2',
            'valor_faixa'      => 4_500.00,
            'cobranca_mensal'  => 4_500.00,
            'evolucao'         => 'subiu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'        => now(),
        ], $overrides));
    }

    private function criarGrupoSnapshot(Carbon $mes, array $overrides = []): FechamentoGrupoSnapshot
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);

        return FechamentoGrupoSnapshot::create(array_merge([
            'company_group_id' => $grupo->id,
            'grupo_name'       => $grupo->name,
            'mes_referencia'   => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'      => 3,
            'faixa_aplicada'   => 'faixa_3',
            'valor_faixa'      => 6_000.00,
            'cobranca_mensal'  => 6_000.00,
            'evolucao'         => 'desceu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'empresas_count'   => 2,
            'gerado_em'        => now(),
        ], $overrides));
    }

    // ─── (a) Tarefa 1 — totalCobrancaDoMesAnterior() isolado ──────────────

    #[Test]
    public function mes_anterior_fechado_soma_grupo_mais_empresa_sem_grupo_sem_dobrar(): void
    {
        $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['cobranca_mensal' => 3_000.00, 'company_group_id' => null]);

        // Grupo de duas empresas: a linha do GRUPO já traz o valor somado —
        // as linhas de EMPRESA-membro (company_group_id preenchido) não
        // podem ser somadas de novo, senão o total dobra.
        $grupo = $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1), ['cobranca_mensal' => 9_000.00]);
        $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['cobranca_mensal' => 4_500.00, 'company_group_id' => $grupo->company_group_id]);
        $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['cobranca_mensal' => 4_500.00, 'company_group_id' => $grupo->company_group_id]);

        $resultado = app(FechamentoComparativoService::class)->totalCobrancaDoMesAnterior('2026-09-01');

        $this->assertTrue($resultado['fechado']);
        $this->assertEqualsWithDelta(12_000.00, $resultado['total'], 0.01, 'R$3.000 (empresa solta) + R$9.000 (grupo) = R$12.000 — as duas linhas de membro do grupo NÃO podem ser somadas de novo.');
    }

    #[Test]
    public function mes_anterior_sem_nenhum_fechamento_devolve_fechado_false_e_total_null(): void
    {
        $resultado = app(FechamentoComparativoService::class)->totalCobrancaDoMesAnterior('2026-09-01');

        $this->assertFalse($resultado['fechado']);
        $this->assertNull($resultado['total'], '"Não fechamos agosto" é diferente de "agosto gerou R$0" — total precisa ser null, nunca 0.0.');
    }

    #[Test]
    public function mes_anterior_fechado_so_com_cobranca_null_devolve_fechado_true_e_total_zero(): void
    {
        $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['cobranca_mensal' => null, 'company_group_id' => null]);

        $resultado = app(FechamentoComparativoService::class)->totalCobrancaDoMesAnterior('2026-09-01');

        $this->assertTrue($resultado['fechado']);
        $this->assertSame(0.0, $resultado['total'], 'Mês fechado com todas as linhas em null soma R$0 — diferente de não ter fechado.');
    }

    #[Test]
    public function faz_no_maximo_duas_consultas(): void
    {
        $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1));
        $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1));

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(FechamentoComparativoService::class)->totalCobrancaDoMesAnterior('2026-09-01');
        $log = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, count($log), 'totalCobrancaDoMesAnterior() precisa fazer no máximo 2 consultas.');
    }

    #[Test]
    public function vira_de_ano_janeiro_le_dezembro_do_ano_anterior(): void
    {
        $this->criarEmpresaSnapshot(Carbon::create(2025, 12, 1), ['cobranca_mensal' => 7_500.00, 'company_group_id' => null]);

        $resultado = app(FechamentoComparativoService::class)->totalCobrancaDoMesAnterior('2026-01-01');

        $this->assertTrue($resultado['fechado']);
        $this->assertEqualsWithDelta(7_500.00, $resultado['total'], 0.01);
    }
}
