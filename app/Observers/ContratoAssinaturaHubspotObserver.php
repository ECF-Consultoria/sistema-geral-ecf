<?php

namespace App\Observers;

use App\Jobs\AvancarDealHubspotJob;
use App\Models\ContratoAssinatura;

/**
 * ContratoAssinaturaHubspotObserver — Quick 261103-hsf (DEV-55).
 *
 * Avisa o HubSpot quando o contrato anda: `aguardando_assinaturas` (enviado
 * ao cliente) move o deal para "Contrato Enviado"; `assinado` move para
 * "Ganho". Observer e não chamada nos jobs porque o status muda em mais de
 * um caminho (webhook da Clicksign, reconciliação agendada) e todos passam
 * por `save()` do model — o mesmo motivo pelo qual o hook `saving()` do
 * model é o único lugar que alimenta a trava D-01.
 *
 * Com mais de um contrato na empresa (um por serviço), o PRIMEIRO assinado
 * já move para Ganho: cliente assinou = negócio ganho. O job só avança, então
 * os eventos seguintes da mesma empresa não fazem nada.
 */
class ContratoAssinaturaHubspotObserver
{
    public function updated(ContratoAssinatura $contrato): void
    {
        if (! $contrato->wasChanged('status')) {
            return;
        }

        $destino = match ($contrato->status) {
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS => AvancarDealHubspotJob::DESTINO_CONTRATO_ENVIADO,
            ContratoAssinatura::STATUS_ASSINADO               => AvancarDealHubspotJob::DESTINO_GANHO,
            default                                           => null,
        };

        if ($destino === null || $contrato->company_id === null) {
            return;
        }

        AvancarDealHubspotJob::dispatch((int) $contrato->company_id, $destino, $contrato->id);
    }
}
