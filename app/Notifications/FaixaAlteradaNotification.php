<?php

namespace App\Notifications;

/**
 * Notificação automática disparada quando uma empresa ou grupo muda de
 * faixa de cobrança no fechamento mensal (Fase 138, D-02).
 *
 * Quem dispara é o notificador do plano 05, ao FIM de
 * `fechamento:consolidar-mes`, para os admins (`User::where('role','admin')`).
 * A idempotência (não avisar de novo em "Refazer fechamento" quando nada
 * mudou) NÃO vive aqui — vive nas colunas `notificado_em` e
 * `notificado_faixa_ordem` de `fechamento_snapshots`/`fechamento_grupo_snapshots`
 * (Fase 138, D-03). Esta classe só monta o payload; quem decide se dispara
 * é o plano 05.
 *
 * `autorUserId = null` (sistema, sem autor) e `url = null` (sem deeplink,
 * mesma regra de `MetaAtingidaNotification` — a tela de notificações não
 * renderiza link hoje).
 */
class FaixaAlteradaNotification extends BaseNotification
{
    /**
     * Construtor enxuto — apenas título, mensagem e meta opcional. A
     * categoria é fixa (`Categoria::FAIXA_ALTERADA`) e os demais slots
     * canônicos do `BaseNotification` (`autorUserId`, `url`) vêm como `null`.
     */
    public function __construct(string $titulo, string $mensagem, ?array $meta = [])
    {
        parent::__construct(
            titulo:      $titulo,
            mensagem:    $mensagem,
            categoria:   Categoria::FAIXA_ALTERADA,
            autorUserId: null,
            url:         null,
            meta:        $meta ?? [],
        );
    }
}
