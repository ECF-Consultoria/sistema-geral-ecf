---
phase: 115-suite-e2e-documenta-o-da-regra-de-valor-v20-0
plan: 02
subsystem: testing
tags: [phpunit, http-fake, monolog, hubspot, laravel]

requires:
  - phase: 113-enriquecimento-dedup-fase-113
    provides: HubspotContactSelector, HubspotCompanyMatcher, snapshot completo
  - phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0
    provides: comando hubspot:reprocess-event, listagem Comercial enriquecida
provides:
  - Auditoria com rastreabilidade SC4/SC5 nas 2 suites nucleares de replay e listagem
  - Suite nova Phase115HubspotInvariantesTest com os 2 invariantes transversais de segurança da milestone
  - Fix de bug: line_items_nao_mapeados não limpava do payload após replay materializar o contrato
affects: [115-03, verificação final v20.0]

tech-stack:
  added: []
  patterns:
    - "Teste-guarda de invariante transversal: Http::preventStrayRequests() + assertSent(host) cobrindo webhook E replay sob a mesma guarda"
    - "Monolog\\Handler\\TestHandler injetado via Log::channel(x)->getLogger()->pushHandler() para asserção de ausência de segredo em log"

key-files:
  created:
    - tests/Feature/Phase115HubspotInvariantesTest.php
  modified:
    - tests/Feature/Phase114HubspotReplayTest.php
    - tests/Feature/Phase114ComercialListagemEnrichmentTest.php
    - app/Http/Controllers/Api/HubspotWebhookController.php

key-decisions:
  - "persistirContratos() agora limpa a chave line_items_nao_mapeados do payload quando o mapping é cadastrado depois e o replay materializa o contrato faltante (antes ficava fantasma)"
  - "Suite de invariantes cobre webhook E replay sob a MESMA guarda Http::preventStrayRequests, não só o caminho de criação"

patterns-established:
  - "Teste-guarda dedicado para invariante de segurança transversal (não amarrado a um único cenário de negócio) — reusa fixtures completas (deal+company+contato+line_item) para exercitar o fluxo real"

requirements-completed: [HUB-TEST-04, HUB-TEST-05]

duration: ~25min
completed: 2026-07-24
---

# Phase 115 Plan 02: Suite E2E + Documentação da Regra de Valor Summary

**Auditoria de replay/listagem com rastreabilidade SC4/SC5 + suite nova de invariantes transversais (zero rede real HubSpot + zero token no log ecf-webhooks), incluindo fix de bug real encontrado durante a auditoria (pendência fantasma pós-replay).**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-07-24T23:42:36Z
- **Completed:** 2026-07-24T23:56:01Z
- **Tasks:** 3/3
- **Files modified:** 4 (1 criado, 3 modificados)

## Accomplishments
- SC4 (HUB-TEST-04, replay) auditado e reforçado: o efeito prático da pendência (line item sem mapping) agora é comprovado ponta a ponta — não só o contrato passa a existir, mas a pendência some de `line_items_nao_mapeados` no payload do evento.
- SC5 (HUB-TEST-05, listagem) auditado: todos os campos do critério (contato, observação, confiança, warning, `valor_revisar` só-HubSpot) já estavam literalmente assertados — rastreabilidade documentada via docblock.
- SC5 (HUB-TEST-05, transversais) fechado: suite nova `Phase115HubspotInvariantesTest` prova os 2 invariantes de segurança da milestone de forma explícita (não mais implícita via `Http::fake` espalhado): zero rede real (webhook + replay) e zero token/segredo no log `ecf-webhooks`.
- Bug real corrigido (Rule 1): `persistirContratos()` nunca limpava a pendência `line_items_nao_mapeados` do payload quando ela deixava de existir (mapping cadastrado depois + replay) — ficava uma pendência "fantasma" mesmo após a resolução de verdade.

## Task Commits

Each task was committed atomically:

1. **Tarefa 1: Auditar Phase114HubspotReplayTest (HUB-TEST-04)** - `f33cefde` (test + fix)
2. **Tarefa 2: Auditar Phase114ComercialListagemEnrichmentTest (HUB-TEST-05 — listagem)** - `43576772` (docs)
3. **Tarefa 3: Criar Phase115HubspotInvariantesTest** - `073a742f` (test)

**Plan metadata:** (a seguir, commit final desta entrega)

## Files Created/Modified
- `tests/Feature/Phase115HubspotInvariantesTest.php` - Suite nova: 2 invariantes transversais (zero rede real + zero token no log)
- `tests/Feature/Phase114HubspotReplayTest.php` - Docblock de rastreabilidade SC4 + assert reforçado de que a pendência some do payload após o replay
- `tests/Feature/Phase114ComercialListagemEnrichmentTest.php` - Docblock de rastreabilidade SC5 (listagem) → método
- `app/Http/Controllers/Api/HubspotWebhookController.php` - `persistirContratos()`: limpa `line_items_nao_mapeados` do payload quando não há mais itens sem mapping (fix de bug, Rule 1)

## Decisions Made
- Reforçar o assert de "efeito prático" no teste de replay em vez de criar um teste novo — mantém a suite focada por cenário e evita duplicação (a suíte já tinha o `HubspotEvento::first()` e o payload prontos).
- A suite de invariantes cobre TANTO o webhook quanto o replay sob a mesma guarda `Http::preventStrayRequests`, porque ambos os caminhos refazem os mesmos fetches HTTP e o critério de aceite da milestone exige zero rede real nos dois.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `persistirContratos()` não limpava `line_items_nao_mapeados` do payload após o replay resolver a pendência**
- **Found during:** Tarefa 1 (ao reforçar o assert do efeito prático do replay)
- **Issue:** O código só gravava `payload['line_items_nao_mapeados']` quando havia itens sem mapping (`!empty($naoMapeados)`); quando o replay rodava depois com o mapping já cadastrado, `$naoMapeados` ficava vazio e a chave antiga NUNCA era removida — a pendência continuava aparecendo no payload mesmo já resolvida de verdade.
- **Fix:** Adicionado ramo `elseif` que remove (`unset`) a chave do payload quando `$naoMapeados` está vazio e a chave existia antes.
- **Files modified:** `app/Http/Controllers/Api/HubspotWebhookController.php`
- **Verification:** `php artisan test --filter=Phase114HubspotReplayTest` (3/3) e gate `--filter=Hubspot` (91/91) verdes.
- **Committed in:** `f33cefde` (Tarefa 1)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug)
**Impact on plan:** Fix necessário para correção funcional (a pendência deixava de refletir o estado real após o replay); nenhum scope creep — mudança mínima e cirúrgica, sem tocar em nenhum outro comportamento.

## Issues Encountered
None além do bug documentado acima, que foi encontrado e corrigido durante a própria auditoria (exatamente o objetivo da Tarefa 1).

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- SC4 e SC5 (listagem + transversais) fechados e rastreados; gate `--filter=Hubspot` 91/91 verde (89 pré-existentes + 2 novos), zero regressão.
- Falta apenas o Plan 115-03 (documentação da regra de valor) para fechar a Fase 115 por completo.

---
*Phase: 115-suite-e2e-documenta-o-da-regra-de-valor-v20-0*
*Completed: 2026-07-24*

## Self-Check: PASSED

Todos os arquivos criados/modificados encontrados no disco; todos os 3 commits de tarefa (`f33cefde`, `43576772`, `073a742f`) confirmados em `git log`.
