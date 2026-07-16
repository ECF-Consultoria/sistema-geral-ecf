---
phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
plan: 02
subsystem: nps
tags: [laravel, http-request, phpunit, tdd, audit-trail]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    plan: 01
    provides: colunas de rastro (nps_surveys/nps_responses), tabela nps_survey_events, model NpsSurveyEvent, config/nps.php, NpsSuspicionService::evaluate()
provides:
  - "NpsController::respond() grava rastro de abertura (first_opened_at/last_opened_at/open_count/open_ip_address/open_user_agent) em TODO GET, mesmo completed/expired"
  - "Eventos nps_survey_events 'opened' (toda abertura) e 'expired' (unico ponto do codebase que transiciona status)"
  - "Helper privado capturarRastroEAvaliarSuspeita() compartilhado entre submitResponseV15()/submitResponseLegacy() — grava rastro de resposta + veredito do NpsSuspicionService na MESMA linha do create"
  - "suspicion_reasons persistido como objeto {reasons, severity} (shape pronto para a Fase 95 consumir)"
  - "Evento 'submitted' emitido DENTRO da transacao (reverte junto se o dedup 23000 estourar) + evento 'generated' no link manual (metadata.origem=manual)"
affects: [94-03-nps-disparar-mensal-instrumentado, 95-ui-confianca-admin, 96-endurecimento-bloqueio]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper privado compartilhado entre os 2 paths de submit (v15/legado) — nunca duplicar logica de captura/suspeita entre eles (pitfall real ja documentado no projeto)"
    - "Evento de auditoria emitido DENTRO da mesma DB::transaction do dominio (mesmo padrao do NpsSnapshotService) — reverte junto em caso de rollback"
    - "Rastro roda ANTES dos early-returns de completed/expired — reabertura de link vencido/ja respondido tambem gera sinal tecnico"

key-files:
  created:
    - tests/Feature/Phase94/NpsOpenTrailTest.php
    - tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php
    - tests/Feature/Phase94/NpsSurveyEventsTest.php
  modified:
    - app/Http/Controllers/NpsController.php
    - tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php

key-decisions:
  - "suspicion_reasons persiste como objeto {reasons: [...], severity: 'media'|'alta'} — decisao travada do plano, pronta para a Fase 95 sem migration nova"
  - "response_duration_seconds usa survey->created_at->diffInSeconds(now()) — ordem CRITICA no Carbon 3 (signed diff); NAO inverter"
  - "Evento 'expired' so existe no GET (respond()) — POST expirado retorna 422 sem persistir status, decisao ja resolvida no RESEARCH (Open Question 2, nao criar 7o event_type)"

requirements-completed: [AB-94-1, AB-94-2, AB-94-3, AB-94-4]

# Metrics
duration: ~35min
completed: 2026-07-16
---

# Phase 94 Plan 02: NpsController Instrumentado Summary

**Instrumentação cirúrgica do `NpsController` — rastro de abertura em todo GET, rastro de resposta + veredito de suspeita nos dois paths de submit via helper compartilhado, e trilha de eventos `opened`/`expired`/`submitted`/`generated` — zero mudança na UX pública, 241/241 testes Nps passando.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-07-16T19:19:00Z
- **Completed:** 2026-07-16T19:54:37Z
- **Tasks:** 3 completed
- **Files modified:** 5 (3 testes novos, 1 controller instrumentado, 1 teste antigo corrigido)

## Accomplishments
- `NpsController::respond()` ganhou `Request $request` e grava o rastro de abertura (`first_opened_at`/`last_opened_at`/`open_count`/`open_ip_address`/`open_user_agent`) logo após o `firstOrFail()`, ANTES dos early-returns de `completed`/`expired` — reaberturas preservam `first_opened_at` (nunca sobrescrito) e incrementam `open_count`
- Evento `opened` emitido em TODO GET (metadata `first_open` calculado após o incremento) e evento `expired` emitido no único ponto do codebase que transiciona `status` para `expired`
- Helper privado `capturarRastroEAvaliarSuspeita()` compartilhado entre `submitResponseV15()` e `submitResponseLegacy()` — grava `response_ip_address`/`response_user_agent`/`response_duration_seconds` + `is_suspicious`/`suspicion_reasons` na MESMA linha do `NpsResponse::create()`, sem UPDATE extra
- Helper `registrarEventoSubmitted()` emite o evento `submitted` DENTRO da mesma `DB::transaction()`, logo antes do update de `status='completed'` — comprovado que reverte junto quando o guard `QueryException 23000` (dedup mensal) estoura
- Evento `generated` emitido no link manual (`NpsController::generate()`) com `metadata.origem='manual'` — discriminador contra `disparo_mensal` (Plano 94-03)
- Payload Inertia público de `Nps/Respond` permanece byte-idêntico (teste asserta exatamente as 6 chaves de `survey`)
- Regressão completa: **241/241 testes Nps passando** (221 baseline pós-94-01 + 20 novos deste plano), 0 falhas

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1: Rastro de abertura + eventos opened/expired (AB-94-1)**
   - `b298b4f` (test) — RED: NpsOpenTrailTest + cenários opened/expired em NpsSurveyEventsTest
   - `fa8a6f6` (feat) — GREEN: instrumentação do `respond()`
2. **Task 2: Rastro de resposta + suspeita + evento submitted (AB-94-2/AB-94-4)**
   - `f94d950` (test) — RED: NpsResponseTrailAndSuspicionTest + cenários submitted/dedup
   - `882a9dd` (feat) — GREEN: helpers `capturarRastroEAvaliarSuspeita`/`registrarEventoSubmitted` + fix do teste legado desatualizado (Rule 1)
3. **Task 3: Evento generated no link manual + regressão Nps (AB-94-3)**
   - `2b729b0` (test) — RED: cenários generated-manual em NpsSurveyEventsTest
   - `3f580ce` (feat) — GREEN: instrumentação do `generate()` + regressão completa 241/241

_TDD: cada task teve commit RED e GREEN separados, conforme exigido pelo plano._

## Files Created/Modified
- `app/Http/Controllers/NpsController.php` - `respond()`/`submitResponseV15()`/`submitResponseLegacy()`/`generate()` instrumentados; 2 helpers privados novos (`capturarRastroEAvaliarSuspeita`, `registrarEventoSubmitted`)
- `tests/Feature/Phase94/NpsOpenTrailTest.php` - cobertura AB-94-1 (primeira abertura, reabertura, completed, payload)
- `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php` - cobertura AB-94-2 + AB-94-4 nos 2 paths (v15/legado), 4 regras via HTTP, duração, resposta limpa, POST inválido
- `tests/Feature/Phase94/NpsSurveyEventsTest.php` - cobertura AB-94-3 completa: opened/expired/submitted/generated + dedup 23000 sem evento órfão
- `tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php` - assertions do teste `test_post_nps_respond_legado_completo_funciona` atualizadas (ver Deviations)

## Decisions Made
- `suspicion_reasons` persiste como objeto `{reasons, severity}` (não array simples) — shape travado no plano, pronto para a Fase 95 consumir sem migration nova
- `response_duration_seconds` calculado via `$survey->created_at->diffInSeconds(now())` — ordem crítica no Carbon 3 (diff signed); documentado em comentário único (grep gate = 1)
- Evento `expired` só existe no fluxo GET (`respond()`) — POST em survey vencida retorna 422 sem persistir status nem emitir evento (decisão já resolvida no RESEARCH, não criar 7º `event_type`)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Corrigida asserção desatualizada em NpsAntiBurlamentoBackwardCompatTest**
- **Found during:** Task 2 (rastro de resposta + suspeita)
- **Issue:** `test_post_nps_respond_legado_completo_funciona` (escrito no Plano 94-01, ANTES de qualquer instrumentação existir) esperava `response_ip_address`/`response_user_agent` sempre `null` e `is_suspicious` sempre `false` em qualquer POST. Isso contradiz diretamente a decisão travada do CONTEXT AB-94-2 ("Registrar SEMPRE, para todo submit — a coleta é silenciosa e universal"), que este mesmo plano implementa.
- **Fix:** Atualizadas as asserções para refletir o comportamento correto e esperado: trail preenchido (IP do request de teste) e `is_suspicious=true` com motivo da Regra 2 (survey criada e respondida na mesma passada, duração ~0s cai na janela default de 60s) — comportamento correto do `NpsSuspicionService`, não uma quebra.
- **Files modified:** `tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php`
- **Verification:** `php artisan test --filter=NpsAntiBurlamentoBackwardCompatTest` verde (5/5)
- **Committed in:** `882a9dd` (parte do commit GREEN da Task 2)

---

**Total deviations:** 1 auto-fixado (1 bug — asserção de teste desatualizada)
**Impact on plan:** Correção necessária e diretamente causada pela instrumentação exigida por este mesmo plano (AB-94-2). Nenhum scope creep — o teste corrigido pertence à mesma área de código (fluxo de submit NPS) e a mudança apenas alinha a expectativa ao comportamento explicitamente especificado no CONTEXT.md.

## Issues Encountered

Nenhum problema de ambiente nesta sessão (PATH do PHP já ajustado desde o plano 94-01).

## User Setup Required

None — nenhuma configuração de serviço externo necessária. A verificação manual de topologia de proxy do VPS (Pitfall 1 do RESEARCH, necessária para a Regra 1 do `NpsSuspicionService` funcionar corretamente em produção com o IP real do cliente) continua atribuída ao plano 94-03, conforme já decidido no RESEARCH (Open Question 1).

## Next Phase Readiness

`NpsController` totalmente instrumentado — dados de auditoria técnica (rastro de abertura, rastro de resposta, veredito de suspeita, trilha de eventos) agora NASCEM em produção a partir do primeiro deploy pós-merge. Pronto para:
- **Plano 94-03**: instrumentar `NpsDispararMensal`/`NpsDigisacDispatchService` (eventos `generated`/`sent_email`/`sent_digisac` do disparo mensal automático) + checkpoint de verificação de topologia de proxy do VPS
- **Fase 95**: UI admin-only de confiança/auditoria — consumir `is_suspicious`/`suspicion_reasons` (shape `{reasons, severity}`) e a timeline de `nps_survey_events`

Nenhum bloqueio identificado. Payload público intocado — zero impacto em `resources/js/`.

## Self-Check: PASSED

Todos os 5 arquivos declarados (3 testes criados + 2 modificados, incluindo este SUMMARY) confirmados em disco. Todos os 6 commits de task (`b298b4f`, `fa8a6f6`, `f94d950`, `882a9dd`, `2b729b0`, `3f580ce`) confirmados via `git log --oneline --all`.

---
*Phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba*
*Completed: 2026-07-16*
