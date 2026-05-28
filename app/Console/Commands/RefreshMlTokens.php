<?php

namespace App\Console\Commands;

use App\Models\MlToken;
use App\Services\MercadoLivreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renova proativamente tokens ML que expiram em menos de 2 horas.
 * Executado diariamente pelo scheduler — complementa a renovação reativa
 * que ocorre no momento de cada chamada à API.
 */
class RefreshMlTokens extends Command
{
    protected $signature   = 'ml:refresh-tokens';
    protected $description = 'Renova tokens Mercado Livre próximos de expirar';

    public function handle(MercadoLivreService $ml): int
    {
        $tokens = MlToken::where('status', 'active')
            ->where('expires_at', '<', now()->addHours(2))
            ->with('company')
            ->get();

        if ($tokens->isEmpty()) {
            $this->info('Nenhum token ML próximo de expirar.');
            return Command::SUCCESS;
        }

        $success = 0;
        $failed  = 0;

        foreach ($tokens as $token) {
            try {
                $ml->refreshToken($token);
                $success++;
                $this->line("  ✓ empresa {$token->company_id} ({$token->company?->name})");
            } catch (\Throwable $e) {
                $failed++;
                Log::error("[MercadoLivre] Falha ao renovar token empresa {$token->company_id}: {$e->getMessage()}");
                $this->warn("  ✗ empresa {$token->company_id}: {$e->getMessage()}");
            }
        }

        $this->info("ml:refresh-tokens concluído — sucesso: {$success}, falha: {$failed}");

        return Command::SUCCESS;
    }
}
