<?php

namespace Tests\Feature\Phase137;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\FechamentoFaixaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 03 — Tarefa 1: FechamentoFaixaResolver.
 *
 * Cobre D-01 (herança serviço→empresa), D-13 (exceção all-or-nothing) e a
 * classificação de faturamento em faixa, incluindo a faixa-piso de
 * Gestão/Brigada e a ausência de faixa aberta em Shopee.
 *
 * Cada fixture cria os serviços à mão (RefreshDatabase só semeia o catálogo
 * base da Fase 14, sem `plataforma`/`setor` preenchidos e sem "Brigada" nem
 * "Gestão de ADS Shopee" — mesmo estado de partida que
 * `Phase137FaixasSchemaTest`).
 *
 * ⚠️ A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` roda
 * dentro do `RefreshDatabase` (é uma migration normal) e JÁ semeia as 7
 * faixas de "Gestão" (o único dos três serviços que existe no catálogo base
 * no momento em que ela roda — "Brigada" e "Gestão de ADS Shopee" ainda não
 * existem, ela os pula em silêncio). Por isso `criarServicoGestao()` NÃO
 * chama `semearFaixasGestaoBrigada()` — semear de novo estouraria a unique
 * (servico_id, ordem). Para Brigada e Shopee, que nascem como fixture DEPOIS
 * da migration, a semeadura manual é necessária.
 */
class Phase137FaixaResolverTest extends TestCase
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

    private function criarServicoBrigada(): Servico
    {
        return Servico::create([
            'nome'          => 'Brigada',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
    }

    private function criarServicoShopee(?int $donoId = null): Servico
    {
        return Servico::create([
            'nome'                           => 'Gestão de ADS Shopee',
            'valor_padrao'                   => 0,
            'tipo_cobranca'                  => Servico::TIPO_MENSAL,
            'ativo'                          => true,
            'setor'                          => Servico::SETOR_SHOPEE,
            'plataforma'                     => 'Shopee',
            'contrato_junto_com_servico_id'  => $donoId,
        ]);
    }

    /** Semeia as 7 faixas de Gestão/Brigada (D-02b) para o serviço informado. */
    private function semearFaixasGestaoBrigada(Servico $servico): void
    {
        $faixas = [
            [1, 499_999.99, 3_000.00, false],
            [2, 999_999.99, 4_500.00, false],
            [3, 1_999_999.99, 6_000.00, false],
            [4, 2_999_999.99, 7_500.00, false],
            [5, 3_999_999.99, 9_000.00, false],
            [6, 4_999_999.99, 10_500.00, false],
            [7, null, 12_000.00, true],
        ];

        foreach ($faixas as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            ServicoFaixaFaturamento::create([
                'servico_id'      => $servico->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
            ]);
        }
    }

    /** Semeia as 8 faixas de Shopee (D-02b) — todas fechadas, sem piso. */
    private function semearFaixasShopee(Servico $servico): void
    {
        $faixas = [
            [1, 50_000.00, 1_500.00],
            [2, 150_000.00, 2_000.00],
            [3, 250_000.00, 2_500.00],
            [4, 500_000.00, 3_000.00],
            [5, 1_000_000.00, 3_500.00],
            [6, 1_500_000.00, 4_000.00],
            [7, 2_000_000.00, 4_500.00],
            [8, 3_000_000.00, 5_000.00],
        ];

        foreach ($faixas as [$ordem, $limiteSuperior, $valor]) {
            ServicoFaixaFaturamento::create([
                'servico_id'      => $servico->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => false,
            ]);
        }
    }

    // ─── paraEmpresa() ───────────────────────────────────────────────────

    #[Test]
    public function empresa_com_excecao_propria_ignora_a_tabela_do_servico(): void
    {
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
            ]);
        }

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('propria', $resultado['origem']);
        $this->assertNull($resultado['servico_id']);
        $this->assertCount(3, $resultado['faixas'], 'Exceção própria de 3 faixas nunca recebe uma 4ª faixa vinda do serviço (D-13).');
    }

    #[Test]
    public function empresa_sem_excecao_com_contrato_de_gestao_resolve_para_o_servico(): void
    {
        $servico = $this->criarServicoGestao();

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($servico->id, $resultado['servico_id']);
        $this->assertCount(7, $resultado['faixas']);
    }

    #[Test]
    public function empresa_com_shopee_e_gestao_ativos_resolve_para_gestao_contrato_combinado(): void
    {
        $gestao = $this->criarServicoGestao();

        $shopee = $this->criarServicoShopee(donoId: $gestao->id);
        $this->semearFaixasShopee($shopee);

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($gestao->id, $resultado['servico_id'], 'Contrato combinado (D-05): a tabela do dono (Gestão) vence.');
        $this->assertCount(7, $resultado['faixas']);
    }

    #[Test]
    public function empresa_so_com_shopee_sem_faixas_cadastradas_resolve_null(): void
    {
        // Dono ausente (empresa não tem contrato de Gestão) e nenhuma linha
        // em servico_faixas_faturamento para este serviço — estado real de
        // "A DEFINIR" até o cadastro do checkpoint do plano 10.
        $shopee = $this->criarServicoShopee(donoId: null);

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNull($resultado);
    }

    #[Test]
    public function empresa_com_brigada_sem_dono_resolve_para_a_propria_tabela_de_brigada(): void
    {
        $brigada = $this->criarServicoBrigada();
        $this->semearFaixasGestaoBrigada($brigada);

        $company = Company::factory()->create();
        ContratoServico::factory()->paraServico($brigada)->create(['company_id' => $company->id, 'ativo' => true]);

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNotNull($resultado);
        $this->assertSame('servico', $resultado['origem']);
        $this->assertSame($brigada->id, $resultado['servico_id']);
        $this->assertCount(7, $resultado['faixas']);
    }

    #[Test]
    public function empresa_sem_nenhum_contrato_candidato_resolve_null(): void
    {
        $company = Company::factory()->create();

        $resolver = app(FechamentoFaixaResolver::class);
        $resultado = $resolver->paraEmpresa($company);

        $this->assertNull($resultado);
    }

    // ─── classificar() ──────────────────────────────────────────────────

    #[Test]
    public function classificar_450_mil_devolve_a_primeira_faixa_de_gestao(): void
    {
        $servico = $this->criarServicoGestao();
        $faixas = ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get();

        $resolver = app(FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(450_000.00, $faixas);

        $this->assertNotNull($classificacao);
        $this->assertSame(1, $classificacao['ordem']);
        $this->assertEqualsWithDelta(3_000.00, $classificacao['valor'], 0.001);
        $this->assertEqualsWithDelta(0.0, $classificacao['limite_inferior'], 0.001);
        $this->assertEqualsWithDelta(499_999.99, $classificacao['limite_superior'], 0.001);
    }

    #[Test]
    public function classificar_9_milhoes_devolve_a_faixa_maxima_de_gestao(): void
    {
        $servico = $this->criarServicoGestao();
        $faixas = ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get();

        $resolver = app(FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(9_000_000.00, $faixas);

        $this->assertNotNull($classificacao);
        $this->assertSame(7, $classificacao['ordem']);
        $this->assertEqualsWithDelta(12_000.00, $classificacao['valor'], 0.001);
        $this->assertNull($classificacao['limite_superior']);
        $this->assertSame('maxima', $classificacao['label']);
    }

    #[Test]
    public function classificar_500_mil_devolve_a_segunda_faixa_limite_exclusivo_por_cima(): void
    {
        $servico = $this->criarServicoGestao();
        $faixas = ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get();

        $resolver = app(FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(500_000.00, $faixas);

        $this->assertNotNull($classificacao);
        $this->assertSame(2, $classificacao['ordem'], 'O limite da faixa 1 é 499.999,99 — R$ 500.000,00 cai na faixa 2.');
    }

    #[Test]
    public function classificar_9_milhoes_marca_valor_e_piso_verdadeiro(): void
    {
        $servico = $this->criarServicoGestao();
        $faixas = ServicoFaixaFaturamento::where('servico_id', $servico->id)->ordenadas()->get();

        $resolver = app(FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(9_000_000.00, $faixas);

        $this->assertNotNull($classificacao);
        $this->assertTrue($classificacao['valor_e_piso'], 'A última faixa de Gestão é "a partir de R$ 12.000" — piso, não preço fechado.');
    }

    #[Test]
    public function classificar_acima_do_teto_de_shopee_devolve_null(): void
    {
        $shopee = $this->criarServicoShopee();
        $this->semearFaixasShopee($shopee);
        $faixas = ServicoFaixaFaturamento::where('servico_id', $shopee->id)->ordenadas()->get();

        $resolver = app(FechamentoFaixaResolver::class);
        $classificacao = $resolver->classificar(4_000_000.00, $faixas);

        $this->assertNull($classificacao, 'A tabela de Shopee não tem faixa aberta (teto R$ 3.000.000) — nunca devolver a última faixa por aproximação.');
    }
}
