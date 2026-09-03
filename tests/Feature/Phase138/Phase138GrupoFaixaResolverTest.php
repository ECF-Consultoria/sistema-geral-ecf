<?php

namespace Tests\Feature\Phase138;

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
 * Fase 138 Plano 01 — Tarefa 2: degrau de grupo no `FechamentoFaixaResolver`
 * (D-01, precedência grupo → empresa → serviço) e `paraGrupo()`.
 *
 * Molde de fixture igual ao de `Phase137FaixaResolverTest` — o serviço
 * "Gestão" já vem semeado com 7 faixas pela migration
 * `2026_09_02_100003_seed_faixas_faturamento_iniciais`, que roda dentro do
 * `RefreshDatabase`.
 */
class Phase138GrupoFaixaResolverTest extends TestCase
{
    use RefreshDatabase;

    /** As 8 chaves do shape único devolvido por paraEmpresa()/paraGrupo(). */
    private const CHAVES_DO_SHAPE = [
        'origem', 'servico_id', 'servico_nome', 'grupo_id', 'grupo_nome',
        'herdada_de_company_id', 'herdada_de_company_name', 'faixas',
    ];

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarFaixasDeGrupo(CompanyGroup $grupo): void
    {
        foreach ([1, 2] as $ordem) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $ordem === 1 ? 200_000.00 : null,
                'valor'             => $ordem * 5_000,
                'valor_e_piso'      => $ordem !== 1,
            ]);
        }
    }

    // ─── paraEmpresa() — degrau de grupo ────────────────────────────────

    #[Test]
    public function empresa_sem_grupo_e_sem_tabela_propria_resolve_para_o_servico_como_antes(): void
    {
        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create(['company_group_id' => null]);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($servico->id, $resultado['servico_id']);
        $this->assertNull($resultado['grupo_id']);
        $this->assertNull($resultado['herdada_de_company_id']);
        $this->assertSameShapeKeys($resultado);
    }

    #[Test]
    public function empresa_em_grupo_sem_tabela_de_grupo_resolve_exatamente_como_antes(): void
    {
        $servico = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);

        $company = Company::factory()->create(['company_group_id' => $grupo->id]);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem'], 'Grupo sem tabela própria não muda o resultado de antes.');
        $this->assertSame($servico->id, $resultado['servico_id']);
        $this->assertNull($resultado['grupo_id']);
        $this->assertSameShapeKeys($resultado);
    }

    #[Test]
    public function empresa_em_grupo_com_tabela_de_grupo_vence_mesmo_com_tabela_propria(): void
    {
        $servico = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Com Tabela']);
        $this->criarFaixasDeGrupo($grupo);

        $company = Company::factory()->create(['company_group_id' => $grupo->id]);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        // Empresa também tem tabela própria cadastrada — mesmo assim o
        // grupo vence (D-01).
        foreach ([1, 2, 3] as $ordem) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $ordem * 100_000,
                'valor'           => $ordem * 1_000,
                'valor_e_piso'    => false,
            ]);
        }

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('grupo', $resultado['origem']);
        $this->assertSame($grupo->id, $resultado['grupo_id']);
        $this->assertSame('Grupo Com Tabela', $resultado['grupo_nome']);
        $this->assertNull($resultado['servico_id']);
        $this->assertNull($resultado['herdada_de_company_id']);
        $this->assertCount(2, $resultado['faixas']);
        $this->assertSameShapeKeys($resultado);
    }

    // ─── paraGrupo() ─────────────────────────────────────────────────────

    #[Test]
    public function para_grupo_com_tabela_propria_devolve_origem_grupo_sem_heranca(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Com Tabela']);
        $this->criarFaixasDeGrupo($grupo);

        $ancora = Company::factory()->create(['company_group_id' => $grupo->id]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, $ancora);

        $this->assertNotNull($resultado);
        $this->assertSame('grupo', $resultado['origem']);
        $this->assertSame($grupo->id, $resultado['grupo_id']);
        $this->assertNull($resultado['herdada_de_company_id']);
        $this->assertNull($resultado['herdada_de_company_name']);
        $this->assertCount(2, $resultado['faixas']);
        $this->assertSameShapeKeys($resultado);
    }

    #[Test]
    public function para_grupo_sem_tabela_devolve_a_tabela_da_ancora_com_heranca_marcada(): void
    {
        $servico = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);

        $ancora = Company::factory()->create(['company_group_id' => $grupo->id, 'name' => 'Empresa Âncora']);
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $ancora->id, 'ativo' => true]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, $ancora);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($servico->id, $resultado['servico_id']);
        $this->assertSame($ancora->id, $resultado['herdada_de_company_id']);
        $this->assertSame('Empresa Âncora', $resultado['herdada_de_company_name']);
        $this->assertSameShapeKeys($resultado);
    }

    #[Test]
    public function para_grupo_sem_tabela_e_sem_ancora_devolve_null(): void
    {
        $grupo = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, null);

        $this->assertNull($resultado);
    }

    #[Test]
    public function para_grupo_sem_tabela_com_ancora_sem_tabela_nenhuma_devolve_null(): void
    {
        $grupo  = CompanyGroup::create(['name' => 'Grupo Sem Tabela']);
        $ancora = Company::factory()->create(['company_group_id' => $grupo->id]);

        $resolver  = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraGrupo($grupo, $ancora);

        $this->assertNull($resultado, 'Âncora sem nenhuma tabela resolvida é estado A DEFINIR, nunca faixa aproximada.');
    }

    private function assertSameShapeKeys(array $resultado): void
    {
        foreach (self::CHAVES_DO_SHAPE as $chave) {
            $this->assertArrayHasKey($chave, $resultado, "Chave '{$chave}' precisa estar sempre presente no shape.");
        }
    }
}
