<?php

namespace App\Notifications;

/**
 * Movimento numa pendência do Analista de Conta dos Polos (TKT-0035): quem pediu é avisado quando
 * ela é concluída, devolvida ou cancelada, ou quando alguém comenta. Leva `url` para o sino abrir a
 * fila do Analista de Conta já na pendência.
 */
class PendenciaContaNotification extends BaseNotification
{
    public function __construct(string $titulo, string $mensagem, string $url, ?int $autorUserId, array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::PENDENCIA_CONTA,
            autorUserId: $autorUserId,
            url:         $url,
            meta:        $meta,
        );
    }
}
