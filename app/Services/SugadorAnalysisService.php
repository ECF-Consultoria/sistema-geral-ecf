<?php

namespace App\Services;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Models\SugadorConfig;
use App\Repositories\AdgroupMlbMapRepository;
use App\Services\Sugadores\SugadoresAdsProviderFactory;
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
    /**
     * Phase 39 Plan 39-04: constructor passa a receber o factory de providers.
     * Quick task 260626-qgf: adiciona AdgroupMlbMapRepository para persistir o
     * mapeamento adgroup->[MLB IDs] durante a analise ML.
     *
     * - $providers: resolve qual provider (Adman ou Mercado Livre) atende cada empresa.
     *   Substitui as chamadas diretas a $this->adman dentro de analyzeCompany.
     * - $adman: PRESERVADO para uso interno de loadCampaignsInfo legacy (consumida
     *   por CleanupSugadoresQuarentena, que NAO faz parte do refactor Phase 39).
     *   Sera removido em Phase 42+ quando todos os call-sites migrarem para o
     *   AdgroupMlbMapRepository / provider ML.
     * - $adgroupMlbMap: usado APENAS no path ML para persistir adgroup->[MLB IDs]
     *   no cache `adman_adgroup_mlbs`, alimentando o drilldown do Show.jsx.
     *   Provider Adman tem Job dedicado (SyncCompanyAdgroupMlbsJob — Phase 30);
     *   duplicar aqui criaria double-write desnecessario.
     */
    public function __construct(
        private SugadoresAdsProviderFactory $providers,
        private AdmanService $adman,
        private AdgroupMlbMapRepository $adgroupMlbMap,
    ) {}

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
     *
     * Phase 39 Plan 39-04: aceita $forceProvider opcional que é propagado a
     * analyzeCompany → factory.for(). Comando sugadores:analyze (Plan 39-05)
     * usa esse caminho para forçar --provider=adman|ml.
     */
    public function analyzeAll(?Carbon $referenceDate = null, bool $dryRun = false, ?string $forceProvider = null): array
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
                $result = $this->analyzeCompany($company, $referenceDate, $dryRun, $forceProvider);
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
    public function analyzeCompany(Company $company, ?Carbon $referenceDate = null, bool $dryRun = false, ?string $forceProvider = null): array
    {
        $referenceDate = $referenceDate ?? now()->startOfDay();

        $skip = function (string $reason) {
            return ['skipped' => true, 'reason' => $reason, 'campanhas' => 0, 'adgroups' => 0, 'detalhes' => []];
        };

        // Phase 39 Plan 39-04: resolução de provider via factory.
        // - factory.for() escolhe AdmanSugadoresProvider (default até Phase 42) ou
        //   MercadoLivreSugadoresProvider, baseado em capability detection ou em
        //   $forceProvider ('adman'|'ml') passado pelo command (Plan 39-05).
        // - Se nenhum provider suporta a empresa, factory lança RuntimeException —
        //   convertemos em skip estruturado para preservar o retorno semântico
        //   antigo (analyzeAll trata skip como companies_skipped, não failed).
        try {
            $provider = $this->providers->for($company, $forceProvider);
        } catch (\RuntimeException $e) {
            return $skip('sem provider compatível: ' . $e->getMessage());
        }

        $config = SugadorConfig::forCompany($company);
        if (!$config->ativo) return $skip('config inativa');

        // Phase 42 D-03 (briefing §4): janela de 30 dias FECHADOS por padrao.
        // periodoFim = ontem (referenceDate - 1 dia); periodoInicio = ontem - 29 dias.
        // Total: 30 dias, exclui o dia em curso. Comportamento ja vigente via
        // dias_analise=30 (DEFAULT); este comentario apenas explicita a regra
        // pra rastreabilidade. Override `$config->dias_analise != 30` muda apenas
        // o tamanho da janela — `periodoFim = referenceDate - 1 dia` permanece fixo.
        $periodoFim    = $referenceDate->copy()->subDay();
        $periodoInicio = $periodoFim->copy()->subDays($config->dias_analise - 1);
        $dateFrom      = $periodoInicio->toDateString();
        $dateTo        = $periodoFim->toDateString();
        $refDateStr    = $referenceDate->toDateString();

        // Lookup campaignId → {name, status} pra descartar adgroups que o analista
        // já moveu pra campanha de quarentena (SGI/Sugador/Sugadores) ou cuja
        // campanha está pausada/encerrada — esses já foram tratados.
        // Phase 39 Plan 39-04: lookup vem do provider (era $this->adman direto).
        $campaignsInfo = $this->buildCampaignsInfoFromProvider($provider, $company);

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
                // Phase 39 Plan 39-04: substitui $this->adman->fetchAdsMetrics
                // por $provider->fetchAdgroupsMetrics — provider sabe extrair
                // adman_account_id (Adman) ou advertiser_id ML internamente.
                $ads = $provider->fetchAdgroupsMetrics($company, $periodoInicio, $periodoFim);

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

                    // Phase 53 W4 hotfix UAT 2026-07-02: campaign_name/status eram gravados
                    // hardcoded null aqui, ignorando o merge com listCampaigns (Wave 1 T1).
                    // Consequência: 100% dos sugadores criados ficavam com campaign_name=NULL,
                    // impedindo filtros retroativos (SGI) e cleanups. Fix trivial: usar o
                    // $campaignsInfo populado pelo path a montante (fail-open preservado —
                    // se listCampaigns caiu, ambos ficam null como antes).
                    $campInfo = $campaignsInfo[$cId] ?? null;
                    $toUpsert[] = $this->buildRow($company->id, $refDateStr, $existingMap, [
                        'tipo'                 => Sugador::TIPO_ADGROUP,
                        'campaign_id'          => $cId,
                        'campaign_name'        => $campInfo['name']   ?? null,
                        'campaign_status'      => $campInfo['status'] ?? null,
                        'adgroup_id'           => $ad['adgroup_id'],
                        'adgroup_name'         => $ad['adgroup_name'],
                        'thumbnail'            => $ad['thumbnail']       ?? null,
                        'adgroup_type'         => $ad['adgroup_type']    ?? null,
                        'catalog_listing'      => (bool) ($ad['catalog_listing'] ?? false),
                        // Phase 42 Plan 42-06 — provider ML retorna mlb_id (do item_id)
                        // e mlb_titulo no contrato §3. Antes hardcodado null por estar
                        // assumindo path Adman; agora propaga quando origem ML expoe.
                        'mlb_id'               => $ad['mlb_id']     ?? null,
                        'mlb_titulo'           => $ad['mlb_titulo'] ?? null,
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
                // Phase 39 Plan 39-04: substitui $this->adman->fetchCampaignsRange
                // por $provider->fetchCampaignsMetrics — assinatura recebe Carbon
                // ao invés de strings de data.
                $campaigns = $provider->fetchCampaignsMetrics($company, $periodoInicio, $periodoFim);

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

        // Phase 42 D-05 (briefing §10.2 + §13): fluxo final é API ML → normalizer →
        // SugadorAnalysisService → tabela sugadores → /sugadores. Idempotência por
        // chave estável (company_id, reference_date, tipo, campaign_id, adgroup_id)
        // garante que re-análise atualiza métricas sem duplicar linhas. Status travados
        // (D-06: em_acao/resolvido/ignorado/movido/auto_resolvido) são preservados
        // em buildRow via STATUS_TRAVADOS — comportamento válido para ambos providers.

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

        // ─── Persistencia adgroup -> [MLB IDs] (quick task 260626-qgf) ────────
        // Phase 30 (Adman) tem Job dedicado de sync (SyncCompanyAdgroupMlbsJob);
        // provider ML expoe o mapa direto no payload de ads (fetchAdgroupMlbs).
        // Persistimos aqui para o drilldown do Show.jsx ler instantaneamente via
        // AdgroupMlbMapRepository::getMlbsForAdgroup, sem nova chamada a API.
        // Fail-open: erro no fetch nao derruba a analise (cache complementar).
        if (!$dryRun && $provider->name() === 'ml') {
            try {
                $map = $provider->fetchAdgroupMlbs($company, $periodoInicio, $periodoFim);
                if (!empty($map)) {
                    $count = $this->adgroupMlbMap->bulkSetFromProvider($company->id, $map);
                    Log::info(
                        "[Sugadores] Empresa {$company->id} ({$company->name}): "
                        . "{$count} pares (adgroup, MLB) persistidos em adman_adgroup_mlbs (provider ML)."
                    );
                }
            } catch (\Throwable $e) {
                Log::warning(
                    "[Sugadores] Empresa {$company->id} ({$company->name}): "
                    . 'falha ao persistir mapa adgroup->MLB via provider ML: ' . $e->getMessage()
                );
            }
        }

        // ─── Auto-resolução (Phase 15 + polish Phase 42) ──────────────────────
        // Pendentes cuja chave (tipo|campaign_id|adgroup_id) não foi re-detectada
        // hoje deixaram de bater os critérios — não acumular fila.
        //
        // Phase 42 polish: agora usa `reference_date <= hoje` (antes era `<`).
        // A regra antiga assumia que config era estatico no dia; em producao o
        // admin altera SugadorConfig (gasto_minimo, cpc_maximo etc) e re-roda
        // analise no mesmo dia — sugadores criados em runs anteriores com
        // criterios mais frouxos precisam ser re-avaliados. Sem este `=`, os
        // antigos do mesmo dia ficavam orfaos como `pendente` mesmo quando o
        // ad nao bate mais nos criterios novos.
        //
        // STATUS_TRAVADOS continuam protegidos pelo filtro status='pendente'.
        // Sugadores que ainda batem (mesmo apos config mudar) foram marcados
        // em $chavesHoje pelo upsert acima, entao NAO entram em
        // $idsAutoResolvidos — preservacao do status pendente legitimo.
        $autoResolvidosCount = 0;
        if (!$dryRun) {
            // Set de chaves detectadas hoje a partir do upsert
            $chavesHoje = [];
            foreach ($toUpsert as $row) {
                $chavesHoje["{$row['tipo']}|{$row['campaign_id']}|{$row['adgroup_id']}"] = true;
            }

            $pendentesAntigos = Sugador::where('company_id', $company->id)
                ->where('status', Sugador::STATUS_PENDENTE)
                ->where('reference_date', '<=', $refDateStr)
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

        // Phase 42 D-06: status TRAVADOS por DECISAO HUMANA — em_acao, resolvido,
        // ignorado, movido — sao preservados em re-analise (nao voltam a pendente).
        //
        // EXCECAO (polish Phase 42 — relato operador): `auto_resolvido` NAO eh
        // decisao humana — eh autoresolucao automatica quando o ad deixou de
        // bater os criterios. Quando o admin AFROUXA a config (gasto 90 -> 20)
        // e os ads voltam a bater criterios, eles chegam a este buildRow
        // (motivos != []). Nesse caso, o auto_resolvido foi obsoleto pela
        // mudanca de config — restaurar pra pendente pra reaparecer na listagem.
        //
        // STATUS_TRAVADOS originais (em_acao/resolvido/ignorado/movido)
        // continuam preservados — analista decidiu manualmente, sistema respeita.
        $statusTravadoHumano = [
            Sugador::STATUS_EM_ACAO,
            Sugador::STATUS_RESOLVIDO,
            Sugador::STATUS_IGNORADO,
            Sugador::STATUS_MOVIDO,
        ];
        $status = ($existing && in_array($existing->status, $statusTravadoHumano, true))
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
        // Phase 42 D-01: gating composto opcional via cpc_minimo_cliques (briefing §8 Opcao B).
        // Quando cpc_minimo_cliques eh null, comportamento legacy preservado (CPC > limite + zero vendas).
        // Quando preenchido, exige tambem clicks >= cpc_minimo_cliques pra marcar hit.
        if ($config->cpc_maximo !== null && $cpc !== null) {
            $threshold = (float) $config->cpc_maximo;
            $criteria[] = [
                'key'   => 'cpc_alto',
                'hit'   => $vendas === 0
                            && (float) $cpc > $threshold
                            && ($config->cpc_minimo_cliques === null || $clicks >= (int) $config->cpc_minimo_cliques),
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

        // Phase 53-02: filtro venda organica global (fix B2 BARAOSHOP + B3 DINMAP —
        // research §Caso B2 e §Caso B3). Se motivo 'gasto_sem_venda' bate mas o MLB
        // vende globalmente >= 10 unidades (FULL organico, busca direta, etc), o
        // adgroup NAO e sugador — cliente escolheu pausar o ads porque produto
        // vende sem ele. Threshold 10 LOCKED (research §Assumptions A1 —
        // configurable e feature futura, DEFERRED).
        //
        // sold_global vem do provider ML (Wave 1 fetchItemStatus -> Wave 2 chave
        // canonica). Path Adman NAO expoe essa chave — sold_global=null preserva
        // comportamento legacy (fail-open universal).
        //
        // Filtro roda DEPOIS da projecao final — nao altera semantica de
        // required/optional acima. So remove 'gasto_sem_venda' se satisfeito o
        // filtro; outros motivos (cpc_alto, acos_alto, cliques_sem_conversao)
        // sao preservados. Se 'gasto_sem_venda' era o unico motivo, retorna []
        // e o caller nao persiste o sugador (linha 198 do analyzeCompany:
        // `if (empty($motivos)) continue;`).
        $soldGlobal = $metrics['sold_global'] ?? null;
        if ($soldGlobal !== null && (int) $soldGlobal >= 10) {
            $motivos = array_values(array_filter($motivos, fn($m) => $m !== 'gasto_sem_venda'));
        }

        return $motivos;
    }

    /**
     * LEGACY Phase 30 — Lookup `campaignId => ['name', 'status']` consumindo
     * AdmanService::fetchAllCampaigns direto. Preservado para callers externos
     * (CleanupSugadoresQuarentena command line) que passam $custId puro.
     *
     * Phase 39 Plan 39-04: o fluxo novo de analyzeCompany usa
     * {@see self::buildCampaignsInfoFromProvider()} — vai pelo provider
     * resolvido pela factory ao invés do AdmanService direto. Este método
     * permanece intocado funcionalmente para não quebrar
     * CleanupSugadoresQuarentena (fora de escopo Phase 39).
     *
     * Fail-open: se a chamada falha, retorna array vazio e a análise segue
     * sem o filtro.
     */
    public function loadCampaignsInfo(string $custId, string $marketplace = 'meli'): array
    {
        try {
            // Phase 18.5: marketplace dinamico (default 'meli' preserva
            // compat com callers nao migrados — ex.: CleanupSugadoresQuarentena
            // command line passa custId puro e usa default).
            $campaigns = $this->adman->fetchAllCampaigns($custId, $marketplace);
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
     * Phase 39 Plan 39-04: monta lookup `campaign_id => ['name', 'status']` via
     * provider (substitui chamada direta ao AdmanService::fetchAllCampaigns
     * que ainda vive em {@see self::loadCampaignsInfo()} legacy).
     *
     * O contrato {@see SugadoresAdsProvider::fetchCampaigns()} já retorna o
     * payload normalizado com chaves campaign_id/campaign_name/campaign_status,
     * portanto este método apenas indexa por campaign_id. Fail-open: se o
     * provider falha, retorna [] e a análise segue sem o filtro de quarentena.
     */
    private function buildCampaignsInfoFromProvider(SugadoresAdsProvider $provider, Company $company): array
    {
        try {
            $campaigns = $provider->fetchCampaigns($company);
        } catch (\Throwable $e) {
            Log::warning(
                "[Sugadores] Não conseguiu listar campanhas via provider '{$provider->name()}' "
                . "para empresa {$company->id} pro filtro de quarentena: " . $e->getMessage()
            );
            return [];
        }

        $lookup = [];
        foreach ($campaigns as $c) {
            $id = (string) ($c['campaign_id'] ?? '');
            if ($id === '') continue;
            $lookup[$id] = [
                'name'   => $c['campaign_name']   ?? null,
                'status' => $c['campaign_status'] ?? null,
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
