<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Matriz de diagnóstico READ-ONLY para Product Ads ML (2026-07-03).
 *
 * Conforme "estudo-permissao-escrita-product-ads-mercado-livre.md" §6 do operador:
 * antes de tentar escrita, provar separadamente identidade, advertiser, campanhas,
 * anúncios e ad_groups. Sem esse diagnóstico ficamos girando entre "faltou permissão"
 * e "endpoint errado".
 *
 * Endpoints testados (todos GET — read-only, zero risco):
 *   T1: /users/me                                                              (identidade)
 *   T2: /advertising/advertisers?product_id=PADS         Api-Version: 1        (advertiser PADS)
 *   T3: /advertising/{SITE}/advertisers/{ADV}/product_ads/campaigns/search  api-version:2
 *   T4: /advertising/{SITE}/advertisers/{ADV}/product_ads/ads/search        api-version:2
 *   T5: /advertising/{SITE}/advertisers/{ADV}/product_ads/ad_groups/search  api-version:2
 *
 * Coleta pra fixture:
 *   - HTTP status, x-request-id, latência, tamanho da resposta
 *   - No T4: sample de 3 ads com status (active/paused/idle/delegated/revoked)
 *     — delegated/revoked bloqueia escrita mesmo com OAuth OK (estudo §4.3)
 *   - Zero token/access_token/refresh_token na fixture (anti-leak)
 *
 * Saídas:
 *   - Console: tabela dos 5 testes (ok/fail + status HTTP + observações)
 *   - Disco: storage/app/sugadores/ml-read-diagnostic/{id}-{ts}.json
 */
class SugadoresMlReadDiagnostic extends Command
{
    protected $signature = 'sugadores:ml-read-diagnostic
        {--company= : ID numérico da empresa (default: primeira com ml_token ativo)}';

    protected $description = 'Matriz de 5 testes READ-ONLY em Product Ads ML — pré-condição para retomar Phase 44 (estudo permissao 2026-07-03).';

    private const API_BASE = 'https://api.mercadolibre.com';
    private const TIMEOUT_SECONDS = 30;

    public function __construct(private MercadoLivreService $ml) {
        parent::__construct();
    }

    public function handle(): int
    {
        $company = $this->resolveCompany();
        if ($company === null) {
            return self::FAILURE;
        }

        $token = $company->mlToken;
        if (!$token || $token->status !== 'active') {
            $this->error("Empresa {$company->id} ({$company->name}) sem MlToken ativo.");
            return self::FAILURE;
        }

        $this->info("Matriz diagnóstico READ-ONLY — {$company->name} (company_id={$company->id})");
        $this->info("Token connected_at={$token->connected_at}, last_refresh={$token->last_refreshed_at}");
        $this->info("Scope: " . substr((string) $token->scope, 0, 200) . '...');
        $this->newLine();

        $results = [];
        $advertiserId = null;
        $siteId = 'MLB';

        // T1: identidade
        $r = $this->doGet($token->access_token, '/users/me', [], []);
        $r['test'] = 'T1-users-me';
        $r['obs'] = $r['ok'] ? sprintf('user_id=%s nickname=%s', $r['body']['id'] ?? '?', $r['body']['nickname'] ?? '?') : $r['error'];
        $results[] = $r;

        // T2: descobrir advertiser PADS
        $r = $this->doGet($token->access_token, '/advertising/advertisers', ['product_id' => 'PADS'], ['Api-Version' => '1']);
        $r['test'] = 'T2-advertisers-PADS';
        if ($r['ok']) {
            $advertisers = $r['body']['advertisers'] ?? [];
            $advertiserId = $advertisers[0]['advertiser_id'] ?? null;
            $siteId = $advertisers[0]['site_id'] ?? 'MLB';
            $r['obs'] = sprintf('advertisers=%d advertiser_id=%s site=%s',
                count($advertisers),
                $advertiserId ?? 'null',
                $siteId);
        } else {
            $r['obs'] = $r['error'];
        }
        $results[] = $r;

        if (!$advertiserId) {
            $this->reportAndSave($company, $results, "advertiser_id não resolvido — abortando testes 3-5.");
            return self::SUCCESS;
        }

        // T3: campanhas
        $r = $this->doGet($token->access_token,
            "/advertising/{$siteId}/advertisers/{$advertiserId}/product_ads/campaigns/search",
            ['limit' => 5],
            ['api-version' => '2']);
        $r['test'] = 'T3-campaigns-search';
        if ($r['ok']) {
            $results3 = $r['body']['results'] ?? $r['body']['campaigns'] ?? [];
            $sample = array_map(fn($c) => [
                'id' => $c['id'] ?? null,
                'name' => $c['name'] ?? null,
                'status' => $c['status'] ?? null,
            ], array_slice($results3, 0, 3));
            $r['sample'] = $sample;
            $r['obs'] = sprintf('campanhas=%d amostra_status=%s', count($results3),
                implode(',', array_column($sample, 'status')));
        } else {
            $r['obs'] = $r['error'];
        }
        $results[] = $r;

        // T4: anúncios (com status por item — delegated/revoked bloqueia escrita)
        $r = $this->doGet($token->access_token,
            "/advertising/{$siteId}/advertisers/{$advertiserId}/product_ads/ads/search",
            ['limit' => 10],
            ['api-version' => '2']);
        $r['test'] = 'T4-ads-search';
        if ($r['ok']) {
            $results4 = $r['body']['results'] ?? $r['body']['ads'] ?? [];
            $statusHistogram = [];
            $sample = [];
            foreach ($results4 as $ad) {
                $st = $ad['status'] ?? 'unknown';
                $statusHistogram[$st] = ($statusHistogram[$st] ?? 0) + 1;
                if (count($sample) < 3) {
                    $sample[] = [
                        'item_id' => $ad['item_id'] ?? $ad['id'] ?? null,
                        'status' => $st,
                        'campaign_id' => $ad['campaign_id'] ?? null,
                        'ad_group_id' => $ad['ad_group_id'] ?? null,
                    ];
                }
            }
            $r['sample'] = $sample;
            $r['status_histogram'] = $statusHistogram;
            $delegated = $statusHistogram['delegated'] ?? 0;
            $revoked = $statusHistogram['revoked'] ?? 0;
            $r['obs'] = sprintf('anuncios=%d histograma=%s delegated+revoked=%d',
                count($results4),
                json_encode($statusHistogram),
                $delegated + $revoked);
        } else {
            $r['obs'] = $r['error'];
        }
        $results[] = $r;

        // T5: ad_groups (Catalog/User Products)
        $r = $this->doGet($token->access_token,
            "/advertising/{$siteId}/advertisers/{$advertiserId}/product_ads/ad_groups/search",
            ['limit' => 5],
            ['api-version' => '2']);
        $r['test'] = 'T5-ad-groups-search';
        if ($r['ok']) {
            $results5 = $r['body']['results'] ?? $r['body']['ad_groups'] ?? [];
            $r['obs'] = sprintf('ad_groups=%d', count($results5));
        } else {
            $r['obs'] = $r['error'];
        }
        $results[] = $r;

        $this->reportAndSave($company, $results, null);

        return self::SUCCESS;
    }

    private function doGet(string $token, string $path, array $query = [], array $headers = []): array
    {
        $url = self::API_BASE . $path;
        $t0 = microtime(true);
        try {
            $resp = Http::withToken($token)
                ->withHeaders(array_merge(['Accept' => 'application/json'], $headers))
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);
            $elapsedMs = (int) ((microtime(true) - $t0) * 1000);
            $ok = $resp->successful();
            $body = null;
            try { $body = $resp->json(); } catch (\Throwable) { $body = null; }
            return [
                'ok'         => $ok,
                'status'     => $resp->status(),
                'url'        => $url,
                'query'      => $query,
                'headers'    => $headers,
                'elapsed_ms' => $elapsedMs,
                'body'       => $body,
                'x_request_id' => $resp->header('x-request-id'),
                'error'      => $ok ? null : ($body['message'] ?? $body['error'] ?? $resp->body()),
            ];
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((microtime(true) - $t0) * 1000);
            // Anti-leak: nunca logar token no exception message
            Log::warning("[MlDiagnostic] {$path} falhou: " . $e->getMessage());
            return [
                'ok' => false, 'status' => null, 'url' => $url, 'query' => $query,
                'headers' => $headers, 'elapsed_ms' => $elapsedMs, 'body' => null,
                'x_request_id' => null, 'error' => $e->getMessage(),
            ];
        }
    }

    private function reportAndSave(Company $company, array $results, ?string $extraNote): void
    {
        // Tabela CLI
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                $r['test'],
                $r['ok'] ? '✓ OK' : '✗ FAIL',
                (string) ($r['status'] ?? '-'),
                $r['elapsed_ms'] . 'ms',
                mb_strimwidth((string) $r['obs'], 0, 80, '…'),
            ];
        }
        $this->table(['Teste', 'Resultado', 'HTTP', 'Latência', 'Observação'], $rows);

        if ($extraNote) {
            $this->warn($extraNote);
        }

        // Fixture — NÃO gravar access_token/refresh_token/authorization em canto nenhum.
        $ts = now()->format('Y-m-d-His');
        $path = "sugadores/ml-read-diagnostic/{$company->id}-{$ts}.json";
        Storage::disk('local')->put($path, json_encode([
            'company'    => ['id' => $company->id, 'name' => $company->name],
            'ran_at'     => now()->toIso8601String(),
            'results'    => $results,
            'note'       => $extraNote,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Fixture: storage/app/{$path}");
    }

    private function resolveCompany(): ?Company
    {
        $id = $this->option('company');
        if ($id) {
            $c = Company::find((int) $id);
            if (!$c) {
                $this->error("Empresa {$id} não encontrada.");
                return null;
            }
            return $c;
        }
        $c = Company::whereHas('mlToken', fn($q) => $q->where('status', 'active'))->first();
        if (!$c) {
            $this->error('Nenhuma empresa com MlToken ativo encontrada.');
            return null;
        }
        $this->info("Sem --company; usando {$c->name} (id={$c->id}).");
        return $c;
    }
}
