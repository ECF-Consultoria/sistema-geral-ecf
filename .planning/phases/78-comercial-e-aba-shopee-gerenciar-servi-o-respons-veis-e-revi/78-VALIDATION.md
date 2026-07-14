---
phase: 78
slug: comercial-e-aba-shopee-gerenciar-servi-o-respons-veis-e-revi
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-14
---

# Phase 78 — Validation Strategy

> Detalhe no 78-CONTEXT.md → "## Validation Architecture". (RESEARCH.md ficou parcial — agente interrompido por limite de sessão; análise incorporada inline nos planos.)

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (Feature, `tests/Feature/V16/`) |
| Quick run | `php artisan test tests/Feature/V16` (PHP em /c/xampp/php/php.exe) |
| Frontend | `npm run build` (gotcha: variável de escopo em `.map()` — ver memória) |

## Per-Task Verification Map
| Prova | Decisão | Comando |
|---|---|---|
| Selects escopados: index retorna só analistas/estrategistas do Setor Shopee | DEC-78-1 | `php artisan test tests/Feature/V16` |
| Pendência `sem_responsavel` usa responsável do SERVIÇO Shopee (multi-marketplace: ML analista não conta) | DEC-78-1/2 | idem |
| Resolver: atribui analista/estrategista Shopee (servico_id) + email → pendências somem | DEC-78-2 | idem |
| Excluir = cancela só o contrato Shopee (`ativo=false`); empresa + contrato ML permanecem; sai da aba Shopee | DEC-78-4 | idem |
| RBAC/escopo: resolver/cancelar gated `shopee.empresas` + guard Shopee (fora do escopo → 403/422) | DEC-78-4 | idem |
| Comercial: adicionar serviço Shopee com responsáveis grava por-serviço sem tocar ML | DEC-78-5 | idem |
| Frontend: `Shopee/Empresas.jsx` sem `nps.generate` (grep) + build verde | DEC-78-3 | `npm run build` |

## Wave 0
- [ ] `tests/Feature/V16/` existe (Phase 76). Reusar `CriaCenarioResponsaveis`.

## Manual-Only
| Behavior | Steps |
|---|---|
| Popup Resolver visual + selects Shopee + Excluir | Checkpoint humano pós-build: abrir /shopee/empresas, aba Pendências, Resolver → atribuir Felipe/Gustavo, Excluir cancela serviço |

## Sign-Off
- [x] nyquist_compliant: true

**Approval:** pending
