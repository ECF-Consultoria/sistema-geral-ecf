<?php

namespace Tests\Feature\Phase119_1;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Fase 119.1 Plan 01 — Prova de que o agendamento diário do disparo
 * automático de NPS saiu do schedule (D2), mas a rotina da Fase 116
 * (`nps-materializar-nao-respondidos`) continua intacta, e o comando
 * `nps:disparar-mensal` continua registrado e invocável manualmente.
 *
 * Comentários e nomes de teste em pt-BR, conforme convenção do projeto.
 *
 * @see routes/console.php
 * @see app/Console/Commands/NpsDispararMensal.php
 */
class NpsAgendamentoRemovidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_nao_contem_mais_o_evento_nps_disparar_mensal(): void
    {
        $schedule = app(Schedule::class);

        $encontrado = collect($schedule->events())
            ->contains(fn ($event) => str_contains((string) $event->getSummaryForDisplay(), 'nps-disparar-mensal'));

        $this->assertFalse($encontrado, 'O schedule NÃO deve mais conter o evento nps-disparar-mensal (D2).');
    }

    public function test_schedule_ainda_contem_o_evento_nps_materializar_nao_respondidos(): void
    {
        $schedule = app(Schedule::class);

        $encontrado = collect($schedule->events())
            ->contains(fn ($event) => str_contains((string) $event->getSummaryForDisplay(), 'nps-materializar-nao-respondidos'));

        $this->assertTrue($encontrado, 'A rotina da Fase 116 (materializar não respondidos) deve continuar no schedule.');
    }

    public function test_comando_nps_disparar_mensal_continua_registrado_e_invocavel(): void
    {
        $this->assertArrayHasKey(
            'nps:disparar-mensal',
            Artisan::all(),
            'O comando continua existindo para uso manual em massa (D2).'
        );

        $exitCode = Artisan::call('nps:disparar-mensal', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, 'O comando deve continuar invocável e retornar sucesso em dry-run.');
    }
}
