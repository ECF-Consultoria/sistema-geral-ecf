<?php

namespace Tests\Feature\Publicador;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A renovação da promoção automática pós-publicação (10/10/2026) roda todo dia às 00:05 de São Paulo:
 * o ciclo de 14 dias acaba às 23:59:59 do último dia, e o seguinte começa no dia de hoje.
 */
class PromocaoAutomaticaAgendaTest extends TestCase
{
    public function test_renovacao_agendada_uma_vez_as_00_05_de_sao_paulo_sem_sobrepor(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'publicador:promocoes-renovar'));

        $this->assertCount(1, $eventos, 'agendada exatamente uma vez');
        $e = $eventos->first();
        $this->assertSame('5 0 * * *', $e->expression);
        $this->assertSame('America/Sao_Paulo', $e->timezone);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame('publicador-promocoes-renovar', $e->description);
    }
}
