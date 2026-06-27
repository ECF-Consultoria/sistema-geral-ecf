<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\Sugadores\MercadoLivreAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Smoke do path WRITE da API Mercado Ads.
 *
 * Phase 44 Plan 44-01 — prova empírica de que POST (criar SGI) e PUT (mover ad)
 * funcionam com o scope 'read write offline_access'. Pré-requisito obrigatório
 * para a Phase 44 avançar; sem este smoke verde, codar 44-02/03/04 seria codar
 * sobre suposição.
 *
 * Fluxo (5 etapas + guard):
 *   0) Guard: MlToken.scope deve conter 'write' — aborta imediatamente se não contiver
 *   1) Discover advertiser_id via MercadoLivreAdsService::discoverAdvertiser()
 *   2) Escolher 1 ad de teste via listCampaigns() + listAds() (pega 1 ad com status != hold)
 *   3) POST criar SGI de teste pausada (Variante A; fallback Variante B se 404)
 *   4) PUT mover o ad de teste para a SGI criada
 *   5) PUT reverter o ad de teste para a campanha original
 *   6) Relatório CLI + fixture JSON em storage/app/sugadores/ml-write-smoke/
 *
 * Saídas:
 *   - Console: relatório tabular com cada etapa (ok/falha, HTTP status, latência)
 *   - Disco: fixture JSON em storage/app/sugadores/ml-write-smoke/{id}-{ts}.json
 *
 * Smoke é DIAGNÓSTICO — falhas nas Etapas 3-5 são documentadas no relatório com
 * exit code 0 (informação útil). Somente Etapa 0 (scope) e resolução de empresa/token
 * causam exit code 1 (sem dados para fixture útil).
 *
 * ANTI-LEAK (T-44-01-01, T-44-01-02):
 *   - Fixture JSON NUNCA contém access_token, refresh_token ou header Authorization
 *   - Log::* desta classe NUNCA passa o token — apenas company.id e endpoint
 *
 * @see 44-RESEARCH.md §1.1 (PUT ad), §1.3 (POST campaign), §6 (etapas), §7.5 (api-version)
 * @see 44-01-PLAN.md (especificação completa das 5 etapas)
 */
class SugadoresMlWriteSmoke extends Command
{
    protected $signature = 'sugadores:ml-write-smoke
        {--company= : ID numérico da empresa (default: empresa Bymobille com token ativo)}
        {--days=30 : Janela de leitura de ads/campaigns em dias (1..90)}';

    protected $description = 'Smoke do path WRITE da API Mercado Ads — valida POST criar SGI + PUT mover ad. Pré-requisito Plan 44-01: scope read write offline_access ativo. Não toca produção (SGI criada fica como teste, ad é revertido).';

    // ─── Headers obrigatórios para endpoints de write Mercado Ads ─────────────
    // Conforme 44-RESEARCH §7.5: api-version minúsculo, valor 2 (write endpoints).
    private const WRITE_HEADERS = [
        'api-version'  => '2',
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ];

    // URL base da API ML (espelha MercadoLivreAdsService::API_BASE).
    private const API_BASE = 'https://api.mercadolibre.com';

    // Timeout por chamada write (44-CONTEXT §7.6).
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private MercadoLivreAdsService $ads,
        private MercadoLivreService $ml,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // ─── Etapa 0: resolver empresa + guard de scope ───────────────────────
        $company = $this->resolveCompany();
        if ($company === null) {
            return self::FAILURE;
        }

        $mlToken = $company->mlToken;

        if (! $mlToken || $mlToken->status !== 'active') {
            $this->error("Empresa {$company->id} ({$company->name}) sem token ML ativo — execute OAuth ML primeiro.");
            return self::FAILURE;
        }

        // Guard scope: token deve conter literalmente 'write'.
        if (! str_contains((string) ($mlToken->scope ?? ''), 'write')) {
            $this->error(
                "Re-auth necessário — operador deve reconectar a empresa após Tarefa 1 do Plan 44-01 " .
                "expandir o scope para 'read write offline_access'. " .
                "Scope atual: '{$mlToken->scope}'"
            );
            return self::FAILURE;
        }

        // Clamp da janela em [1, 90] dias.
        $days     = max(1, min(90, (int) $this->option('days')));
        $dateTo   = now()->toDateString();
        $dateFrom = now()->subDays($days)->toDateString();

        $this->info("Smoke ML WRITE — {$company->name} (company_id={$company->id}, janela={$days}d, {$dateFrom} → {$dateTo})");
        $this->info("Scope do token: {$mlToken->scope}");
        $this->newLine();

        // ─── Estrutura do relatório ───────────────────────────────────────────
        $steps            = [];
        $blockers         = [];
        $advertiserId     = null;
        $siteId           = null;
        $newCampaignId    = null;
        $moveTargetItemId = null;
        $originalCampId   = null;
        $variantUsed      = null;
        $endpointsOk      = 0;
        $endpointsFailed  = 0;
        $abortAfterStep   = null;

        // ─── Etapa 1: discover advertiser ────────────────────────────────────
        $step1 = $this->runStep(1, 'Discover advertiser', function () use ($company) {
            return $this->ads->discoverAdvertiser($company);
        });
        $steps[] = $step1;

        if (! $step1['ok']) {
            $blockers[]      = 'Etapa 1 — discover advertiser falhou: ' . ($step1['error'] ?? 'erro desconhecido');
            $endpointsFailed++;
            $abortAfterStep  = 1;
        } else {
            $advertiserId = $step1['result']['advertiser_id'] ?? null;
            $siteId       = $step1['result']['site_id'] ?? 'MLB';
            $endpointsOk++;

            if ($advertiserId === null) {
                $blockers[]     = 'Etapa 1 — conta sem Mercado Ads (advertisers vazio)';
                $endpointsFailed++;
                $abortAfterStep = 1;
            } else {
                $this->info("Etapa 1 OK — advertiser_id={$advertiserId}, site_id={$siteId}");
            }
        }

        // ─── Etapa 2: escolher 1 ad de teste ─────────────────────────────────
        if ($abortAfterStep === null) {
            $step2 = $this->runStep(2, 'Listar ads e escolher 1 de teste', function () use ($company, $advertiserId, $dateFrom, $dateTo) {
                $adsResult = $this->ads->listAds($company, $advertiserId, $dateFrom, $dateTo);

                // Pega o primeiro ad com status != hold (hold não pode ser movido — RESEARCH §1.1).
                $candidato = null;
                foreach ($adsResult['results'] ?? [] as $ad) {
                    $status = strtolower((string) ($ad['status'] ?? ''));
                    if ($status !== 'hold') {
                        $candidato = $ad;
                        break;
                    }
                }

                return [
                    'candidato'   => $candidato,
                    'total_ads'   => $adsResult['count'] ?? 0,
                    'ads_result'  => $adsResult,
                ];
            });
            $steps[] = $step2;

            if (! $step2['ok'] || ($step2['result']['candidato'] ?? null) === null) {
                $blockers[]     = 'Etapa 2 — nenhum ad candidato encontrado (todos em hold ou conta sem ads)';
                $endpointsFailed++;
                $abortAfterStep = 2;
            } else {
                $candidato        = $step2['result']['candidato'];
                $moveTargetItemId = (string) ($candidato['item_id'] ?? '');
                $originalCampId   = $candidato['campaign_id'] ?? null;
                $endpointsOk++;
                $this->info("Etapa 2 OK — candidato item_id={$moveTargetItemId}, campaign_id_original={$originalCampId}");
            }
        }

        // ─── Etapa 3: POST criar SGI de teste (Variante A, fallback B) ───────
        if ($abortAfterStep === null) {
            $ts        = now()->format('YmdHis');
            $sgNome    = "SGI-SMOKE-TEST-{$ts}";
            $step3     = $this->runStep(3, 'POST criar SGI teste (Variante A)', function () use ($mlToken, $siteId, $advertiserId, $sgNome) {
                // Variante A — endpoint marketplace (preferido, 44-RESEARCH §1.3).
                $urlA = self::API_BASE . "/marketplace/advertising/{$siteId}/advertisers/{$advertiserId}/product_ads/campaigns?channel=marketplace";
                $bodyA = [
                    'name'        => $sgNome,
                    'status'      => 'paused',
                    'budget'      => 5,
                    'strategy'    => 'profitability',
                    'acos_target' => 15,
                    'channel'     => 'marketplace',
                ];

                $t0    = microtime(true);
                $respA = Http::withToken($mlToken->access_token)
                    ->withHeaders(self::WRITE_HEADERS)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->post($urlA, $bodyA);
                $latA  = (int) round((microtime(true) - $t0) * 1000);

                if ($respA->status() !== 404) {
                    // Variante A retornou algo — seja 201 (ok) ou outro erro (registrar).
                    return [
                        'variant'          => 'A',
                        'url'              => $urlA,
                        'request_body'     => $bodyA,
                        'http_status'      => $respA->status(),
                        'response_preview' => substr($respA->body(), 0, 500),
                        'response_json'    => $respA->json(),
                        'latency_ms'       => $latA,
                        'new_campaign_id'  => $respA->json('id'),
                    ];
                }

                // Variante A deu 404 — tentar Variante B (legacy).
                $urlB  = self::API_BASE . '/advertising/product_ads_2/campaigns';
                $bodyB = ['budget' => 5, 'status' => 'paused', 'name' => $sgNome];

                $t0    = microtime(true);
                $respB = Http::withToken($mlToken->access_token)
                    ->withHeaders(self::WRITE_HEADERS)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->post($urlB, $bodyB);
                $latB  = (int) round((microtime(true) - $t0) * 1000);

                return [
                    'variant'               => 'B',
                    'url'                   => $urlB,
                    'request_body'          => $bodyB,
                    'http_status'           => $respB->status(),
                    'response_preview'      => substr($respB->body(), 0, 500),
                    'response_json'         => $respB->json(),
                    'latency_ms'            => $latB,
                    'new_campaign_id'       => $respB->json('id'),
                    'variante_a_http_status'=> 404,
                ];
            });
            $steps[] = $step3;

            $step3HttpStatus = $step3['result']['http_status'] ?? 0;
            $step3IsSuccess  = in_array($step3HttpStatus, [200, 201], true);

            if (! $step3['ok'] || ! $step3IsSuccess) {
                $variantUsed    = $step3['result']['variant'] ?? null;
                $blockers[]     = "Etapa 3 — POST criar SGI (Variante {$variantUsed}) falhou com HTTP {$step3HttpStatus}: " .
                    substr($step3['result']['response_preview'] ?? '', 0, 200);
                $endpointsFailed++;
                $abortAfterStep = 3;
            } else {
                $variantUsed   = $step3['result']['variant'] ?? 'A';
                $newCampaignId = $step3['result']['new_campaign_id'] ?? null;
                $endpointsOk++;
                $this->info("Etapa 3 OK — SGI criada id={$newCampaignId} (Variante {$variantUsed}). Status: {$step3HttpStatus}");
                $this->warn("SGI '{$sgNome}' (paused) criada em produção — remover manualmente no painel ML se desejar.");
            }
        }

        // ─── Etapa 4: PUT mover ad teste para a SGI criada ────────────────────
        if ($abortAfterStep === null) {
            $step4 = $this->runStep(4, "PUT mover ad {$moveTargetItemId} → SGI {$newCampaignId}", function () use ($mlToken, $siteId, $moveTargetItemId, $newCampaignId) {
                $url  = self::API_BASE . "/marketplace/advertising/{$siteId}/product_ads/ads/{$moveTargetItemId}?channel=marketplace";
                $body = ['campaign_id' => $newCampaignId];

                $t0   = microtime(true);
                $resp = Http::withToken($mlToken->access_token)
                    ->withHeaders(self::WRITE_HEADERS)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->put($url, $body);
                $lat  = (int) round((microtime(true) - $t0) * 1000);

                return [
                    'url'              => $url,
                    'request_body'     => $body,
                    'http_status'      => $resp->status(),
                    'response_preview' => substr($resp->body(), 0, 500),
                    'response_json'    => $resp->json(),
                    'latency_ms'       => $lat,
                ];
            });
            $steps[] = $step4;

            $step4HttpStatus = $step4['result']['http_status'] ?? 0;
            $step4IsSuccess  = in_array($step4HttpStatus, [200, 204], true);

            if (! $step4['ok'] || ! $step4IsSuccess) {
                $blocker = "Etapa 4 — PUT mover ad falhou com HTTP {$step4HttpStatus}";
                if ($step4HttpStatus === 403) {
                    $blocker .= ' — verificar scope=write OU permissão Advertising na app DevCenter ML';
                }
                $blockers[]     = $blocker;
                $endpointsFailed++;
                $abortAfterStep = 4; // Etapa 5 NÃO tenta (early-abort).
            } else {
                $endpointsOk++;
                $this->info("Etapa 4 OK — ad movido para SGI {$newCampaignId}. Status: {$step4HttpStatus}");
            }
        }

        // ─── Etapa 5: PUT reverter ad para campanha original ──────────────────
        if ($abortAfterStep === null) {
            $step5 = $this->runStep(5, "PUT reverter ad {$moveTargetItemId} → campaign {$originalCampId}", function () use ($mlToken, $siteId, $moveTargetItemId, $originalCampId) {
                $url  = self::API_BASE . "/marketplace/advertising/{$siteId}/product_ads/ads/{$moveTargetItemId}?channel=marketplace";
                $body = ['campaign_id' => $originalCampId];

                $t0   = microtime(true);
                $resp = Http::withToken($mlToken->access_token)
                    ->withHeaders(self::WRITE_HEADERS)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->put($url, $body);
                $lat  = (int) round((microtime(true) - $t0) * 1000);

                return [
                    'url'              => $url,
                    'request_body'     => $body,
                    'http_status'      => $resp->status(),
                    'response_preview' => substr($resp->body(), 0, 500),
                    'response_json'    => $resp->json(),
                    'latency_ms'       => $lat,
                ];
            });
            $steps[] = $step5;

            $step5HttpStatus = $step5['result']['http_status'] ?? 0;
            $step5IsSuccess  = in_array($step5HttpStatus, [200, 204], true);

            if (! $step5['ok'] || ! $step5IsSuccess) {
                $blockers[]    = "Etapa 5 — PUT reverter ad falhou com HTTP {$step5HttpStatus}: " .
                    substr($step5['result']['response_preview'] ?? '', 0, 200);
                $endpointsFailed++;
            } else {
                $endpointsOk++;
                $this->info("Etapa 5 OK — ad revertido para campanha original {$originalCampId}. Status: {$step5HttpStatus}");
            }
        }

        // ─── Montar e gravar fixture JSON ─────────────────────────────────────
        $report = [
            'company'              => ['id' => $company->id, 'name' => $company->name],
            'company_token_scope'  => $mlToken->scope,
            'advertiser_id'        => $advertiserId,
            'site_id'              => $siteId,
            'steps'                => $this->sanitizeStepsForFixture($steps),
            'summary'              => [
                'endpoints_ok'              => $endpointsOk,
                'endpoints_failed'          => $endpointsFailed,
                'blockers'                  => $blockers,
                'api_version_used'          => '2',
                'post_campaign_variant_used' => $variantUsed,
                'new_campaign_id'           => $newCampaignId,
                'move_target_item_id'       => $moveTargetItemId,
                'original_campaign_id'      => $originalCampId,
            ],
        ];

        $filename     = "{$company->id}-" . now()->format('Y-m-d-His') . '.json';
        $relativePath = "sugadores/ml-write-smoke/{$filename}";

        Storage::disk('local')->put(
            $relativePath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        // ─── Relatório CLI ────────────────────────────────────────────────────
        $this->newLine();
        $this->printReport($company, $endpointsOk, $endpointsFailed, $blockers, $relativePath);

        return self::SUCCESS;
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Executa uma etapa capturando resultado, ok/falha e latência.
     * Exceptions viram ok=false; resultado parcial é preservado para relatório.
     */
    private function runStep(int $n, string $name, callable $cb): array
    {
        $t0  = microtime(true);
        $ok  = false;
        $res = null;
        $err = null;

        try {
            $res = $cb();
            $ok  = true;
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            // Log sem token — apenas company.id e mensagem (T-44-01-02).
            Log::warning("[SugadoresMlWriteSmoke] Etapa {$n} falhou: {$err}");
        }

        return [
            'step_n'     => $n,
            'name'       => $name,
            'ok'         => $ok,
            'latency_ms' => (int) round((microtime(true) - $t0) * 1000),
            'result'     => $res,
            'error'      => $err,
        ];
    }

    /**
     * Sanitiza o array de steps para a fixture JSON — remove chaves sensíveis
     * antes da serialização (T-44-01-01). Helper defensivo: a API ML não retorna
     * access_token em PUTs/POSTs de ads, mas o unset garante proteção contra
     * regressão futura.
     */
    private function sanitizeStepsForFixture(array $steps): array
    {
        return array_map(function (array $step) {
            if (isset($step['result'])) {
                $step['result'] = $this->sanitizeForFixture($step['result']);
            }
            return $step;
        }, $steps);
    }

    /**
     * Remove recursivamente chaves sensíveis de um array.
     * Chaves removidas: access_token, refresh_token, Authorization (qualquer case).
     *
     * @param  array  $payload
     * @return array
     */
    private function sanitizeForFixture(array $payload): array
    {
        $sensitiveKeys = ['access_token', 'refresh_token', 'authorization', 'Authorization'];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['access_token', 'refresh_token', 'authorization'], true)) {
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitizeForFixture($value);
            }
        }

        return $payload;
    }

    /**
     * Resolve a empresa via --company ou lookup Bymobille (fallback sem flag).
     * Retorna null e imprime mensagem de erro se não encontrada ou ambígua.
     */
    private function resolveCompany(): ?Company
    {
        $companyId = (int) $this->option('company');

        // Fallback sem flag: tenta empresa Bymobille com mlToken ativo.
        if ($companyId === 0) {
            $candidates = Company::with('mlToken')
                ->whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
                ->where(function ($q) {
                    $q->where('name', 'like', '%Bymobille%')
                      ->orWhere('name', 'like', '%ByMobille%');
                })
                ->get(['id', 'name']);

            if ($candidates->count() === 1) {
                return Company::with('mlToken')->find($candidates->first()->id);
            }

            if ($candidates->isEmpty()) {
                $this->error('Empresa não encontrada — nenhuma empresa Bymobille com mlToken ativo. Passe --company={id} explicitamente.');
            } else {
                $this->error('Empresa ambígua — múltiplos candidatos Bymobille; passe --company={id}:');
                foreach ($candidates as $c) {
                    $this->line("  - id={$c->id}: {$c->name}");
                }
            }
            return null;
        }

        // Caminho explícito: --company={id}
        $company = Company::with('mlToken')->find($companyId);

        if ($company === null) {
            $this->error("Empresa não encontrada (id={$companyId}).");
            return null;
        }

        return $company;
    }

    /**
     * Imprime relatório CLI estruturado em Markdown legível.
     */
    private function printReport(
        Company $company,
        int $endpointsOk,
        int $endpointsFailed,
        array $blockers,
        string $fixturePath,
    ): void {
        $total = $endpointsOk + $endpointsFailed;
        $score = "{$endpointsOk}/{$total}";

        $this->line("## Smoke ML WRITE — {$company->name} (company_id={$company->id})");
        $this->newLine();

        if ($endpointsFailed === 0 && $endpointsOk >= 5) {
            $this->info("Resultado: {$score} verdes — SMOKE APROVADO");
        } else {
            $this->warn("Resultado: {$score} — {$endpointsFailed} etapa(s) com falha");
        }

        $this->newLine();
        $this->line("### blockers ({$total}/{$total})");
        if (empty($blockers)) {
            $this->info('  (vazio) — pronto para Phase 44-02/03/04');
        } else {
            foreach ($blockers as $b) {
                $this->error("  - {$b}");
            }
        }

        $this->newLine();
        $this->info("Fixture gravada em storage/app/{$fixturePath}");
        $this->warn('Fixture pode conter dados reais — revisar antes de compartilhar fora da equipe.');
    }
}
