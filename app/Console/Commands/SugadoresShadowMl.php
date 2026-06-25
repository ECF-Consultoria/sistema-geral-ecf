<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Sugadores\ShadowRunService;
use Illuminate\Console\Command;

/**
 * Phase 40 Plan 40-04 — Dispara shadow mode (Adman + ML em paralelo) por
 * empresa e por dia. Resultado é gravado em `sugador_provider_runs` e
 * `sugador_provider_items` SEM tocar na tabela canônica `sugadores`
 * (gate REQ-40-02 garantido pelo ShadowRunService).
 *
 * Modos:
 *   --company={id}   → roda apenas para a empresa de ID numérico informado
 *   --company=all    → roda para todas as empresas listadas em
 *                      config('sugadores.ml_shadow_companies'), populada pela
 *                      env SUGADORES_ML_SHADOW_COMPANIES (CSV de IDs)
 *   --days=N         → quantos dias para trás rodar (clamp 1..90 contra
 *                      T-40-04-01 — DoS por janela absurda)
 *
 * Exit codes:
 *   0 → execução completa (mesmo com falhas individuais por provider, que
 *       ficam registradas na row com status='failed')
 *   1 → erro de validação (empresa inexistente, --company inválido, env vazia,
 *       --company=all sem config)
 */
class SugadoresShadowMl extends Command
{
    protected $signature = 'sugadores:shadow-ml
        {--company= : ID numérico da empresa ou "all" (usa env SUGADORES_ML_SHADOW_COMPANIES)}
        {--days=1   : Dias para trás (clamp 1..90)}';

    protected $description = 'Phase 40 — roda shadow mode (Adman + ML em paralelo) gravando em sugador_provider_runs/items sem alterar sugadores';

    public function __construct(private ShadowRunService $shadow)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyOpt = $this->option('company');

        if ($companyOpt === null || $companyOpt === '') {
            $this->error('Parâmetro --company obrigatório (ID numérico ou "all").');
            return self::FAILURE;
        }

        $companyIds = $this->resolveCompanies($companyOpt);
        if ($companyIds === null) {
            // Mensagem já impressa por resolveCompanies()
            return self::FAILURE;
        }
        if (empty($companyIds)) {
            $this->error('Nenhuma empresa elegível. Defina --company={id} ou configure SUGADORES_ML_SHADOW_COMPANIES no .env.');
            return self::FAILURE;
        }

        // Clamp silencioso contra T-40-04-01 (DoS por janela absurda)
        $days = max(1, min(90, (int) $this->option('days')));

        // Resolve todas as empresas; primeira inexistente aborta com mensagem clara
        // (T-40-04-02: validar existência antes de iniciar o loop).
        $companies = [];
        foreach ($companyIds as $id) {
            $company = Company::find($id);
            if ($company === null) {
                $this->error("Empresa {$id} não encontrada.");
                return self::FAILURE;
            }
            $companies[] = $company;
        }

        $admanOk = 0;
        $mlOk    = 0;
        $falhas  = 0;

        foreach ($companies as $company) {
            for ($i = 0; $i < $days; $i++) {
                $refDate = now()->startOfDay()->subDays($i);
                $this->line("Empresa {$company->id} ({$company->name}) — {$refDate->toDateString()}");

                try {
                    $result = $this->shadow->run($company, $refDate);
                } catch (\Throwable $e) {
                    $falhas++;
                    $this->error("  Erro: " . $e->getMessage());
                    continue;
                }

                if (($result['adman_status'] ?? null) === 'completed') {
                    $admanOk++;
                } else {
                    $falhas++;
                }
                if (($result['ml_status'] ?? null) === 'completed') {
                    $mlOk++;
                } else {
                    $falhas++;
                }

                $this->line(
                    "  Adman run_id={$result['adman_run_id']} status={$result['adman_status']}"
                    . " | ML run_id={$result['ml_run_id']} status={$result['ml_status']}"
                );
            }
        }

        $this->newLine();
        $this->info("Concluído: {$admanOk} runs Adman ok, {$mlOk} runs ML ok, {$falhas} falhas.");

        return self::SUCCESS;
    }

    /**
     * Resolve a opção --company em uma lista de IDs.
     *
     * Retorno:
     *   array  → lista de IDs (pode ser vazia se config vazia em modo 'all')
     *   null   → erro de validação já impresso ao operador (--company inválido)
     */
    private function resolveCompanies(string $opt): ?array
    {
        if ($opt === 'all') {
            $ids = (array) config('sugadores.ml_shadow_companies', []);
            return array_map('intval', $ids);
        }

        if (!ctype_digit($opt)) {
            $this->error("--company inválido: '{$opt}'. Use ID numérico ou 'all'.");
            return null;
        }

        return [(int) $opt];
    }
}
