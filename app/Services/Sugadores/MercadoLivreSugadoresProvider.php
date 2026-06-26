<?php

namespace App\Services\Sugadores;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Implementação Mercado Livre do contract SugadoresAdsProvider.
 *
 * Phase 39 Plan 39-02 (migração Sugadores Adman → Mercado Livre direto):
 * Encapsula o {@see MercadoLivreAdsService} (Phase 38 Plan 38-01) via COMPOSIÇÃO
 * (não herança, não modificação) e adapta o payload Mercado Ads ao contrato
 * normalizado §2.2/§2.3 do plano de migração.
 *
 * Decisões de design:
 *  - MercadoLivreAdsService NÃO é modificado — todos os métodos consumidos são
 *    públicos e já tratam auth/refresh do mlToken via MercadoLivreService (Phase 20).
 *  - Provider NUNCA toca access_token nem refresh_token — a autenticação é
 *    100% delegada à camada Phase 20 (T-39-02-01 anti-leak).
 *  - safe_div local cobre cpc/ctr/acos/roas quando o payload ML não os pré-calcula.
 *  - fetchAdgroupMlbs extrai o mapeamento adgroup→item_id direto do payload de ads
 *    (ao contrário do path Adman, que depende do Job SyncCompanyAdgroupMlbsJob).
 *
 * CANDIDATO — revalidar após smoke real Phase 38 Tarefa 3 (smoke DEFERIDO por
 * bloqueio MariaDB local — ver .planning/quick/260625-mrd-reparar-mariadb-local/).
 * O mapeamento ML → contrato (cost→investment, total_amount→revenue,
 * units_quantity→sold_quantity, prints→impressions, item_id→mlb_id, etc) é a
 * MELHOR HIPÓTESE baseada em §2.3 do plano + MercadoLivreAdsService::DEFAULT_METRICS
 * + doc oficial Mercado Ads. Quando o smoke real rodar, validar shape contra
 * fixture em storage/app/sugadores/ml-smoke/{id}-{date}.json — ajustes pontuais
 * NÃO devem mudar a assinatura nem o contrato §2.3.
 *
 * Refatoração do SugadorAnalysisService para consumir este provider via factory
 * é entregue em Plan 39-04 — Phase 39 Plan 39-02 só entrega o provider + factory.
 */
class MercadoLivreSugadoresProvider implements SugadoresAdsProvider
{
    /**
     * Janela default usada por fetchCampaigns() (que não recebe período no contract).
     * 30 dias alinhado com a janela operacional do módulo Sugadores.
     */
    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(private MercadoLivreAdsService $ads) {}

    public function supports(Company $company): bool
    {
        // Espelha Company::is_ml_driven (linha 92) — token ML válido = provider suportado.
        return optional($company->mlToken)->status === 'active';
    }

    public function name(): string
    {
        return 'ml';
    }

    public function fetchCampaigns(Company $company): array
    {
        $advertiserId = $this->resolveAdvertiserId($company);
        if ($advertiserId === null) {
            return [];
        }

        // fetchCampaigns não recebe período no contract — usa janela default 30d.
        $to   = now()->toDateString();
        $from = now()->subDays(self::DEFAULT_RANGE_DAYS)->toDateString();

        $raw = $this->ads->listCampaigns($company, $advertiserId, $from, $to);

        $out = [];
        foreach (($raw['results'] ?? []) as $r) {
            // CANDIDATO — chave 'id' pode vir como 'campaign_id' no payload real;
            // ajustar pós-smoke Phase 38 Tarefa 3 se necessário.
            $id = (string) ($r['id'] ?? $r['campaign_id'] ?? '');
            if ($id === '') continue;

            $out[] = [
                'campaign_id'     => $id,
                'campaign_name'   => $r['name']   ?? null,
                'campaign_status' => $r['status'] ?? null,
                'raw'             => $r,
            ];
        }
        return $out;
    }

    public function fetchCampaignsMetrics(Company $company, Carbon $from, Carbon $to): array
    {
        $advertiserId = $this->resolveAdvertiserId($company);
        if ($advertiserId === null) {
            return [];
        }

        $raw = $this->ads->listCampaigns($company, $advertiserId, $from->toDateString(), $to->toDateString());

        $out = [];
        foreach (($raw['results'] ?? []) as $r) {
            // CANDIDATO — payload ML aninha métricas em $r['metrics']; ajustar
            // se o smoke revelar que vêm flat em $r diretamente.
            $metrics = $r['metrics'] ?? [];

            $cost          = isset($metrics['cost'])           ? (float) $metrics['cost']           : null;
            $totalAmount   = isset($metrics['total_amount'])   ? (float) $metrics['total_amount']   : null;
            $unitsQuantity = isset($metrics['units_quantity']) ? (int)   $metrics['units_quantity'] : null;
            $clicks        = isset($metrics['clicks'])         ? (int)   $metrics['clicks']         : null;
            $prints        = isset($metrics['prints'])         ? (int)   $metrics['prints']         : null;

            $cpc  = isset($metrics['cpc'])  ? (float) $metrics['cpc']  : self::safe_div($cost, $clicks);
            $acos = isset($metrics['acos']) ? (float) $metrics['acos'] : self::safe_div(($cost !== null ? $cost * 100 : null), $totalAmount);
            $roas = isset($metrics['roas']) ? (float) $metrics['roas'] : self::safe_div($totalAmount, $cost);

            $id = (string) ($r['id'] ?? $r['campaign_id'] ?? '');
            if ($id === '') continue;

            $out[] = [
                'campaign_id'     => $id,
                'campaign_name'   => $r['name']   ?? null,
                'campaign_status' => $r['status'] ?? null,
                'investment'      => $cost,
                'revenue'         => $totalAmount,
                'sold_quantity'   => $unitsQuantity,
                'clicks'          => $clicks,
                'impressions'     => $prints,
                'cpc'             => $cpc,
                'acos'            => $acos,
                'roas'            => $roas,
                'raw'             => $r,
            ];
        }
        return $out;
    }

    public function fetchAdgroupsMetrics(Company $company, Carbon $from, Carbon $to): array
    {
        $advertiserId = $this->resolveAdvertiserId($company);
        if ($advertiserId === null) {
            return [];
        }

        // Phase 42 Plan 42-03 (REQ-42-01 + REQ-42-05): resolvemos `campaign_name` +
        // `campaign_status` localmente via merge interno com listCampaigns ANTES
        // de iterar os adgroups. Motivo: o filtro de quarentena SGI do briefing
        // §12 (e do CONTEXT D-07) skipa campanhas pelo NOME ("SGI", "Sugador",
        // "Sugadores") — sem o nome disponivel no payload do adgroup, a
        // quarentena nunca dispararia pela origem ML. O contrato §3 do briefing
        // exige `campaign_name` e `campaign_status` na lista normalizada de
        // adgroups (mesmo que null). Cache de 10min em MercadoLivreAdsService
        // (Phase 41-02) evita 2 chamadas duplicadas quando
        // buildCampaignsInfoFromProvider() roda em seguida no service.
        // Fail-open: se listCampaigns quebra, adgroups ainda fluem com
        // campaign_name=null (T-42-03-02 — quarentena por nome NAO dispara, mas
        // a analise nao trava).
        $campaignNames = [];
        try {
            $rawCampaigns = $this->ads->listCampaigns($company, $advertiserId, $from->toDateString(), $to->toDateString());
            foreach (($rawCampaigns['results'] ?? []) as $c) {
                $cId = (string) ($c['id'] ?? $c['campaign_id'] ?? '');
                if ($cId === '') continue;
                $campaignNames[$cId] = [
                    'name'   => $c['name']   ?? null,
                    'status' => $c['status'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // So company_id no log — nunca payload, nunca token (T-39-02-01).
            Log::warning("[MercadoLivreSugadoresProvider] empresa {$company->id} falha em listCampaigns (merge campaign_name): " . $e->getMessage());
            // $campaignNames continua [] — fail-open documentado.
        }

        $wrap = $this->ads->tryFetchAdsMetrics($company, $advertiserId, $from->toDateString(), $to->toDateString());
        if (!($wrap['ok'] ?? false)) {
            // Só logamos o ID da empresa — nunca payload bruto, nunca token (T-39-02-01).
            $err = $wrap['error']['message'] ?? 'erro desconhecido';
            Log::warning("[MercadoLivreSugadoresProvider] empresa {$company->id} falha em tryFetchAdsMetrics: {$err}");
            // Phase 42 polish — surface failure pro operador NAO confundir "API
            // ML caiu" com "conta limpa sem ads". Lanca RuntimeException com a
            // causa raiz; o caller (analyzeCompany ou comando Artisan) loga +
            // alerta o operador via output do comando.
            throw new \RuntimeException(
                "[Sugadores ML] tryFetchAdsMetrics falhou para empresa {$company->id}: {$err}"
            );
        }

        $results = $wrap['data']['results'] ?? [];

        $out = [];
        foreach ($results as $r) {
            // CANDIDATO — chaves item_id/title/type/campaign_id podem variar; revalidar
            // pós-smoke Phase 38 Tarefa 3 contra fixture real em storage/app/sugadores/ml-smoke/.
            $metrics = $r['metrics'] ?? [];

            $cost          = isset($metrics['cost'])           ? (float) $metrics['cost']           : null;
            $totalAmount   = isset($metrics['total_amount'])   ? (float) $metrics['total_amount']   : null;
            $unitsQuantity = isset($metrics['units_quantity']) ? (int)   $metrics['units_quantity'] : null;
            $clicks        = isset($metrics['clicks'])         ? (int)   $metrics['clicks']         : null;
            $prints        = isset($metrics['prints'])         ? (int)   $metrics['prints']         : null;

            $cpc  = isset($metrics['cpc'])  ? (float) $metrics['cpc']  : self::safe_div($cost, $clicks);
            $ctr  = isset($metrics['ctr'])  ? (float) $metrics['ctr']  : self::safe_div($clicks, $prints);
            $acos = isset($metrics['acos']) ? (float) $metrics['acos'] : self::safe_div(($cost !== null ? $cost * 100 : null), $totalAmount);
            $roas = isset($metrics['roas']) ? (float) $metrics['roas'] : self::safe_div($totalAmount, $cost);

            $campaignIdAdg = (string) ($r['campaign_id'] ?? '');

            $out[] = [
                // quick 260626-qgf — payload real do /product_ads/items expoe a chave
                // 'ad_group_id' (snake_case com underscore). Smoke direto na empresa 298
                // (ByMobille - Teste, advertiser 620095) confirmou: nao existe 'id' no
                // nivel raiz do item; o que existe e ad_group_id, item_id, campaign_id.
                // Antes desta correcao, todos os sugadores tipo=adgroup eram persistidos
                // com adgroup_id='' (string vazia) e a tabela adman_adgroup_mlbs caia
                // no mesmo bucket vazio, inviabilizando o lookup do drilldown.
                'adgroup_id'      => (string) ($r['ad_group_id'] ?? ''),
                'adgroup_name'    => $r['title'] ?? null,
                'campaign_id'     => $campaignIdAdg,
                // CANDIDATO — revalidar pos-smoke Phase 38 Tarefa 3.
                // Phase 42 Plan 42-03 (REQ-42-01 + REQ-42-05): campaign_name/status
                // resolvidos via merge com listCampaigns acima. Null se nao bate.
                'campaign_name'   => $campaignNames[$campaignIdAdg]['name']   ?? null,
                'campaign_status' => $campaignNames[$campaignIdAdg]['status'] ?? null,
                'thumbnail'       => $r['thumbnail'] ?? null,
                'adgroup_type'    => $r['type'] ?? null,
                'catalog_listing' => (bool) ($r['catalog_listing'] ?? false),
                // No path ML, item_id do anúncio É o MLB do produto.
                'mlb_id'          => $r['item_id'] ?? null,
                'mlb_titulo'      => $r['title']   ?? null,
                'investment'      => $cost,
                'revenue'         => $totalAmount,
                'sold_quantity'   => $unitsQuantity,
                'clicks'          => $clicks,
                'impressions'     => $prints,
                'cpc'             => $cpc,
                'ctr'             => $ctr,
                'acos'            => $acos,
                'roas'            => $roas,
                // Mercado Ads não retorna receita orgânica nesse endpoint — deferred.
                // Caso o smoke revele esses campos, substituir por mapeamento direto.
                'organic_amount'  => null,
                'organic_units'   => null,
                'raw'             => $r,
            ];
        }
        return $out;
    }

    public function fetchAdgroupMlbs(Company $company, Carbon $from, Carbon $to): array
    {
        $advertiserId = $this->resolveAdvertiserId($company);
        if ($advertiserId === null) {
            return [];
        }

        $wrap = $this->ads->tryFetchAdsMetrics($company, $advertiserId, $from->toDateString(), $to->toDateString());
        if (!($wrap['ok'] ?? false)) {
            $err = $wrap['error']['message'] ?? 'erro desconhecido';
            Log::warning("[MercadoLivreSugadoresProvider] empresa {$company->id} falha em fetchAdgroupMlbs: {$err}");
            return [];
        }

        // quick 260626-qgf — payload real do /product_ads/items usa 'ad_group_id'
        // (snake_case), nao 'id'. Confirmado via direct call em prod (advertiser 620095).
        // Antes desta correcao, todos os pares (adgroup, MLB) caiam num bucket unico
        // de adgroup_id='' e o lookup getMlbsForAdgroup nunca encontrava match.
        $map = [];
        foreach (($wrap['data']['results'] ?? []) as $r) {
            $adgroupId = (string) ($r['ad_group_id'] ?? '');
            $mlbId     = $r['item_id'] ?? null;
            if ($adgroupId === '' || $mlbId === null) continue;

            $map[$adgroupId][] = (string) $mlbId;
        }

        // Preserva ordem de inserção (PHP arrays mantêm) e deduplica MLBs por adgroup.
        foreach ($map as $adgroupId => $mlbs) {
            $map[$adgroupId] = array_values(array_unique($mlbs));
        }

        return $map;
    }

    /**
     * Resolve advertiser_id Mercado Ads da empresa. Retorna null se a conta não
     * tem Mercado Ads ativo (estado válido — não exceção).
     */
    private function resolveAdvertiserId(Company $company): ?int
    {
        $info = $this->ads->discoverAdvertiser($company);
        return $info['advertiser_id'] ?? null;
    }

    /**
     * Divisão segura: retorna null se denominador for null/zero/negativo.
     * Mantida estática para reuso em testes e legibilidade. Espelha o helper
     * do AdmanSugadoresProvider — não extraído para trait nesta phase pra
     * manter o escopo reduzido (decisão consciente Plan 39-02).
     */
    private static function safe_div(float|int|null $num, float|int|null $den): ?float
    {
        if ($num === null || $den === null) return null;
        if ((float) $den <= 0)               return null;
        return (float) $num / (float) $den;
    }
}
