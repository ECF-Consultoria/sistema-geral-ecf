<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Support\Publicador\Erros\RespostaMl;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * As chamadas do Publicador ao ML. Devolve a resposta classificada
 * (`RespostaMl`) em vez de lançar — o orquestrador decide por classe (`09` §2).
 *
 * Reaproveita o token do `MercadoLivreService` (renovação com lock por conta e
 * gravação atômica do refresh, que é de uso único — RN-01) sem mudar o
 * `write()` de lá, do qual outros módulos dependem.
 *
 * - 401: renova o token UMA vez e repete; `invalid_grant` → conta a reconectar (V-ACC-01).
 * - 429: espera (Retry-After ou 1, 2, 4, 8, 16 s) e repete.
 * - 5xx e rede: repete só quando `$repetir` (leitura, validate). O `POST /items`
 *   passa `false`: repetir às cegas poderia criar anúncio duplicado (RN-93).
 *
 * Nunca registra token em log; registra método, caminho, status e códigos.
 */
class ClienteMlPublicador
{
    private const API = 'https://api.mercadolibre.com';

    /** @var \Closure(int): void */
    private \Closure $dormir;

    public function __construct(
        private MercadoLivreService $ml,
        private MlColetaService $coleta,
        ?\Closure $dormir = null,
    ) {
        $this->dormir = $dormir ?? fn (int $segundos) => sleep($segundos);
    }

    /** Chamada com o token da EMPRESA. */
    public function daConta(Company $empresa, string $metodo, string $caminho, array $query = [], ?array $corpo = null, bool $repetir = true): RespostaMl
    {
        return $this->executar($empresa, $metodo, $caminho, $repetir,
            fn (string $token) => $this->enviar($token, $metodo, $caminho, $query, $corpo));
    }

    /**
     * Sobe UMA foto (`POST /pictures/items/upload`, multipart — `06` §7). O
     * limite por minuto do ML aparece como 429 ou 400 `bad_request` (`06` §7):
     * o 429 é repetido aqui; o resto volta para quem chamou.
     */
    public function enviarFoto(Company $empresa, string $conteudo, string $nome): RespostaMl
    {
        return $this->executar($empresa, 'POST', '/pictures/items/upload', true, function (string $token) use ($conteudo, $nome) {
            try {
                $resp = Http::withToken($token)->acceptJson()->timeout((int) config('publicador.timeout_segundos', 30))
                    ->attach('file', $conteudo, $nome)->post(self::API.'/pictures/items/upload');

                return new RespostaMl($resp->status(), $resp->json() ?? ($resp->body() === '' ? null : $resp->body()));
            } catch (ConnectionException $e) {
                return new RespostaMl(0, ['erro_de_rede' => $e->getMessage()]);
            }
        });
    }

    /** O laço comum: token válido, UMA renovação em 401, novas tentativas por classe, registro sem token. */
    private function executar(Company $empresa, string $metodo, string $caminho, bool $repetir, \Closure $envio): RespostaMl
    {
        $token = $this->tokenValido($empresa);
        $renovou = false;

        for ($tentativa = 1; ; $tentativa++) {
            $resposta = $envio($token->access_token);

            if ($resposta->classe === RespostaMl::AUTH && ! $renovou) {
                $token = $this->renovar($token);
                $renovou = true;

                continue;
            }
            if (! $this->repetir($resposta, $tentativa, $repetir)) {
                break;
            }
        }

        $this->registrar($metodo, $caminho, $resposta);

        return $resposta;
    }

    /** Chamada pública (categorias, atributos, tarifas) com o APP token — nenhum token de cliente envolvido. */
    public function publico(string $caminho, array $query = []): RespostaMl
    {
        $token = $this->coleta->getAppToken();
        for ($tentativa = 1; ; $tentativa++) {
            $resposta = $this->enviar($token, 'GET', $caminho, $query, null);
            if (! $this->repetir($resposta, $tentativa, true)) {
                break;
            }
        }
        $this->registrar('GET', $caminho, $resposta);

        return $resposta;
    }

    /** O token da empresa, renovado com folga (`09` §6). Sem token ativo: a conta precisa ser reconectada. */
    private function tokenValido(Company $empresa): MlToken
    {
        $token = $this->ml->ensureValidToken($empresa);
        if (! $token) {
            throw self::desconectada();
        }
        if ($token->expiresSoon((int) config('publicador.renovar_token_minutos', 10))) {
            $token = $this->renovar($token);
        }

        return $token;
    }

    private function renovar(MlToken $token): MlToken
    {
        try {
            return $this->ml->refreshToken($token);
        } catch (\RuntimeException $e) {
            // invalid_grant: o MercadoLivreService já marcou o token como revogado.
            if ($token->fresh()?->status === 'revoked') {
                throw self::desconectada();
            }
            throw new RegraViolada('V-ACC-01', 'Não foi possível renovar o acesso ao Mercado Livre agora. Tente de novo em instantes.');
        }
    }

    private function enviar(string $token, string $metodo, string $caminho, array $query, ?array $corpo): RespostaMl
    {
        try {
            $req = Http::withToken($token)->acceptJson()->timeout((int) config('publicador.timeout_segundos', 30));
            $url = self::API.$caminho.($query && $metodo !== 'GET' ? '?'.http_build_query($query) : '');
            $resp = $metodo === 'GET' ? $req->get($url, $query) : $req->send($metodo, $url, ['json' => $corpo ?? []]);

            $retryAfter = $resp->header('Retry-After');

            return new RespostaMl($resp->status(), $resp->json() ?? ($resp->body() === '' ? null : $resp->body()), is_numeric($retryAfter) ? (int) $retryAfter : null);
        } catch (ConnectionException $e) {
            return new RespostaMl(0, ['erro_de_rede' => $e->getMessage()]);
        }
    }

    private function repetir(RespostaMl $r, int $tentativa, bool $repetirFalhaDeServidor): bool
    {
        $limite = match ($r->classe) {
            RespostaMl::RATE_LIMIT => (int) config('publicador.tentativas_429', 5),
            RespostaMl::SERVER, RespostaMl::NETWORK => $repetirFalhaDeServidor ? (int) config('publicador.tentativas_5xx', 3) : 0,
            default => 0,
        };
        if ($tentativa > $limite) {
            return false;
        }

        $espera = min((int) config('publicador.espera_maxima_segundos', 16), $r->retryAfter ?? 2 ** ($tentativa - 1));
        ($this->dormir)($espera);

        return true;
    }

    private function registrar(string $metodo, string $caminho, RespostaMl $r): void
    {
        if ($r->classe === RespostaMl::OK) {
            return;
        }
        $codigos = implode(', ', array_column($r->causas, 'code'));
        Log::log($r->semErro() ? 'info' : 'warning', "[Publicador] {$metodo} {$caminho} → HTTP {$r->status} ({$r->classe})".($codigos !== '' ? " — {$codigos}" : ''));
    }

    private static function desconectada(): RegraViolada
    {
        return new RegraViolada('V-ACC-01', 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.');
    }
}
