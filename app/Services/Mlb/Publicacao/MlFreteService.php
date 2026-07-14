<?php

namespace App\Services\Mlb\Publicacao;

use App\Models\Company;
use App\Services\MercadoLivreService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cotação de frete automática via Mercado Envios (SHIP-02).
 *
 * Responsabilidades:
 *  - Consultar GET /users/{seller_id}/shipping_options/free para obter estimativa
 *    de custo de frete com base nas dimensões e peso do pacote.
 *  - Retornar null em qualquer falha (token ausente, HTTP != 2xx, exceção de rede).
 *    Publicação NUNCA é bloqueada por falha de cotação (SHIP-02: degradação graciosa).
 *
 * CAVEAT ME2 (STACK.md linha 329): na maioria das categorias ME2 as dimensões enviadas
 * são ignoradas pelo ML — ele usa as dimensões configuradas da categoria.
 * A estimativa é INDICATIVA, não definitiva. Labels no wizard devem refletir isso.
 *
 * NÃO usa cache: cotações de frete são efêmeras (preço muda com peso, dimensões,
 * tipo de anúncio, configuração do vendedor). Diferente de MlGradeService (TTL 1h).
 *
 * Usa token de empresa (não app token) pois o endpoint /users/{seller_id}/shipping_options/free
 * exige seller_id — obtido via MlToken::ml_user_id (confirmado nos spikes Fase A/B).
 */
class MlFreteService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function __construct(private MercadoLivreService $ml) {}

    /**
     * Cota o frete para um pacote nas dimensões e peso informados.
     *
     * Monta a string dimensions como "{altura_cm}x{largura_cm}x{comprimento_cm},{peso_g}"
     * e chama GET /users/{seller_id}/shipping_options/free com os parâmetros fixos do ME2.
     *
     * Retorna o array completo da resposta em sucesso (inclui shipping_options[].list_cost
     * e shipping_options[].cost). Retorna null em qualquer falha — o controller exibe
     * estimativa_frete=null e a publicação segue normalmente (SHIP-02).
     *
     * @param  Company $company  Empresa com conta ML conectada
     * @param  array   $params   Parâmetros de cotação:
     *                           - peso_g (numeric, min:1)
     *                           - altura_cm (numeric, min:1)
     *                           - largura_cm (numeric, min:1)
     *                           - comprimento_cm (numeric, min:1)
     *                           - item_price (numeric, min:0.01)
     *                           - listing_type_id (string: gold_special|gold_pro)
     * @return array|null  Resposta da API ML em sucesso; null em falha (SHIP-02)
     */
    public function cotar(Company $company, array $params): ?array
    {
        try {
            // Obtém token da empresa (não app token — endpoint requer seller_id)
            $token = $this->ml->ensureValidToken($company);

            if ($token === null) {
                // Empresa sem token ML ativo → retorna null silenciosamente (SHIP-02)
                return null;
            }

            $sellerId = (string) $token->ml_user_id;

            // Monta a string dimensions no formato exigido pelo ML:
            // "{altura}x{largura}x{comprimento},{peso_gramas}" (STACK.md linha 177)
            $dimensions = "{$params['altura_cm']}x{$params['largura_cm']}x{$params['comprimento_cm']},{$params['peso_g']}";

            // Parâmetros fixos do endpoint ME2 (STACK.md linhas 280-292)
            $paramsQuery = [
                'dimensions'      => $dimensions,
                'item_price'      => $params['item_price'],
                'listing_type_id' => $params['listing_type_id'],
                'mode'            => 'me2',
                'condition'       => 'new',
                'logistic_type'   => 'drop_off',
                'free_shipping'   => false,
                'verbose'         => true,
            ];

            $resp = Http::withToken($token->access_token)
                ->get(self::API_BASE . "/users/{$sellerId}/shipping_options/free", $paramsQuery);

            // SHIP-02: degradação graciosa — retorna null quando o ML não responde com 2xx
            return $resp->successful() ? $resp->json() : null;
        } catch (\Throwable $e) {
            // Falha de rede, token inválido, JSON malformado etc.
            // Prefixo "[MLB Frete]" para rastreabilidade nos logs (padrão do projeto)
            Log::warning("[MLB Frete] Falha na cotação empresa {$company->id}: {$e->getMessage()}");

            // Publicação NÃO é bloqueada (SHIP-02)
            return null;
        }
    }
}
