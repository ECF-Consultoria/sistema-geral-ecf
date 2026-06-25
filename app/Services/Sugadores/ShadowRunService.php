<?php

namespace App\Services\Sugadores;

use App\Models\Company;
use App\Models\SugadorProviderItem;
use App\Models\SugadorProviderRun;
use App\Models\SugadorConfig;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\MercadoLivreAdsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Phase 40 Plan 40-02 — Orquestrador de shadow runs paralelas (Adman + ML).
 *
 * Para cada (empresa + data de referência), executa 2 vezes o SugadorAnalysisService:
 *   - 1 vez forçando provider 'adman'
 *   - 1 vez forçando provider 'ml'
 *
 * Ambas as execuções usam $dryRun=true (gate de segurança REQ-40-02) — o analyzer
 * já garante via Phase 39 que com dryRun=true NÃO grava em `sugadores`. Os motivos
 * detectados em cada execução são persistidos em `sugador_provider_runs` (1 row por
 * provider) + `sugador_provider_items` (1 row por motivo) — tabelas auxiliares criadas
 * em Plan 40-01 dedicadas ao shadow mode.
 *
 * IMPORTANTE — este serviço NUNCA grava na tabela canônica `sugadores`. Esse é o
 * gate central de toda a Phase 40: medir paridade Adman vs ML sem afetar produção.
 * Se um futuro caminho de gravação for necessário, ele virá em Phase 42 (cut-over)
 * via um service SEPARADO — não modificando este.
 *
 * Falha isolada por provider: try/catch \Throwable por execução, então uma falha
 * em Adman (RuntimeException, timeout, etc) não interrompe a execução de ML, e
 * vice-versa. A row da execução falha fica com status='failed' e error=msg.
 */
class ShadowRunService
{
    /**
     * Construtor.
     *
     * @param SugadorAnalysisService $analysis  Analyzer canônico (Phase 39).
     * @param ?MercadoLivreAdsService $mlAds    Phase 41-04 — INJEÇÃO OPCIONAL.
     *        Quando disponível via DI, suas métricas operacionais (getLastRunMetrics)
     *        são mescladas no `summary` JSON das rows do provider 'ml'. Nullable
     *        para preservar testes Plan 40-02 que mockam apenas SugadorAnalysisService
     *        (Mockery não precisa configurar expectativas no MercadoLivreAdsService
     *        nesses testes). Em produção o container Laravel resolve automaticamente.
     */
    public function __construct(
        private SugadorAnalysisService $analysis,
        private ?MercadoLivreAdsService $mlAds = null,
    ) {}

    /**
     * Roda shadow para uma (empresa + data). Retorna sumário das 2 execuções.
     *
     * @return array{adman_run_id: int, ml_run_id: int, adman_status: string, ml_status: string}
     */
    public function run(Company $company, ?Carbon $referenceDate = null): array
    {
        $referenceDate = $referenceDate ?? now()->startOfDay();

        // Janela analisada espelha exatamente a lógica do SugadorAnalysisService
        // (linhas 136-137 do service): periodoFim = refDate - 1d; periodoInicio
        // = periodoFim - (dias_analise - 1). Lemos a config da empresa via API
        // pública estática `forCompany` (mesma usada pelo analyzer internamente).
        $config        = SugadorConfig::forCompany($company);
        $diasAnalise   = (int) ($config->dias_analise ?? 7);
        $periodoFim    = $referenceDate->copy()->subDay();
        $periodoInicio = $periodoFim->copy()->subDays(max(0, $diasAnalise - 1));

        $results = [];
        foreach (['adman', 'ml'] as $providerName) {
            $results[$providerName] = $this->executeForProvider(
                $company,
                $referenceDate,
                $periodoInicio,
                $periodoFim,
                $providerName,
            );
        }

        return [
            'adman_run_id' => $results['adman']['run_id'],
            'ml_run_id'    => $results['ml']['run_id'],
            'adman_status' => $results['adman']['status'],
            'ml_status'    => $results['ml']['status'],
        ];
    }

    /**
     * Executa shadow para 1 provider: cria row 'running', chama analyzer com
     * dryRun=true, persiste items, marca completed/failed.
     *
     * @return array{run_id: int, status: string}
     */
    private function executeForProvider(
        Company $company,
        Carbon $referenceDate,
        Carbon $periodoInicio,
        Carbon $periodoFim,
        string $providerName,
    ): array {
        $run = SugadorProviderRun::create([
            'company_id'     => $company->id,
            'provider'       => $providerName,
            'reference_date' => $referenceDate->toDateString(),
            'periodo_inicio' => $periodoInicio->toDateString(),
            'periodo_fim'    => $periodoFim->toDateString(),
            'status'         => 'running',
            'started_at'     => now(),
            'finished_at'    => null,
            'error'          => null,
            'summary'        => null,
        ]);

        try {
            // ─── GATE DE SEGURANÇA REQ-40-02 ───────────────────────────────
            // O 4º argumento $dryRun é HARDCODED como true. NUNCA passar false
            // aqui — o caminho de gravação em `sugadores` virá em Phase 42 via
            // um service SEPARADO (cut-over por empresa). Em Phase 40 medimos
            // paridade sem afetar a tabela canônica de produção.
            $result = $this->analysis->analyzeCompany(
                $company,
                $referenceDate,
                true,            // $dryRun — SEMPRE true neste service
                $providerName,   // $forceProvider
            );

            // Persiste cada motivo detectado em sugador_provider_items.
            $items = 0;
            foreach (($result['detalhes'] ?? []) as $det) {
                SugadorProviderItem::create($this->buildItemPayload($run->id, $det));
                $items++;
            }

            $summary = [
                'campanhas' => (int) ($result['campanhas'] ?? 0),
                'adgroups'  => (int) ($result['adgroups']  ?? 0),
                'items'     => $items,
                'skipped'   => (bool) ($result['skipped'] ?? false),
                'reason'    => $result['reason'] ?? null,
            ];

            // Phase 41-04 — mescla métricas operacionais ML no summary apenas quando
            // provider='ml' e MercadoLivreAdsService está disponível via DI. Coleta
            // defensiva: exceção em getLastRunMetrics NÃO interrompe a run (apenas
            // emite warning no log). Mantém status='completed' e summary sem
            // ml_metrics em caso de falha — UI Plan 41-05 lida com chave ausente.
            if ($providerName === 'ml' && $this->mlAds !== null) {
                try {
                    $summary['ml_metrics'] = $this->mlAds->getLastRunMetrics();
                } catch (\Throwable $e) {
                    Log::warning(
                        "[Sugadores Shadow] Falha ao coletar ml_metrics empresa {$company->id}: " . $e->getMessage()
                    );
                }
            }

            $run->update([
                'status'      => 'completed',
                'finished_at' => now(),
                'summary'     => $summary,
            ]);

            return ['run_id' => $run->id, 'status' => 'completed'];
        } catch (\Throwable $e) {
            // Falha isolada — loga, marca row e segue. O loop em run() continua
            // pro próximo provider mesmo se este falhar.
            Log::error(
                "[Sugadores Shadow] Empresa {$company->id} provider {$providerName}: " . $e->getMessage()
            );

            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'error'       => mb_substr($e->getMessage(), 0, 65000),
            ]);

            return ['run_id' => $run->id, 'status' => 'failed'];
        }
    }

    /**
     * Constrói o payload de criação de um SugadorProviderItem a partir do
     * "detalhe" retornado por SugadorAnalysisService::analyzeCompany.
     *
     * Shape do $det (ver SugadorAnalysisService.php linhas 220-228 e 300-307):
     *   tipo, campaign_id, [adgroup_id], [mlb_id], nome, investimento, vendas, motivos
     *
     * Tudo que não é coluna dedicada vira `metrics_json` — é o que ProviderComparisonService
     * (Plan 40-03) vai consumir pra diffar.
     */
    private function buildItemPayload(int $runId, array $det): array
    {
        $motivos = (array) ($det['motivos'] ?? []);

        // metrics_json = tudo do detalhe que não tem coluna dedicada
        $metricsJson = $det;
        unset(
            $metricsJson['tipo'],
            $metricsJson['campaign_id'],
            $metricsJson['adgroup_id'],
            $metricsJson['mlb_id'],
            $metricsJson['motivos'],
        );

        return [
            'run_id'       => $runId,
            'tipo'         => (string) ($det['tipo'] ?? ''),
            'campaign_id'  => (string) ($det['campaign_id'] ?? ''),
            'adgroup_id'   => isset($det['adgroup_id']) ? (string) $det['adgroup_id'] : null,
            'mlb_id'       => isset($det['mlb_id']) ? (string) $det['mlb_id'] : null,
            'motivos'      => $motivos,
            'metrics_json' => $metricsJson,
            'raw_hash'     => $this->computeRawHash($motivos, $metricsJson),
        ];
    }

    /**
     * SHA256 determinístico sobre (motivos ordenados + metrics ordenadas).
     * Usado para detectar duplicatas exatas em re-runs com a mesma config.
     *
     * Determinismo:
     *   - motivos ordenados via sort() (ordem original irrelevante)
     *   - metrics ordenadas recursivamente via ksort em cada nível
     *   - encode JSON com JSON_UNESCAPED_UNICODE + JSON_THROW_ON_ERROR
     */
    private function computeRawHash(array $motivos, array $metrics): string
    {
        $motivosSorted = $motivos;
        sort($motivosSorted);

        $metricsSorted = $metrics;
        $this->ksortRecursive($metricsSorted);

        $payload = json_encode(
            ['motivos' => $motivosSorted, 'metrics' => $metricsSorted],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $payload);
    }

    /**
     * Ordena chaves de array associativo recursivamente (não toca em listas).
     */
    private function ksortRecursive(array &$arr): void
    {
        if ($this->isAssoc($arr)) {
            ksort($arr);
        }
        foreach ($arr as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
