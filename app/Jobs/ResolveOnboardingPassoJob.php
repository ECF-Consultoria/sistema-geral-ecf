<?php

namespace App\Jobs;

use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ResolveOnboardingPassoJob — único lugar de onde um resolver automático que
 * toca rede (Adman/Mercado Livre — Plano 06) pode rodar.
 *
 * `fetchPerformance()`/`fetchUserInfo()` têm `timeout(120)` e retentam com
 * backoff — rodar isso dentro de uma request derruba o painel com 504
 * (Pitfall 2 do RESEARCH, já proibido pelo CLAUDE.md: "Long external API
 * calls (Adman) must go through Jobs to avoid nginx/php-fpm timeout").
 *
 * `ShouldBeUnique` por `id` do passo evita empilhar sondas do mesmo passo
 * enquanto uma já está em voo — mesmo molde de
 * {@see \App\Jobs\SyncMlAcervoCompanyJob}.
 */
class ResolveOnboardingPassoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly OnboardingPasso $passo)
    {
    }

    /** Chave de unicidade por passo — evita duas sondas do mesmo passo em voo. */
    public function uniqueId(): string
    {
        return (string) $this->passo->id;
    }

    public function uniqueFor(): int
    {
        return 1800;
    }

    /** Backoff exponencial: 1min, 5min, 15min. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(OnboardingResolverFactory $factory, OnboardingEngineService $engine): void
    {
        // Recarrega do banco — o job pode ter ficado um tempo na fila e o
        // passo pode ter sido concluído manualmente ou reavaliado enquanto
        // aguardava.
        $passo = $this->passo->fresh();

        if ($passo === null) {
            return;
        }

        if (in_array($passo->status, [OnboardingPasso::STATUS_CONCLUIDO, OnboardingPasso::STATUS_NAO_APLICAVEL], true)) {
            return;
        }

        $templatePasso = $passo->templatePasso;

        // Defesa contra despacho errado — passo sem auto_fonte não tem
        // resolver a chamar.
        if ($templatePasso === null || $templatePasso->auto_fonte === null) {
            return;
        }

        $onboarding = $passo->onboarding;

        // D-05 — rascunho não corre SLA nem consome quota da Adman.
        if ($onboarding->status === Onboarding::STATUS_RASCUNHO) {
            return;
        }

        $resultado = $factory->for($templatePasso->auto_fonte)->resolver($onboarding, $passo);

        $engine->aplicarResultado($passo, $resultado);

        Log::info(
            "[Onboarding] passo {$passo->id} ({$passo->chave}) resolvido via {$templatePasso->auto_fonte} "
            . "— estado {$resultado->estado}."
        );
    }

    /**
     * Falha definitiva do job — nunca pode deixar o passo em `aberto`
     * fingindo que ninguém tentou (T-135-06-05). Marca `indeterminado` com
     * o erro, na mesma disciplina de `aplicarResultado()`.
     */
    public function failed(\Throwable $e): void
    {
        Log::error("[ResolveOnboardingPassoJob] Falha definitiva passo {$this->passo->id}: {$e->getMessage()}");

        $passo = $this->passo->fresh();

        if ($passo === null) {
            return;
        }

        $passo->status = OnboardingPasso::STATUS_INDETERMINADO;
        $passo->tentativas = $passo->tentativas + 1;
        $passo->ultimo_erro = $e->getMessage();
        $passo->save();
    }
}
