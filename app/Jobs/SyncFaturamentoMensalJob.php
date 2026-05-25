<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncFaturamentoMensalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly Company $company,
        public readonly string $yearMonth,
    ) {}

    public function handle(AdmanService $adman): void
    {
        $adman->syncMonthRevenue($this->company, $this->yearMonth);
        Log::info("[Faturamento] Sincronizado empresa {$this->company->id} ({$this->company->name}) mês {$this->yearMonth}.");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncFaturamentoMensalJob] Falha empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");
    }
}
