<?php

namespace App\Services\Bling;

use App\Models\BlingConexao;
use App\Models\Company;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fase 184 (BLING-09 a BLING-13) — ciclo OAuth do Bling, com as quatro travas
 * que a API exige:
 *
 * 1. `state` de uso único em cache (`consumirState`): a segunda chamada com o
 *    mesmo state devolve `null` e o callback para antes de falar com o Bling.
 * 2. `code` trocado uma única vez (`trocarCode`, sem nenhuma retentativa):
 *    **reusar um code ainda válido revoga o cliente**. O `code` vale só 1 minuto.
 * 3. Renovação serializada por conexão (`renovar`, `Cache::lock` por
 *    `$conexao->id`): duas renovações simultâneas da mesma conta derrubam a
 *    autorização.
 * 4. Keep-alive espalhado (comando `bling:renovar-tokens`, não aqui): **20
 *    chamadas a `/oauth/token` em 60 s bloqueiam o IP da VPS por 60 minutos,
 *    para TODOS os clientes**.
 *
 * ⚠️ A URL de token é `https://api.bling.com.br/Api/v3/oauth/token`. A
 * documentação do Bling escreve `/oauth/token` **sem** o `/Api/v3`, e essa
 * forma dá 403 (testado pelo seed em 10/10/2026). O mesmo vale para
 * `/oauth/revoke`.
 *
 * Molde copiado de `App\Services\MercadoLivreService` (state em cache,
 * `Cache::lock` na renovação, `fresh()` dentro do lock, "só revoga quando o
 * token é recusado de verdade").
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

    // ═══ Fase 184-07 (BLING-17, BLING-18, BLING-29) — link assinado da landing ═

    /**
     * Gera (ou regera) o link assinado de 7 dias que a equipe manda ao
     * cliente. Mora AQUI, não no controller, porque o plano 11 cria
     * `MlbPublicadorBlingController`, que também precisa gerar o link — os
     * dois controllers chamam este método único em vez de um chamar o outro.
     *
     * Regerar o link para uma empresa já `ativa` NÃO apaga o token: a ação de
     * socorro ("manda outro link, o cliente perdeu o antigo") não pode
     * custar a conexão viva. Só quando ainda não há token a linha nasce/some
     * para `aguardando_cliente`.
     *
     * @return array{url: string, gerado_em: string, expira_em: string}
     *
     * @throws \RuntimeException quando as credenciais do app não estão no `.env`
     */
    public function gerarLinkDeAutorizacao(Company $company, ?int $porUsuarioId): array
    {
        if (! BlingConexao::estaConfigurado()) {
            throw new \RuntimeException(
                '[Bling] Credenciais não configuradas — preencha BLING_CLIENT_ID, BLING_CLIENT_SECRET e BLING_REDIRECT_URI no .env.'
            );
        }

        $expiraEm = now()->addDays(7);
        $url = URL::temporarySignedRoute('bling.connect.landing', $expiraEm, ['company' => $company->id]);

        $conexaoAtual = BlingConexao::where('company_id', $company->id)->first();
        $jaAtiva = $conexaoAtual && $conexaoAtual->status === BlingConexao::STATUS_ATIVA && $conexaoAtual->access_token;

        $dados = [
            'link_url' => $url,
            'link_gerado_em' => now(),
            'conectado_por' => $porUsuarioId,
        ];

        // Só muda o status para "aguardando o cliente" quando ainda não há
        // token ativo — regerar o link de uma conexão já conectada é socorro,
        // não reinício do fluxo.
        if (! $jaAtiva) {
            $dados['status'] = BlingConexao::STATUS_AGUARDANDO_CLIENTE;
        }

        if (! $company->cnpj) {
            $dados['last_error'] = 'A empresa não tem CNPJ cadastrado; a conferência da conta no retorno vai acusar divergência.';
        }

        BlingConexao::updateOrCreate(['company_id' => $company->id], $dados);

        return [
            'url' => $url,
            'gerado_em' => now()->toISOString(),
            'expira_em' => $expiraEm->toISOString(),
        ];
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

    // ═══ OAuth: renovação travada por conexão ════════════════════════════════

    /**
     * Renova o `access_token` da conexão, travando por `$conexao->id` — nunca
     * por uma chave global, que serializaria clientes diferentes entre si e,
     * com o refresh girando, poderia derrubar a conexão de outra empresa.
     *
     * Regras da API (RESEARCH §2.1/§2.2), todas obrigatórias:
     * - o `refresh_token` devolvido é **sempre** gravado (suposição 5 do
     *   RESEARCH §5, não medida: que o refresh de fato gira a cada renovação
     *   — gravar sempre o novo cobre os dois casos; TODO(184-13): confirmar
     *   na conta de teste e registrar em `184-MEDICOES.md`);
     * - **429 nunca retenta na hora**: 20 chamadas a `/oauth/token` em 60 s
     *   bloqueiam o IP da VPS por 60 min, para todos os clientes;
     * - **só revoga em `invalid_grant`/`UNAUTHORIZED_ERROR`** (400/401); 5xx e
     *   qualquer outro erro mantêm `status` intacto, para o chamador retentar.
     *
     * @throws \RuntimeException
     */
    public function renovar(BlingConexao $conexao): BlingConexao
    {
        [$clientId, $clientSecret] = $this->credenciais();

        try {
            return Cache::lock("bling-refresh-{$conexao->id}", 15)->block(10, function () use ($conexao, $clientId, $clientSecret) {
                // Recarrega: outro processo pode ter renovado enquanto esperávamos o lock.
                $conexao = $conexao->fresh() ?? $conexao;

                // Já ativa, renovada há <30s e longe de expirar → reaproveita (evita rotação à toa).
                if ($conexao->status === BlingConexao::STATUS_ATIVA
                    && ! $conexao->expiraEm(5)
                    && $conexao->last_refreshed_at?->gt(now()->subSeconds(30))) {
                    return $conexao;
                }

                try {
                    $response = Http::asForm()->withHeaders([
                        'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                        'enable-jwt' => '1',
                        'Accept' => 'application/json',
                    ])->post(self::TOKEN_URL, [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $conexao->refresh_token,
                    ]);
                } catch (ConnectionException $e) {
                    // Falha de rede — transitória. NÃO mexe no status; deixa para a próxima tentativa.
                    Log::warning("[Bling] Falha de conexão ao renovar a conexão {$conexao->id}: {$e->getMessage()}");

                    throw new \RuntimeException('[Bling] Erro de conexão ao renovar (transitório).');
                }

                // 429: nunca retentar na hora. 600 req/10s e 20 chamadas de
                // /oauth/token em 60s bloqueiam o IP da VPS para todos os clientes.
                if ($response->status() === 429) {
                    $periodo = $response->json('error.description.period') ?? $response->json('period');

                    $conexao->update([
                        'last_error' => 'TOO_MANY_REQUESTS'.($periodo !== null ? " (period={$periodo})" : ''),
                        'last_error_at' => now(),
                    ]);

                    Log::warning("[Bling] 429 ao renovar a conexão {$conexao->id} — sem nova tentativa imediata.");

                    throw new \RuntimeException('[Bling] Limite de chamadas do Bling atingido — tente novamente mais tarde.');
                }

                if (! $response->successful()) {
                    $corpo = $response->body();
                    $revoga = in_array($response->status(), [400, 401], true)
                        && (stripos($corpo, 'invalid_grant') !== false || stripos($corpo, 'UNAUTHORIZED_ERROR') !== false);

                    if ($revoga) {
                        $conexao->update([
                            'status' => BlingConexao::STATUS_REVOGADA,
                            'last_error' => 'invalid_grant',
                            'last_error_at' => now(),
                        ]);

                        Log::warning("[Bling] Conexão {$conexao->id} revogada pelo Bling — cliente precisa reautorizar.");

                        throw new \RuntimeException('[Bling] Conexão revogada pelo Bling — peça ao cliente para reautorizar.');
                    }

                    // Transitório (5xx e demais): mantém a conexão ativa e deixa retentar.
                    $conexao->update([
                        'last_error' => "HTTP {$response->status()}",
                        'last_error_at' => now(),
                    ]);

                    Log::warning("[Bling] Erro {$response->status()} ao renovar a conexão {$conexao->id} — mantida ativa para nova tentativa.", [
                        'corpo' => self::semSegredo($corpo),
                    ]);

                    throw new \RuntimeException("[Bling] Erro {$response->status()} ao renovar (transitório).");
                }

                $dados = $response->json();

                $conexao->update([
                    'access_token' => $dados['access_token'],
                    'refresh_token' => $dados['refresh_token'],
                    'expires_at' => now()->addSeconds((int) ($dados['expires_in'] ?? 0)),
                    'last_refreshed_at' => now(),
                    'status' => BlingConexao::STATUS_ATIVA,
                    'last_error' => null,
                    'last_error_at' => null,
                ]);

                return $conexao->fresh();
            });
        } catch (LockTimeoutException) {
            // Outro processo está renovando esta mesma conexão agora — reaproveita o banco.
            Log::info("[Bling] Renovação concorrente da conexão {$conexao->id} — reaproveitando o que já está no banco.");

            $fresca = $conexao->fresh();

            if (! $fresca || $fresca->status !== BlingConexao::STATUS_ATIVA) {
                throw new \RuntimeException('[Bling] Não foi possível renovar a conexão (concorrência de lock).');
            }

            return $fresca;
        }
    }

    /**
     * Revoga a conexão no Bling e apaga o token local, nesta ordem invertida
     * de prioridade: a tentativa ao Bling é best-effort, mas a limpeza local
     * acontece SEMPRE. Desconectar é ação do nosso lado — se dependesse do
     * Bling estar no ar, o cliente ficaria com um token vivo que não
     * conseguimos apagar (T-184-23, risco aceito).
     */
    public function revogar(BlingConexao $conexao): void
    {
        $erro = null;

        try {
            [$clientId, $clientSecret] = $this->credenciais();

            $response = Http::asForm()->withHeaders([
                'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                'enable-jwt' => '1',
                'Accept' => 'application/json',
            ])->post(self::REVOKE_URL, [
                'token' => $conexao->access_token,
                'token_type_hint' => 'access_token',
            ]);

            if (! $response->successful()) {
                $erro = "HTTP {$response->status()}";
                Log::warning("[Bling] Revogação no Bling respondeu {$response->status()} para a conexão {$conexao->id} — token local apagado de qualquer forma.");
            }
        } catch (\Throwable $e) {
            Log::warning("[Bling] Falha ao revogar no Bling a conexão {$conexao->id}: {$e->getMessage()}");
            $erro = $e->getMessage();
        }

        // Independentemente do resultado acima: apaga o token local.
        $conexao->update([
            'access_token' => null,
            'refresh_token' => null,
            'status' => BlingConexao::STATUS_REVOGADA,
            'connected_at' => null,
            'last_error' => $erro,
            'last_error_at' => $erro !== null ? now() : $conexao->last_error_at,
        ]);
    }

    /**
     * Ponto único de acesso ao token válido — renova antes quando a conexão
     * está perto de expirar. É daqui que o `BlingClient` (plano 06) lê.
     */
    public function acessoValido(BlingConexao $conexao): string
    {
        if ($conexao->expiraEm(5)) {
            $conexao = $this->renovar($conexao);
        }

        return (string) $conexao->access_token;
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
