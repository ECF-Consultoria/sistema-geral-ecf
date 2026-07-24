---
phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo
plan: 02
subsystem: api
tags: [hubspot, handoff, dto, comercial, tdd, php]

# Dependency graph
requires:
  - phase: 112-01
    provides: "App\\Services\\Hubspot\\HubspotValueResolver — resolve(Servico, lineItem, dealProps): array (valor operacional mensal x anual)"
provides:
  - "App\\Services\\Hubspot\\HubspotHandoffData — DTO extensível do handoff (deal_data/line_items/contracts_to_create/warnings/confidence + company_data/contact_data reservados p/ Fase 113)"
  - "App\\Services\\Hubspot\\HubspotDealHandoffService::build(deal, lineItems, propsDeal) — extração da montagem de valor/contratos, testável isoladamente sem HTTP/webhook"
affects: [112-03, 113, 114]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DTO extensível: campos reservados para fase futura declarados nullable e documentados em PHPDoc, sem uso no fluxo atual"
    - "Confidence agregada do DTO = a MENOR confiança entre os contratos individuais (ordenação low < medium < high)"
    - "hubspot_snapshot por contrato guarda {line_item, resolver_result} — rastro completo de proveniência/auditoria (T-112-02-03)"

key-files:
  created:
    - app/Services/Hubspot/HubspotHandoffData.php
    - app/Services/Hubspot/HubspotDealHandoffService.php
    - tests/Feature/Phase112HandoffServiceTest.php
  modified: []

key-decisions:
  - "Warning do fluxo legado (servico_ecf sem match no catálogo) usa shape {name, motivo: 'servico_nao_encontrado'} — distinto do shape de line item não-mapeado ({name, price, recurringbillingfrequency}), porque a origem/dados disponíveis são diferentes; ambos expõem 'name' para consumo uniforme."
  - "Confidence agregada quando NÃO há contratos: 'low' se existe warning pendente (algo não resolvido precisa de atenção), 'high' quando não há nada a reportar (line item vazio ignorado silenciosamente, paridade com o controller)."
  - "menorConfianca() trata valor desconhecido como 'high' (mais permissivo) — nunca derruba a confiança agregada por um valor de confidence inesperado vindo do resolver."
  - "hubspot_line_item_id/product_id/currency ficam null no fluxo legado (sem line item de origem) — auditável e consistente com o shape do DTO."

patterns-established:
  - "Service HubspotDealHandoffService recebe HubspotValueResolver via DI (constructor promotion) — resolvido pelo container em app(HubspotDealHandoffService::class) nos testes, sem precisar mockar."
  - "Suite Feature isolada chama build() direto (sem HTTP::fake nem rota) — RefreshDatabase + limpeza de hubspot_line_item_mapping/servicos no setUp, mesmo padrão de fixtures do Phase37WebhookLineItemsTest."

requirements-completed: [HUB-VAL-02, HUB-VAL-04]

# Metrics
duration: ~15min
completed: 2026-07-24
---

# Phase 112 Plan 02: HubspotDealHandoffService + DTO Summary

**`HubspotDealHandoffService::build()` extrai do `HubspotWebhookController` a montagem de valor/contratos, consumindo o `HubspotValueResolver` (plano 112-01) por line item — cada item mapeado vira 1 contrato com valor operacional + 11 campos de auditoria; multi-line-item produz contratos separados por `hubspot_line_item_id`; a confiança do DTO é a MENOR entre os contratos.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-24T13:51:00Z
- **Completed:** 2026-07-24T13:57:15Z
- **Tasks:** 2 (DTO + TDD RED/GREEN do service)
- **Files modified:** 3 (2 criados + 1 teste criado)

## Accomplishments
- `HubspotHandoffData` (DTO) criado no namespace `App\Services\Hubspot`, com `deal_data`/`line_items`/`contracts_to_create`/`warnings`/`confidence` (default `high`) preenchidos nesta fase, e `company_data`/`contact_data` nullable reservados para a Fase 113 (enriquecimento + dedup).
- `HubspotDealHandoffService::build(deal, lineItems, propsDeal)` extrai fielmente `processarLineItems()`/`processarServicoLegado()` do controller — SÓ a parte de valor/contrato — injetando `HubspotValueResolver` via DI.
- Multi-line-item resolvido individualmente: 2 line items mapeados → 2 contratos com `hubspot_line_item_id` distintos, nunca consolidados (T-112-02-02).
- Line item sem mapping ativo (ou mapping apontando para Serviço inativo) NÃO vira contrato — acumula warning no mesmo shape do controller atual (T-112-02-01, paridade comprovada por teste).
- `confidence` agregada do DTO = a MENOR entre os contratos (`low < medium < high`) — testado com 2 itens de confiança distintas (monthly=`high` + annually sem P1Y=`medium`) resultando em `confidence='medium'`, preservando o `hubspot_valor_confidence` individual de cada contrato.
- Fluxo legado (deal sem line items) resolve o `Servico` do deal + `deal.amount` pelo resolver; nome sem match no catálogo gera warning `servico_nao_encontrado`.
- `hubspot_snapshot` de cada contrato guarda `{line_item, resolver_result}` — rastro completo de proveniência (T-112-02-03).
- Suite `Phase112HandoffServiceTest` (8 testes, 34 asserções) prova o handoff isolado, sem HTTP/webhook.
- Gate de não-regressão intacto: `Phase37WebhookLineItemsTest` (10 testes, 57 asserções) continua 100% verde — o controller ainda não foi tocado (escopo do plano 112-03).

## Task Commits

Each task was committed atomically:

1. **Tarefa 1: DTO HubspotHandoffData (contract-first, extensível)** - `ab40fd22` (feat)
2. **Tarefa 2 (RED): suite Feature isolada HubspotDealHandoffService** - `41d5db8d` (test)
3. **Tarefa 2 (GREEN): implementa HubspotDealHandoffService::build()** - `85665dda` (feat)

_Nota: Tarefa 2 seguiu TDD (RED→GREEN) conforme `tdd="true"` no frontmatter da tarefa._

## Files Created/Modified
- `app/Services/Hubspot/HubspotHandoffData.php` - DTO simples (propriedades públicas + construtor com promoção PHP 8.2), shape documentado em PHPDoc (11 chaves `hubspot_*` por contrato).
- `app/Services/Hubspot/HubspotDealHandoffService.php` - `build()` + helpers privados `montarContrato()` (monta o array pronto para `ContratoServico`) e `menorConfianca()` (ordenação low<medium<high).
- `tests/Feature/Phase112HandoffServiceTest.php` - 8 métodos cobrindo todo o `<behavior>` do plano (mensal monthly/annually P1Y, multi-line-item, confidence mista, sem mapping, mapping inativo, fluxo legado com/sem serviço compatível).

## Decisions Made
Ver `key-decisions` no frontmatter — shape do warning legado, regra de confidence agregada sem contratos, tratamento de confidence desconhecida em `menorConfianca()`.

## Deviations from Plan

None - plano executado exatamente como escrito. Único ajuste cosmético: o comentário de classe inicial mencionava literalmente "Company/MlbEmpresa/notes" (para descrever o que o service NÃO faz), o que colidia com o grep de acceptance criteria (`grep -nE "Company::create|MlbEmpresa|->notes"`); reescrito para descrever o mesmo escopo sem os literais, sem qualquer mudança de comportamento de código.

## Issues Encountered
None.

## User Setup Required
None - nenhuma configuração de serviço externo necessária. Classe pura + resolver injetado via DI, sem I/O externo.

## Next Phase Readiness
- `HubspotDealHandoffService` e `HubspotHandoffData` prontos para o controller consumir no plano 112-03 (que deve substituir `processarLineItems`/`processarServicoLegado` por uma chamada a `build()` + persistir `contracts_to_create` como `ContratoServico`).
- DTO já nasce extensível: `company_data`/`contact_data` aguardando a Fase 113 sem exigir mudança de assinatura.
- Nenhum bloqueio conhecido. Controller (`HubspotWebhookController`) ainda intocado — 10/10 `Phase37WebhookLineItemsTest` seguem verdes.

---
*Phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: app/Services/Hubspot/HubspotHandoffData.php
- FOUND: app/Services/Hubspot/HubspotDealHandoffService.php
- FOUND: tests/Feature/Phase112HandoffServiceTest.php
- FOUND: ab40fd22 (DTO commit)
- FOUND: 41d5db8d (RED commit)
- FOUND: 85665dda (GREEN commit)
