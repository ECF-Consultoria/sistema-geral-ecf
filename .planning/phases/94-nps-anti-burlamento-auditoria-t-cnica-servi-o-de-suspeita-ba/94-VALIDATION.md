---
phase: 94
slug: nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-16
---

# Phase 94 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.
> Atualizado após o plan-check (2026-07-16) para refletir a estrutura final de 3 planos.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` (SQLite in-memory) |
| **Quick run command** | `php artisan test --filter=Phase94` (namespace `tests/Feature/Phase94/`) |
| **Full suite command** | `php artisan test --filter=Nps` (suite NPS completa — baseline 207/207 verde) |
| **Estimated runtime** | ~30-90 segundos |

---

## Sampling Rate

- **After every task commit:** Rodar `php artisan test --filter=Phase94`
- **After every plan wave:** Rodar `php artisan test --filter=Nps` (regressão — baseline 207/207)
- **Before `/gsd:verify-work`:** Suite NPS completa verde + suites V16 (126)
- **Max feedback latency:** 120 segundos

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 94-01-01 | 01 | 1 | AB-94-3, AB-94-5 | — | Campos nullable, zero backfill | feature | `php artisan test --filter=NpsSurveyEventsTest` | ❌ W0 | ⬜ pending |
| 94-01-02 | 01 | 1 | AB-94-4 | Regras 1-4 | Motivos pt-BR; severidade no JSON | feature | `php artisan test --filter=NpsSuspicionServiceTest` | ❌ W0 | ⬜ pending |
| 94-01-03 | 01 | 1 | AB-94-5 | — | Legado intacto | feature | `php artisan test --filter=NpsAntiBurlamentoBackwardCompatTest` | ❌ W0 | ⬜ pending |
| 94-02-01 | 02 | 2 | AB-94-1 | — | Nenhum dado técnico no payload público | feature | `php artisan test --filter=NpsOpenTrailTest` | ❌ W0 | ⬜ pending |
| 94-02-02 | 02 | 2 | AB-94-2, AB-94-4 | Dual-path v15/legacy | `suspicion_reasons` nunca renderizado ao respondente | feature | `php artisan test --filter=NpsResponseTrailAndSuspicionTest` | ❌ W0 | ⬜ pending |
| 94-02-03 | 02 | 2 | AB-94-3 | — | Evento só após create (caso negativo coberto) | feature | `php artisan test --filter=NpsSurveyEventsTest` | ❌ W0 | ⬜ pending |
| 94-03-* | 03 | 3 | AB-94-3, AB-94-5 | — | Timeline E2E + gate regressão | feature | `php artisan test --filter=NpsSurveyEventsTest && php artisan test --filter=Nps` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase94/NpsSurveyEventsTest.php` — trilha de eventos (AB-94-3, todos os emissores)
- [ ] `tests/Feature/Phase94/NpsSuspicionServiceTest.php` — 4 regras de suspeita (AB-94-4)
- [ ] `tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php` — retrocompat legado (AB-94-5)
- [ ] `tests/Feature/Phase94/NpsOpenTrailTest.php` — rastro de abertura (AB-94-1)
- [ ] `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php` — rastro de resposta dual-path (AB-94-2)

*Infra existente cobre o resto: factories `NpsSurvey`/`NpsResponse` já usadas pela suite Nps (207 testes); `Phase31NpsSubmitTest` permanece como guarda de regressão do submit legado.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| IP real do cliente atrás do proxy do VPS | AB-94-4 (Regra 1) | Topologia de rede de produção não reproduzível em teste (trustProxies ausente em `bootstrap/app.php` — Pitfall #1 do RESEARCH; checkpoint incluído no plano 94-03) | Pós-deploy: abrir link NPS de fora da rede ECF e conferir `open_ip_address` = IP público real do dispositivo (≠ IP do servidor/proxy); abrir da rede interna e conferir marcação de suspeita |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 120s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-07-16 (plan-checker: VERIFICATION PASSED, 0 blockers)
