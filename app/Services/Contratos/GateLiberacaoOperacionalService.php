<?php

namespace App\Services\Contratos;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaSignatario;

/**
 * GateLiberacaoOperacionalService — Fase 129 Plano 04 (CLICK-05, D7 do
 * REQUIREMENTS + Pitfall A do RESEARCH). A regra única que responde "esta
 * empresa pode entrar em operação por este serviço?".
 *
 * O achado que dita esta classe inteira: `status == "closed"` NÃO é
 * sinônimo de "todos assinaram". O evento `deadline` documentado pela
 * Clicksign (doc oficial, WebFetch 2026-08-12) fecha o envelope
 * automaticamente quando o prazo vence, DESDE QUE haja pelo menos uma
 * assinatura — e o campo `deadline_partial_signature_action` (default
 * `"closed"`) foi MEDIDO AO VIVO nesta sessão contra o sandbox
 * (`CLICKSIGN-SANDBOX-EMPIRICO.md` §5). As duas fontes convergem: um
 * envelope `closed` pode ter assinatura PARCIAL. Liberar por `closed`
 * sozinho colocaria em operação uma empresa cujo cliente nunca assinou.
 *
 * (a) "Estado agregado" (D7) significa o estado agregado DAQUELE envelope,
 * incluindo a granularidade de SIGNATÁRIO — nunca só o enum de topo
 * (`draft`/`running`/`closed`).
 * (b) A situação individual do signatário só existe no NOSSO banco
 * (`CLICKSIGN-SANDBOX-EMPIRICO.md` §4 — `GET /signers/{id}` não responde
 * "fulano assinou?"). Este service depende de `ContratoSignatariosSyncService`
 * ter rodado antes e populado `contrato_assinatura_signatarios.situacao`.
 * (c) Nenhuma decisão aqui olha o payload do evento do webhook — só o
 * envelope RECONSULTADO (D-06/D-07 da fase) e o nosso banco.
 *
 * Puro: não faz HTTP nem consulta a Clicksign. Quem chama (o job desta
 * fase, e futuramente o job de reconciliação da REDE-04 na Fase 130) já
 * reconsultou o envelope e passa o resultado pronto. Mantém a regra num
 * lugar só e testável sem rede.
 */
class GateLiberacaoOperacionalService
{
    /**
     * Avalia se o contrato pode liberar a empresa para o operacional.
     *
     * @param  array  $envelopeReconsultado  Recurso DESEMBRULHADO de
     *   `ClicksignClient::consultarEnvelope()` — `id`/`type`/`attributes`
     *   no TOPO, NÃO dentro de `data` (medido ao vivo nesta sessão).
     * @return array{
     *     liberar: bool,
     *     motivo: string,
     *     status_envelope: ?string,
     *     contratantes_pendentes: int,
     *     houve_recusa: bool,
     * }
     */
    public function avaliar(ContratoAssinatura $contrato, array $envelopeReconsultado): array
    {
        $statusEnvelope = $envelopeReconsultado['attributes']['status'] ?? null;

        $base = [
            'status_envelope'        => $statusEnvelope,
            'contratantes_pendentes' => 0,
            'houve_recusa'           => false,
        ];

        // 1. O envelope precisa estar fechado — sem isso não há nem sinal
        // de que o processo terminou.
        if ($statusEnvelope !== 'closed') {
            return array_merge($base, [
                'liberar' => false,
                'motivo'  => 'envelope ainda nao esta fechado',
            ]);
        }

        $signatarios = $contrato->signatarios;

        // 2. Recusa é estado de parada HUMANA, nunca falha técnica — pára
        // aqui mesmo que o envelope tenha fechado.
        $houveRecusa = $signatarios->contains(
            fn (ContratoAssinaturaSignatario $s) => $s->situacao === ContratoAssinaturaSignatario::SITUACAO_RECUSOU
        );

        if ($houveRecusa) {
            return array_merge($base, [
                'liberar'      => false,
                'motivo'       => 'algum signatario recusou assinar',
                'houve_recusa' => true,
            ]);
        }

        $contratantes = $signatarios->where('papel', ContratoAssinaturaSignatario::PAPEL_CONTRATANTE);

        // 3. Fail-closed deliberado: sem nenhum contratante conhecido não
        // existe evidência de que o cliente assinou, e liberar aqui seria
        // liberar com base em nada.
        if ($contratantes->isEmpty()) {
            return array_merge($base, [
                'liberar' => false,
                'motivo'  => 'contrato sem signatario contratante registrado',
            ]);
        }

        $contratantesPendentes = $contratantes
            ->reject(fn (ContratoAssinaturaSignatario $s) => $s->situacao === ContratoAssinaturaSignatario::SITUACAO_ASSINOU)
            ->count();

        // 4. O ponto inteiro deste service (Pitfall A): o envelope pode
        // estar `closed` porque o prazo venceu com assinatura parcial. Só o
        // `status` de topo não distingue isso de um fechamento normal — a
        // contagem por signatário CONTRATANTE é o que distingue.
        if ($contratantesPendentes > 0) {
            return array_merge($base, [
                'liberar'                => false,
                'motivo'                 => 'fechamento parcial: contratante ainda nao assinou',
                'contratantes_pendentes' => $contratantesPendentes,
            ]);
        }

        // 5. Envelope fechado + todo contratante assinou.
        //
        // Por que só o `contratante` é obrigatório (129-RESEARCH.md §3): os
        // outros dois papéis são a ECF (`contratada`, dois sócios) e a
        // testemunha do Comercial — papel formal do NOSSO lado. Prender a
        // operação do cliente porque um sócio nosso ainda não clicou
        // transformaria uma pendência interna em empresa parada. O cliente
        // assinou: o serviço dele começa.
        return array_merge($base, [
            'liberar' => true,
            'motivo'  => 'envelope fechado e contratante assinou',
        ]);
    }
}
