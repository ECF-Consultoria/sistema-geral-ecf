<?php

namespace App\Services\Shopee;

use App\Models\Company;
use App\Models\ShopeeToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integração direta com a Shopee Open Platform (API v2).
 * Gerencia o ciclo OAuth (auth URL, troca de code, refresh rotativo) e chamadas
 * autenticadas assinadas (HMAC-SHA256 via ShopeeSigner).
 *
 * Difere do MercadoLivreService:
 * - Sem PKCE e sem `scope` na URL — toda request leva `sign` na query.
 * - `shop_id` obrigatório em toda chamada de shop (vai na query junto do token).
 * - `access_token` dura ~4h → ensureValidToken renova com folga (expiresSoon 30min).
 * - Respostas v2: erro no top-level `error` (string vazia = ok); dados de shop
 *   ficam sob a chave `response`.
 */
class ShopeeService
{
    private const STATE_TTL = 604800; // 7 dias para o cliente autorizar

    // Caminhos de auth (assinatura PÚBLICA — só partner_id+path+timestamp)
    private const PATH_AUTH        = '/api/v2/shop/auth_partner';
    private const PATH_TOKEN_GET   = '/api/v2/auth/token/get';
    private const PATH_TOKEN_REFRESH = '/api/v2/auth/access_token/get';

    private int $partnerId;
    private string $partnerKey;
    private string $host;
    private string $redirect;
    private ShopeeSigner $signer;

    public function __construct()
    {
        $this->partnerId  = (int) config('services.shopee.partner_id');
        $this->partnerKey = (string) config('services.shopee.partner_key');
        $this->host       = rtrim((string) config('services.shopee.host'), '/');
        $this->redirect   = (string) config('services.shopee.redirect');
        $this->signer     = new ShopeeSigner($this->partnerId, $this->partnerKey);
    }

    /**
     * Cliente HTTP base. Desliga a verificação SSL SOMENTE quando
     * services.shopee.verify_ssl é false (dev local atrás de TLS interceptado /
     * PHP sem cacert.pem). Em produção verify_ssl deve ser true.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::timeout(30);

        if (! config('services.shopee.verify_ssl', true)) {
            $req = $req->withoutVerifying();
        }

        return $req;
    }

    // ═══ OAuth: geração de URL ════════════════════════════════════════════════

    /**
     * Gera a URL de autorização Shopee para a empresa. O `state` (UUID) é anexado
     * ao redirect e guardado em cache para vincular o callback à empresa — a
     * Shopee preserva o query do redirect e ainda anexa `code` e `shop_id`.
     *
     * ⚠️ A base do redirect (config services.shopee.redirect) precisa estar
     *    cadastrada no console do app da Shopee.
     */
    public function buildAuthUrl(Company $company): string
    {
        $state     = Str::uuid()->toString();
        $timestamp = time();
        $sign      = $this->signer->sign(self::PATH_AUTH, $timestamp);

        Cache::put("shopee_oauth_state_{$state}", [
            'company_id' => $company->id,
        ], self::STATE_TTL);

        // redirect carrega nosso state; a Shopee anexa &code=&shop_id= depois.
        $redirect = $this->redirect . (str_contains($this->redirect, '?') ? '&' : '?') . 'state=' . $state;

        $url = $this->host . self::PATH_AUTH . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
            'redirect'   => $redirect,
        ]);

        $company->update([
            'shopee_link_generated_at' => now(),
            'shopee_link_url'          => $url,
        ]);

        return $url;
    }

    /**
     * Recupera os dados vinculados ao state e remove do cache (evita replay).
     * Retorna ['company_id' => int] ou null se inválido/expirado.
     */
    public function consumeState(string $state): ?array
    {
        $key  = "shopee_oauth_state_{$state}";
        $data = Cache::get($key);
        Cache::forget($key);

        return is_array($data) ? $data : null;
    }

    // ═══ OAuth: troca e renovação de tokens ══════════════════════════════════

    /**
     * Troca o `code` do callback por access_token + refresh_token.
     *
     * @throws \RuntimeException
     */
    public function exchangeCode(string $code, string $shopId): array
    {
        $timestamp = time();
        $sign      = $this->signer->sign(self::PATH_TOKEN_GET, $timestamp);

        $url = $this->host . self::PATH_TOKEN_GET . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
        ]);

        $response = $this->http()->post($url, [
            'code'       => $code,
            'partner_id' => $this->partnerId,
            'shop_id'    => (int) $shopId,
        ]);

        $json = $response->json() ?? [];

        if (! $response->successful() || $this->isError($json)) {
            throw new \RuntimeException('[Shopee] Falha ao trocar code: ' . $response->body());
        }

        return $json;
    }

    /**
     * Salva (cria ou atualiza) o token da empresa. Sempre substitui o par —
     * o refresh_token da Shopee rotaciona a cada renovação.
     */
    public function saveToken(Company $company, string $shopId, array $data): ShopeeToken
    {
        $expireIn = (int) ($data['expire_in'] ?? 14400);

        return ShopeeToken::updateOrCreate(
            ['company_id' => $company->id],
            [
                'shop_id'            => (string) ($data['shop_id'] ?? $shopId),
                'merchant_id'        => isset($data['merchant_id']) ? (string) $data['merchant_id'] : null,
                'access_token'       => $data['access_token'],
                'refresh_token'      => $data['refresh_token'],
                'expires_at'         => now()->addSeconds($expireIn - 60),
                'refresh_expires_at' => now()->addDays(30), // informativo — API não devolve
                'last_refreshed_at'  => now(),
                'status'             => 'active',
                'connected_at'       => now(),
            ]
        );
    }

    /**
     * Renova o access_token via refresh_token (rotativo/single-use).
     * Serializa por empresa com Cache::lock para evitar dois processos usando o
     * mesmo refresh_token em paralelo (o segundo receberia erro e mataria a conexão).
     *
     * @throws \RuntimeException em erro de refresh (reconectar) ou transitório (retry)
     */
    public function refreshToken(ShopeeToken $token): ShopeeToken
    {
        $companyId = $token->company_id;

        try {
            return Cache::lock("shopee-refresh-{$companyId}", 15)->block(10, function () use ($token, $companyId) {
                // Recarrega: outro processo pode ter renovado enquanto esperávamos o lock.
                $token = $token->fresh() ?? $token;

                // Já ativo e longe de expirar → reaproveita (evita rotação à toa).
                if ($token->status === 'active' && ! $token->expiresSoon(10)) {
                    return $token;
                }

                $timestamp = time();
                $sign      = $this->signer->sign(self::PATH_TOKEN_REFRESH, $timestamp);

                $url = $this->host . self::PATH_TOKEN_REFRESH . '?' . http_build_query([
                    'partner_id' => $this->partnerId,
                    'timestamp'  => $timestamp,
                    'sign'       => $sign,
                ]);

                $body = [
                    'refresh_token' => $token->refresh_token,
                    'partner_id'    => $this->partnerId,
                    'shop_id'       => (int) $token->shop_id,
                ];
                if ($token->merchant_id) {
                    $body['merchant_id'] = (int) $token->merchant_id;
                }

                try {
                    $response = $this->http()->post($url, $body);
                } catch (\Illuminate\Http\Client\ConnectionException $e) {
                    Log::warning("[Shopee] Falha de conexão ao renovar token empresa {$companyId}: {$e->getMessage()}");
                    throw new \RuntimeException('[Shopee] Erro de conexão ao renovar token (transitório).');
                }

                $json = $response->json() ?? [];

                if (! $response->successful() || $this->isError($json)) {
                    $error = strtolower((string) ($json['error'] ?? ''));
                    // Erros de refresh inválido/expirado → revoga (reconectar).
                    $isInvalid = str_contains($error, 'token')
                        || str_contains($error, 'invalid')
                        || str_contains($error, 'expire');

                    if ($isInvalid) {
                        $token->update(['status' => 'revoked']);
                        Log::warning('[Shopee] Refresh token revogado', [
                            'company_id' => $companyId,
                            'response'   => $response->body(),
                        ]);
                        throw new \RuntimeException('[Shopee] Refresh token inválido — empresa precisa reconectar.');
                    }

                    Log::warning("[Shopee] Erro transitório ao renovar token empresa {$companyId} — conexão mantida ativa", [
                        'response' => $response->body(),
                    ]);
                    throw new \RuntimeException('[Shopee] Erro transitório ao renovar token.');
                }

                $expireIn = (int) ($json['expire_in'] ?? 14400);

                $token->update([
                    'access_token'      => $json['access_token'],
                    'refresh_token'     => $json['refresh_token'],
                    'expires_at'        => now()->addSeconds($expireIn - 60),
                    'refresh_expires_at' => now()->addDays(30),
                    'last_refreshed_at' => now(),
                    'status'            => 'active',
                ]);

                return $token->fresh();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            Log::info("[Shopee] Refresh concorrente empresa {$companyId} — reutilizando token do banco.");
            $fresh = $token->fresh();

            if (! $fresh || $fresh->status !== 'active') {
                throw new \RuntimeException('[Shopee] Não foi possível renovar token (concorrência de lock).');
            }

            return $fresh;
        }
    }

    // ═══ Token: helpers ═══════════════════════════════════════════════════════

    /**
     * Retorna token válido da empresa, renovando se necessário.
     * Retorna null se sem token ou revogado.
     */
    public function ensureValidToken(Company $company): ?ShopeeToken
    {
        $token = $company->shopeeToken ?? ShopeeToken::where('company_id', $company->id)->first();

        if (! $token || $token->status === 'revoked') {
            return null;
        }

        if ($token->expiresSoon()) {
            try {
                $token = $this->refreshToken($token);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $token;
    }

    // ═══ HTTP: chamada de shop autenticada e assinada ═════════════════════════

    /**
     * GET assinado a um endpoint de shop da Shopee. Monta partner_id/timestamp/
     * access_token/shop_id/sign na query e mescla os params de negócio.
     * Retorna o conteúdo sob a chave `response` (padrão da v2).
     *
     * @throws \RuntimeException
     */
    public function get(Company $company, string $apiPath, array $query = []): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[Shopee] Empresa {$company->id} sem token válido.");
        }

        $timestamp = time();
        $sign      = $this->signer->sign($apiPath, $timestamp, $token->access_token, (int) $token->shop_id);

        $params = array_merge([
            'partner_id'   => $this->partnerId,
            'timestamp'    => $timestamp,
            'access_token' => $token->access_token,
            'shop_id'      => (int) $token->shop_id,
            'sign'         => $sign,
        ], $query);

        $response = $this->http()->get($this->host . $apiPath, $params);
        $json     = $response->json() ?? [];

        if (! $response->successful() || $this->isError($json)) {
            throw new \RuntimeException("[Shopee] Erro em {$apiPath} empresa {$company->id}: {$response->body()}");
        }

        return $json['response'] ?? [];
    }

    /** True quando a resposta v2 traz um `error` não-vazio. */
    private function isError(array $json): bool
    {
        return isset($json['error']) && $json['error'] !== '' && $json['error'] !== null;
    }

    // ═══ Público (nível partner) ══════════════════════════════════════════════

    /**
     * Lista as lojas já autorizadas a este partner (public API — assinatura
     * PÚBLICA, sem shop_id/token). Serve como smoke test de conectividade e
     * assinatura, e para descobrir shop_ids já vinculados no sandbox.
     *
     * @return array resposta v2 crua (authed_shop_list, more, etc.)
     * @throws \RuntimeException
     */
    public function fetchAuthedShops(int $pageNo = 1, int $pageSize = 100): array
    {
        $path      = '/api/v2/public/get_shops_by_partner';
        $timestamp = time();
        $sign      = $this->signer->sign($path, $timestamp);

        $response = $this->http()->get($this->host . $path, [
            'partner_id' => $this->partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
            'page_no'    => $pageNo,
            'page_size'  => $pageSize,
        ]);

        $json = $response->json() ?? [];

        if (! $response->successful() || $this->isError($json)) {
            throw new \RuntimeException('[Shopee] get_shops_by_partner: ' . $response->body());
        }

        return $json;
    }

    // ═══ Dados: conta/loja ═════════════════════════════════════════════════════

    /**
     * Info básica da loja autenticada — usado para confirmar que o token é válido.
     */
    public function fetchShopInfo(Company $company): array
    {
        return $this->get($company, '/api/v2/shop/get_shop_info');
    }

    // ═══ Dados: pedidos (faturamento bruto) ═══════════════════════════════════

    /**
     * Soma o faturamento bruto dos pedidos da janela informada.
     * get_order_list (paginação por cursor, janela ≤15 dias) → order_sn;
     * get_order_detail (lotes de 50) → total_amount. Ignora UNPAID e CANCELLED.
     *
     * @param  string $dateFrom  YYYY-MM-DD (inclusive, 00:00 BRT)
     * @param  string $dateTo    YYYY-MM-DD (inclusive, 23:59 BRT)
     * @return array{revenue: float, orders_count: int, sold_quantity: int}
     */
    public function fetchOrdersSummary(Company $company, string $dateFrom, string $dateTo): array
    {
        // Unix seconds no fuso BRT (-03:00)
        $from = strtotime($dateFrom . ' 00:00:00 -0300');
        $to   = strtotime($dateTo   . ' 23:59:59 -0300');

        // 1) Lista os order_sn da janela (cursor)
        $orderSns = [];
        $cursor   = '';
        do {
            $data = $this->get($company, '/api/v2/order/get_order_list', [
                'time_range_field' => 'create_time',
                'time_from'        => $from,
                'time_to'          => $to,
                'page_size'        => 100,
                'cursor'           => $cursor,
            ]);

            foreach ($data['order_list'] ?? [] as $o) {
                if (! empty($o['order_sn'])) {
                    $orderSns[] = $o['order_sn'];
                }
            }

            $cursor = $data['next_cursor'] ?? '';
            $more   = (bool) ($data['more'] ?? false);
        } while ($more && $cursor !== '' && count($orderSns) < 2000);

        // 2) Detalhe em lotes de 50 → soma valores
        $revenue = 0.0;
        $soldQty = 0;
        $counted = 0;

        foreach (array_chunk($orderSns, 50) as $chunk) {
            $detail = $this->get($company, '/api/v2/order/get_order_detail', [
                'order_sn_list'           => implode(',', $chunk),
                'response_optional_fields' => 'total_amount,order_status,item_list',
            ]);

            foreach ($detail['order_list'] ?? [] as $order) {
                $status = strtoupper($order['order_status'] ?? '');
                if (in_array($status, ['UNPAID', 'CANCELLED', 'IN_CANCEL'], true)) {
                    continue;
                }

                $revenue += (float) ($order['total_amount'] ?? 0);
                $counted++;

                foreach ($order['item_list'] ?? [] as $item) {
                    $soldQty += (int) ($item['model_quantity_purchased'] ?? 0);
                }
            }
        }

        return [
            'revenue'       => round($revenue, 2),
            'orders_count'  => $counted,
            'sold_quantity' => $soldQty,
        ];
    }
}
