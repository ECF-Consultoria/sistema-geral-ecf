<?php

namespace App\Contracts;

use App\Models\Company;
use Carbon\Carbon;

/**
 * Contract de provider de dados de anúncios para o módulo Sugadores.
 *
 * Phase 39 (migração Adman → Mercado Livre direto): separa o "como buscar
 * dados de anúncios" (Adman API hoje, Mercado Livre Ads API em Phase 39-02+)
 * do "como detectar sugadores" (SugadorAnalysisService — Phase 39-04 refatora
 * para consumir este contract via factory).
 *
 * Fonte canônica do contrato normalizado: `plano-migracao-sugadores-ml-direto.md`
 * §2.2 (campanhas) e §2.3 (adgroups). Toda implementação deste contract DEVE
 * retornar os payloads com as chaves listadas nos PHPDoc de cada método —
 * o SugadorAnalysisService confia neste shape.
 *
 * Implementações atuais:
 *  - {@see \App\Services\Sugadores\AdmanSugadoresProvider} (Phase 39-01) — encapsula
 *    {@see \App\Services\AdmanService} via composição, sem modificá-lo.
 *  - {@see \App\Services\Sugadores\MercadoLivreSugadoresProvider} (Phase 39-02) — usa
 *    {@see \App\Services\Sugadores\MercadoLivreAdsService} (Phase 38).
 *
 * Resolução via {@see \App\Services\Sugadores\SugadoresAdsProviderFactory}.
 */
interface SugadoresAdsProvider
{
    /**
     * Retorna true se este provider sabe lidar com a empresa.
     *
     * Exemplos:
     *  - AdmanSugadoresProvider::supports() === true quando $company->adman_account_id existe;
     *  - MercadoLivreSugadoresProvider::supports() === true quando $company tem mlToken ativo.
     *
     * O factory chama este método para auto-resolução quando nenhum `forceName`
     * é passado (Plan 39-02 completa a lógica).
     */
    public function supports(Company $company): bool;

    /**
     * Identificador estável do provider para logs/relatórios/dry-run output.
     *
     * Valores conhecidos: 'adman', 'ml'. Não traduzir — usado em chaves de cache,
     * mensagens CLI e correlação de logs entre runs.
     */
    public function name(): string;

    /**
     * Lista campanhas atuais da empresa. Usado pelo SugadorAnalysisService para
     * a regra de quarentena (campanhas com nome SGI/Sugador ou status
     * paused/closed/ended são puladas — adgroups dentro delas não viram sugador).
     *
     * Cada item normalizado contém OBRIGATORIAMENTE:
     *  - 'campaign_id'     string         — ID estável da campanha no provider
     *  - 'campaign_name'   string|null    — nome legível
     *  - 'campaign_status' string|null    — active/paused/closed/ended (lowercase preferível)
     *  - 'raw'             array|null     — payload bruto do provider para debug
     *
     * @return array<int, array{campaign_id: string, campaign_name: ?string, campaign_status: ?string, raw: ?array}>
     */
    public function fetchCampaigns(Company $company): array;

    /**
     * Métricas agregadas POR CAMPANHA no período. Usado para detectar sugadores
     * tipo='campanha' (campanhas com ACOS alto, ROAS baixo, etc).
     *
     * Cada item normalizado contém OBRIGATORIAMENTE (§2.2 do plano):
     *  - 'campaign_id'     string
     *  - 'campaign_name'   string|null
     *  - 'campaign_status' string|null
     *  - 'investment'      float|null
     *  - 'revenue'         float|null
     *  - 'sold_quantity'   int|null
     *  - 'clicks'          int|null
     *  - 'impressions'     int|null
     *  - 'cpc'             float|null   — calcular via safe_div se provider não pré-calcular
     *  - 'acos'            float|null   — em % (0..100)
     *  - 'roas'            float|null
     *  - 'raw'             array|null
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCampaignsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /**
     * Métricas POR ADGROUP/ANÚNCIO no período. Base canônica da detecção de
     * sugadores tipo='adgroup' (a unidade prática de "anúncio" no MLB Ads).
     *
     * Cada item normalizado contém OBRIGATORIAMENTE as 20 chaves do §2.3 do
     * plano de migração (mesmas que SugadorAnalysisService::analyzeCompany
     * consome hoje via AdmanService::fetchAdsMetrics):
     *
     *  - 'adgroup_id'      string
     *  - 'adgroup_name'    string|null
     *  - 'campaign_id'     string
     *  - 'thumbnail'       string|null   — URL da imagem do produto
     *  - 'adgroup_type'    string|null   — CATALOG/PRODUCT/MANUAL
     *  - 'catalog_listing' bool
     *  - 'mlb_id'          string|null   — ID MLB primário (quando aplicável)
     *  - 'mlb_titulo'      string|null
     *  - 'investment'      float|null
     *  - 'revenue'         float|null
     *  - 'sold_quantity'   int|null
     *  - 'clicks'          int|null
     *  - 'impressions'     int|null
     *  - 'cpc'             float|null   — calcular via safe_div se ausente
     *  - 'ctr'             float|null   — calcular via safe_div se ausente
     *  - 'acos'            float|null   — em % (0..100)
     *  - 'roas'            float|null
     *  - 'organic_amount'  float|null   — receita orgânica no período (contexto)
     *  - 'organic_units'   int|null
     *  - 'raw'             array|null
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAdgroupsMetrics(Company $company, Carbon $from, Carbon $to): array;

    /**
     * Mapeamento adgroup_id → lista de MLB IDs no período.
     *
     * Usado para popular o cache em `adman_adgroup_mlbs` (abstraído pelo
     * AdgroupMlbMapRepository — Plan 39-03). O provider Adman retorna `[]`
     * porque esse mapeamento é populado via Job separado
     * (SyncCompanyAdgroupMlbsJob). O provider ML (Plan 39-02) extrai
     * adgroup→items do endpoint `/product_ads/items`.
     *
     * @return array<string, array<int, string>>
     */
    public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array;
}
