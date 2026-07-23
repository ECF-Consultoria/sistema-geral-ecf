<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Sync sob demanda do faturamento + Ads Shopee de UMA empresa, disparado pelo
 * botão "Sincronizar" da aba /shopee/empresas.
 *
 * Reusa os comandos já testados `shopee:sync` (faturamento/pedidos) e
 * `shopee:sync-ads` (performance de Ads, com clamp de 6 meses) — os mesmos que
 * o cron roda diariamente — só que escopados a uma empresa e a uma janela.
 *
 * Assíncrono (fila) porque o backfill de ~2 meses percorre dia a dia com pausa
 * de ~0,3s por chamada (respeita rate limit da Shopee): loja movimentada leva
 * alguns minutos — inviável numa request HTTP. Requer worker de fila ativo
 * (na VPS o Supervisor já roda; no localhost subir `php artisan queue:work`).
 *
 * Janela padrão: 1º dia do mês ANTERIOR → hoje (popula o mês corrente + o
 * anterior, alimentando o dashboard e o delta "vs mês anterior"). O sync-ads
 * corta sozinho datas fora do lookback de 6 meses.
 */
class SyncShopeeCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Loja movimentada × ~60 dias pagina muitos pedidos/Ads; 1200s dá folga.
    public int $timeout = 1200;

    /**
     * @param  int          $companyId  Empresa a sincronizar.
     * @param  string|null  $from       Início 'YYYY-MM-DD' (null = 1º dia do mês anterior).
     * @param  string|null  $to         Fim 'YYYY-MM-DD' (null = hoje).
     */
    public function __construct(
        public int $companyId,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function handle(): void
    {
        $from = $this->from ?? Carbon::now()->startOfMonth()->subMonth()->toDateString();
        $to   = $this->to   ?? Carbon::now()->toDateString();

        Log::info("[Shopee] Sync manual (job) empresa {$this->companyId} — {$from} → {$to}");

        // Faturamento (token ERP). O comando pula sozinho se não houver token ativo.
        Artisan::call('shopee:sync', [
            '--company' => $this->companyId,
            '--from'    => $from,
            '--to'      => $to,
        ]);
        Log::info("[Shopee] Sync manual (job) faturamento empresa {$this->companyId}: " . trim(Artisan::output()));

        // Ads (token ADS separado). O comando pula empresa sem token ADS e clampa
        // datas anteriores ao lookback de 6 meses.
        Artisan::call('shopee:sync-ads', [
            '--company' => $this->companyId,
            '--from'    => $from,
            '--to'      => $to,
        ]);
        Log::info("[Shopee] Sync manual (job) Ads empresa {$this->companyId}: " . trim(Artisan::output()));
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Shopee] Sync manual (job) FALHOU empresa {$this->companyId}: {$e->getMessage()}");
    }
}
