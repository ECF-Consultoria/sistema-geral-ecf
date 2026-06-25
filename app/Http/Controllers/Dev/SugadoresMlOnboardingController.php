<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\SugadorMlCompanyConfig;
use App\Models\SugadorProviderRun;
use App\Services\Sugadores\ProviderComparisonService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 41 Plan 41-05 — Painel admin de onboarding ML por empresa.
 *
 * UI operacional que mostra, em uma unica tela, o estado da migracao
 * Sugadores Adman → ML por empresa: token ML (ausente/expirado/erro/ok),
 * advertiser_id (cache Plan 41-01), ultimo smoke (storage local), paridade
 * shadow dos ultimos 7 dias (ProviderComparisonService Plan 40-03) e status
 * geral derivado (adman_only / ml_shadow / ml_primary_candidate / ml_primary).
 *
 * Acoes inline (smoke, shadow, toggle shadow) sao DISPATCHADAS via
 * Artisan::queue (assincrono — nao bloqueia o request). Polling em tempo
 * real foi deferido (CONTEXT §Deferred).
 *
 * Esta classe NAO grava em `sugadores` — somente em `sugador_ml_company_config`
 * (flag shadow_enabled). primary_enabled e Phase 42 (cut-over real).
 *
 * Gate: middleware `role:admin` aplicado na declaracao da rota em routes/web.php.
 */
class SugadoresMlOnboardingController extends Controller
{
    /** Constante usada nas linhas baseline do PLAN. */
    private const SHADOW_PARIDADE_MIN_CANDIDATE = 95.0;

    /** Janela em dias para leitura de shadow runs / paridade media. */
    private const SHADOW_WINDOW_DAYS = 7;

    public function __construct(private ProviderComparisonService $comparison)
    {
    }

    /**
     * Pagina principal: tabela com 1 linha por empresa active=true.
     */
    public function index(): Response
    {
        $companies = Company::with(['mlToken', 'mlAdvertiser', 'sugadorMlConfig'])
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => $this->buildRow($c, $this->resolveShadowStats($c)))
            ->values()
            ->all();

        return Inertia::render('Dev/SugadoresMlOnboarding/Index', [
            'companies' => $companies,
        ]);
    }

    /**
     * Dispatcha o smoke ML para uma empresa (assincrono via queue).
     */
    public function runSmoke(Company $company): RedirectResponse
    {
        Artisan::queue('sugadores:ml-smoke', ['--company' => $company->id]);

        return back()->with('success', "Smoke ML disparado para {$company->name}.");
    }

    /**
     * Dispatcha o shadow run ML para uma empresa (assincrono via queue, janela 1d).
     */
    public function runShadow(Company $company): RedirectResponse
    {
        Artisan::queue('sugadores:shadow-ml', [
            '--company' => (string) $company->id,
            '--days'    => 1,
        ]);

        return back()->with('success', "Shadow ML disparado para {$company->name}.");
    }

    /**
     * Toggle (firstOrNew) do `shadow_enabled` em sugador_ml_company_config.
     *
     * NAO toca `primary_enabled` — esse flag e mutado SOMENTE na Phase 42
     * (cut-over real). Cria a row com primary_enabled=false explicito quando
     * for a primeira vez.
     */
    public function toggleShadow(Company $company): RedirectResponse
    {
        $config = SugadorMlCompanyConfig::firstOrNew(['company_id' => $company->id]);

        $wasEnabled = (bool) $config->shadow_enabled;
        $config->shadow_enabled    = ! $wasEnabled;
        $config->shadow_started_at = $wasEnabled ? null : now()->toDateString();

        // Defensive coding: ao criar a row, garantimos primary_enabled=false.
        if (! $config->exists) {
            $config->primary_enabled = false;
        }

        $config->save();

        $msg = $wasEnabled
            ? "Shadow desativado para {$company->name}."
            : "Shadow ativado para {$company->name}.";

        return back()->with('success', $msg);
    }

    // ───────────────────────────── Helpers privados ─────────────────────────────

    /**
     * Monta um row de payload por empresa (chaves consumidas pela tabela React).
     *
     * @param  array{paridade_motivos_pct?: float|int|null}|null  $shadowStats
     * @return array<string,mixed>
     */
    private function buildRow(Company $c, ?array $shadowStats): array
    {
        $tokenState = $this->resolveTokenState($c->mlToken);
        $advertiser = $c->mlAdvertiser !== null ? 'ok' : 'none';

        $paridade = $shadowStats['paridade_motivos_pct'] ?? null;
        $status   = $this->resolveStatus($c->sugadorMlConfig, $paridade);

        return [
            'id'                 => $c->id,
            'name'               => $c->name,
            'token_state'        => $tokenState,
            'advertiser'         => $advertiser,
            'smoke_last_at'      => $this->resolveLastSmoke($c->id),
            'shadow_paridade_7d' => $paridade !== null ? (float) $paridade : null,
            'status'             => $status,
            'actions'            => [
                'can_run_smoke'     => $tokenState === 'active',
                'can_run_shadow'    => $tokenState === 'active' && $advertiser === 'ok',
                'can_toggle_shadow' => $tokenState === 'active' && $advertiser === 'ok',
            ],
        ];
    }

    /**
     * Calcula o estado canonico do MlToken (4 valores).
     */
    private function resolveTokenState(?MlToken $token): string
    {
        if ($token === null) {
            return 'missing';
        }
        if (in_array($token->status, ['error_refresh', 'revoked'], true)) {
            return 'error_refresh';
        }
        if ($token->isExpired()) {
            return 'expired';
        }
        return 'active';
    }

    /**
     * Retorna o timestamp ISO do ultimo arquivo de smoke ml para a empresa,
     * ou null se nao houver.
     */
    private function resolveLastSmoke(int $companyId): ?string
    {
        try {
            $files   = Storage::disk('local')->files('sugadores/ml-smoke');
            $matches = array_filter(
                $files,
                fn (string $f): bool => str_starts_with(basename($f), "{$companyId}-")
            );
            if (empty($matches)) {
                return null;
            }
            $latest = max(array_map(
                fn (string $f): int => Storage::disk('local')->lastModified($f),
                $matches
            ));
            return Carbon::createFromTimestamp($latest)->toIso8601String();
        } catch (\Throwable $e) {
            Log::warning('[SugadoresMlOnboarding] Falha ao ler smoke local', [
                'company_id' => $companyId,
                'msg'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retorna stats da janela 7d de shadow runs (paridade media).
     *
     * Se nao ha SugadorProviderRun nos ultimos 7 dias OU se a chamada ao
     * comparison service lancar, retorna null (paridade indisponivel).
     *
     * @return array<string,mixed>|null
     */
    private function resolveShadowStats(Company $c): ?array
    {
        $start = now()->subDays(self::SHADOW_WINDOW_DAYS);

        $hasRuns = SugadorProviderRun::where('company_id', $c->id)
            ->where('reference_date', '>=', $start->toDateString())
            ->where('status', 'completed')
            ->exists();

        if (! $hasRuns) {
            return null;
        }

        try {
            return $this->comparison->compareWindow($c->id, $start, now());
        } catch (\Throwable $e) {
            Log::warning('[SugadoresMlOnboarding] Falha ao calcular paridade', [
                'company_id' => $c->id,
                'msg'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Deriva o status geral da empresa na migracao ML.
     *
     * Ordem de prioridade:
     *  1. primary_enabled=true            → 'ml_primary'
     *  2. shadow_enabled=true && paridade >= 95% → 'ml_primary_candidate'
     *  3. shadow_enabled=true             → 'ml_shadow'
     *  4. caso contrario                  → 'adman_only'
     */
    private function resolveStatus(?SugadorMlCompanyConfig $cfg, float|int|null $paridade): string
    {
        if ($cfg?->primary_enabled === true) {
            return 'ml_primary';
        }
        if ($cfg?->shadow_enabled === true && $paridade !== null && (float) $paridade >= self::SHADOW_PARIDADE_MIN_CANDIDATE) {
            return 'ml_primary_candidate';
        }
        if ($cfg?->shadow_enabled === true) {
            return 'ml_shadow';
        }
        return 'adman_only';
    }
}
