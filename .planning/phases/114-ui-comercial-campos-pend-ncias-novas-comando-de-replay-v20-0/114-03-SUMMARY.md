---
phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0
plan: 03
subsystem: api
tags: [hubspot, laravel, artisan-command, replay, idempotencia]

# Dependency graph
requires:
  - phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
    provides: "HubspotApiClient (fetch* usados no refetch do replay)"
  - phase: 112-hubspotvalueresolver-n-cleo-mensal-anual
    provides: "HubspotDealHandoffService::build() (montagem valor/contratos reusada por criarEmpresa)"
  - phase: 113-enriquecimento-contato-empresa-dedup
    provides: "HubspotCompanyMatcher (dedup) + guard hubspot_line_item_id em persistirContratos — base da idempotencia do replay"
provides:
  - "Comando `php artisan hubspot:reprocess-event {id}` (classe ReprocessHubspotEvent)"
  - "Método público `HubspotWebhookController::reprocessarEvento()` reusável fora do fluxo do webhook"
  - "Fecha o loop operacional: mapping cadastrado após deal → replay materializa o contrato faltante sem esperar novo webhook"
affects: [115-e2e-doc]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Replay reusa o MESMO bloco de fetch do webhook, mas sem os 2 guards de INGESTÃO (filtro dealstage + jaProcessado) — só a idempotência de DADOS (matcher + guard de contrato) é preservada"
    - "Company::wasRecentlyCreated usado para detectar enriquecimento vs. criação sem duplicar a lógica do matcher"

key-files:
  created:
    - app/Console/Commands/ReprocessHubspotEvent.php
    - tests/Feature/Phase114HubspotReplayTest.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php

key-decisions:
  - "reprocessarEvento() sempre faz refetch via HubspotApiClient (dados frescos), não lê do hubspot_snapshot — discrição da fase, escolhido por simplicidade e paridade total com o webhook"
  - "empresas_enriquecidas detectado via $company->wasRecentlyCreated (true só quando Company::create() rodou nesta chamada), evitando duplicar a lógica de match forte/fraco do HubspotCompanyMatcher"
  - "contratos_ignorados é estimado por contarTentativasContrato() (leitura, espelha a resolução de mapping de HubspotDealHandoffService) — não altera a assinatura de persistirContratos"

patterns-established:
  - "Comando de replay Artisan delega 100% da lógica de negócio ao controller (handle() é só orquestração + apresentação da tabela)"

requirements-completed: [HUB-REPLAY-01]

# Metrics
duration: ~30min
completed: 2026-07-24
---

# Phase 114 Plan 03: Comando de replay hubspot:reprocess-event Summary

**Comando Artisan `hubspot:reprocess-event {id}` que reprocessa um HubspotEvento reusando o dedup/handoff do webhook (HubspotCompanyMatcher + guard hubspot_line_item_id) — idempotente, materializa contratos que ficaram faltando após o admin cadastrar um HubspotLineItemMapping ausente.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 3/3 completos (Task 3 sem código — gate de regressão)
- **Files modified:** 3 (1 controller, 1 comando novo, 1 teste novo)

## Accomplishments
- `HubspotWebhookController::reprocessarEvento(HubspotEvento $evento, HubspotApiClient $api): array` — método público que refaz o bloco de fetch (deal + company + contatos batch + line items) e chama `criarEmpresa()`, sem o filtro de dealstage nem o early-return `jaProcessado` (regras de ingestão do webhook, não do replay intencional).
- Comando `ReprocessHubspotEvent` (`hubspot:reprocess-event {id}`) carrega o `HubspotEvento`, delega ao controller e imprime resumo estruturado (evento/deal/company/contratos criados/ignorados/empresas enriquecidas/warnings).
- Idempotência comprovada: `HubspotCompanyMatcher` (não duplica company) + guard `hubspot_line_item_id` em `persistirContratos` (não duplica contrato) — 2ª execução do replay mantém `Company::count()` e `ContratoServico::count()`.
- Caso âncora comprovado por teste: deal com line item "Serviço X" sem mapping → empresa criada sem o contrato → admin cadastra `Servico` + `HubspotLineItemMapping` → replay cria o contrato faltante com o `servico_id` correto.
- Log estruturado `[Hubspot] Replay: ...` no canal `ecf-webhooks` (início, falha de fetch de contatos, conclusão) — nunca loga token/secret.
- Gate de regressão: `php artisan test --filter=Hubspot` → 89/89 verdes (86 pré-existentes + 3 novos do replay).

## Task Commits

Cada task foi commitada atomicamente (TDD RED→GREEN):

1. **Task 1: Método público reprocessarEvento() (reuso do fetch + criarEmpresa)**
   - `ba80380e` test(114-03): RED - comando hubspot:reprocess-event ainda nao existe
   - `29a6fba5` feat(114-03): reprocessarEvento publico no HubspotWebhookController
2. **Task 2: Comando hubspot:reprocess-event + suite Phase114HubspotReplayTest**
   - `1afb850c` feat(114-03): comando hubspot:reprocess-event (HUB-REPLAY-01) — 3/3 verdes
3. **Task 3: Gate de regressão do webhook HubSpot**
   - Sem commit de código — `php artisan test --filter=Hubspot` já 89/89 verde após a Task 2, nenhum ajuste necessário no controller.

**Plan metadata:** (commit final abaixo, junto com STATE.md/ROADMAP.md)

## Files Created/Modified
- `app/Http/Controllers/Api/HubspotWebhookController.php` — novo método público `reprocessarEvento()` + helper privado `contarTentativasContrato()`; `processar()` inalterado (nenhuma regressão)
- `app/Console/Commands/ReprocessHubspotEvent.php` (novo) — comando Artisan `hubspot:reprocess-event {id}`
- `tests/Feature/Phase114HubspotReplayTest.php` (novo) — 3 testes: efeito prático (mapping cadastrado → contrato criado), idempotência (2 execuções sem duplicar), id inexistente (erro amigável)

## Decisions Made
- `reprocessarEvento()` sempre refetch via `HubspotApiClient` (dados frescos da API), não lê `hubspot_snapshot` — mais simples e com paridade total de comportamento com o webhook original; discrição concedida pelo CONTEXT.md da fase.
- Detecção de `empresas_enriquecidas` via `Company::wasRecentlyCreated` (flag nativa do Eloquent: `true` só quando `Company::create()` rodou nesta chamada) — evita reimplementar a lógica de match forte/fraco do `HubspotCompanyMatcher` só para fins de relatório.
- `contratos_ignorados` é uma estimativa em modo leitura (`contarTentativasContrato()`, espelha a resolução de mapping ativo de `HubspotDealHandoffService::build()`), sem alterar a assinatura de `persistirContratos()` (conforme instrução do plano) — não é asserido explicitamente pelos testes, só reportado no resumo/tabela do comando.
- `processar()` do webhook NÃO foi refatorado para delegar a `reprocessarEvento()` (opção mencionada como opcional no plano) — priorizei regressão zero sobre DRY, já que qualquer refactor arriscaria o fluxo de ingestão em produção sem ganho funcional.

## Deviations from Plan

None - plano executado exatamente como especificado.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Uso operacional: `php artisan hubspot:reprocess-event {id}` no servidor (VPS), pelo id de `hubspot_eventos`.

## Next Phase Readiness

HUB-REPLAY-01 fechado — loop operacional completo: quando um deal cai com line item não reconhecido, o admin cadastra o `HubspotLineItemMapping` (UI já existente da Fase 37) e roda o replay para materializar o contrato, sem esperar um novo webhook. Independente do Plan 114-02 (frontend), que segue pendente separadamente. Pronto para a Fase 115 (E2E + doc da regra de valor).

---
*Phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: app/Console/Commands/ReprocessHubspotEvent.php
- FOUND: tests/Feature/Phase114HubspotReplayTest.php
- FOUND: app/Http/Controllers/Api/HubspotWebhookController.php
- FOUND: .planning/phases/114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0/114-03-SUMMARY.md
- FOUND commit: ba80380e
- FOUND commit: 29a6fba5
- FOUND commit: 1afb850c
