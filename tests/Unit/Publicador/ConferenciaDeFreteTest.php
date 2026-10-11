<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\ConferenciaDeFrete as F;
use PHPUnit\Framework\TestCase;

/**
 * 11/10/2026 — as faixas da conferência de frete, aprovadas pelo usuário: "se for alguns centavos a menos ou
 * a mais, não tem problema; agora, se for um valor muito alto de diferença, aí já temos que rever".
 *
 * Até R$ 1,00 não avisa; acima, avisa; acima de R$ 10 ou de 10% do frete do Mercado Livre, pede para
 * refazer o preço.
 */
class ConferenciaDeFreteTest extends TestCase
{
    private const CFG = ['tolerancia' => 1.00, 'reprecificar_valor' => 10.00, 'reprecificar_percentual' => 10.0];

    private static function nivel(float $portal, float $ml): string
    {
        return F::comparar($portal, $ml, self::CFG)['nivel'];
    }

    public function test_centavos_e_ate_um_real_nao_avisam_nos_dois_sentidos(): void
    {
        $this->assertSame(F::IGUAL, self::nivel(106.85, 106.85));
        $this->assertSame(F::IGUAL, self::nivel(106.85, 107.20));
        $this->assertSame(F::IGUAL, self::nivel(107.85, 106.85), 'exatamente R$ 1,00 ainda é igual');
        $this->assertSame(F::IGUAL, self::nivel(106.00, 107.00));
    }

    public function test_acima_de_um_real_avisa(): void
    {
        $this->assertSame(F::DIFERENTE, self::nivel(100.00, 106.85));
        $this->assertSame(F::DIFERENTE, self::nivel(106.85, 100.00), 'o Mercado Livre cobrando MENOS também é diferença');
        $this->assertSame(F::DIFERENTE, self::nivel(100.00, 101.01));
    }

    public function test_acima_de_dez_reais_ou_de_dez_por_cento_pede_para_refazer_o_preco(): void
    {
        // Mais de R$ 10, mesmo sendo pouco em percentual.
        $this->assertSame(F::REPRECIFICAR, self::nivel(229.00, 240.90));
        $this->assertSame(F::DIFERENTE, self::nivel(230.90, 240.90), 'exatamente R$ 10 ainda é só aviso');
        // Mais de 10% do frete do Mercado Livre, mesmo sendo poucos reais.
        $this->assertSame(F::REPRECIFICAR, self::nivel(20.00, 23.00));
        $this->assertSame(F::DIFERENTE, self::nivel(41.00, 45.25), '9,4% e R$ 4,25: só aviso');
        // O caso real da Poltrona Opala: R$ 120 digitados no Portal, R$ 240,90 no Mercado Livre.
        $this->assertSame(F::REPRECIFICAR, self::nivel(120.00, 240.90));
    }

    public function test_a_diferenca_vem_com_sinal_e_o_percentual_e_sobre_o_frete_do_mercado_livre(): void
    {
        $this->assertSame(['nivel' => F::REPRECIFICAR, 'diferenca' => 120.9, 'percentual' => 50.2], F::comparar(120.00, 240.90, self::CFG));
        $this->assertSame(-6.85, F::comparar(106.85, 100.00, self::CFG)['diferenca']);
        $this->assertNull(F::comparar(5.00, 0.0, self::CFG)['percentual'], 'sem frete do Mercado Livre não há percentual');
    }

    public function test_a_frase_diz_os_dois_valores_de_onde_veio_o_do_portal_e_o_que_fazer(): void
    {
        $this->assertSame(
            'Frete do Clássico: o Mercado Livre cobra hoje R$ 106,85 e a Precificação usou R$ 100,00 (digitado no Portal). Diferença de R$ 6,85.',
            F::mensagem('do Clássico', 100.00, 'digitado', 106.85, F::DIFERENTE),
        );
        $this->assertStringEndsWith('O preço foi calculado com frete menor que o real: refaça o preço.',
            F::mensagem('do Premium', 120.00, 'tabela', 240.90, F::REPRECIFICAR));
        $this->assertStringContainsString('(estimado pela tabela)', F::mensagem('do Premium', 120.00, 'tabela', 240.90, F::REPRECIFICAR));
        $this->assertStringEndsWith('O preço foi calculado com frete maior que o real: dá para baixar o preço.',
            F::mensagem('do Clássico', 240.90, 'conta', 120.00, F::REPRECIFICAR));
        // Anúncio já publicado: "cobra", sem o "hoje"; origem desconhecida não inventa parênteses.
        $this->assertStringStartsWith('Frete do Clássico: o Mercado Livre cobra R$ 106,85 e a Precificação usou R$ 100,00. Diferença',
            F::mensagem('do Clássico', 100.00, null, 106.85, F::DIFERENTE, publicado: true));
    }

    public function test_o_pacote_do_rascunho_no_formato_da_cotacao_ou_nulo_se_faltar_medida(): void
    {
        $pacote = [
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '90 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '85 cm'],
            'SELLER_PACKAGE_LENGTH' => ['value_name' => '70,4 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '12000 g', 'value_number' => 12000],
        ];
        $this->assertSame('90x85x70,12000', F::dimensions($pacote));

        unset($pacote['SELLER_PACKAGE_WIDTH']);
        $this->assertNull(F::dimensions($pacote));
        $this->assertNull(F::dimensions([]));
    }
}
