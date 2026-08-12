<?php

namespace Tests\Feature\Phase128;

use App\Models\Servico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano 128-01 (D-03 / FLUXO-08): prova do Success Criteria 0 no nível do
 * catálogo — Polos sai da migration já isento e nenhum outro lugar do
 * código decide isso por nome. A prova no nível do fluxo (o gatilho de
 * verdade não chamando Clicksign para Polos) vem nos planos 03 e 04.
 */
class ExigeContratoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function polos_semeado_pela_migration_ja_nasce_isento_de_contrato(): void
    {
        $polos = Servico::where('nome', 'Polos')->first();

        $this->assertNotNull($polos, 'seed do catálogo deveria ter criado o serviço Polos');
        $this->assertFalse($polos->exigeContrato());
    }

    #[Test]
    public function servico_novo_sem_informar_exige_contrato_nasce_exigindo(): void
    {
        $servico = Servico::create([
            'nome'          => 'Serviço de teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $this->assertTrue($servico->fresh()->exigeContrato());
    }

    #[Test]
    public function servico_com_exige_contrato_false_gravado_a_mao_nao_exige(): void
    {
        $servico = Servico::create([
            'nome'          => 'Serviço de teste '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        $servico->update(['exige_contrato' => false]);

        $this->assertFalse($servico->fresh()->exigeContrato());
    }

    #[Test]
    public function scope_exige_contrato_nunca_inclui_polos(): void
    {
        $nomes = Servico::query()->exigeContrato()->pluck('nome');

        $this->assertNotContains('Polos', $nomes);
    }

    #[Test]
    public function exige_contrato_e_bool_php_nunca_inteiro_cru(): void
    {
        $polos = Servico::where('nome', 'Polos')->first();

        $this->assertIsBool($polos->exige_contrato);
    }
}
