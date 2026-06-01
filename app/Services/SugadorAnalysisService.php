<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Models\SugadorConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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
     * Campanhas de "quarentena" — onde analistas movem adgroups já tratados.
     * Match por word boundary: pega "SGI", "Sugador", "Sugadores" no nome.
     */
    private const QUARANTINE_NAME_REGEX = '/\b(sgi|sugadores?)\b/iu';

    /** Status da campanha que indicam que o time já encerrou aquela frente. */
    private const QUARANTINE_STATUSES = ['paused', 'closed', 'ended'];

    /**
     * Analisa todas as empresas ativas com config ativa.
     * Retorna estatísticas globais.
     */
    public function analyzeAll(?Carbon $referenceDate = null, bool $dryRun = false): array
    {
        $companies = Company::with('sugadorConfig')
            ->where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
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
            'adgroups_flagados'  => 0,
        ];

        foreach ($companies as $company) {
            try {
                $result = $this->analyzeCompany($company, $referenceDate, $dryRun);
                if ($result['skipped']) {
                    $totals['companies_skipped']++;
                } else {
                    $totals['companies_analyzed']++;
                    $totals['campanhas_flagadas'] += $result['campanhas'];
                    $totals['adgroups_flagados']  += $result['adgroups'];
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
     *   'adgroups'   => int,
     *   'detalhes'   => array,          // lista pra UI: [['tipo', 'id', 'motivos', 'novo' => bool], ...]
     * ]
     */
    public function analyzeCompany(Company $company, ?Carbon $referenceDate = null, bool $dryRun = false): array
    {
        $referenceDate = $referenceDate ?? now()->startOfDay();
        // A detecção de sugadores consulta a API Adman ao vivo — preferir o ID
        // Adman (adman_account_id). Antes usava ml_store_id ?: adman_account_id,
        // o que, em empresa com vínculo ML, passava o Seller ID do ML para a
        // Adman (que não o reconhece) e quebrava a análise. Detecção via ML é
        // follow-up separado.
        $custId        = $company->adman_account_id ?: $company->ml_store_id;

        $skip = function (string $reason) {
            return ['skipped' => true, 'reason' => $reason, 'campanhas' => 0, 'adgroups' => 0, 'detalhes' => []];
        };

        if (!$custId) return $skip('sem adman_account_id');

        $config = SugadorConfig::forCompany($company);
        if (!$config->ativo) return $skip('config inativa');

        $periodoFim    = $referenceDate->copy()->subDay();
        $periodoInicio = $periodoFim->copy()->subDays($config->dias_analise - 1);
        $dateFrom      = $periodoInicio->toDateString();
        $dateTo        = $periodoFim->toDateString();
        $refDateStr    = $referenceDate->toDateString();

        // Lookup campaignId → {name, status} pra descartar adgroups que o analista
        // já moveu pra campanha de quarentena (SGI/Sugador/Sugadores) ou cuja
        // campanha está pausada/encerrada — esses já foram tratados.
        $campaignsInfo = $this->loadCampaignsInfo($custId);

        // Pré-fetch dos sugadores já existentes para esta empresa+data → evita SELECT por item
        $existingMap = $dryRun ? collect() : Sugador::where('company_id', $company->id)
            ->where('reference_date', $refDateStr)
            ->get()
            ->keyBy(fn($s) => "{$s->tipo}|{$s->campaign_id}|{$s->adgroup_id}");

        $toUpsert      = [];
        $detalhes      = [];
        $campanhasCount = 0;
        $adgroupsCount  = 0;

        // Mapeia campaign_id → [total_anuncios, sugadores] para a regra de %
        $campanhaStats = [];

        // ─── Análise de anúncios (adgroup-level) ────────────────────────────
        if ($config->incluir_anuncios) {
            try {
                $ads = $this->adman->fetchAdsMetrics($custId, $dateFrom, $dateTo);

                foreach ($ads as $ad) {
                    $cId = $ad['campaign_id'];
                    if ($cId === '') continue;

                    // Skipa adgroups em campanha de quarentena ou pausada/encerrada
                    if ($this->shouldSkipCampaign($campaignsInfo[$cId] ?? null)) continue;

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
                        'tipo'                 => Sugador::TIPO_ADGROUP,
                        'campaign_id'          => $cId,
                        'campaign_name'        => null,
                        'campaign_status'      => null,
                        'adgroup_id'           => $ad['adgroup_id'],
                        'adgroup_name'         => $ad['adgroup_name'],
                        'thumbnail'            => $ad['thumbnail']       ?? null,
                        'adgroup_type'         => $ad['adgroup_type']    ?? null,
                        'catalog_listing'      => (bool) ($ad['catalog_listing'] ?? false),
                        'mlb_id'               => null,
                        'mlb_titulo'           => null,
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
                        'organic_amount'       => $ad['organic_amount'] ?? null,
                        'organic_units'        => $ad['organic_units']  ?? null,
                        'motivos'              => $motivos,
                        'raw_data'             => $ad['raw'] ?? null,
                    ]);

                    $adgroupsCount++;
                    $detalhes[] = [
                        'tipo'         => 'adgroup',
                        'campaign_id'  => $cId,
                        'adgroup_id'   => $ad['adgroup_id'],
                        'nome'         => $ad['adgroup_name'],
                        'investimento' => $ad['investment'] ?? 0,
                        'vendas'       => $ad['sold_quantity'] ?? 0,
                        'motivos'      => $motivos,
                    ];
                }
            } catch (\Throwable $e) {
                // Falha aqui é crítica: adgroups são a granularidade principal do módulo.
                // Loga como error (não warning) e re-propaga para o Command/Controller exibir.
                Log::error("[Sugadores] Erro ao buscar adgroups da empresa {$company->id} ({$company->name}): " . $e->getMessage());
                throw $e;
            }
        }

        // ─── Análise de campanhas ────────────────────────────────────────────
        if ($config->incluir_campanhas) {
            try {
                $campaigns = $this->adman->fetchCampaignsRange($custId, $dateFrom, $dateTo);

                foreach ($campaigns as $camp) {
                    $cId    = $camp['campaign_id'];

                    // Mesma regra aplicada aos adgroups: campanhas SGI/Sugadores ou
                    // já pausadas/encerradas representam estado tratado pelo analista
                    if ($this->shouldSkipCampaign([
                        'name'   => $camp['campaign_name']   ?? null,
                        'status' => $camp['campaign_status'] ?? null,
                    ])) continue;

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
                        'adgroup_name'         => null,
                        'thumbnail'            => null,
                        'adgroup_type'         => null,
                        'catalog_listing'      => false,
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
                        'organic_amount'       => null,
                        'organic_units'        => null,
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
                'campaign_name', 'campaign_status', 'adgroup_name',
                'thumbnail', 'adgroup_type', 'catalog_listing',
                'mlb_titulo',
                'periodo_inicio', 'periodo_fim',
                'investimento_periodo', 'faturamento_periodo', 'vendas_periodo',
                'cliques', 'impressoes', 'cpc_medio', 'ctr', 'acos', 'roas',
                'organic_amount', 'organic_units',
                'motivos', 'raw_data', 'status', 'updated_at',
            ]);
        }

        // ─── Auto-resolução (Phase 15) ──────────────────────────────────────
        // Pendentes históricos cuja chave (tipo|campaign_id|adgroup_id) não foi
        // re-detectada hoje deixaram de bater os critérios — não acumular fila.
        // Usa reference_date < hoje (estritamente menor) para proteger contra
        // rerun manual no mesmo dia. STATUS_TRAVADOS NÃO são tocados (filtro
        // pelo status='pendente' no where já garante isso).
        $autoResolvidosCount = 0;
        if (!$dryRun) {
            // Set de chaves detectadas hoje a partir do upsert
            $chavesHoje = [];
            foreach ($toUpsert as $row) {
                $chavesHoje["{$row['tipo']}|{$row['campaign_id']}|{$row['adgroup_id']}"] = true;
            }

            $pendentesAntigos = Sugador::where('company_id', $company->id)
                ->where('status', Sugador::STATUS_PENDENTE)
                ->where('reference_date', '<', $refDateStr)
                ->get(['id', 'tipo', 'campaign_id', 'adgroup_id']);

            $idsAutoResolvidos = [];
            foreach ($pendentesAntigos as $s) {
                $chave = "{$s->tipo}|{$s->campaign_id}|{$s->adgroup_id}";
                if (!isset($chavesHoje[$chave])) {
                    $idsAutoResolvidos[] = $s->id;
                }
            }

            if (!empty($idsAutoResolvidos)) {
                $nowDt = now();
                DB::transaction(function () use ($idsAutoResolvidos, $nowDt) {
                    // Atualiza status em massa
                    Sugador::whereIn('id', $idsAutoResolvidos)->update([
                        'status'        => Sugador::STATUS_AUTO_RESOLVIDO,
                        'resolvido_em'  => $nowDt,
                        'resolvido_por' => null,
                        'updated_at'    => $nowDt,
                    ]);

                    // Audit log em massa. SugadorAcao::$timestamps = false, então
                    // created_at precisa ser preenchido manualmente em cada row.
                    $rows = [];
                    foreach ($idsAutoResolvidos as $sid) {
                        $rows[] = [
                            'sugador_id'      => $sid,
                            'user_id'         => null,
                            'acao'            => SugadorAcao::ACAO_AUTO_RESOLVIDO,
                            'status_anterior' => Sugador::STATUS_PENDENTE,
                            'status_novo'     => Sugador::STATUS_AUTO_RESOLVIDO,
                            'observacao'      => 'Resolvido automaticamente pelo sistema — não re-detectado em análise diária.',
                            'created_at'      => $nowDt,
                        ];
                    }
                    SugadorAcao::insert($rows);
                });

                $autoResolvidosCount = count($idsAutoResolvidos);
                Log::info("[Sugadores] Auto-resolveu {$autoResolvidosCount} sugador(es) antigo(s) da empresa {$company->id} ({$company->name})");
            }
        }

        return [
            'skipped'         => false,
            'reason'          => null,
            'campanhas'       => $campanhasCount,
            'adgroups'        => $adgroupsCount,
            'auto_resolvidos' => $autoResolvidosCount,
            'detalhes'        => $detalhes,
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
            'adgroup_name'         => $data['adgroup_name']    ?? null,
            'thumbnail'            => $data['thumbnail']       ?? null,
            'adgroup_type'         => $data['adgroup_type']    ?? null,
            'catalog_listing'      => (bool) ($data['catalog_listing'] ?? false),
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
            'organic_amount'       => $data['organic_amount'] ?? null,
            'organic_units'        => $data['organic_units']  ?? null,
            'motivos'              => json_encode($data['motivos']),
            'raw_data'             => isset($data['raw_data']) ? json_encode($data['raw_data']) : null,
            'status'               => $status,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];
    }

    /**
     * Lógica pura de avaliação. Recebe métricas e config, retorna lista de motivos.
     *
     * Cada critério pode ser 'required' (AND) ou 'optional' (OR). Regra final:
     *   item é sugador  ⇔  TODOS os 'required' passam  E
     *                       (não há 'optional' OU pelo menos 1 'optional' passa)
     *
     * Casos:
     *   - Todos optional + 1+ passa  → flag (= comportamento histórico OR)
     *   - Todos required + todos passam → flag
     *   - Misto: required define o "piso obrigatório", optional define o "qualifier"
     *   - Critério com threshold null (desligado) é ignorado totalmente
     *
     * Quando flag, retorna os motivos de TODOS os critérios que passaram
     * (required + optional), pra UI mostrar contexto completo.
     *
     * @param  array  $metrics  Espera-se chaves: investment, sold_quantity, cpc, clicks, acos
     * @return array<string>
     */
    public function evaluateMetrics(array $metrics, SugadorConfig $config): array
    {
        $investment  = (float) ($metrics['investment']    ?? 0);
        $vendas      = (int)   ($metrics['sold_quantity'] ?? 0);
        $cpc         = $metrics['cpc']  ?? null;
        $clicks      = (int)   ($metrics['clicks']        ?? 0);
        $acos        = $metrics['acos'] ?? null;

        // Cada item: ['key' => motivo, 'hit' => bool, 'logic' => required|optional]
        $criteria = [];

        // Critério 1: gastou X sem vender nada
        if ($config->gasto_minimo_sem_venda !== null) {
            $threshold = (float) $config->gasto_minimo_sem_venda;
            $criteria[] = [
                'key'   => 'gasto_sem_venda',
                'hit'   => $vendas === 0 && $investment >= $threshold,
                'logic' => $config->gasto_minimo_logic ?? SugadorConfig::LOGIC_OPTIONAL,
            ];
        }

        // Critério 2: CPC alto sem vender
        if ($config->cpc_maximo !== null && $cpc !== null) {
            $threshold = (float) $config->cpc_maximo;
            $criteria[] = [
                'key'   => 'cpc_alto',
                'hit'   => $vendas === 0 && (float) $cpc > $threshold,
                'logic' => $config->cpc_maximo_logic ?? SugadorConfig::LOGIC_OPTIONAL,
            ];
        }

        // Critério 3: ACOS alto (com pelo menos 1 venda — ACOS sem venda é infinito/null)
        if ($config->acos_maximo_pct !== null && $acos !== null) {
            $threshold = (float) $config->acos_maximo_pct;
            $criteria[] = [
                'key'   => 'acos_alto',
                'hit'   => $vendas > 0 && (float) $acos > $threshold,
                'logic' => $config->acos_maximo_logic ?? SugadorConfig::LOGIC_OPTIONAL,
            ];
        }

        // Critério 4: muitos cliques sem conversão
        if ($config->cliques_minimos_sem_venda !== null) {
            $threshold = (int) $config->cliques_minimos_sem_venda;
            $criteria[] = [
                'key'   => 'cliques_sem_conversao',
                'hit'   => $vendas === 0 && $clicks >= $threshold,
                'logic' => $config->cliques_minimos_logic ?? SugadorConfig::LOGIC_OPTIONAL,
            ];
        }

        if (empty($criteria)) return [];

        $required = array_filter($criteria, fn($c) => $c['logic'] === SugadorConfig::LOGIC_REQUIRED);
        $optional = array_filter($criteria, fn($c) => $c['logic'] === SugadorConfig::LOGIC_OPTIONAL);

        // Todos required precisam passar — se algum falha, item não é sugador
        foreach ($required as $c) {
            if (!$c['hit']) return [];
        }

        // Se há optional, pelo menos um precisa passar. Se não há optional,
        // só os required já bastam (caso AND puro).
        if (!empty($optional)) {
            $anyOptionalHit = false;
            foreach ($optional as $c) {
                if ($c['hit']) { $anyOptionalHit = true; break; }
            }
            if (!$anyOptionalHit) return [];
        } elseif (empty($required)) {
            // Defensivo: critérios todos preenchidos mas com logic inválido — não flag.
            return [];
        }

        // Item é sugador → retorna chaves de todos critérios que passaram
        // (required + optional). UI mostra os motivos.
        $motivos = [];
        foreach ($criteria as $c) {
            if ($c['hit']) $motivos[] = $c['key'];
        }
        return $motivos;
    }

    /**
     * Lookup `campaignId => ['name', 'status']` da Adman. Usado pra filtrar
     * campanhas de quarentena (SGI/Sugadores) e pausadas/encerradas. Fail-open:
     * se a chamada falha, retorna array vazio e a análise segue sem o filtro.
     *
     * Público pra reuso pelo CleanupSugadoresQuarentena.
     */
    public function loadCampaignsInfo(string $custId): array
    {
        try {
            $campaigns = $this->adman->fetchAllCampaigns($custId);
        } catch (\Throwable $e) {
            Log::warning("[Sugadores] Não conseguiu listar campanhas {$custId} pro filtro de quarentena: " . $e->getMessage());
            return [];
        }

        $lookup = [];
        foreach ($campaigns as $c) {
            $id = (string) ($c['campaignId'] ?? $c['id'] ?? '');
            if ($id === '') continue;
            $lookup[$id] = [
                'name'   => $c['name']   ?? null,
                'status' => $c['status'] ?? null,
            ];
        }
        return $lookup;
    }

    /**
     * True se a campanha está em quarentena (nome SGI/Sugador/Sugadores) ou
     * pausada/encerrada — o analista já lidou com ela.
     *
     * Público pra reuso pelo CleanupSugadoresQuarentena.
     *
     * @param  array{name: ?string, status: ?string}|null  $info
     */
    public function shouldSkipCampaign(?array $info): bool
    {
        if (!$info) return false; // fail-open: sem info, deixa entrar

        $status = $info['status'] ?? null;
        if ($status && \in_array(strtolower((string) $status), self::QUARANTINE_STATUSES, true)) {
            return true;
        }

        $name = $info['name'] ?? null;
        if ($name && preg_match(self::QUARANTINE_NAME_REGEX, $name)) {
            return true;
        }

        return false;
    }

}
