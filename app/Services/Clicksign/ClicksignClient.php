<?php

namespace App\Services\Clicksign;

use App\Exceptions\ClicksignException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 126 Plan 126-01 (CLICK-01) — client HTTP para a API Clicksign v3
 * (conceito de Envelope). Esta fase cobre a fundação — headers, retry, log
 * seguro — já exercitada pelas duas primeiras chamadas do fluxo
 * (`criarEnvelope()` + `anexarDocumento()`). A Fase 126-02 acrescenta
 * signatário, requisito, notificação, consulta e cancelamento sobre o mesmo
 * núcleo privado `enviar()` — se ele nasce seguro, os demais métodos herdam
 * a garantia.
 *
 * Referência: `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` — respostas
 * reais medidas contra o sandbox, com precedência sobre a doc oficial (dois
 * pontos dela estavam errados: prefixo do token no header e formato do
 * `content_base64`).
 */
class ClicksignClient
{
    private ?string $token;
    private ?string $baseUrl;

    /**
     * Token e base URL são injetáveis para teste; fallback para
     * `config('services.clicksign.*')` — nunca `env()` direto aqui.
     */
    public function __construct(?string $token = null, ?string $baseUrl = null)
    {
        $this->token   = $token ?? config('services.clicksign.access_token');
        $this->baseUrl = $baseUrl ?? config('services.clicksign.base_url');
    }

    /**
     * POST /envelopes — cria o envelope. Devolve o bloco `data` da resposta.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    public function criarEnvelope(array $atributos): array
    {
        return $this->enviar('post', '/envelopes', [
            'data' => [
                'type'       => 'envelopes',
                'attributes' => $atributos,
            ],
        ], 'criar envelope');
    }

    /**
     * POST /envelopes/{envelopeId}/documents — anexa o PDF ao envelope.
     * `content_base64` sai como Data URI completo (§2 do empírico — a doc
     * oficial exemplifica base64 puro, e isso dá 400 real). Antes de
     * qualquer requisição, guarda o limite de tamanho (gate #5, NÃO MEDIDO).
     *
     * @return array<string, mixed>
     */
    public function anexarDocumento(string $envelopeId, string $nomeArquivo, string $pdfBinario): array
    {
        $limite = (int) config('services.clicksign.max_upload_bytes');

        if ($limite > 0 && strlen($pdfBinario) > $limite) {
            throw new ClicksignException(
                "O PDF do contrato excede o limite de upload aceito pela Clicksign ({$limite} bytes)."
            );
        }

        $conteudo = 'data:application/pdf;base64,' . base64_encode($pdfBinario);

        return $this->enviar('post', "/envelopes/{$envelopeId}/documents", [
            'data' => [
                'type'       => 'documents',
                'attributes' => [
                    'filename'       => $nomeArquivo,
                    'content_base64' => $conteudo,
                ],
            ],
        ], 'anexar documento');
    }

    /**
     * Monta a requisição base: headers exatos do §1 do empírico + timeout de
     * 30s. NÃO usa `Http::withToken()` — esse helper do Laravel sempre
     * prefixa "Bearer " no Authorization, e a Clicksign devolve 401 com o
     * prefixo (medido). O Content-Type é fixado via `contentType()` DEPOIS
     * de `withHeaders()` de propósito: o Laravel já seta
     * 'Content-Type' => 'application/json' no construtor do PendingRequest
     * (via `asJson()`), e `withHeaders()` faz `array_merge_recursive` — se o
     * Content-Type entrasse dentro do array de `withHeaders()`, a mesma
     * chave em ambos os lados viraria um array de dois valores em vez de
     * sobrescrever. `contentType()` sempre atribui direto, sem esse risco.
     */
    private function baseRequest(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->token,
            'Accept'        => 'application/vnd.api+json',
        ])->contentType('application/vnd.api+json')->timeout(30);
    }

    /**
     * Ponto único por onde toda chamada ao client passa: retry disciplinado
     * (D-11 — só em 429, 5xx e falha de conexão, nunca em 4xx de dado, no
     * máximo 3 tentativas com espera curta e crescente, teto bem abaixo de
     * 5s — o client roda dentro de um job da fila, D-14), log seguro
     * (T-126-01 — só `contexto`/`status`/`codigo`/`ponteiro`, nunca a
     * resposta ou a exceção inteiras) e conversão para `ClicksignException`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enviar(string $metodo, string $caminho, array $payload, string $contexto): array
    {
        $url = rtrim((string) $this->baseUrl, '/') . '/' . ltrim($caminho, '/');

        $res = $this->baseRequest()
            ->retry(3, static fn (int $tentativa) => $tentativa * 200, function (\Throwable $excecao) {
                if ($excecao instanceof ConnectionException) {
                    return true;
                }

                if ($excecao instanceof RequestException && $excecao->response !== null) {
                    $status = $excecao->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            }, false)
            ->send($metodo, $url, empty($payload) ? [] : ['json' => $payload]);

        if ($res->successful()) {
            Log::channel('ecf-webhooks')->info('[Clicksign] ' . $contexto, [
                'contexto' => $contexto,
                'status'   => $res->status(),
                'id'       => $res->json('data.id'),
            ]);

            return $res->json('data') ?? [];
        }

        // NUNCA logar $res, $res->body(), $res->json() inteiro nem o payload
        // enviado — a resposta de erro da Clicksign pode ecoar nome e e-mail
        // do signatário (achado WR-11 da Fase 125). Só os campos nomeados.
        $corpo    = $res->json() ?? [];
        $codigo   = $corpo['errors'][0]['code'] ?? null;
        $ponteiro = $corpo['errors'][0]['source']['pointer'] ?? null;

        Log::channel('ecf-webhooks')->warning('[Clicksign] Falha em ' . $contexto, [
            'contexto' => $contexto,
            'status'   => $res->status(),
            'codigo'   => $codigo,
            'ponteiro' => $ponteiro,
        ]);

        throw ClicksignException::fromResponse($res->status(), $corpo);
    }
}
