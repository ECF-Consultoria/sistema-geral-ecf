<?php

namespace App\Notifications;

/**
 * "Publicado, aguardando alavancas": um produto saiu pelo Publicador e a tarefa pós-publicação
 * nasceu (09/10/2026). Leva `url` para o sino abrir a tarefa direto na fila.
 */
class TarefaAlavancasNotification extends BaseNotification
{
    public function __construct(string $titulo, string $mensagem, string $url, ?int $autorUserId, array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::TAREFA_ALAVANCAS,
            autorUserId: $autorUserId,
            url:         $url,
            meta:        $meta,
        );
    }
}
