---
phase: 96-nps-anti-burlamento-endurecimento-e-gest-o
plan: 01
subsystem: nps
tags: [laravel, http-request, phpunit, tdd, audit-trail, inertia]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    provides: "NpsSuspicionService::evaluate() (Regra 4 = isAuthenticatedSession), tabela nps_survey_events + model NpsSurveyEvent (6 event_types), NpsController::submitResponse()/submitResponseV15()/submitResponseLegacy() instrumentados"
provides:
  - "submitResponse() bloqueia ANTES de qualquer NpsResponse::create() quando auth()->check() (sessão interna) — upgrade da Regra 4, que antes só marcava"
  - "7º event_type 'blocked' em nps_survey_events, auditando quem tentou (user_id/ip/user_agent)"
  - "Página pública Nps/Blocked.jsx (molde Expired.jsx) — mensagem amigável pt-BR sem revelar o gatilho"
  - "tests/Feature/Phase96/NpsBloqueioSessaoInternaTest.php — cobertura completa do bloqueio + regressão GET/anônimo"
affects: [96-02-ips-internos-ui, 96-03-invalidacao-manual]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bloqueio no ponto de entrada compartilhado (submitResponse(), ANTES do discriminador v15/legado) — mesmo princípio anti-duplicação da Fase 94, nunca replicar a checagem dentro de cada path"
    - "Migration enum+SQLite (branch por driver) reaplicada pela 3ª vez no módulo NPS — padrão totalmente consolidado"
    - "Testes E2E que simulam admin gerando link + cliente respondendo agora precisam de logout() explícito entre as duas fases (sessão de teste persiste entre requests)"

key-files:
  created:
    - database/migrations/2026_07_17_090001_add_blocked_event_type_to_nps_survey_events.php
    - resources/js/Pages/Nps/Blocked.jsx
    - tests/Feature/Phase96/NpsBloqueioSessaoInternaTest.php
  modified:
    - app/Models/NpsSurveyEvent.php
    - app/Http/Controllers/NpsController.php
    - tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php
    - tests/Feature/Phase94/NpsSurveyEventsTest.php
    - tests/Feature/Phase73/NpsV15E2ETest.php

key-decisions:
  - "Bloqueio implementado 1x em submitResponse(), não duplicado em submitResponseV15()/Legacy() — é o único ponto que os dois paths compartilham antes de qualquer create()"
  - "metadata=null no evento 'blocked' — o próprio user_id já é o sinal suficiente, sem necessidade de detalhar o motivo (decisão já sugerida no RESEARCH)"
  - "Mensagem de Nps/Blocked.jsx evita as palavras IP/login/sessão interna/usuário interno — não revela o mecanismo de detecção ao usuário bloqueado (per CONTEXT AB-96-1)"
  - "3 testes pré-existentes (Phase73/Phase94) que assumiam sessão autenticada sendo ACEITA (marcar mas não bloquear) foram corrigidos — um deles já trazia o comentário 'Fase 96 fara o bloqueio', confirmando que a mudança de comportamento era esperada"

requirements-completed: [AB-96-1]

# Metrics
duration: ~55min
completed: 2026-07-17
---

# Phase 96 Plan 01: Bloqueio de Sessão Interna (AB-96-1) Summary

**Submit NPS (POST) em sessão autenticada de usuário interno passa a ser BLOQUEADO antes de qualquer `NpsResponse::create()`, com evento `blocked` auditado (7º event_type) e página `Nps/Blocked.jsx` amigável — upgrade da Regra 4 da Fase 94, que antes só marcava a resposta como suspeita.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-07-17T (ver commit 1c2b08c)
- **Completed:** 2026-07-17
- **Tasks:** 2 completed
- **Files modified:** 8 (3 criados, 5 modificados — incluindo 3 testes pré-existentes ajustados por regressão esperada)

## Accomplishments
- Migration `add_blocked_event_type_to_nps_survey_events` adiciona o 7º valor `blocked` ao enum `nps_survey_events.event_type`, seguindo EXATAMENTE o padrão enum+SQLite já comprovado 2x no módulo (branch MySQL faz `ALTER ... MODIFY COLUMN ENUM(...)`, branch SQLite recria a coluna como `string(20)` sem CHECK) — `down()` idempotente que aborta com `RuntimeException` se existirem eventos `blocked` órfãos
- `NpsSurveyEvent::TYPE_BLOCKED = 'blocked'` + entrada em `TYPES`
- `NpsController::submitResponse()` intercepta `auth()->check()` logo após o guard de expiração e ANTES do discriminador `template_id !== null` (único ponto compartilhado pelos dois paths v15/legado) — cria o evento `blocked` (survey_id, ip_address, user_agent, user_id, metadata=null) e retorna `Inertia::render('Nps/Blocked')` sem tocar em `NpsResponse`/status do survey
- `resources/js/Pages/Nps/Blocked.jsx` — página pública full-screen no molde exato de `Expired.jsx`, ícone `ShieldAlert`, mensagem que orienta abrir o link em janela anônima/outro navegador, sem citar IP/login/sessão/usuário interno
- `npm run build` executado com sucesso (exit 0) — página registrada no manifest do Vite
- Suíte `Phase96/NpsBloqueioSessaoInternaTest` 5/5 verde: constante+persistência do enum, bloqueio+auditoria do submit logado, submit anônimo intacto, GET logado intacto (emite `opened` normalmente)
- Baseline completo `--filter=Nps`: **269/269 passando** (264 anterior + 5 novos deste plano), 0 falhas

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1: Migration do 7º event_type `blocked` + constante no model + teste RED** - `1c2b08c` (test)
2. **Task 2: Interceptar submit de sessão interna em submitResponse() + página Blocked.jsx** - `e9ccd3f` (feat)

_TDD: Task 1 deixou o cenário de bloqueio propositalmente RED (só a parte de migration/constante ficou GREEN); Task 2 fechou o GREEN e, ao rodar a regressão completa, revelou 3 testes pré-existentes com asserções desatualizadas — corrigidos no mesmo commit (ver Deviations)._

## Files Created/Modified
- `database/migrations/2026_07_17_090001_add_blocked_event_type_to_nps_survey_events.php` - 7º event_type `blocked`, branch MySQL/SQLite
- `app/Models/NpsSurveyEvent.php` - `TYPE_BLOCKED` + entrada em `TYPES`
- `app/Http/Controllers/NpsController.php` - `submitResponse()` intercepta sessão autenticada ANTES do dispatch v15/legado
- `resources/js/Pages/Nps/Blocked.jsx` - página pública de bloqueio (novo)
- `tests/Feature/Phase96/NpsBloqueioSessaoInternaTest.php` - suíte completa AB-96-1 (novo)
- `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php` - cenário 6 reescrito: sessão autenticada agora é BLOQUEADA, não aceita+marcada
- `tests/Feature/Phase94/NpsSurveyEventsTest.php` - timeline E2E manual: logout do admin antes do "cliente" abrir/responder
- `tests/Feature/Phase73/NpsV15E2ETest.php` - E2E v15: logout do admin antes do "cliente" abrir/responder

## Decisions Made
- Bloqueio implementado uma única vez em `submitResponse()`, nunca duplicado dentro de `submitResponseV15()`/`submitResponseLegacy()` — mesmo princípio anti-duplicação já documentado na Fase 94 para o helper `capturarRastroEAvaliarSuspeita()`
- `metadata: null` no evento `blocked` — o `user_id` já é o sinal suficiente, sem necessidade de detalhar o motivo (a Regra 4 é a única fonte possível de bloqueio nesta fase)
- Mensagem de `Nps/Blocked.jsx` evita citar o mecanismo de detecção (IP/login/sessão/usuário interno), conforme decisão travada do CONTEXT AB-96-1
- `NpsSurvey.status` permanece `pending` após um bloqueio — nenhuma transição de estado, consistente com a decisão da Fase 96 de não reabrir/alterar o survey em cenários de rejeição técnica

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug/Regressão esperada] Cenário 6 de `NpsResponseTrailAndSuspicionTest` assumia sessão autenticada ACEITA (comportamento pré-96)**
- **Found during:** Task 2, ao rodar `php artisan test --filter=Nps` (regressão completa pós-implementação)
- **Issue:** `test_sessao_autenticada_marca_suspeita_mas_aceita_normalmente()` — escrito no Plano 94-02 — asserta `assertOk()` + `status === 'completed'` para um POST autenticado como admin. O próprio comentário do teste já dizia *"Marca suspeita mas NAO bloqueia (Fase 96 fara o bloqueio)"*, confirmando que a quebra era o comportamento ESPERADO desta fase, não um bug introduzido por acidente.
- **Fix:** Teste renomeado para `test_sessao_autenticada_e_bloqueada_pelo_endurecimento_da_fase_96()`; asserções trocadas para `assertDatabaseCount` implícito via `NpsResponse::where(...)->count() === 0` e `status === 'pending'` (sem transição). A cobertura completa do bloqueio (evento `blocked`, `Inertia::render`) já está no novo `Phase96/NpsBloqueioSessaoInternaTest.php` — este teste do Phase94 passou a documentar apenas que o comportamento antigo (marcar sem bloquear) não existe mais.
- **Files modified:** `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php`
- **Verification:** `php artisan test --filter=NpsResponseTrailAndSuspicionTest` verde (8/8)
- **Committed in:** `e9ccd3f` (parte do commit GREEN da Task 2)

**2. [Rule 1 - Bug/Regressão esperada] 2 testes E2E reutilizavam a sessão admin para simular o "cliente" respondendo**
- **Found during:** Task 2, mesma rodada de regressão
- **Issue:** `Phase94/NpsSurveyEventsTest::test_timeline_e2e_fluxo_manual_generated_opened_opened_submitted()` e `Phase73/NpsV15E2ETest::test_e2e_v15_fluxo_completo_...()` fazem `actingAs($admin)` para gerar o survey/template via UI admin e, SEM logout, continuam usando o mesmo client de teste para o GET/POST do "cliente" — isso nunca corresponderia a um cenário real (o cliente do link é sempre uma pessoa externa, não o admin que gerou o link na mesma aba), mas só ficou exposto agora que o bloqueio existe.
- **Fix:** Inserido `$this->post(route('logout'))->assertRedirect()` entre as etapas administrativas e as etapas de "cliente" em ambos os testes — simula corretamente um respondente anônimo/externo, sem alterar a asserção de negócio de nenhum dos dois testes (o evento `generated` continua carregando o `user_id` do admin, capturado ANTES do logout).
- **Files modified:** `tests/Feature/Phase94/NpsSurveyEventsTest.php`, `tests/Feature/Phase73/NpsV15E2ETest.php`
- **Verification:** `php artisan test --filter="NpsSurveyEventsTest|NpsV15E2ETest"` verde (18/18); baseline completo `--filter=Nps` 269/269
- **Committed in:** `e9ccd3f` (parte do commit GREEN da Task 2)

---

**Total deviations:** 2 auto-fixados (ambos Rule 1 — regressões ESPERADAS pelo próprio endurecimento que este plano implementa, uma delas já anunciada em comentário de código da Fase 94)
**Impact on plan:** Nenhum scope creep — as 3 correções pertencem exatamente à área de código que este plano toca (fluxo de submit NPS) e uma delas já estava documentada como pendência conhecida desde a Fase 94. Sem essas correções, a suíte `--filter=Nps` não fecharia verde, violando o critério de verificação do próprio plano.

## Issues Encountered
Nenhum problema de ambiente (PATH do PHP precisou ser exportado manualmente na sessão do Bash tool — `/c/xampp/php` — mas isso é característica do ambiente, não um bloqueio do plano).

## User Setup Required
None — nenhuma configuração de serviço externo necessária. Migration roda automaticamente no próximo `php artisan migrate` (dev/staging/produção).

## Next Phase Readiness
AB-96-1 completo e testado. `NpsSurveyEvent` já tem os 7 event_types que a Fase 96 precisa (nenhum 8º tipo previsto). Pronto para:
- **Plano 96-02 (AB-96-2)**: IPs/CIDRs internos configuráveis pela UI — não depende de nada deste plano, mas deve reusar a mesma leitura de `auth()->check()` já validada aqui como ponto de referência de "sessão interna"
- **Plano 96-03 (AB-96-3)**: invalidação manual de resposta — independente deste plano; a flag `blocked` (nps_survey_events) e `invalidated_at` (nps_responses, plano 03) são conceitos DIFERENTES — um é preventivo (nunca vira response), o outro é corretivo pós-fato (response existe mas some das agregações)

Nenhum bloqueio identificado. Nenhuma mudança de rota necessária (`POST /nps/{token}` já era pública, sem middleware).

## Self-Check: PASSED

Arquivos criados confirmados em disco:
- `database/migrations/2026_07_17_090001_add_blocked_event_type_to_nps_survey_events.php` — FOUND
- `resources/js/Pages/Nps/Blocked.jsx` — FOUND
- `tests/Feature/Phase96/NpsBloqueioSessaoInternaTest.php` — FOUND

Commits confirmados via `git log --oneline`:
- `1c2b08c` (test, Task 1) — FOUND
- `e9ccd3f` (feat, Task 2) — FOUND

---
*Phase: 96-nps-anti-burlamento-endurecimento-e-gest-o*
*Completed: 2026-07-17*
