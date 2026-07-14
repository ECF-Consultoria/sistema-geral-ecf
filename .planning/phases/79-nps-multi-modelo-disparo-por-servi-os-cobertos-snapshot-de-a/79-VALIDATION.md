---
phase: 79
slug: nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-14
---

# Phase 79 — Validation Strategy

> Detalhe no 79-RESEARCH.md → "## Validation Architecture".

## Test Infrastructure
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (Feature, `tests/Feature/V16/`) |
| Quick run | `php artisan test tests/Feature/V16` (PHP em /c/xampp/php/php.exe) |
| Regressão NPS/bônus | `php artisan test --filter=Nps` + `--filter=Desempenho` |

## Per-Task Verification Map
| Prova | Decisão | Comando |
|---|---|---|
| 3 tabelas novas (schema + FKs) cross-driver | DEC-79-C | `php artisan test tests/Feature/V16` |
| Seed NPS Shopee (template+perguntas+opções+scope shopee) idempotente | DEC-79-B | idem |
| Disparo estrito: performance→Padrão, shopee→Shopee, ambos→2, sem cobertura→0+log; dedup por template_id | DEC-79-A | idem |
| Submit: nps_response_scores por dimensão + nps_response_covered_services + nps_score_assignments (dentro da transação, após answers) | DEC-79-D | idem |
| Atribuição por serviço: NPS Shopee → média analista vai pro analista Shopee (servico_id), NÃO pro ML; responsável faltante → sem assignment + pendência | DEC-79-D | idem |
| Média empresa em scores (dimensao=empresa), sem assignment de pessoa | DEC-79-D | idem |
| Regressão: submit legacy Phase 31 + v15 + bônus `->principal()` inalterados | DEC-79-E | `--filter=Nps` + `--filter=Desempenho` |

## Wave 0
- [ ] `tests/Feature/V16/` existe. Fixtures: template + service_scopes + company_users servico_id (reusar CriaCenarioResponsaveis + helpers NPS v15).

## Manual-Only
| Behavior | Steps |
|---|---|
| Rollout do disparo estrito | Pós-deploy no VPS: rodar `nps:disparar-mensal --dry-run` e conferir o Log::warning das empresas sem serviço coberto (quem ficaria sem NPS) antes do envio real |
| FK MySQL das tabelas novas | Validar no VPS: migrate + SHOW CREATE TABLE das 3 tabelas |

## Sign-Off
- [x] nyquist_compliant: true

**Approval:** pending
