<?php

namespace App\Services\Bling;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fase 184 (BLING-14) — limitador de ritmo das chamadas à API do Bling.
 *
 * Existem DUAS cotas diferentes, e confundi-las é o erro que derruba produção
 * (RESEARCH §2.2, seed §6 "Limites"):
 *
 * | Gatilho | Efeito |
 * |---|---|
 * | 3 req/s · 120.000 req/dia por conta do cliente | cota compartilhada com as outras integrações dele — só o plano Elite segrega |
 * | 600 req em 10 s | IP da VPS bloqueado 10 min |
 * | 300 erros em 10 s | IP da VPS bloqueado 10 min |
 * | Reincidência | duração indeterminada |
 *
 * **Não há headers de limite na resposta do Bling: o ritmo é controle nosso.**
 * **Todos os clientes saem do mesmo IP da VPS** — um bloqueio derruba todos de uma vez.
 *
 * Por isso há DOIS baldes de `RateLimiter`, mais um terceiro de erros:
 * - `bling-conta-{$contaId}`: por CONTA do cliente — essa cota é dele, dividida com as outras
 *   integrações que ele já tem rodando (hub, outros apps);
 * - `bling-ip`: GLOBAL, por IP de saída — é este balde que prova que a classe não é só o balde
 *   por conta renomeado (Teste 4 do plano, T-184-31);
 * - `bling-erros`: alimentado por `registrarErro()` em todo caminho de erro do `BlingClient`,
 *   contra os 300 erros em 10 s que também bloqueiam o IP.
 */
class LimitadorDoBling
{
    /**
     * O Bling permite 3 req/s, mas a cota é DO CLIENTE e dividida com as outras integrações
     * dele. 1 req/s é o ritmo medido no seed para carga em massa (~8,5 min para 500 produtos,
     * RESEARCH §2.2) e deixa 2/3 da cota livre para o resto do ecossistema dele — não é nosso
     * lugar de consumir tudo que ele permite.
     */
    public const POR_CONTA_POR_SEGUNDO = 1;

    /**
     * Contra os 600 req em 10 s (equivalente a 60/s) que bloqueiam o IP por 10 min, para TODOS
     * os clientes. 5/s é um doze avos do teto, com folga para rodadas paralelas de empresas
     * diferentes acontecendo ao mesmo tempo.
     */
    public const GLOBAL_POR_SEGUNDO = 5;

    /** Contra os 300 erros em 10 s que também bloqueiam o IP por 10 min. */
    public const ERROS_POR_10S = 20;

    /**
     * Teto por pausa — para não prender um worker indefinidamente. Esgotando este teto,
     * `aguardarVez()` lança `\RuntimeException` em vez de esperar: melhor falhar devagar do
     * que bater no Bling e tomar o bloqueio de IP.
     */
    public const ESPERA_MAXIMA_MS = 5000;

    /** Pausa extra somada quando o balde de erros está cheio (RateLimiter de 'bling-erros'). */
    private const PAUSA_EXTRA_ERROS_MS = 1000;

    /** A partir daqui a pausa é considerada "longa" e vale um log de aviso. */
    private const LIMIAR_LOG_MS = 500;

    /**
     * Espera a vez da conta `$contaId`, respeitando o balde por conta, o balde global por IP e
     * a pausa extra do balde de erros. Devolve `true` quando libera; nunca devolve `false` — ou
     * passa, ou lança exceção.
     *
     * @throws \RuntimeException quando a espera necessária passaria do teto `ESPERA_MAXIMA_MS`
     */
    public function aguardarVez(string $contaId): bool
    {
        $chaveConta = 'bling-conta-'.$contaId;

        $esperaContaMs = RateLimiter::tooManyAttempts($chaveConta, self::POR_CONTA_POR_SEGUNDO)
            ? RateLimiter::availableIn($chaveConta) * 1000
            : 0;

        $esperaGlobalMs = RateLimiter::tooManyAttempts('bling-ip', self::GLOBAL_POR_SEGUNDO)
            ? RateLimiter::availableIn('bling-ip') * 1000
            : 0;

        // Os dois baldes de ritmo esperam JUNTOS (o maior dos dois cobre o outro); o balde de
        // erros soma uma pausa EXTRA por cima, porque é um sinal de problema, não de ritmo normal.
        $esperaRitmoMs = max($esperaContaMs, $esperaGlobalMs);

        $pausaErrosMs = RateLimiter::tooManyAttempts('bling-erros', self::ERROS_POR_10S)
            ? self::PAUSA_EXTRA_ERROS_MS
            : 0;

        $esperaTotalMs = $esperaRitmoMs + $pausaErrosMs;

        if ($esperaTotalMs > self::ESPERA_MAXIMA_MS) {
            Log::warning("[Bling] Limite de espera esgotado para a conta {$contaId}: precisaria de {$esperaTotalMs}ms, acima do teto de ".self::ESPERA_MAXIMA_MS.'ms — falhando em vez de bater no Bling e arriscar o bloqueio de IP.');

            throw new \RuntimeException(
                "[Bling] Limite de requisições esgotado para a conta {$contaId} — faltariam {$esperaTotalMs}ms de espera, acima do teto permitido. Tente novamente mais tarde."
            );
        }

        if ($esperaTotalMs > 0) {
            if ($esperaTotalMs >= self::LIMIAR_LOG_MS) {
                Log::warning("[Bling] Aguardando {$esperaTotalMs}ms antes da próxima chamada (conta {$contaId}).");
            }

            $this->esperar($esperaTotalMs);
        }

        RateLimiter::hit($chaveConta, 1);
        RateLimiter::hit('bling-ip', 1);

        return true;
    }

    /**
     * Alimenta o balde de erros — chamado pelo `BlingClient` em todo caminho de erro (exceto o
     * 403 `insufficient_scope`, que é um problema de cadastro do app, não de ritmo).
     */
    public function registrarErro(): void
    {
        RateLimiter::hit('bling-erros', 10);
    }

    /**
     * Pausa bloqueante encapsulada aqui dentro — `protected` para que o teste troque esta única
     * função por um espião e prove a espera sem deixar a suíte lenta de verdade.
     */
    protected function esperar(int $ms): void
    {
        usleep($ms * 1000);
    }
}
