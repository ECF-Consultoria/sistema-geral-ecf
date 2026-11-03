---
quick_id: 261103-hsf
demanda: DEV-55
prazo: 2026-11-05
---

# Quick 261103-hsf — Novo fluxo do Pipeline Consultoria no HubSpot (DEV-55)

Pedido do Comercial (03/11/2026): o Pipeline Consultoria ganhou a coluna **Contrato Pendente**.
A empresa passa a entrar no sistema nessa coluna, e o sistema move o deal sozinho depois:

| Momento no ECF Admin | Coluna no HubSpot |
|---|---|
| Deal arrastado para Contrato Pendente | empresa criada (antes: só em Ganho) |
| Contrato enviado ao cliente (`aguardando_assinaturas`) | Contrato Enviado |
| Contrato assinado (`assinado`) | Ganho |

## Medido em produção (03/11, leitura)

- Pipeline Consultoria = `default`. Contrato Pendente = `1470731063`, Contrato Enviado =
  `contractsent`, Ganho = `closedwon`, Perdido = `closedlost`.
- O token do Private App já tem `crm.objects.deals.write`. Nada a mudar no HubSpot.

## Tarefas

1. `config/services.php`: chaves `pipeline_consultoria_id`, `stage_contrato_pendente_id`,
   `stage_contrato_enviado_id`, `stage_ganho_consultoria_id`, `stage_perdido_consultoria_id` e a
   chave de desligar `avancar_deal_ativo`, com os valores medidos como default.
2. Webhook de entrada: Contrato Pendente vira gatilho de criação, junto dos de Ganho já existentes.
   Ganho depois de Contrato Pendente cai na idempotência (não duplica).
3. `HubspotApiClient::atualizarEtapaDoDeal()` (PATCH do `dealstage`).
4. `AvancarDealHubspotJob` (fila `high`, `afterCommit`, retry): resolve o deal pelo último
   `HubspotEvento` processado da empresa (fallback `companies.hubspot_deal_id`); só mexe em deal do
   Pipeline Consultoria; só AVANÇA (nunca volta, nunca tira de Perdido).
5. `ContratoAssinaturaHubspotObserver`: quando `status` muda para `aguardando_assinaturas` ou
   `assinado`, despacha o job. Observer pega todos os caminhos (webhook Clicksign e reconciliação).
6. Testes Feature com `Http::fake`.

Decisão: com mais de um contrato na empresa, o **primeiro** assinado já move para Ganho (cliente
assinou = negócio ganho). Esperar todos poderia deixar o deal parado se um deles for cancelado.
