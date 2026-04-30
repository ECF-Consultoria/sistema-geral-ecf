<?php

namespace App\Services;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdmanService
{
    private string $baseUrl;
    private string $apiKey;
    private string $marketplace;

    public function __construct()
    {
        $this->baseUrl     = rtrim(config('services.adman.base_url', 'https://api.ad-man.io/v1'), '/');
        $this->apiKey      = config('services.adman.api_key', '');
        $this->marketplace = 'meli';
    }

    private function headers(): array
    {
        return [
            'integrator-api-key' => $this->apiKey,
            'Accept'             => 'application/json',
        ];
    }

    public function syncAll(): array
    {
        $companies = Company::where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->get();

        $results = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($companies as $company) {
            try {
                $this->syncCompany($company);
                $results['success']++;
                usleep(700_000);
            } catch (\Throwable $e) {
                Log::error("[Adman] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
                $results['failed']++;
            }
        }

        return $results;
    }

    public function syncCompany(Company $company, ?string $date = null): AdmanMetric
    {
        // Padrão: ontem — dados do dia corrente ficam incompletos até o processamento noturno da Adman
        $date   = $date ?? now()->subDay()->toDateString();
        $custId = $company->adman_account_id;

        $performance = $this->fetchPerformance($custId, $date, $date);
        $summarized  = $performance['summarizedData'] ?? [];
        $items       = $performance['items'] ?? [];

        $grossBilling = $summarized['grossBilling']['value']  ?? null;
        $netBilling   = $summarized['netBilling']['value']    ?? null;
        $salesFee     = $summarized['salesFee']['value']      ?? null;
        $taxes        = $summarized['taxes']['value']         ?? null;
        $shippingCost = $summarized['shippingCost']['value']  ?? null;
        $productCost  = $summarized['productCost']['value']   ?? null;
        $returnCost   = $summarized['returnCost']['value']    ?? null;
        $investment   = $summarized['investment']['value']    ?? null;
        $profitMargin = $summarized['profitMargin']['value']  ?? null;
        $profitShare  = $summarized['profitShare']['value']   ?? null;
        $soldQty      = $summarized['soldQuantity']['value']  ?? null;
        $prevBilling  = $summarized['grossBilling']['prev']   ?? null;

        $tacos     = ($grossBilling > 0 && $investment !== null)
            ? round(($investment / $grossBilling) * 100, 4) : null;
        $marginPct = ($grossBilling > 0 && $profitMargin !== null)
            ? round(($profitMargin / $grossBilling) * 100, 4) : null;

        $productsTotal       = count($items);
        $productsWithoutCost = collect($items)->filter(fn($i) => ($i['cost']['value'] ?? 0) == 0)->count();

        $metric = AdmanMetric::updateOrCreate(
            ['company_id' => $company->id, 'reference_date' => $date],
            [
                'tacos'                   => $tacos,
                'revenue'                 => $grossBilling,
                'net_billing'             => $netBilling,
                'sales_fee'               => $salesFee,
                'taxes'                   => $taxes,
                'shipping_cost'           => $shippingCost,
                'product_cost'            => $productCost,
                'return_cost'             => $returnCost,
                'profit_share'            => $profitShare,
                'sold_quantity'           => $soldQty,
                'ad_spend'                => $investment,
                'contribution_margin'     => $profitMargin,
                'contribution_margin_pct' => $marginPct,
                'products_total'          => $productsTotal > 0 ? $productsTotal : null,
                'products_without_cost'   => $productsTotal > 0 ? $productsWithoutCost : null,
                'revenue_prev_period'     => $prevBilling,
                'raw_data'                => $summarized,
                'synced_at'               => now(),
            ]
        );

        try {
            $this->syncCampaigns($company, $custId, $date);
        } catch (\Throwable $e) {
            Log::warning("[Adman] Campanhas empresa {$company->id}: " . $e->getMessage());
        }

        return $metric;
    }

    public function syncCampaigns(Company $company, string $custId, string $date): void
    {
        $campaigns = $this->fetchCampaigns($custId);

        foreach ($campaigns as $campaign) {
            $campaignId = (string) ($campaign['campaignId'] ?? $campaign['id'] ?? null);
            if (!$campaignId) continue;

            try {
                $cm = $this->fetchCampaignMetrics($custId, $campaignId, $date, $date);

                // A API pode retornar valores diretos ou dentro de um sub-objeto {value: X}
                $val = fn($key) => is_array($cm[$key] ?? null)
                    ? ($cm[$key]['value'] ?? null)
                    : ($cm[$key] ?? null);

                AdmanCampaignMetric::updateOrCreate(
                    ['company_id' => $company->id, 'reference_date' => $date, 'campaign_id' => $campaignId],
                    [
                        'campaign_name'   => $campaign['name'] ?? null,
                        'campaign_status' => $campaign['status'] ?? null,
                        'investment'      => $val('investment'),
                        'revenue'         => $val('revenue'),
                        'acos'            => $val('acos'),
                        'tacos'           => $val('tacos'),
                        'roas'            => $val('roas'),
                        'cpc'             => $val('cpc'),
                        'clicks'          => $val('clicks'),
                        'impressions'     => $val('impressions'),
                        'sold_quantity'   => $val('soldQuantity'),
                        'synced_at'       => now(),
                    ]
                );

                usleep(400_000);
            } catch (\Throwable $e) {
                Log::warning("[Adman] Campanha {$campaignId}: " . $e->getMessage());
            }
        }
    }

    public function fetchPerformance(string $custId, string $dateFrom, string $dateTo): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$this->marketplace}/performance/{$custId}", [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

        if ($response->status() === 401) throw new \RuntimeException('Adman API: chave inválida (401).');
        if ($response->status() === 429) throw new \RuntimeException('Adman API: rate limit (429).');
        if ($response->failed()) throw new \RuntimeException("Adman API erro {$response->status()} custId={$custId}");

        return $response->json() ?? [];
    }

    public function fetchCampaigns(string $custId): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$this->marketplace}/ads/{$custId}/campaigns");

        if ($response->failed()) {
            throw new \RuntimeException("Adman campanhas erro {$response->status()} custId={$custId}");
        }

        $data = $response->json() ?? [];
        return is_array($data) && isset($data[0]) ? $data : ($data['data'] ?? $data['campaigns'] ?? []);
    }

    public function fetchCampaignMetrics(string $custId, string $campaignId, string $dateFrom, string $dateTo): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$this->marketplace}/ads/{$custId}/{$campaignId}/metrics", [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Adman métricas campanha {$campaignId} erro {$response->status()}");
        }

        return $response->json() ?? [];
    }

    public function syncHistorical(Company $company, string $dateFrom, string $dateTo): array
    {
        $current = new \DateTime($dateFrom);
        $end     = new \DateTime($dateTo);
        $results = ['success' => 0, 'failed' => 0];

        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            try {
                $this->syncCompany($company, $date);
                $results['success']++;
                usleep(700_000);
            } catch (\Throwable $e) {
                Log::warning("[Adman] Histórico {$date} empresa {$company->id}: " . $e->getMessage());
                $results['failed']++;
            }
            $current->modify('+1 day');
        }

        return $results;
    }

    public function fetchAccountMetrics(string $custId, string $dateFrom, string $dateTo): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$this->marketplace}/accounts/{$custId}/metrics", [
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ]);

        if ($response->failed()) throw new \RuntimeException("Adman account metrics erro {$response->status()}");
        return $response->json() ?? [];
    }

    public function listAccounts(?string $filter = null, int $page = 1): array
    {
        $params = ['page' => $page];
        if ($filter) $params['filter'] = $filter;

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get("{$this->baseUrl}/{$this->marketplace}/accounts", $params);

        if ($response->failed()) throw new \RuntimeException("Adman accounts erro {$response->status()}");
        return $response->json() ?? [];
    }
}
