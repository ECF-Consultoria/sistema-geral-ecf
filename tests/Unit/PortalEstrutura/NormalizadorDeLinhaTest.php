<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
use PHPUnit\Framework\TestCase;

/**
 * Fase 167-06: leitura única de uma linha de produto (grade, celular e planilha).
 */
class NormalizadorDeLinhaTest extends TestCase
{
    private function ler(array $extra = []): array
    {
        return NormalizadorDeLinha::normalizar(['codigo' => 'ABC-1', 'nome' => 'Cristaleira', ...$extra]);
    }

    public function test_codigo_e_nome_sao_obrigatorios_e_codigo_tem_limite(): void
    {
        $r = NormalizadorDeLinha::normalizar(['codigo' => ' ', 'nome' => '']);
        $this->assertSame('Informe o código (Ref).', $r['erros']['codigo']);
        $this->assertSame('Informe o nome do produto.', $r['erros']['nome']);

        $r = $this->ler(['codigo' => str_repeat('A', 121)]);
        $this->assertArrayHasKey('codigo', $r['erros']);

        $this->assertSame([], $this->ler()['erros']);
    }

    public function test_coluna_variacao_ordinal_eixo_valor_e_valor_solto(): void
    {
        $r = $this->ler(['variacao' => '2']);
        $this->assertSame(2, $r['campos']['ordem']);
        $this->assertContains('ordem', $r['presentes']);

        $this->assertSame(1, $this->ler(['variacao' => 'única'])['campos']['ordem']);
        $this->assertSame(1, $this->ler(['variacao' => 'unica'])['campos']['ordem']);

        $r = $this->ler(['variacao' => 'Cor: Natural']);
        $this->assertSame('cor', $r['campos']['eixo']);
        $this->assertSame('Natural', $r['campos']['valor']);
        $this->assertSame([], $r['avisos']);

        $r = $this->ler(['variacao' => 'Estampa: Floral']);
        $this->assertSame('outro', $r['campos']['eixo']);
        $this->assertSame('Floral', $r['campos']['valor']);
        $this->assertCount(1, $r['avisos']);

        $r = $this->ler(['variacao' => 'Natural']);
        $this->assertSame('Natural', $r['campos']['valor']);
        $this->assertNull($r['campos']['eixo']);

        // Eixo explícito vence o da coluna Variação.
        $r = $this->ler(['variacao' => 'Cor: Natural', 'eixo' => 'tamanho']);
        $this->assertSame('tamanho', $r['campos']['eixo']);
    }

    public function test_eixo_por_rotulo_e_lista_fechada(): void
    {
        $this->assertSame('tamanho', $this->ler(['eixo' => 'Tamanho'])['campos']['eixo']);

        $r = $this->ler(['eixo' => 'Peso']);
        $this->assertArrayHasKey('eixo', $r['erros']);
        $this->assertStringContainsString('Cor', $r['erros']['eixo']);
    }

    public function test_ambientes_por_separador_ou_array_e_familia_sem_barra(): void
    {
        $this->assertSame(['Sala Jantar', 'Sala Estar'], $this->ler(['ambientes' => 'Sala Jantar / Sala Estar'])['campos']['ambientes']);
        $this->assertSame(['Sala Jantar', 'Sala Estar'], $this->ler(['ambientes' => 'Sala Jantar, Sala Estar'])['campos']['ambientes']);
        $this->assertSame(['Hall', 'Sala'], $this->ler(['ambientes' => ['Hall', 'Sala', 'sala']])['campos']['ambientes']);

        $r = $this->ler(['familia' => 'Farm/house']);
        $this->assertStringContainsString('Não use / , |', $r['erros']['familia']);

        $r = $this->ler(['familia' => ' Farmhouse ']);
        $this->assertSame('Farmhouse', $r['campos']['familia']);
    }

    public function test_categoria_id_texto_e_limpar(): void
    {
        $r = $this->ler(['categoria_ml_id' => ' mlb1234 ']);
        $this->assertSame('MLB1234', $r['campos']['categoria_ml_id']);
        $this->assertNull($r['campos']['categoria_texto']);

        $r = $this->ler(['categoria_ml_id' => 'Cristaleiras']);
        $this->assertNull($r['campos']['categoria_ml_id']);
        $this->assertSame('Cristaleiras', $r['campos']['categoria_texto']);

        $r = $this->ler(['categoria_ml_id' => '']);
        $this->assertContains('categoria', $r['presentes']);
        $this->assertNull($r['campos']['categoria_ml_id']);
        $this->assertNull($r['campos']['categoria_texto']);

        $this->assertNotContains('categoria', $this->ler()['presentes']);
    }

    public function test_custo_brasileiro_e_invalido(): void
    {
        $this->assertSame(1234.5, $this->ler(['custo' => '1.234,50'])['campos']['custo']);

        $r = $this->ler(['custo' => 'abc']);
        $this->assertSame('Use só números. Exemplo: 27,8', $r['erros']['custo']);

        // Célula em branco não apaga custo existente.
        $this->assertNotContains('custo', $this->ler(['custo' => ''])['presentes']);
    }

    public function test_volumes_texto_ilegivel_avisa_e_nao_entra_nos_presentes(): void
    {
        $r = $this->ler(['volumes_texto' => 'caixa grande']);
        $this->assertNotContains('volumes', $r['presentes']);
        $this->assertCount(1, $r['avisos']);
        $this->assertSame([], $r['erros']);

        $r = $this->ler(['volumes_texto' => "186\u{00D7}43\u{00D7}12 \u{00B7} 27.8 | 97\u{00D7}42\u{00D7}12 \u{00B7} 12.1"]);
        $this->assertContains('volumes', $r['presentes']);
        $this->assertCount(2, $r['campos']['volumes']);
    }

    /** BE-CR-02: "SEM MEDIDAS" é célula em branco; só `volumes: []` explícito limpa. */
    public function test_sem_medidas_nao_conta_como_presente_e_lista_vazia_conta(): void
    {
        foreach (['SEM MEDIDAS', 'Sem medida', 'sem-medidas.'] as $t) {
            $r = $this->ler(['volumes_texto' => $t]);
            $this->assertNotContains('volumes', $r['presentes'], $t);
            $this->assertSame([], $r['avisos'], $t);
            $this->assertSame([], $r['erros'], $t);
        }

        $this->assertContains('volumes', $this->ler(['volumes' => []])['presentes']);
    }

    public function test_volume_com_zero_e_erro(): void
    {
        $r = $this->ler(['volumes' => [['c' => 10, 'l' => 0, 'a' => 5, 'kg' => 2]]]);
        $this->assertArrayHasKey('volumes', $r['erros']);

        $r = $this->ler(['volumes' => [['c' => '93', 'l' => '55', 'a' => '6', 'kg' => '9,5']]]);
        $this->assertSame([], $r['erros']);
        $this->assertSame(9.5, $r['campos']['volumes'][0]['kg']);
    }

    public function test_presentes_lista_so_o_que_veio_na_linha(): void
    {
        $r = $this->ler();
        $this->assertSame(['nome'], $r['presentes']);

        $r = $this->ler(['grupo' => '1014', 'custo' => 10, 'familia' => 'Farmhouse', 'ambientes' => 'Hall']);
        foreach (['nome', 'grupo', 'custo', 'familia', 'ambientes'] as $k) {
            $this->assertContains($k, $r['presentes']);
        }
        $this->assertNotContains('volumes', $r['presentes']);
        $this->assertNotContains('eixo', $r['presentes']);
    }
}
