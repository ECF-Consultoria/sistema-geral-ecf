<?php

namespace Tests\Feature\Phase141;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\Fechamento\FechamentoRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 01 — Tarefa 2: recorte por plataforma contratada (D-02).
 *
 * Prova que `porEmpresa($mes, $companies, somenteContratadas: true)` só soma
 * o faturamento das plataformas em que a empresa tem serviço CONTRATADO e
 * ATIVO cobrado por tabela progressiva — e que o modo atual (default,
 * `false`) continua byte a byte igual ao de hoje, só com a chave nova
 * `plataformas_consideradas` adicionada.
 */
class Phase141ElegibilidadePlataformaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Cria um serviço de catálogo já configurado para os testes desta
     * suíte — sem ServicoFactory dedicada no projeto (mesma disciplina do
     * `ContratoServicoFactory::definition()`).
     */
    private function criarServico(string $nome, string $setor, ?string $plataforma, bool $usaTabelaProgressiva): Servico
    {
        return Servico::create([
            'nome'                    => $nome,
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => $setor,
            'plataforma'              => $plataforma,
            'usa_tabela_progressiva'  => $usaTabelaProgressiva,
        ]);
    }

    #[Test]
    public function empresa_so_com_mentoria_nao_soma_nada_mesmo_com_metrica_de_ml(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $company  = Company::factory()->create();
        $mentoria = $this->criarServico('Mentoria', Servico::SETOR_PERFORMANCE, 'Mercado Livre', usaTabelaProgressiva: false);

        ContratoServico::factory()->for($company)->paraServico($mentoria)->create(['ativo' => true]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08', Company::whereKey($company->id)->get(), somenteContratadas: true);

        $this->assertNull($resultado[$company->id]['faturamento_ml']);
        $this->assertNull($resultado[$company->id]['faturamento_shopee']);
        $this->assertNull($resultado[$company->id]['faturamento_total']);
        $this->assertSame([], $resultado[$company->id]['plataformas_consideradas']);
    }

    #[Test]
    public function empresa_com_gestao_e_gestao_ads_shopee_soma_as_duas_plataformas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $company = Company::factory()->create();

        $gestao       = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 'Mercado Livre', usaTabelaProgressiva: true);
        $gestaoShopee = $this->criarServico('Gestão de ADS Shopee', Servico::SETOR_SHOPEE, 'Shopee', usaTabelaProgressiva: true);

        ContratoServico::factory()->for($company)->paraServico($gestao)->create(['ativo' => true]);
        ContratoServico::factory()->for($company)->paraServico($gestaoShopee)->create(['ativo' => true]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08', Company::whereKey($company->id)->get(), somenteContratadas: true);

        $this->assertEqualsWithDelta(10_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(4_000.00, $resultado[$company->id]['faturamento_shopee'], 0.001);
        $this->assertEqualsWithDelta(14_000.00, $resultado[$company->id]['faturamento_total'], 0.001);
        $this->assertEqualsCanonicalizing(['ml', 'shopee'], $resultado[$company->id]['plataformas_consideradas']);
    }

    #[Test]
    public function empresa_so_com_gestao_ml_nao_soma_metrica_shopee_avulsa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $company = Company::factory()->create();
        $gestao  = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, 'Mercado Livre', usaTabelaProgressiva: true);

        ContratoServico::factory()->for($company)->paraServico($gestao)->create(['ativo' => true]);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);
        // Empresa tem linha de shopee_metrics, mas NENHUM serviço de Shopee
        // contratado — não pode entrar na soma.
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08', Company::whereKey($company->id)->get(), somenteContratadas: true);

        $this->assertEqualsWithDelta(10_000.00, $resultado[$company->id]['faturamento_ml'], 0.001);
        $this->assertNull($resultado[$company->id]['faturamento_shopee']);
        $this->assertEqualsWithDelta(10_000.00, $resultado[$company->id]['faturamento_total'], 0.001);
        $this->assertSame(['ml'], $resultado[$company->id]['plataformas_consideradas']);
    }

    #[Test]
    public function contrato_inativo_de_shopee_nao_habilita_a_plataforma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $company      = Company::factory()->create();
        $gestaoShopee = $this->criarServico('Gestão de ADS Shopee', Servico::SETOR_SHOPEE, 'Shopee', usaTabelaProgressiva: true);

        ContratoServico::factory()->for($company)->paraServico($gestaoShopee)->create(['ativo' => false]);

        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $service   = app(FechamentoRollupService::class);
        $resultado = $service->porEmpresa('2026-08', Company::whereKey($company->id)->get(), somenteContratadas: true);

        $this->assertNull($resultado[$company->id]['faturamento_shopee']);
        $this->assertSame([], $resultado[$company->id]['plataformas_consideradas']);
    }

    #[Test]
    public function com_parametro_desligado_retorno_e_o_de_hoje_mais_a_chave_nova(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $company = Company::factory()->create();

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 10_000.00]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 4_000.00]);

        $service = app(FechamentoRollupService::class);

        // Sem terceiro parâmetro — assinatura antiga intacta.
        $resultadoPadrao = $service->porEmpresa('2026-08');

        $this->assertEqualsWithDelta(10_000.00, $resultadoPadrao[$company->id]['faturamento_ml'], 0.001);
        $this->assertEqualsWithDelta(4_000.00, $resultadoPadrao[$company->id]['faturamento_shopee'], 0.001);
        $this->assertEqualsWithDelta(14_000.00, $resultadoPadrao[$company->id]['faturamento_total'], 0.001);
        $this->assertSame(['ml', 'shopee'], $resultadoPadrao[$company->id]['plataformas_consideradas']);
    }

    #[Test]
    public function somente_contratadas_true_sem_companies_lanca_excecao(): void
    {
        $service = app(FechamentoRollupService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->porEmpresa('2026-08', null, somenteContratadas: true);
    }

    // ─── plataformasElegiveis() isolado ─────────────────────────────────

    #[Test]
    public function plataformas_elegiveis_devolve_vazio_para_empresa_sem_contrato_com_tabela(): void
    {
        $company  = Company::factory()->create();
        $mentoria = $this->criarServico('Mentoria', Servico::SETOR_PERFORMANCE, null, usaTabelaProgressiva: false);

        ContratoServico::factory()->for($company)->paraServico($mentoria)->create(['ativo' => true]);

        $service = app(FechamentoRollupService::class);

        $this->assertSame([], $service->plataformasElegiveis($company));
    }

    #[Test]
    public function plataformas_elegiveis_usa_setor_como_rede_de_seguranca_quando_texto_da_plataforma_e_nulo(): void
    {
        $company = Company::factory()->create();
        $gestao  = $this->criarServico('Gestão', Servico::SETOR_PERFORMANCE, null, usaTabelaProgressiva: true);

        ContratoServico::factory()->for($company)->paraServico($gestao)->create(['ativo' => true]);

        $service = app(FechamentoRollupService::class);

        $this->assertSame(['ml'], $service->plataformasElegiveis($company));
    }
}
