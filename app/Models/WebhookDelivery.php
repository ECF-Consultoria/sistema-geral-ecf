<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model de auditoria e idempotência dos webhooks recebidos do ECF Drive (Phase 26).
 *
 * Cada linha representa um webhook recebido e validado. O campo `event_id` é UNIQUE
 * no banco, garantindo que o mesmo evento nunca seja processado duas vezes — mesmo
 * que o ECF Drive retente a entrega (RFC webhook: até 5 tentativas com backoff).
 *
 * `payload` é o body JSON completo da requisição recebida. O secret HMAC NUNCA é
 * incluído aqui nem em nenhum log estruturado (D-10 do PLAN).
 *
 * Sem LogsActivity intencionalmente: auditoria fica no canal de log estruturado
 * `ecf-webhooks` (D-G do PLAN). Adicionar LogsActivity aqui geraria entrada
 * duplicada no activity_log sem valor extra para debugging.
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_type',
        'payload',
        'signature_valid',
        'received_at',
        'processed_at',
        'status',
        'error_message',
        'ip_address',
    ];

    /**
     * Casts automáticos para hidratação correta dos campos.
     *
     * `payload` → array: Laravel faz round-trip JSON automaticamente.
     * `signature_valid` → bool: coluna boolean do MySQL.
     * `received_at` / `processed_at` → Carbon: facilita comparações de data.
     */
    protected function casts(): array
    {
        return [
            'payload'        => 'array',
            'signature_valid' => 'boolean',
            'received_at'    => 'datetime',
            'processed_at'   => 'datetime',
        ];
    }
}
