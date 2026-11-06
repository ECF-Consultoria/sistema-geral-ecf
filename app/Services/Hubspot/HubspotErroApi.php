<?php

namespace App\Services\Hubspot;

/**
 * Fase 202 — erro tipado da API do HubSpot na renovação contratual.
 *
 * O tipo decide o que o orquestrador faz: limite/temporário => tentar de novo
 * depois; incerto => conciliar pela chave de correlação (NUNCA recriar às
 * cegas); permanente/conflito => parar e registrar.
 */
class HubspotErroApi extends \RuntimeException
{
    public const LIMITE      = 'limite';      // 429
    public const INCERTO     = 'incerto';     // timeout/conexão/5xx DEPOIS de enviar uma escrita
    public const PERMANENTE  = 'permanente';  // 4xx exceto 429/409
    public const TEMPORARIO  = 'temporario';  // 5xx/conexão em leitura após esgotar retries
    public const CONFLITO    = 'conflito';    // 409

    public function __construct(
        string $mensagem,
        public readonly string $tipo,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        ?\Throwable $anterior = null,
    ) {
        // Corpo truncado em 500 chars; nunca inclui token/Authorization.
        parent::__construct(mb_substr($mensagem, 0, 500 + 80), 0, $anterior);
    }

    public function ehIncerto(): bool
    {
        return $this->tipo === self::INCERTO;
    }

    public function ehLimite(): bool
    {
        return $this->tipo === self::LIMITE;
    }

    public function ehPermanente(): bool
    {
        return $this->tipo === self::PERMANENTE;
    }
}
