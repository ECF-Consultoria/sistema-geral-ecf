<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MlToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gerencia o ciclo de vida OAuth 2.0 com o Mercado Livre:
 * geração de URL de autorização, troca de code por tokens,
 * renovação automática via refresh_token e chamadas autenticadas à API.
 */
class MercadoLivreService
{
    private const AUTH_URL    = 'https://auth.mercadolivre.com.br/authorization';
    private const TOKEN_URL   = 'https://api.mercadolibre.com/oauth/token';
    private const API_BASE    = 'https://api.mercadolibre.com';
    private const STATE_TTL   = 900;   // 15 minutos para o cliente autorizar

    // ── OAuth: geração de URL ─────────────────────────────────────────────────

    /**
     * Gera URL de autorização OAuth para a empresa informada.
     * Armazena state no cache para vinculação no callback.
     *
     * @return string URL a ser enviada ao cliente/admin
     */
    public function buildAuthUrl(Company $company): string
    {
        $state = Str::uuid()->toString();

        Cache::put("ml_oauth_state_{$state}", $company->id, self::STATE_TTL);

        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => config('services.mercadolivre.client_id'),
            'redirect_uri'  => config('services.mercadolivre.redirect'),
            'state'         => $state,
            // read: dados da conta; offline_access: mantém refresh token válido indefinidamente
            'scope'         => 'read offline_access',
        ]);
    }

    /**
     * Recupera o company_id vinculado ao state e remove do cache.
     * Retorna null se o state for inválido ou expirado.
     */
    public function consumeState(string $state): ?int
    {
        $key       = "ml_oauth_state_{$state}";
        $companyId = Cache::get($key);
        Cache::forget($key);
        return $companyId;
    }

    // ── OAuth: troca de code por tokens ──────────────────────────────────────

    /**
     * Troca o authorization code por access_token + refresh_token.
     *
     * @throws \RuntimeException em caso de falha na API ML
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'code'          => $code,
            'redirect_uri'  => config('services.mercadolivre.redirect'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('[MercadoLivre] Falha ao trocar código: ' . $response->body());
        }

        return $response->json();
    }

    // ── Token: armazenamento e renovação ─────────────────────────────────────

    /**
     * Salva (cria ou atualiza) o token da empresa com os dados retornados pelo ML.
     * O ML retorna um novo refresh_token em cada renovação — sempre substituir.
     *
     * @param  array $data  Resposta do endpoint /oauth/token
     */
    public function saveToken(Company $company, array $data): MlToken
    {
        return MlToken::updateOrCreate(
            ['company_id' => $company->id],
            [
                'ml_user_id'        => (string) $data['user_id'],
                'access_token'      => $data['access_token'],
                'refresh_token'     => $data['refresh_token'],
                'token_type'        => $data['token_type'] ?? 'bearer',
                'scope'             => $data['scope'] ?? null,
                'expires_at'        => now()->addSeconds($data['expires_in'] - 60),
                'last_refreshed_at' => now(),
                'status'            => 'active',
                'connected_at'      => now(),
            ]
        );
    }

    /**
     * Renova o access_token usando o refresh_token armazenado.
     * O ML também renova o refresh_token a cada chamada — ambos são substituídos.
     *
     * @throws \RuntimeException se a renovação falhar (ex.: refresh revogado)
     */
    public function refreshToken(MlToken $token): MlToken
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
            'refresh_token' => $token->refresh_token,
        ]);

        if (! $response->successful()) {
            $token->update(['status' => 'revoked']);
            Log::warning('[MercadoLivre] Refresh token revogado', [
                'company_id' => $token->company_id,
                'response'   => $response->body(),
            ]);
            throw new \RuntimeException('[MercadoLivre] Refresh token inválido — empresa precisa reconectar.');
        }

        $data = $response->json();

        $token->update([
            'access_token'      => $data['access_token'],
            'refresh_token'     => $data['refresh_token'],   // ML renova o refresh também
            'expires_at'        => now()->addSeconds($data['expires_in'] - 60),
            'last_refreshed_at' => now(),
            'status'            => 'active',
        ]);

        return $token->fresh();
    }

    // ── Acesso à API ML ───────────────────────────────────────────────────────

    /**
     * Garante que o token da empresa está válido, renovando se necessário.
     * Retorna null se a empresa não tiver token ou se o refresh falhar permanentemente.
     */
    public function ensureValidToken(Company $company): ?MlToken
    {
        $token = $company->mlToken;

        if (! $token || $token->status === 'revoked') {
            return null;
        }

        if ($token->expiresSoon(5)) {
            try {
                $token = $this->refreshToken($token);
            } catch (\RuntimeException) {
                return null;
            }
        }

        return $token;
    }

    /**
     * Executa uma chamada autenticada à API do ML.
     * Renova o token automaticamente se estiver perto de expirar.
     *
     * @param  string $endpoint  Path relativo, ex: '/users/me'
     * @param  array  $query     Query params adicionais
     * @throws \RuntimeException se a empresa não tiver token válido
     */
    public function get(Company $company, string $endpoint, array $query = []): array
    {
        $token = $this->ensureValidToken($company);

        if (! $token) {
            throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} ({$company->name}) sem token válido.");
        }

        $response = Http::withToken($token->access_token)
            ->get(self::API_BASE . $endpoint, $query);

        if ($response->status() === 401) {
            // Token rejeitado pela API mesmo após renovação — marca como revogado
            $token->update(['status' => 'revoked']);
            Log::error("[MercadoLivre] Token rejeitado pela API empresa {$company->id} ({$company->name})");
            throw new \RuntimeException("[MercadoLivre] Token inválido para empresa {$company->name}.");
        }

        if (! $response->successful()) {
            throw new \RuntimeException("[MercadoLivre] Erro na API: {$response->status()} — {$response->body()}");
        }

        return $response->json();
    }
}
