<?php

namespace App\Services\Desempenho;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\AdmanMetricDiffService;
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

    /**
     * Quantas empresas frias a TABELA tolera antes de virar "calculando…".
     *
     * O gate não pode ser "qualquer uma fria": medido em produção 2026-08-07,
     * o warm converge em ondas (25 → 9 → 1 de 25), e com 1 sozinha fria o
     * gate esconderia a tabela inteira quando 24/25 dos dados já estão
     * prontos e faltariam ~6s para completar. Pior: o `ERROR_SENTINEL` do
     * `AdmanMetricDiffService` expira em 10min, então UMA empresa que erre
     * cronicamente faria a tabela piscar entre normal e "calculando" para
     * sempre — a tela nunca renderizaria de novo.
     *
     * 3 × ~6,3s/empresa (157,6s ÷ 25 medidos) ≈ 19s de pior caso síncrono:
     * ruim, mas finito e MUITO abaixo dos 300s do `max_execution_time`, e
     * infinitamente melhor que uma tabela que não volta.
     */
    private const TOLERANCIA_EMPRESAS_FRIAS = 3;

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private CarteiraContextService $carteiraContext,
        private AdmanMetricDiffService $admanDiff,
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
     *
     * Público desde 2026-08-24: `PerformanceController::show()` decide por ele
     * se a seção "Empresas da carteira" aquece ou anuncia carteira vazia. A
     * alternativa era ler `empresas_carteira` do payload — que existe no
     * `breakdown_json` de produção mas não é garantido em todo snapshot, e
     * dependeria de uma chave em vez da régua canônica de vínculos.
     */
    public function temCarteira(User $user): bool
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

        return $this->enfileirar($ids, $mes);
    }

    /**
     * Aquecimento do DETALHE POR EMPRESA de UM profissional (2026-08-24).
     *
     * Existe separado de `agendarWarm()` por causa do lock: aquele é por MÊS
     * (global), porque nasceu para o RANKING, onde um job cobre a lista
     * inteira de uma vez. Aqui a tela é de um profissional só, e dois
     * profissionais abertos em sequência caem dentro da mesma janela de 3min
     * — com o lock por mês, o segundo ficaria em "calculando…" para sempre,
     * sem job nenhum em voo. O lock é por (user, competência).
     *
     * @return bool  true se ESTE request disparou o job
     */
    public function agendarWarmDetalheEmpresas(User $user, Carbon $mes): bool
    {
        if (! $this->temCarteira($user)) {
            return false;
        }

        $lockKey = 'desempenho.warm.empresas.lock.' . $user->id . '.' . $mes->format('Y-m');
        if (! Cache::add($lockKey, true, now()->addMinutes(self::LOCK_MINUTOS))) {
            return false;
        }

        return $this->enfileirar([$user->id], $mes);
    }

    /**
     * Dispatch propriamente dito — compartilhado pelos dois agendadores acima
     * para que a escolha de fila (e o motivo dela) exista num lugar só.
     *
     * @param  int[]  $ids
     */
    private function enfileirar(array $ids, Carbon $mes): bool
    {
        // Fila `high`, NUNCA a default. A default é onde vivem os syncs em
        // lote (acervo ML, vendas, grants): jobs de 2 a 5 minutos que chegam
        // às centenas. Em 2026-08-12 a default tinha 111 jobs de
        // `SyncMlAcervo*` e a tela de Desempenho ficava em "Calculando…"
        // indefinidamente — o warm entrava no fim de uma fila de horas.
        //
        // Este job é INTERATIVO: existe porque um humano está olhando a tela
        // esperando. Não pode competir com trabalho de fundo. A `high` tem
        // worker dedicado (`ecf-worker-high`) e ainda é priorizada pelos dois
        // `ecf-worker`, que consomem `high,default` nessa ordem.
        // `?->` de propósito: este caminho roda durante a RENDERIZAÇÃO da
        // página. Se `queue()` devolvesse null (mock, decorator, versão
        // futura), um fatal aqui derrubaria a tela inteira — com o nullsafe o
        // pior caso é o job voltar à fila default, que é o comportamento
        // anterior, não uma página quebrada.
        Artisan::queue('desempenho:warm-cache', [
            '--mes'  => $mes->format('Y-m'),
            '--user' => $ids,
        ])?->onQueue('high');

        return true;
    }

    /**
     * Conveniência para as telas de UM profissional (carteira e desempenho
     * individual): decide o gate e já agenda o warm quando frio.
     *
     * @return bool  true quando a tela deve renderizar em modo "calculando"
     */
    /**
     * Gate da TABELA da carteira — camada diferente da nota.
     *
     * A carteira tem DOIS fan-outs independentes: o da nota (gateIndividual
     * acima) e o dela própria, que chama `MetricDiffDispatcher::compute()` por
     * empresa para montar faturamento/margem/ADS. Blindar só o primeiro deixou
     * `/admin/users/{id}/portfolio` levando 115s em produção depois do deploy
     * de 2026-08-07 — foi a medição pós-deploy que expôs o segundo.
     *
     * Frio se QUALQUER empresa da lista estiver fria: basta uma para o laço
     * pagar HTTP síncrono, e a tabela é montada inteira ou nenhuma.
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Company>  $companies
     * @param  array  $periodo  shape do MetricPeriodResolver
     */
    public function gateCarteira($companies, array $periodo, Carbon $mes): bool
    {
        $frias = $companies->filter(
            fn ($c) => ! $this->admanDiff->isCached($c, $periodo)
        )->count();

        // Aquece SEMPRE que houver fria — inclusive abaixo do limite, para a
        // retardatária entrar no cache e a próxima visita já achar tudo pronto.
        // Aquecer e gatear são decisões separadas de propósito.
        if ($frias > 0) {
            $lockKey = 'adman.diff.warm.lock.' . $mes->format('Y-m');
            if (Cache::add($lockKey, true, now()->addMinutes(self::LOCK_MINUTOS))) {
                // `--period` aceita `YYYY-MM` (mesmo contrato do MetricPeriodResolver).
                // Fila `high` pelo mesmo motivo do warm da nota: é a tabela da
                // carteira de alguém que está esperando na tela.
                Artisan::queue('adman:warm-diff', ['--period' => $mes->format('Y-m')])
                    ?->onQueue('high');
            }
        }

        return $frias > self::TOLERANCIA_EMPRESAS_FRIAS;
    }

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
