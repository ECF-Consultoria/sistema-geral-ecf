<?php

namespace Tests\Feature\Phase141;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 04 — Tarefa 1: a tabela do serviço deixa de classificar
 * (virada de regra no `FechamentoFaixaResolver`).
 *
 * Molde de fixture igual a `Phase137FaixaResolverTest`/`Phase138GrupoFaixaResolverTest`
 * — o serviço "Gestão" já vem semeado com 7 faixas pela migration
 * `2026_09_02_100003_seed_faixas_faturamento_iniciais`, que roda dentro do
 * `RefreshDatabase`.
 */
class Phase141ResolverCutoverTest extends TestCase
{
    use RefreshDatabase;

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    // ─── Flag desligada — byte a byte o de hoje ─────────────────────────

    #[Test]
    public function flag_desligada_empresa_sem_tabela_propria_e_sem_grupo_resolve_pela_tabela_do_servico(): void
    {
        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($servico->id, $resultado['servico_id']);
        $this->assertCount(7, $resultado['faixas']);
        $this->assertNull($resultado['procedencia'], 'origem=servico nunca preenche procedencia.');
    }

    // ─── Flag ligada — tabela do serviço nunca mais classifica ──────────

    #[Test]
    public function flag_ligada_a_mesma_empresa_resolve_null_em_vez_de_herdar_do_servico(): void
    {
        $this->ligarFlag();

        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNull($resultado, 'Com a flag ligada, sem tabela de grupo nem própria, a empresa fica sem tabela — nunca faixa aproximada da tabela do serviço.');
    }

    #[Test]
    public function flag_ligada_empresa_com_tabela_propria_resolve_normalmente(): void
    {
        $this->ligarFlag();

        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        foreach ([1, 2, 3] as $ordem) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $ordem * 100_000,
                'valor'           => $ordem * 1_000,
                'valor_e_piso'    => false,
                'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('propria', $resultado['origem']);
        $this->assertCount(3, $resultado['faixas']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $resultado['procedencia']);
    }

    #[Test]
    public function flag_ligada_empresa_de_grupo_com_tabela_de_grupo_resolve_origem_grupo(): void
    {
        $this->ligarFlag();

        $servico = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Com Tabela']);

        foreach ([1, 2] as $ordem) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $ordem === 1 ? 200_000.00 : null,
                'valor'             => $ordem * 5_000,
                'valor_e_piso'      => $ordem !== 1,
            ]);
        }

        $company = Company::factory()->create(['company_group_id' => $grupo->id]);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('grupo', $resultado['origem']);
        $this->assertSame($grupo->id, $resultado['grupo_id']);
        $this->assertNull($resultado['procedencia'], 'origem=grupo nunca preenche procedencia.');
    }

    #[Test]
    public function flag_ligada_paragrupo_sem_tabela_de_grupo_so_herda_da_ancora_quando_ela_tem_tabela_propria(): void
    {
        $this->ligarFlag();

        $servico = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);

        // Âncora só tem contrato de Gestão (que, com a flag ligada, não é
        // mais régua) — sem tabela própria cadastrada.
        $ancora = Company::factory()->create(['company_group_id' => $grupo->id, 'name' => 'Âncora Sem Tabela Própria']);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $ancora->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, $ancora);

        $this->assertNull($resultado, 'Com a flag ligada, a herança do grupo não pode mais vir da tabela do serviço — a âncora precisa ter tabela PRÓPRIA.');
    }

    #[Test]
    public function flag_ligada_paragrupo_sem_tabela_de_grupo_herda_da_ancora_quando_ela_tem_tabela_propria(): void
    {
        $this->ligarFlag();

        $grupo = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);

        $ancora = Company::factory()->create(['company_group_id' => $grupo->id, 'name' => 'Âncora Com Tabela Própria']);
        foreach ([1, 2] as $ordem) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $ancora->id,
                'ordem'           => $ordem,
                'limite_superior' => $ordem === 1 ? 100_000 : null,
                'valor'           => $ordem * 2_000,
                'valor_e_piso'    => $ordem !== 1,
                'origem'          => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            ]);
        }

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, $ancora);

        $this->assertNotNull($resultado);
        $this->assertSame('propria', $resultado['origem']);
        $this->assertSame($ancora->id, $resultado['herdada_de_company_id']);
        $this->assertSame('Âncora Com Tabela Própria', $resultado['herdada_de_company_name']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_CONTRATO, $resultado['procedencia']);
    }

    // ─── procedencia sempre presente no shape ────────────────────────────

    #[Test]
    public function shape_sempre_traz_a_chave_procedencia(): void
    {
        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertArrayHasKey('procedencia', $resultado);
    }
}
