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
    protected $signature = 'ml:refresh-tokens
        {--recover : Tenta reativar TODOS os tokens revogados (recuperação de revogações por erro transitório)}';

    protected $description = 'Renova tokens Mercado Livre próximos de expirar e recupera revogações transitórias';

    public function handle(MercadoLivreService $ml): int
    {
        $recoverAll = (bool) $this->option('recover');

        // Seleciona:
        //  - ativos prestes a expirar (renovação proativa); e
        //  - revogados recentes (≤7d) p/ auto-recuperação — prováveis vítimas de
        //    erro transitório. Com --recover, tenta TODOS os revogados.
        $tokens = MlToken::query()
            ->with('company')
            ->where(function ($q) use ($recoverAll) {
                $q->where(fn($s) => $s->where('status', 'active')
                                      ->where('expires_at', '<', now()->addHours(2)));
                $q->orWhere(function ($s) use ($recoverAll) {
                    $s->where('status', 'revoked');
                    if (! $recoverAll) {
                        $s->where('updated_at', '>', now()->subDays(7));
                    }
                });
            })
            ->get();

        if ($tokens->isEmpty()) {
            $this->info('Nenhum token ML para renovar/recuperar.');
            return Command::SUCCESS;
        }

        $success    = 0;
        $failed     = 0;
        $recovered  = 0;

        foreach ($tokens as $token) {
            $wasRevoked = $token->status === 'revoked';
            try {
                $fresh = $ml->refreshToken($token);
                $success++;
                if ($wasRevoked && $fresh->status === 'active') {
                    $recovered++;
                    $this->line("  ✓ empresa {$token->company_id} ({$token->company?->name}) — reativado");
                } else {
                    $this->line("  ✓ empresa {$token->company_id} ({$token->company?->name})");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("[MercadoLivre] Falha ao renovar token empresa {$token->company_id}: {$e->getMessage()}");
                $this->warn("  ✗ empresa {$token->company_id}: {$e->getMessage()}");
            }
        }

        $this->info("ml:refresh-tokens concluído — sucesso: {$success}, reativados: {$recovered}, falha: {$failed}");

        return Command::SUCCESS;
    }
}
