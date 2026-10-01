---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 03
subsystem: companies
tags: [laravel, inertia, react, pivot, company_users, menu, mariadb-sqlite-parity]

# Dependency graph
requires:
  - phase: 159-01
    provides: "Schema de user_setores com unique (user_id, setor_id, cargo_id); tela /users já grava/edita dois cargos no mesmo setor"
  - phase: 159-02
    provides: "Admin/SetorMembroController e consumidores de Setor::membros convivem com cargo duplo"
provides:
  - "CompanyController::update grava um attach() por papel — a mesma pessoa cabe em consultor_id E estrategista_id de uma vez (D-03), e o histórico registra os dois papéis"
  - "Prova de que bulkAssign, ShopeeEmpresasController::resolver() e o formulário de NPS (D-07) já suportam a mesma pessoa nos dois papéis sem mudança de código"
  - "Prova de SC3/D-04: os quatro selects de responsável (CompanyController, ShopeeEmpresasController, DistribuicaoService::elegiveis/distribuir, DashboardController) já listam a pessoa em cada cargo que ela tem"
  - "resources/js/lib/visibilidadeMenu.js (itemOcultoPorPapel) — item de menu com excludeRoles aparece se pelo menos um cargo da pessoa tem acesso (D-08), usado por AppLayout::itemVisivel()"
affects: [159-04, 159-05, 159-06, 159-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Escrita de pivot com o mesmo user_id em dois papéis: NUNCA um array PHP indexado por user_id (as duas chaves colidem e a segunda sobrescreve a primeira) — sempre um attach()/insert por papel, diferenciado por uma coluna extra do unique (aqui, role)"
    - "Regra pura extraída para resources/js/lib/*.js quando a lógica de UI precisa ser testável por node:test sem montar o componente React inteiro (mesmo padrão de permissions.js/demandasDev.js)"

key-files:
  created:
    - tests/Feature/Phase159/EmpresaMesmaPessoaDoisPapeisTest.php
    - tests/Feature/Phase159/SelectsResponsavelDoisCargosTest.php
    - resources/js/lib/visibilidadeMenu.js
    - tests/js/visibilidadeMenu.test.js
  modified:
    - app/Http/Controllers/CompanyController.php
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "D-03: CompanyController::update() troca o array $sync indexado por user_id (colidiria com a mesma chave para os dois papéis) por dois attach() separados — um attach(analista) e um attach(estrategista) — diferenciados pelo role no unique (company_id, user_id, role, servico_id) de company_users. A regra herdada que zerava o estrategista quando era a mesma pessoa foi removida por inteiro, não só contornada."
  - "D-07: nenhuma mudança de código — o Teste 8 trava o comportamento atual de NpsController::responsaveisDoSurvey() (resolve estrategista e analista por wherePivot('role', $role)->first(), independentes um do outro), confirmando que o formulário de NPS já mostra as duas perguntas com o nome certo quando é a mesma pessoa."
  - "D-04/SC3: nenhuma mudança de código nos quatro backends de selects — a pesquisa (§5) já havia confirmado que todos resolvem 'quem tem o cargo X' por query independente por slug (whereIn/whereExists), não por keyBy()/value(). Os 5 testes de SelectsResponsavelDoisCargosTest.php passaram na primeira execução, sem precisar de ciclo RED→GREEN de produção — só documentam a regressão."
  - "D-08: itemOcultoPorPapel() troca 'esconde se QUALQUER papel efetivo está em excludeRoles' por 'esconde se o papel do sistema OU TODOS os cargos de publicação estão em excludeRoles'. Para quem tem um cargo só, as duas regras são idênticas — não foi preciso nenhum caso especial de compatibilidade. O cargo 'dev' é descartado da lista antes de aplicar a regra (ortogonal: governa módulos ocultos, não perfil de acesso)."

patterns-established:
  - "TDD plan-level: Task 1 e Task 3 com commit test(...) RED seguido de feat(...) GREEN, confirmado por execução real (phpunit e node --test) entre os dois. Task 2 é teste-only (5/5 passam já no RED) porque a pesquisa já havia provado que os quatro backends não precisavam de mudança — documentado como achado, não como desvio do ciclo TDD."

requirements-completed: [SC2, SC3, D-03, D-04, D-07, D-08]

# Metrics
duration: ~20min
completed: 2026-09-30
---

# Fase 159 Plano 03: Mesma pessoa nos dois papéis (empresa, selects, NPS, menu) Summary

**`CompanyController::update()` passa a gravar um `attach()` por papel em vez de um array `$sync` indexado por `user_id` que colidiria com a mesma pessoa nos dois papéis; os quatro selects de responsável (Companies, Shopee, Distribuição, Dashboard) e o formulário de NPS já suportavam o cargo duplo sem mudança de código — confirmado por 13 testes novos; e o menu (`AppLayout`) ganha `itemOcultoPorPapel()`, que só esconde um item quando TODOS os cargos da pessoa estão excluídos.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-30T17:20:00Z (aprox.)
- **Completed:** 2026-09-30T17:30:00Z
- **Tasks:** 3 (Task 1 e Task 3 em ciclo TDD RED→GREEN; Task 2 teste-only)
- **Files modified:** 6 (4 criados, 2 modificados)

## Accomplishments
- `CompanyController::update()` (D-03/SC2): removida a regra herdada "mesma pessoa nos dois papéis continua valendo só como analista" e o array `$sync` indexado por `user_id` que colidiria mesmo sem essa regra (a segunda chave igual sobrescreveria a primeira). No lugar, dois `attach()` separados — a mesma pessoa agora grava 2 linhas em `company_users` (consultor + estrategista), `company_manager_history` registra os dois papéis, e resalvar o mesmo payload não apaga nenhum dos dois. `registrarHistoricoGestao()` e `limparSlotPerformance()` não precisaram mudar.
- 8 testes em `EmpresaMesmaPessoaDoisPapeisTest.php`: os 5 primeiros provam D-03 (grava, resalva, troca parcial preservando o outro papel, slot consolidado `servico_id NULL`, e as relações `analistaPerformance()`/`estrategistaPerformance()`); os 3 últimos são regressão pura (sem mudança de código) de `bulkAssign`, `ShopeeEmpresasController::resolver()` e D-07 (`GET /nps/{token}` mostra `estrategista_name`/`analista_name` com o mesmo nome e `tem_analista = true`).
- 5 testes em `SelectsResponsavelDoisCargosTest.php` (D-04/SC3), todos verdes na primeira execução, sem tocar código de produção: `/companies` (`analistas`/`estrategistas`), `/shopee/empresas` (cargos escopados ao setor Shopee), `DistribuicaoService::elegiveis()` e `::distribuir()` (grava os dois papéis quando `analistaId === estrategistaId`), e `GET /dashboard?period=30` (filtros do admin).
- `resources/js/lib/visibilidadeMenu.js` exporta `itemOcultoPorPapel({ excludeRoles, mainRole, cargos })` (D-08): esconde se o papel do sistema está em `excludeRoles`, OU se sobrar pelo menos um cargo de publicação (descartado `'dev'`, ortogonal) e TODOS os que sobraram estiverem em `excludeRoles`. `AppLayout::itemVisivel()` passou a chamar essa função em vez do antigo `excludeRoles?.some(r => effectiveRoles.has(r))`; a constante `effectiveRoles` foi removida (ficou sem uso). Quem tem analista e estrategista agora vê "Alertas Estratégicos" (que só exclui `analista`); quem tem um cargo só vê exatamente o mesmo menu de antes.
- 7 testes em `visibilidadeMenu.test.js` cobrindo os 7 casos do plano (um cargo excluído, dois cargos com um deles liberando o item, papel do sistema excluído continuando a esconder, sem cargos de publicação, cargo Dev não contando, sem `excludeRoles`, `excludeRoles` que não bate com nada).
- `npm run test:js` sobe de 455 para 462 testes (460 passam — as mesmas 2 falhas herdadas de `estrutura-grade-glide`/`polosEntrantes`, sem nenhuma nova); `npm run build` sai 0 com o manifest atualizado.
- Sentinelas idênticos à baseline: `Quick260917Mfu/AtribuicaoResponsaveisTest` (8/8), `V16/AtribuicaoPorServicoIsolamentoTest` (5/5), `V16/CompanyControllerResponsavelPerformanceTest` (9/9), `Phase75/Phase75ShopeeEmpresasTest` (17/17), `Phase154` (26/26), `Phase157` (30/30), `Dashboard/DashboardWidgetsRecorteTest` (7/7). `tests/Feature/Phase159/` completo: 38/38. `DesempenhoScoreService.php` sem diff contra `origin/main`. `git diff --name-only origin/main` não lista `app/Http/Controllers/NpsController.php`.

## Task Commits

Cada task foi commitada atomicamente (Tasks 1 e 3 seguiram o ciclo TDD RED→GREEN):

1. **Task 1: CompanyController::update grava um papel por linha (D-03) e o NPS pergunta por função (D-07)**
   - RED: `ec65b9d6` (test) — 8 testes do bloco `<behavior>`, 5 falham (regra herdada + colisão do `$sync`)
   - GREEN: `84e26b8f` (feat) — `CompanyController` ajustado, os 8 testes passam
2. **Task 2: Teste de regressão dos selects de responsável com cargo duplo (D-04/SC3)** - `3daaa4bc` (test) — 5/5 passam, sem código de produção
3. **Task 3: Menu aparece se pelo menos um cargo tem acesso (D-08)**
   - RED: `a0232ab5` (test) — `visibilidadeMenu.js` ainda não existe, `node --test` falha com `ERR_MODULE_NOT_FOUND`
   - GREEN: `c24f7a8d` (feat) — `visibilidadeMenu.js` criado e `AppLayout.jsx` ajustado, os 7 testes passam

**Plan metadata:** commit separado a seguir (docs: complete plan)

## Files Created/Modified
- `tests/Feature/Phase159/EmpresaMesmaPessoaDoisPapeisTest.php` - 8 testes: D-03/SC2 (1-5) + regressão bulkAssign/Shopee/NPS (6-8)
- `tests/Feature/Phase159/SelectsResponsavelDoisCargosTest.php` - 5 testes de regressão dos 4 backends de selects (D-04/SC3)
- `app/Http/Controllers/CompanyController.php` - `update()` com dois `attach()` separados em vez do `$sync` indexado por `user_id`
- `resources/js/lib/visibilidadeMenu.js` - `itemOcultoPorPapel()`, regra pura de exclusão por papel (D-08)
- `tests/js/visibilidadeMenu.test.js` - 7 casos da regra nova
- `resources/js/Layouts/AppLayout.jsx` - `itemVisivel()` usa `itemOcultoPorPapel()`; `effectiveRoles` removida

## Decisions Made
- **D-03 (correção do achado da pesquisa):** o comentário "regra herdada" fazia crer que o bug seria "vale só como analista", mas o array `$sync[$userId] = [...]` sobrescreveria a PRIMEIRA atribuição com a SEGUNDA — o bug residual real seria "vale só como estrategista" se alguém só removesse o `if`. A correção não reaproveitou nenhuma parte do array: trocou por dois `attach()` incondicionais, cada um guardado pelo próprio `if ($novoX !== null)`.
- **D-07 (sem código):** confirmado que `responsaveisDoSurvey()` já resolve `estrategista` e `analista` com duas chamadas independentes de `wherePivot('role', $role)->first()` — não há nenhum ponto em que a mesma pessoa "vença" um papel sobre o outro nesse caminho.
- **D-04/SC3 (sem código):** os 5 testes confirmam a pesquisa sem exigir nenhuma investigação adicional — os 4 backends já filtravam por slug de cargo com `whereIn`/`whereExists` independentes. Nenhum achado divergente da pesquisa foi encontrado (o `<action>` da Task 2 instruía parar e reportar se algum teste falhasse; nenhum falhou).
- **D-08 (equivalência para cargo único):** a regra nova ("oculta só se TODOS os cargos restantes estão em `excludeRoles`") é matematicamente idêntica à antiga quando a pessoa tem 1 cargo só — não foi necessário nenhum branch especial de compatibilidade retroativa. O cargo `'dev'` é filtrado antes da regra porque ele já tem um gate próprio (`modulos_ocultos`) e não é um "papel de acesso" no sentido de `excludeRoles`.
- **Setor Performance nos testes (achado de ambiente, não decisão de produto):** a migration `2026_09_10_140000_seed_setor_performance` (Fase 157) já cria o setor `setores.slug='performance'` — diferente do que um comentário antigo em `DistribuicaoServiceTest` (Fase 154) registrava como "não tem linha correspondente". Os testes novos usam `Setor::firstOrCreate(['slug' => 'performance'], ...)` para não duplicar nem depender da ordem de migrations.

## Deviations from Plan

None - plan executado exatamente como escrito. Task 2 confirmou, sem surpresas, o que a pesquisa (§5) já previa: os quatro backends de selects não precisavam de nenhuma mudança de código.

## Issues Encountered

Nenhum. O padrão "teste passa sem mudança de produção porque o comportamento já é correto por construção" se repetiu na Task 2 (análogo ao observado em 159-01/159-02) — não é gatilho de fail-fast porque o `<action>` da própria task previa esse resultado como o caminho esperado, com instrução explícita de parar e reportar SE algo falhasse (nada falhou).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## TDD Gate Compliance

Tasks 1 e 3 têm `tdd="true"` no frontmatter do plano e seguiram o ciclo completo:

- Task 1: `test(159-03)` RED (`ec65b9d6`, 5/8 falham) → `feat(159-03)` GREEN (`84e26b8f`, 8/8 passam). Sem REFACTOR (não foi necessário).
- Task 3: `test(159-03)` RED (`a0232ab5`, módulo inexistente — `ERR_MODULE_NOT_FOUND`) → `feat(159-03)` GREEN (`c24f7a8d`, 7/7 passam + `npm run test:js`/`npm run build` verdes). Sem REFACTOR (não foi necessário).

Task 2 não tem `tdd="true"` no frontmatter da task (é `type="auto" tdd="true"` no cabeçalho do bloco, mas o `<action>` explicitamente instrui "este plano NÃO altera código de produção nesta task" — os 5 testes passam de primeira porque a produção já estava correta, confirmado por execução real do phpunit, não por inspeção).

Todos os gates RED e GREEN confirmados por execução real (phpunit e node --test) entre os commits, não apenas por inspeção de código.

## Next Phase Readiness
- SC2 e SC3 entregues e provados por teste; D-07 travado por teste sem tocar o NPS; D-08 aplicado com a regra isolada, testável e testada.
- `CompanyController::update()` agora é a base correta para qualquer plano futuro que precise ler/escrever os dois papéis da mesma pessoa (ex.: 159-06, a junção das contas do Danilo, que vai mover vínculos `company_users` do user 35 para o 15 — se o 15 já tiver um dos dois papéis, o padrão de dois `attach()` independentes por role continua valendo).
- Nenhum bloqueio identificado. Os demais planos da fase (D-05, D-06, D-09, D-11, D-12) seguem conforme `159-CONTEXT.md` — nenhum depende de código deste plano além do padrão de escrita por `role` já confirmado aqui.

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-09-30*

## Self-Check: PASSED

- `159-03-SUMMARY.md` presente em `.planning/phases/159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo/`
- Todos os 7 arquivos citados (criados/modificados) confirmados presentes no disco
- Todos os 5 commits citados confirmados em `git log --oneline`: `ec65b9d6`, `84e26b8f`, `3daaa4bc`, `a0232ab5`, `c24f7a8d`
