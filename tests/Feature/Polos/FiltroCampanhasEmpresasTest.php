<?php

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\PoloAdsStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TKT-0015 — filtro "Campanhas" em /polos/empresas: ativa, desligada, sem campanha ou
 * não verificado.
 *
 * O chip "Ads desligado" junta conta com campanha parada e conta sem campanha nenhuma (é
 * o sentido de `ads_desligado`, que o roster congela e outras telas leem). A situação nova
 * separa as duas, só para leitura, a partir do que o sync já grava em `polos_ads_status`.
 *
 * @group polos
 */
class FiltroCampanhasEmpresasTest extends TestCase
{
    use RefreshDatabase;

    private function leitura(string $cust, int $ativas, int $total, ?\DateTimeInterface $em = null): PoloAdsStatus
    {
        return PoloAdsStatus::create([
            'cust_id'          => $cust,
            'campanhas_ativas' => $ativas,
            'campanhas_total'  => $total,
            'verificado_em'    => $em ?? now()->subHour(),
        ]);
    }

    private function comAdsAutomatico(array $linhas): array
    {
        $m = new ReflectionMethod(PolosController::class, 'comAdsAutomatico');
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $linhas);
    }

    public function test_leitura_separa_campanha_ativa_desligada_e_conta_sem_campanha(): void
    {
        $this->assertSame(PoloAdsStatus::CAMPANHA_ATIVA, $this->leitura('1', 2, 5)->situacao());
        $this->assertSame(PoloAdsStatus::CAMPANHA_DESLIGADA, $this->leitura('2', 0, 3)->situacao());
        $this->assertSame(PoloAdsStatus::CAMPANHA_SEM_CAMPANHA, $this->leitura('3', 0, 0)->situacao());

        // As duas últimas continuam "desligado" no sentido de sempre: nada muda em ads_desligado.
        $this->assertTrue(PoloAdsStatus::where('cust_id', '2')->first()->desligado());
        $this->assertTrue(PoloAdsStatus::where('cust_id', '3')->first()->desligado());
    }

    public function test_sem_leitura_vale_a_marcacao_manual_e_nunca_afirma_sem_campanha(): void
    {
        $this->assertSame(PoloAdsStatus::CAMPANHA_ATIVA, PoloAdsStatus::situacaoDaEmpresa(null, false));
        $this->assertSame(PoloAdsStatus::CAMPANHA_DESLIGADA, PoloAdsStatus::situacaoDaEmpresa(null, true));
        $this->assertNull(PoloAdsStatus::situacaoDaEmpresa(null, null), 'Sem leitura e sem marcação = não verificado.');
    }

    public function test_cada_linha_da_tela_recebe_a_situacao_e_a_leitura_manda_na_marcacao(): void
    {
        $this->leitura('111', 1, 4);
        $this->leitura('222', 0, 2);
        $this->leitura('333', 0, 0);
        // Leitura velha não vale: a empresa volta para a marcação manual.
        $this->leitura('444', 0, 0, now()->subHours(PoloAdsStatus::FRESCOR_HORAS + 1));

        $linhas = collect($this->comAdsAutomatico([
            ['cust_id' => '111', 'ads_desligado' => true],   // marcação antiga perde para a leitura
            ['cust_id' => '222', 'ads_desligado' => null],
            ['cust_id' => '333', 'ads_desligado' => false],
            ['cust_id' => '444', 'ads_desligado' => true],
            ['cust_id' => '555', 'ads_desligado' => false],  // a Adman não enxerga, marcada Ligado
            ['cust_id' => '666', 'ads_desligado' => null],   // a Adman não enxerga, sem marcação
        ]))->keyBy('cust_id');

        $this->assertSame('ativa', $linhas['111']['ads_campanha']);
        $this->assertSame('desligada', $linhas['222']['ads_campanha']);
        $this->assertSame('sem_campanha', $linhas['333']['ads_campanha']);
        $this->assertSame('desligada', $linhas['444']['ads_campanha'], 'Sem leitura fresca, "sem campanha" não é afirmado.');
        $this->assertSame('ativa', $linhas['555']['ads_campanha']);
        $this->assertNull($linhas['666']['ads_campanha']);

        // O chip "Ads desligado" segue igual: desligada + sem campanha (lidas) + marcadas Desligado.
        $this->assertTrue($linhas['222']['ads_desligado']);
        $this->assertTrue($linhas['333']['ads_desligado']);
        $this->assertFalse($linhas['111']['ads_desligado']);
    }
}
