<?php

namespace App\Services\Shopee;

use App\Models\Company;
use App\Models\ShopeeMetric;
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

    /**
     * Trava de segurança da paginação de pedidos de UMA janela. Quick
     * 261006-dv3: era 2000 e o loop parava calado ao atingir — agora o
     * `fetchOrdersSummary` loga `Log::error` quando trunca (ver lá).
     */
    private const MAX_ORDERS_POR_JANELA = 10000;

    /**
     * TTL da trava que serializa o refresh por empresa. PRECISA ser maior que
     * o `Http::timeout(30)` de dentro da trava: com os 15s de antes, uma
     * resposta lenta da Shopee fazia a trava expirar COM A REQUEST EM VOO, um
     * segundo processo entrava e usava o mesmo `refresh_token` — que é rotativo
     * e single-use. Um dos dois recebia refresh inválido e o token era
     * REVOGADO, que é exatamente a "desconexão sozinha" relatada pelo setor
     * Shopee (quick 261006-dv3).
     */
    private const REFRESH_LOCK_TTL = 60;

    // Caminhos de auth (assinatura PÚBLICA — só partner_id+path+timestamp)
    private const PATH_AUTH        = '/api/v2/shop/auth_partner';
    private const PATH_TOKEN_GET   = '/api/v2/auth/token/get';
    private const PATH_TOKEN_REFRESH = '/api/v2/auth/access_token/get';

    private int $partnerId;
    private string $partnerKey;
    private string $host;
    private string $redirect;
    private ShopeeSigner $signer;

    /**
     * @param string $app Qual app Shopee usar: 'erp' (Order/Payment) ou 'ads'
     *                    (performance de anúncios). Cada um tem credenciais próprias.
     *                    Default 'erp' — é o resolvido pela DI/container.
     */
    public function __construct(private string $app = 'erp')
    {
        $cfg = (array) config("services.shopee.apps.{$this->app}", []);

        $this->partnerId  = (int) ($cfg['partner_id'] ?? 0);
        $this->partnerKey = (string) ($cfg['partner_key'] ?? '');
        $this->redirect   = (string) ($cfg['redirect'] ?? '');
        $this->host       = rtrim((string) config('services.shopee.host'), '/');
        $this->signer     = new ShopeeSigner($this->partnerId, $this->partnerKey);
    }

    /** Instancia o serviço para um app específico ('erp' | 'ads'). */
    public static function for(string $app): self
    {
        return new self($app);
    }

    /** Qual app este serviço representa. */
    public function app(): string
    {
        return $this->app;
    }

    /** True se o app tem credenciais configuradas (usado p/ pular o passo Ads). */
    public function isConfigured(): bool
    {
        return $this->partnerId > 0 && $this->partnerKey !== '';
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
     * ⚠️ A base do redirect (config services.shopee.apps.{app}.redirect) precisa estar
     *    cadastrada no console do app da Shopee.
     */
    public function buildAuthUrl(Company $company, ?string $expectedShopId = null): string
    {
        $state     = Str::uuid()->toString();
        $timestamp = time();
        $sign      = $this->signer->sign(self::PATH_AUTH, $timestamp);

        // O state amarra o callback à empresa, ao APP (erp/ads) e — no passo Ads —
        // à loja já conectada no passo ERP (expected_shop_id), p/ barrar loja errada.
        Cache::put("shopee_oauth_state_{$state}", [
            'company_id'       => $company->id,
            'app'              => $this->app,
            'expected_shop_id' => $expectedShopId,
        ], self::STATE_TTL);

        // redirect carrega nosso state; a Shopee anexa &code=&shop_id= depois.
        $redirect = $this->redirect . (str_contains($this->redirect, '?') ? '&' : '?') . 'state=' . $state;

        return $this->host . self::PATH_AUTH . '?' . http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp'  => $timestamp,
            'sign'       => $sign,
            'redirect'   => $redirect,
        ]);
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
            ['company_id' => $company->id, 'app' => $this->app],
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
                'last_error'         => null,
                'last_error_at'      => null,
            ]
        );
    }

    /**
     * Renova o access_token via refresh_token (rotativo/single-use).
     * Serializa por empresa com Cache::lock para evitar dois processos usando o
     * mesmo refresh_token em paralelo (o segundo receberia erro e mataria a conexão).
     *
     * Toda falha fica registrada em last_error/last_error_at (o painel mostra
     * "Falha na renovação") e vai para Log::error — produção roda LOG_LEVEL=error.
     *
     * @param bool $force Renova mesmo com o access_token ainda válido (keep-alive
     *                    do shopee:refresh-tokens, que mantém a cadeia do refresh viva).
     * @throws \RuntimeException em erro de refresh (reconectar) ou transitório (retry)
     */
    public function refreshToken(ShopeeToken $token, bool $force = false): ShopeeToken
    {
        $companyId = $token->company_id;

        try {
            return Cache::lock("shopee-refresh-{$this->app}-{$companyId}", self::REFRESH_LOCK_TTL)->block(10, function () use ($token, $force) {
                // Recarrega: outro processo pode ter renovado enquanto esperávamos o lock.
                $token = $token->fresh() ?? $token;

                // Já ativo e longe de expirar → reaproveita (evita rotação à toa).
                if (! $force && $token->status === 'active' && ! $token->expiresSoon(10)) {
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
                    $this->registrarFalha($token, "Falha de conexão: {$e->getMessage()}");
                    throw new \RuntimeException('[Shopee] Erro de conexão ao renovar token (transitório).');
                }

                $json = $response->json() ?? [];

                if (! $response->successful() || $this->isError($json)) {
                    $error = strtolower((string) ($json['error'] ?? ''));
                    // Erros de refresh inválido/expirado → revoga (reconectar).
                    $isInvalid = str_contains($error, 'token')
                        || str_contains($error, 'invalid')
                        || str_contains($error, 'expire');

                    $detalhe = trim(($json['error'] ?? "HTTP {$response->status()}") . ' ' . ($json['message'] ?? ''));

                    if ($isInvalid) {
                        $this->registrarFalha($token, "Refresh recusado — empresa precisa reconectar ({$detalhe})", revogar: true);
                        throw new \RuntimeException('[Shopee] Refresh token inválido — empresa precisa reconectar.');
                    }

                    $this->registrarFalha($token, "Erro transitório — conexão mantida ativa ({$detalhe})");
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
                    'last_error'        => null,
                    'last_error_at'     => null,
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
     * Grava a falha de renovação no token (visível no painel) e loga como erro.
     * Com $revogar, marca o token como revoked (a empresa precisa reconectar).
     */
    private function registrarFalha(ShopeeToken $token, string $mensagem, bool $revogar = false): void
    {
        $dados = [
            'last_error'    => mb_substr($mensagem, 0, 1000),
            'last_error_at' => now(),
        ];
        if ($revogar) {
            $dados['status'] = 'revoked';
        }

        $token->update($dados);

        $nome = $token->company?->name;
        Log::error("[Shopee] Renovação do token {$this->app} falhou empresa {$token->company_id} ({$nome}): {$mensagem}");
    }

    /**
     * Retorna token válido da empresa, renovando se necessário.
     * Retorna null se sem token ou revogado.
     */
    public function ensureValidToken(Company $company): ?ShopeeToken
    {
        $token = ShopeeToken::where('company_id', $company->id)
            ->where('app', $this->app)
            ->first();

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

    /**
     * POST assinado a um endpoint de shop da Shopee. Irmão do `get()`: mesmas
     * credenciais, mesma assinatura e mesmo tratamento de erro — a única
     * diferença é que os parâmetros de NEGÓCIO vão no corpo JSON em vez da
     * query string.
     *
     * ⚠️ A `ShopeeSigner` cobre partner_id + caminho + timestamp + access_token
     *    + shop_id e **nada do corpo** — por isso `post()` e `get()` assinam
     *    exatamente igual: o payload não participa da base string.
     *
     * Existe porque endpoints em lote (quick 261006-j44:
     * `/api/v2/payment/get_escrow_detail_batch`) exigem POST — a lista de
     * `order_sn` não cabe/não é aceita na query.
     *
     * Retorna o conteúdo sob a chave `response` (padrão da v2).
     *
     * @param  array $payload Corpo JSON (parâmetros de negócio)
     * @throws \RuntimeException
     */
    public function post(Company $company, string $apiPath, array $payload = []): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[Shopee] Empresa {$company->id} sem token válido.");
        }

        $timestamp = time();
        $sign      = $this->signer->sign($apiPath, $timestamp, $token->access_token, (int) $token->shop_id);

        // Credenciais SEMPRE na query, mesmo no POST (exigência da v2).
        $url = $this->host . $apiPath . '?' . http_build_query([
            'partner_id'   => $this->partnerId,
            'timestamp'    => $timestamp,
            'access_token' => $token->access_token,
            'shop_id'      => (int) $token->shop_id,
            'sign'         => $sign,
        ]);

        $response = $this->http()->asJson()->post($url, $payload);
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
     * Soma o faturamento dos pedidos da janela informada, na MESMA régua do
     * painel da Shopee (Seller Center).
     *
     * get_order_list (paginação por cursor, janela ≤15 dias) → order_sn;
     * get_order_detail (lotes de 50) → `pay_time` + `item_list`;
     * get_escrow_detail_batch (MESMOS lotes de 50) → `voucher_from_seller`.
     *
     *     revenue = Σ (pedidos com `pay_time` não vazio)
     *                 [ Σ (itens) model_discounted_price × model_quantity_purchased ]
     *                 −  voucher_from_seller
     *
     * Sem frete, SEM filtro de status e SEM descontar item cancelado/devolvido.
     *
     * ─── Por que o cupom entrou (quick 261006-j44, 2026-10-06) ──────────────
     * A régua de itens do 261006-fac deixou um resíduo pequeno e SEMPRE PARA
     * CIMA — o usuário conferiu contra a planilha: "a diferença é pouca e
     * sempre pra cima, sempre o valor do sistema é maior que o da planilha".
     * A causa é o cupom que o VENDEDOR banca, que o painel desconta e nós não.
     * Medido na API real, três lojas independentes:
     *
     *   | loja                    | alvo (painel)  | itens (antes)      | itens − cupom      |
     *   |-------------------------|----------------|--------------------|--------------------|
     *   | ITUFARMA1 #225, 30/09   | R$    603,72   |    609,13 (+0,90%) | 603,72 (0,00%) ✔   |
     *   | CAMILLO MATRIZ #1,30/09 | R$  8.953,89   |  9.133,89 (+2,01%) | 8.983,89 (+0,34%)  |
     *   | DROSSI #217, setembro   | R$ 392.422,00  | 403.187,26 (+2,74%)| 391.537,81 (−0,23%)|
     *
     * ⚠️ Os R$ 30,00 que sobram na CAMILLO são IRREDUTÍVEIS: todo pedido enviado
     *    dela tem cupom de R$ 30, e os pedidos pagos e cancelados DEPOIS voltam
     *    com `voucher_from_seller = 0` — a Shopee para de reportar o cupom após
     *    o cancelamento. É resíduo conhecido e minúsculo; não tentar recuperar.
     *
     * ⛔ `voucher_from_shopee` NÃO é descontado. Medido: descontar os dois PASSA
     *    do alvo (ITUFARMA −1,76%, CAMILLO −3,20%). O cupom da Shopee é bancado
     *    pela plataforma e o painel não o tira do faturamento do vendedor.
     *
     * ⛔ O lote é obrigatório: `get_escrow_detail` pedido a pedido seria inviável
     *    (a GENUINEAUTOMOTIVE faz ~980 pedidos/dia). Com chunk de 50 — o mesmo
     *    do `get_order_detail` — o custo é UMA chamada a mais por lote de 50.
     *
     * ─── Por que mudou (quick 261006-fac, 2026-10-06) ───────────────────────
     * Decisão do usuário: "o faturamento das empresas no sistema deve ser
     * exatamente igual ao do painel da Shopee, com frete ou sem frete não
     * importa". Antes somava `total_amount` do pedido e pulava
     * UNPAID|CANCELLED|IN_CANCEL — as DUAS coisas estavam erradas:
     *
     * 1. O painel conta no PAGAMENTO. Pedido pago e cancelado DEPOIS continua
     *    sendo faturamento, e o filtro por status jogava isso fora. Na CAMILLO
     *    MATRIZ (#1) em 30/09/2026 eram R$ 1.434,94 em dois pedidos pagos e
     *    cancelados pelo comprador em seguida (R$ 1.398,13 e R$ 36,81): o painel
     *    mostrava R$ 8.953,89, nós tínhamos gravado R$ 6.953,31, e com esta
     *    regra sai R$ 9.133,89.
     *    ⚠️ Medido: o painel conta o pedido pago POR INTEIRO — descontar o item
     *    cancelado (`cancelled_qty`/`returned_qty`) passa do alvo em 16,6%.
     *    Portanto NÃO descontar.
     * 2. O campo certo é o ITEM, não o pedido. O `total_amount` (itens + frete
     *    pago pelo cliente − promoções) às vezes fica acima e às vezes abaixo do
     *    preço dos itens: na ITUFARMA um pedido tinha total R$ 31,42 contra
     *    R$ 48,46 de item — 54% de diferença.
     *
     * Setembro/2026 inteiro contra a planilha manual do time: EDUMAC PARTS #144
     * e CAMILLO FILIAL RS #358 fecham ao centavo (0,00%) e o pior resto é a GRAN
     * BELO #212, com +5,53%. O resíduo é pequeno e sempre PARA CIMA
     * (provavelmente desconto aplicado no pedido, e não no item): é pendência
     * conhecida, não regressão.
     *
     * A janela do dia continua em BRT (−03:00): dos cinco fusos testados só o
     * BRT fecha (UTC erra +12%, GMT+8 erra −16%).
     *
     * ⚠️ Ponto único da correção: os consumidores leem `shopee_metrics.revenue`,
     * então arrumar aqui propaga para fechamento, dashboard, carteira e
     * desempenho de uma vez — nenhum deles replica a conta.
     *
     * ⚠️ `orders_count` e `sold_quantity` NÃO mudam com o cupom — só o `revenue`.
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
        } while ($more && $cursor !== '' && count($orderSns) < self::MAX_ORDERS_POR_JANELA);

        // Quick 261006-dv3: o corte existe como trava de segurança, mas parar
        // calado faz o faturamento do dia sair truncado sem ninguém saber —
        // a GENUINEAUTOMOTIVE já faz ~980 pedidos num dia normal, e um dia de
        // promoção passa fácil do limite antigo (2000). Se truncar, grita.
        if ($more && count($orderSns) >= self::MAX_ORDERS_POR_JANELA) {
            Log::error(
                "[Shopee] Faturamento TRUNCADO empresa {$company->id} ({$company->name}) janela {$dateFrom}→{$dateTo}: "
                . 'parou em ' . count($orderSns) . ' pedidos e a Shopee ainda tinha mais. '
                . 'O valor deste dia está INCOMPLETO — reduza a janela ou suba MAX_ORDERS_POR_JANELA.'
            );
        }

        // 2) Detalhe em lotes de 50 → soma os itens dos pedidos PAGOS e
        //    desconta o cupom do vendedor (lote de escrow do MESMO chunk).
        $revenue = 0.0;
        $soldQty = 0;
        $counted = 0;

        foreach (array_chunk($orderSns, 50) as $chunk) {
            $detail = $this->get($company, '/api/v2/order/get_order_detail', [
                'order_sn_list'            => implode(',', $chunk),
                'response_optional_fields' => 'total_amount,order_status,item_list,pay_time',
            ]);

            // Só os PAGOS deste chunk — são os únicos que entram no faturamento
            // e, portanto, os únicos cujo cupom precisamos buscar.
            $pagos = [];

            foreach ($detail['order_list'] ?? [] as $order) {
                // O pagamento é o que define o faturamento: quem não tem
                // `pay_time` (UNPAID) não entra, e quem tem entra mesmo que o
                // status de hoje seja CANCELLED/IN_CANCEL — é assim que o painel
                // da Shopee conta (ver docblock).
                if (empty($order['pay_time'])) {
                    continue;
                }

                $counted++;

                if (! empty($order['order_sn'])) {
                    $pagos[] = (string) $order['order_sn'];
                }

                foreach ($order['item_list'] ?? [] as $item) {
                    $qty = (int) ($item['model_quantity_purchased'] ?? 0);

                    // Preço do item JÁ com desconto (sem frete) × quantidade
                    // comprada. `cancelled_qty`/`returned_qty` não são
                    // descontados de propósito: descontar passa do alvo em 16,6%.
                    $revenue += (float) ($item['model_discounted_price'] ?? 0) * $qty;
                    $soldQty += $qty;
                }
            }

            // Cupom do vendedor deste lote. Pedido ausente da resposta conta 0.
            foreach ($this->vouchersDoVendedor($company, $pagos, $dateFrom, $dateTo) as $voucher) {
                $revenue -= $voucher;
            }
        }

        return [
            'revenue'       => round($revenue, 2),
            'orders_count'  => $counted,
            'sold_quantity' => $soldQty,
        ];
    }

    /**
     * Cupom bancado pelo VENDEDOR dos pedidos informados, em UMA chamada de
     * lote (`POST /api/v2/payment/get_escrow_detail_batch`, corpo
     * `{"order_sn_list": [...]}`).
     *
     * A `response` v2 deste endpoint é uma LISTA de
     * `{ escrow_detail: { order_sn, order_income: { voucher_from_seller, ... } } }`
     * — o valor vive em `escrow_detail.order_income.voucher_from_seller`.
     *
     * ⚠️ Se a chamada falhar, NÃO inventa: grita em `Log::error` (mesmo espírito
     *    da trava de truncamento do 261006-dv3) e devolve lista vazia, ou seja,
     *    o lote segue SEM o desconto. Faturamento maior é justamente o defeito
     *    que esta mudança corrige — sair calado o reintroduz em silêncio.
     *
     * @param  array<int, string> $orderSns Pedidos PAGOS de um chunk (≤50)
     * @return array<string, float> order_sn → cupom do vendedor (só os > 0)
     */
    private function vouchersDoVendedor(Company $company, array $orderSns, string $dateFrom, string $dateTo): array
    {
        if ($orderSns === []) {
            return [];
        }

        try {
            $lote = $this->post($company, '/api/v2/payment/get_escrow_detail_batch', [
                'order_sn_list' => array_values($orderSns),
            ]);
        } catch (\Throwable $e) {
            Log::error(
                "[Shopee] Cupom do vendedor NÃO descontado empresa {$company->id} ({$company->name}) "
                . "janela {$dateFrom}→{$dateTo}: o lote get_escrow_detail_batch de " . count($orderSns)
                . ' pedidos falhou (' . $e->getMessage() . '). '
                . 'O faturamento deste lote sai MAIOR que o do painel da Shopee (cupom não descontado).'
            );

            return [];
        }

        $vouchers = [];

        foreach ($lote as $linha) {
            $escrow = $linha['escrow_detail'] ?? null;

            if (! is_array($escrow) || empty($escrow['order_sn'])) {
                continue;
            }

            // ⛔ `voucher_from_shopee` fica de fora de propósito: é bancado pela
            //    plataforma e descontá-lo passa do alvo (ver docblock do
            //    fetchOrdersSummary).
            $valor = (float) ($escrow['order_income']['voucher_from_seller'] ?? 0);

            if ($valor > 0) {
                $vouchers[(string) $escrow['order_sn']] = $valor;
            }
        }

        return $vouchers;
    }

    // ═══ Dados: anúncios (ADS) ════════════════════════════════════════════════

    /**
     * Métricas de anúncios (CPC) da loja no período, via
     * get_all_cpc_ads_daily_performance (nível loja, diário). Só faz sentido no
     * app 'ads' — instancie com ShopeeService::for('ads').
     *
     * ⚠️ A Shopee exige data em DD-MM-YYYY neste endpoint (validado no sandbox: a
     *    resposta ecoa "date":"DD-MM-YYYY"). A `response` v2 vem como LISTA de dias.
     *
     * Agrega o período e deriva CTR/ROAS/ACoS (safe div). O TACoS NÃO sai daqui —
     * ele cruza o gasto com o faturamento TOTAL da loja (app 'erp',
     * fetchOrdersSummary); use ShopeeService::tacos($expense, $revenueTotal).
     *
     * @param  string $dateFrom YYYY-MM-DD (inclusive)
     * @param  string $dateTo   YYYY-MM-DD (inclusive)
     * @return array{
     *   expense: float, broad_gmv: float, direct_gmv: float, impressions: int,
     *   clicks: int, broad_orders: int, direct_orders: int, broad_conversions: int,
     *   ctr: float, broad_roas: float|null, acos: float|null,
     *   days: array<int, array<string, mixed>>
     * }
     */
    public function fetchAdsMetrics(Company $company, string $dateFrom, string $dateTo): array
    {
        $data = $this->get($company, '/api/v2/ads/get_all_cpc_ads_daily_performance', [
            'start_date' => date('d-m-Y', strtotime($dateFrom)),
            'end_date'   => date('d-m-Y', strtotime($dateTo)),
        ]);

        // response v2 é uma LISTA de dias (cada item = um dia da janela).
        $days = array_is_list($data) ? $data : [];

        $expense    = 0.0;
        $broadGmv   = 0.0;
        $directGmv  = 0.0;
        $impressions = 0;
        $clicks     = 0;
        $broadOrders  = 0;
        $directOrders = 0;
        $broadConversions = 0;

        foreach ($days as $d) {
            $expense      += (float) ($d['expense'] ?? 0);
            $broadGmv     += (float) ($d['broad_gmv'] ?? 0);
            $directGmv    += (float) ($d['direct_gmv'] ?? 0);
            $impressions  += (int) ($d['impression'] ?? 0);
            $clicks       += (int) ($d['clicks'] ?? 0);
            $broadOrders  += (int) ($d['broad_order'] ?? 0);
            $directOrders += (int) ($d['direct_order'] ?? 0);
            $broadConversions += (int) ($d['broad_conversions'] ?? 0);
        }

        return [
            'expense'           => round($expense, 2),
            'broad_gmv'         => round($broadGmv, 2),
            'direct_gmv'        => round($directGmv, 2),
            'impressions'       => $impressions,
            'clicks'            => $clicks,
            'broad_orders'      => $broadOrders,
            'direct_orders'     => $directOrders,
            'broad_conversions' => $broadConversions,
            'ctr'               => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
            'broad_roas'        => $expense > 0 ? round($broadGmv / $expense, 2) : null,
            'acos'              => $broadGmv > 0 ? round($expense / $broadGmv, 4) : null,
            'days'              => $days,
        ];
    }

    /**
     * TACoS (Total Advertising Cost of Sales) = gasto com ads / faturamento TOTAL.
     * Cruza o app 'ads' (expense) com o 'erp' (faturamento bruto). Null se sem
     * faturamento (evita divisão por zero).
     */
    public static function tacos(float $expense, float $revenueTotal): ?float
    {
        return $revenueTotal > 0 ? round($expense / $revenueTotal, 4) : null;
    }

    // ═══ Sync: grava métricas diárias ═════════════════════════════════════════

    /**
     * Sincroniza o faturamento de UM dia da empresa para shopee_metrics.
     *
     * Dia sem pedido válido:
     * - se NÃO existe linha → não grava (mantém a tabela enxuta, como sempre);
     * - se JÁ existe linha → grava o ZERO. Quick 261006-dv3: antes devolvia
     *   `null` e deixava o valor velho no banco, então pedido cancelado depois
     *   da coleta nunca baixava o número — a releitura só sabia subir. Também é
     *   o que preenche as linhas que o `syncAdsDay` criou sozinho (`revenue = 0`
     *   por default da coluna, `synced_at` NULL): 15 delas em 4 empresas em
     *   06/10/2026, indistinguíveis de dia sem venda.
     *
     * Retorna a métrica gravada ou null (dia vazio e sem linha prévia).
     */
    public function syncCompanyDay(Company $company, string $date): ?ShopeeMetric
    {
        $summary = $this->fetchOrdersSummary($company, $date, $date);

        if (($summary['orders_count'] ?? 0) === 0) {
            // Dia sem pedido e SEM linha prévia: não inventa linha.
            if (! $this->linhaDoDia($company, $date)) {
                return null;
            }

            // Linha existe e o dia não tem mais pedido válido — zera de verdade.
            return $this->upsertDia($company, $date, [
                'revenue'       => 0,
                'orders_count'  => 0,
                'sold_quantity' => 0,
                'synced_at'     => now(),
            ]);
        }

        return $this->upsertDia($company, $date, [
            'revenue'       => $summary['revenue'],
            'orders_count'  => $summary['orders_count'],
            'sold_quantity' => $summary['sold_quantity'],
            'synced_at'     => now(),
        ]);
    }

    /**
     * A linha de `shopee_metrics` daquele dia, ou null.
     *
     * Usa `whereDate` (não igualdade crua) pelo mesmo motivo documentado em
     * `ShopeeMetricDiffService::naJanela()`: `reference_date` tem cast `date`, e
     * em SQLite o valor é serializado como `Y-m-d 00:00:00`, que não casa com a
     * string `Y-m-d` numa comparação direta.
     */
    private function linhaDoDia(Company $company, string $date): ?ShopeeMetric
    {
        return ShopeeMetric::where('company_id', $company->id)
            ->whereDate('reference_date', $date)
            ->first();
    }

    /**
     * Grava os campos informados no dia da empresa, criando a linha se não
     * existir. Preserva as colunas que não vieram em `$dados` — é o que permite
     * ao app 'ads' fazer merge na mesma linha do faturamento.
     *
     * ⚠️ POR QUE NÃO `updateOrCreate(['company_id','reference_date'])`, que era
     * o que estava aqui: o match por igualdade crua de `reference_date` só
     * funciona no MySQL/MariaDB (coluna DATE, comparação tolerante). No SQLite
     * dos testes o valor gravado é `Y-m-d 00:00:00` e o `where` por `Y-m-d` não
     * casa — o `updateOrCreate` tentava INSERT e estourava a unique
     * `(company_id, reference_date)`. Ninguém tinha visto porque nenhum teste
     * regravava um dia que já existia; a releitura diária faz exatamente isso
     * (quick 261006-dv3).
     */
    private function upsertDia(Company $company, string $date, array $dados): ShopeeMetric
    {
        $linha = $this->linhaDoDia($company, $date);

        if ($linha) {
            $linha->update($dados);

            return $linha->fresh();
        }

        return ShopeeMetric::create(array_merge($dados, [
            'company_id'     => $company->id,
            'reference_date' => $date,
        ]));
    }

    /**
     * Sincroniza os Ads de UM dia da empresa para shopee_metrics (colunas ad_*).
     * Só faz sentido no app 'ads' — instancie com new ShopeeService('ads').
     *
     * Faz merge na MESMA linha do faturamento (via `upsertDia`): grava SÓ as
     * colunas ad_*, sem tocar em revenue/orders (que vêm do app 'erp'). Dia sem
     * gasto E sem impressão não grava linha (mantém a tabela enxuta). Retorna a
     * métrica ou null (dia vazio).
     *
     * ⚠️ Quando a linha do dia ainda NÃO existe, este método a cria com
     * `revenue = 0` (default da coluna) e `synced_at` NULL — um buraco do sync
     * de faturamento fica indistinguível de dia sem venda (15 linhas em 4
     * empresas em 06/10/2026). Quem conserta é o `shopee:reler-dias`, que relê
     * o faturamento daquele dia e grava o valor certo em cima.
     *
     * ⚠️ Não chamar com datas > 6 meses atrás — a Shopee devolve
     *    ads.performance.error_date_too_old (o comando shopee:sync-ads faz o clamp).
     */
    public function syncAdsDay(Company $company, string $date): ?ShopeeMetric
    {
        $ads = $this->fetchAdsMetrics($company, $date, $date);

        // Dia totalmente zerado (sem gasto e sem impressão) não gera linha.
        if ((float) $ads['expense'] === 0.0 && (int) $ads['impressions'] === 0) {
            return null;
        }

        return $this->upsertDia($company, $date, [
            'ad_expense'           => $ads['expense'],
            'ad_impressions'       => $ads['impressions'],
            'ad_clicks'            => $ads['clicks'],
            'ad_broad_gmv'         => $ads['broad_gmv'],
            'ad_broad_orders'      => $ads['broad_orders'],
            'ad_broad_conversions' => $ads['broad_conversions'],
            'ad_synced_at'         => now(),
        ]);
    }
}
