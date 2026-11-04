<?php

namespace App\Jobs\Trilha;

use App\Services\Publicador\Fila\AgendadorDaFila;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A passada da fila de publicação NA HORA, depois que a trilha agenda um anúncio (retorno do 1º teste, R5, 04/11/2026).
 *
 * Por que existe: no teste de 04/11 o item ficou 7 min 42 s na fila antes de começar — a fila só anda na passada de
 * minuto (`publicador:fila-publicacao`) e espera o editor do Publicador fechar. Este Job é a MESMA passada
 * (`AgendadorDaFila::rodar()`, exatamente o que o comando faz), disparada logo depois do `agendar` da trilha
 * ({@see \App\Services\Trilha\PublicacaoNaTrilha::executar()}). Nenhuma trava é pulada:
 * - as checagens do agendador valem todas (conta, conferência e revisão gravadas no item, avisos/"ciente", vendedor da
 *   conferência, bloqueios e preço do Portal, editor em uso → espera, rodadas, intervalo, janela, teto de inícios por
 *   minuto, autor ativo) — elas moram dentro do `rodar()`;
 * - a guarda da #459 (D48) já passou duas vezes no Job da trilha antes de agendar; aqui nada é agendado;
 * - a trava de SOBREPOSIÇÃO do agendamento de minuto (`withoutOverlapping`) é a MESMA: o Job pega o mutex do evento
 *   `publicador-fila-publicacao` antes de rodar e o solta no fim. Com a passada do minuto rodando agora, ele volta para a
 *   fila em {@see ESPERA_S} s (`release`); sem achar o evento agendado (mudou o nome?), não anda nada — a passada do
 *   minuto cuida, como antes.
 *
 * Roda TODAS as filas ativas, como o comando: é a passada do minuto, só que antes. Fila `high` (clique de pessoa, como o
 * `PublicarPelaTrilhaJob` que o dispara); a publicação em si continua no `PublicarRascunhoJob`, que o `iniciar()` despacha.
 */
class AndarFilaDePublicacaoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** O nome (`->name()`) do agendamento de minuto em `routes/console.php`. */
    public const EVENTO = 'publicador-fila-publicacao';

    /** Com a passada do minuto rodando agora: tenta de novo daqui a isto. */
    public const ESPERA_S = 10;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct()
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(AgendadorDaFila $agendador): void
    {
        $evento = self::eventoDoMinuto();
        if ($evento === null) {
            Log::warning('[Trilha] Fila de publicação: o agendamento "'.self::EVENTO.'" não foi achado; a passada fica para o minuto.');

            return;
        }
        if (! $evento->mutex->create($evento)) {
            // A passada do minuto está rodando: ela pode ter lido as filas antes do agendamento — tenta de novo logo.
            $this->release(self::ESPERA_S);

            return;
        }
        try {
            $r = $agendador->rodar();
            Log::info("[Trilha] Fila de publicação andou na hora: {$r['iniciados']} começado(s), {$r['revisar']} para revisar, {$r['pausadas']} fila(s) pausada(s).");
        } finally {
            $evento->mutex->forget($evento);
        }
    }

    /** O evento do agendamento de minuto — o dono do mutex de sobreposição. */
    public static function eventoDoMinuto(): ?Event
    {
        foreach (app(Schedule::class)->events() as $evento) {
            if ($evento->description === self::EVENTO) {
                return $evento;
            }
        }

        return null;
    }

    public function failed(\Throwable $e): void
    {
        // Sem passada na hora (a do minuto segue valendo): só registra.
        Log::error('[Trilha] Fila de publicação: a passada na hora não rodou: '.$e->getMessage());
    }
}
