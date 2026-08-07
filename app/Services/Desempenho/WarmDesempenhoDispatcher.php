<?php

namespace App\Services\Desempenho;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Gate quente/frio do desempenho + dispatch do warm sob-demanda.
 *
 * POR QUE EXISTE: `DesempenhoScoreService::compute()` faz HTTP SÍNCRONO à
 * Adman por empresa da carteira — medido em 110s para um profissional de 25
 * empresas num mês frio (2026-08-07, user 20, competência 2026-05). Toda tela
 * que chama `computeCached()` direto paga isso na cara do usuário quando o
 * cache está frio, e o navegador fica "carregando pra sempre" (o teto é o
 * `max_execution_time` de 300s do php-fpm).
 *
 * A Fase 106 resolveu isso no RANKING (`PerformanceController::index`), mas o
 * gate ficou inline lá e as telas de DETALHE nunca ganharam a proteção — foi
 * assim que `/admin/users/{id}/portfolio` e `/performance/{id}` continuaram
 * pendurando. Esta classe é aquela lógica extraída, para as três telas usarem
 * a MESMA regra em vez de três cópias divergentes.
 *
 * Contrato: quem chama NUNCA computa o frio. Se `estaFrio()`, a tela renderiza
 * placeholder `calculando` e o front faz poll; o cálculo real acontece no
 * worker, via `desempenho:warm-cache`.
 */
class WarmDesempenhoDispatcher
{
    /**
     * Lock de 3min por (mês) — evita empilhar um job a cada poll de 6s do
     * front. Só o 1º request dentro da janela adquire e dispara; os seguintes
     * veem o lock ocupado e não criam um 2º job. (Fase 106, T-106-03.)
     */
    private const LOCK_MINUTOS = 3;

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private CarteiraContextService $carteiraContext,
    ) {}

    /**
     * O score deste profissional/mês exige compute() síncrono agora?
     *
     * `false` quando já há cache quente — aí a tela pode ler à vontade.
     */
    public function estaFrio(User $user, Carbon $mes): bool
    {
        return ! $this->scoreService->isCached($user, $mes);
    }

    /**
     * Profissional SEM vínculo ativo nenhum não passa pelo gate.
     *
     * O gate existe para evitar o fan-out HTTP por empresa; carteira vazia não
     * tem fan-out — `compute()` corta em `computeUniverso` e devolve
     * `sem_carteira` na hora (DESEMP-10). Mandar essa tela para "calculando…"
     * seria mentir sobre o motivo de não haver nota E enfileirar um warm que
     * não tem trabalho a fazer.
     *
     * `forUser()` é a ÚNICA porta para resolver vínculos (nunca reimplementar o
     * join em `company_users.servico_id` — perde o ramo legado CTX-05).
     */
    private function temCarteira(User $user): bool
    {
        return $this->carteiraContext->forUser($user, ['active' => true])->isNotEmpty();
    }

    /**
     * Agenda o aquecimento em background para os IDs informados.
     *
     * Os IDs SEMPRE vêm de query interna do controller, nunca de input cru
     * (T-106-04) — o `--mes` já chega validado pelo resolvedor de período.
     *
     * @param  int[]  $userIds
     * @return bool  true se ESTE request disparou o job (false = já havia um em voo)
     */
    public function agendarWarm(array $userIds, Carbon $mes): bool
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if ($ids === []) {
            return false;
        }

        $lockKey = 'desempenho.warm.lock.' . $mes->format('Y-m');
        if (! Cache::add($lockKey, true, now()->addMinutes(self::LOCK_MINUTOS))) {
            return false;
        }

        Artisan::queue('desempenho:warm-cache', [
            '--mes'  => $mes->format('Y-m'),
            '--user' => $ids,
        ]);

        return true;
    }

    /**
     * Conveniência para as telas de UM profissional (carteira e desempenho
     * individual): decide o gate e já agenda o warm quando frio.
     *
     * @return bool  true quando a tela deve renderizar em modo "calculando"
     */
    public function gateIndividual(User $user, Carbon $mes): bool
    {
        if (! $this->estaFrio($user, $mes)) {
            return false;
        }

        if (! $this->temCarteira($user)) {
            return false;
        }

        $this->agendarWarm([$user->id], $mes);

        return true;
    }
}
