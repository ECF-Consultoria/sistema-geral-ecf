<?php

namespace App\Services\Shopee;

/**
 * Assinatura HMAC-SHA256 exigida em TODA request da Shopee Open Platform (v2).
 *
 * A "base string" é a concatenação literal (sem separadores) dos campos, e a
 * chave HMAC é o `partner_key`. A saída é hex em minúsculo. O `partner_key`
 * NUNCA vai na request — só entra aqui.
 *
 *   - APIs públicas (auth):  partner_id + api_path + timestamp
 *   - APIs de shop:          partner_id + api_path + timestamp + access_token + shop_id
 *
 * O `api_path` é SÓ o caminho (ex.: "/api/v2/order/get_order_list"), sem host e
 * sem query string. Erro clássico: assinar a URL completa.
 */
class ShopeeSigner
{
    public function __construct(
        private int $partnerId,
        private string $partnerKey,
    ) {}

    /**
     * Gera o `sign` para o caminho informado.
     *
     * @param  string       $apiPath      Caminho puro (começa com /api/v2/...)
     * @param  int          $timestamp    Unix em segundos
     * @param  string|null  $accessToken  Presente só em APIs de shop
     * @param  int|null     $shopId       Presente só em APIs de shop
     */
    public function sign(string $apiPath, int $timestamp, ?string $accessToken = null, ?int $shopId = null): string
    {
        $base = $this->partnerId . $apiPath . $timestamp;

        if ($accessToken !== null) {
            $base .= $accessToken;
            if ($shopId !== null) {
                $base .= $shopId;
            }
        }

        return hash_hmac('sha256', $base, $this->partnerKey); // hex minúsculo
    }
}
