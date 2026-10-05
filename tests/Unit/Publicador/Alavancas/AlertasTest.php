<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\AlertasAlavancas;
use App\Services\Publicador\Alavancas\DatasDoMl;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** 166-04: alertas do D-12 — marcam, nunca bloqueiam e nunca ordenam. */
class AlertasTest extends TestCase
{
    private function hoje(): CarbonImmutable
    {
        return DatasDoMl::ler('2026-10-04T10:00:00')->startOfDay();
    }

    public function test_convite_que_vence_em_2_dias_alerta_e_em_5_nao(): void
    {
        $a = AlertasAlavancas::doConvite(['prazo' => '2026-10-06T23:59:59'], $this->hoje());
        $this->assertSame('prazo', $a[0]['codigo']);
        $this->assertStringContainsString('2 dia(s)', $a[0]['texto']);
        $this->assertStringContainsString('06/10', $a[0]['texto']);

        $this->assertSame([], AlertasAlavancas::doConvite(['prazo' => '2026-10-09T23:59:59'], $this->hoje()));
        $this->assertSame([], AlertasAlavancas::doConvite([], $this->hoje()));
    }

    public function test_config_nula_desliga_o_prazo(): void
    {
        config(['publicador.alavancas.alertas.convite_vence_em_dias' => null]);
        $this->assertSame([], AlertasAlavancas::doConvite(['prazo' => '2026-10-05T23:59:59'], $this->hoje()));
    }

    public function test_recebido_com_queda_acima_do_limite(): void
    {
        $a = AlertasAlavancas::doItem(['tipo' => 'DEAL', 'recebe_normal' => 100, 'recebe_promocao' => 80], []);
        $this->assertSame(['recebido'], array_column($a, 'codigo'));
        $this->assertStringContainsString('R$ 80,00', $a[0]['texto']);
        $this->assertStringContainsString('20%', $a[0]['texto']);

        $this->assertSame([], AlertasAlavancas::doItem(['tipo' => 'DEAL', 'recebe_normal' => 100, 'recebe_promocao' => 95], []));

        config(['publicador.alavancas.alertas.recebido_queda_percentual' => null]);
        $this->assertSame([], AlertasAlavancas::doItem(['tipo' => 'DEAL', 'recebe_normal' => 100, 'recebe_promocao' => 80], []));
    }

    public function test_estoque_abaixo_do_minimo_da_oferta(): void
    {
        $a = AlertasAlavancas::doItem(['tipo' => 'DOD', 'estoque' => 3, 'estoque_minimo' => 5], []);
        $this->assertSame(['estoque'], array_column($a, 'codigo'));

        config(['publicador.alavancas.alertas.estoque_minimo' => false]);
        $this->assertSame([], AlertasAlavancas::doItem(['tipo' => 'DOD', 'estoque' => 3, 'estoque_minimo' => 5], []));
    }

    public function test_reputacao_so_pesa_em_desconto_campanha_do_vendedor_e_cupom(): void
    {
        $a = AlertasAlavancas::doItem(['tipo' => 'PRICE_DISCOUNT'], ['reputacao' => '3_yellow']);
        $this->assertSame(['reputacao'], array_column($a, 'codigo'));
        $this->assertSame([], AlertasAlavancas::doItem(['tipo' => 'PRICE_DISCOUNT'], ['reputacao' => '5_green']));
        $this->assertSame([], AlertasAlavancas::doItem(['tipo' => 'DEAL'], ['reputacao' => '3_yellow']));
    }

    public function test_ordem_fixa_recebido_estoque_reputacao(): void
    {
        $a = AlertasAlavancas::doItem(
            ['tipo' => 'DOD', 'recebe_normal' => 100, 'recebe_promocao' => 50, 'estoque' => 1, 'estoque_minimo' => 5],
            ['reputacao' => '3_yellow'],
        );
        $this->assertSame(['recebido', 'estoque'], array_column($a, 'codigo'));

        $b = AlertasAlavancas::doItem(
            ['tipo' => 'PRICE_DISCOUNT', 'recebe_normal' => 100, 'recebe_promocao' => 50],
            ['reputacao' => '3_yellow'],
        );
        $this->assertSame(['recebido', 'reputacao'], array_column($b, 'codigo'));
    }

    public function test_alerta_nunca_bloqueia_nem_ordena_por_fonte(): void
    {
        foreach (glob(base_path('app/Services/Publicador/Alavancas/Acoes/*.php')) as $arq) {
            $this->assertStringNotContainsString('AlertasAlavancas', file_get_contents($arq), basename($arq));
        }
        $this->assertStringNotContainsString('sort', file_get_contents(base_path('app/Services/Publicador/Alavancas/AlertasAlavancas.php')));
    }
}
