<?php

namespace Tests\Feature\Phase139;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoComparativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 139 Plano 01 (D-04) — o risco número 1 da fase: `faixa_ordem_anterior`,
 * `valor_faixa_anterior`, `subiu_de_faixa` e `ganho_faixa` têm que chegar nos
 * QUATRO caminhos que montam linha em `AdminController::fechamento()` (empresa
 * ao vivo, empresa congelada — dois literais — grupo ao vivo, grupo congelado).
 * Nesta linha de trabalho já aconteceu três vezes de um dado atravessar quase
 * todo o caminho e morrer no último trecho — este arquivo é a trava.
 *
 * Duas frentes:
 *  (a) Tarefa 1 — `FechamentoComparativoService` isolado, via
 *      `app(FechamentoComparativoService::class)`, com linhas de snapshot
 *      criadas na mão.
 *  (b) Tarefa 3 — os quatro caminhos via HTTP em `/administrativo/financeiro`,
 *      comparando ao vivo x congelado no MESMO cenário.
 */
class Phase139ComparativoFaixaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" — mesmo padrão dos demais testes da fase.
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    private function criarEmpresaSnapshot(Carbon $mes, array $overrides = []): FechamentoSnapshot
    {
        $company = Company::factory()->create();

        return FechamentoSnapshot::create(array_merge([
            'company_id'     => $company->id,
            'company_name'   => $company->name,
            'mes_referencia' => $mes->copy()->startOfMonth()->toDateString(),
            'faixa_ordem'    => 2,
            'faixa_aplicada' => 'faixa_2',
            'valor_faixa'    => 4_500.00,
            'evolucao'       => 'subiu',
            'estado'         => FechamentoSnapshot::ESTADO_OK,
            'origem'         => FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'gerado_em'      => now(),
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
            'evolucao'         => 'desceu',
            'estado'           => FechamentoSnapshot::ESTADO_OK,
            'origem'           => FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES,
            'empresas_count'   => 2,
            'gerado_em'        => now(),
        ], $overrides));
    }

    // ─── (a) Tarefa 1 — FechamentoComparativoService isolado ─────────────

    #[Test]
    public function anteriores_por_empresa_le_o_snapshot_do_mes_anterior_com_faixa_e_valor(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['faixa_ordem' => 1, 'valor_faixa' => 3_000.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'A empresa com snapshot fechado em agosto precisa aparecer na leitura do mês anterior a setembro.');
        $this->assertSame(1, $resultado[$snap->company_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(3_000.00, $resultado[$snap->company_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function anteriores_por_grupo_le_fechamento_grupo_snapshots_do_mes_anterior(): void
    {
        $snap = $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1), ['faixa_ordem' => 2, 'valor_faixa' => 4_500.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');

        $this->assertArrayHasKey($snap->company_group_id, $resultado);
        $this->assertSame(2, $resultado[$snap->company_group_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(4_500.00, $resultado[$snap->company_group_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function mes_anterior_sem_nenhum_fechamento_devolve_array_vazio_nunca_null(): void
    {
        $resultadoEmpresa = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');
        $resultadoGrupo    = app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');

        $this->assertIsArray($resultadoEmpresa);
        $this->assertSame([], $resultadoEmpresa, 'Sem fechamento nenhum em agosto, o array precisa ser vazio — nunca null.');
        $this->assertIsArray($resultadoGrupo);
        $this->assertSame([], $resultadoGrupo);
    }

    #[Test]
    public function empresa_com_faixa_ordem_null_no_mes_anterior_entra_no_array_com_as_duas_chaves_em_null(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), [
            'faixa_ordem'    => null,
            'faixa_aplicada' => null,
            'valor_faixa'    => null,
            'evolucao'       => null,
            'estado'         => FechamentoSnapshot::ESTADO_SEM_TABELA,
        ]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'Estado sem_tabela ainda gera linha no snapshot — precisa aparecer no array, só com os valores em null.');
        $this->assertNull($resultado[$snap->company_id]['faixa_ordem']);
        $this->assertNull($resultado[$snap->company_id]['valor_faixa']);
    }

    #[Test]
    public function vira_de_ano_janeiro_le_dezembro_do_ano_anterior(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2025, 12, 1), ['faixa_ordem' => 4, 'valor_faixa' => 7_500.00]);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-01-01');

        $this->assertArrayHasKey($snap->company_id, $resultado, 'Janeiro/2026 precisa ler dezembro/2025 (subMonthNoOverflow), sem estourar de ano.');
        $this->assertSame(4, $resultado[$snap->company_id]['faixa_ordem']);
        $this->assertEqualsWithDelta(7_500.00, $resultado[$snap->company_id]['valor_faixa'], 0.01);
    }

    #[Test]
    public function ignora_snapshot_de_origem_diferente_de_consolidar_mes(): void
    {
        $snap = $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1), ['origem' => 'preview']);

        $resultado = app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');

        $this->assertArrayNotHasKey($snap->company_id, $resultado, 'Só origem consolidar_mes conta como fechamento real do mês anterior — mesma trava de competencia_fechada.');
    }

    #[Test]
    public function cada_metodo_faz_exatamente_uma_consulta_independente_da_quantidade_de_linhas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->criarEmpresaSnapshot(Carbon::create(2026, 8, 1));
        }
        for ($i = 0; $i < 5; $i++) {
            $this->criarGrupoSnapshot(Carbon::create(2026, 8, 1));
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        app(FechamentoComparativoService::class)->anterioresPorEmpresa('2026-09-01');
        $logEmpresa = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        app(FechamentoComparativoService::class)->anterioresPorGrupo('2026-09-01');
        $logGrupo = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(1, $logEmpresa, 'anterioresPorEmpresa() precisa fazer exatamente 1 consulta, nunca uma por empresa (o problema que este serviço substitui).');
        $this->assertCount(1, $logGrupo, 'anterioresPorGrupo() precisa fazer exatamente 1 consulta, nunca uma por grupo.');
    }
}
