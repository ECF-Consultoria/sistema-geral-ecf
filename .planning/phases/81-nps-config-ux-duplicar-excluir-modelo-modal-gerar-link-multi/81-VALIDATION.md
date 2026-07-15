---
phase: 81
slug: nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-14
---

# Phase 81 — Validation Strategy

> Detalhe no 81-RESEARCH.md → "## Validation Architecture".

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (Feature, `tests/Feature/V16/`) |
| Quick run | `php artisan test tests/Feature/V16` (PHP em /c/xampp/php/php.exe) |
| Frontend | `npm run build` (gotcha var em `.map()`) |

## Per-Task Verification Map
| Prova | Decisão | Comando |
|---|---|---|
| Duplicar: clona template+perguntas+opções+scopes com is_default=false; original intocado | DEC-81-1 | `php artisan test tests/Feature/V16` |
| Excluir: destroy de modelo sem respostas + não-principal funciona; is_default → bloqueado; modelo com respostas → bloqueado (422, sugere arquivar) — histórico preservado | DEC-81-2 | idem |
| Empresas elegíveis por template: modelo com scopes → só empresas com serviço coberto ativo (Shopee→só Shopee); modelo sem scopes → todas (fallback); não-admin escopado por carteira | DEC-81-3 | idem |
| Modal: modelo-first (obrigatório) + filtro reativo; build verde | DEC-81-3 | `npm run build` |
| Regressão: CRUD de templates (store/update/toggleActive/setPrincipal) + gerar-link atuais | — | `--filter=Nps` |

## Wave 0
- [ ] `tests/Feature/V16/` existe. Fixtures: template + questions/options + service_scopes + companies com contratos (reusar CriaCenarioResponsaveis + helpers NPS).

## Manual-Only
| Behavior | Steps |
|---|---|
| Modal gerar-link multi-step | Checkpoint visual: /nps → Gerar Link → escolher modelo Shopee → só empresas Shopee aparecem; duplicar/excluir na /nps/configuracao |

## Sign-Off
- [x] nyquist_compliant: true

**Approval:** pending
