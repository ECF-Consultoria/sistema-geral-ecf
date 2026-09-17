<?php

namespace App\Console\Commands;

use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keep-alive dos tokens Shopee (ERP e Ads), independente do sync diário.
 *
 * O refresh_token da Shopee vale ~30 dias e rotaciona a cada uso; hoje só o
 * shopee:sync / shopee:sync-ads o renovam. Se o sync parar (fila, scheduler,
 * erro antes da chamada), a cadeia morre em silêncio. Este comando renova todo
 * token ativo sem renovação há mais de 12h e tenta reativar revogados recentes
 * (≤7 dias) — prováveis vítimas de erro classificado como definitivo por engano.
 * Falhas ficam em shopee_tokens.last_error e aparecem no painel /shopee-oauth.
 */
class RefreshShopeeTokens extends Command
{
    protected $signature = 'shopee:refresh-tokens
        {--horas=12 : Renova tokens ativos sem renovação há mais de N horas}
        {--company= : Limita a uma empresa (id)}';

    protected $description = 'Renova (keep-alive) os tokens Shopee ERP e Ads e tenta reativar revogações recentes';

    public function handle(): int
    {
        $horas = max(0, (int) $this->option('horas'));

        $tokens = ShopeeToken::query()
            ->with('company:id,name')
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->where(function ($q) use ($horas) {
                $q->where(fn ($s) => $s->where('status', 'active')
                    ->where(fn ($d) => $d->whereNull('last_refreshed_at')
                        ->orWhere('last_refreshed_at', '<', now()->subHours($horas))));
                $q->orWhere(fn ($s) => $s->where('status', 'revoked')
                    ->where('updated_at', '>', now()->subDays(7)));
            })
            ->orderBy('company_id')
            ->get();

        if ($tokens->isEmpty()) {
            $this->info('[Shopee] Nenhum token para renovar.');
            return self::SUCCESS;
        }

        $ok = 0;
        $reativados = 0;
        $falhas = 0;

        foreach ($tokens as $token) {
            $eraRevogado = $token->status === 'revoked';
            $rotulo = "#{$token->company_id} {$token->company?->name} [{$token->app}]";

            try {
                $fresh = ShopeeService::for($token->app)->refreshToken($token, force: true);
                $ok++;
                if ($eraRevogado && $fresh->status === 'active') {
                    $reativados++;
                    $this->line("  ✓ {$rotulo} — reativado");
                } else {
                    $this->line("  ✓ {$rotulo}");
                }
            } catch (\Throwable $e) {
                // O service já gravou last_error e logou; aqui só contabiliza.
                $falhas++;
                $this->warn("  ✗ {$rotulo}: {$e->getMessage()}");
            }

            usleep(300000); // ~0,3s entre chamadas — respeita rate limit
        }

        $resumo = "[Shopee] refresh-tokens concluído — renovados: {$ok}, reativados: {$reativados}, falhas: {$falhas}";
        $falhas > 0 ? Log::error($resumo) : Log::info($resumo);
        $this->info($resumo);

        return self::SUCCESS;
    }
}
