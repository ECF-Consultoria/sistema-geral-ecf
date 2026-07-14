---
phase: 75
slug: empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-14
---

# Phase 75 — Validation Strategy

> Contrato de validação por-phase para amostragem de feedback durante a execução.
> Detalhe completo em `75-RESEARCH.md` → seção "## Validation Architecture".

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (Feature tests, `tests/Feature/`) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Phase75` |
| **Full suite command** | `php artisan test tests/Feature/Phase75` |
| **Estimated runtime** | ~15-30 s (subconjunto Phase 75) |

> Nota (STATE.md): a suíte completa pode estourar `Maximum execution time of 300s` por causa de `SyncGrantsFromEcfDrive` — rodar o subconjunto Phase 75 e módulos adjacentes (Comercial, Companies, Nps) em lotes.

---

## Sampling Rate

- **Após cada commit de task:** `php artisan test --filter=Phase75`
- **Após cada wave:** `php artisan test tests/Feature/Phase75` + regressão do módulo tocado (Comercial / Companies / Nps)
- **Antes de `/gsd:verify-work`:** subconjunto Phase 75 verde + zero regressão nos módulos adjacentes
- **Max feedback latency:** ~30 s

---

## Per-Task Verification Map

> A preencher pelo planner (os task IDs ainda não existem). Cobertura mínima exigida pelo RESEARCH.md:

| Área (decisão) | Requisito | Tipo de prova | Comando |
|---|---|---|---|
| Migration enum→'shopee' persiste em **SQLite** (CHECK enforçado) | DEC-1 | Feature (migration + create Servico setor=shopee) | `php artisan test --filter=Phase75` |
| Seed serviço "Shopee" idempotente (rodar up() 2× não duplica) | DEC-1 | Feature | idem |
| Cadastro empresa Shopee **sem ML** não cria `MlbEmpresa` e salva | DEC-1 | Feature (`ComercialController::store`) | idem |
| Aba Shopee filtra só contrato setor='shopee' (exclui ML-only) | DEC-4 | Feature (`ShopeeEmpresasController@index`) | idem |
| Pendências: `sem_responsavel`, `sem_contato` (email E digisac vazios), `empresa_nova` | DEC-2 | Feature (asserção do payload) | idem |
| Atribuição pivot: "Analista"→role `consultor`, "Estrategista"→`estrategista` | DEC-4 | Feature | idem |
| Gate `shopee.empresas`: admin vê; user sem key → 403; user com key → 200 | DEC-3 | Feature (`EnsurePermission`) | idem |
| NPS gerável para empresa Shopee (sem métrica) | DEC-5 | Feature (`POST /nps/generate`) | idem |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Phase75/` — diretório de testes da phase
- [ ] Factory/fixtures: `Servico` setor=shopee, `Company` sem `adman_account_id`/`ml_store_id`, pivot `company_users`

*Infraestrutura PHPUnit já existe; sem framework novo.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Menu "Shopee → Empresas" aparece pro Setor Shopee | DEC-3 | RBAC via setor criado manualmente pelo usuário | Criar Setor Shopee em `/setores`, dar key `shopee.empresas`, logar como membro, confirmar item no sidebar |
| Botão "Gerar NPS" abre o fluxo NPS correto | DEC-4 | Deep-link/UX visual | Clicar na linha da empresa Shopee → confirmar link NPS gerado |

*Demais comportamentos têm verificação automatizada.*

---

## Validation Sign-Off

- [ ] Todas as tasks têm verify `<automated>` ou dependência de Wave 0
- [ ] Continuidade de amostragem: sem 3 tasks consecutivas sem verify automatizado
- [ ] Wave 0 cobre todas as referências MISSING
- [ ] Sem flags de watch-mode
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` no frontmatter

**Approval:** pending
