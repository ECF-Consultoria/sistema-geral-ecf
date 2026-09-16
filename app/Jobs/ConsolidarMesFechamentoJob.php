<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Quick 260916-ejt — tira a reconsolidação de uma competência de dentro da
 * requisição web.
 *
 * O defeito medido em produção em 16/09/2026: o clique em "Refazer
 * fechamento" rodava `Artisan::call('fechamento:consolidar-mes')` dentro do
 * request. A consolidação busca faturamento de ~200 empresas na Adman e o PHP
 * do site tem `memory_limit = 512M` — estourava com
 * `Allowed memory size of 536870912 bytes exhausted` em
 * `Http/Client/Response.php`, devolvia 500, NADA era gravado e o usuário
 * seguia vendo os números antigos achando que o cálculo estava errado. Na
 * linha de comando não há limite de memória, e é por isso que o mesmo mês
 * refazia sem drama pelo terminal.
 *
 * ⚠️ Este job NÃO reimplementa a consolidação: chama exatamente o MESMO
 * comando que o cron e o terminal chamam. Ele só muda ONDE o cálculo roda
 * (worker, sem limite de memória de request), nunca O QUE ele calcula.
 *
 * Andamento em cache no mesmo formato de `SyncCompanyAdgroupMlbsJob`
 * (`status` / `started_at` / `completed_at` / `error`), lido pela tela via
 * `admin.financeiro.competencia.refazer.status`.
 *
 * @see app/Http/Controllers/FechamentoController.php::refazerCompetencia()
 * @see app/Console/Commands/ConsolidarMesFechamento.php
 */
class ConsolidarMesFechamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * UMA tentativa, de propósito. Refazer duas vezes sozinho é pior do que
     * falhar: cada execução regrava a competência inteira e escreve mais uma
     * linha na trilha de auditoria (`fechamento_reconsolidacoes`). Quem
     * decide tentar de novo é a pessoa, olhando o motivo da falha.
     */
    public int $tries = 1;

    /** 30min — ~200 empresas vezes as chamadas de faturamento na Adman. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $mes,
        public readonly string $motivo,
        public readonly ?int $porUserId = null,
    ) {}

    public function handle(): void
    {
        $chave = self::statusCacheKeyFor($this->mes);

        $iniciadoEm = now()->toIso8601String();

        Cache::put($chave, [
            'status'       => 'running',
            'mes'          => $this->mes,
            'started_at'   => $iniciadoEm,
            'completed_at' => null,
            'error'        => null,
        ], self::ttlDoAndamento());

        Log::info("[Fechamento] Refazendo o fechamento de {$this->mes} na fila (por user {$this->porUserId}).");

        $exitCode = Artisan::call('fechamento:consolidar-mes', [
            '--mes'    => $this->mes,
            '--motivo' => $this->motivo,
            '--por'    => $this->porUserId,
        ]);

        if ($exitCode !== 0) {
            $saida = Artisan::output();

            // Texto inteiro só no log; no cache vai a versão curta, que a tela
            // mostra para a pessoa.
            Log::error("[Fechamento] Falha ao refazer a competência {$this->mes} (exit {$exitCode}).", [
                'saida' => $saida,
            ]);

            Cache::put($chave, [
                'status'       => 'failed',
                'mes'          => $this->mes,
                'started_at'   => $iniciadoEm,
                'completed_at' => now()->toIso8601String(),
                'error'        => self::resumirSaida($saida) ?? "O cálculo terminou com erro (código {$exitCode}).",
            ], self::ttlDoAndamento());

            return;
        }

        Cache::put($chave, [
            'status'       => 'ready',
            'mes'          => $this->mes,
            'started_at'   => $iniciadoEm,
            'completed_at' => now()->toIso8601String(),
            'error'        => null,
        ], self::ttlDoAndamento());

        Log::info("[Fechamento] Fechamento de {$this->mes} refeito com sucesso pela fila.");
    }

    /**
     * Sem isto a falha some: o andamento ficaria eternamente em `running` e a
     * tela seguiria girando sem nunca dizer o que houve.
     */
    public function failed(\Throwable $e): void
    {
        Cache::put(self::statusCacheKeyFor($this->mes), [
            'status'       => 'failed',
            'mes'          => $this->mes,
            'started_at'   => null,
            'completed_at' => now()->toIso8601String(),
            'error'        => $e->getMessage(),
        ], self::ttlDoAndamento());

        Log::error("[Fechamento] Falha definitiva ao refazer a competência {$this->mes}: {$e->getMessage()}", [
            'exception' => $e,
        ]);
    }

    /**
     * Chave do andamento. Uma por mês — é ela que também serve de trava
     * anti-duplo-disparo no controller (dois cliques simultâneos regravariam
     * a mesma competência em paralelo).
     */
    public static function statusCacheKeyFor(string $mes): string
    {
        return "fechamento:refazer:{$mes}";
    }

    /**
     * Folgado de propósito: o andamento precisa sobreviver à consolidação
     * inteira (até 30min) e ainda ficar legível por um bom tempo depois, para
     * quem só voltou à tela mais tarde.
     */
    public static function ttlDoAndamento(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * A saída do comando tem dezenas de linhas de progresso; a tela só
     * precisa do fim dela, que é onde a mensagem de erro aparece.
     */
    private static function resumirSaida(?string $saida): ?string
    {
        $limpo = trim((string) $saida);

        if ($limpo === '') {
            return null;
        }

        $linhas = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $limpo) ?: []),
            fn (string $linha) => $linha !== '',
        ));

        $ultimas = implode(' ', array_slice($linhas, -3));

        return mb_substr($ultimas, 0, 500);
    }
}
