---
phase: 94
slug: nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-16
---

# Phase 94 — Validation Strategy

> Contrato de validação por fase para amostragem de feedback durante a execução.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` (SQLite in-memory) |
| **Quick run command** | `php artisan test --filter=Phase94` |
| **Full suite command** | `php artisan test --filter=Nps` (suite NPS completa — 207 testes baseline verde) |
| **Estimated runtime** | ~30-90 segundos |

---

## Sampling Rate

- **After every task commit:** Rodar `php artisan test --filter=Phase94`
- **After every plan wave:** Rodar `php artisan test --filter=Nps` (regressão — baseline 207/207)
- **Before `/gsd:verify-work`:** Suite NPS completa verde
- **Max feedback latency:** 120 segundos

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| (preencher pelo planner) | — | — | AB-94-1..5 | — | Coleta silenciosa; nenhum dado técnico vaza para a UX pública | feature | `php artisan test --filter=Phase94` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase94AntiBurlamentoTest.php` — stubs cobrindo AB-94-1..5 (abertura registra rastro, resposta registra rastro+veredito, eventos emitidos, regras de suspeita, legado não quebra)

*Infra existente cobre o resto: factories `NpsSurvey`/`NpsResponse` já usadas pela suite Nps (207 testes).*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| IP real do cliente atrás do proxy do VPS | AB-94-4 (Regra 1) | Topologia de rede de produção não reproduzível em teste (trustProxies ausente em `bootstrap/app.php` — Pitfall #1 do RESEARCH) | Pós-deploy: abrir link NPS de fora da rede ECF e conferir IP registrado ≠ IP do servidor/proxy; abrir da rede interna e conferir marcação |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
