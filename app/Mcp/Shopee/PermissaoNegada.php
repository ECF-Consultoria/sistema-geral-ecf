<?php

namespace App\Mcp\Shopee;

use RuntimeException;

/**
 * A Shopee recusou o endpoint pelo TIPO do app ("This app type has no
 * permission to this API"). Uso interno da {@see ChamadaShopee}: dispara a
 * tentativa no outro app da loja antes de virar erro para o usuário.
 */
class PermissaoNegada extends RuntimeException
{
}
