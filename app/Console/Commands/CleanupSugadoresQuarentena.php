<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Sugador;
use App\Services\SugadorAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fecha (marca como resolvido) sugadores adgroup-level já tratados pelo analista
 * via movimentação pra campanha de quarentena (SGI/Sugadores/Sugador) ou cuja
 * campanha hoje está pausada/encerrada.
 *
 * Existe pra limpar o backlog que ficou no banco entre o nascimento do módulo
 * (sem filtro de quarentena) e a aplicação do filtro em SugadorAnalysisService.
 *
 * Atualiza `status=resolvido` + `acao_tomada=outro` + `observacao` explicando.
 * Preserva histórico (não deleta). Spatie LogsActivity registra automaticamente.
 *
 * Uso:
 *   php artisan sugadores:cleanup-quarentena --dry-run    # mostra impacto
 *   php artisan sugadores:cleanup-quarentena              # aplica
 */
class CleanupSugadoresQuarentena extends Command
{
    protected $signature = 'sugadores:cleanup-quarentena {--dry-run : Apenas conta, sem alterar}';
    protected $description = 'Fecha sugadores cuja campanha hoje é SGI/Sugadores ou está pausada/encerrada (analista já tratou).';

    public function handle(SugadorAnalysisService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tag    = $dryRun ? '[DRY-RUN] ' : '';

        // Empresas com sugadores adgroup ainda em aberto (pendente ou em_acao)
        $companyIds = Sugador::whereIn('status', [Sugador::STATUS_PENDENTE, Sugador::STATUS_EM_ACAO])
            ->where('tipo', Sugador::TIPO_ADGROUP)
            ->distinct()
            ->pluck('company_id');

        $companies = Company::whereIn('id', $companyIds)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('Nenhuma empresa com sugadores adgroup em aberto. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->line("{$tag}Verificando {$companies->count()} empresa(s) com sugadores adgroup em aberto...\n");

        $totalCompanies = 0;
        $totalResolved  = 0;
        $totalFailed    = 0;

        foreach ($companies as $company) {
            $totalCompanies++;
            $this->line("→ #{$company->id} {$company->name}");

            $info = $service->loadCampaignsInfo($company->adman_account_id);
            if (empty($info)) {
                $this->warn('  Falha ao listar campanhas — pulando.');
                $totalFailed++;
                continue;
            }

            $sugadores = Sugador::where('company_id', $company->id)
                ->where('tipo', Sugador::TIPO_ADGROUP)
                ->whereIn('status', [Sugador::STATUS_PENDENTE, Sugador::STATUS_EM_ACAO])
                ->get();

            $resolved = 0;
            foreach ($sugadores as $sug) {
                $campInfo = $info[$sug->campaign_id] ?? null;
                if (!$service->shouldSkipCampaign($campInfo)) continue;

                $resolved++;
                $name   = $campInfo['name']   ?? '?';
                $status = $campInfo['status'] ?? '?';

                if ($dryRun) {
                    $this->line("    [sug #{$sug->id}] adgroup='{$sug->adgroup_name}' → campanha '{$name}' (status={$status})");
                    continue;
                }

                $obs = "Fechamento automático: campanha atual '{$name}' (status={$status}) é de quarentena — analista já tratou movendo o adgroup pra cá.";

                $sug->update([
                    'status'        => Sugador::STATUS_RESOLVIDO,
                    'acao_tomada'   => Sugador::ACAO_OUTRO,
                    'observacao'    => $obs,
                    'resolvido_em'  => now(),
                    'resolvido_por' => null, // sistema
                ]);
            }

            $totalResolved += $resolved;
            $this->line("  {$tag}{$resolved} sugador(es) resolvido(s) nesta empresa.\n");

            // Throttle leve pra não martelar Adman entre empresas
            usleep(500_000);
        }

        $this->info("\n{$tag}Concluído.");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Empresas verificadas', $totalCompanies],
                ['Empresas com falha de listagem', $totalFailed],
                ['Sugadores ' . ($dryRun ? 'que seriam' : 'efetivamente') . ' resolvidos', $totalResolved],
            ]
        );

        Log::info("[Sugadores/Cleanup] {$tag}empresas={$totalCompanies} resolvidos={$totalResolved} falhas={$totalFailed}");

        return self::SUCCESS;
    }
}
