<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\DiasUteis;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Prazo das tarefas pós-publicação (09/10/2026): D+1 útil pula fim de semana e feriado, no fuso de São Paulo. */
class DiasUteisTest extends TestCase
{
    private static function sp(string $quando): CarbonImmutable
    {
        return CarbonImmutable::parse($quando, 'America/Sao_Paulo');
    }

    private static function d1(string $quando): string
    {
        return DiasUteis::somar(self::sp($quando), 1)->format('Y-m-d');
    }

    public function test_sexta_antes_de_aparecida_vai_para_terca(): void
    {
        $this->assertSame('2026-10-13', self::d1('2026-10-09 15:00'), 'sábado, domingo e 12/10 no meio');
        $this->assertSame('2026-10-13', self::d1('2026-10-10 10:00'), 'publicado no sábado');
        $this->assertSame('2026-10-13', self::d1('2026-10-11 10:00'), 'publicado no domingo');
        $this->assertSame('2026-10-13', self::d1('2026-10-12 10:00'), 'publicado no próprio feriado');
    }

    public function test_dia_comum_vai_para_o_dia_seguinte(): void
    {
        $this->assertSame('2026-10-14', self::d1('2026-10-13 09:00'));
        $this->assertSame('2026-10-16', self::d1('2026-10-15 18:30'));
    }

    public function test_a_hora_vale_no_fuso_de_sao_paulo(): void
    {
        // 23h30 de sexta em São Paulo já é sábado em UTC: continua sendo publicação de sexta.
        $utc = CarbonImmutable::parse('2026-10-17 02:30:00', 'UTC');
        $this->assertSame('2026-10-19', DiasUteis::somar($utc, 1)->format('Y-m-d'), 'sexta 16/10 → segunda 19/10');
    }

    public function test_feriados_nacionais_fixos(): void
    {
        $this->assertSame('2026-11-23', self::d1('2026-11-19 10:00'), 'Consciência Negra (sexta 20/11) é nacional desde 2024');
        $this->assertSame('2026-11-03', self::d1('2026-10-30 10:00'), 'sexta → segunda 02/11 Finados → terça');
        $this->assertSame('2026-12-28', self::d1('2026-12-24 10:00'), 'Natal na sexta');
        $this->assertSame('2027-01-04', self::d1('2026-12-31 10:00'), 'Confraternização na sexta 01/01/2027');
        $this->assertFalse(DiasUteis::ehUtil(self::sp('2027-04-21 10:00')), 'Tiradentes');
    }

    public function test_datas_moveis_vem_da_config(): void
    {
        $this->assertSame('2026-02-18', self::d1('2026-02-13 10:00'), 'Carnaval 16 e 17/02/2026 estão na config');
        $this->assertSame('2026-04-06', self::d1('2026-04-02 10:00'), 'Sexta-feira Santa 03/04/2026');

        config(['publicador.feriados' => ['2026-10-14', 'lixo']]);
        $this->assertSame('2026-10-15', self::d1('2026-10-13 10:00'), 'feriado acrescentado na config pula');
        $this->assertSame('2026-02-16', self::d1('2026-02-13 10:00'), 'sem o Carnaval na config, segunda é útil');
    }

    public function test_n_dias_uteis(): void
    {
        $this->assertSame('2026-10-14', DiasUteis::somar(self::sp('2026-10-09 15:00'), 2)->format('Y-m-d'));
        $this->assertSame('2026-10-09', DiasUteis::somar(self::sp('2026-10-09 15:00'), 0)->format('Y-m-d'), 'D+0 é o próprio dia');
    }
}
