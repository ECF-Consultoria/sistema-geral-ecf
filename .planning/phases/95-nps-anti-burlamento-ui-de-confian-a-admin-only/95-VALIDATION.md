---
phase: 95
slug: nps-anti-burlamento-ui-de-confian-a-admin-only
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-16
---

# Phase 95 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x + AssertableInertia |
| **Config file** | `phpunit.xml` (SQLite in-memory) |
| **Quick run command** | `php artisan test --filter=Phase95` (namespace `tests/Feature/Phase95/`) |
| **Full suite command** | `php artisan test --filter=Nps` (baseline pós-Fase 94: 250/250) + `npm run build` (gate de frontend) |
| **Estimated runtime** | ~60-120 segundos |

---

## Sampling Rate

- **After every task commit:** Rodar `php artisan test --filter=Phase95`
- **After every plan wave:** Rodar `php artisan test --filter=Nps` (baseline 250/250); `npm run build` se a wave tocou frontend
- **Before `/gsd:verify-work`:** Suite Nps completa verde + `npm run build` exit 0
- **Max feedback latency:** 120 segundos

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| (preencher pelo planner) | — | — | AB-95-1..4 | Blindagem server-side | Payload de não-admin sem `confianca`/`auditoria`/IPs/UA | feature (AssertableInertia has/missing) | `php artisan test --filter=Phase95` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase95/` — testes de payload por role (molde: `tests/Feature/V16/NpsResponsaveisPayloadTest.php` — helpers `propsDoIndex`/`admin()`), badge tri-estado, filtro server-side, seção de auditoria

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Aparência do badge/seção no dark theme | AB-95-1/2 | Julgamento visual | Checkpoint visual pós-execução: abrir a listagem NPS como admin, conferir badge verde/amarelo/vermelho e seção de auditoria; abrir como consultor e confirmar tela idêntica à atual |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] No watch-mode flags
- [x] Feedback latency < 120s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending (atualizar após plan-check)
