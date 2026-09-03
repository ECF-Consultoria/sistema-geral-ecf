<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 07 — contrato de props de `AdminController::fechamento()`
 * (`/administrativo/financeiro`) depois da migração para mês-calendário
 * fechado (D-06), grupos do Comercial (D-08/D-09/D-10) e leitura do
 * congelado quando a competência já foi fechada (D-11).
 *
 * Cobre as 8 asserções pedidas pelo plano 137-07 (Tarefa 3):
 * (a) mês corrente devolve periodo_inicio no dia 1;
 * (b) grupo de 2 empresas gera 1 linha tipo=grupo com soma e faixa da soma;
 * (c) empresa com parent_company_id e sem grupo é linha própria;
 * (d) competência fechada devolve o valor do snapshot mesmo depois de
 *     alterar adman_metrics;
 * (e) empresa sem tabela devolve estado='sem_tabela' e valor_mensal nulo;
 * (f) empresa com ML e Shopee no mês devolve faturamento_ml,
 *     faturamento_shopee e faturamento = soma;
 * (g) nenhum item de progressao tem a chave 'acumulado';
 * (h) props de página trazem competencia_fechada.
 */
class Phase137FinanceiroPropsTest extends TestCase
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
     * semeia as 7 faixas de "Gestão" — este helper só ajusta
     * plataforma/setor para o serviço virar candidato no resolver (mesmo
     * padrão de `Phase137FaixaResolverTest`/`Phase137ConsolidarMesTest`).
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

    /** Serviço candidato (setor financeiro) SEM nenhuma linha em servico_faixas_faturamento. */
    private function criarServicoSemFaixas(): Servico
    {
        return Servico::create([
            'nome'          => 'Serviço Sem Tabela '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
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

    // ─── (a) mês corrente devolve periodo_inicio no dia 1 do mês ────────

    #[Test]
    public function mes_corrente_devolve_periodo_inicio_no_dia_1_do_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('01/09', $linha['periodo_inicio'], 'Mês corrente precisa começar no dia 1 do mês-calendário (D-06), nunca 30 dias atrás.');
    }

    // ─── (b) grupo de 2 empresas gera 1 linha tipo=grupo com soma e faixa da soma ─

    #[Test]
    public function grupo_de_2_empresas_gera_1_linha_tipo_grupo_com_soma_e_faixa_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 250_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];

        $linhaGrupo = collect($companies)->firstWhere('tipo', 'grupo');
        $this->assertNotNull($linhaGrupo, 'Deve existir 1 linha tipo=grupo para o grupo Lyam.');
        $this->assertEqualsWithDelta(550_000.00, (float) $linhaGrupo['faturamento'], 0.01, 'faturamento do grupo é a soma das 2 empresas-membro (D-10).');
        $this->assertSame('faixa_2', $linhaGrupo['faixa'], '550.000 cai na faixa 2 da tabela de Gestão — a faixa é da SOMA, não de cada empresa isolada.');

        // As 2 empresas-membro não aparecem mais como linha própria — a
        // linha de grupo substitui.
        $linhasEmpresa = collect($companies)->where('tipo', '!=', 'grupo');
        $this->assertFalse($linhasEmpresa->contains('id', $membroA->id));
        $this->assertFalse($linhasEmpresa->contains('id', $membroB->id));

        $filhas = collect($linhaGrupo['filhas'])->keyBy('id');
        $this->assertTrue($filhas->has($membroA->id));
        $this->assertTrue($filhas->has($membroB->id));
        $this->assertFalse($filhas[$membroA->id]['conta_no_total']);
        $this->assertFalse($filhas[$membroB->id]['conta_no_total']);
    }

    // ─── (c) empresa com parent_company_id e sem grupo é linha própria ──

    #[Test]
    public function empresa_com_parent_company_id_e_sem_grupo_e_linha_propria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        $pai   = $this->criarEmpresaComContrato($gestao);
        $filha = $this->criarEmpresaComContrato($gestao, ['parent_company_id' => $pai->id]);

        AdmanMetric::create(['company_id' => $pai->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $filha->id, 'reference_date' => '2026-09-05', 'revenue' => 200_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];

        $this->assertFalse(collect($companies)->contains('tipo', 'grupo'), 'parent_company_id sozinho (D-08/D-09) não gera linha de grupo.');

        $linhaPai   = collect($companies)->firstWhere('id', $pai->id);
        $linhaFilha = collect($companies)->firstWhere('id', $filha->id);

        $this->assertNotNull($linhaPai);
        $this->assertNotNull($linhaFilha);
        $this->assertTrue($linhaPai['conta_no_total']);
        $this->assertTrue($linhaFilha['conta_no_total']);
        $this->assertEqualsWithDelta(300_000.00, (float) $linhaPai['faturamento'], 0.01, 'Faturamento do pai não pode incluir o da filha (parent_company_id não soma).');
        $this->assertEqualsWithDelta(200_000.00, (float) $linhaFilha['faturamento'], 0.01);
    }

    // ─── (d) competência fechada devolve o valor do snapshot mesmo depois de alterar adman_metrics ─

    #[Test]
    public function competencia_fechada_devolve_valor_do_snapshot_mesmo_apos_alterar_adman_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $valorCongelado = (float) FechamentoSnapshot::where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')
            ->value('faturamento_total');
        $this->assertEqualsWithDelta(300_000.00, $valorCongelado, 0.01);

        // Adman "corrige" o faturamento depois do fechamento — nunca deve
        // vazar pra tela de uma competência já congelada.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-20', 'revenue' => 999_999.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(300_000.00, (float) $linha['faturamento'], 0.01, 'Competência fechada não pode recalcular ao vivo — precisa ler o congelado (D-11).');
        $this->assertTrue($response->viewData('page')['props']['competencia_fechada']);
    }

    // ─── (e) empresa sem tabela devolve estado='sem_tabela' e valor_mensal nulo ─

    #[Test]
    public function empresa_sem_tabela_devolve_estado_sem_tabela_e_valor_mensal_nulo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin            = $this->criarAdmin();
        $servicoSemFaixas = $this->criarServicoSemFaixas();
        $company          = $this->criarEmpresaComContrato($servicoSemFaixas);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('sem_tabela', $linha['estado']);
        $this->assertNull($linha['valor_mensal'], 'Nunca R$ 0 — ausência de tabela precisa ficar visível como null.');
    }

    // ─── (f) empresa com ML e Shopee no mês devolve faturamento_ml, faturamento_shopee e faturamento = soma ─

    #[Test]
    public function empresa_com_ml_e_shopee_devolve_faturamento_ml_shopee_e_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000.00, 'ad_expense' => 0]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(300_000.00, (float) $linha['faturamento_ml'], 0.01);
        $this->assertEqualsWithDelta(50_000.00, (float) $linha['faturamento_shopee'], 0.01);
        $this->assertEqualsWithDelta(350_000.00, (float) $linha['faturamento'], 0.01, 'faturamento total é a SOMA de ML + Shopee (D-05).');
    }

    // ─── (g) nenhum item de progressao tem a chave 'acumulado' ──────────

    #[Test]
    public function nenhum_item_de_progressao_tem_a_chave_acumulado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-07-10', 'revenue' => 300_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-07'])->assertExitCode(0);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertGreaterThanOrEqual(2, count($linha['progressao']), 'Precisa ter histórico de pelo menos 2 competências fechadas (julho + agosto).');

        foreach ($linha['progressao'] as $item) {
            $this->assertArrayNotHasKey('acumulado', $item, 'D-06 é explícito: não deve haver coluna acumulada em lugar nenhum.');
        }
    }

    // ─── (h) props de página trazem competencia_fechada ─────────────────

    #[Test]
    public function props_de_pagina_trazem_competencia_fechada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        // Mês aberto (corrente) — competencia_fechada precisa ser false.
        $respostaAberta = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respostaAberta->assertOk();
        $this->assertFalse($respostaAberta->viewData('page')['props']['competencia_fechada']);
        $this->assertNull($respostaAberta->viewData('page')['props']['competencia_fechada_em']);

        // Mês fechado — competencia_fechada precisa ser true, com data.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $respostaFechada = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $respostaFechada->assertOk();
        $props = $respostaFechada->viewData('page')['props'];
        $this->assertTrue($props['competencia_fechada']);
        $this->assertNotNull($props['competencia_fechada_em']);
    }

    // ─── Cobertura extra: T-137-27 (tabela_origem/tabela_servico_nome) ──

    #[Test]
    public function empresa_ok_devolve_tabela_origem_e_servico_nome(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertSame('servico', $linha['tabela_origem']);
        $this->assertSame('Gestão', $linha['tabela_servico_nome']);
        $this->assertFalse($linha['valor_faixa_e_piso'], 'R$ 300.000 cai na 1ª faixa (fechada), não na faixa-piso.');
    }

    #[Test]
    public function empresa_na_faixa_piso_de_gestao_devolve_valor_faixa_e_piso_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 6_000_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertSame('maxima', $linha['faixa']);
        $this->assertTrue($linha['valor_faixa_e_piso'], 'Acima de R$ 5M a última faixa de Gestão é piso ("a partir de R$ 12.000").');
        $this->assertEqualsWithDelta(12_000.00, (float) $linha['valor_mensal'], 0.001);
    }
}
