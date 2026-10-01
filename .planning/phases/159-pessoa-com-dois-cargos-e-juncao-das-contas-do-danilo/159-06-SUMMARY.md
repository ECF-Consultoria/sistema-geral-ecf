---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 06
subsystem: database
tags: [laravel, artisan-command, nps, dry-run, backup-restore, activity-log, mariadb-sqlite-parity]

# Dependency graph
requires:
  - phase: 159-05
    provides: "UnificacaoContasService::planejar()/aplicar()/desfazer() — núcleo (carteira, histórico de gestão, cargos, desativação), backup por lote, censo D-11 e comando usuarios:unificar-contas com dry-run/--apply/--desfazer"
provides:
  - "UnificacaoContasService estendido com as etapas nps_atribuicoes, nps_imputacoes, snapshots_diarios, snapshots_empresa, ppas e onboardings — SC6 completo em código"
  - "Avisos de janela de coleta do NPS no plano da junção (coleta ainda não aberta + lembrete de re-execução com data-limite)"
  - "Comando desempenho:auditar-ramo-legado — medição SÓ LEITURA de D-09 (exposição do ramo legado de NPS por profissional/competência)"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Etapa de junção que precisa de JOIN para competência (nps_atribuicoes via nps_responses/nps_surveys.completed_at) convive, na mesma planejar(), com etapa cuja competência já é coluna materializada (nps_imputacoes via competencia_nps) — nenhuma das duas reimplementa a régua do motor, só filtra >= o corte"
    - "Colisão por grão específico de cada tabela (nunca um único helper genérico): carteira usa (company,role,servico), nps_atribuicoes usa (nps_response_id,role,servico), nps_imputacoes usa o grão do unique nps_imput_grao_uniq"
    - "Comando de medição (D-09) delega 100% a leitura ao serviço existente (NpsPorEmpresaService::notasNpsPorEmpresa) e só reagrupa por divergência/parcialidade — nunca reimplementa a regra do ramo legado, molde de VerificarConsolidacaoDesempenho (read-only, veredito por exit code)"

key-files:
  created:
    - app/Console/Commands/AuditarRamoLegadoNps.php
    - tests/Feature/Phase159/AuditarRamoLegadoCommandTest.php
  modified:
    - app/Services/Usuarios/UnificacaoContasService.php
    - tests/Feature/Phase159/UnificarContasCommandTest.php

key-decisions:
  - "D-06 (NPS): competência de nps_score_assignments nunca é coluna — sempre o JOIN nps_responses/nps_surveys.completed_at >= mês de coleta do corte (NpsJanelaResolver::mesDeColeta); nps_imputed_assignments.competencia_nps JÁ é o mês de coleta materializado, comparação direta"
  - "D-06 (colisão NPS): destino já ter a mesma linha pelo grão da origem vira delete da origem (nunca update que colidiria); carteira, atribuições e imputações seguem a mesma disciplina do 159-05"
  - "D-06 (snapshots): só CACHE (diário sem mes_referencia; detalhe por empresa com origem != consolidar_mes) a partir do corte FINANCEIRO (não o mês de coleta) é removido — mensal e consolidar_mes nunca, e a trava de competência consolidada do 159-05 já impede que uma dessas linhas exista >= corte"
  - "D-06 (avisos): plano sempre acrescenta um aviso de re-execução com a data-limite do desempenho:consolidar-mes, mesmo quando a coleta já abriu — respostas que já chegaram antes do --apply ficam com a origem até o próximo --apply"
  - "D-11 (PPAs/onboardings): regra mover/manter é 'em aberto segue a pessoa, concluído fica no histórico' — ppas.status in (draft,sent) e onboardings não concluídos (rascunho,andamento); onboardings usa uma operação por (linha,coluna) entre os 3 slots de responsável, nunca mexendo em coluna não tocada"
  - "D-09: medição é 100% delegada a NpsPorEmpresaService::notasNpsPorEmpresa() — o comando novo não toca DesempenhoScoreService/NpsPorEmpresaService/User (confirmado por git diff origin/main vazio nos 3 arquivos); 'papel real' vem de company_users agrupado por empresa, nunca de dimensaoNpsDesempenho (que só dá o papel APLICADO)"

patterns-established:
  - "TDD por task com commits RED/GREEN reais: cada task teve o commit test(...) criado com a funcionalidade ainda ausente, rodado e confirmado falho (CommandNotFoundException para o comando novo; asserções de contagem/JSON para as etapas), antes do commit feat(...) que faz os mesmos testes passarem"

requirements-completed: [SC6, D-06, D-09, D-11]

# Metrics
duration: ~30min
completed: 2026-10-01
---

# Fase 159 Plano 06: Etapas finais da junção (NPS/snapshots/PPAs/onboardings) e medição do ramo legado Summary

**UnificacaoContasService ganha 6 etapas novas (NPS, snapshots, PPAs, onboardings) que fecham SC6 em código, e nasce `desempenho:auditar-ramo-legado`, uma medição só-leitura do D-09 pronta para rodar em produção.**

## Performance

- **Duration:** ~30 min
- **Tasks:** 3 (todas `tdd="true"`, cada uma com ciclo RED→GREEN real)
- **Files modified:** 4 (2 novos, 2 estendidos — nenhum arquivo do motor de bônus tocado)

## Accomplishments
- `UnificacaoContasService::planejar()` passa de 4 para 10 etapas: `carteira`, `historico_gestao`, `cargos`, `nps_atribuicoes`, `nps_imputacoes`, `snapshots_diarios`, `snapshots_empresa`, `ppas`, `onboardings`, `desativar_origem` (sempre última) — SC6 completo em código
- Etapas de NPS movem atribuições/imputações da competência a partir do corte pela régua exata do motor (`NpsJanelaResolver::mesDeColeta`, nunca `assigned_at`/`month_reference`), com colisão resolvida por `delete` da origem (nunca `update` que colidiria)
- Etapas de snapshots removem só o que é CACHE (diário sem `mes_referencia`; detalhe por empresa com `origem != consolidar_mes`) a partir do corte financeiro — mensal e `consolidar_mes` nunca são tocados
- Plano sempre acrescenta um aviso de re-execução com a data-limite do `desempenho:consolidar-mes`, mais um aviso específico quando a coleta do corte ainda não abriu (0 atribuições é esperado, não bug)
- Etapas de PPAs/onboardings aplicam a regra "em aberto segue a pessoa, concluído fica" — PPA `draft`/`sent` e onboarding `rascunho`/`andamento`, uma operação por (linha, coluna) nos 3 slots de responsável de onboarding
- Censo D-11 ganha 6 colunas novas classificadas `tratada` com motivo explícito ("parcial: só competência >= corte" / "parcial: só o que ainda está em aberto")
- `aplicar()`/`desfazer()`/`bustarCache()`/reconsulta do 159-05 não precisaram de nenhuma mudança — já eram genéricos o bastante para as etapas novas (confirmado pelos testes 21 e 27: desfazer restaura NPS/snapshots/PPAs/onboardings com o mesmo id)
- `desempenho:auditar-ramo-legado` (D-09): comando novo, só leitura, que mede quanto da nota de NPS de cada profissional/competência vem do ramo legado e em que lojas o papel aplicado (`dimensaoNpsDesempenho`) diverge do papel real (`company_users`) ou cobre só um de dois papéis acumulados — delega 100% a leitura a `NpsPorEmpresaService::notasNpsPorEmpresa()`, nunca reimplementa a regra
- 33 testes novos em `tests/Feature/Phase159/` (28 em `UnificarContasCommandTest.php`, 5 em `AuditarRamoLegadoCommandTest.php`), todos verdes; suíte completa de `tests/Feature/Phase159/` (86 testes) sem regressão; `tests/Feature/Phase118/` (36 testes) e `NpsPorEmpresaContratoTest.php` verdes; `git diff origin/main -- app/Services/DesempenhoScoreService.php app/Services/Desempenho/ app/Services/Nps/ app/Models/User.php` vazio

## Task Commits

Cada task seguiu o ciclo TDD RED→GREEN real (teste rodado e confirmado falho antes do código que o faz passar):

1. **Task 1: Etapas de NPS, snapshots e aviso de janela de coleta**
   - RED: `3dc4bd37` (test) — testes 16-22 + Teste 2 (159-05) atualizado para a lista de etapas; 8 falhas reais confirmadas (assinatura de etapas, contagens de linhas movidas/removidas, aviso ausente, censo `sem_regra`)
   - GREEN: `bfe85b74` (feat) — 4 etapas novas + avisos + censo; 23/23 testes passam

2. **Task 2: Etapas de PPAs e onboardings — regra mover/manter do D-11**
   - RED: `0b203575` (test) — testes 23-27 + Teste 2 atualizado de novo; 6 falhas reais confirmadas
   - GREEN: `e7d5d520` (feat) — 2 etapas novas + censo; 28/28 testes passam

3. **Task 3: Comando de medição do ramo legado de NPS (D-09)**
   - RED: `50849dfa` (test) — 5 testes, todos falhando por `CommandNotFoundException` (comando ainda não existia)
   - GREEN: `1cc9a532` (feat) — `AuditarRamoLegadoNps`; 5/5 testes passam

**Plan metadata:** commit separado a seguir (docs: complete plan)

## Files Created/Modified
- `app/Services/Usuarios/UnificacaoContasService.php` - 6 etapas novas (`planejarNpsAtribuicoes`/`planejarNpsImputacoes`/`planejarSnapshotsDiarios`/`planejarSnapshotsEmpresa`/`planejarPpas`/`planejarOnboardings`), avisos de janela de coleta, 6 entradas novas no censo D-11
- `tests/Feature/Phase159/UnificarContasCommandTest.php` - testes 16-27 (12 novos) + Teste 2 atualizado duas vezes para a lista completa de etapas; helpers `criarAtribuicaoNps`/`criarImputacaoNps`/`criarPpa`/`criarOnboarding`
- `app/Console/Commands/AuditarRamoLegadoNps.php` - comando `desempenho:auditar-ramo-legado` (D-09), só leitura
- `tests/Feature/Phase159/AuditarRamoLegadoCommandTest.php` - 5 testes cobrindo divergência, parcialidade, validação de `--mes` e a garantia de "só leitura"

## Decisions Made
Ver `key-decisions` no frontmatter — resumo: competência de NPS nunca é coluna (sempre a régua do motor); colisão em qualquer etapa vira `delete` da origem, nunca `update`; snapshots só removem CACHE a partir do corte financeiro; o plano sempre avisa a data-limite de re-execução; PPAs/onboardings seguem "em aberto segue a pessoa, concluído fica"; a medição de D-09 delega 100% ao serviço existente e nunca toca o motor de bônus.

## Deviations from Plan

None - plano executado exatamente como escrito. As três tasks já previam etapas/campos/condições de colisão com bastante precisão no `<action>`; a implementação seguiu a especificação linha por linha (inclusive nomes de etapas, ordem, grão de colisão de cada tabela, textos de aviso e a estrutura de `divergentes`/`parciais` do comando de auditoria).

## Issues Encountered

Nenhum bloqueio. Único ponto que exigiu atenção extra (não é deviation, é disciplina de TDD): como as etapas novas se inserem na MESMA lista de etapas que o `Teste 2` do 159-05 já travava com `assertSame` da ordem completa, esse teste precisou ser atualizado DUAS vezes (uma por task) como parte do próprio commit RED de cada task — documentado explicitamente nas mensagens de commit `test(159-06)` para não parecer uma alteração de comportamento escondida num commit de feature.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Os dois comandos (`usuarios:unificar-contas` estendido e `desempenho:auditar-ramo-legado` novo) são de uso exclusivo via shell no servidor, sem rota HTTP nova.

## TDD Gate Compliance

As três tasks têm `tdd="true"` no frontmatter do plano e seguiram o ciclo completo RED→GREEN, confirmado por execução real do PHPUnit entre os commits (não apenas por inspeção de código):

- Task 1: `test(159-06)` RED (`3dc4bd37`, 8/23 falham de verdade) → `feat(159-06)` GREEN (`bfe85b74`, 23/23 passam).
- Task 2: `test(159-06)` RED (`0b203575`, 6/28 falham de verdade) → `feat(159-06)` GREEN (`e7d5d520`, 28/28 passam).
- Task 3: `test(159-06)` RED (`50849dfa`, 5/5 falham por `CommandNotFoundException`) → `feat(159-06)` GREEN (`1cc9a532`, 5/5 passam).

Sem REFACTOR em nenhuma das três (não foi necessário).

## Next Phase Readiness

- **SC6 está COMPLETO em código.** O comando `usuarios:unificar-contas` agora move carteira, histórico de gestão, cargos, atribuições de NPS, imputações, snapshots de cache (nunca competência fechada), PPAs e onboardings em aberto da origem ao destino a partir do corte — sempre com backup por lote, `--desfazer` e reconsulta ao banco como veredito.
- **D-09 tem medição pronta para produção.** `desempenho:auditar-ramo-legado --user=15 --user=35 --mes=2026-08` (ou a competência que for decidida) pode rodar contra o banco de produção, leitura pura, para decidir se há exposição real do ramo legado antes de qualquer `--apply` da junção.
- **A operação em produção continua como passo separado** (D-06 do CONTEXT.md): nada foi executado contra produção nesta fase, só código + testes locais. A ordem recomendada para quem for operar: (1) rodar `auditar-ramo-legado` em produção para decidir D-09; (2) rodar `unificar-contas` em dry-run; (3) `--apply` depois do deploy e com o usuário ciente; (4) re-rodar a etapa de NPS depois que a coleta da competência abrir (01/10 para a competência 09), antes do `desempenho:consolidar-mes` do último dia do mês de coleta às 14:00.
- `DesempenhoScoreService.php`, `app/Services/Desempenho/`, `app/Services/Nps/` e `app/Models/User.php` seguem sem nenhuma alteração (`git diff origin/main` vazio) — confirmado ao final de cada task.
- Nenhum bloqueio identificado. Fase 159 pode ser considerada pronta para fechamento (sujeita à confirmação do verifier sobre `BASELINE-TESTES.md`/D-12).

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-10-01*

## Self-Check: PASSED

- Todos os 5 arquivos citados (4 criados/modificados + este SUMMARY) confirmados presentes no disco
- Todos os 6 commits citados confirmados em `git log --oneline`: `3dc4bd37`, `bfe85b74`, `0b203575`, `e7d5d520`, `50849dfa`, `1cc9a532`
