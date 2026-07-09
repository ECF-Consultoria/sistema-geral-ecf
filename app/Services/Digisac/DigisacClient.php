<?php

namespace App\Services\Digisac;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente HTTP da API Digisac — v15.5.
 *
 * Encapsula 2 endpoints usados hoje pelo módulo NPS WhatsApp:
 *  - GET  /api/v1/whatsapp/groups/service/{serviceId} — lista grupos de uma conexão
 *  - POST /api/v1/messages                            — envia texto para um contactId (grupo ou individual)
 *
 * Configuração vem de `config/digisac.php` (base_url, token, timeout, origin).
 * Erros HTTP não-2xx lançam RuntimeException com contexto de status e body para
 * o consumidor (NpsDigisacDispatchService) capturar e persistir no log de envio.
 *
 * Não retenta automaticamente — o comando `nps:disparar-mensal` roda 1x/dia por
 * empresa e o retry manual pelo admin é preferível a estourar rate limit da API.
 *
 * @see config/digisac.php
 * @see app/Services/Digisac/NpsDigisacDispatchService.php
 */
class DigisacClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $token = null,
        private readonly ?int $timeout = null,
        private readonly ?string $origin = null,
    ) {}

    /**
     * Lista grupos WhatsApp de uma conexão específica.
     *
     * A API Digisac retorna contatos com `isGroup: true` — este método já filtra
     * e devolve apenas grupos, no shape reduzido `{id, name}` que a UI de
     * mapeamento consome (evita expor payload completo).
     *
     * @return array<int, array{id: string, name: string, raw?: array}>
     * @throws RuntimeException quando token/base_url ausentes ou API responde erro
     */
    public function listGroups(string $serviceId): array
    {
        $this->guardCredentials();

        if (trim($serviceId) === '') {
            throw new RuntimeException('[Digisac] serviceId obrigatorio para listar grupos');
        }

        $url = $this->resolveBaseUrl() . '/api/v1/whatsapp/groups/service/' . rawurlencode($serviceId);

        try {
            $response = Http::withToken($this->resolveToken())
                ->timeout($this->resolveTimeout())
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('[Digisac] falha de conexao ao listar grupos: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $body   = $this->truncateForError($response->body());
            throw new RuntimeException("[Digisac] listGroups HTTP {$status}: {$body}");
        }

        $json = $response->json();
        // A resposta pode vir como array direto ou dentro de `data` — normalizamos.
        $items = is_array($json)
            ? ($json['data'] ?? $json ?? [])
            : [];

        $groups = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            // isGroup=true é o marcador oficial de grupo WhatsApp.
            $isGroup = (bool) ($item['isGroup'] ?? false);
            if (! $isGroup) {
                continue;
            }
            $id   = (string) ($item['id'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($id === '') {
                continue;
            }
            $groups[] = [
                'id'   => $id,
                'name' => $name !== '' ? $name : '(sem nome)',
                'raw'  => $item,
            ];
        }

        return $groups;
    }

    /**
     * Envia mensagem de texto para um contactId (grupo ou individual).
     *
     * Payload conforme especificação: `text`, `type=chat`, `contactId`, `userId`, `origin`.
     * Retorna array com `provider_message_id` (id retornado quando disponível) e
     * `raw` (payload de resposta completo — útil para debug e persistência no metadata).
     *
     * @return array{provider_message_id: ?string, raw: array}
     * @throws RuntimeException quando token/base_url ausentes ou API responde erro
     */
    public function sendMessage(string $contactId, string $text, string $userId, string $serviceId): array
    {
        $this->guardCredentials();

        if (trim($contactId) === '' || trim($text) === '') {
            throw new RuntimeException('[Digisac] contactId e text obrigatorios para envio');
        }

        $url = $this->resolveBaseUrl() . '/api/v1/messages';

        // O `serviceId` não é obrigatório no payload de /messages segundo a doc,
        // mas manter garante que a Digisac roteie pela conexão correta quando a
        // conta tem várias. Enviamos apenas se não vazio.
        $payload = [
            'text'      => $text,
            'type'      => 'chat',
            'contactId' => $contactId,
            'userId'    => $userId,
            'origin'    => $this->resolveOrigin(),
        ];
        if (trim($serviceId) !== '') {
            $payload['serviceId'] = $serviceId;
        }

        try {
            $response = Http::withToken($this->resolveToken())
                ->timeout($this->resolveTimeout())
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('[Digisac] falha de conexao ao enviar mensagem: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            $body   = $this->truncateForError($response->body());
            throw new RuntimeException("[Digisac] sendMessage HTTP {$status}: {$body}");
        }

        $json = $response->json();
        $raw  = is_array($json) ? $json : ['_body' => $response->body()];

        // Digisac normalmente retorna id em `id` ou em `data.id`; tentamos ambos.
        $providerId = null;
        if (is_array($json)) {
            $providerId = $json['id']
                ?? ($json['data']['id'] ?? null);
            if ($providerId !== null) {
                $providerId = (string) $providerId;
            }
        }

        return [
            'provider_message_id' => $providerId,
            'raw'                 => $raw,
        ];
    }

    /**
     * Garante que existe token + base_url — sem isso o cliente não pode operar.
     * Levanta RuntimeException com mensagem clara para aparecer no log de envio.
     */
    private function guardCredentials(): void
    {
        if ($this->resolveBaseUrl() === '' || $this->resolveToken() === '') {
            throw new RuntimeException('[Digisac] DIGISAC_BASE_URL e DIGISAC_TOKEN obrigatorios em config/digisac.php');
        }
    }

    private function resolveBaseUrl(): string
    {
        $url = $this->baseUrl ?? (string) config('digisac.base_url', '');
        return rtrim($url, '/');
    }

    private function resolveToken(): string
    {
        return (string) ($this->token ?? config('digisac.token', ''));
    }

    private function resolveTimeout(): int
    {
        return (int) ($this->timeout ?? config('digisac.timeout', 15));
    }

    private function resolveOrigin(): string
    {
        return (string) ($this->origin ?? config('digisac.origin', 'bot'));
    }

    /**
     * Trunca payload de erro para caber no `erro_msg` (TEXT 65k), preservando
     * início do body onde geralmente está a mensagem principal do erro Digisac.
     */
    private function truncateForError(string $body): string
    {
        if (strlen($body) > 1000) {
            return substr($body, 0, 1000) . '…[truncated]';
        }
        return $body;
    }
}
