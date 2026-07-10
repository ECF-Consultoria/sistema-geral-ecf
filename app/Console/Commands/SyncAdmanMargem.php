<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza SÓ os campos de custo/margem via Adman para empresas com OAuth ML ativo.
 *
 * Ajuste 2026-07-10 (audit margem-luiz-ana): pós-cutover Adman→ML (commit
 * `c85b86f`, 01/06), essas empresas pararam de rodar `adman:sync` e passaram
 * a ser sincronizadas só via `MercadoLivreService::syncCompany`, que não
 * expõe `contribution_margin` porque a API do ML não devolve CMV. Este
 * command fecha o gap chamando `AdmanService::syncCompanyMarginOnly` que
 * atualiza APENAS `product_cost`, `taxes`, `contribution_margin`, etc,
 * sem tocar em `revenue`/`ad_spend` (esses permanecem do ml:sync, mais fresco).
 *
 * Filtra empresas com `ml_tokens.status='active'` — as sem OAuth ML já são
 * cobertas pelo `adman:sync` normal e não precisam desta rodada.
 *
 * Throttle: 7s entre calls (mesmo padrão do adman:sync original). Pra 59
 * empresas × N dias, o comando leva ~7×59×N segundos. Backfill de 15 dias
 * = ~2h em foreground; usar `--dias=1` no schedule diário.
 *
 * Uso:
 *   php artisan adman:sync-margem                  # ontem só (schedule diário)
 *   php artisan adman:sync-margem --dias=15        # backfill: últimos 15 dias
 *   php artisan adman:sync-margem --company=238    # debug pontual pra 1 empresa
 *   php artisan adman:sync-margem --data=2026-07-01 # 1 dia específico
 */
class SyncAdmanMargem extends Command
{
    protected $signature = 'adman:sync-margem
        {--dias= : Últimos N dias (padrão: 1 = só ontem). Ignora --data.}
        {--data= : 1 dia específico YYYY-MM-DD (padrão: ontem)}
        {--company= : Só 1 empresa por ID (debug)}';

    protected $description = 'Sincroniza custo/margem via Adman pra empresas com OAuth ML ativo (fecha o gap do cutover Adman→ML).';

    public function __construct(private AdmanService $adman)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Empresas alvo: OAuth ML ativo (essas foram excluídas do adman:sync
        // pelo cutover e ficaram sem margem desde ~30/06).
        $query = Company::query()
            ->where('active', true)
            ->whereHas('mlToken', fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            });

        if ($companyId = $this->option('company')) {
            $query->where('id', (int) $companyId);
        }

        $companies = $query->get();
        $total     = $companies->count();

        if ($total === 0) {
            $this->info('Nenhuma empresa OAuth ML ativa — nada a sincronizar.');
            return self::SUCCESS;
        }

        // Range de datas: --dias > --data > default (ontem).
        $dias = $this->option('dias');
        if ($dias !== null && $dias !== '') {
            $diasInt = max(1, (int) $dias);
            $datas   = [];
            for ($i = $diasInt; $i >= 1; $i--) {
                $datas[] = Carbon::now()->subDays($i)->toDateString();
            }
        } elseif ($data = $this->option('data')) {
            $datas = [Carbon::createFromFormat('Y-m-d', $data)->toDateString()];
        } else {
            $datas = [Carbon::now()->subDay()->toDateString()];
        }

        $totalCalls = $total * count($datas);
        $etaMin     = (int) ceil(($totalCalls * 7) / 60);
        $this->info("[Adman margem] {$total} empresas × " . count($datas) . " dias = {$totalCalls} calls (~{$etaMin}min).");

        $ok = 0; $skip = 0; $fail = 0;
        $t0 = microtime(true);

        foreach ($datas as $data) {
            $this->line("─── {$data} ───");

            foreach ($companies as $company) {
                try {
                    $metric = $this->adman->syncCompanyMarginOnly($company, $data);
                    if ($metric === null) {
                        $this->line("  · empresa {$company->id} ({$company->name}) — Adman sem margem em {$data}");
                        $skip++;
                    } else {
                        $margem = number_format((float) $metric->contribution_margin, 2, ',', '.');
                        $this->line("  ✓ empresa {$company->id} ({$company->name}) — margem R$ {$margem}");
                        $ok++;
                    }
                } catch (\Throwable $e) {
                    $this->error("  ✗ empresa {$company->id} — {$e->getMessage()}");
                    $fail++;
                }

                // Throttle Adman: 10 req/min ⇒ 7s entre calls (mesma folga do adman:sync).
                usleep(7_000_000);
            }
        }

        $tempo = round(microtime(true) - $t0, 1);
        $this->info("[Adman margem] Concluído em {$tempo}s — OK={$ok}, SKIP={$skip}, FAIL={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
