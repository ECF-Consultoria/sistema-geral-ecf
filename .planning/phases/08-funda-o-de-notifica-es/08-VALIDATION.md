---
phase: 8
slug: funda-o-de-notifica-es
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-21
---

# Phase 8 — Estratégia de Validação (Nyquist)

> Contrato de validação per-phase para sampling de feedback durante a execução. Fonte das decisões: `08-RESEARCH.md` §Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.50 (já instalado via `composer.json` line 28) |
| **Config file** | `phpunit.xml` (testsuites: Unit, Feature; `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`) |
| **Quick run command** | `php artisan test --filter=Phase8FoundationTest` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~2 segundos para o filter (suíte da fase apenas, 7 testes, SQLite in-memory) |

---

## Sampling Rate

- **Após cada commit de tarefa:** `php artisan test --filter=Phase8FoundationTest`
- **Após cada wave do plano:** `php artisan test --testsuite=Feature`
- **Antes de `/gsd:verify-work`:** `php artisan test` (Unit + Feature) verde
- **Max feedback latency:** ~2s (filter da fase) / ~30s (Feature suite completa)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 8-01-01 | 01 | 1 | (infra) | — | Tabela `notifications` existe com 9 colunas canônicas após migration | feature | `php artisan test --filter=Phase8FoundationTest::test_migration_cria_tabela_notifications` | ❌ W0 | ⬜ pending |
| 8-02-01 | 02 | 2 | (infra) | — | Enum `Categoria` retorna `'manual'` para `MANUAL->value` (round-trip via `Categoria::from`) | feature | (coberto por Test 7 / smoke) | ❌ W0 | ⬜ pending |
| 8-02-02 | 02 | 2 | (infra) | V5 Input Validation | `BaseNotification::toArray()` retorna 6 chaves canônicas; `via()` retorna `['database']` | feature | `php artisan test --filter=Phase8FoundationTest::test_base_notification_persiste_payload_canonico` | ❌ W0 | ⬜ pending |
| 8-02-03 | 02 | 2 | (infra) | — | `Notification::send($user, new TestAnon())` persiste 1 linha em `notifications` com `data` contendo as 6 chaves canônicas (titulo, mensagem, categoria, autor_user_id, url, meta) | feature | `php artisan test --filter=Phase8FoundationTest::test_base_notification_persiste_payload_canonico` | ❌ W0 | ⬜ pending |
| 8-03-01 | 03 | 3 | PERM-01 | V4 Access Control | `Permissions::all()` contém `'notificacoes.criar'`; `Permissions::NOTIFICACOES_CRIAR` é a constante | feature | `php artisan test --filter=Phase8FoundationTest::test_permissions_all_inclui_notificacoes_criar` | ❌ W0 | ⬜ pending |
| 8-03-02 | 03 | 3 | PERM-01 | V4 Access Control | `Permissions::catalog()['Notificações']` existe com label `'Criar notificações'` e descrição pt-BR mencionando "manuais" | feature | `php artisan test --filter=Phase8FoundationTest::test_catalog_inclui_grupo_notificacoes` | ❌ W0 | ⬜ pending |
| 8-03-03 | 03 | 3 | PERM-03 | V4 Access Control | `Permissions::AUTO_LIDERANCA` contém `'notificacoes.criar'` | feature | `php artisan test --filter=Phase8FoundationTest::test_auto_lideranca_inclui_notificacoes_criar` | ❌ W0 | ⬜ pending |
| 8-04-01 | 04 | 4 | PERM-02 | V4 Access Control | Admin (`role=admin`) retorna `true` em `hasPermission('notificacoes.criar')` sem atribuição de setor nem permissão manual | feature | `php artisan test --filter=Phase8FoundationTest::test_admin_tem_permissao_via_short_circuit` | ❌ W0 | ⬜ pending |
| 8-04-02 | 04 | 4 | PERM-03 | V4 Access Control | User não-admin com `setoresLiderados()` ativo retorna `true` em `hasPermission('notificacoes.criar')` sem permissão manual no setor | feature | `php artisan test --filter=Phase8FoundationTest::test_lider_tem_permissao_via_auto_lideranca` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Task IDs acima são sugestões para o planner** — devem ser ajustados conforme `[plan-id]-[task-id]` real após o planner gerar os PLAN.md files. O mapeamento Test → Success Criterion / REQ está em `08-RESEARCH.md` §Phase Coverage Map e deve ser preservado.

**Nota sobre Waves (atualizada após revision):** Os Plans 02, 03 e 04 escrevem todos no mesmo arquivo `tests/Feature/Notifications/Phase8FoundationTest.php`. Para evitar clobber de arquivo em execução paralela, as waves foram serializadas:
- Plan 01 → wave 1
- Plan 02 → wave 2 (depends_on: 08-01)
- Plan 03 → wave 3 (depends_on: 08-02)
- Plan 04 → wave 4 (depends_on: 08-03)

---

## Wave 0 Requirements

- [ ] Criar diretório `app/Notifications/` (autoload PSR-4 já cobre `App\` → `app/` em `composer.json` linha 32)
- [ ] Criar diretório `tests/Feature/Notifications/` (autoload PSR-4 já cobre `Tests\` → `tests/`)
- [ ] Criar `tests/Feature/Notifications/Phase8FoundationTest.php` com classe `Phase8FoundationTest extends TestCase` e `use RefreshDatabase;`
- [ ] Sem necessidade de shared fixtures (`conftest.py` equivalente) — setup inline com `User::factory()` e `Setor::create()` é suficiente para os 7 testes
- [ ] PHPUnit 11 já instalado — sem `composer require` adicional

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Migration roda em ambiente local (XAMPP) sem erro além do SQLite in-memory dos testes | (infra) | Os testes usam SQLite in-memory; o smoke contra MySQL real (driver de produção) exige `php artisan migrate` no host | Após merge, rodar `php artisan migrate` em ambiente XAMPP do dev e confirmar `Schema::hasTable('notifications')` via Tinker |

*Todos os outros comportamentos têm verificação automatizada — só esta tem dependência de ambiente externo ao test runner.*

---

## Validation Sign-Off

- [ ] Todas as tarefas têm `<automated>` verify ou estão listadas em Wave 0
- [ ] Continuidade de sampling: nenhuma sequência de 3 tarefas consecutivas sem verificação automatizada
- [ ] Wave 0 cobre todas as referências MISSING (diretórios + arquivo de teste)
- [ ] Sem watch-mode flags (PHPUnit roda one-shot)
- [ ] Feedback latency < 5s para o filter da fase
- [ ] `nyquist_compliant: true` setado no frontmatter (após execute-phase concluir)

**Approval:** pending
