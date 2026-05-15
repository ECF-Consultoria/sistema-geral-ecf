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

class SyncAdmanCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly Company $company,
        public readonly ?string $date = null,
    ) {}

    // Backoff exponencial: 1min, 5min, 15min
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AdmanService $adman): void
    {
        $adman->syncCompany($this->company, $this->date);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncAdmanCompanyJob] Falha definitiva empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");
    }
}
