<?php

namespace Tests\Unit;

use App\Services\Shopee\ShopeeSigner;
use Tests\TestCase;

/**
 * A assinatura HMAC-SHA256 é o maior risco da integração Shopee (errar a ordem/
 * composição da base = todas as chamadas falham com "sign inválido"). Trava as
 * duas bases contra valores-ouro computados manualmente, isolado de HTTP/banco/config.
 *
 *   - Base pública = partner_id + api_path + timestamp
 *   - Base de shop = partner_id + api_path + timestamp + access_token + shop_id
 */
class ShopeeSignerTest extends TestCase
{
    private ShopeeSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        // Credenciais fixas — os valores-ouro abaixo derivam EXATAMENTE destas.
        $this->signer = new ShopeeSigner(123456, 'shpk_test_key');
    }

    /** Base pública = partner_id + path + timestamp (sem token/shop_id). */
    public function test_sign_publico_bate_com_valor_ouro(): void
    {
        $sign = $this->signer->sign('/api/v2/shop/auth_partner', 1700000000);

        // hash_hmac('sha256', '123456/api/v2/shop/auth_partner1700000000', 'shpk_test_key')
        $this->assertSame(
            '598c40f3c234b729b2ca9d31ebdfe2d2dce7d97adf2f015dbb12d53c156f55b9',
            $sign
        );
    }

    /** Base de shop = partner_id + path + timestamp + access_token + shop_id. */
    public function test_sign_de_shop_bate_com_valor_ouro(): void
    {
        $sign = $this->signer->sign('/api/v2/order/get_order_list', 1700000000, 'atk_abc', 789);

        // hash_hmac('sha256', '123456/api/v2/order/get_order_list1700000000atk_abc789', 'shpk_test_key')
        $this->assertSame(
            '01716607d401c9ca9b383e1cb1314a0828a360832b113e8aedb8be3ec87a1218',
            $sign
        );
    }

    /** A composição da base importa: público ≠ shop mesmo com path/timestamp iguais. */
    public function test_bases_publica_e_de_shop_sao_diferentes(): void
    {
        $pub  = $this->signer->sign('/api/v2/order/get_order_list', 1700000000);
        $shop = $this->signer->sign('/api/v2/order/get_order_list', 1700000000, 'atk_abc', 789);

        $this->assertNotSame($pub, $shop);
    }

    /** Sem shop_id, o access_token entra na base mas o shop_id não (API de shop parcial). */
    public function test_sign_com_token_sem_shop_id_ainda_muda_a_base(): void
    {
        $publico  = $this->signer->sign('/api/v2/x', 1700000000);
        $comToken = $this->signer->sign('/api/v2/x', 1700000000, 'atk_abc');

        $this->assertNotSame($publico, $comToken);
    }
}
