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

    // ─── (b) Tarefa 3 — a prop `totais` via HTTP, nos dois ramos ──────────

    #[Test]
    public function prop_totais_chega_com_as_onze_chaves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $this->criarEmpresaComContrato($gestao);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $totais = $response->viewData('page')['props']['totais'];

        foreach ([
            'total_a_receber', 'total_e_piso', 'empresas_com_cobranca',
            'empresas_sem_valor_definido', 'faturamento_gerado',
            'mes_anterior_fechado', 'mes_anterior_total', 'variacao',
            'upgrades_quantidade', 'upgrades_ganho_total', 'upgrades_ganho_parcial',
        ] as $chave) {
            $this->assertArrayHasKey($chave, $totais, "Sem a chave '{$chave}' em `totais`, o widget do topo não tem o que mostrar.");
        }
    }

    #[Test]
    public function total_a_receber_e_a_soma_exata_de_tres_empresas_com_mensalidade_conhecida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        // Faixas semeadas: até R$499.999,99 = faixa 1 (R$3.000); até
        // R$999.999,99 = faixa 2 (R$4.500). Três empresas na faixa 1.
        $c1 = $this->criarEmpresaComContrato($gestao);
        $c2 = $this->criarEmpresaComContrato($gestao);
        $c3 = $this->criarEmpresaComContrato($gestao);
        foreach ([$c1, $c2, $c3] as $c) {
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        }

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props     = $response->viewData('page')['props'];
        $companies = collect($props['companies'])->whereIn('id', [$c1->id, $c2->id, $c3->id]);
        $somaEsperada = $companies->sum('cobranca_mensal');

        $this->assertEqualsWithDelta($somaEsperada, $props['totais']['total_a_receber'], 0.01);
        $this->assertSame(3, $props['totais']['empresas_com_cobranca']);
    }

    #[Test]
    public function grupo_conta_uma_vez_no_total_nunca_soma_os_membros_por_fora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Total '.uniqid(), 'color' => '#000']);
        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $solta   = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 200_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $solta->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props        = $response->viewData('page')['props'];
        $linhaGrupo   = collect($props['companies'])->firstWhere('tipo', 'grupo');
        $linhaSolta   = collect($props['companies'])->firstWhere('id', $solta->id);
        $somaEsperada = (float) $linhaGrupo['cobranca_mensal'] + (float) $linhaSolta['cobranca_mensal'];

        $this->assertEqualsWithDelta($somaEsperada, $props['totais']['total_a_receber'], 0.01, 'O total precisa contar a linha do GRUPO uma vez — nunca somar as duas empresas-membro por fora.');
        $this->assertSame(2, $props['totais']['empresas_com_cobranca'], 'Grupo (1) + empresa solta (1) = 2 linhas com cobrança — as membros do grupo não contam à parte.');
    }

    #[Test]
    public function empresa_sem_tabela_nao_entra_no_total_e_conta_como_sem_valor_definido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        // Empresa com faturamento mas SEM contrato de serviço — sem tabela
        // pra classificar, cai em `sem_tabela`.
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $comControle = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $comControle->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $linha = collect($props['companies'])->firstWhere('id', $company->id);

        $this->assertSame(FechamentoSnapshot::ESTADO_SEM_TABELA, $linha['estado']);
        $this->assertNull($linha['cobranca_mensal']);

        $totalSoComControle = collect($props['companies'])->firstWhere('id', $comControle->id)['cobranca_mensal'];
        $this->assertEqualsWithDelta((float) $totalSoComControle, $props['totais']['total_a_receber'], 0.01, 'Empresa sem tabela não pode entrar como R$0 — senão o total sai menor sem ninguém perceber.');
        $this->assertGreaterThanOrEqual(1, $props['totais']['empresas_sem_valor_definido'], 'Empresa sem tabela precisa aparecer nomeada em `empresas_sem_valor_definido`, nunca sumir silenciosamente do total.');
    }

    #[Test]
    public function total_e_piso_true_quando_alguma_linha_somada_e_faixa_piso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        // Faturamento acima do limite superior da última faixa semeada —
        // resolver classifica como faixa-piso (`a partir de R$X`).
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $linha = collect($props['companies'])->firstWhere('id', $company->id);

        $this->assertTrue($linha['valor_faixa_e_piso'], 'Pré-condição do teste: a linha precisa estar em faixa-piso.');
        $this->assertTrue($props['totais']['total_e_piso'], 'Total que soma uma linha de faixa-piso precisa ser marcado como piso — nunca sair como valor seco.');
    }

    #[Test]
    public function mes_anterior_nunca_fechado_devolve_estado_ausente_e_variacao_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $this->criarEmpresaComContrato($gestao);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $totais = $response->viewData('page')['props']['totais'];

        $this->assertFalse($totais['mes_anterior_fechado']);
        $this->assertNull($totais['mes_anterior_total']);
        $this->assertNull($totais['variacao'], 'Sem mês anterior fechado, a variação precisa ser null — nunca comparar contra R$0.');
    }

    #[Test]
    public function mes_anterior_fechado_bate_com_a_soma_e_variacao_e_a_diferenca_exata(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 600_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $totais = $response->viewData('page')['props']['totais'];

        $totalAgostoEsperado = (float) FechamentoSnapshot::query()
            ->whereDate('mes_referencia', '2026-08-01')
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->sum('cobranca_mensal');

        $this->assertTrue($totais['mes_anterior_fechado']);
        $this->assertEqualsWithDelta($totalAgostoEsperado, $totais['mes_anterior_total'], 0.01);
        $this->assertEqualsWithDelta($totais['total_a_receber'] - $totalAgostoEsperado, $totais['variacao'], 0.01);
    }

    #[Test]
    public function competencia_corrente_fechada_traz_os_mesmos_totais_do_ramo_aberto(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 300_000.00]);

        $respAoVivo = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respAoVivo->assertOk();
        $totaisAoVivo = $respAoVivo->viewData('page')['props']['totais'];

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->assertExitCode(0);

        $respCongelado = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respCongelado->assertOk();
        $totaisCongelado = $respCongelado->viewData('page')['props']['totais'];

        $this->assertEqualsWithDelta($totaisAoVivo['total_a_receber'], $totaisCongelado['total_a_receber'], 0.01, 'O congelado não pode zerar o topo da tela.');
        $this->assertSame($totaisAoVivo['empresas_com_cobranca'], $totaisCongelado['empresas_com_cobranca']);
    }

    #[Test]
    public function upgrades_quantidade_e_ganho_total_batem_com_as_linhas_que_subiram(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $sobe    = $this->criarEmpresaComContrato($gestao);
        $desce   = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $sobe->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $desce->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        AdmanMetric::create(['company_id' => $sobe->id, 'reference_date' => '2026-09-10', 'revenue' => 600_000.00]);
        AdmanMetric::create(['company_id' => $desce->id, 'reference_date' => '2026-09-10', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props  = $response->viewData('page')['props'];
        $totais = $props['totais'];

        $linhasQueSubiram = collect($props['companies'])->where('subiu_de_faixa', true);

        $this->assertSame($linhasQueSubiram->count(), $totais['upgrades_quantidade']);
        $this->assertEqualsWithDelta((float) $linhasQueSubiram->sum('ganho_faixa'), $totais['upgrades_ganho_total'], 0.01);
        $this->assertFalse($totais['upgrades_ganho_parcial'], 'Nenhuma linha subiu sem o valor da faixa anterior neste cenário.');
    }
}
