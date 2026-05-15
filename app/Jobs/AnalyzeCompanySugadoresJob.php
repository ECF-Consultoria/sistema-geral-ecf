<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\SugadorAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Roda a análise de sugadores de UMA empresa em background.
 * Disparado pelo SugadorController quando o usuário clica em "Rodar análise"
 * — assíncrono porque a Adman pode demorar vários minutos para contas grandes
 * (CAMILLO tem 2389 adgroups ≈ 48 páginas + retry em 429), o que estoura
 * o timeout do nginx/php-fpm.
 */
class AnalyzeCompanySugadoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** 15 min — Adman paginada pode chegar perto disso pra contas com 5k+ adgroups */
    public int $timeout = 900;

    public function __construct(
        public readonly Company $company,
    ) {}

    /** Backoff: 2min e 10min. Erro definitivo após 2 tentativas. */
    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(SugadorAnalysisService $service): void
    {
        $r = $service->analyzeCompany($this->company);

        Log::info(sprintf(
            '[Sugadores] Empresa %d (%s): %s',
            $this->company->id,
            $this->company->name,
            $r['skipped']
                ? "pulada ({$r['reason']})"
                : "{$r['adgroups']} adgroup(s) + {$r['campanhas']} campanha(s) flagados"
        ));
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[AnalyzeCompanySugadoresJob] Falha definitiva empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");
    }
}
