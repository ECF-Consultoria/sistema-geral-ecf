<?php

namespace Tests\Unit;

use App\Models\MlbImplementacao;
use Tests\TestCase;

/**
 * Testes da regra do Mercado Envios sobre as MEDIDAS DA EMBALAGEM da Planilha
 * de Produtos (Onboarding /implementacao).
 *
 * MlbImplementacao::planilhaExcedeMercadoEnvios() decide se algum produto
 * ultrapassa os limites logísticos do Mercado Envios:
 *   - maior lado > 200 cm
 *   - soma dos três lados > 300 cm
 *   - peso > 50 kg
 * Quando excede, o controller marca o me1 da empresa como "Precisa de ME1".
 *
 * @group onboarding
 */
class MlbImplementacaoMercadoEnviosTest extends TestCase
{
    // Sem RefreshDatabase — planilhaExcedeMercadoEnvios() é estática, só memória.

    /** Monta um produto com as medidas da embalagem informadas. */
    private function produto(array $emb): array
    {
        return array_merge(
            ['altura_emb' => '', 'largura_emb' => '', 'prof_emb' => '', 'peso_emb_kg' => ''],
            $emb
        );
    }

    public function test_planilha_vazia_nao_excede(): void
    {
        $this->assertFalse(MlbImplementacao::planilhaExcedeMercadoEnvios([]));
    }

    public function test_dentro_dos_limites_nao_excede(): void
    {
        // maior lado 100, soma 250, peso 40 — tudo dentro do limite
        $produtos = [$this->produto([
            'altura_emb' => '100', 'largura_emb' => '100', 'prof_emb' => '50', 'peso_emb_kg' => '40',
        ])];
        $this->assertFalse(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_nos_limites_exatos_nao_excede(): void
    {
        // Limites são "não pode passar" — igual ao limite NÃO excede.
        // maior lado 200, soma 300 (200+50+50), peso 50
        $produtos = [$this->produto([
            'altura_emb' => '200', 'largura_emb' => '50', 'prof_emb' => '50', 'peso_emb_kg' => '50',
        ])];
        $this->assertFalse(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_maior_lado_acima_de_200_excede(): void
    {
        $produtos = [$this->produto([
            'altura_emb' => '201', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5',
        ])];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_soma_dos_lados_acima_de_300_excede(): void
    {
        // Nenhum lado passa de 200, mas 150+150+50 = 350 > 300
        $produtos = [$this->produto([
            'altura_emb' => '150', 'largura_emb' => '150', 'prof_emb' => '50', 'peso_emb_kg' => '5',
        ])];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_peso_acima_de_50_excede(): void
    {
        $produtos = [$this->produto([
            'altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '50.5',
        ])];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_aceita_virgula_como_separador_decimal(): void
    {
        // 50,5 kg (vírgula) deve ser lido como 50.5 → excede
        $produtos = [$this->produto([
            'altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '50,5',
        ])];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_campos_vazios_nao_excedem(): void
    {
        // Produto sem medidas de embalagem preenchidas não deve disparar a regra.
        $produtos = [$this->produto([])];
        $this->assertFalse(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_basta_um_produto_excedente(): void
    {
        // Primeiro dentro do limite, segundo estoura o peso → excede.
        $produtos = [
            $this->produto(['altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5']),
            $this->produto(['altura_emb' => '10', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '80']),
        ];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }

    public function test_ignora_entradas_nao_array(): void
    {
        // Robustez: entradas malformadas não devem quebrar a avaliação.
        $produtos = [
            null,
            'lixo',
            $this->produto(['altura_emb' => '250', 'largura_emb' => '10', 'prof_emb' => '10', 'peso_emb_kg' => '5']),
        ];
        $this->assertTrue(MlbImplementacao::planilhaExcedeMercadoEnvios($produtos));
    }
}
