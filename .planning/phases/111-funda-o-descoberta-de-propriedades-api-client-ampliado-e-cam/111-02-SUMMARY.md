---
phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
plan: 02
subsystem: api
tags: [hubspot, http-client, crm-v3, tdd]

requires:
  - phase: 111-01
    provides: services.hubspot.props ampliado (deal/company/contact) — usado como fonte de nomes de propriedade
provides:
  - "fetchDealLineItems com 16 props (MRR/ARR/TCV/ACV, periodo/datas de recorrencia, moeda, description, amount, hs_sku)"
  - "fetchAssociations(fromObject, fromId, toObject) — GET generico de associacoes v3"
  - "fetchAssociatedCompanyIds / fetchAssociatedContactIds — todos os IDs (nao so o primeiro)"
  - "fetchCompanies / fetchContacts — busca multipla por N GETs, mapa id=>properties"
affects: [112-hubspot-value-resolver, 113-enriquecimento-dedup]

tech-stack:
  added: []
  patterns: [http-fake-only-testing, resiliencia-4xx-5xx-retorna-vazio-sem-excecao]

key-files:
  created:
    - tests/Feature/Phase111HubspotApiClientTest.php
  modified:
    - app/Services/HubspotApiClient.php
    - tests/Feature/Phase37LineItemsFetchTest.php

key-decisions:
  - "fetchCompanies/fetchContacts usam N GETs (reusando o padrao ja existente no client) em vez de batch read POST — mais simples, consistente com fetchDealLineItems, e discricao do plano permitia qualquer uma das duas abordagens"
  - "fetchAssociatedCompanyIds/fetchAssociatedContactIds implementados sobre fetchAssociations generico (DRY) em vez de duplicar a chamada HTTP"
  - "Base https://api.hubapi.com/crm/v3/objects mantida — NAO migrar para /crm/objects/2026-03 (decisao travada do prompt canonico, T-111-04 aceita no threat model)"

requirements-completed: [HUB-API-03]

duration: ~12min
completed: 2026-07-24
---

# Phase 111 Plan 02: HubspotApiClient ampliado — line items completos + associações/batch Summary

**`HubspotApiClient::fetchDealLineItems` passa de 6 para 17 chaves (MRR/ARR/TCV/ACV, período/datas de recorrência, moeda) e ganha 5 métodos novos de associações/batch (`fetchAssociatedCompanyIds`/`fetchAssociatedContactIds`/`fetchAssociations`/`fetchCompanies`/`fetchContacts`), tudo coexistindo com os métodos singulares atuais sem quebrar o webhook consumidor.**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-07-24T12:35:00Z
- **Completed:** 2026-07-24T12:41:42Z
- **Tasks:** 2 completos (TDD RED→GREEN cada)
- **Files modified:** 3 (1 criado, 2 modificados)

## Accomplishments
- `fetchDealLineItems` retorna o conjunto completo de props do handoff comercial (§Fase 3 do prompt canônico), preservando cast e comportamento dos 6 campos atuais
- 5 métodos novos de associações/batch cobertos por suite dedicada (`Phase111HubspotApiClientTest`, 14 cenários)
- Zero regressão: `Phase37LineItemsFetchTest` (9/9), `Phase37WebhookLineItemsTest` (10/10), `Phase34HubspotWebhookTest` (6/6), `Phase35HubspotV2Test` (7/7) — todos verdes
- Base v3 confirmada intacta (`grep -c "crm/objects/2026-03"` == 0); nenhum token vazado em log (coberto por teste dedicado)

## Task Commits

Each task was committed atomically (TDD RED→GREEN):

1. **Task 1: Ampliar fetchDealLineItems** - `048d253a` (test, RED) → `dfdf3878` (feat, GREEN)
2. **Task 2: Novos métodos de associações e batch read** - `0615162c` (test, RED) → `37c2f001` (feat, GREEN)

**Plan metadata:** (a seguir, commit desta SUMMARY + STATE + ROADMAP)

_TDD: cada task teve 2 commits (test→feat), sem necessidade de refactor._

## Files Created/Modified
- `app/Services/HubspotApiClient.php` - `fetchDealLineItems` ampliado (16 props) + `fetchAssociations`/`fetchAssociatedCompanyIds`/`fetchAssociatedContactIds`/`fetchCompanies`/`fetchContacts` novos
- `tests/Feature/Phase37LineItemsFetchTest.php` - asserção de shape/valores atualizada só no cenário `test_deal_com_2_line_items_retorna_lista_completa`; T2-T9 preservados intactos
- `tests/Feature/Phase111HubspotApiClientTest.php` (novo) - 14 cenários cobrindo os 5 métodos novos + regressão de método singular + não-vazamento de token

## Decisions Made
- N GETs (não batch read POST) para `fetchCompanies`/`fetchContacts` — consistente com o padrão já usado no client (fetchDealLineItems também faz N GETs para detalhes de line items)
- `fetchAssociatedCompanyIds`/`fetchAssociatedContactIds` implementados sobre `fetchAssociations` genérico para evitar duplicação de lógica HTTP
- Base v3 mantida conforme decisão travada do prompt canônico (T-111-04, disposition `accept` no threat model)

## Deviations from Plan

None - plan executado exatamente como escrito.

## Known Stubs

Nenhum. Métodos novos são wrappers HTTP puros (mesma natureza dos métodos atuais); não há dados mockados/placeholder na saída — tudo vem do payload real da API (ou `Http::fake` nos testes).

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. Os 5 métodos novos seguem os mesmos trust boundaries já registrados (ECF app → HubSpot CRM v3 API) e a mesma disciplina de log (T-111-03).

## Self-Check

- [x] `app/Services/HubspotApiClient.php` existe e contém os 5 métodos novos + `fetchDealLineItems` ampliado
- [x] `tests/Feature/Phase111HubspotApiClientTest.php` existe
- [x] Commits `048d253a`, `dfdf3878`, `0615162c`, `37c2f001` existem no histórico
- [x] `php artisan test --filter=Phase37LineItemsFetchTest` → 9/9 verde
- [x] `php artisan test --filter=Phase111HubspotApiClientTest` → 14/14 verde
- [x] Regressão `Phase34HubspotWebhookTest`/`Phase35HubspotV2Test`/`Phase37WebhookLineItemsTest` → todos verdes
- [x] `grep -c "crm/objects/2026-03"` == 0 (base v3 intacta)

## Self-Check: PASSED
