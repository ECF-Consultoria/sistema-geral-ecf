---
phase: 77
slug: setor-shopee-organizacional-cargos-e-usu-rios-felipe-gustavo
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-14
---

# Phase 77 — Validation Strategy

> Detalhe em `77-CONTEXT.md` → "## Validation Architecture".

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (Feature, `tests/Feature/V16/`) |
| **Config** | `phpunit.xml` (SQLite `:memory:`) |
| **Quick run** | `php artisan test tests/Feature/V16/SetorShopeeSeedTest.php` |
| **Runtime** | ~10 s |

## Per-Task Verification Map

| Prova | Decisão | Comando |
|---|---|---|
| Setor `shopee` + cargos analista/estrategista criados | DEC-77-1/2 | `php artisan test tests/Feature/V16/SetorShopeeSeedTest.php` |
| `setor_permissoes: shopee.empresas` vinculada | DEC-77-3 | idem |
| Felipe (email) → estrategista Shopee + líder (`isLider`) | DEC-77-4 | idem |
| Gustavo (email) → analista Shopee (+ Performance se existir) | DEC-77-4 | idem |
| Idempotência (rodar 2×) + skip gracioso (sem emails) + down() | DEC-77-1 | idem |

## Wave 0 Requirements
- [ ] `tests/Feature/V16/` já existe (Phase 76). Reusar `CriaCenarioResponsaveis` se útil; senão fixtures locais via factory/DB::table.

## Manual-Only Verifications
| Behavior | Why | Steps |
|---|---|---|
| Felipe/Gustavo reais vinculados em prod | dados dependem dos emails existirem no banco de prod | Pós-deploy: conferir em `/setores` que Shopee tem Felipe (estrategista+líder) e Gustavo (analista) |

## Validation Sign-Off
- [x] `nyquist_compliant: true`

**Approval:** pending
