---
phase: 1
slug: diagn-stico-adman
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-18
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` (raiz do projeto) |
| **Quick run command** | `php artisan test --filter DevControllerTest` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 seconds (quick) / ~60 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter DevControllerTest`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| migration | W0 | 0 | DEV-01/02/03 | — | N/A | feature | `php artisan migrate --env=testing` | ❌ W0 | ⬜ pending |
| DevControllerTest::test_index_retorna_empresas | W0 | 0 | DEV-01 | — | Rota protegida por `role:admin` | feature | `php artisan test --filter test_index_retorna_empresas_com_synced_at` | ❌ W0 | ⬜ pending |
| DevControllerTest::test_index_retorna_raw_data | W0 | 0 | DEV-02 | — | Payload bruto retornado como prop | feature | `php artisan test --filter test_index_retorna_raw_data_no_payload` | ❌ W0 | ⬜ pending |
| DevControllerTest::test_index_retorna_diff | W0 | 0 | DEV-03 | — | Diff criados/atualizados/ignorados nas props | feature | `php artisan test --filter test_index_retorna_diff_do_ultimo_log` | ❌ W0 | ⬜ pending |
| DevControllerTest::test_dispatch_sync | W0 | 0 | DEV-04 | — | Job enfileirado; flash success retornado | feature | `php artisan test --filter test_dispatch_sync_enfileira_job` | ❌ W0 | ⬜ pending |
| DevControllerTest::test_dispatch_sem_admin | W0 | 0 | DEV-04 | Access Control | POST retorna 403 para não-admin | feature | `php artisan test --filter test_dispatch_sync_rejeita_nao_admin` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/DevControllerTest.php` — cobre DEV-01 (synced_at), DEV-02 (raw_data), DEV-03 (diff criados/atualizados/ignorados), DEV-04 (dispatch job + flash + 403 para não-admin)
- [ ] `database/migrations/2026_05_18_XXXXXX_create_adman_sync_logs_table.php` — tabela `adman_sync_logs` necessária antes de qualquer teste

*Framework PHPUnit já instalado e configurado em `phpunit.xml` — sem instalação nova necessária.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Accordion expand/collapse visual | DEV-02 | Comportamento de UI não coberto por testes PHP | Abrir `/dev/desenvolvimento`, clicar em empresa → verificar que accordion expande; clicar novamente → colapsa |
| Toast de sucesso após dispatch | DEV-04 | Flash toast renderizado via AppLayout; não testável via PHPUnit feature test | Clicar "Disparar sync" → verificar toast "Sync enfileirado para [Empresa]" aparece |
| Job executado pelo worker | DEV-04 | Depende de queue worker rodando | `php artisan queue:work --once` após dispatch → verificar `adman_sync_logs` recebe nova linha |
| Botão disabled durante loading | DEV-04 | Estado de UI React local | Clicar "Disparar sync" → verificar botão fica com `animate-spin` e texto "Disparando..." |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
