<?php

namespace App\Jobs;

use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMlCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
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

    public function handle(MercadoLivreService $ml): void
    {
        $date = $this->date ?? now()->toDateString();
        $ml->syncCompany($this->company, $date);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncMlCompanyJob] Falha definitiva empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");

        AdmanSyncLog::create([
            'company_id'    => $this->company->id,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'error_message' => '[ML] ' . $e->getMessage(),
            'synced_at'     => now(),
        ]);
    }
}
