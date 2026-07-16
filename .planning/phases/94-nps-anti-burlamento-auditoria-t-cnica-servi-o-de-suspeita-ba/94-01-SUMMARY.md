---
phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
plan: 01
subsystem: nps
tags: [laravel, eloquent, migrations, symfony-ip-utils, tdd]

# Dependency graph
requires:
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    provides: NpsSurvey/NpsResponse com template_id, NpsTemplate/NpsTemplateQuestion
  - phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
    provides: padrão de migration cross-driver + NpsSnapshotService (stateless via container)
provides:
  - "Colunas de rastro de abertura (first_opened_at/last_opened_at/open_count/open_ip_address/open_user_agent) em nps_surveys"
  - "Colunas de rastro de resposta + suspeita (response_ip_address/response_user_agent/response_duration_seconds/is_suspicious/suspicion_reasons) em nps_responses"
  - "Tabela nps_survey_events (6 event_types) — auditoria técnica append-only"
  - "Model NpsSurveyEvent + factory + relação NpsSurvey::events()"
  - "config/nps.php (anti_burlamento.internal_ips/internal_cidrs/fast_response_window_seconds) 100% via .env"
  - "NpsSuspicionService::evaluate() — 4 regras de suspeita com textos pt-BR travados"
affects: [94-02-nps-controller-instrumentado, 94-03-nps-disparar-mensal-instrumentado, 95-ui-confianca-admin]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "NpsSuspicionService stateless em App\\Services\\Nps, resolvido via app(), mesmo padrão de NpsSnapshotService/NpsPendingService"
    - "Symfony\\Component\\HttpFoundation\\IpUtils::checkIp() para matching IP/CIDR (IPv4+IPv6) sem hand-roll"
    - "config/nps.php com env() + array_filter/trim — nunca hardcode de IPs/janela"

key-files:
  created:
    - database/migrations/2026_07_16_100001_add_open_trail_to_nps_surveys_table.php
    - database/migrations/2026_07_16_100002_add_response_trail_and_suspicion_to_nps_responses_table.php
    - database/migrations/2026_07_16_100003_create_nps_survey_events_table.php
    - app/Models/NpsSurveyEvent.php
    - database/factories/NpsSurveyEventFactory.php
    - config/nps.php
    - app/Services/Nps/NpsSuspicionService.php
    - tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php
    - tests/Feature/Phase94/NpsSuspicionServiceTest.php
  modified:
    - app/Models/NpsSurvey.php
    - app/Models/NpsResponse.php
    - .env.example

key-decisions:
  - "config/nps.php nasce como arquivo NOVO (não chave em config existente) — CONTEXT A5"
  - "suspicion_reasons persiste array de strings (não objeto com severity embutido) — severity é retornado separadamente pelo service e cabe ao controller (plano 94-02) decidir como combinar no JSON"
  - "Nenhum controller/command foi tocado neste plano — 94-01 é fundação isolada, instrumentação real é 94-02/94-03"

requirements-completed: [AB-94-3, AB-94-4, AB-94-5]

# Metrics
duration: 9min
completed: 2026-07-16
---

# Phase 94 Plan 01: Fundação Anti-Burlamento NPS Summary

**Schema de rastro (abertura + resposta) e tabela `nps_survey_events` + `NpsSuspicionService` com 4 regras de suspeita (IP interno, resposta rápida, combinação, sessão autenticada), 100% configurável via `.env`, sem tocar em nenhum controller de produção.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-07-16T16:14:28-03:00
- **Completed:** 2026-07-16T16:22:49-03:00
- **Tasks:** 3 completed
- **Files modified:** 12 (9 criados, 3 estendidos)

## Accomplishments
- 3 migrations idempotentes (guard `Schema::hasColumn`/`Schema::hasTable`) adicionam rastro nullable em `nps_surveys`/`nps_responses` e criam `nps_survey_events` com os 6 `event_type` travados — sem tocar no enum `nps_surveys.status`
- `NpsSurveyEvent` model + factory + relação `NpsSurvey::events()` prontos para os emissores dos planos 94-02/94-03
- `config/nps.php` expõe `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS`/`NPS_SUSPICION_WINDOW_SECONDS` via `.env` — zero hardcode
- `NpsSuspicionService::evaluate()` avalia as 4 regras com `Symfony\Component\HttpFoundation\IpUtils::checkIp()` (sem hand-roll de CIDR), textos pt-BR exatos e severidade alta/media/nenhuma
- AB-94-5 comprovado: survey/response legados (sem os campos novos) continuam com defaults corretos (null/0/false); fluxo GET/POST legado completo (`Nps/Respond` → `Nps/ThankYou`) intacto
- Regressão completa da suite Nps: **221/221 passando** (207 baseline + 14 novos deste plano), 0 falhas

## Task Commits

Each task was committed atomically (TDD RED → GREEN):

1. **Task 1: Migrations de rastro + tabela nps_survey_events + models + factory**
   - `4d44de9` (test) — RED: schema/defaults asserts
   - `c84dab4` (feat) — GREEN: migrations + models + factory
2. **Task 2: config/nps.php + NpsSuspicionService com as 4 regras (TDD)**
   - `2ba7a65` (test) — RED: 9 cenários das 4 regras
   - `2ec6d9d` (feat) — GREEN: config/nps.php + NpsSuspicionService
3. **Task 3: Retrocompatibilidade AB-94-5 — legado intacto + regressão Nps completa**
   - `4a9f056` (test) — assert componente `Nps/ThankYou` + regressão Nps 221/221 confirmada

_TDD: cada task teve commit RED e GREEN separados, conforme exigido pelo plano._

## Files Created/Modified
- `database/migrations/2026_07_16_100001_add_open_trail_to_nps_surveys_table.php` - 5 colunas nullable de rastro de abertura em `nps_surveys`
- `database/migrations/2026_07_16_100002_add_response_trail_and_suspicion_to_nps_responses_table.php` - 5 colunas nullable de rastro/suspeita em `nps_responses`
- `database/migrations/2026_07_16_100003_create_nps_survey_events_table.php` - tabela `nps_survey_events` (6 event_types, FKs, índices)
- `app/Models/NpsSurveyEvent.php` - model com constantes `TYPE_*`, cast `metadata` array, relações `survey()`/`user()`
- `database/factories/NpsSurveyEventFactory.php` - factory mínima do evento
- `app/Models/NpsSurvey.php` - fillable/casts estendidos + relação `events()`
- `app/Models/NpsResponse.php` - fillable/casts estendidos (rastro + suspeita)
- `config/nps.php` - config `anti_burlamento` (internal_ips/internal_cidrs/janela) via `.env`
- `.env.example` - chaves `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS`/`NPS_SUSPICION_WINDOW_SECONDS`
- `app/Services/Nps/NpsSuspicionService.php` - serviço stateless com as 4 regras
- `tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php` - schema + defaults + fluxo legado GET/POST
- `tests/Feature/Phase94/NpsSuspicionServiceTest.php` - 9 cenários das 4 regras

## Decisions Made
- `config/nps.php` como arquivo novo (não seção em `config/digisac.php` ou outro existente) — não havia config NPS-específica prévia, decisão A5 do RESEARCH aplicada sem ajuste
- `suspicion_reasons` persiste como array simples de strings (cast `array` no model); a `severity` retornada pelo service fica para o controller decidir o shape final do JSON no plano 94-02 — evita acoplar o service a uma decisão de schema que ainda não foi tomada
- Testes de defaults (`open_count`, `is_suspicious`) usam `refresh()` após `::create()` para ler os defaults aplicados pelo BANCO (via migration), não os atributos locais do insert — evita falso-negativo de teste

## Deviations from Plan

None - plan executado exatamente como especificado. Os únicos ajustes foram nos próprios arquivos de teste (Task 1) durante o ciclo TDD (adição de `refresh()` para ler defaults de banco), sem qualquer mudança de código de produção fora do escopo do plano.

## Issues Encountered

`php` não estava no `PATH` do shell Bash usado por este executor (ambiente Windows/Git Bash) — resolvido adicionando `/c/xampp/php` ao `PATH` da sessão antes de cada chamada `php artisan test`. Não é um deviation de código, apenas ajuste de ambiente de execução.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. `.env.example` documenta as 3 novas chaves (`ECF_INTERNAL_IPS`, `ECF_INTERNAL_CIDRS`, `NPS_SUSPICION_WINDOW_SECONDS`) com defaults seguros (vazio/60s) — preencher `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS` no `.env` real do VPS é responsabilidade do plano 94-03 (checkpoint de topologia de proxy, conforme RESEARCH Q1).

## Next Phase Readiness

Schema, model, factory e `NpsSuspicionService` prontos para os planos 94-02 (`NpsController` instrumentado — captura de abertura/resposta + chamada ao service) e 94-03 (`NpsDispararMensal` instrumentado — eventos `generated`/`sent_email`/`sent_digisac` + verificação de topologia de proxy no VPS). Nenhum bloqueio identificado.

## Self-Check: PASSED

Todos os 10 arquivos declarados (9 criados + este SUMMARY) confirmados em disco via `[ -f ... ]`.
Todos os 5 commits de task (`4d44de9`, `c84dab4`, `2ba7a65`, `2ec6d9d`, `4a9f056`) confirmados via `git log --oneline --all`.

---
*Phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba*
*Completed: 2026-07-16*
