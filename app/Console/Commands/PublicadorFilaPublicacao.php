<?php

namespace App\Console\Commands;

use App\Services\Publicador\Fila\AgendadorDaFila;
use Illuminate\Console\Command;

/**
 * Anda as filas de publicação em lote do Publicador (10/10/2026, learnings publicador-ml §20). Agendado todo
 * minuto em `routes/console.php` (`withoutOverlapping(10)->onOneServer()`): fecha o produto que terminou de
 * publicar, respeita as rodadas, o intervalo e a janela de cada fila e começa os próximos — no máximo
 * `publicador.fila_publicacao.teto_inicios_por_minuto` por minuto, somando todas as filas.
 *
 * Rodar à mão é seguro (é o mesmo passo do agendador): sem fila ativa, não faz nada. Nada aqui fala com o
 * Mercado Livre; a publicação em si roda no `PublicarRascunhoJob` (fila `high`).
 */
class PublicadorFilaPublicacao extends Command
{
    protected $signature = 'publicador:fila-publicacao';

    protected $description = 'Anda as filas de publicação em lote do Publicador (em rodadas, com intervalo entre elas)';

    public function handle(AgendadorDaFila $agendador): int
    {
        $r = $agendador->rodar();
        if (array_sum($r) > 0) {
            $this->info("Fila de publicação: {$r['iniciados']} começado(s), {$r['fechados']} fechado(s), {$r['revisar']} para revisar, {$r['pausadas']} fila(s) pausada(s), {$r['concluidas']} concluída(s).");
        }

        return self::SUCCESS;
    }
}
