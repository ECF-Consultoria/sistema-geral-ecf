<?php

namespace App\Services\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fase 184 (BLING-09, BLING-10) — ciclo OAuth do Bling: geração da URL de
 * autorização, `state` de uso único em cache e troca do `code` por token.
 *
 * Duas travas que a API exige, já nesta primeira metade do serviço:
 *
 * 1. `state` de uso único em cache (`consumirState`): a segunda chamada com o
 *    mesmo state devolve `null` e o callback para antes de falar com o Bling.
 * 2. `code` trocado uma única vez (`trocarCode`, sem nenhuma retentativa):
 *    **reusar um code ainda válido revoga o cliente**. O `code` vale só 1 minuto.
 *
 * A renovação travada por conexão e a revogação (BLING-11/12) chegam na
 * próxima task deste mesmo plano.
 *
 * ⚠️ A URL de token é `https://api.bling.com.br/Api/v3/oauth/token`. A
 * documentação do Bling escreve `/oauth/token` **sem** o `/Api/v3`, e essa
 * forma dá 403 (testado pelo seed em 10/10/2026).
 *
 * Molde copiado de `App\Services\MercadoLivreService` (state em cache,
 * `http_build_query` da URL de autorização).
 */
class BlingOAuthService
{
    private const AUTH_URL = 'https://www.bling.com.br/Api/v3/oauth/authorize';

    private const TOKEN_URL = 'https://api.bling.com.br/Api/v3/oauth/token';

    private const REVOKE_URL = 'https://api.bling.com.br/Api/v3/oauth/revoke';

    private const STATE_TTL = 604800; // 7 dias para o cliente autorizar

    private const PREFIXO_STATE = 'bling_oauth_state_';

    // ═══ OAuth: geração de URL e consumo do state ════════════════════════════

    /**
     * Gera a URL de autorização do Bling e guarda o `state` em cache, de uso
     * único, preso à empresa/ator/destino.
     *
     * @throws \RuntimeException quando as credenciais do app não estão no `.env`
     */
    public function urlDeAutorizacao(Company $empresa, ?int $atorUserId, ?string $destino = null): string
    {
        if (! BlingConexao::estaConfigurado()) {
            throw new \RuntimeException(
                '[Bling] Credenciais não configuradas — preencha BLING_CLIENT_ID, BLING_CLIENT_SECRET e BLING_REDIRECT_URI no .env.'
            );
        }

        $state = Str::uuid()->toString();

        Cache::put(self::PREFIXO_STATE.$state, [
            'company_id' => $empresa->id,
            'ator' => $atorUserId,
            'destino' => $destino,
        ], self::STATE_TTL);

        // ⚠️ `redirect_uri` e `scope` NÃO entram de propósito: o Bling os
        // ignora (valem os do cadastro do app) e mandá-los esconderia um erro
        // de cadastro do app atrás de um comportamento que parece funcionar.
        return self::AUTH_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.bling.client_id'),
            'state' => $state,
        ]);
    }

    /**
     * Recupera os dados presos ao `state` e o apaga do cache.
     *
     * É esta função que impede o reuso do `code`: um callback reentregue
     * (duplo clique, retry do navegador) encontra o `state` já consumido e
     * devolve `null` ANTES de falar com o Bling de novo — e reusar um `code`
     * ainda válido revoga o cliente.
     *
     * @return array{company_id: int, ator: ?int, destino: ?string}|null
     */
    public function consumirState(string $state): ?array
    {
        $chave = self::PREFIXO_STATE.$state;
        $dados = Cache::get($chave);
        Cache::forget($chave);

        return is_array($dados) ? $dados : null;
    }

    // ═══ OAuth: troca do code ═════════════════════════════════════════════════

    /**
     * Troca o `code` da autorização por `access_token`/`refresh_token`.
     *
     * Nenhuma retentativa: o `code` vale 1 minuto e é de uso único — uma
     * segunda troca com o mesmo `code` revoga o cliente no Bling.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: string}
     *
     * @throws \RuntimeException
     */
    public function trocarCode(string $code): array
    {
        [$clientId, $clientSecret] = $this->credenciais();

        try {
            $response = Http::asForm()->withHeaders([
                'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                'enable-jwt' => '1',
                'Accept' => 'application/json',
            ])->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('[Bling] Falha de conexão ao trocar o código de autorização: '.$e->getMessage());

            throw new \RuntimeException('[Bling] Erro de conexão ao trocar o código de autorização.');
        }

        if (! $response->successful()) {
            Log::warning('[Bling] Falha ao trocar o código de autorização', [
                'status' => $response->status(),
                'corpo' => self::semSegredo($response->body()),
            ]);

            throw new \RuntimeException('[Bling] Não foi possível concluir a autorização — gere um novo link e tente de novo.');
        }

        return $response->json();
    }

    // ═══ Internos ═════════════════════════════════════════════════════════════

    /**
     * @return array{0: string, 1: string} [client_id, client_secret]
     *
     * @throws \RuntimeException quando alguma credencial falta
     */
    private function credenciais(): array
    {
        $clientId = config('services.bling.client_id');
        $clientSecret = config('services.bling.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new \RuntimeException(
                '[Bling] Credenciais não configuradas — preencha BLING_CLIENT_ID, BLING_CLIENT_SECRET e BLING_REDIRECT_URI no .env.'
            );
        }

        return [$clientId, $clientSecret];
    }

    /**
     * Troca sequências longas de caracteres (o formato de um JWT ou de um
     * refresh token do Bling) por um marcador, antes de logar o corpo de uma
     * resposta de erro. O corpo de erro não deveria trazer token nenhum, mas
     * este helper existe para não depender disso.
     */
    private static function semSegredo(string $corpo): string
    {
        $limpo = preg_replace('/[A-Za-z0-9_\-]{24,}(?:\.[A-Za-z0-9_\-]{10,}){0,2}/', '[token]', $corpo);

        return $limpo ?? $corpo;
    }
}
