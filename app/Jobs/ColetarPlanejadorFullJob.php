<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\PlanejadorFull\FullColetaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Coleta do Planejador FULL de UMA empresa (FullColetaService). Disparado pelo
 * agendamento diário (`full:coletar`) e pelo botão "Atualizar agora" da tela.
 *
 * A maior conta (WENUS) faz ~1.000 chamadas por coleta — alguns minutos. O timeout
 * fica abaixo do --timeout=1800 do worker e do retry_after=2000 da fila de produção
 * (ver project_polos_sync_job_retry_after_bug).
 */
class ColetarPlanejadorFullJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 1700;

    public function __construct(
        public readonly Company $company,
        public readonly string $origem = 'agendada',
    ) {}

    /** Uma coleta por empresa por vez — o clique repetido no botão não empilha. */
    public function uniqueId(): string
    {
        return (string) $this->company->id;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(FullColetaService $coleta): void
    {
        $coleta->coletar($this->company, $this->origem);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[PlanejadorFull] coleta falhou de vez — empresa {$this->company->id} ({$this->company->name}): " . mb_substr($e->getMessage(), 0, 500));
    }
}
