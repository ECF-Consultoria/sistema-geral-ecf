---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 01
subsystem: database
tags: [laravel, migration, mariadb-sqlite-parity, user_setores, inertia, react, validation]

# Dependency graph
requires: []
provides:
  - "Schema de user_setores aceita dois cargos no mesmo setor para a mesma pessoa (unique trocado de (user_id, setor_id) para (user_id, setor_id, cargo_id))"
  - "UserController::syncVinculos() sincroniza por par (setor_id, cargo_id), preservando assigned_at/created_at ao editar"
  - "UserController::validateUser() recusa par repetido e setor repetido com vínculo sem cargo"
  - "Tela /users grava, edita e remove dois cargos no mesmo setor sem sobrescrever um pelo outro"
  - "159-BASELINE-TESTES.md com os números reais de 18 grupos PHP + npm run test:js, antes de qualquer código desta fase"
affects: [159-02, 159-03, 159-04, 159-05, 159-06, 159-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Migration de swap de unique com hasIndex() cross-driver e ordem cria-novo-antes-de-dropar-antigo (molde 2026_07_14_000001), sem try/catch"
    - "Sincronização de pivot por PAR de colunas (não mais por user_id+setor_id sozinho), com chave null-safe 'setorId:cargoId'"

key-files:
  created:
    - .planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/159-BASELINE-TESTES.md
    - database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php
    - tests/Feature/Phase159/UserSetoresDoisCargosTest.php
  modified:
    - app/Http/Controllers/UserController.php
    - resources/js/Pages/Users/Index.jsx

key-decisions:
  - "D-01: unique de user_setores trocado para (user_id, setor_id, cargo_id); NULL em cargo_id continua distinto no banco, então a defesa contra 'setor repetido sem cargo' é de aplicação, não de schema"
  - "D-02: syncVinculos() passou a comparar por par (setor_id, cargo_id) em vez de (user_id, setor_id); is_principal continua no máximo 1 por usuário, e agora também indica o cargo principal quando há dois no mesmo setor"
  - "D-12: baseline de testes registrada antes de qualquer código; as 17 falhas de tests/Feature/Phase119 (gate de hash de DesempenhoScoreService.php) e as 2 falhas de npm run test:js (estrutura-grade-glide, polosEntrantes) são herdadas e permanecem idênticas ao fim do plano"

patterns-established:
  - "TDD plan-level: cada task com tdd=\"true\" gerou commit test(...) RED seguido de commit feat(...) GREEN, confirmado por execução real do phpunit entre os dois"

requirements-completed: [SC1, D-01, D-02, D-12]

# Metrics
duration: 25min
completed: 2026-09-30
---

# Fase 159 Plano 01: Fundação (baseline, schema e tela /users) Summary

**Migration troca o unique de `user_setores` para (user_id, setor_id, cargo_id) e `UserController`/`Users/Index.jsx` passam a gravar, editar e remover dois cargos no mesmo setor por PAR, preservando `assigned_at` na edição.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-09-30T19:40:00Z (aprox.)
- **Completed:** 2026-09-30T20:05:00Z
- **Tasks:** 3 (Task 2 e Task 3 em ciclo TDD RED→GREEN)
- **Files modified:** 5 (3 criados, 2 modificados)

## Accomplishments
- `159-BASELINE-TESTES.md` registrado com os números reais de 18 grupos PHP (17 verdes + `tests/Feature/Phase119` com as 17/29 falhas herdadas do gate de hash) e `npm run test:js` (453/455, 2 falhas herdadas fora do escopo), tudo ANTES de qualquer código desta fase (D-12)
- Migration `2026_09_30_100000_amplia_unique_user_setores_por_cargo` troca o unique de `user_setores` de 2 para 3 colunas com ordem correta (cria o novo antes de dropar o antigo — evita o 1553 do MariaDB), idempotente, sem try/catch, `down()` recusa com `RuntimeException` quando há duplicidade (D-01)
- `UserController::validateUser()` recusa par (setor, cargo) repetido e setor repetido com vínculo sem cargo; `syncVinculos()` sincroniza por par (setor_id, cargo_id), preservando `assigned_at`/`created_at` de vínculos que sobrevivem à edição (D-02)
- `Users/Index.jsx` deixa de bloquear a repetição de setor no select, desabilita só o cargo já usado por outro vínculo do mesmo setor, e "Adicionar vínculo" busca primeiro setor livre e depois cargo livre em setor já usado
- 12 testes novos em `tests/Feature/Phase159/UserSetoresDoisCargosTest.php`, todos verdes; `CargoDevNoUsuarioTest` e `PerformanceCargoFilterTest` batem exatamente com a baseline; `DesempenhoScoreService.php` sem diff contra `origin/main`

## Task Commits

Cada task foi commitada atomicamente (Tasks 2 e 3 seguiram o ciclo TDD RED→GREEN):

1. **Task 1: Baseline de testes antes de qualquer código (D-12)** - `386c3ca0` (docs)
2. **Task 2: Migration do unique de user_setores (D-01)**
   - RED: `f812be40` (test) — 6 testes do bloco `<behavior>`, 5 falham por falta da migration
   - GREEN: `d7ef6bfa` (feat) — migration criada, os 6 testes passam
3. **Task 3: /users grava e edita dois cargos no mesmo setor (D-02)**
   - RED: `a894424c` (test) — testes 7-12 acrescentados, 5 falham (teste 12 já passava, `index()` não precisava mudar)
   - GREEN: `2b4fc375` (feat) — `UserController` e `Users/Index.jsx` ajustados, os 12 testes passam

**Plan metadata:** commit separado a seguir (docs: complete plan)

## Files Created/Modified
- `.planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/159-BASELINE-TESTES.md` - baseline real de 18 grupos PHP + npm run test:js, capturada antes de qualquer código
- `database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php` - troca do unique de `user_setores`, com decisão de schema completa no docblock
- `tests/Feature/Phase159/UserSetoresDoisCargosTest.php` - 12 testes: schema (1-6) + `/users` PUT/GET (7-12)
- `app/Http/Controllers/UserController.php` - `validateUser()` com as duas regras novas; `syncVinculos()` reescrito por par (setor_id, cargo_id)
- `resources/js/Pages/Users/Index.jsx` - form de vínculos aceita dois cargos no mesmo setor; chips da tabela com key por setor+cargo

## Decisions Made
- **D-01 (schema):** unique novo `user_setores_user_id_setor_id_cargo_id_unique` criado ANTES de dropar o antigo `user_setores_user_id_setor_id_unique` — evita o erro 1553 do MariaDB documentado em `.planning/learnings/desempenho-bonificacao.md` §10.1. `cargo_id` NULL continua distinto dentro do unique (MariaDB/SQLite); a defesa contra duas linhas "sem cargo" no mesmo setor é de aplicação (`UserController`), não de banco — registrado como limitação conhecida no docblock da migration.
- **D-01 (rollback):** `down()` conta grupos `(user_id, setor_id)` com mais de uma linha e recusa com `RuntimeException` explícita em vez de apagar vínculo — nunca houve try/catch envolvendo `Schema::table`.
- **D-02 (chave de sincronização):** `syncVinculos()` passou a comparar pelo PAR `(setor_id, cargo_id)` com chave null-safe (`"setorId:cargoId"` / `"setorId:null"`). Ao encontrar o mesmo par, faz UPDATE só de `is_principal`/`updated_at` (preserva `assigned_at`/`created_at` — é a data do vínculo). Se existir duplicata antiga do mesmo par (só possível por inconsistência anterior a esta fase), mantém a linha de menor id e apaga as demais.
- **D-12 (baseline):** as 17 falhas de `tests/Feature/Phase119` (hash de `DesempenhoScoreService.php` não rotacionado) e as 2 falhas de `npm run test:js` (`estrutura-grade-glide.test.js`, `polosEntrantes.test.js`) são herdadas, não tocadas por este plano, e continuam idênticas ao fim da Task 3 — confirmado por reexecução.

## Deviations from Plan

None - plan executado exatamente como escrito.

Uma armadilha de ambiente foi encontrada e corrigida DURANTE a coleta da baseline (Task 1, antes de qualquer código): o primeiro script de loop usou um array bash chamado `GROUPS`, que é uma variável ESPECIAL somente-leitura do bash — a atribuição falhou silenciosamente e o loop rodou 1x com o gid numérico do usuário (`197121`) em vez do path do teste. Renomear para `TEST_GROUPS` resolveu. Não é um deviation de código de produção (nenhum arquivo de `app/`, `database/`, `resources/`, `routes/` ou `tests/` foi tocado até a correção), mas foi registrado em `159-BASELINE-TESTES.md` porque não é dedutível do código e pode se repetir em qualquer script bash futuro deste projeto.

## Issues Encountered
None além da armadilha de ambiente documentada acima (corrigida antes de qualquer commit).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## TDD Gate Compliance

Tasks 2 e 3 têm `tdd="true"` no frontmatro do plano e seguiram o ciclo completo:

- Task 2: `test(159-01)` RED (`f812be40`, 5/6 falham) → `feat(159-01)` GREEN (`d7ef6bfa`, 6/6 passam). Sem REFACTOR (não foi necessário).
- Task 3: `test(159-01)` RED (`a894424c`, 5/6 novos falham) → `feat(159-01)` GREEN (`2b4fc375`, 12/12 passam). Sem REFACTOR (não foi necessário).

Ambos os gates RED e GREEN confirmados por execução real do PHPUnit entre os commits (não apenas por inspeção de código).

## Next Phase Readiness
- Schema e tela principal de cadastro (`/users`) já aceitam dois cargos no mesmo setor — fundação pronta para os planos seguintes da fase (159-02 em diante: `Admin/SetorMembroController` conforme D-10, `CompanyController` conforme D-03/D-04, ranking/relatório conforme D-05, junção das contas do Danilo conforme D-06, e demais decisões travadas em `159-CONTEXT.md`).
- Nenhum bloqueio identificado. A verificação real do índice no MariaDB de produção (`SHOW INDEX FROM user_setores`) fica para o plano 159-07, conforme já previsto no plano.

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-09-30*

## Self-Check: PASSED

- `159-01-SUMMARY.md` presente em `.planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/`
- Todos os 5 commits citados confirmados em `git log --oneline`: `386c3ca0`, `f812be40`, `d7ef6bfa`, `a894424c`, `2b4fc375`
- Todos os arquivos citados (criados/modificados) confirmados presentes no disco
