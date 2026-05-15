<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Núcleo do módulo Sugadores. Responsável por:
 * - Carregar config por empresa (ou criar default)
 * - Buscar dados da Adman no range
 * - Avaliar critérios de detecção
 * - Persistir/atualizar `sugadores` respeitando idempotência (STATUS_TRAVADOS)
 * - Aplicar regra "% anúncios sugadores → flag campanha"
 *
 * Detalhes da feature: MODULO_SUGADORES.md.
 */
class SugadorAnalysisService
{
    public function __construct(private AdmanService $adman) {}

    /**
     * Analisa todas as empresas ativas com config ativa.
     * Retorna estatísticas globais.
     */
    public function analyzeAll(?Carbon $referenceDate = null, bool $dryRun = false): array
    {
        $companies = Company::with('sugadorConfig')
            ->where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->where(function ($q) {
                // Empresas com config ativa OU sem config (default é ativo=true via forCompany)
                $q->whereHas('sugadorConfig', fn($q) => $q->where('ativo', true))
                  ->orWhereDoesntHave('sugadorConfig');
            })
            ->get();

        $totals = [
            'companies_analyzed' => 0,
            'companies_skipped'  => 0,
            'companies_failed'   => 0,
            'campanhas_flagadas' => 0,
            'anuncios_flagados'  => 0,
        ];

        foreach ($companies as $company) {
            try {
                $result = $this->analyzeCompany($company, $referenceDate, $dryRun);
                if ($result['skipped']) {
                    $totals['companies_skipped']++;
                } else {
                    $totals['companies_analyzed']++;
                    $totals['campanhas_flagadas'] += $result['campanhas'];
                    $totals['anuncios_flagados']  += $result['anuncios'];
                }
            } catch (\Throwable $e) {
                Log::error("[Sugadores] Empresa {$company->id} ({$company->name}): " . $e->getMessage());
                $totals['companies_failed']++;
            }
        }

        return $totals;
    }

    /**
     * Analisa uma empresa específica.
     *
     * Retorno: [
     *   'skipped'    => bool,
     *   'reason'     => string|null,    // se skipped
     *   'campanhas'  => int,            // sugadores tipo=campanha criados/atualizados
     *   'anuncios'   => int,
     *   'detalhes'   => array,          // lista pra UI: [['tipo', 'id', 'motivos', 'novo' => bool], ...]
     * ]
     */
    public function analyzeCompany(Company $company, ?Carbon $referenceDate = null, bool $dryRun = false): array
    {
        $referenceDate = $referenceDate ?? now()->startOfDay();
        $custId        = $company->adman_account_id;

        $skip = function (string $reason) {
            return ['skipped' => true, 'reason' => $reason, 'campanhas' => 0, 'anuncios' => 0, 'detalhes' => []];
        };

        if (!$custId) return $skip('sem adman_account_id');

        $config = SugadorConfig::forCompany($company);
        if (!$config->ativo) return $skip('config inativa');

        $periodoFim    = $referenceDate->copy()->subDay();
        $periodoInicio = $periodoFim->copy()->subDays($config->dias_analise - 1);
        $dateFrom      = $periodoInicio->toDateString();
        $dateTo        = $periodoFim->toDateString();
        $refDateStr    = $referenceDate->toDateString();

        // Pré-fetch dos sugadores já existentes para esta empresa+data → evita SELECT por item
        $existingMap = $dryRun ? collect() : Sugador::where('company_id', $company->id)
            ->where('reference_date', $refDateStr)
            ->get()
            ->keyBy(fn($s) => "{$s->tipo}|{$s->campaign_id}|{$s->adgroup_id}");

        $toUpsert      = [];
        $detalhes      = [];
        $campanhasCount = 0;
        $anunciosCount  = 0;

        // Mapeia campaign_id → [total_anuncios, sugadores] para a regra de %
        $campanhaStats = [];

        // ─── Análise de anúncios (adgroup-level) ────────────────────────────
        if ($config->incluir_anuncios) {
            try {
                $ads = $this->adman->fetchAdsMetrics($custId, $dateFrom, $dateTo);

                foreach ($ads as $ad) {
                    $cId = $ad['campaign_id'];
                    if ($cId === '') continue;

                    // Conta total na campanha (somente itens com algum investimento)
                    if (($ad['investment'] ?? 0) > 0) {
                        $campanhaStats[$cId] ??= ['total' => 0, 'sugadores' => 0];
                        $campanhaStats[$cId]['total']++;
                    }

                    $motivos = $this->evaluateMetrics($ad, $config);
                    if (empty($motivos)) continue;

                    if (isset($campanhaStats[$cId])) {
                        $campanhaStats[$cId]['sugadores']++;
                    }

                    $toUpsert[] = $this->buildRow($company->id, $refDateStr, $existingMap, [
                        'tipo'                 => Sugador::TIPO_ANUNCIO,
                        'campaign_id'          => $cId,
                        'campaign_name'        => null,
                        'campaign_status'      => null,
                        'adgroup_id'           => $ad['adgroup_id'],
                        'mlb_id'               => null,
                        'mlb_titulo'           => $ad['adgroup_name'],
                        'periodo_inicio'       => $dateFrom,
                        'periodo_fim'          => $dateTo,
                        'investimento_periodo' => $ad['investment']    ?? 0,
                        'faturamento_periodo'  => $ad['revenue']       ?? 0,
                        'vendas_periodo'       => $ad['sold_quantity'] ?? 0,
                        'cliques'              => $ad['clicks']        ?? 0,
                        'impressoes'           => $ad['impressions']   ?? 0,
                        'cpc_medio'            => $ad['cpc'],
                        'ctr'                  => $ad['ctr'],
                        'acos'                 => $ad['acos'],
                        'roas'                 => $ad['roas'],
                        'motivos'              => $motivos,
                        'raw_data'             => $ad['raw'] ?? null,
                    ]);

                    $anunciosCount++;
                    $detalhes[] = [
                        'tipo'         => 'anuncio',
                        'campaign_id'  => $cId,
                        'adgroup_id'   => $ad['adgroup_id'],
                        'nome'         => $ad['adgroup_name'],
                        'investimento' => $ad['investment'] ?? 0,
                        'vendas'       => $ad['sold_quantity'] ?? 0,
                        'motivos'      => $motivos,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Sugadores] Erro ao buscar adgroups da empresa {$company->id}: " . $e->getMessage());
            }
        }

        // ─── Análise de campanhas ────────────────────────────────────────────
        if ($config->incluir_campanhas) {
            try {
                $campaigns = $this->adman->fetchCampaignsRange($custId, $dateFrom, $dateTo);

                foreach ($campaigns as $camp) {
                    $cId    = $camp['campaign_id'];
                    $motivos = $this->evaluateMetrics($camp, $config);

                    // Aplica regra do § 2.2: se ≥ X% dos anúncios da campanha são sugadores,
                    // flag a campanha (mesmo que ela não bata os outros critérios).
                    $stats = $campanhaStats[$cId] ?? null;
                    if ($stats && $stats['total'] > 0 && empty($motivos)) {
                        $pct = ($stats['sugadores'] / $stats['total']) * 100;
                        if ($pct >= $config->pct_anuncios_para_flag_campanha) {
                            $motivos = ['pct_anuncios_sugadores'];
                        }
                    }

                    if (empty($motivos)) continue;

                    $toUpsert[] = $this->buildRow($company->id, $refDateStr, $existingMap, [
                        'tipo'                 => Sugador::TIPO_CAMPANHA,
                        'campaign_id'          => $cId,
                        'campaign_name'        => $camp['campaign_name'],
                        'campaign_status'      => $camp['campaign_status'],
                        'adgroup_id'           => '',
                        'mlb_id'               => null,
                        'mlb_titulo'           => null,
                        'periodo_inicio'       => $dateFrom,
                        'periodo_fim'          => $dateTo,
                        'investimento_periodo' => $camp['investment']    ?? 0,
                        'faturamento_periodo'  => $camp['revenue']       ?? 0,
                        'vendas_periodo'       => $camp['sold_quantity'] ?? 0,
                        'cliques'              => $camp['clicks']        ?? 0,
                        'impressoes'           => $camp['impressions']   ?? 0,
                        'cpc_medio'            => $camp['cpc'],
                        'ctr'                  => null,
                        'acos'                 => $camp['acos'],
                        'roas'                 => $camp['roas'],
                        'motivos'              => $motivos,
                        'raw_data'             => $camp['raw'] ?? null,
                    ]);

                    $campanhasCount++;
                    $detalhes[] = [
                        'tipo'         => 'campanha',
                        'campaign_id'  => $cId,
                        'nome'         => $camp['campaign_name'],
                        'investimento' => $camp['investment'] ?? 0,
                        'vendas'       => $camp['sold_quantity'] ?? 0,
                        'motivos'      => $motivos,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("[Sugadores] Erro ao buscar campanhas da empresa {$company->id}: " . $e->getMessage());
            }
        }

        // Bulk upsert: 1 query para todos os sugadores desta empresa (em vez de 2N)
        if (!$dryRun && !empty($toUpsert)) {
            Sugador::upsert($toUpsert, ['company_id', 'reference_date', 'tipo', 'campaign_id', 'adgroup_id'], [
                'campaign_name', 'campaign_status', 'mlb_titulo',
                'periodo_inicio', 'periodo_fim',
                'investimento_periodo', 'faturamento_periodo', 'vendas_periodo',
                'cliques', 'impressoes', 'cpc_medio', 'ctr', 'acos', 'roas',
                'motivos', 'raw_data', 'status', 'updated_at',
            ]);
        }

        return [
            'skipped'   => false,
            'reason'    => null,
            'campanhas' => $campanhasCount,
            'anuncios'  => $anunciosCount,
            'detalhes'  => $detalhes,
        ];
    }

    /**
     * Monta o array de uma linha para bulk upsert, resolvendo o status via mapa em memória.
     * motivos e raw_data são json_encode'd pois upsert() bypassa os casts do Eloquent.
     */
    private function buildRow(int $companyId, string $refDateStr, $existingMap, array $data): array
    {
        $mapKey   = "{$data['tipo']}|{$data['campaign_id']}|{$data['adgroup_id']}";
        $existing = $existingMap[$mapKey] ?? null;
        $status   = ($existing && in_array($existing->status, Sugador::STATUS_TRAVADOS, true))
            ? $existing->status
            : Sugador::STATUS_PENDENTE;

        $now = now()->toDateTimeString();

        return [
            'company_id'           => $companyId,
            'reference_date'       => $refDateStr,
            'tipo'                 => $data['tipo'],
            'campaign_id'          => $data['campaign_id'],
            'campaign_name'        => $data['campaign_name'],
            'campaign_status'      => $data['campaign_status'],
            'adgroup_id'           => $data['adgroup_id'],
            'mlb_id'               => $data['mlb_id'],
            'mlb_titulo'           => $data['mlb_titulo'],
            'periodo_inicio'       => $data['periodo_inicio'],
            'periodo_fim'          => $data['periodo_fim'],
            'investimento_periodo' => $data['investimento_periodo'],
            'faturamento_periodo'  => $data['faturamento_periodo'],
            'vendas_periodo'       => $data['vendas_periodo'],
            'cliques'              => $data['cliques'],
            'impressoes'           => $data['impressoes'],
            'cpc_medio'            => $data['cpc_medio'],
            'ctr'                  => $data['ctr'],
            'acos'                 => $data['acos'],
            'roas'                 => $data['roas'],
            'motivos'              => json_encode($data['motivos']),
            'raw_data'             => isset($data['raw_data']) ? json_encode($data['raw_data']) : null,
            'status'               => $status,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    /**
     * Lógica pura de avaliação. Recebe métricas e config, retorna lista de motivos.
     * Critérios são OR — qualquer um basta para flagar.
     *
     * @param  array  $metrics  Espera-se chaves: investment, sold_quantity, cpc, clicks, acos
     * @return array<string>
     */
    public function evaluateMetrics(array $metrics, SugadorConfig $config): array
    {
        $motivos     = [];
        $investment  = (float) ($metrics['investment']    ?? 0);
        $vendas      = (int)   ($metrics['sold_quantity'] ?? 0);
        $cpc         = $metrics['cpc']  ?? null;
        $clicks      = (int)   ($metrics['clicks']        ?? 0);
        $acos        = $metrics['acos'] ?? null;

        // Critério 1: gastou X sem vender nada
        if ($config->gasto_minimo_sem_venda !== null) {
            $threshold = (float) $config->gasto_minimo_sem_venda;
            if ($vendas === 0 && $investment >= $threshold) {
                $motivos[] = 'gasto_sem_venda';
            }
        }

        // Critério 2: CPC alto sem vender
        if ($config->cpc_maximo !== null && $cpc !== null) {
            $threshold = (float) $config->cpc_maximo;
            if ($vendas === 0 && (float) $cpc > $threshold) {
                $motivos[] = 'cpc_alto';
            }
        }

        // Critério 3: ACOS alto (com pelo menos 1 venda — ACOS sem venda é infinito/null)
        if ($config->acos_maximo_pct !== null && $acos !== null) {
            $threshold = (float) $config->acos_maximo_pct;
            if ($vendas > 0 && (float) $acos > $threshold) {
                $motivos[] = 'acos_alto';
            }
        }

        // Critério 4: muitos cliques sem conversão
        if ($config->cliques_minimos_sem_venda !== null) {
            $threshold = (int) $config->cliques_minimos_sem_venda;
            if ($vendas === 0 && $clicks >= $threshold) {
                $motivos[] = 'cliques_sem_conversao';
            }
        }

        return $motivos;
    }

}
