---
phase: 137
slug: fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-09-02
---

# Phase 137 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (phpunit.xml na raiz) |
| **Config file** | phpunit.xml |
| **Quick run command** | `C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase137"` |
| **Full suite command** | `C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase122\|Phase136\|Phase137"` |
| **Estimated runtime** | ~90 segundos (gate filtrado) |

> ⚠️ A suíte COMPLETA (`vendor/bin/phpunit` sem filtro) **não termina neste ambiente**: morre em
> `MercadoLivreAdsService` por limite de 300s do PHP, e as ~12 falhas de Polos são anteriores a
> qualquer trabalho desta fase (documentadas em `.planning/learnings/`). O gate da fase é o
> filtrado acima — não use "suíte completa verde" como critério, porque ele é inalcançável aqui.

---

## Sampling Rate

- **After every task commit:** Rodar o gate filtrado da fase
- **After every plan wave:** Rodar o gate filtrado + as fases vizinhas que compartilham codigo
- **Before `/gsd:verify-work`:** o gate filtrado da fase verde (ver aviso acima — a suíte sem
  filtro não termina neste ambiente)
- **Max feedback latency:** ~120 segundos

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 137-01-01 | 01 | 1 | REQ-{XX} | T-137-01 / — | {expected secure behavior or "N/A"} | unit | `{command}` | ✅ / ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `{tests/test_file.py}` — stubs for REQ-{XX}
- [ ] `{tests/conftest.py}` — shared fixtures
- [ ] `{framework install}` — if no framework detected

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| {behavior} | REQ-{XX} | {reason} | {steps} |

*If none: "All phase behaviors have automated verification."*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 137s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** {pending / approved YYYY-MM-DD}
