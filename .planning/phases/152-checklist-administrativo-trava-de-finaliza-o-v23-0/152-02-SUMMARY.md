---
phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0
plan: 02
subsystem: backend
tags: [migration, eloquent-model, mariadb, checklist-administrativo]

# Dependency graph
requires:
  - phase: 152-01
    provides: "Baseline verde 137/138 + config('services.adman.register_url') + REQUIREMENTS-v23.md corrigido"
provides:
  - "Tabela checklist_administrativo_itens no MariaDB local, ancorada em company_id (D-10)"
  - "App\\Models\\ChecklistAdministrativoItem — model com \$fillable explícito, catálogo fechado de 2 status e feitoPor()->withTrashed() (D-11)"
affects: [152-03, 152-04, 152-05, 152-06, 152-07, 152-08, 152-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Índice unique nomeado à mão em migration nova (cai_company_chave_unique, 24 chars) — evita erro 1059 no MariaDB por nome autogerado > 64 chars"
    - "FK nullable() declarada ANTES de constrained()->nullOnDelete() — evita erro 1830 no MariaDB"
    - "belongsTo(User::class, '<coluna>')->withTrashed() para autoria que sobrevive a soft delete — mesmo padrão de Pendencia::abertaPor()/corrigidaPor()"

key-files:
  created:
    - database/migrations/2026_09_09_170000_create_checklist_administrativo_itens_table.php
    - app/Models/ChecklistAdministrativoItem.php
    - tests/Unit/Phase152/ChecklistItemModelTest.php
  modified: []

key-decisions:
  - "D-10 implementado: a tabela é ancorada em company_id direto, nunca em onboarding_id ou contrato_servico_id — nenhuma FK, coluna ou dependência do motor de Onboarding foi copiada além do shape (autoria + valor json + timestamps de auto-conclusão)"
  - "D-02 implementado: catálogo fechado de status com só 2 valores (STATUS_ABERTO/STATUS_CONCLUIDO) — o estado 'não aplicável' do motor de Onboarding foi deliberadamente excluído da tabela, do model e de todo comentário/docblock, para que os grep de auditoria do próprio plano (verificação item 3) retornem zero em vez de falsos positivos de documentação"
  - "D-11 implementado: feitoPor()->withTrashed() copiado do análogo correto (Pendencia::abertaPor()), não de OnboardingPasso::feitoPor() (que não usa withTrashed()) — provado por teste que faz soft delete do usuário e afirma que a relação sobrevive"

patterns-established:
  - "Migration nova validada em dois passos: SQLite via phpunit + MariaDB local via 'artisan migrate --force', com SHOW INDEX/SHOW COLUMNS conferidos manualmente — nenhuma das duas armadilhas de erro 1059/1830 é pega pela suíte SQLite sozinha"

requirements-completed: [ADMIN-01, ADMIN-04]

# Metrics
duration: 14min
completed: 2026-09-09
---

# Phase 152 Plan 02: Persistência do checklist administrativo (migration + model) Summary

**Tabela `checklist_administrativo_itens` ancorada em `company_id` (D-10), com índice unique nomeado à mão para evitar o erro 1059 do MariaDB, e o model `ChecklistAdministrativoItem` com catálogo fechado de 2 status (D-02) e autoria que sobrevive a soft delete de usuário (D-11) — fundação sem nenhum comportamento observável na tela.**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-09-09T18:38:23Z (logo após o fechamento do 152-01)
- **Completed:** 2026-09-09T18:52:01Z
- **Tasks:** 2/2
- **Files modified:** 3 (todos criados novos)

## Accomplishments

- `database/migrations/2026_09_09_170000_create_checklist_administrativo_itens_table.php` cria a tabela com FK `company_id` (`cascadeOnDelete`), `chave` (60), `status` (24, default `aberto`), `valor` json nullable, `feito_por` FK nullable (`nullOnDelete`), `feito_em`/`auto_em` timestamps nullable, e o índice `unique(['company_id','chave'], 'cai_company_chave_unique')` — nome explícito de 24 caracteres, bem abaixo do limite de 64 do MariaDB.
- Migration rodada e confirmada contra o **MariaDB local** (`ecf_admin`, driver `mysql`): `artisan migrate --force` concluiu em 301ms sem erro; `SHOW INDEX` confirmou `cai_company_chave_unique` como unique composto sobre `(company_id, chave)`; `SHOW COLUMNS` confirmou `feito_por` nullable e as demais colunas na forma exata do plano.
- `app/Models/ChecklistAdministrativoItem.php` — `$table` declarada explicitamente (a pluralização automática do Eloquent não produz `checklist_administrativo_itens`), `$fillable` com as 7 colunas (nunca `$guarded = []`), `$casts` para `valor`/`feito_em`/`auto_em`, catálogo `STATUS_ABERTO`/`STATUS_CONCLUIDO`/`STATUS_TODOS`, relação `company()` e `feitoPor()` com `->withTrashed()`.
- `tests/Unit/Phase152/ChecklistItemModelTest.php` — 3 testes/9 assertions provando D-02 (2 estados, sem "não aplicável"), D-11 (autoria sobrevive a soft delete de usuário) e a trava de mass assignment (id forçado no `create()` não é gravado).
- Suíte de regressão `tests/Unit/Phase150 + tests/Feature/Phase150 + tests/Feature/Phase151 + tests/Unit/Phase152` rodou **128 testes / 457 assertions, 100% verde** — sem nenhuma regressão contra a baseline de 123/444 do 152-01 (125 depois do 152-01 com o `AdmanRegisterUrlConfigTest`, +3 deste plano = 128).

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration da tabela checklist_administrativo_itens** - `3b90803d` (feat) — commit original criado, depois emendado (`--amend`) duas vezes na própria task para remover as strings literais `nao_aplicavel`/`onboarding_id`/`template_passo_id` dos comentários explicativos (ver Deviations)
2. **Task 2: Model ChecklistAdministrativoItem com autoria withTrashed** - `33e7c9b7` (feat)

## Files Created/Modified

- `database/migrations/2026_09_09_170000_create_checklist_administrativo_itens_table.php` - tabela nova, ancorada em `company_id`, índice unique nomeado à mão
- `app/Models/ChecklistAdministrativoItem.php` - model com fillable explícito, catálogo de 2 status, autoria `withTrashed()`
- `tests/Unit/Phase152/ChecklistItemModelTest.php` - prova de D-02, D-11 e mass assignment

## Decisions Made

- **D-10 (ancoragem):** `company_id` direto, sem nenhuma FK do motor de Onboarding — só o *shape* (autoria + valor json + timestamps de auto-conclusão) foi copiado, nunca a hospedagem.
- **D-02 (catálogo fechado):** só `aberto`/`concluido` — nenhuma menção ao estado "não aplicável" sobrevive nem em código nem em comentário/docblock de nenhum dos dois arquivos (migration e model), satisfazendo literalmente a verificação por grep do próprio plano.
- **D-11 (autoria):** `feitoPor()->withTrashed()` copiado de `Pendencia::abertaPor()/corrigidaPor()` — não de `OnboardingPasso::feitoPor()`, que é o análogo errado para este detalhe específico (não usa `withTrashed()`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Bloqueio] Comentários explicativos continham as strings proibidas pela própria verificação do plano**
- **Found during:** Task 1 e Task 2, ao rodar a verificação `grep -c 'nao_aplicavel' ...` e a checagem adicional de `disponivel_em|template_passo_id|onboarding_id` (acceptance criteria da Task 1)
- **Issue:** Os docblocks explicando *por que* o estado "não aplicável" e as FKs `onboarding_id`/`template_passo_id` do motor de Onboarding NÃO existem nesta fase citavam essas strings literalmente para explicar a ausência — o que fazia o grep de auditoria (que busca 0 ocorrências) encontrar falsos positivos vindos de documentação, não de código funcional.
- **Fix:** Reescritas as passagens para descrever os conceitos sem repetir os identificadores `snake_case` proibidos (ex.: "o estado não aplicável" em vez de `` `nao_aplicavel` ``; "a FK do onboarding" em vez de `` `onboarding_id` ``), preservando o sentido explicativo integralmente.
- **Files modified:** `database/migrations/2026_09_09_170000_create_checklist_administrativo_itens_table.php`, `app/Models/ChecklistAdministrativoItem.php`
- **Commit:** emendado dentro do próprio `3b90803d` (Task 1, antes de a Task 2 começar) — nenhum commit adicional gerado; `33e7c9b7` (Task 2) já nasceu com o texto corrigido.

Nenhum outro desvio. As duas tasks seguiram a `<action>` do plano na letra; nenhuma decisão arquitetural nova foi necessária (Rule 4 não se aplicou).

## Issues Encountered

Nenhum. A migration subiu limpa no MariaDB local na primeira tentativa, com o índice unique e a ordem `nullable()`→`constrained()`→`nullOnDelete()` corretas desde o primeiro rascunho — as duas armadilhas descritas no plano (erro 1059/1830) foram evitadas por desenho, não corrigidas depois de falhar.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. A tabela já existe no MariaDB local do worktree; nenhuma ação manual pendente.

## Next Phase Readiness

- `App\Models\ChecklistAdministrativoItem` está pronto para os planos 152-03 em diante consumirem: catálogo de status, relações `company()`/`feitoPor()`, e o helper `estaConcluido()`.
- A tabela existe no MariaDB local e passou pela validação de schema (SHOW INDEX/SHOW COLUMNS) — os planos seguintes podem gravar/ler linhas sem repetir essa verificação.
- Nenhum bloqueio identificado para o plano 152-03 (o catálogo fechado `ChecklistAdministrativoDefinicao` e os 4 resolvers síncronos, conforme `152-PATTERNS.md`).

---
*Phase: 152-checklist-administrativo-trava-de-finaliza-o-v23-0*
*Completed: 2026-09-09*

## Self-Check: PASSED

- FOUND: `database/migrations/2026_09_09_170000_create_checklist_administrativo_itens_table.php`
- FOUND: `app/Models/ChecklistAdministrativoItem.php`
- FOUND: `tests/Unit/Phase152/ChecklistItemModelTest.php`
- FOUND commit: `3b90803d`
- FOUND commit: `33e7c9b7`
