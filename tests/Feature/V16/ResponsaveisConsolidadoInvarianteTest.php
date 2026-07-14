<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prova o INVARIANTE consolidado (DEC-A2, prova "c" da 76-VALIDATION):
 * a leitura consolidada `Company::consultor()`/`estrategista()` retorna sempre
 * 1 responsável, MESMO quando a Phase 78 adicionar a 2ª linha (servico_id Shopee)
 * para a mesma empresa+role. As variantes service-aware (*DoServico) distinguem
 * corretamente por serviço.
 */
class ResponsaveisConsolidadoInvarianteTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    public function test_consolidado_retorna_um_responsavel_antes_da_linha_shopee(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];

        $this->assertSame(
            1,
            $company->consultor()->count(),
            'Cenário ML puro deve ter exatamente 1 analista (role consultor) consolidado.'
        );
        $this->assertSame(
            1,
            $company->estrategista()->count(),
            'Cenário ML puro deve ter exatamente 1 estrategista consolidado.'
        );
        $this->assertSame(
            $cenario['analista']->id,
            $company->consultor()->first()->id,
            'O consolidado deve apontar para o analista do cenário.'
        );
    }

    public function test_linha_shopee_nao_altera_o_consolidado(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];

        // Simula a duplicação por-serviço da Phase 78: MESMA empresa, MESMO analista,
        // role 'consultor', mas servico_id do Shopee.
        $shopee = $this->inserirLinhaShopee($company->id, $cenario['analista']->id, 'consultor');

        // O consolidado CONTINUA 1 (->distinct() colapsa ML+Shopee pois servico_id
        // não está no SELECT) e ainda aponta para o mesmo analista.
        $this->assertSame(
            1,
            $company->consultor()->count(),
            'Com a linha Shopee do mesmo analista, o consolidado deve permanecer 1 (dedup por distinct).'
        );
        $this->assertSame(
            $cenario['analista']->id,
            $company->consultor()->first()->id,
            'O consolidado deve continuar apontando para o mesmo analista após a linha Shopee.'
        );

        // As variantes service-aware distinguem por servico_id.
        $this->assertSame(
            1,
            $company->consultorDoServico($shopee['servicoShopee'])->count(),
            'consultorDoServico(shopee) deve encontrar exatamente a linha Shopee.'
        );
        $this->assertSame(
            1,
            $company->consultorDoServico($cenario['servicoPerf'])->count(),
            'consultorDoServico(performance) deve encontrar exatamente a linha ML/performance.'
        );
    }
}
