---
phase: 76
slug: respons-veis-por-servi-o-company-users-com-dimens-o-de-servi
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-14
---

# Phase 76 — Validation Strategy

> Contrato de validação Nyquist. Detalhe em `76-RESEARCH.md` → "## Validation Architecture".

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (Feature, `tests/Feature/V16/`) |
| **Config** | `phpunit.xml` (SQLite `:memory:`) |
| **Quick run** | `php artisan test tests/Feature/V16` |
| **Full (regressão bônus)** | `php artisan test --filter=Portfolio && php artisan test --filter=Desempenho && php artisan test --filter=Nps` |
| **Runtime** | ~20-40 s |

> Testes rodam em `tests/Feature/V16/` (NÃO `Phase76/` — colide com anunciar-ml Phase77-81). O branch MySQL da FK NÃO é exercitado em SQLite — validar no VPS antes de deploy.

## Wave 0 Requirements

- [ ] Criar `tests/Feature/V16/` (não existe).
- [ ] Criar `ServicoFactory` + `ContratoServicoFactory` (não existem — só Company/User/CompanyMarketplace) OU usar `DB::table` no setup.

## Per-Task Verification Map (por prova exigida)

| Prova | Decisão | Tipo | Comando |
|---|---|---|---|
| a) Migration adiciona `servico_id` + unique `(company_id,user_id,role,servico_id)` persiste em SQLite | DEC-A1 | Feature | `php artisan test tests/Feature/V16` |
| b) Data-migration idempotente: linha ML→servico performance; empresa sem performance→NULL; rodar 2× não muda | DEC-A1 | Feature | idem |
| c) INVARIANTE: leitores consolidados (`Company::consultor/estrategista`, carteira `User::companies`) retornam o MESMO resultado com/sem `servico_id`; `->distinct()` evita double-count quando existe linha Shopee futura | DEC-A2 | Feature (regressão) | idem + filtros Portfolio/Desempenho |
| d) Atribuição Shopee (`bulkAssign` servico=shopee) NÃO apaga a linha ML e vice-versa (isolamento por servico_id; gotcha whereNull) | DEC-A3 | Feature | idem |
| e) Regressão bônus/carteira sem novas falhas | DEC-A2 | Feature | `--filter=Portfolio/Desempenho/Nps` |

*Status: ⬜ pending · ✅ green · ❌ red*

## Manual-Only Verifications

| Behavior | Why Manual | Steps |
|----------|------------|-------|
| Branch MySQL da FK `servico_id` | SQLite não exercita FK em ALTER | Validar no VPS pós-deploy: `migrate` + conferir constraint em `company_users` |

## Validation Sign-Off
- [ ] Todas as tasks com verify automatizado ou Wave 0
- [ ] Invariante consolidado provado (teste c)
- [ ] Isolamento ML×Shopee provado (teste d)
- [ ] Regressão bônus/carteira verde
- [x] `nyquist_compliant: true`

**Approval:** pending
