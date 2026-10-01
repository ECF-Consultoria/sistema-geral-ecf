---
phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo
plan: 05
subsystem: database
tags: [laravel, artisan-command, migration, dry-run, backup-restore, activity-log, mariadb-sqlite-parity]

# Dependency graph
requires:
  - phase: 159-01
    provides: "unique de user_setores trocado para (user_id, setor_id, cargo_id) — permite duas linhas de cargo para a mesma pessoa no mesmo setor (D-01), usado pela etapa `cargos` deste plano"
provides:
  - "Comando `usuarios:unificar-contas --de --para --a-partir` com dry-run por padrão, `--apply` explícito e `--desfazer=<lote>`"
  - "`UnificacaoContasService::planejar()` — somente leitura, monta as 4 etapas do núcleo (carteira, historico_gestao, cargos, desativar_origem) e os bloqueios de competência consolidada"
  - "Censo D-11: toda coluna do banco que referencia `users` (FK ou heurística de nome), classificada tratada/mantida/sem_regra, com `--manter` liberando pendência sem regra"
  - "Backup por lote em `unificacao_contas_backup` (sem FK), com `aplicar()`/`desfazer()` transacionais e reconsulta ao banco pós-`--apply` decidindo o exit code"
affects: [159-06]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Comando Artisan dry-run/--apply/--desfazer com backup por lote uuid (molde `ReverterRecadastrosCompetencia`/`mlb_reversao_backup`) — backup gravado ANTES de update/delete e LOGO APÓS insert"
    - "Censo dinâmico de toda FK/coluna `user_id` apontando para `users`, via `information_schema` no MySQL/MariaDB e `PRAGMA foreign_key_list`/`table_info` no SQLite — sem hardcode de lista de tabelas"
    - "Reconsulta ao banco como veredito do `--apply` (replaneja e conta operações pendentes), nunca o texto impresso no stdout (learnings §4)"

key-files:
  created:
    - database/migrations/2026_09_30_110000_create_unificacao_contas_backup_table.php
    - app/Services/Usuarios/UnificacaoContasService.php
    - app/Console/Commands/UnificarContas.php
    - tests/Feature/Phase159/UnificarContasCommandTest.php
  modified: []

key-decisions:
  - "D-06 (núcleo): carteira (`company_users`) move por linha, com colisão (mesma empresa+role+serviço no destino) resolvida por `delete` da origem em vez de `update` — nunca tenta um UPDATE que colidiria no unique"
  - "D-06 (histórico): `company_manager_history` só ACRESCENTA saída da origem + entrada do destino por (empresa, papel) tocado pela carteira; em colisão (delete) não há entrada nova, pois o destino já estava lá — log append-only nunca reescrito"
  - "D-06 (cargos): destino ganha só o(s) cargo(s) que a origem tem e ele não tem, sempre com `is_principal=false`; linhas de `user_setores` da origem não são tocadas (usa D-01 da 159-01)"
  - "D-06 (desativação): sempre a ÚLTIMA etapa; `users.active=false` sem apagar e sem nunca copiar `password`/`remember_token` para o backup"
  - "D-11 (censo): classificação por constante fixa (`company_users.user_id`/`user_setores.user_id` = tratada; `company_manager_history.*`, `portfolio_goals.user_id`, `sessions.user_id` = mantida) + heurística de nome (prefixo `dev_`/`chamado`, sufixo `_by`/`_por`, `autor_id`/`ator_id`/`solicitante_id`/`enviado_por` = mantida) — o resto vira `sem_regra` e trava o `--apply` até `--manter` decidir"
  - "Backup sem FK nenhuma (`unificacao_contas_backup`) — uma FK com cascade apagaria o próprio backup no mesmo DELETE que ele precisa restaurar depois, e o `--desfazer` também apaga/recria linhas que o backup descreve"
  - "Reconsulta pós-`--apply` chama `planejar()` de novo com os mesmos parâmetros e soma as operações pendentes nas 4 etapas — exit 1 se sobrar alguma, nunca confia no `$n` retornado pelo `aplicar()` (mesmo padrão do `desempenho:verificar-consolidacao`, learnings §4)"

patterns-established:
  - "TDD por task com commits RED/GREEN reais: para cada task, o commit `test(...)` foi criado com o serviço/comando AINDA NÃO existentes (ou sem a funcionalidade da task), rodado e confirmado como falha real antes do commit `feat(...)` que faz os mesmos testes passarem"

requirements-completed: [D-06, D-11]

# Metrics
duration: ~35min
completed: 2026-09-30
---

# Fase 159 Plano 05: Junção de contas — núcleo (comando, backup, censo) Summary

**Comando `usuarios:unificar-contas` move carteira/histórico/cargos e desativa a origem com dry-run por padrão, censo dinâmico de D-11 travando colunas sem regra, backup por lote e `--desfazer`, confirmado por reconsulta ao banco (não por stdout).**

## Performance

- **Duration:** ~35 min
- **Tasks:** 2 (ambas `tdd="true"`, cada uma com ciclo RED→GREEN real)
- **Files modified:** 4 (todos novos — nenhum arquivo existente foi tocado)

## Accomplishments
- Migration `unificacao_contas_backup` sem nenhuma FK (o backup precisa sobreviver ao DELETE que ele descreve e ao `--desfazer` que apaga/recria linhas), com índice `unificacao_contas_backup_lote_index`
- `UnificacaoContasService::planejar()` — somente leitura — monta as 4 etapas do núcleo (`carteira`, `historico_gestao`, `cargos`, `desativar_origem`, sempre nesta ordem, com `desativar_origem` sempre última) e os bloqueios de competência consolidada (snapshot mensal `desempenho_score_snapshots` OU detalhe por empresa `origem=consolidar_mes`), para origem OU destino, sempre `>=` o início de `--a-partir`
- Censo D-11 dinâmico: descobre toda coluna que referencia `users` via `information_schema.KEY_COLUMN_USAGE`/`COLUMNS` (MySQL/MariaDB) ou `PRAGMA foreign_key_list`/`table_info` (SQLite), classifica cada uma (`tratada`/`mantida`/`sem_regra`) e trava o `--apply` quando há coluna `sem_regra` com linha da origem fora de `--manter`
- `aplicar()` grava o backup ANTES de cada update/delete e LOGO APÓS cada insert (para capturar o id via `insertGetId`), tudo numa única transação; recusa (sem gravar nada) se houver bloqueio ou pendência de censo
- `bustarCache()` derruba a chave de `DesempenhoScoreService::cacheKey()` dos dois usuários, mês a mês, do início de `--a-partir` até o mês corrente — nunca `cache:clear` (learnings §5)
- `desfazer()` restaura um lote em ordem inversa, sem `try/catch` (colisão ao restaurar derruba a transação inteira), recusa lote inexistente ou já desfeito
- Comando: dry-run por padrão, `--apply` reconsulta o banco (replaneja) e só sai 0 quando a soma de operações pendentes é zero, `--desfazer=<lote>` tem precedência, `--json` imprime o plano inteiro; NUNCA imprime nota/faixa/valor de bônus (learnings §11)
- 16 testes novos em `tests/Feature/Phase159/UnificarContasCommandTest.php` (607 linhas), todos verdes; suíte completa de `tests/Feature/Phase159/` (69 testes) sem regressão; `git diff origin/main -- app/Services/DesempenhoScoreService.php app/Services/Desempenho/` vazio

## Task Commits

Cada task seguiu o ciclo TDD RED→GREEN real (teste rodado e confirmado falho antes do código que o faz passar):

1. **Task 1: Tabela de backup, planejar() com travas, censo e etapas do núcleo, comando em dry-run**
   - RED: `fe587fdb` (test) — 8 métodos de teste (7 cenários do `<behavior>`), confirmados falhos por `CommandNotFoundException`/tabela ausente (migration/serviço/comando ainda não existiam)
   - GREEN: `d7b1a2f2` (feat) — migration + `UnificacaoContasService` (parte de leitura) + comando (só caminho dry-run); os 8 testes passam
2. **Task 2: --apply com backup, reconsulta, cache e --desfazer**
   - RED: `6943b958` (test) — mais 8 métodos de teste acrescentados (16 no total), 6 falham de verdade (`--apply`/`--desfazer` ainda não faziam nada nesta base — Task 1 só entrega dry-run)
   - GREEN: `c0309a1f` (feat) — `aplicar()`, `desfazer()`, `bustarCache()`, `aplicarOperacao()` no serviço + `--apply`/`--desfazer`/reconsulta no comando; os 16 testes passam

**Plan metadata:** commit separado a seguir (docs: complete plan)

## Files Created/Modified
- `database/migrations/2026_09_30_110000_create_unificacao_contas_backup_table.php` - tabela de backup por lote, sem FK, com decisão de schema completa no docblock
- `app/Services/Usuarios/UnificacaoContasService.php` - `planejar()`/`pendenciasCenso()`/`aplicar()`/`desfazer()`, censo D-11, bloqueios de competência consolidada, bust de cache
- `app/Console/Commands/UnificarContas.php` - comando `usuarios:unificar-contas` (dry-run, `--apply`, `--desfazer`, `--manter`, `--json`)
- `tests/Feature/Phase159/UnificarContasCommandTest.php` - 16 testes cobrindo os 15 cenários do `<behavior>` das duas tasks (607 linhas)

## Decisions Made
Ver `key-decisions` no frontmatter — resumo: carteira move por linha com delete-em-colisão; histórico só acrescenta (nunca reescreve); cargos só inserem o que falta no destino (`is_principal=false`); desativação é sempre a última etapa e nunca toca senha/token; censo classifica por constante fixa + heurística de nome e trava qualquer coluna sem regra com dado real; backup sem FK nenhuma; reconsulta ao banco (não o stdout) decide o exit code do `--apply`.

## Deviations from Plan

None - plano executado exatamente como escrito. As duas tasks já previam os campos/etapas/bloqueios com bastante precisão no `<action>`; a implementação seguiu a especificação linha por linha (inclusive nomes de etapas, ordem, condição de colisão null-safe em `servico_id`, e a lista de motivos do censo).

Uma correção cosmética foi feita durante a Task 1, antes do commit GREEN: o docblock original da migration continha a substring literal `constrained()` explicando por que ela NÃO foi usada — como o critério de aceitação do plano verifica `nenhuma chamada constrained(` via grep, reescrevi a frase para descrever o mesmo raciocínio sem usar a substring literal (ex.: "FK comum com `cascadeOnDelete`" em vez de `constrained()`). Não é uma mudança de comportamento, só evita um falso positivo de grep num comentário.

## Issues Encountered
None além do ajuste cosmético de docblock acima.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. O comando é de uso exclusivo via shell no servidor (`php artisan usuarios:unificar-contas`), sem rota HTTP nova (threat model T-159-17, disposition `accept`).

## TDD Gate Compliance

Ambas as tasks têm `tdd="true"` no frontmatter do plano e seguiram o ciclo completo RED→GREEN, confirmado por execução real do PHPUnit entre os commits (não apenas por inspeção de código):

- Task 1: `test(159-05)` RED (`fe587fdb`, 8/8 falham por `CommandNotFoundException`/tabela ausente) → `feat(159-05)` GREEN (`d7b1a2f2`, 8/8 passam).
- Task 2: `test(159-05)` RED (`6943b958`, 6/16 falham de verdade nas asserções de `--apply`/`--desfazer`/cache) → `feat(159-05)` GREEN (`c0309a1f`, 16/16 passam).

Sem REFACTOR em nenhuma das duas (não foi necessário).

## Next Phase Readiness

- **SC6 está apenas PARCIALMENTE completo.** Este plano entrega o NÚCLEO (carteira, histórico de gestão, cargos, desativação da origem) e as travas/censo de D-11. O plano 159-06 acrescenta as etapas de NPS, snapshots, PPAs e onboardings sobre a mesma estrutura (`UnificacaoContasService`/`unificacao_contas_backup`/comando já prontos para receber novas etapas). **Não marcar SC6 como concluído até o 159-06 terminar.**
- A operação em produção (mover de fato o user 35 para o 15) continua como passo separado, a rodar DEPOIS do deploy e com o usuário ciente (D-06 do CONTEXT.md) — nada foi executado contra produção nesta fase, só código+testes locais.
- `DesempenhoScoreService.php` e `app/Services/Desempenho/` seguem sem nenhuma alteração (`git diff origin/main` vazio) — confirmado ao final da Task 2.
- Nenhum bloqueio identificado para o 159-06 prosseguir sobre esta base.

---
*Phase: 159-pessoa-com-dois-cargos-e-juncao-das-contas-do-danilo*
*Completed: 2026-09-30*

## Self-Check: PASSED

- Todos os 5 arquivos citados (4 criados + este SUMMARY) confirmados presentes no disco
- Todos os 4 commits citados confirmados em `git log --oneline`: `fe587fdb`, `d7b1a2f2`, `6943b958`, `c0309a1f`
