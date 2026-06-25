<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Sugadores\MercadoLivreAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Comando smoke da API oficial Mercado Livre Mercado Ads (Product Ads).
 *
 * Phase 38 Plan 38-02 — Fase 0 da Milestone v11.0 (migração Sugadores
 * Adman → ML). Roda manualmente no XAMPP de dev contra o token real da
 * empresa Bymobille para confirmar shape do payload ANTES da Phase 39
 * implementar o provider pattern.
 *
 * Fluxo (4 etapas):
 *   1) Descobre advertiser_id Mercado Ads
 *   2) Lista campanhas no período
 *   3) Lista anúncios/items (endpoint CANDIDATO — pode falhar)
 *   4) Tenta extrair métricas dos items
 *
 * Saídas:
 *   - Console: relatório CLI estruturado (endpoints_ok, endpoints_failed,
 *     contract_fields_present, contract_fields_missing, blockers)
 *   - Disco: fixture JSON em storage/app/sugadores/ml-smoke/{id}-{YYYY-MM-DD}.json
 *
 * Smoke é DIAGNÓSTICO — etapas individuais falhadas viram blockers no
 * relatório, NÃO exit code != 0. Exit code só falha em problemas de
 * resolução de empresa/token (early failure antes de chamar a API).
 *
 * NÃO toca: tabelas de produção do Sugadores, routes/console.php (manual),
 * AdmanService, SugadorAnalysisService, jobs do módulo.
 *
 * @see plano-migracao-sugadores-ml-direto.md §9 (prompt operacional original)
 * @see .planning/phases/38-smoke-ml-piloto-bymobile/38-CONTEXT.md (decisões)
 */
class SugadoresMlSmoke extends Command
{
    protected $signature = 'sugadores:ml-smoke
        {--company= : ID numerico da empresa (default: Bymobille - Teste)}
        {--days=30 : Janela em dias para metricas (clamp 1-90)}';

    protected $description = 'Smoke test da API oficial ML Mercado Ads para validar shape de payload antes da migracao Sugadores Adman->ML (Fase 0 da Milestone v11.0)';

    /**
     * Mapeamento contrato §2.3 do plano → nomes esperados no payload ML.
     * Usado para gerar contract_fields_present / contract_fields_missing.
     */
    private const CONTRACT_FIELDS = [
        // chave_no_contrato => nome_no_ml
        'investment'      => 'cost',
        'revenue'         => 'total_amount',
        'sold_quantity'   => 'units_quantity',
        'clicks'          => 'clicks',
        'impressions'     => 'prints',
        'cpc'             => 'cpc',
        'ctr'             => 'ctr',
        'acos'            => 'acos',
        'roas'            => 'roas',
        'mlb_id'          => 'item_id',
        'mlb_titulo'      => 'title',
        'thumbnail'       => 'thumbnail',
        'adgroup_type'    => 'type',
        'catalog_listing' => 'catalog_listing',
        'organic_amount'  => 'organic_amount',
        'organic_units'   => 'organic_units',
    ];

    public function handle(MercadoLivreAdsService $ads): int
    {
        // ─── Etapa 0: resolver empresa + token ───────────────────────────────
        $company = $this->resolveCompany();
        if ($company === null) {
            return self::FAILURE; // mensagem já impressa em resolveCompany()
        }

        if (! $company->mlToken || $company->mlToken->status !== 'active') {
            $this->error("Empresa {$company->id} ({$company->name}) sem token ML ativo — execute OAuth ML primeiro.");
            return self::FAILURE;
        }

        // Clamp da janela em [1, 90] dias (T-38-08 — DoS por paginação infinita).
        $days     = max(1, min(90, (int) $this->option('days')));
        $dateTo   = now()->toDateString();
        $dateFrom = now()->subDays($days)->toDateString();

        $this->info("Smoke ML — {$company->name} (company_id={$company->id}, janela={$days}d, {$dateFrom} → {$dateTo})");
        $this->newLine();

        // ─── Inicialização de estruturas do relatório ─────────────────────────
        $endpointsOk     = [];
        $endpointsFailed = [];
        $blockers        = [];
        $advertiser      = null;
        $campaignsResult = null;
        $adsResult       = null;
        $metricsResult   = null;

        // ─── Etapa 1: descobrir advertiser_id Mercado Ads ────────────────────
        try {
            $advertiser = $ads->discoverAdvertiser($company);

            if ($advertiser['advertiser_id'] === null) {
                $blockers[] = 'Conta sem Mercado Ads (advertisers vazio) — Phase 39 nao pode prosseguir';
                $this->warn('Advertiser vazio — gravando fixture parcial e abortando smoke.');
            } else {
                $endpointsOk[] = $advertiser['url'];
                $this->info("OK Advertiser: id={$advertiser['advertiser_id']}, site={$advertiser['site_id']}, seller={$advertiser['seller_id']}");
            }
        } catch (\RuntimeException $e) {
            $endpointsFailed[] = [
                'endpoint' => '/advertising/advertisers',
                'error'    => $e->getMessage(),
            ];
            $blockers[] = 'Discovery falhou: ' . $e->getMessage();
            $this->error('Discovery do advertiser falhou: ' . $e->getMessage());
        }

        // ─── Etapa 2: listar campanhas (só se discovery deu advertiser_id) ────
        if (is_array($advertiser) && $advertiser['advertiser_id'] !== null) {
            try {
                $campaignsResult = $ads->listCampaigns(
                    $company,
                    $advertiser['advertiser_id'],
                    $dateFrom,
                    $dateTo,
                );

                foreach ($campaignsResult['endpoints_tried'] as $url) {
                    $endpointsOk[] = $url;
                }
                $this->info("OK Campanhas: {$campaignsResult['count']} listadas em " . count($campaignsResult['endpoints_tried']) . ' pagina(s)');
            } catch (\RuntimeException $e) {
                $endpointsFailed[] = [
                    'endpoint' => '/product_ads/campaigns/search',
                    'error'    => $e->getMessage(),
                ];
                $blockers[] = 'Listagem de campanhas falhou: ' . $e->getMessage();
                $this->error('Listagem de campanhas falhou: ' . $e->getMessage());
            }
        }

        // ─── Etapa 3+4: ads/items + métricas (wrapper try/catch do service) ──
        if (is_array($advertiser) && $advertiser['advertiser_id'] !== null) {
            $metricsResult = $ads->tryFetchAdsMetrics(
                $company,
                $advertiser['advertiser_id'],
                $dateFrom,
                $dateTo,
            );

            if ($metricsResult['ok'] === true) {
                $adsResult = $metricsResult['data'];
                foreach ($adsResult['endpoints_tried'] as $url) {
                    $endpointsOk[] = $url;
                }
                $this->info("OK Ads/items: {$adsResult['count']} listados em " . count($adsResult['endpoints_tried']) . ' pagina(s)');
            } else {
                $endpointsFailed[] = [
                    'endpoint' => '/product_ads/items',
                    'error'    => $metricsResult['error']['message'],
                ];
                $blockers[] = 'Endpoint ads/items falhou — confirmar nome correto via doc oficial: ' . $metricsResult['error']['message'];
                $this->error('Endpoint ads/items falhou: ' . $metricsResult['error']['message']);
            }
        }

        // ─── Etapa 5: comparar campos do contrato §2.3 vs payload real ───────
        [$present, $missing] = $this->inspectContractFields($adsResult, $campaignsResult);

        // ─── Montar fixture JSON ──────────────────────────────────────────────
        $fixture = [
            'company_id'   => $company->id,
            'company_name' => $company->name,
            'run_at'       => now()->toIso8601String(),
            'days_window'  => $days,
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'advertiser'   => $advertiser,
            'campaigns'    => $campaignsResult,
            'ads'          => $adsResult,
            'metrics'      => $metricsResult,
            'report'       => [
                'endpoints_ok'            => $endpointsOk,
                'endpoints_failed'        => $endpointsFailed,
                'contract_fields_present' => $present,
                'contract_fields_missing' => $missing,
                'blockers'                => $blockers,
            ],
        ];

        // ─── Gravar fixture no disco ──────────────────────────────────────────
        $filename     = "{$company->id}-" . now()->toDateString() . '.json';
        $relativePath = 'sugadores/ml-smoke/' . $filename;

        Storage::disk('local')->put(
            $relativePath,
            json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->newLine();
        $this->info("Fixture gravada em storage/app/{$relativePath}");

        // ─── Imprimir relatório CLI legível ───────────────────────────────────
        $this->printReport($company, $days, $endpointsOk, $endpointsFailed, $present, $missing, $blockers);

        // Smoke nunca falha exit code != 0 a partir daqui — etapas falhadas
        // já viraram blockers no relatório acima.
        return self::SUCCESS;
    }

    /**
     * Resolve a empresa via --company (explícito) ou lookup Bymobille (fallback).
     * Retorna null e imprime mensagem de erro se ambígua/ausente.
     */
    private function resolveCompany(): ?Company
    {
        $companyId = (int) $this->option('company');

        // Fallback: tentar Bymobille via lookup quando --company não foi passado.
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
                $this->error('Empresa não encontrada — multiplos candidatos Bymobille; passe --company={id} explicitamente:');
                foreach ($candidates as $c) {
                    $this->line("  - id={$c->id}: {$c->name}");
                }
            }
            return null;
        }

        // Caminho explícito: --company={id} foi passado.
        // Cast (int) acima já mitiga T-38-07 (SQL injection via input).
        $company = Company::with('mlToken')->find($companyId);

        if ($company === null) {
            $this->error("Empresa não encontrada (id={$companyId}).");
            return null;
        }

        return $company;
    }

    /**
     * Inspeciona shape do payload (ads primeiro, fallback campanhas) e separa
     * campos do contrato §2.3 em presentes/ausentes.
     *
     * @return array{0: string[], 1: string[]} [presentes, ausentes]
     */
    private function inspectContractFields(?array $adsResult, ?array $campaignsResult): array
    {
        // Preferir ads (item-level tem mais campos do contrato), fallback campanhas.
        $sampleItem = $adsResult['raw_first_page']['results'][0]
            ?? $campaignsResult['raw_first_page']['results'][0]
            ?? null;

        if ($sampleItem === null) {
            // Sem nenhum sample disponível — tudo ausente (não-determinístico:
            // pode ser endpoint que falhou ou conta sem dados).
            return [[], array_keys(self::CONTRACT_FIELDS)];
        }

        $sampleMetrics  = $sampleItem['metrics'] ?? [];
        $sampleTopLevel = $sampleItem; // catálogo/título/thumb vivem fora de metrics

        $present = [];
        $missing = [];

        foreach (self::CONTRACT_FIELDS as $contractKey => $mlKey) {
            $existsInMetrics  = array_key_exists($mlKey, $sampleMetrics);
            $existsInTopLevel = array_key_exists($mlKey, $sampleTopLevel);

            if ($existsInMetrics || $existsInTopLevel) {
                $present[] = $contractKey;
            } else {
                $missing[] = $contractKey;
            }
        }

        return [$present, $missing];
    }

    /**
     * Imprime relatório CLI estruturado em Markdown legível.
     * Formato alinhado com CONTEXT.md §Relatório CLI.
     */
    private function printReport(
        Company $company,
        int $days,
        array $endpointsOk,
        array $endpointsFailed,
        array $present,
        array $missing,
        array $blockers,
    ): void {
        $this->newLine();
        $this->line('## Smoke ML — ' . $company->name . ' (company_id=' . $company->id . ', ' . $days . 'd)');
        $this->newLine();

        $this->line('### endpoints_ok (' . count($endpointsOk) . ')');
        foreach ($endpointsOk as $url) {
            $this->line('  - ' . $url);
        }
        $this->newLine();

        $this->line('### endpoints_failed (' . count($endpointsFailed) . ')');
        foreach ($endpointsFailed as $f) {
            $this->line('  - ' . $f['endpoint'] . ': ' . $f['error']);
        }
        $this->newLine();

        $this->line('### contract_fields_present (' . count($present) . ')');
        $this->line('  ' . implode(', ', $present));
        $this->newLine();

        $this->line('### contract_fields_missing (' . count($missing) . ')');
        $this->line('  ' . implode(', ', $missing));
        $this->newLine();

        $this->line('### blockers (' . count($blockers) . ')');
        if (empty($blockers)) {
            $this->info('  (vazio) — pronto para Phase 39');
        } else {
            foreach ($blockers as $b) {
                $this->error('  - ' . $b);
            }
        }
        $this->newLine();

        // Aviso T-38-05: fixture pode conter PII real do cliente final.
        $this->warn('Fixture pode conter dados reais de cliente — revisar antes de compartilhar fora da equipe.');
    }
}
