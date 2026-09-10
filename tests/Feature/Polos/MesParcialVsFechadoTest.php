<?php

// Regra de "mês parcial" no eixo de meses do /polos.
//
// Incidente que originou o teste (02/09/2026): o painel mostrava agosto com R$ 2,74 mi
// em vez de R$ 4,73 mi. Na virada do mês a origem publica o mês recém-encerrado DUAS
// vezes — as linhas definitivas 'FECHADO' e as antigas 'PARCIAL' convivem no mesmo CSV
// (agosto chegou com 547 de cada). A regra antiga (`|| $parcial`) fazia UMA linha parcial
// vencer todas as fechadas, e o mês continuava "corrente".
//
// Não é cosmético: mês parcial faz montarAtivosDoMes() usar o roster AO VIVO, e o time
// avança todas as fases na virada (M0→M1→…→M4→Encerrado). Agosto passou a ser somado com
// as fases de setembro e as 43 empresas que eram M4 sumiram do total.

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use ReflectionMethod;
use Tests\TestCase;

/** @group polos */
class MesParcialVsFechadoTest extends TestCase
{
    /** listarMeses() é helper privado de lógica pura — exercitado por reflection. */
    private function listarMeses(array $linhas): array
    {
        $m = new ReflectionMethod(PolosController::class, 'listarMeses');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $linhas);
    }

    private function linha(string $mes, string $comparativo): array
    {
        return ['TIM_MONTH_ID' => $mes, 'COMPARATIVO' => $comparativo];
    }

    /** @return array<string,bool> [mes => parcial] */
    private function mapa(array $linhas): array
    {
        $out = [];
        foreach ($this->listarMeses($linhas) as $m) {
            $out[$m['value']] = $m['parcial'];
        }

        return $out;
    }

    /** Mês passado anterior ao corrente, para não colidir com a injeção do mês atual. */
    private function mesPassado(int $meses = 1): string
    {
        return now()->subMonthsNoOverflow($meses)->format('Ym');
    }

    public function test_mes_com_fechado_e_parcial_convivendo_conta_como_fechado(): void
    {
        // O caso agosto/2026: metade das linhas FECHADO, metade PARCIAL.
        $mes = $this->mesPassado();
        $linhas = [
            $this->linha($mes, 'FECHADO'),
            $this->linha($mes, 'PARCIAL'),
            $this->linha($mes, 'PARCIAL'),
        ];

        $this->assertFalse(
            $this->mapa($linhas)[$mes],
            'Uma linha PARCIAL não pode fazer um mês já FECHADO voltar a ser corrente — '
            .'é isso que faz o painel somar o mês passado com o roster de hoje.'
        );
    }

    public function test_a_ordem_das_linhas_nao_muda_o_resultado(): void
    {
        // Regressão de shape: o CSV não garante ordem, e a regra antiga dependia dela.
        $mes = $this->mesPassado();

        $this->assertFalse($this->mapa([
            $this->linha($mes, 'PARCIAL'),
            $this->linha($mes, 'FECHADO'),
        ])[$mes]);

        $this->assertFalse($this->mapa([
            $this->linha($mes, 'FECHADO'),
            $this->linha($mes, 'PARCIAL'),
        ])[$mes]);
    }

    public function test_mes_so_com_parcial_continua_parcial(): void
    {
        // Mês que de fato ainda está enchendo: tem de usar o roster ao vivo.
        $mes = $this->mesPassado();

        $this->assertTrue(
            $this->mapa([$this->linha($mes, 'PARCIAL')])[$mes],
            'Sem nenhuma linha FECHADO o mês ainda está aberto.'
        );
    }

    public function test_mes_so_com_fechado_continua_fechado(): void
    {
        $mes = $this->mesPassado(2);

        $this->assertFalse($this->mapa([$this->linha($mes, 'FECHADO')])[$mes]);
    }

    public function test_mes_corrente_e_sempre_parcial_mesmo_com_linha_fechado(): void
    {
        // A injeção do mês atual é intencional (faturamento vem da Adman, não do CSV) e
        // precisa vencer qualquer COMPARATIVO que a origem publique para ele.
        $corrente = now()->format('Ym');

        $this->assertTrue(
            $this->mapa([$this->linha($corrente, 'FECHADO')])[$corrente],
            'O mês corrente usa o roster ao vivo — não pode ser tratado como histórico.'
        );
    }

    public function test_mes_corrente_aparece_mesmo_sem_linha_no_csv(): void
    {
        // A Comercial publica o mês vigente com dias de atraso; o eixo não pode esperar.
        $this->assertArrayHasKey(now()->format('Ym'), $this->mapa([]));
    }
}
