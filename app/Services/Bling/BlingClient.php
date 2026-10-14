<?php

namespace App\Services\Bling;

use App\Models\BlingConexao;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 184 (BLING-15, BLING-16) — cliente HTTP da API v3 do Bling.
 *
 * Só os cinco endpoints de LEITURA que esta fase usa. Este cliente não tem
 * nenhum caminho equivalente a um verbo de escrita (os quatro que alteram
 * dado no Bling): a ausência é intencional, não uma omissão — escrever no
 * Bling é a Fase 185 e está fora do escopo declarado desta fase (§3 do
 * CONTEXT). A guarda que prova isso é um `grep` que conta zero ocorrências
 * de `->` seguido imediatamente por um desses quatro verbos e `(` no
 * arquivo, por isso este docblock evita deliberadamente escrever esse par
 * de caracteres junto ao nome de qualquer um deles.
 *
 * Toda chamada passa por `pedir()`, que:
 * - chama `LimitadorDoBling::aguardarVez()` ANTES de cada requisição —
 *   nunca depois, e nunca pulando numa segunda tentativa;
 * - lê o token por `BlingOAuthService::acessoValido()`, o ponto único de
 *   leitura (plano 04);
 * - manda `Authorization: Bearer {token}` (via `Http::withToken()`),
 *   `enable-jwt: 1` e `Accept: application/json`, sempre para
 *   `https://api.bling.com.br/Api/v3{caminho}`;
 * - num 401, renova o token **uma única vez** (guarda `$jaRenovou`) e repete
 *   a MESMA requisição mais uma vez. Sem essa guarda, um token que o Bling
 *   recusa por inadimplência ou permissão perdida (`UNAUTHORIZED_ERROR`)
 *   viraria um laço de renovação — e 20 chamadas a `/oauth/token` em 60 s
 *   bloqueiam o IP da VPS por 60 min, para TODOS os clientes;
 * - num 429, nunca retenta — nem na hora, nem com qualquer mecanismo de
 *   retentativa automática do cliente HTTP. 600 req em 10 s bloqueiam o
 *   mesmo IP por 10 min;
 * - num 403 com `insufficient_scope`, devolve uma mensagem que deixa claro
 *   que mudar o escopo no cadastro do app REVOGA todos os clientes já
 *   conectados — essa decisão é do usuário (D-03 do CONTEXT), nunca algo
 *   para o executor "corrigir" sozinho ao ver o erro;
 * - em qualquer outro erro (5xx e demais), alimenta o balde de erros do
 *   limitador antes de lançar a exceção.
 *
 * Nenhuma mensagem de log daqui carrega o `access_token`/`refresh_token`: o
 * corpo da resposta de erro passa por `semSegredo()` antes de ser logado —
 * o corpo não deveria trazer token nenhum, mas esta troca existe para não
 * depender disso (mesma defesa de `BlingOAuthService::semSegredo()`,
 * duplicada aqui porque este plano não modifica aquele arquivo).
 */
class BlingClient
{
    private const API_BASE = 'https://api.bling.com.br/Api/v3';

    /**
     * Quantos ids cabem por chamada em `/estoques/saldos` é a suposição 3 do
     * RESEARCH §5 (não medida — estimativa de 50 a 100). 50 é o CHÃO dessa
     * estimativa, escolhido de propósito: começar conservador e confirmar na
     * conta de teste (checkpoint 184-13) é mais seguro do que descobrir o
     * teto real tomando um 400/414 em produção.
     */
    public const IDS_POR_CHAMADA_PADRAO = 50;

    /** Guarda o status HTTP da última resposta, para os fallbacks de `produtos()`/`saldos()`. */
    private ?int $ultimoStatusHttp = null;

    public function __construct(
        private readonly BlingOAuthService $oauth,
        private readonly LimitadorDoBling $limitador,
    ) {}

    /** `GET /empresas/me/dados-basicos` — id da empresa no Bling e o CNPJ. */
    public function dadosBasicos(BlingConexao $conexao): array
    {
        return $this->pedir($conexao, 'GET', '/empresas/me/dados-basicos');
    }

    /**
     * `GET /produtos` — listagem RESUMIDA (a ficha completa só vem de
     * `produto()`). Não vem total na resposta: quem itera (plano 08) para na
     * página vazia ou menor que `$limite`.
     *
     * O máximo de `$limite` não é documentado — outra suposição não medida.
     * Padrão 100; se o Bling recusar com 400, cai para 50 UMA ÚNICA vez e
     * registra o aviso (não insiste de novo se cair de novo).
     */
    public function produtos(BlingConexao $conexao, int $pagina, int $limite = 100, array $extra = []): array
    {
        $query = array_merge([
            'pagina' => $pagina,
            'limite' => $limite,
            'criterio' => 2, // ativos
        ], $extra);

        try {
            return $this->pedir($conexao, 'GET', '/produtos', $query);
        } catch (\RuntimeException $e) {
            if ($this->ultimoStatusHttp === 400 && $limite > 50) {
                Log::warning("[Bling] /produtos recusou o limite {$limite} (400) — tentando uma única vez com 50.");

                return $this->pedir($conexao, 'GET', '/produtos', array_merge($query, ['limite' => 50]));
            }

            throw $e;
        }
    }

    /** `GET /produtos/{id}` — a ficha completa. A listagem resumida de `produtos()` não serve para isso. */
    public function produto(BlingConexao $conexao, string $id): array
    {
        return $this->pedir($conexao, 'GET', '/produtos/'.$id);
    }

    /**
     * `GET /estoques/saldos?idsProdutos[]=…` — saldo físico e virtual por
     * produto e por depósito.
     *
     * Quantos ids cabem por chamada é, de novo, a suposição 3 do RESEARCH §5
     * (`IDS_POR_CHAMADA_PADRAO`). Quem decide em quantos blocos quebrar uma
     * lista grande é o chamador (plano 09) — aqui, se o Bling recusar o lote
     * recebido com 400 ou 414 ("URI too long"), tenta UMA ÚNICA vez com só os
     * 25 primeiros ids e registra o aviso, em vez de insistir com o lote
     * inteiro outra vez.
     */
    public function saldos(BlingConexao $conexao, array $idsProdutos): array
    {
        try {
            return $this->pedir($conexao, 'GET', '/estoques/saldos', ['idsProdutos' => $idsProdutos]);
        } catch (\RuntimeException $e) {
            if (in_array($this->ultimoStatusHttp, [400, 414], true) && count($idsProdutos) > 25) {
                Log::warning('[Bling] /estoques/saldos recusou '.count($idsProdutos)." ids ({$this->ultimoStatusHttp}) — tentando uma única vez com os primeiros 25.");

                return $this->pedir($conexao, 'GET', '/estoques/saldos', ['idsProdutos' => array_slice($idsProdutos, 0, 25)]);
            }

            throw $e;
        }
    }

    /** `GET /depositos` — flags `padrao` e `desconsiderarSaldo` de cada depósito do cliente. */
    public function depositos(BlingConexao $conexao): array
    {
        return $this->pedir($conexao, 'GET', '/depositos');
    }

    /**
     * Ponto único de disparo HTTP. `$jaRenovou` é a trava do 401: no máximo
     * UMA renovação por requisição original, nunca mais.
     *
     * @throws \RuntimeException
     */
    private function pedir(BlingConexao $conexao, string $metodo, string $caminho, array $query = [], bool $jaRenovou = false): array
    {
        $contaId = (string) ($conexao->bling_empresa_id ?: $conexao->id);
        $this->limitador->aguardarVez($contaId);

        $token = $this->oauth->acessoValido($conexao);

        try {
            $resposta = Http::withToken($token)
                ->withHeaders([
                    'enable-jwt' => '1',
                    'Accept' => 'application/json',
                ])
                ->timeout(30)
                ->get(self::API_BASE.$caminho, $query);
        } catch (ConnectionException $e) {
            $this->limitador->registrarErro();
            Log::warning("[Bling] Falha de conexão em {$metodo} {$caminho}: ".self::semSegredo($e->getMessage()));

            throw new \RuntimeException('[Bling] Erro de conexão ao chamar o Bling — tente novamente mais tarde.');
        }

        $this->ultimoStatusHttp = $resposta->status();

        if ($resposta->status() === 401) {
            if (! $jaRenovou) {
                $this->oauth->renovar($conexao);

                return $this->pedir($conexao, $metodo, $caminho, $query, jaRenovou: true);
            }

            // Segunda recusa, mesmo depois de renovar: não insiste uma terceira
            // vez. Insistir aqui é o caminho mais curto para o bloqueio de IP
            // de 60 min (20 chamadas a /oauth/token em 60s).
            $this->limitador->registrarErro();
            Log::warning("[Bling] 401 mesmo após renovar o token em {$caminho} — sem nova tentativa.");

            throw new \RuntimeException('[Bling] Autorização recusada pelo Bling mesmo após renovar o token — o cliente provavelmente precisa reautorizar.');
        }

        if ($resposta->status() === 429) {
            $this->limitador->registrarErro();

            $erro = $resposta->json('error') ?? [];
            $limit = $erro['limit'] ?? $erro['description']['limit'] ?? null;
            $period = $erro['period'] ?? $erro['description']['period'] ?? null;

            Log::warning("[Bling] 429 TOO_MANY_REQUESTS em {$caminho} (limit={$limit}, period={$period}) — sem nova tentativa.");

            throw new \RuntimeException("[Bling] Limite de chamadas do Bling atingido (TOO_MANY_REQUESTS, period={$period}) — espere antes de tentar de novo, sem insistir agora.");
        }

        if ($resposta->status() === 403 && stripos($resposta->body(), 'insufficient_scope') !== false) {
            Log::warning("[Bling] 403 insufficient_scope em {$caminho}.");

            throw new \RuntimeException('[Bling] O app do Bling não tem o escopo necessário para esta chamada. Mudar o escopo no cadastro do app revoga TODOS os clientes já conectados — essa é uma decisão do usuário, não algo para corrigir sozinho a partir deste erro.');
        }

        if (! $resposta->successful()) {
            $this->limitador->registrarErro();
            Log::warning("[Bling] Erro {$resposta->status()} em {$metodo} {$caminho}: ".self::semSegredo($resposta->body()));

            throw new \RuntimeException("[Bling] Erro {$resposta->status()} ao chamar o Bling em {$caminho}.");
        }

        return $resposta->json('data') ?? $resposta->json() ?? [];
    }

    /**
     * Troca sequências longas de caracteres (o formato de um JWT ou de um
     * refresh token do Bling) por um marcador, antes de logar o corpo de uma
     * resposta de erro. Duplicado de `BlingOAuthService::semSegredo()` — este
     * plano não modifica aquele arquivo.
     */
    private static function semSegredo(string $corpo): string
    {
        $limpo = preg_replace('/[A-Za-z0-9_\-]{24,}(?:\.[A-Za-z0-9_\-]{10,}){0,2}/', '[token]', $corpo);

        return $limpo ?? $corpo;
    }
}
