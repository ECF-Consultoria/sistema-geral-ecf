<?php

namespace App\Support\Publicador\Erros;

/**
 * Uma resposta do ML, já classificada (`09` §1–2). Nunca lança: quem chama
 * decide o que fazer com cada classe. O corpo é guardado BRUTO (V11).
 *
 * | Classe          | Como se reconhece                                   | O que fazer                         |
 * |-----------------|-----------------------------------------------------|-------------------------------------|
 * | OK              | 2xx sem causas                                      | seguir                              |
 * | WARNING         | causas só `type: warning` (o validate dá 400 assim) | seguir e mostrar (H-24, 01/10)      |
 * | VALIDATION      | 4xx com causa `error` (ou `error` sem `cause`)      | mapear para o campo                 |
 * | AUTH            | 401                                                 | renovar o token 1 vez e repetir     |
 * | PERMISSION      | 403, ou 3250/`seller.unable_to_list`                | não repetir; resolver no ML         |
 * | RATE_LIMIT      | 429                                                 | esperar e repetir                   |
 * | SERVER          | 5xx                                                 | esperar; POST /items: reconciliar   |
 * | NETWORK         | sem resposta (status 0)                             | POST /items: UNKNOWN → reconciliar  |
 * | UNKNOWN_FORMAT  | corpo fora do padrão                                | guardar bruto; tratar como erro     |
 */
final class RespostaMl
{
    public const OK = 'OK';
    public const WARNING = 'WARNING';
    public const VALIDATION = 'VALIDATION';
    public const AUTH = 'AUTH';
    public const PERMISSION = 'PERMISSION';
    public const RATE_LIMIT = 'RATE_LIMIT';
    public const SERVER = 'SERVER';
    public const NETWORK = 'NETWORK';
    public const UNKNOWN_FORMAT = 'UNKNOWN_FORMAT';

    private const SEM_PERMISSAO = ['moderations.seller.not_authorized', 'seller.unable_to_list'];

    public readonly string $classe;

    /** @var list<array{cause_id: ?int, code: string, type: string, message: string, references: list<string>}> */
    public readonly array $causas;

    /** @param ?int $retryAfter  segundos do cabeçalho Retry-After (429), quando veio */
    public function __construct(
        public readonly int $status,
        public readonly mixed $corpo,
        public readonly ?int $retryAfter = null,
    ) {
        $this->causas = self::causas($corpo);
        $this->classe = $this->classificar();
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Aprovado pelo ML: nenhuma causa `error`. É o critério do validate (V-REM-01 revisada, D6/H-24). */
    public function semErro(): bool
    {
        return in_array($this->classe, [self::OK, self::WARNING], true);
    }

    /** Timeout ou 5xx depois de enviar: o item PODE ter sido criado (RN-93). */
    public function podeTerCriado(): bool
    {
        return in_array($this->classe, [self::NETWORK, self::SERVER], true);
    }

    public function erros(): array
    {
        return array_values(array_filter($this->causas, fn ($c) => $c['type'] !== 'warning'));
    }

    public function avisos(): array
    {
        return array_values(array_filter($this->causas, fn ($c) => $c['type'] === 'warning'));
    }

    private function classificar(): string
    {
        return match (true) {
            $this->status === 0 => self::NETWORK,
            $this->status === 401 => self::AUTH,
            $this->status === 429 => self::RATE_LIMIT,
            $this->status >= 500 => self::SERVER,
            $this->status === 403 || (bool) array_filter($this->causas, fn ($c) => in_array($c['code'], self::SEM_PERMISSAO, true)) => self::PERMISSION,
            $this->status >= 400 && ! is_array($this->corpo) => self::UNKNOWN_FORMAT,
            $this->erros() !== [] => self::VALIDATION,
            $this->status >= 400 && $this->causas === [] => self::UNKNOWN_FORMAT,
            $this->avisos() !== [] => self::WARNING,
            default => self::OK,
        };
    }

    /**
     * As causas no formato do `09` §1. O erro de corpo sem `cause`
     * (`{"error": "The fields [title] are invalid…", "message": "body.invalid_fields"}`)
     * vira uma causa `error` com o `message` como código.
     */
    private static function causas(mixed $corpo): array
    {
        if (! is_array($corpo)) {
            return [];
        }

        $causas = array_values(array_map(fn ($c) => [
            'cause_id' => isset($c['cause_id']) ? (int) $c['cause_id'] : null,
            'code' => (string) ($c['code'] ?? ''),
            'type' => strtolower((string) ($c['type'] ?? 'error')) === 'warning' ? 'warning' : 'error',
            'message' => (string) ($c['message'] ?? ''),
            'references' => array_values((array) ($c['references'] ?? [])),
        ], array_filter((array) ($corpo['cause'] ?? []), 'is_array')));

        if ($causas === [] && isset($corpo['error']) && ! in_array($corpo['error'], ['validation_error', null], true) && isset($corpo['message'])) {
            $causas[] = ['cause_id' => null, 'code' => (string) $corpo['message'], 'type' => 'error', 'message' => (string) $corpo['error'], 'references' => []];
        }

        return $causas;
    }
}
