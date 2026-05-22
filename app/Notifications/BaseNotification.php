<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Classe abstrata base de todas as Notifications do MVP v3.0.
 *
 * Trava `via()` em `['database']` (canal único — mail/broadcast estão Out of
 * Scope da v3.0, ver 08-CONTEXT.md D-01) e expõe um payload canônico de 6
 * chaves em `toArray()` que o `DatabaseChannel` consome direto via fallback
 * (vendor `Illuminate\Notifications\Channels\DatabaseChannel::getData()`
 * linhas 54–67 — se `toDatabase()` não existe, usa `toArray()`).
 *
 * Subclasses concretas (`ManualNotification`, `MetaAtribuidaNotification`,
 * `MetaAtingidaNotification`) saem nas Phases 11 (auto) e 12 (manual) — D-04
 * deste plano proíbe criar subclasses nesta fase. O smoke test desta phase
 * usa uma classe anônima inline (`new class(...) extends BaseNotification {};`).
 *
 * Activity log NÃO é aplicado aqui (D-06) — o model nativo
 * `Illuminate\Notifications\DatabaseNotification` é usado direto, sem trait
 * `LogsActivity`. O log das criações manuais fica nas dispatch sites da
 * Phase 12 (REQ POLL-05).
 */
abstract class BaseNotification extends Notification
{
    /**
     * Construtor canônico — 6 parâmetros via PHP 8 property promotion (D-02).
     *
     * Os defaults garantem que subclasses concretas possam ser instanciadas
     * com apenas os 3 campos obrigatórios (`titulo`, `mensagem`, `categoria`)
     * e ainda assim o `toArray()` retorne as 6 chaves canônicas — `meta`
     * defaulta a `[]` (NUNCA `null`) para preservar shape estável no consumidor
     * (Phase 9 lê `$row->data['meta']` sem precisar de coalesce).
     */
    public function __construct(
        public string $titulo,
        public string $mensagem,
        public Categoria $categoria,
        public ?int $autorUserId = null,
        public ?string $url = null,
        public array $meta = [],
    ) {}

    /**
     * Canal único do MVP: apenas database.
     *
     * `via()` é fixo intencionalmente — mail/broadcast estão Out of Scope da
     * v3.0. Subclasses concretas das Phases 11/12 NÃO devem sobrescrever este
     * método; a separação por canal é uma decisão de plataforma, não de uso.
     *
     * @return array{0:string}
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Payload canônico — 6 chaves SEMPRE presentes.
     *
     * Consumido pelo `DatabaseChannel::getData()` (vendor linhas 54–67) e
     * gravado na coluna `data` da tabela `notifications`. O cast `array` do
     * `DatabaseNotification` faz o round-trip JSON automaticamente.
     *
     * Importante:
     *   - `categoria` retorna a string (`$this->categoria->value`) — enums
     *     não são JSON-serializáveis de forma estável sem `->value` explícito.
     *   - `meta` é sempre `array` (defaulta a `[]`, NUNCA `null`) — Phase 9
     *     espera shape fixa no read path.
     *
     * @return array{titulo:string,mensagem:string,categoria:string,autor_user_id:?int,url:?string,meta:array}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'titulo'        => $this->titulo,
            'mensagem'      => $this->mensagem,
            'categoria'     => $this->categoria->value,
            'autor_user_id' => $this->autorUserId,
            'url'           => $this->url,
            'meta'          => $this->meta,
        ];
    }
}
