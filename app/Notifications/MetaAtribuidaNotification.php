<?php

namespace App\Notifications;

/**
 * Notificação automática disparada quando uma meta é atribuída a algum escopo
 * (setor / empresa / carteira) — cobre AUTO-01, AUTO-02 e AUTO-03 da Phase 11.
 *
 * Recebe `titulo`, `mensagem` e `meta` opcional do dispatch site (hooks
 * `static::created` em `SetorGoal`, `Goal` e `PortfolioGoal`) e delega ao
 * `BaseNotification` com `categoria = META_ATRIBUIDA`, `autorUserId = null`
 * (sistema, não um user humano — D-04 do 11-01-PLAN.md) e `url = null`
 * (MVP não usa deeplink por notificação — D-05).
 *
 * O canal e o payload canônico de 6 chaves vêm 100% do parent — esta classe
 * só fixa a categoria.
 */
class MetaAtribuidaNotification extends BaseNotification
{
    /**
     * Construtor enxuto — apenas título, mensagem e meta opcional. A categoria
     * é fixa (`Categoria::META_ATRIBUIDA`) e os demais slots canônicos do
     * `BaseNotification` (`autorUserId`, `url`) vêm como `null`.
     */
    public function __construct(string $titulo, string $mensagem, ?array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::META_ATRIBUIDA,
            autorUserId: null,
            url:         null,
            meta:        $meta ?? [],
        );
    }
}
