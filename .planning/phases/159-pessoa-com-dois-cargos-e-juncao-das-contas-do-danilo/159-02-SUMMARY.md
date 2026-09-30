---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 02
subsystem: setores
tags: [laravel, inertia, react, pivot, notifications, mariadb-sqlite-parity]

# Dependency graph
requires:
  - phase: 159-01
    provides: "Schema de user_setores com unique (user_id, setor_id, cargo_id); tela /users já grava/edita dois cargos no mesmo setor"
provides:
  - "Admin/SetorMembroController (segunda tela que grava user_setores) convive com D-01: adiciona cargo a quem já é membro, remove por cargo (vinculo_id), sem apagar o outro cargo da pessoa"
  - "SetorController::show() expõe vinculo_id por linha; index() conta pessoas distintas em membros_count"
  - "SetorGoal::booted() e NotificacaoController::criar() deduplicam por unique('id') antes de notificar — pessoa com dois cargos no setor não recebe em dobro"
  - "LiderancaController::show() colapsa a pessoa com dois cargos numa entrada só (cargo_nome = 'Analista · Estrategista')"
  - "Tela /administrativo/setores/{setor} lista uma linha por cargo, conta pessoas distintas e remove cargo por cargo"
affects: [159-03, 159-04, 159-05, 159-06, 159-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Segunda tela que grava a mesma pivot (user_setores) segue a MESMA regra de par (cargo_id, setor_id) da tela primária — D-10 generaliza o padrão do plano 159-01 para qualquer novo consumidor futuro"
    - "Remoção por vínculo: query string com o id da linha da pivot (não do par user+setor), sempre revalidada contra setor_id E user_id da rota antes de apagar (defesa IDOR)"
    - "Consumidores de relação belongsToMany que serve de lista de notificação/contagem aplicam ->unique('id') no ponto de leitura, não na relação em si (Setor::membros() não mudou)"

key-files:
  created:
    - tests/Feature/Phase159/SetorMembroDoisCargosTest.php
  modified:
    - app/Http/Controllers/Admin/SetorMembroController.php
    - app/Http/Controllers/Admin/SetorController.php
    - app/Models/SetorGoal.php
    - app/Http/Controllers/NotificacaoController.php
    - app/Http/Controllers/LiderancaController.php
    - resources/js/Pages/Admin/Setores/Show.jsx

key-decisions:
  - "D-10: storeMembro() distingue 3 casos pela combinação (cargo informado?, já existe linha com esse cargo?, existe linha SEM cargo?) — cargo repetido recusa, cargo novo em quem só tinha linha sem cargo CONVERTE a linha existente (não duplica), cargo novo em quem já tem outro cargo INSERE segunda linha"
  - "destroyMembro() aceita vinculo (id da linha) por query string; sem vinculo, preserva o comportamento antigo só quando o par tem exatamente 1 linha — com 2+ recusa explicitamente, para uma tela não apagar os dois cargos de quem tem dois sem o admin escolher qual"
  - "Linha principal removida promove a de MENOR id entre as restantes — regra simples e determinística, sem pedir ao admin pra escolher a nova principal"
  - "CalculateSetorGoalResults.php (pluck('id') num whereIn) e AudienciaRedeSeguranca.php (setor comercial) foram conferidos e DEIXADOS DE PROPÓSITO — duplicata em whereIn é inofensiva e o setor comercial está fora do escopo do cargo duplo desta fase"

patterns-established:
  - "TDD plan-level: Task 1 (testes 1-10) e Task 2 (testes 11-13) cada uma com commit test(...) RED seguido de feat(...) GREEN, confirmado por execução real do phpunit entre os dois"

requirements-completed: [SC1, D-10]

# Metrics
duration: ~30min
completed: 2026-09-30
---

# Fase 159 Plano 02: Setores conviven com cargo duplo Summary

**`Admin/SetorMembroController` (segunda tela que grava `user_setores`) passa a adicionar cargo a quem já é membro e a remover por cargo via `vinculo_id`, e os três consumidores de `Setor::membros()` que contavam/notificavam em dobro (`SetorGoal`, `NotificacaoController`, `LiderancaController`) passam a deduplicar por `unique('id')` ou colapso por pessoa.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-09-30T19:55:00Z (aprox.)
- **Completed:** 2026-09-30T20:17:00Z
- **Tasks:** 3 (Task 1 e Task 2 em ciclo TDD RED→GREEN; Task 3 sem TDD, front-end)
- **Files modified:** 6 (1 criado, 5 modificados)

## Accomplishments
- `SetorMembroController::storeMembro()` deixa de recusar qualquer segunda linha genericamente — agora recusa só cargo repetido ("Usuário já tem este cargo neste setor."), recusa cargo ausente quando já há alguma linha ("...Para acrescentar outro cargo, escolha qual."), converte a linha sem cargo em vez de duplicar, e insere normalmente no caso de cargo novo e diferente (D-10)
- `destroyMembro()` passa a apagar por `vinculo` (id da linha, restrito a `setor_id` E `user_id` da rota — 404 se não bater, T-159-05/IDOR); sem `vinculo`, preserva o comportamento antigo só para quem tem exatamente 1 linha, recusa explicitamente para quem tem 2+; linha principal removida promove a de menor id restante
- `SetorController::show()` expõe `vinculo_id` por linha (`withPivot('id')` só nesta query, `Setor::membros()` não mudou) e ordena por nome+cargo; `index()` troca `membros_count` para `count(distinct user_setores.user_id)` — pessoa com dois cargos conta 1x
- `SetorGoal::booted()` e `NotificacaoController::criar()` aplicam `->unique('id')->values()` antes de `Notification::send` — pessoa com dois cargos no setor recebe `MetaAtribuidaNotification`/`ManualNotification` exatamente 1x (T-159-07)
- `LiderancaController::show()` agrupa as linhas por pessoa e junta os nomes dos cargos com " · " (ordem de `Setor::cargos()`); `Lideranca/Setor.jsx` continua com `key={m.id}`, agora sempre único, e `kpis.total_membros`/`membros_ativos` contam sobre a coleção já colapsada
- `Admin/Setores/Show.jsx`: cabeçalho conta pessoas distintas (`new Set(membros.map(m => m.id)).size`), cada `<tr>` usa `key={m.vinculo_id}`, a lixeira manda `vinculo: m.vinculo_id` e confirma mencionando o cargo específico, e o modal de adicionar membro ganha texto explicando como dar um segundo cargo
- 13 testes novos em `tests/Feature/Phase159/SetorMembroDoisCargosTest.php` (10 da Task 1 + 3 da Task 2), todos verdes junto com os 12 de `UserSetoresDoisCargosTest.php` do plano 01 (25/25 em `tests/Feature/Phase159/`)
- `Phase11AutoTest` (6/6) e `Phase12ManualTest` (11/11) idênticos à baseline; `PerformanceCargoFilterTest` (6/6) e `CargoDevNoUsuarioTest` (6/6) idênticos; `npm run test:js` com o mesmo resultado (453/455, as 2 falhas herdadas de `estrutura-grade-glide` e `polosEntrantes`)
- `DesempenhoScoreService.php` sem diff contra `origin/main`

## Task Commits

Cada task foi commitada atomicamente (Tasks 1 e 2 seguiram o ciclo TDD RED→GREEN):

1. **Task 1: SetorMembroController por cargo e SetorController com vínculo por linha (D-10)**
   - RED: `e00e521d` (test) — 10 testes do bloco `<behavior>`, 8 falham (2 já passavam por comportamento antigo compatível, sem gatilho de fail-fast — mesmo padrão do 159-01)
   - GREEN: `6d68db6e` (feat) — `SetorMembroController` e `SetorController` ajustados, os 10 testes passam
2. **Task 2: Consumidores de Setor::membros contam e notificam a pessoa uma vez só**
   - RED: `318ba79e` (test) — testes 11-13 acrescentados, os 3 falham
   - GREEN: `1fc647bd` (feat) — `SetorGoal`, `NotificacaoController` e `LiderancaController` ajustados, os 13 testes passam
3. **Task 3: Tela /administrativo/setores/{setor} com uma linha por cargo** - `95d409cd` (feat)

**Plan metadata:** commit separado a seguir (docs: complete plan)

## Files Created/Modified
- `tests/Feature/Phase159/SetorMembroDoisCargosTest.php` - 13 testes: Admin/SetorMembroController por cargo (1-10) + consumidores deduplicados (11-13)
- `app/Http/Controllers/Admin/SetorMembroController.php` - `storeMembro()` por cargo (3 casos) e `destroyMembro()` por vínculo com promoção de principal
- `app/Http/Controllers/Admin/SetorController.php` - `show()` com `vinculo_id`; `index()` com contagem de pessoas distintas
- `app/Models/SetorGoal.php` - `booted()` deduplica membros por `unique('id')` antes de notificar
- `app/Http/Controllers/NotificacaoController.php` - `criar()` deduplica membros do setor por `unique('id')` antes de notificar
- `app/Http/Controllers/LiderancaController.php` - `show()` colapsa membros por pessoa, cargo_nome unido por " · "
- `resources/js/Pages/Admin/Setores/Show.jsx` - `TabMembros` com contagem de pessoas distintas, `key={m.vinculo_id}`, remoção por vínculo; `AddMembroModal` com texto de ajuda

## Decisions Made
- **D-10 (storeMembro):** a distinção dos 3 casos (cargo repetido / sem cargo com linha existente / cargo novo em linha sem cargo / cargo novo em linha com outro cargo) é feita ANTES da transação, olhando as linhas já existentes do par — evita fazer o insert/update condicional dentro da transação sem saber qual caminho seguir.
- **D-10 (destroyMembro, IDOR):** a linha por `vinculo` é SEMPRE buscada restrita a `setor_id` E `user_id` da própria rota — nunca por `id` isolado. Vínculo de outro setor ou de outro usuário dá 404 e nada é apagado (T-159-05, testado).
- **D-10 (promoção de principal):** ao apagar a linha principal, a de MENOR id entre as restantes vira principal automaticamente — sem interação extra do admin. Simples e determinístico; se o admin quiser outra principal, marca manualmente depois.
- **T-159-07 (deduplicação):** aplicada no PONTO DE LEITURA (`->unique('id')` depois de `$setor->membros`), não na relação `Setor::membros()` em si — `app/Models/Setor.php` não foi tocado, como exigido pelo acceptance criteria da Task 1.
- **Consumidores fora do escopo, conferidos e deixados de propósito:** `CalculateSetorGoalResults.php` usa `pluck('id')` dentro de um `whereIn` — duplicata é inofensiva ali (SQL `IN` já deduplica efetivamente); `AudienciaRedeSeguranca.php` opera sobre o setor `comercial`, fora do escopo do cargo duplo desta fase. Nenhum dos dois foi alterado.

## Deviations from Plan

None - plan executado exatamente como escrito.

## Issues Encountered

Nenhum. O padrão de "teste passa antes do GREEN porque o comportamento antigo já é um subconjunto compatível" se repetiu (testes 4 e 7 da Task 1, análogo ao teste 12 do plano 159-01) — não é gatilho de fail-fast porque o comportamento observado (recusa ao adicionar sem cargo quando já é membro; apagar a única linha existente) é exatamente o que o novo código também faz nesses casos específicos, só muda quando há 2+ linhas.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## TDD Gate Compliance

Tasks 1 e 2 têm `tdd="true"` no frontmatter do plano e seguiram o ciclo completo:

- Task 1: `test(159-02)` RED (`e00e521d`, 8/10 falham) → `feat(159-02)` GREEN (`6d68db6e`, 10/10 passam). Sem REFACTOR (não foi necessário).
- Task 2: `test(159-02)` RED (`318ba79e`, 3/3 novos falham) → `feat(159-02)` GREEN (`1fc647bd`, 13/13 passam). Sem REFACTOR (não foi necessário).

Ambos os gates RED e GREEN confirmados por execução real do PHPUnit entre os commits (não apenas por inspeção de código).

## Next Phase Readiness
- D-10 entregue: as duas telas que gravam `user_setores` (`/users` do plano 159-01 e `/administrativo/setores/{setor}` deste plano) convivem com o cargo duplo sem uma desfazer o que a outra grava, e os três consumidores de `Setor::membros()` identificados na pesquisa não vazam a duplicidade da pivot para contagem nem notificação.
- Nenhum bloqueio identificado. Os demais planos da fase (D-03 a D-09, D-11, D-12) seguem conforme `159-CONTEXT.md` — nenhum deles depende de código deste plano além do schema já entregue em 159-01.

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-09-30*

## Self-Check: PASSED

- `159-02-SUMMARY.md` presente em `.planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/`
- Todos os 7 arquivos citados (criado/modificados) confirmados presentes no disco
- Todos os 6 commits citados confirmados em `git log --oneline`: `e00e521d`, `6d68db6e`, `318ba79e`, `1fc647bd`, `95d409cd`, `df6b175a`
