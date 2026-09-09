<?php

namespace Tests\Feature\Phase141;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 03 — Tarefa 2: `fechamento:materializar-tabelas` — a ponte de transição (D-04/D-05
 * do CONTEXT).
 *
 * Prova os sete comportamentos do plano: dry-run é o padrão e não grava nada; `--aplicar` copia a
 * tabela do serviço para quem depende dela hoje, carimbando `origem = 'presumida_servico'`;
 * idempotência (segunda rodada não duplica nem sobrescreve); empresa com tabela própria ou tabela de
 * grupo não é tocada; empresa sem tabela nenhuma aparece nominalmente no relatório; nenhum snapshot é
 * lido ou escrito. Toda asserção de persistência é por RECONSULTA ao banco (`DB::table`).
 */
class Phase141MaterializarTabelasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Serviço com tabela progressiva própria (2 faixas) e uma empresa ativa com contrato ativo nele
     * — é exatamente o caso "127 empresas" do CONTEXT: classificada hoje por `origem = 'servico'`.
     */
    private function criarEmpresaQueDependeDoServico(): array
    {
        $servico = Servico::create([
            'nome'          => 'Gestão Teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => 1_500.00, 'valor_e_piso' => false,
        ]);
        ServicoFaixaFaturamento::create([
            'servico_id' => $servico->id, 'ordem' => 2, 'limite_superior' => null, 'valor' => 3_000.00, 'valor_e_piso' => true,
        ]);

        $company = Company::factory()->create(['active' => true]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        return [$company, $servico];
    }

    // ── 1. Empresa 'servico': dry-run não grava ──────────────────────────

    #[Test]
    public function sem_aplicar_nada_e_gravado_e_a_contagem_de_linhas_e_a_mesma_antes_e_depois(): void
    {
        [$company] = $this->criarEmpresaQueDependeDoServico();

        $antes = DB::table('empresa_faixas_faturamento')->count();

        $exitCode = Artisan::call('fechamento:materializar-tabelas');

        $depois = DB::table('empresa_faixas_faturamento')->count();

        $this->assertSame(0, $exitCode);
        $this->assertSame($antes, $depois);
        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── 2. --aplicar copia a tabela do serviço como presumida ────────────

    #[Test]
    public function com_aplicar_copia_a_tabela_do_servico_marcando_presumida_servico(): void
    {
        [$company, $servico] = $this->criarEmpresaQueDependeDoServico();

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->orderBy('ordem')->get();

        $this->assertCount(2, $linhas);

        $this->assertSame(1, (int) $linhas[0]->ordem);
        $this->assertSame(300000.00, (float) $linhas[0]->limite_superior);
        $this->assertSame(1500.00, (float) $linhas[0]->valor);
        $this->assertSame(0, (int) $linhas[0]->valor_e_piso);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $linhas[0]->origem);
        $this->assertSame($servico->id, (int) $linhas[0]->servico_origem_id);

        $this->assertSame(2, (int) $linhas[1]->ordem);
        $this->assertNull($linhas[1]->limite_superior);
        $this->assertSame(3000.00, (float) $linhas[1]->valor);
        $this->assertSame(1, (int) $linhas[1]->valor_e_piso);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $linhas[1]->origem);
        $this->assertSame($servico->id, (int) $linhas[1]->servico_origem_id);
    }

    // ── 3. Empresa com tabela própria não é tocada ────────────────────────

    #[Test]
    public function empresa_que_ja_tem_tabela_propria_nao_e_tocada(): void
    {
        [$company] = $this->criarEmpresaQueDependeDoServico();

        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 9_999.00,
            'origem'     => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);

        $linhas = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();

        $this->assertCount(1, $linhas);
        $this->assertSame(9999.00, (float) $linhas[0]->valor);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linhas[0]->origem);
    }

    // ── 4. Empresa cuja tabela resolve por grupo não é tocada ─────────────

    #[Test]
    public function empresa_cuja_tabela_resolve_por_grupo_nao_e_tocada(): void
    {
        [$company] = $this->criarEmpresaQueDependeDoServico();

        $grupo = CompanyGroup::create(['name' => 'Grupo Teste '.uniqid()]);
        $company->update(['company_group_id' => $grupo->id]);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null, 'valor' => 4_321.00,
        ]);

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);

        $this->assertSame(0, DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->count());
    }

    // ── 5. Empresa sem tabela nenhuma aparece no relatório ────────────────

    #[Test]
    public function empresa_que_continua_sem_tabela_aparece_no_json_com_nome(): void
    {
        $company = Company::factory()->create(['active' => true, 'name' => 'Empresa Sem Tabela Nenhuma']);

        Artisan::call('fechamento:materializar-tabelas', ['--json' => true]);

        $saida = json_decode(Artisan::output(), true);

        $this->assertIsArray($saida);
        $this->assertGreaterThanOrEqual(1, $saida['contagens']['continua_sem_tabela']);
        $nomes = array_column($saida['continua_sem_tabela'], 'company_name');
        $this->assertContains('Empresa Sem Tabela Nenhuma', $nomes);
    }

    // ── 6/7. Idempotência: segunda rodada de --aplicar não grava nada ────

    #[Test]
    public function segunda_rodada_de_aplicar_nao_duplica_nem_sobrescreve(): void
    {
        [$company] = $this->criarEmpresaQueDependeDoServico();

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);
        $depoisDaPrimeira = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();
        $this->assertCount(2, $depoisDaPrimeira);

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);
        $depoisDaSegunda = DB::table('empresa_faixas_faturamento')->where('company_id', $company->id)->get();

        $this->assertCount(2, $depoisDaSegunda);
        $this->assertEquals(
            $depoisDaPrimeira->pluck('valor')->all(),
            $depoisDaSegunda->pluck('valor')->all()
        );
    }

    // ── 8. Nenhuma linha de snapshot é lida ou escrita ────────────────────

    #[Test]
    public function nenhuma_linha_de_fechamento_snapshots_e_lida_ou_escrita(): void
    {
        $this->criarEmpresaQueDependeDoServico();

        $antesSnapshots      = DB::table('fechamento_snapshots')->count();
        $antesSnapshotsGrupo = DB::table('fechamento_grupo_snapshots')->count();

        Artisan::call('fechamento:materializar-tabelas', ['--aplicar' => true]);

        $this->assertSame($antesSnapshots, DB::table('fechamento_snapshots')->count());
        $this->assertSame($antesSnapshotsGrupo, DB::table('fechamento_grupo_snapshots')->count());
    }
}
