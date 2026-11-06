---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 02
subsystem: hubspot
tags: [hubspot, renovacao, http-client, retry]
requires: []
provides:
  - "HubspotApiClient: criarDeal, buscarDealsPorPropriedade, buscarDealOuNull, buscarPipelineDeDeals, buscarPropriedadeDeDeal, criarPropriedadeDeDeal, associarDealPadrao"
  - "HubspotErroApi (limite|incerto|permanente|temporario|conflito)"
  - "config services.hubspot.renovacao"
  - "comando renovacao:hubspot-preparar"
key-files:
  created:
    - app/Services/Hubspot/HubspotErroApi.php
    - app/Console/Commands/PrepararHubspotRenovacao.php
    - tests/Feature/Phase202/HubspotClienteRenovacaoTest.php
  modified:
    - config/services.php
    - .env.example
    - app/Services/HubspotApiClient.php
requirements-completed: [REN-03, REN-08]
duration: ~25min
completed: 2026-11-06
---

# Fase 202 Plano 02: Cliente HubSpot da renovação Summary

Cliente HubSpot com criação de negócio sem retry cego (timeout/5xx vira "incerto"), leituras com backoff e Retry-After, exceção tipada e comando de preparo em dry-run.

## Commits
- 1c5468ed2 — config da renovação + `HubspotErroApi`
- (Task 2+3) — cliente, comando e testes (hash no relatório final)

## Decisões
- IDs do pipeline/etapas só em `config/services.php` (medidos em 06/11); `.env.example` sem token.
- `associarDealPadrao` (PUT idempotente) usa o retry de leitura; `criarDeal` e `criarPropriedadeDeDeal` nunca repetem.
- Retry usa `Illuminate\Support\Sleep` (testável com `Sleep::fake()`); espera por 429 limitada a 10s.

## Desvios
Nenhum de comportamento. Detalhe: no teste do timeout o POST não é registrado por `Http::recorded` quando o fake lança, então a contagem de 1 envio usa um contador no próprio fake em vez de `Http::assertSentCount(1)`.

## Testes
`HubspotClienteRenovacaoTest` (16) + suítes HubSpot existentes (`--filter=Hubspot`: 183 passaram).

## Pendências humanas
Escopos do token (`crm.objects.deals.write`, `crm.schemas.deals.write`) não verificados; rodar `renovacao:hubspot-preparar` (simulação) e depois `--aplicar` no VPS.

## Known Stubs
Nenhum.

## Self-Check: PASSED
