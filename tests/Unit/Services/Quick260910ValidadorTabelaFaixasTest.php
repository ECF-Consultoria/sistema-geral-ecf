<?php

namespace Tests\Unit\Services;

use App\Services\Fechamento\ValidadorTabelaFaixas;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick 260910-l7k — as regras compostas da tabela de faixas, agora fora do FormRequest.
 *
 * A prova de que a extração não mudou comportamento é a suíte do cadastro manual
 * (`Phase137FaixasCrudTest`/`Phase138FaixasGrupoCrudTest`) continuar verde sem edição nenhuma.
 * Aqui ficam os casos diretos do serviço — inclusive a forma das duas tabelas malformadas que
 * entraram em produção pela porta da confirmação de contrato.
 */
class Quick260910ValidadorTabelaFaixasTest extends TestCase
{
    private function validador(): ValidadorTabelaFaixas
    {
        return new ValidadorTabelaFaixas();
    }

    #[Test]
    public function tabela_bem_formada_nao_tem_erro(): void
    {
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => 499999.99, 'valor' => 3000.00, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 999999.99, 'valor' => 4000.00, 'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => null, 'valor' => 5000.00, 'valor_e_piso' => true],
        ]);

        $this->assertSame([], $erros);
    }

    #[Test]
    public function teto_fora_de_ordem_e_recusado_apontando_a_linha_original(): void
    {
        // A forma da MAXIGOLD (#234): a penúltima linha tem teto MENOR que a anterior.
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => 48000.00, 'valor' => 1500.00, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => 25000.00, 'valor' => 2000.00, 'valor_e_piso' => false],
            ['ordem' => 3, 'limite_superior' => null, 'valor' => 4000.00, 'valor_e_piso' => true],
        ]);

        $this->assertCount(1, $erros);
        $this->assertSame('faixas.1.limite_superior', $erros[0]['campo']);
        $this->assertStringContainsString('sobrepõe', $erros[0]['mensagem']);
    }

    #[Test]
    public function ordem_repetida_e_recusada(): void
    {
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => 100.00, 'valor' => 10.00],
            ['ordem' => 1, 'limite_superior' => null, 'valor' => 20.00],
        ]);

        $this->assertCount(1, $erros);
        $this->assertSame('faixas', $erros[0]['campo']);
    }

    #[Test]
    public function duas_faixas_sem_teto_sao_recusadas(): void
    {
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => null, 'valor' => 10.00],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => 20.00],
        ]);

        $this->assertCount(2, $erros);
    }

    #[Test]
    public function faixa_sem_teto_no_meio_da_tabela_e_recusada(): void
    {
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => null, 'valor' => 10.00],
            ['ordem' => 2, 'limite_superior' => 100.00, 'valor' => 20.00],
        ]);

        $this->assertCount(1, $erros);
        $this->assertSame('faixas.0.limite_superior', $erros[0]['campo']);
    }

    #[Test]
    public function valor_e_piso_em_faixa_com_teto_e_recusado(): void
    {
        $erros = $this->validador()->erros([
            ['ordem' => 1, 'limite_superior' => 100.00, 'valor' => 10.00, 'valor_e_piso' => true],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => 20.00, 'valor_e_piso' => true],
        ]);

        $this->assertCount(1, $erros);
        $this->assertSame('faixas.0.valor_e_piso', $erros[0]['campo']);
    }

    #[Test]
    public function tabela_vazia_fica_por_conta_das_regras_primarias(): void
    {
        $this->assertSame([], $this->validador()->erros([]));
    }
}
