---
quick_id: 261103-hsf
demanda: DEV-55
status: complete
---

# Resumo — 261103-hsf (DEV-55)

## Entregue

- **Entrada em Contrato Pendente:** `HubspotWebhookController::processar()` aceita
  `stage_contrato_pendente_id` (`1470731063`) como gatilho, além dos de Ganho. Ganho que chega
  depois para o mesmo deal cai na idempotência.
- **Escrita no HubSpot:** `HubspotApiClient::atualizarEtapaDoDeal()` (PATCH do `dealstage`).
- **`AvancarDealHubspotJob`** (fila `high`, `afterCommit`, 5 tentativas): resolve o deal pelo
  último `HubspotEvento` processado da empresa (fallback na coluna); só no Pipeline Consultoria;
  só avança (não reescreve o destino, não tira de Ganho nem de Perdido); chave
  `HUBSPOT_AVANCAR_DEAL_ATIVO=false` desliga sem deploy.
- **`ContratoAssinaturaHubspotObserver`** (registrado por `#[ObservedBy]` em
  `ContratoAssinatura`): `aguardando_assinaturas` → Contrato Enviado; `assinado` → Ganho.
- `config/services.php`: IDs medidos em produção como default. **Nenhuma mudança de `.env` é
  necessária** para ligar.

## Testes

- Novos: `tests/Feature/Quick261103HubspotPipelineConsultoriaTest.php`, 10 testes, OK.
- Regressão: HubSpot + Clicksign (Phase34/35/111–116/124/128/129/131 e afins), 332 testes, OK;
  demais suítes que tocam status de contrato, 161 testes, OK.

## Pendente

- Deploy (aguarda autorização do usuário).
- Deals que **já estão** em Contrato Pendente antes do deploy não entram sozinhos: o HubSpot só
  avisa quando o deal muda de coluna. Arrastar de volta e para frente, ou reprocessar.
