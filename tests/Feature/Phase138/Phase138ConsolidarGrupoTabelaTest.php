<?php

namespace Tests\Feature\Phase138;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 138 Plano 03 — Tarefa 2: `fechamento:consolidar-mes` (Passo 5)
 * classificando o grupo pela tabela do próprio grupo, quando ela existir
 * (D-01, precedência grupo → empresa → serviço).
 *
 * Molde: `Tests\Feature\Phase137\Phase137ConsolidarMesTest`. Toda asserção é
 * por RECONSULTA às tabelas de snapshot — nunca pela saída de texto do
 * comando (.planning/learnings/desempenho-bonificacao.md §4).
 */
class Phase138ConsolidarGrupoTabelaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" dentro do RefreshDatabase — este helper
     * só ajusta `plataforma`/`setor` (mesmo padrão de Phase137ConsolidarMesTest).
     *
     * Faixas conhecidas (usadas nas asserções): ordem 1 até 499.999,99 =
     * R$ 3.000; ordem 2 até 999.999,99 = R$ 4.500.
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

    /** Empresa com integração Adman (cust_id) e contrato ativo do serviço informado. */
    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        return $company;
    }

    /** Tabela de faixas própria da empresa, deliberadamente diferente da do serviço e da do grupo. */
    private function criarFaixasPropriasDaEmpresa(Company $company): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => 50_000.00,
            'valor'           => 777.00,
            'valor_e_piso'    => false,
        ]);
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 2,
            'limite_superior' => null,
            'valor'           => 888.00,
            'valor_e_piso'    => true,
        ]);
    }

    /** Tabela de faixas do grupo, deliberadamente diferente da da âncora e da própria da empresa. */
    private function criarFaixasDeGrupo(CompanyGroup $grupo): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'             => 1,
            'limite_superior'   => 1_000_000.00,
            'valor'             => 99_999.00,
            'valor_e_piso'      => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'             => 2,
            'limite_superior'   => null,
            'valor'             => 199_999.00,
            'valor_e_piso'      => true,
        ]);
    }

    // ─── Caso (a): grupo SEM tabela de grupo — comportamento de antes ──────

    #[Test]
    public function grupo_sem_tabela_propria_continua_classificado_pela_tabela_da_ancora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Sem Tabela', 'color' => '#000']);

        // Ambas via tabela do serviço (nenhuma tem exceção própria nem o
        // grupo tem tabela) — membroB fatura mais e vira a âncora.
        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 200_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $grupoGravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        $this->assertNotNull($grupoGravado);
        $this->assertSame('servico', $grupoGravado->tabela_origem, 'Sem tabela de grupo, a origem continua sendo a tabela do serviço da âncora — nada regrediu.');
        $this->assertSame($gestao->id, $grupoGravado->servico_id);
        $this->assertSame($membroB->id, $grupoGravado->empresa_ancora_id, 'A âncora é a empresa de maior faturamento_total.');
        // Soma = 500.000,00 → cai na faixa 2 da tabela de Gestão (até 999.999,99 = R$ 4.500).
        $this->assertSame(2, (int) $grupoGravado->faixa_ordem);
        $this->assertEqualsWithDelta(4_500.00, (float) $grupoGravado->valor_faixa, 0.01);
    }

    // ─── Casos (b), (c) e (d): grupo COM tabela de grupo ────────────────────

    #[Test]
    public function grupo_com_tabela_propria_vence_a_da_ancora_e_zera_a_divergencia_entre_membros(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Com Tabela', 'color' => '#000']);

        // membroA tem exceção própria; membroB só tem a tabela do serviço —
        // ANTES do grupo ganhar tabela, isso é um par (origem, servico_id)
        // divergente entre os dois membros.
        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $this->criarFaixasPropriasDaEmpresa($membroA);

        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        // membroB fatura mais → é a âncora.
        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 400_000.00]);

        // Tabela do grupo — deliberadamente diferente da própria de membroA
        // e da do serviço que classificaria membroB.
        $this->criarFaixasDeGrupo($grupo);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // (b) — snapshot do grupo: origem 'grupo', sem serviço, âncora preservada.
        $grupoGravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        $this->assertNotNull($grupoGravado);
        $this->assertSame('grupo', $grupoGravado->tabela_origem);
        $this->assertNull($grupoGravado->servico_id, 'Tabela é do grupo — não existe serviço "dono" para registrar aqui.');
        $this->assertSame($membroB->id, $grupoGravado->empresa_ancora_id, 'empresa_ancora_id continua preenchido mesmo quando a tabela é do grupo — é a identidade da linha, não a origem da tabela.');
        // Soma = 500.000,00 → tabela do grupo: faixa 1 (até 1.000.000,00 = R$ 99.999).
        $this->assertSame(1, (int) $grupoGravado->faixa_ordem);
        $this->assertEqualsWithDelta(99_999.00, (float) $grupoGravado->valor_faixa, 0.01);

        // (d) — divergência de tabelas entre os membros deixa de ser sinalizada.
        $this->assertFalse((bool) $grupoGravado->tabelas_divergentes, 'Com tabela de grupo, a divergência entre as tabelas das empresas-membro deixou de ser relevante.');

        // (c) — as linhas das empresas-membro também saem com origem 'grupo'.
        $linhaA = DB::table('fechamento_snapshots')->where('company_id', $membroA->id)->first();
        $linhaB = DB::table('fechamento_snapshots')->where('company_id', $membroB->id)->first();

        $this->assertSame('grupo', $linhaA->tabela_origem, 'A precedência de D-01 vale para o membro, não só para a soma do grupo — mesmo membroA, que tem exceção própria.');
        $this->assertNull($linhaA->servico_id);
        $this->assertSame('grupo', $linhaB->tabela_origem);
        $this->assertNull($linhaB->servico_id);

        // membroA fatura 100.000,00 → faixa 1 da tabela do grupo (até 1.000.000,00 = R$ 99.999).
        $this->assertSame(1, (int) $linhaA->faixa_ordem);
        $this->assertEqualsWithDelta(99_999.00, (float) $linhaA->valor_faixa, 0.01);
        // membroB fatura 400.000,00 → também faixa 1 da tabela do grupo.
        $this->assertSame(1, (int) $linhaB->faixa_ordem);
        $this->assertEqualsWithDelta(99_999.00, (float) $linhaB->valor_faixa, 0.01);
    }
}
