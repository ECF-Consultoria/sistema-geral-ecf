<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Services\Publicador\VitrineDeProdutosService;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\RegraViolada;
use Tests\TestCase;

/**
 * 26/10/2026 — o usuário liberou o Publicador novo para TODAS as contas, inclusive publicar ("deixar todos no
 * mesmo padrão Dev 02 Teste"). Os interruptores `todas` (publicar e Alavancas) e o `vitrine` ligam tudo de uma
 * vez; desligados, vale a lista conta a conta, como antes.
 *
 * Mutação: tirar o `todas` de uma das classes reprova o caso "fora da lista" dela; ligar um interruptor pelo outro
 * reprova o caso de independência.
 */
class LiberarTodasAsContasTest extends TestCase
{
    private function company(int $id): Company
    {
        return (new Company())->forceFill(['id' => $id]);
    }

    private function mlbEmpresa(int $id): MlbEmpresa
    {
        return (new MlbEmpresa())->forceFill(['id' => $id]);
    }

    private function listas(bool $publicar, bool $alavancas): void
    {
        config([
            'publicador.contas_liberadas' => ['todas' => $publicar, 'companies' => [459], 'mlb_empresas' => []],
            'publicador.alavancas.contas_liberadas' => ['todas' => $alavancas, 'companies' => [459], 'mlb_empresas' => []],
        ]);
    }

    public function test_desligado_vale_so_a_lista(): void
    {
        $this->listas(false, false);

        $this->assertTrue(ContasLiberadas::libera($this->company(459)));
        $this->assertFalse(ContasLiberadas::libera($this->company(12)));
        $this->assertFalse(ContasLiberadas::libera($this->mlbEmpresa(459)), 'Company 459 não libera MlbEmpresa 459');
        $this->assertFalse(AlavancasLiberadas::libera($this->company(12)));
        $this->assertFalse(AlavancasLiberadas::liberaChave('company-12'));
        $this->assertTrue(AlavancasLiberadas::liberaChave('company-459'));

        $this->expectException(RegraViolada::class);
        ContasLiberadas::exigir($this->company(12));
    }

    public function test_publicar_ligado_libera_qualquer_conta_das_duas_ancoras(): void
    {
        $this->listas(true, false);

        $this->assertTrue(ContasLiberadas::libera($this->company(12)));
        $this->assertTrue(ContasLiberadas::libera($this->mlbEmpresa(665)));
        ContasLiberadas::exigir($this->company(12));
        $this->assertFalse(ContasLiberadas::libera(null), 'sem conta segue fechado');
    }

    public function test_alavancas_ligado_libera_qualquer_conta_e_qualquer_chave_valida(): void
    {
        $this->listas(false, true);

        $this->assertTrue(AlavancasLiberadas::libera($this->company(12)));
        $this->assertTrue(AlavancasLiberadas::libera($this->mlbEmpresa(665)));
        $this->assertTrue(AlavancasLiberadas::liberaChave('company-12'));
        $this->assertTrue(AlavancasLiberadas::liberaChave('empresa-665'));
        $this->assertFalse(AlavancasLiberadas::liberaChave('lixo-12'), 'chave em outro formato segue fechada');
        $this->assertFalse(AlavancasLiberadas::liberaChave(null));
        $this->assertFalse(AlavancasLiberadas::libera(null));
    }

    public function test_um_interruptor_nao_liga_o_outro(): void
    {
        $this->listas(true, false);
        $this->assertFalse(AlavancasLiberadas::libera($this->company(12)), 'publicar liberado não libera as Alavancas');

        $this->listas(false, true);
        $this->assertFalse(ContasLiberadas::libera($this->company(12)), 'Alavancas liberadas não liberam publicar');
    }

    public function test_vitrine_liga_para_todas_ou_so_para_as_listadas(): void
    {
        config(['publicador.vitrine' => false, 'publicador.vitrine_contas' => ['company-459']]);
        $this->assertTrue(VitrineDeProdutosService::ligadaPara('company-459'));
        $this->assertFalse(VitrineDeProdutosService::ligadaPara('company-12'));

        config(['publicador.vitrine' => true]);
        $this->assertTrue(VitrineDeProdutosService::ligadaPara('company-12'));
        $this->assertTrue(VitrineDeProdutosService::ligadaPara('empresa-665'));
    }
}
