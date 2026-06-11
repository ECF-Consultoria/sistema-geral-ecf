<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\EcfDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Quick task 260611-eml — autopopulação de email_cliente + telefone via ECF Drive.
 *
 * Percorre empresas com cust_id (= adman_account_id || ml_store_id) que tenham
 * email_cliente OU telefone vazio e consulta `GET /clientes/{custId}` na API
 * do ECF Drive (EcfDriveService::cliente). Atualiza apenas os campos vazios —
 * preserva valores já preenchidos manualmente.
 *
 * Uso:
 *   php artisan companies:importar-drive             # roda em todas elegíveis
 *   php artisan companies:importar-drive --dry-run   # só relata, não salva
 *   php artisan companies:importar-drive --id=42     # apenas a empresa 42
 *   php artisan companies:importar-drive --force     # sobrescreve mesmo quando ja preenchido
 *
 * Throttle 700ms entre requests (consistente com Phase 22+).
 * Idempotente — pode rodar várias vezes sem efeito colateral.
 */
class CompaniesImportarDrive extends Command
{
    protected $signature = 'companies:importar-drive
        {--dry-run : Não salva, apenas lista o que seria atualizado}
        {--id= : Restringe a uma empresa específica}
        {--force : Sobrescreve email_cliente/telefone mesmo se já estiverem preenchidos}';

    protected $description = 'Importa email_cliente + telefone das empresas via ECF Drive (GET /clientes/{custId}).';

    public function handle(EcfDriveService $drive): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');
        $only    = $this->option('id');

        $query = Company::query()->where('active', true);
        if ($only) {
            $query->where('id', $only);
        }

        // Empresas elegíveis: ativas, com cust_id (adman ou ML), e com pelo menos
        // 1 campo vazio (a menos que --force).
        $companies = $query->get()->filter(function (Company $c) use ($force) {
            if (!$c->cust_id) {
                return false;
            }
            if ($force) {
                return true;
            }
            return empty($c->email_cliente) || empty($c->telefone);
        });

        $total = $companies->count();
        $this->info("[Importar Drive] {$total} empresa(s) elegível(eis). dry-run=" . ($dryRun ? 'sim' : 'nao'));

        $stats = ['ok' => 0, 'atualizada' => 0, 'sem_dado' => 0, 'erro' => 0, 'pulada' => 0];

        foreach ($companies as $c) {
            try {
                $payload = $drive->cliente($c->cust_id);
                $stats['ok']++;

                $emailRemoto = is_string($payload['email'] ?? null) ? trim($payload['email']) : null;
                $foneRemoto  = is_string($payload['telefone'] ?? null) ? trim($payload['telefone']) : null;

                $update = [];
                if ($emailRemoto && ($force || empty($c->email_cliente))) {
                    $update['email_cliente'] = $emailRemoto;
                }
                if ($foneRemoto && ($force || empty($c->telefone))) {
                    $update['telefone'] = $foneRemoto;
                }

                if (empty($update)) {
                    $stats['sem_dado']++;
                    $this->line("  · {$c->id} {$c->name} — Drive sem email/telefone OR já preenchido");
                    continue;
                }

                $resumo = collect($update)->map(fn($v, $k) => "{$k}={$v}")->implode(' | ');
                if ($dryRun) {
                    $stats['pulada']++;
                    $this->line("  ? {$c->id} {$c->name} — seria atualizado: {$resumo}");
                } else {
                    $c->update($update);
                    $stats['atualizada']++;
                    $this->info("  ✓ {$c->id} {$c->name} — {$resumo}");
                }
            } catch (\Throwable $e) {
                $stats['erro']++;
                $this->error("  × {$c->id} {$c->name} — Drive erro: " . $e->getMessage());
                Log::warning("[Importar Drive] Falha cust_id={$c->cust_id} empresa={$c->id}: " . $e->getMessage());
            }

            // Throttle consistente com Phase 22+ (700ms entre requests do Drive).
            usleep(700_000);
        }

        $this->newLine();
        $this->info(sprintf(
            '[Importar Drive] Concluído. OK=%d, atualizadas=%d, sem-dado=%d, erros=%d, dry-puladas=%d',
            $stats['ok'],
            $stats['atualizada'],
            $stats['sem_dado'],
            $stats['erro'],
            $stats['pulada'],
        ));

        return self::SUCCESS;
    }
}
