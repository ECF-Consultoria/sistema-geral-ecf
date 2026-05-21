<?php

namespace App\Notifications;

/**
 * Notificação automática disparada quando uma meta é atingida no período corrente
 * (setor / empresa / carteira) — cobre AUTO-04, AUTO-05 e AUTO-06 da Phase 11.
 *
 * Disparada pelos jobs `CalculateSetorGoalResults`, `CalculateGoalResults` e
 * `CalculatePortfolioGoalResults` imediatamente após `updateOrCreate` do result,
 * condicionada a `pct_achieved >= 100` (setor) ou `achieved === true` (empresa /
 * carteira) E `notificado_em IS NULL` no result — garante idempotência por
 * período de avaliação (D-01 do 11-01-PLAN.md).
 *
 * `autorUserId = null` (sistema) e `url = null` (sem deeplink no MVP) — mesma
 * regra da `MetaAtribuidaNotification`.
 */
class MetaAtingidaNotification extends BaseNotification
{
    /**
     * Construtor enxuto — apenas título, mensagem e meta opcional. A categoria
     * é fixa (`Categoria::META_ATINGIDA`) e os demais slots canônicos do
     * `BaseNotification` (`autorUserId`, `url`) vêm como `null`.
     */
    public function __construct(string $titulo, string $mensagem, ?array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::META_ATINGIDA,
            autorUserId: null,
            url:         null,
            meta:        $meta ?? [],
        );
    }
}
