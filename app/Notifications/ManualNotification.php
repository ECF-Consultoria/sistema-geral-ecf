<?php

namespace App\Notifications;

/**
 * Notificação manual criada por usuário com permissão `notificacoes.criar`
 * (cobre ENVIO-01 a ENVIO-05 da Phase 12).
 *
 * Categoria fixa `Categoria::MANUAL`, `url` apontando para `/notificacoes`
 * (consumida pelo sino + página de histórico da Phase 10) e `autorUserId`
 * com o ID do user humano que disparou — diferente dos disparos automáticos
 * da Phase 11 que passam `autorUserId = null` (sistema).
 *
 * O canal e o payload canônico de 6 chaves vêm 100% do parent `BaseNotification`.
 */
class ManualNotification extends BaseNotification
{
    /**
     * Construtor enxuto — título, mensagem, autor humano (obrigatório) e meta
     * opcional. A categoria é fixa (`Categoria::MANUAL`) e `url` aponta para a
     * página de histórico (não há deeplink por notificação no MVP).
     */
    public function __construct(string $titulo, string $mensagem, int $autorUserId, ?array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::MANUAL,
            autorUserId: $autorUserId,
            url:         route('notificacoes.index'),
            meta:        $meta ?? [],
        );
    }
}
