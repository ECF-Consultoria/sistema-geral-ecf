<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\SugadorAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Roda a análise de sugadores de UMA empresa em background.
 * Disparado pelo SugadorController quando o usuário clica em "Rodar análise"
 * — assíncrono porque a Adman pode demorar vários minutos para contas grandes
 * (CAMILLO tem 2389 adgroups ≈ 48 páginas + retry em 429), o que estoura
 * o timeout do nginx/php-fpm.
 *
 * `ShouldBeUnique` evita o duplicate UUID em failed_jobs quando os 2 workers
 * (ecf-worker_00/01) competem pelo mesmo job da mesma empresa — agora a chave
 * é o company_id e o segundo worker simplesmente não pega o job duplicado.
 */
class AnalyzeCompanySugadoresJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 4 tentativas: o Adman rate-limit é janela de ~1h. backoff sobe pra dar
     * a chance da janela resetar antes do final. Sem isso (com tries=2 +
     * backoff [120,600]), contas grandes batiam 429 nas 2 tentativas e iam
     * direto pra failed_jobs (visto em logs prod 2026-05-21).
     */
    public int $tries = 4;

    /** 15 min — Adman paginada pode chegar perto disso pra contas com 5k+ adgroups */
    public int $timeout = 900;

    public function __construct(
        public readonly Company $company,
    ) {}

    /**
     * Backoff escalonado: 3min, 15min, 30min, 60min.
     * O retry final (~1h depois da 1ª tentativa) cai numa janela nova de
     * rate-limit da Adman.
     */
    public function backoff(): array
    {
        return [180, 900, 1800, 3600];
    }

    /**
     * Chave de unicidade: company_id. Garante que se a fila já tem 1 job
     * pendente pra empresa X, um segundo dispatch (cron + click manual,
     * por exemplo) não enfileira duplicata.
     */
    public function uniqueId(): string
    {
        return (string) $this->company->id;
    }

    /**
     * Phase 30 D-01 — Aplica throttle global Adman 'adman-api' (8/min).
     * Bucket compartilhado entre todos os workers via cache Redis. Quando o
     * limite estoura, Laravel reagenda o Job em delayed sem marcar falha.
     */
    public function middleware(): array
    {
        return [new RateLimited('adman-api')];
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
