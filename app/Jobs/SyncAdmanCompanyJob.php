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

    /**
     * Quick 260930-njd — `false` sincroniza SÓ as métricas do dia, sem
     * `syncCampaigns()`. Usado pela releitura dos últimos dias
     * (`adman:reler-dias`), onde as campanhas custariam `1 + N` chamadas extras
     * por dia por empresa e não têm nada a ver com o que a releitura conserta
     * (o faturamento revisado pela Adman).
     *
     * ⚠️ Propriedade DECLARADA com default, não parâmetro promovido: um job já
     * enfileirado antes do deploy foi serializado sem esta chave, e o
     * `unserialize` inicializa as propriedades com o default da classe antes de
     * aplicar o que veio do payload. Promovida (sem default possível) ela ficaria
     * "não inicializada" e o job antigo estouraria ao ser processado.
     */
    public bool $incluirCampanhas = true;

    public function __construct(
        public readonly Company $company,
        public readonly ?string $date = null,
        bool $incluirCampanhas = true,
    ) {
        $this->incluirCampanhas = $incluirCampanhas;
    }

    // Backoff exponencial: 1min, 5min, 15min
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AdmanService $adman): void
    {
        $adman->syncCompany($this->company, $this->date, $this->incluirCampanhas);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncAdmanCompanyJob] Falha definitiva empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");
    }
}
