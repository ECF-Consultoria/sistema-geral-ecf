---
phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo
plan: 03
subsystem: api
tags: [hubspot, webhook, refactor, persistencia, tdd, comercial, php]

# Dependency graph
requires:
  - phase: 112-01
    provides: "HubspotValueResolver::resolve(Servico, lineItem, dealProps) — valor operacional mensal x anual"
  - phase: 112-02
    provides: "HubspotDealHandoffService::build() + HubspotHandoffData — montagem de valor/contratos extraída do controller"
provides:
  - "HubspotWebhookController fino — criarEmpresa delega ao HubspotDealHandoffService, persistirContratos() concentra a criação de ContratoServico a partir do DTO"
  - "Persistência real das 11 colunas hubspot_* (Fase 111) por contrato, com a linha legada em observacoes preservada"
  - "Critério de aceite âncora da milestone v20.0 fechado: line item mensal R$3.000 + amount/ARR R$36.000 → valor_contratado=3.000, R$36.000 em hubspot_valor_original"
affects: [113, 114, 115]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Controller orquestra (validar → idempotência → handoff → persistir → atualizar evento → notificar); montagem de valor/contrato vive 100% no service, controller só persiste o array pronto do DTO"
    - "Warnings do DTO têm 2 shapes distintos ({motivo:'servico_nao_encontrado'} vs {name,price,recurringbillingfrequency}) — controller roteia por shape para notes (legado) ou payload do evento (line item), preservando o comportamento observável de antes"

key-files:
  created:
    - tests/Feature/Phase112HubspotHandoffWebhookTest.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Services/Hubspot/HubspotValueResolver.php

key-decisions:
  - "processarLineItems()/processarServicoLegado() removidos por completo (não apenas reduzidos) — persistirContratos() único método privado consumindo o DTO, conforme discretion do plano"
  - "observacoes só é anotada quando o contrato tem hubspot_line_item_id (veio de um line item) — fluxo legado nunca teve essa linha e continua sem ela, paridade exata com o comportamento pré-refactor"
  - "Deviation (Rule 1 - bug): HubspotValueResolver::resolverSemLineItem() ramo 'indecidível' (fluxo legado sem line item, nem amount nem amount/12 batem com valor_padrao) sempre devolvia amount/12 mesmo sem nenhuma evidência de que o valor é anual — isso quebrava a INVARIANTE de não-regressão (Phase34/Phase37 esperavam valor_contratado=1500.0 e passaram a receber 125.0). Corrigido para manter o valor bruto observado (nunca transformar um número sem evidência), preservando confidence=low + warning valor_revisar. HubspotValueResolverTest não fixa o valor exato desse ramo (só confidence+warning), então a correção não quebrou o teste unitário do resolver."

patterns-established:
  - "persistirContratos(Company, HubspotHandoffData, ?HubspotEvento): array — ponto único de persistência de ContratoServico a partir do DTO do handoff, reaproveitável pelas Fases 113+"

requirements-completed: [HUB-VAL-02, HUB-VAL-03, HUB-VAL-05]

# Metrics
duration: ~35min
completed: 2026-07-24
---

# Phase 112 Plan 03: Controller fino + persistência + E2E 36k→3k Summary

**`HubspotWebhookController::criarEmpresa` agora delega ao `HubspotDealHandoffService::build()` e `persistirContratos()` grava o valor operacional + as 11 colunas de auditoria HubSpot por `ContratoServico` — fecha o critério de aceite âncora da milestone v20.0 (line item mensal R$3.000 + amount/ARR R$36.000 → `valor_contratado=3.000`, R$36.000 preservado em `hubspot_valor_original`).**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-07-24T14:10:00Z
- **Completed:** 2026-07-24T14:45:00Z
- **Tasks:** 2 (TDD RED + GREEN)
- **Files modified:** 3 (1 teste criado + 2 arquivos de produção)

## Accomplishments
- `HubspotWebhookController::criarEmpresa` não calcula mais valor de contrato inline — delega 100% ao `HubspotDealHandoffService::build($deal, $lineItems, $propsDeal)` resolvido via container.
- `persistirContratos()` (novo método privado único) substitui `processarLineItems()`/`processarServicoLegado()` — cria um `ContratoServico` por item de `contracts_to_create` do DTO, preenchendo as 11 colunas `hubspot_*` (line_item_id, product_id, billing_frequency, billing_period, currency, valor_original, valor_original_tipo, valor_normalizado_mensal, valor_confidence, valor_warning, snapshot) e mantendo a linha legada `observacoes = "tipo_cobranca: {mensal|unica} (HubSpot line_item: {nome})"` (Phase 37) quando o contrato veio de um line item.
- Warnings do DTO roteados por shape: `{motivo:'servico_nao_encontrado'}` (fluxo legado) grava em `company->notes`; `{name,price,recurringbillingfrequency}` (line item sem mapping) grava em `HubspotEvento.payload['line_items_nao_mapeados']` — paridade total com o comportamento anterior.
- HMAC, filtro de stage, idempotência, `DB::transaction`, atualização de `hubspot_eventos` e `notificarComercialSePendente` **intocados** (`receive()`/`processar()` não modificados).
- Suite `Phase112HubspotHandoffWebhookTest` (6 testes, 31 asserções) prova via webhook real (Http::fake): monthly 3000→3000, annually 36000/P1Y→3000 com auditoria, multi-line-item com `hubspot_line_item_id` distintos, observacoes legada preservada, tipo_cobranca=unica NÃO divide por 12, e fluxo legado sem line item com warning de inferência.
- Gate de não-regressão 100% verde: Phase34HubspotWebhookTest, Phase35HubspotV2Test, Phase37WebhookLineItemsTest, Phase37LineItemsFetchTest, HubspotValueResolverTest, Phase112HandoffServiceTest — 67 testes / 289 asserções na suite `--filter=Hubspot` (mais os 4 arquivos citados acima, verificados numa segunda rodada).

## Task Commits

Each task was committed atomically:

1. **Tarefa 1 (RED): E2E do webhook — 36k→3k + auditoria + multi-item** - `e513e494` (test)
2. **Tarefa 2 (GREEN): controller fino delega ao handoff + persiste auditoria; gate de regressão** - `247d04b2` (refactor)

_Nota: Tarefa 2 seguiu TDD (RED→GREEN) conforme `tdd="true"` no frontmatter da tarefa; o commit GREEN inclui a correção de bug no resolver (deviation Rule 1), necessária para o próprio gate de regressão passar._

## Files Created/Modified
- `tests/Feature/Phase112HubspotHandoffWebhookTest.php` (novo) - Suite E2E via webhook real (Http::fake), 6 cenários cobrindo o critério de aceite âncora + auditoria + multi-item + tipo_unica + legado com warning.
- `app/Http/Controllers/Api/HubspotWebhookController.php` - `criarEmpresa` delega ao `HubspotDealHandoffService::build()`; `processarLineItems()`/`processarServicoLegado()` removidos e substituídos por `persistirContratos()`.
- `app/Services/Hubspot/HubspotValueResolver.php` - correção do ramo "indecidível" de `resolverSemLineItem()` (deviation Rule 1 — ver `key-decisions`).

## Decisions Made
Ver `key-decisions` no frontmatter — remoção completa dos métodos antigos (vs. reduzi-los), condição para observacoes legada, e a correção de bug no resolver.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `HubspotValueResolver::resolverSemLineItem()` adivinhava anual sem evidência no ramo indecidível**
- **Found during:** Tarefa 2 (rodando o gate de regressão Phase34/Phase37 após o refactor do controller)
- **Issue:** Quando o fluxo legado (deal sem line item) tinha `deal.amount` que não batia nem com `valor_padrao` nem com `valor_padrao` via `amount/12` (dentro da tolerância de 5%), o resolver sempre devolvia `amount/12` como valor operacional — mesmo sem nenhuma evidência de que o valor é anual. Isso quebrava a INVARIANTE de não-regressão da fase: `Phase34HubspotWebhookTest::evento_processado_cria_company` e `Phase37WebhookLineItemsTest::zero_regressao_phase_34_fluxo_legado_servico_amount` esperavam `valor_contratado=1500.0` (deal.amount cru, comportamento Phase 34/35/37) e passaram a receber `125.0` (1500/12).
- **Fix:** Alterado o retorno do ramo "indecidível" para manter o valor bruto observado (`valor_operacional = $amount`, `valor_original_tipo = 'deal_amount'`, `normalizado_mensal = null`), preservando `confidence='low'` + `warning` de `valor_revisar` — sem transformar um número sem evidência que ele é anual.
- **Files modified:** `app/Services/Hubspot/HubspotValueResolver.php`
- **Verification:** `HubspotValueResolverTest` (9 testes) não fixa o valor exato desse ramo — só `confidence`/`warning` — permaneceu 100% verde; `Phase34HubspotWebhookTest`/`Phase37WebhookLineItemsTest` voltaram a gravar `1500.0` como antes.
- **Committed in:** `247d04b2` (commit da Tarefa 2, junto com o refactor do controller — a correção era pré-condição para o gate de regressão da própria tarefa passar)

---

**Total deviations:** 1 auto-fixed (1 bug em arquivo fora de `files_modified` do plano, necessário para satisfazer o gate de não-regressão explicitamente exigido pela tarefa)
**Impact on plan:** Correção essencial e estritamente escopada ao bug que quebrava a invariante — nenhum scope creep; nenhuma outra ramificação do resolver foi tocada.

## Issues Encountered
None além da deviation documentada acima.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- Fase 112 completa (3/3 planos): `HubspotValueResolver` (112-01) + `HubspotDealHandoffService`/DTO (112-02) + controller fino + persistência (112-03).
- Critério de aceite âncora da milestone v20.0 fechado e comprovado por E2E real via webhook.
- `HubspotHandoffData` já nasceu extensível (`company_data`/`contact_data` reservados) — Fase 113 pode adicionar enriquecimento/dedup sem quebrar o contrato atual.
- Nenhum bloqueio conhecido. Falhas pré-existentes não relacionadas (Phase14* Carbon/timezone, Phase37ServicoSetorTest) permanecem fora de escopo, já documentadas em `deferred-items.md` da Fase 111.

---
*Phase: 112-hubspotvalueresolver-extra-o-do-handoff-service-v20-0-n-cleo*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: tests/Feature/Phase112HubspotHandoffWebhookTest.php
- FOUND: app/Http/Controllers/Api/HubspotWebhookController.php (modificado)
- FOUND: app/Services/Hubspot/HubspotValueResolver.php (modificado)
- FOUND: e513e494 (RED commit)
- FOUND: 247d04b2 (GREEN commit)
