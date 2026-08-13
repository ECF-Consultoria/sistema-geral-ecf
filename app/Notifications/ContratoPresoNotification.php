<?php

namespace App\Notifications;

use App\Models\ContratoAssinatura;
use App\Services\Contratos\ContratosPresosService;

/**
 * Fase 130 Plano 05 (REDE-02, D-01, D-05) — o "alguém sabe" da rede de
 * segurança: alerta in-app de que uma empresa está sem liberação há tempo
 * demais.
 *
 * Subclasse concreta de `BaseNotification` — mesmo padrão arquitetural de
 * `EmpresaHubspotPendenteNotification` (Fase 35). NÃO sobrescreve `via()`
 * nem `toArray()`: canal único `database` (D-01, o sino já existente — nada
 * de e-mail ou WhatsApp/Digisac) e payload canônico de 6 chaves vêm 100% do
 * `BaseNotification`.
 *
 * Categoria fixa = MANUAL, mesmo fallback do cenário análogo (evento de
 * sistema que pede ação humana). Criar um case novo no enum `Categoria` é
 * troca de 1 linha se um dia o time quiser separar esta origem
 * estatisticamente das demais notificações manuais.
 *
 * A mensagem cobre os 7 estados de `ContratosPresosService::causa()`
 * (D-05: "empresa sem liberação há tempo demais, qualquer que seja a
 * causa") e separa explicitamente "o cliente decidiu" (recusado) de "a
 * integração falhou" (erro técnico) — é a exigência central da D-05. Nenhum
 * rótulo usa jargão técnico ("webhook", "envelope", "payload") visível ao
 * usuário.
 *
 * `meta` NUNCA carrega e-mail, CPF ou nome de signatário — só ids, status e
 * a causa (T-130-05-01).
 */
class ContratoPresoNotification extends BaseNotification
{
    /**
     * Rótulos em linguagem simples por causa (D-05) — mapeia as 7
     * constantes `CAUSA_*` de `ContratosPresosService` para uma frase que já
     * contém o que fazer a seguir. `recusado_pelo_cliente` e `erro_tecnico`
     * são deliberadamente diferentes entre si: é a prova de que a mensagem
     * separa "cliente recusou" de "a integração falhou".
     */
    private const LABELS_CAUSA = [
        ContratosPresosService::CAUSA_RASCUNHO_PARADO          => 'O contrato foi criado e nunca chegou a ser enviado ao cliente.',
        ContratosPresosService::CAUSA_AGUARDANDO_ALEM_DO_PRAZO => 'O contrato foi enviado e ainda não foi assinado. Cobre o cliente ou libere a empresa manualmente.',
        ContratosPresosService::CAUSA_ASSINADO_SEM_LIBERACAO   => 'O contrato já foi assinado, mas a empresa continua sem ser liberada.',
        ContratosPresosService::CAUSA_RECUSADO                 => 'O cliente recusou a assinatura. Fale com ele e decida entre reemitir o contrato ou liberar a empresa manualmente.',
        ContratosPresosService::CAUSA_EXPIRADO                 => 'O prazo do contrato acabou sem todas as assinaturas.',
        ContratosPresosService::CAUSA_CANCELADO                => 'O contrato foi cancelado e a empresa continua parada.',
        ContratosPresosService::CAUSA_ERRO                     => 'A integração de assinatura falhou (ninguém recusou nada). Confira o contrato e, se estiver tudo certo do lado do cliente, libere a empresa manualmente.',
    ];

    /** Fallback defensivo — nunca deveria ocorrer, as 7 causas estão mapeadas acima. */
    private const LABEL_FALLBACK = 'A empresa está sem liberação e o motivo não foi identificado. Confira o contrato manualmente.';

    public function __construct(ContratoAssinatura $contrato, string $causa, int $diasParado)
    {
        $rotulo = self::LABELS_CAUSA[$causa] ?? self::LABEL_FALLBACK;

        parent::__construct(
            titulo:      "Empresa parada há {$diasParado} dias: {$contrato->company->name}",
            mensagem:    "{$rotulo} Serviço: {$contrato->servico->nome}.",
            categoria:   Categoria::MANUAL,
            autorUserId: null, // Sistema — sem autor humano
            url:         route('contratos.liberacao-manual.index'),
            meta:        [
                'contrato_assinatura_id' => $contrato->id,
                'company_id'             => $contrato->company_id,
                'servico_id'             => $contrato->servico_id,
                'status'                 => $contrato->status,
                'causa'                  => $causa,
                'dias_parado'            => $diasParado,
                'fonte'                  => 'rede_seguranca',
            ],
        );
    }
}
