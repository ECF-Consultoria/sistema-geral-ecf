<?php

namespace App\Console\Commands;

use App\Jobs\ResolveOnboardingPassoJob;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * onboarding:reavaliar-passos — passada periódica que reavalia os passos
 * automáticos que ficaram esperando (Fase 135, Plano 07).
 *
 * Existe por causa do Pitfall 3 desta fase: o resolver do passo 8
 * ({@see \App\Services\Onboarding\Resolvers\AcervoColetadoResolver}) DISPARA
 * a coleta e devolve o controle — o job de até 30 minutos
 * (`SyncMlAcervoCompanyJob::$timeout=1800`) nunca é esperado dentro de um
 * ciclo. Sem uma passada que volte depois, um passo `aguardando_coleta`
 * ficaria preso ali para sempre — a coleta terminou, mas ninguém voltou para
 * reconferir a tabela. Este comando é esse "alguém que volta depois".
 *
 * Escopo: passos com `auto_fonte` preenchido (só resolver automático fecha
 * — D-19) de onboardings em `andamento` (D-05: rascunho não corre SLA nem
 * consome quota da Adman) cujo status esteja em `aguardando_coleta`,
 * `indeterminado` ou `aberto`. Passo `aberto` entra no escopo porque um
 * resolver automático pode nunca ter sido tentado (onboarding recém-
 * destravado entre uma passada e outra do scheduler).
 *
 * Resolver **síncrono** (leitura local, sem rede — passos 3 e 5) é resolvido
 * INLINE, com `aplicarResultado()` chamado no mesmo request do comando.
 * Resolver **assíncrono** (passos 4, 7 e 8 — toca Adman/Mercado Livre) NUNCA
 * roda aqui: só é despachado via {@see ResolveOnboardingPassoJob}, o único
 * lugar autorizado a chamar rede para um resolver automático (Pitfall 2,
 * Plano 06). Este comando não importa `AdmanService` nem `MercadoLivreService`
 * — a classificação síncrono/assíncrono vem do próprio resolver
 * (`assincrono()`), lida via o mesmo catálogo fechado que os outros
 * consumidores usam.
 *
 * Molde de comando batch: {@see \App\Console\Commands\WarmDesempenhoCache} —
 * `try/catch (\Throwable)` POR ITEM (uma falha não derruba o lote) e opções
 * de escopo parcial (`--onboarding`, `--limite`).
 */
class OnboardingReavaliarPassos extends Command
{
    protected $signature = 'onboarding:reavaliar-passos
        {--onboarding= : Reavalia só este onboarding (debug/sob-demanda)}
        {--limite=200  : Máximo de passos processados nesta passada}';

    protected $description = 'Reavalia passos automáticos pendentes (aguardando_coleta/indeterminado/aberto) de onboardings em andamento';

    public function handle(OnboardingResolverFactory $factory, OnboardingEngineService $engine): int
    {
        $limite = max(1, (int) $this->option('limite'));
        $onboardingId = $this->option('onboarding');

        $passos = OnboardingPasso::query()
            ->whereHas('onboarding', function ($query) use ($onboardingId) {
                $query->where('status', Onboarding::STATUS_ANDAMENTO);

                if ($onboardingId !== null) {
                    $query->where('id', $onboardingId);
                }
            })
            ->whereNotNull('auto_fonte')
            ->whereIn('status', [
                OnboardingPasso::STATUS_AGUARDANDO_COLETA,
                OnboardingPasso::STATUS_INDETERMINADO,
                OnboardingPasso::STATUS_ABERTO,
            ])
            ->with('onboarding')
            ->oldest('updated_at')
            ->limit($limite)
            ->get();

        $this->info("[Onboarding] reavaliação periódica — {$passos->count()} passo(s) selecionado(s).");

        $concluidos = 0;
        $aguardandoColeta = 0;
        $indeterminados = 0;
        $despachados = 0;
        $falhas = 0;
        $onboardingsTocados = [];

        foreach ($passos as $passo) {
            try {
                $onboarding = $passo->onboarding;

                if ($onboarding === null || $passo->auto_fonte === null) {
                    // Defesa contra despacho errado — mesma disciplina do
                    // ResolveOnboardingPassoJob.
                    continue;
                }

                $resolver = $factory->for($passo->auto_fonte);

                if ($resolver->assincrono()) {
                    // Nunca chama Adman/Mercado Livre inline — só despacha.
                    ResolveOnboardingPassoJob::dispatch($passo);
                    $despachados++;
                } else {
                    $resultado = $resolver->resolver($onboarding, $passo);
                    $engine->aplicarResultado($passo, $resultado);

                    match ($passo->fresh()->status) {
                        OnboardingPasso::STATUS_CONCLUIDO => $concluidos++,
                        OnboardingPasso::STATUS_AGUARDANDO_COLETA => $aguardandoColeta++,
                        OnboardingPasso::STATUS_INDETERMINADO => $indeterminados++,
                        default => null,
                    };
                }

                $onboardingsTocados[$onboarding->id] = $onboarding;
            } catch (\Throwable $e) {
                $falhas++;

                Log::error(
                    "[Onboarding] falha na reavaliação do passo {$passo->id} ({$passo->chave}): {$e->getMessage()}"
                );
            }
        }

        // Ao fim de cada onboarding tocado, propaga destravamentos e fecha o
        // onboarding se todos os passos obrigatórios já estiverem resolvidos.
        foreach ($onboardingsTocados as $onboarding) {
            $engine->reavaliar($onboarding);
        }

        $this->info(
            "[Onboarding] reavaliação concluída — concluídos={$concluidos}, "
            . "aguardando coleta={$aguardandoColeta}, indeterminados={$indeterminados}, "
            . "despachados={$despachados}, falhas={$falhas}."
        );

        return self::SUCCESS;
    }
}
