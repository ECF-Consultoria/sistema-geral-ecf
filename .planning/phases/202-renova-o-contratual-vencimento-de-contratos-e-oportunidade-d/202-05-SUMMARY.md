---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 05
subsystem: renovacao
tags: [renovacao, hubspot, webhook, anti-loop]
requires: ["202-01", "202-03"]
provides:
  - "RenovacaoWebhookHandler (ehEtapaDeRenovacao, ehNegocioDeRenovacao, tratar)"
  - "Desvio de renovação em HubspotWebhookController (processar topo, pós-fetchDeal, replay)"
  - "Término calculado na entrada do webhook de venda (persistirContratos -> preencherEmpresa)"
key-files:
  created:
    - app/Services/Renovacao/RenovacaoWebhookHandler.php
    - tests/Feature/Phase202/RenovacaoWebhookTest.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
requirements-completed: [REN-05, REN-01, REN-08]
completed: 2026-11-06
---

# Fase 202 Plano 05: Webhook de renovação Summary

Eventos do pipeline "Renovações contrato" saem do fluxo de venda ganha (nunca criam empresa/contrato); o ganho estende o contrato uma única vez e o contrato novo da venda já nasce com término.

## Commits
- e2b16d0fe — handler + testes
- 823f0e6e6 — desvio no controller + término na entrada

## Decisões
- Desvio por etapa é a PRIMEIRA instrução de `processar()`/`reprocessarEvento()`; segundo desvio por property de correlação após o `fetchDeal` (o `fetchDeal` agora pede também `propriedade_chave`).
- Negócio de renovação em etapa fora do pipeline (ex.: arrastado a `closedwon`): evento `ignorado` + evento do ciclo `etapa_fora_do_pipeline`; não estende.
- Vínculo por correlação com unicidade violada vira HubspotEvento `erro`.
- Replay de renovação devolve o resumo no formato atual com `company_id` null e warning.

## Desvios
Nenhum de regra 1-4. Nenhum teste antigo precisou de ajuste.

## Testes
- `tests/Feature/Phase202`: 109 passed (384 assertions); novos: 12 em RenovacaoWebhookTest.
- `--filter=Hubspot`: 186 passed (771 assertions).
- `Phase151/ComercEtapaNascimentoWebhookTest`: 6 passed.

## Known Stubs
Nenhum.

## Self-Check: PASSED
