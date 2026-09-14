<?php

namespace Tests\Feature\Phase143;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoFaixaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 01 — Tarefa 4: a precedência da tabela passa de
 * grupo → empresa → serviço para **raiz → subgrupo → empresa → serviço**.
 *
 * Molde: `Tests\Feature\Phase138\Phase138GrupoFaixaResolverTest` — o serviço
 * "Gestão" já vem semeado com 7 faixas pela migration
 * `2026_09_02_100003_seed_faixas_faturamento_iniciais`, dentro do
 * `RefreshDatabase`.
 *
 * ⛔ `classificar()` NÃO muda nesta fase: a régua `limite_superior >=
 * faturamento` classifica cobrança viva de 171 empresas. O que muda é a
 * PROCURA da tabela — e há um teste aqui só para travar isso.
 */
class Phase143ResolverPrecedenciaTest extends TestCase
{
    use RefreshDatabase;

    private FechamentoFaixaResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(FechamentoFaixaResolver::class);
    }

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

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        return $company;
    }

    /** Tabela de um grupo qualquer, com valor marcador para saber de onde veio. */
    private function criarFaixasDeGrupo(CompanyGroup $grupo, float $valor): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'            => 1,
            'limite_superior'  => null,
            'valor'            => $valor,
            'valor_e_piso'     => false,
        ]);
    }

    private function criarFaixasPropriasDaEmpresa(Company $company, float $valor): void
    {
        EmpresaFaixaFaturamento::create([
            'company_id'      => $company->id,
            'ordem'           => 1,
            'limite_superior' => null,
            'valor'           => $valor,
            'valor_e_piso'    => false,
        ]);
    }

    // ─── Regressão zero: enquanto ninguém tem pai ─────────────────────────

    #[Test]
    public function grupo_sem_pai_resolve_exatamente_como_antes(): void
    {
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Pai', 'color' => '#000']);
        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        $this->criarFaixasDeGrupo($grupo, 5_000.00);
        $this->criarFaixasPropriasDaEmpresa($empresa, 777.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('grupo', $resultado['origem']);
        $this->assertSame($grupo->id, $resultado['grupo_id']);
        $this->assertSame('Grupo Sem Pai', $resultado['grupo_nome']);
        $this->assertEqualsWithDelta(5_000.00, (float) $resultado['faixas']->first()->valor, 0.01);
        $this->assertNull($resultado['herdada_de_company_id']);
    }

    #[Test]
    public function grupo_sem_pai_e_sem_tabela_continua_caindo_na_empresa(): void
    {
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Pelado', 'color' => '#000']);
        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        $this->criarFaixasPropriasDaEmpresa($empresa, 777.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('propria', $resultado['origem']);
        $this->assertEqualsWithDelta(777.00, (float) $resultado['faixas']->first()->valor, 0.01);
    }

    // ─── A árvore: raiz → subgrupo → empresa ──────────────────────────────

    #[Test]
    public function tabela_so_na_raiz_vence_a_da_empresa_do_subgrupo(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);

        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasPropriasDaEmpresa($empresa, 777.00);
        $this->criarFaixasDeGrupo($raiz, 21_000.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('grupo', $resultado['origem']);
        $this->assertEqualsWithDelta(21_000.00, (float) $resultado['faixas']->first()->valor, 0.01);
        $this->assertSame(
            $raiz->id,
            $resultado['grupo_id'],
            'grupo_id/grupo_nome identificam o DONO da tabela: a RAIZ, nunca o subgrupo — herança invisível é o defeito que a D-01 da Fase 138 veio corrigir.'
        );
        $this->assertSame('MPozenato', $resultado['grupo_nome']);
    }

    #[Test]
    public function com_tabela_na_raiz_e_no_subgrupo_a_da_raiz_vence(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'Gran Belo', 'color' => '#000', 'parent_id' => $raiz->id]);

        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasDeGrupo($raiz, 21_000.00);
        $this->criarFaixasDeGrupo($sub, 12_000.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('grupo', $resultado['origem']);
        $this->assertEqualsWithDelta(
            21_000.00,
            (float) $resultado['faixas']->first()->valor,
            0.01,
            'Quem cobra é a raiz — a tabela do subgrupo só vale quando a raiz não tem nenhuma.'
        );
        $this->assertSame($raiz->id, $resultado['grupo_id']);
    }

    #[Test]
    public function sem_tabela_na_raiz_vale_a_do_subgrupo(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000', 'parent_id' => $raiz->id]);

        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasPropriasDaEmpresa($empresa, 777.00);
        $this->criarFaixasDeGrupo($sub, 6_000.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('grupo', $resultado['origem']);
        $this->assertEqualsWithDelta(6_000.00, (float) $resultado['faixas']->first()->valor, 0.01);
        $this->assertSame($sub->id, $resultado['grupo_id'], 'A tabela veio do subgrupo — é ele que o shape identifica.');
        $this->assertSame('Lyam', $resultado['grupo_nome']);
    }

    #[Test]
    public function sem_tabela_em_lugar_nenhum_da_arvore_cai_na_empresa(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);

        $empresa = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasPropriasDaEmpresa($empresa, 777.00);

        $resultado = $this->resolver->paraEmpresa($empresa->fresh());

        $this->assertSame('propria', $resultado['origem']);
        $this->assertEqualsWithDelta(777.00, (float) $resultado['faixas']->first()->valor, 0.01);
    }

    #[Test]
    public function tabela_de_um_subgrupo_nao_vaza_para_o_subgrupo_irmao(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $subA   = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);
        $subB   = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000', 'parent_id' => $raiz->id]);

        $empresaA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $subA->id]);
        $empresaB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $subB->id]);

        $this->criarFaixasDeGrupo($subA, 6_000.00);
        $this->criarFaixasPropriasDaEmpresa($empresaB, 777.00);

        $this->assertEqualsWithDelta(
            6_000.00,
            (float) $this->resolver->paraEmpresa($empresaA->fresh())['faixas']->first()->valor,
            0.01
        );
        $this->assertSame(
            'propria',
            $this->resolver->paraEmpresa($empresaB->fresh())['origem'],
            'A tabela de um subgrupo vale só para ele — irmão não herda de irmão.'
        );
    }

    // ─── paraGrupo() segue a MESMA ordem ──────────────────────────────────

    #[Test]
    public function para_grupo_chamado_com_o_subgrupo_devolve_a_tabela_da_raiz(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);

        $ancora = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasDeGrupo($raiz, 21_000.00);
        $this->criarFaixasDeGrupo($sub, 12_000.00);

        $resultado = $this->resolver->paraGrupo($sub->fresh(), $ancora);

        $this->assertSame('grupo', $resultado['origem']);
        $this->assertSame($raiz->id, $resultado['grupo_id']);
        $this->assertEqualsWithDelta(21_000.00, (float) $resultado['faixas']->first()->valor, 0.01);
        $this->assertNull($resultado['herdada_de_company_id'], 'Tabela encontrada na árvore não é herança de empresa.');
    }

    #[Test]
    public function para_grupo_sem_tabela_na_arvore_continua_herdando_da_ancora_com_a_marca_visivel(): void
    {
        $gestao = $this->criarServicoGestao();
        $raiz   = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $sub    = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $raiz->id]);

        $ancora = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $sub->id]);
        $this->criarFaixasPropriasDaEmpresa($ancora, 777.00);

        $resultado = $this->resolver->paraGrupo($sub->fresh(), $ancora);

        $this->assertSame('propria', $resultado['origem']);
        $this->assertSame($ancora->id, $resultado['herdada_de_company_id']);
        $this->assertSame($ancora->name, $resultado['herdada_de_company_name']);
    }

    // ─── A régua de corte continua intocada ───────────────────────────────

    #[Test]
    public function classificar_continua_cortando_por_limite_superior_maior_ou_igual(): void
    {
        $raiz = CompanyGroup::create(['name' => 'Raiz', 'color' => '#000']);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 500_000.00, 'valor' => 3_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);

        $faixas = GrupoFaixaFaturamento::where('company_group_id', $raiz->id)->ordenadas()->get();

        // Exatamente NO limite continua caindo na faixa de baixo (>=).
        $this->assertSame(1, $this->resolver->classificar(500_000.00, $faixas)['ordem']);
        $this->assertSame(1, $this->resolver->classificar(499_999.99, $faixas)['ordem']);
        $this->assertSame(2, $this->resolver->classificar(500_000.01, $faixas)['ordem']);
        $this->assertSame(2, $this->resolver->classificar(12_679_411.83, $faixas)['ordem']);
    }
}
