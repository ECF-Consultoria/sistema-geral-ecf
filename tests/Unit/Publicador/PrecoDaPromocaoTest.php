<?php

namespace Tests\Unit\Publicador;

use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use App\Support\Publicador\PrecoDaPromocao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A conta da promoção automática (10/10/2026). O exemplo do usuário: custo 120 (100 + frete 20) com os
 * padrões da Calculadora → anuncia R$ 207,19 e a promoção é R$ 172,66 (−16,67%). Os MESMOS casos
 * estão em `tests/js/publicador-promocao-automatica.test.js` (a tela faz a conta em JS).
 */
class PrecoDaPromocaoTest extends TestCase
{
    private const PORTAL = ['anunciado' => 207.19, 'minimo' => 172.66, 'sem_frete' => false];

    public function test_publicado_pelo_anunciado_a_promocao_e_o_minimo_do_portal(): void
    {
        // A conta da Precificação do Portal, sem cópia: é ela que dá os dois números.
        $portal = PrecificacaoEstrutura::preco(100, 20, 11.5, 19, 0, 0, 20);
        $this->assertSame([172.66, 207.19], [$portal['minimo'], $portal['anunciado']]);

        $r = PrecoDaPromocao::calcular(207.19, ['anunciado' => $portal['anunciado'], 'minimo' => $portal['minimo'], 'sem_frete' => $portal['sem_frete']]);

        $this->assertTrue($r['calculavel']);
        $this->assertSame(172.66, $r['preco']);
        $this->assertSame(16.67, $r['percentual']);
        $this->assertFalse($r['ajustada_ao_minimo']);
    }

    public function test_preco_digitado_acima_aplica_o_mesmo_percentual(): void
    {
        $r = PrecoDaPromocao::calcular(250.0, self::PORTAL);

        // 250 × (172,66 ÷ 207,19) = 208,335… → 208,34; o desconto continua ~16,67%.
        $this->assertSame(208.34, $r['preco']);
        $this->assertSame(16.66, $r['percentual']);
        $this->assertFalse($r['ajustada_ao_minimo']);
    }

    public function test_preco_digitado_abaixo_nunca_cai_abaixo_do_minimo(): void
    {
        $r = PrecoDaPromocao::calcular(195.0, self::PORTAL);

        // 195 × 0,8333 = 162,50 < 172,66: fica no mínimo, e o desconto encolhe para 11,46%.
        $this->assertTrue($r['calculavel']);
        $this->assertSame(172.66, $r['preco']);
        $this->assertSame(11.46, $r['percentual']);
        $this->assertTrue($r['ajustada_ao_minimo']);
    }

    /** @return array<string, array{0: ?float, 1: ?array, 2: string}> */
    public static function semPromocao(): array
    {
        return [
            'sem preço publicado' => [null, self::PORTAL, PrecoDaPromocao::SEM_PRECO],
            'produto sem Portal' => [150.0, null, PrecoDaPromocao::SEM_PORTAL],
            'Portal sem o mínimo' => [150.0, ['anunciado' => 150.0, 'minimo' => null], PrecoDaPromocao::SEM_PORTAL],
            'Portal calculado sem frete' => [207.19, [...self::PORTAL, 'sem_frete' => true], PrecoDaPromocao::SEM_FRETE],
            'publicado no mínimo' => [172.66, self::PORTAL, PrecoDaPromocao::NO_MINIMO],
            'publicado abaixo do mínimo' => [160.0, self::PORTAL, PrecoDaPromocao::NO_MINIMO],
            'desconto abaixo de 5%' => [180.0, self::PORTAL, PrecoDaPromocao::DESCONTO_PEQUENO],
            'acréscimo zero no Portal' => [100.0, ['anunciado' => 100.0, 'minimo' => 100.0], PrecoDaPromocao::NO_MINIMO],
            'desconto de 80% ou mais' => [100.0, ['anunciado' => 100.0, 'minimo' => 20.0], PrecoDaPromocao::DESCONTO_GRANDE],
        ];
    }

    #[DataProvider('semPromocao')]
    public function test_sem_promocao(?float $preco, ?array $portal, string $motivo): void
    {
        $r = PrecoDaPromocao::calcular($preco, $portal);

        $this->assertFalse($r['calculavel']);
        $this->assertSame($motivo, $r['motivo']);
        $this->assertNull($r['preco']);
        $this->assertArrayHasKey($motivo, PrecoDaPromocao::MOTIVOS);
    }

    public function test_limites_do_desconto_individual(): void
    {
        // Exatamente 5% passa; 79,99% passa; 80% não (regra do PRICE_DISCOUNT).
        $this->assertTrue(PrecoDaPromocao::calcular(100.0, ['anunciado' => 100.0, 'minimo' => 95.0])['calculavel']);
        $this->assertSame(79.99, PrecoDaPromocao::calcular(100.0, ['anunciado' => 100.0, 'minimo' => 20.01])['percentual']);
        $this->assertSame(14, PrecoDaPromocao::DIAS);
    }
}
