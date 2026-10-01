<?php

namespace App\Notifications;

/**
 * Aviso de movimento num chamado (novo, transferido, respondido, resolvido…).
 *
 * Diferente das notificações de meta, esta leva `url`: o sino abre o chamado
 * direto — na tela do solicitante ou na caixa de chamados da equipe dev.
 */
class ChamadoNotification extends BaseNotification
{
    public function __construct(string $titulo, string $mensagem, string $url, ?int $autorUserId, array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::CHAMADO,
            autorUserId: $autorUserId,
            url:         $url,
            meta:        $meta,
        );
    }
}
