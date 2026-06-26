<?php

/**
 * Phase 42 Plan 42-05 — Suite Unit do Sugador::linkAdsML.
 *
 * Cobertura: matriz raw_data → URL gerada.
 *
 * Como `Sugador` eh Eloquent, usamos RefreshDatabase. Mesmo nao tendo
 * relacionamentos exercitados aqui, o create() escreve nos casts do model
 * (raw_data => array, etc.) e respeita migration corrente — evita
 * inconsistencia de schema mock.
 *
 * Comentarios em pt-BR.
 */

namespace Tests\Unit\Phase42;

use App\Models\Company;
use App\Models\Sugador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkAdsMlUnitTest extends TestCase
{
    use RefreshDatabase;

    /** Cria sugador minimo com overrides. */
    private function makeSugador(array $overrides = []): Sugador
    {
        $company = Company::create([
            'name'   => 'Empresa Unit ' . random_int(1, 99999),
            'cnpj'   => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active' => true,
        ]);

        $base = [
            'company_id'           => $company->id,
            'reference_date'       => '2026-06-26',
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => 'C1',
            'campaign_name'        => 'Camp 1',
            'adgroup_id'           => 'AG1',
            'periodo_inicio'       => '2026-05-27',
            'periodo_fim'          => '2026-06-25',
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 10,
            'impressoes'           => 100,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => Sugador::STATUS_PENDENTE,
            'raw_data'             => null,
        ];

        return Sugador::create(array_merge($base, $overrides));
    }

    /**
     * T1: origem ML completa (metrics + item_id + type) com campaign_id.
     * URL = Mercado Ads deep link com campaignId.
     */
    #[Test]
    public function origem_ml_completa_gera_url_mercado_ads_com_campaign_id(): void
    {
        $s = $this->makeSugador([
            'campaign_id' => 'C1',
            'raw_data'    => [
                'metrics' => ['cost' => 10.0, 'clicks' => 5],
                'item_id' => 'MLB1',
                'type'    => 'product_ad',
            ],
        ]);

        $url = $s->linkAdsML();

        $this->assertStringContainsString('product-ads/anuncios', $url);
        $this->assertStringContainsString('campaignId=C1', $url);
    }

    /**
     * T2: origem ML detectada SOMENTE por `metrics` (sem item_id/type).
     * URL ainda eh Mercado Ads.
     */
    #[Test]
    public function origem_ml_so_metrics_gera_url_mercado_ads(): void
    {
        $s = $this->makeSugador([
            'campaign_id' => 'C1',
            'raw_data'    => [
                'metrics' => ['cost' => 1.0],
            ],
        ]);

        $url = $s->linkAdsML();

        $this->assertStringContainsString('product-ads', $url);
        $this->assertStringNotContainsString('/anuncios/campanhas/', $url);
    }

    /**
     * T3: origem ML detectada por `item_id` + `type` (sem metrics).
     * URL ainda eh Mercado Ads.
     */
    #[Test]
    public function origem_ml_so_item_id_e_type_gera_url_mercado_ads(): void
    {
        $s = $this->makeSugador([
            'campaign_id' => 'C1',
            'raw_data'    => [
                'item_id' => 'MLB1',
                'type'    => 'product_ad',
            ],
        ]);

        $url = $s->linkAdsML();

        $this->assertStringContainsString('product-ads', $url);
        $this->assertStringNotContainsString('/anuncios/campanhas/', $url);
    }

    /**
     * T4: raw_data Adman-like (sem chaves caracteristicas ML).
     * URL = legacy /anuncios/campanhas/{C1} — zero regressao.
     */
    #[Test]
    public function raw_data_adman_mantem_url_legacy(): void
    {
        $s = $this->makeSugador([
            'campaign_id' => 'C1',
            'raw_data'    => [
                'campaignId' => 123,
                'accountId'  => 456,
            ],
        ]);

        $url = $s->linkAdsML();

        $this->assertSame('https://www.mercadolivre.com.br/anuncios/campanhas/C1', $url);
    }

    /**
     * T5: raw_data null (sugador antigo pre-Phase 42-03) com campaign_id.
     * URL = path Adman legacy. Backward compatibility garantida.
     */
    #[Test]
    public function raw_data_null_cai_em_path_adman_legacy(): void
    {
        $s = $this->makeSugador([
            'campaign_id' => 'C1',
            'raw_data'    => null,
        ]);

        $url = $s->linkAdsML();

        $this->assertSame('https://www.mercadolivre.com.br/anuncios/campanhas/C1', $url);
    }
}
