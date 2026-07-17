---
phase: 96
slug: nps-anti-burlamento-endurecimento-e-gest-o
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-07-17
---

# Phase 96 — Validation Strategy

> Contrato de validação por fase. A parte mais sensível é AB-96-3: a invalidação NÃO pode
> vazar em nenhum dos 8 call-sites que alimentam bônus/dashboards.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x + AssertableInertia |
| **Config file** | `phpunit.xml` (SQLite in-memory) |
| **Quick run command** | `php artisan test --filter=Phase96` |
| **Full suite command** | `php artisan test --filter=Nps` + `php artisan test --filter=V16` + `php artisan test --filter=Desempenho` (regressão do BÔNUS — obrigatória) |
| **Estimated runtime** | ~90-180 segundos |

---

## Sampling Rate

- **After every task commit:** `php artisan test --filter=Phase96`
- **After every plan wave:** `--filter=Nps` (264 baseline) + suítes do bônus (V16/Desempenho); `npm run build` se tocou frontend
- **Before `/gsd:verify-work`:** Nps + V16 + Desempenho verdes + `npm run build` exit 0
- **Max feedback latency:** 180 segundos

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| (preencher pelo planner) | — | — | AB-96-1 | Bloqueio sessão interna | Submit logado bloqueado + evento `blocked` | feature | `php artisan test --filter=Phase96` | ❌ W0 | ⬜ pending |
| (preencher pelo planner) | — | — | AB-96-2 | IPs pela UI | Só admin edita; união .env∪UI | feature | `php artisan test --filter=Phase96` | ❌ W0 | ⬜ pending |
| (preencher pelo planner) | — | — | AB-96-3 | Invalidação | Resposta invalidada SAI dos 8 call-sites do bônus + cache forget | feature | `php artisan test --filter=Phase96` + suítes bônus | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase96/` — bloqueio de submit logado (AB-96-1), config de IPs pela UI (AB-96-2), invalidação com prova de exclusão em CADA call-site do bônus/dashboard + `Cache::forget` (AB-96-3)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Mensagem amigável ao usuário interno bloqueado | AB-96-1 | Julgamento visual/UX | Logar como usuário interno, abrir link NPS, tentar responder — confirmar mensagem clara (não erro cru) |
| Tela de config de IPs no dark theme | AB-96-2 | Julgamento visual | Admin edita IPs em NPS>Configuração; confirmar salvar/validar |
| Ação de invalidar na listagem + sumiço das médias | AB-96-3 | Confirmação em dados reais | Admin invalida uma resposta; conferir que NPS médio/bônus do profissional recalcula |
| Preencher IPs reais da ECF em produção | AB-96-2 | Dado operacional | Pós-deploy: cadastrar os IPs/CIDRs reais pela nova UI (resolve a pendência da Fase 94) |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] No watch-mode flags
- [x] Regressão do bônus (V16/Desempenho) no gate final
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-07-17 (plan-checker: PASSED após 1 revisão — call-site 10 fechado)
