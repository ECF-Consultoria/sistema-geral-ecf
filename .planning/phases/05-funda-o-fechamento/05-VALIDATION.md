---
phase: 5
slug: funda-o-fechamento
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-19
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --filter=Fechamento` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 segundos |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Fechamento`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 20 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 5-01-01 | 01 | 1 | FCH-01/02/03 | — | Somente admin acessa /financeiro | integration | `php artisan test --filter=FechamentoMigrationTest` | ❌ W0 | ⬜ pending |
| 5-01-02 | 01 | 1 | FCH-02 | — | service_type aceita apenas polo/assessoria/incubadora | unit | `php artisan test --filter=CompanyServiceTypeTest` | ❌ W0 | ⬜ pending |
| 5-02-01 | 02 | 2 | FCH-01 | — | fechamento() retorna apenas empresas ativas com has_adman | feature | `php artisan test --filter=AdminFechamentoControllerTest` | ❌ W0 | ⬜ pending |
| 5-02-02 | 02 | 2 | FCH-02/03 | — | updateFechamento() valida e persiste campos | feature | `php artisan test --filter=AdminFechamentoControllerTest` | ❌ W0 | ⬜ pending |
| 5-03-01 | 03 | 3 | CFG-01 | — | N/A | manual | — | ✅ | ⬜ pending |
| 5-03-02 | 03 | 3 | FCH-01 | — | Badge "Sem integração" aparece no browser | manual | — | ✅ | ⬜ pending |
| 5-03-03 | 03 | 3 | FCH-02/03 | — | Accordion abre, salva, exibe flash de sucesso | manual | — | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/AdminFechamentoControllerTest.php` — stubs para FCH-01, FCH-02, FCH-03
- [ ] `tests/Unit/CompanyServiceTypeTest.php` — stubs para validação de service_type
- [ ] `tests/Feature/FechamentoMigrationTest.php` — assertions sobre colunas da tabela companies

*Infraestrutura PHPUnit já existe (phpunit.xml e base TestCase em Phase 1). Apenas os arquivos de teste são novos.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Label "Fechamento" no sidebar | CFG-01 | Mudança de string em JSX — sem teste PHPUnit | Abrir a aplicação no browser e verificar nav lateral |
| Badge "Sem integração" visível | FCH-01 | Renderização React — sem teste PHPUnit | Verificar empresa sem adman_account_id na lista |
| Accordion expande e fecha | FCH-02/03 | Interação UI — sem teste PHPUnit | Clicar no nome de empresa, verificar campos; clicar novamente, verificar fechamento |
| Dados persistem após reload | FCH-02/03 | Fluxo ponta-a-ponta | Salvar tipo de serviço e datas; recarregar página; confirmar valores |
| Carbon serialization: input date value | FCH-03 | Bug latente (Carbon → toDateString) | Após salvar data, reabrir accordion e confirmar que `<input type="date">` exibe a data corretamente |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 20s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
