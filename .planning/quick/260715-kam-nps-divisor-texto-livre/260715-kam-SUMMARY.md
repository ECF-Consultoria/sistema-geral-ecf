---
phase: quick-260715-kam
plan: 01
subsystem: nps
tags: [nps, bonus, divisor, texto_livre, sqlite-check-constraint, backfill]

# Dependency graph
requires:
  - phase: 79-nps-multi-modelo
    provides: nps_response_scores/nps_score_assignments (snapshot congelado consumido pelo bônus da Fase 80)
  - phase: 69-backend-regras-de-negocio
    provides: NpsScoreCalculator::compute() (fonte da regra 2026-07-08 preservada)
provides:
  - "Divisor de nota NPS por dimensão corrigido: texto_livre nunca conta no denominador"
  - "NpsScoreCalculator::contarPerguntasComPeso() como fonte única do divisor (calculator + snapshot + backfill)"
  - "Comando nps:backfill-divisor-texto-livre para corrigir retroativamente as notas congeladas"
  - "Fix de migration: SQLite enum CHECK bloqueava qualquer teste com tipo=texto_livre"
affects: [80-bonus, desempenho-score-service, nps-dashboards]

tech-stack:
  added: []
  patterns:
    - "Divisor único (contarPerguntasComPeso) consumido por 3 pontos — nunca duplicar a query do denominador"
    - "Backfill idempotente por máquina de estados (ja_corrigido/corrigivel/divergente/sem_base) em vez de flag de data de corte"

key-files:
  created:
    - tests/Feature/V16/NpsDivisorTextoLivreTest.php
    - tests/Feature/V16/NpsBackfillDivisorTest.php
    - app/Console/Commands/NpsBackfillDivisorTextoLivre.php
  modified:
    - app/Services/Nps/NpsScoreCalculator.php
    - app/Services/Nps/NpsSnapshotService.php
    - database/migrations/2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php

key-decisions:
  - "Divisor exclui SOMENTE tipo=texto_livre; pergunta escala/opções pulada continua no divisor (regra 2026-07-08 preservada, coberta por teste de regressão isolado em dimensão diferente)"
  - "NpsSnapshotService para de duplicar a query do denominador e passa a chamar o calculator injetado — elimina a classe de bug de invariante quebrada"
  - "Backfill é comando artisan (não migration): a nota corrigida vira valor de bônus, o operador precisa ver o diff antes de gravar"
  - "Migration 2026_07_13_101151 corrigida (Rule 3 bloqueante): SQLite enforça CHECK de enum() do Schema builder — o skip total assumido pela migration original estava errado; aplicado o mesmo padrão já usado em servicos.setor (string sem CHECK no SQLite, ALTER ENUM no MySQL)"

requirements-completed: [KAM-01]

# Metrics
duration: ~50min
completed: 2026-07-15
---

# Quick Task 260715-kam: Divisor de nota NPS exclui texto_livre Summary

**Divisor de nota NPS por dimensão corrigido para excluir perguntas `texto_livre` (que nunca têm peso), destravando a nota 5.00 e corrigindo retroativamente o bônus congelado via comando artisan idempotente.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 3 (RED → GREEN → backfill)
- **Files modified/created:** 6 (3 novos, 3 modificados)

## Accomplishments

- Provado e corrigido: nota 5.00 era matematicamente inalcançável em qualquer dimensão com pergunta `texto_livre`, porque o divisor contava essa pergunta mesmo ela nunca tendo peso (`option_peso_snapshot` sempre `NULL`). Caso real (template principal de produção id=2): cliente LUCCMAX Luccauto Itajaí que respondeu peso 5 em tudo recebia 4.17/3.89/3.75 em vez de 5.00.
- `NpsScoreCalculator::contarPerguntasComPeso()` virou a fonte única do denominador, consumida por `compute()`, `NpsSnapshotService::registrar()` e pelo backfill — elimina a duplicação de query que fazia a invariante `score_sum / question_count == average_score` quebrar silenciosamente.
- Regra de 2026-07-08 (pergunta escala/opções pulada continua puxando a média pra baixo) preservada e coberta por teste de regressão dedicado.
- Comando `nps:backfill-divisor-texto-livre` criado: dry-run, confirmação obrigatória, diff antes/depois, idempotente por máquina de estados (nunca por flag/data de corte), propaga o `average_score` corrigido para `nps_score_assignments` (base direta do bônus da Fase 80).
- Deviation crítica descoberta e corrigida: a migration que introduziu `tipo=texto_livre` (2026-07-13) assumia que "SQLite ignora ENUM" — falso. O Schema builder do Laravel emula `enum()` como `CHECK` no SQLite, e esse CHECK é ENFORÇADO. Isso bloqueava QUALQUER teste (não só este) que tentasse persistir uma pergunta `texto_livre`. Corrigido com o mesmo padrão já estabelecido para `servicos.setor` (shopee/polos).

## Task Commits

Each task was committed atomically:

1. **Task 1: RED — teste que prova o bug + fix da migration bloqueante (Rule 3)** - `a534893` (test)
2. **Task 2: GREEN — divisor exclui texto_livre na fonte única de verdade** - `e3b2103` (fix)
3. **Task 3: Backfill idempotente e auditável das notas congeladas** - `856c716` (feat)

_Não há commit de metadados/plano — por constraint desta quick task, SUMMARY.md/STATE.md/ROADMAP.md NÃO são commitados aqui; o orquestrador cuida disso._

## Files Created/Modified

- `tests/Feature/V16/NpsDivisorTextoLivreTest.php` - 7 testes: caso LUCCMAX (empresa/analista/estrategista), regressão 2026-07-08, texto_livre respondida não altera nada, dimensão só-texto_livre retorna null, invariante do snapshot
- `tests/Feature/V16/NpsBackfillDivisorTest.php` - 6 testes: dry-run, force, idempotência, propagação para assignments, divergente pulado, sem_base sem divisão por zero
- `app/Services/Nps/NpsScoreCalculator.php` - método público `contarPerguntasComPeso()` + `compute()` consumindo-o + docblock distinguindo as duas regras (escala pulada vs texto_livre)
- `app/Services/Nps/NpsSnapshotService.php` - `question_count` vem do calculator injetado, elimina query duplicada
- `app/Console/Commands/NpsBackfillDivisorTextoLivre.php` - comando artisan novo (backfill idempotente)
- `database/migrations/2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php` - branch SQLite corrigido (Rule 3)

## Decisions Made

- **Divisor cirúrgico:** só `TIPO_TEXTO_LIVRE` é excluído; a regra de 2026-07-08 (escala/opções pulada conta) fica intacta — verificado com teste dedicado que isola a pergunta `texto_livre` numa dimensão diferente da testada, de propósito, para provar que o teste passa tanto ANTES quanto DEPOIS do fix (não é uma regressão introduzida por esta task).
- **Fonte única do divisor:** em vez de corrigir a query em 2 lugares (calculator + snapshot service) como o bug original fez, extraído um método público reutilizado nos 3 consumidores (calculator, snapshot, backfill). Isso fecha a classe de bug de vez.
- **Backfill como comando, não migration:** o valor corrigido é bônus pago a analistas/estrategistas — precisa de dry-run, diff visível e confirmação humana antes de gravar. Rodar no VPS é decisão do operador, não deste comando.
- **`score_sum` nunca é recalculado no backfill:** o bug é exclusivamente do denominador (texto_livre grava peso NULL, o SUM sempre esteve correto). Recalcular o SUM reabriria snapshot histórico sem necessidade.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Migration de `tipo=texto_livre` bloqueava QUALQUER teste em SQLite**
- **Found during:** Task 1 (ao rodar o RED pela primeira vez)
- **Issue:** `2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php` pulava o SQLite inteiro assumindo "SQLite não tem ENUM real, ignora a constraint" — mas o Schema builder do Laravel emula `$table->enum(...)` como `CHECK (tipo IN (...))` em SQLite, e esse CHECK É ENFORÇADO pelo driver de teste (`SQLSTATE[23000]: CHECK constraint failed: tipo`). Sem o fix, nenhum teste conseguia persistir uma pergunta `texto_livre` — o que inviabilizava toda a Task 1 (o bug inteiro gira em torno desse tipo).
- **Fix:** Aplicado o mesmo padrão já estabelecido em `2026_07_14_100001_add_shopee_to_servicos_setor_enum.php` (memória do projeto `project_enum_setor_sqlite_check`): branch por driver — MySQL via `ALTER ... MODIFY COLUMN` (comportamento original preservado); SQLite via `$table->string('tipo')->change()` (sem CHECK).
- **Files modified:** `database/migrations/2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php`
- **Verification:** Suite RED voltou a rodar e reproduziu o bug com os números exatos do diagnóstico (4.1666.../3.888.../3.75 e question_count=4 em vez de 3)
- **Committed in:** `a534893` (parte do commit da Task 1)

---

**Total deviations:** 1 auto-fixed (1 bloqueante — Rule 3)
**Impact on plan:** Correção essencial e fora do escopo original do plano, mas necessária para que a Task 1 sequer pudesse rodar. Sem ela, a migration ficaria como uma armadilha latente idêntica à de `'polos'` — quebraria o primeiro teste futuro que criasse uma pergunta `texto_livre`. Nenhum scope creep: só o branch SQLite foi tocado, o comportamento MySQL/produção não mudou.

## Issues Encountered

- **MariaDB local indisponível** (`Connection refused` em `127.0.0.1:3306`) — problema de ambiente já conhecido e documentado (`project_mariadb_local_corrompido`), não relacionado a este fix. Toda a suíte de testes rodou em SQLite `:memory:` (comportamento normal do `phpunit.xml`). Consequência prática: **não foi possível rodar o dry-run do backfill contra o banco de produção/local real** — ver seção abaixo.

## Dry-run do backfill — NÃO executado contra dados reais

O comando `php artisan nps:backfill-divisor-texto-livre --dry-run` foi verificado e está registrado (`php artisan list | grep nps:backfill` confirma), mas ao rodar localmente falhou com `SQLSTATE[HY000] [2002] Nenhuma conexão pôde ser feita` — o MariaDB local está fora do ar (problema de ambiente pré-existente, não deste fix). A suíte de testes em SQLite (`tests/Feature/V16/NpsBackfillDivisorTest.php`, 6/6 verde) é a verificação vinculante desta task, conforme o próprio plano previa ("se o MariaDB local estiver indisponível, o comando pode não conectar — a suite em SQLite é a verificação vinculante").

**Ação necessária pós-deploy (para o operador):**
1. Fazer deploy do código desta task (calculator + snapshot service corrigidos + comando de backfill).
2. No VPS, rodar `php artisan nps:backfill-divisor-texto-livre --dry-run` **primeiro** — isso mostra quantas linhas de `nps_response_scores` são corrigíveis/divergentes/sem_base em produção real, SEM gravar nada.
3. Revisar o diff impresso (response_id | dimensão | qc_antes→qc_depois | media_antes→media_depois).
4. Rodar sem `--dry-run` (vai pedir confirmação interativa) para aplicar.

**Aviso importante:** o código corrigido (Task 2) só vale para respostas NOVAS a partir do deploy — ele **não recalcula sozinho** as ~linhas já congeladas em `nps_response_scores`/`nps_score_assignments`. O backfill (Task 3) é obrigatório para corrigir o histórico.

**Impacto no bônus:** as 13 linhas hoje existentes em `nps_score_assignments` (produção) mudam de valor quando o backfill for aplicado — isso afeta diretamente o bônus calculado pela Fase 80 (`DesempenhoScoreService`, que lê essas atribuições). Comunicar ao time antes de rodar o backfill em produção, na mesma linha do aviso já dado para o deploy da Fase 80 (degrau esperado no `delta_vs_ontem`, não é bug).

## User Setup Required

None - nenhuma configuração de serviço externo necessária. Fix é 100% backend.

## Next Phase Readiness

- Código pronto para deploy (calculator + snapshot service + comando de backfill).
- **NÃO deployado** (constraint do CLAUDE.md — requer autorização explícita do usuário).
- Após deploy: rodar `nps:backfill-divisor-texto-livre --dry-run` no VPS antes de aplicar, conforme passos acima.
- Sem bloqueios técnicos pendentes.

---
*Quick task: 260715-kam-nps-divisor-texto-livre*
*Completed: 2026-07-15*

## Self-Check: PASSED

Todos os 7 arquivos declarados (criados/modificados) confirmados no disco; os 3 commits (`a534893`, `e3b2103`, `856c716`) confirmados em `git log`. Nenhum item ausente.
