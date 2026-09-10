<?php

namespace Tests\Feature\Phase142;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 142 Plano 01 — Tarefa 3: `tabela_faixas` chega em `/administrativo/financeiro` com as
 * LINHAS reais da tabela própria, nos dois ramos (ao vivo/congelado), sem query por empresa
 * (paga a dívida do 137-09 registrada no CONTEXT da Fase 142: a tela não pode mais reconstruir
 * a tabela por aproximação, só mostrar o que está gravado).
 */
class Phase142FaixasProprasNasPropsTest extends TestCase
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

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update([
            'plataforma'             => 'Mercado Livre',
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => true,
        ]);

        return $servico->refresh();
    }

    private function faixasReais(): array
    {
        return [
            [1, 300_000.00, 1_500.00, false],
            [2, null, 3_000.00, true],
        ];
    }

    private function criarTabelaPropria(Company $company, string $origem = EmpresaFaixaFaturamento::ORIGEM_MANUAL): void
    {
        foreach ($this->faixasReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
                'origem'          => $origem,
            ]);
        }
    }

    // ── (a) empresa com tabela própria no mês corrente ───────────────────

    #[Test]
    public function ao_vivo_empresa_com_tabela_propria_traz_as_linhas_exatas_e_nao_e_de_hoje_falso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-tabela-propria']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropria($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('propria', $linha['tabela_origem']);
        $this->assertIsArray($linha['tabela_faixas']);
        $this->assertCount(2, $linha['tabela_faixas']);

        $this->assertSame(1, $linha['tabela_faixas'][0]['ordem']);
        $this->assertEqualsWithDelta(300_000.00, $linha['tabela_faixas'][0]['limite_superior'], 0.01);
        $this->assertEqualsWithDelta(1_500.00, $linha['tabela_faixas'][0]['valor'], 0.01);
        $this->assertFalse($linha['tabela_faixas'][0]['valor_e_piso']);

        $this->assertSame(2, $linha['tabela_faixas'][1]['ordem']);
        $this->assertNull($linha['tabela_faixas'][1]['limite_superior']);
        $this->assertEqualsWithDelta(3_000.00, $linha['tabela_faixas'][1]['valor'], 0.01);
        $this->assertTrue($linha['tabela_faixas'][1]['valor_e_piso']);

        $this->assertFalse($linha['tabela_faixas_e_de_hoje']);
    }

    // ── (b) empresa com tabela do serviço ou do grupo ────────────────────

    #[Test]
    public function ao_vivo_empresa_pela_tabela_do_servico_traz_tabela_faixas_nulo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        // Flag DESLIGADA — só assim a tabela do serviço ainda é régua aplicável.

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        // Precisa existir ao menos uma faixa cadastrada para o serviço "Gestão"
        // para a origem resolver como 'servico' — a migration da Fase 137 já
        // semeia isso, mas garantimos aqui para não depender de ordem de seed.
        if (! \App\Models\ServicoFaixaFaturamento::where('servico_id', $gestao->id)->exists()) {
            \App\Models\ServicoFaixaFaturamento::create([
                'servico_id' => $gestao->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true,
            ]);
        }

        $company = Company::factory()->create(['adman_account_id' => 'cust-pelo-servico']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-01', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('servico', $linha['tabela_origem']);
        $this->assertNull($linha['tabela_faixas'], 'Origem servico já tem o catálogo em faixas_por_servico — tabela_faixas não deve duplicar.');
    }

    // ── (c) mês fechado ────────────────────────────────────────────────

    #[Test]
    public function congelado_traz_tabela_faixas_de_hoje_com_numeros_do_snapshot_intactos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-congelado-142']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropria($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 100_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $congelado = \App\Models\FechamentoSnapshot::where('company_id', $company->id)
            ->whereDate('mes_referencia', '2026-08-01')->first();
        $this->assertSame('propria', $congelado->tabela_origem);
        $valorMensalCongelado = (float) $congelado->valor_faixa;

        // Muda a tabela DEPOIS do fechamento — a tela precisa mostrar a de
        // HOJE (com aviso `tabela_faixas_e_de_hoje`), mas os NÚMEROS do
        // fechamento (faturamento/faixa/mensalidade) continuam do snapshot.
        EmpresaFaixaFaturamento::where('company_id', $company->id)->delete();
        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 9_999.00,
            'valor_e_piso' => true, 'origem' => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertTrue($linha['tabela_faixas_e_de_hoje']);
        $this->assertIsArray($linha['tabela_faixas']);
        $this->assertCount(1, $linha['tabela_faixas']);
        $this->assertEqualsWithDelta(9_999.00, $linha['tabela_faixas'][0]['valor'], 0.01, 'tabela_faixas mostra a tabela cadastrada HOJE, não a do momento do fechamento.');

        // D-11 — números do snapshot continuam intactos, sem recalcular.
        $this->assertEqualsWithDelta($valorMensalCongelado, (float) $linha['valor_mensal'], 0.01);
    }

    // ── (d) sem query por empresa no ramo congelado ───────────────────────

    #[Test]
    public function congelado_le_a_tabela_propria_em_no_maximo_uma_consulta_para_o_lote(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        for ($i = 0; $i < 3; $i++) {
            $c = Company::factory()->create(['adman_account_id' => 'cust-lote-142-'.$i]);
            ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $c->id, 'ativo' => true]);
            $this->criarTabelaPropria($c);
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-08-10', 'revenue' => 100_000.00]);
        }

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        $consultasFaixas = collect($log)->filter(fn ($q) => str_contains($q['query'], 'empresa_faixas_faturamento'));

        $this->assertLessThanOrEqual(1, $consultasFaixas->count(), 'A leitura da tabela própria no ramo congelado precisa ser em lote — nunca uma consulta por empresa.');
    }
}
