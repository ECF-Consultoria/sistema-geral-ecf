---
phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
plan: 01
subsystem: database
tags: [migrations, eloquent, activitylog, faturamento, contrato, mariadb]

# Dependency graph
requires: []
provides:
  - "Tabela `servico_faixas_faturamento` (faixas por serviço, com marcação de valor-piso)"
  - "Tabela `empresa_faixas_faturamento` (exceção de faixas por empresa, D-13 all-or-nothing)"
  - "Seed idempotente das 3 tabelas medidas nos modelos publicados na Clicksign (Gestão, Brigada, Gestão de ADS Shopee)"
  - "Models `ServicoFaixaFaturamento` e `EmpresaFaixaFaturamento` auditáveis via LogsActivity"
  - "Relações `Servico::faixasFaturamento()` e `Company::faixasFaturamento()`"
affects: [137-03, 137-04, 137-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Tabela progressiva como dado estruturado (linha por faixa), não constante PHP"
    - "Coluna `valor_e_piso` para distinguir faixa aberta 'a partir de' de preço fechado"
    - "Índice unique nomeado à mão e curto (`sff_servico_ordem_unq`, `eff_empresa_ordem_unq`) — evita erro 1059 do MariaDB"
    - "Seed via migration com `updateOrInsert` por chave natural, resolução de serviço por nome (nunca id hardcoded)"

key-files:
  created:
    - database/migrations/2026_09_02_100001_create_servico_faixas_faturamento_table.php
    - database/migrations/2026_09_02_100002_create_empresa_faixas_faturamento_table.php
    - database/migrations/2026_09_02_100003_seed_faixas_faturamento_iniciais.php
    - app/Models/ServicoFaixaFaturamento.php
    - app/Models/EmpresaFaixaFaturamento.php
    - tests/Feature/Phase137/Phase137FaixasSchemaTest.php
  modified:
    - app/Models/Servico.php
    - app/Models/Company.php
    - .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/deferred-items.md

key-decisions:
  - "Gestão e Brigada recebem a MESMA tabela de 7 faixas (fato medido no modelo publicado, D-02b) — montada uma vez e aplicada aos dois serviços"
  - "Gestão de ADS Shopee tem 8 faixas próprias, todas fechadas — nenhuma faixa aberta, deliberadamente (dado real do modelo publicado)"
  - "Seed resolve serviço por NOME exato (nunca id hardcoded); se um dos 3 serviços não existir no ambiente, pula em silêncio sem criar serviço"
  - "empresa_faixas_faturamento não é semeada — exceção por empresa é sempre cadastro manual (D-04)"
  - "down() das migrations de tabela e do seed não apaga nada — fechamento congelado (D-11) depende do dado permanecer"

patterns-established:
  - "LogOptions::defaults() do Spatie precisa de ->logFillable()/->logOnly()/->logAll() explícito — sem isso NENHUM atributo é rastreado e o log fica vazio (achado durante este plano, ver Deviations)"

requirements-completed: [D-01, D-02, D-02b, D-04, D-13]

# Metrics
duration: 16min
completed: 2026-09-03
---

# Phase 137 Plan 01: Tabelas de faixas de faturamento Summary

**Tabela progressiva de faturamento vira dado estruturado (2 tabelas + seed das 3 tabelas medidas na Clicksign), com auditoria via activity_log.**

## Performance

- **Duration:** 16 min
- **Started:** 2026-09-03T01:45:15Z
- **Completed:** 2026-09-03T02:01:15Z
- **Tasks:** 3/3
- **Files modified:** 9 (6 criados, 3 modificados)

## Accomplishments
- `servico_faixas_faturamento` e `empresa_faixas_faturamento` criadas com índices únicos nomeados à mão e coluna `valor_e_piso`
- Seed idempotente popula as 3 tabelas medidas em produção na Clicksign (Gestão 7 faixas, Brigada 7 faixas idênticas a Gestão, Shopee 8 faixas sem faixa aberta) — 22 linhas ao todo quando os 3 serviços existem
- Models `ServicoFaixaFaturamento`/`EmpresaFaixaFaturamento` com `LogsActivity` (log_name `faixa_faturamento`) e relações `Servico::faixasFaturamento()` / `Company::faixasFaturamento()`
- 8 testes de schema cobrindo colunas, os 3 catálogos, unique constraint, relações, auditoria e idempotência do seed — todos verdes

## Task Commits

Each task was committed atomically:

1. **Tarefa 1: Migrations das duas tabelas de faixas** - `2ebfcb60` (feat)
2. **Tarefa 2: Models auditáveis e relações** - `22f13f60` (feat)
   - Fix necessário para o acceptance criteria de auditoria - `9fa947e7` (fix)
3. **Tarefa 3: Seed idempotente + teste de schema** - `9d625657` (feat)

**Itens fora de escopo registrados:** `6be5032c` (docs: deferred-items.md)

## Files Created/Modified
- `database/migrations/2026_09_02_100001_create_servico_faixas_faturamento_table.php` - tabela de faixas por serviço
- `database/migrations/2026_09_02_100002_create_empresa_faixas_faturamento_table.php` - tabela de exceção por empresa (D-13)
- `database/migrations/2026_09_02_100003_seed_faixas_faturamento_iniciais.php` - seed das 3 tabelas medidas (D-02b)
- `app/Models/ServicoFaixaFaturamento.php` - model auditável, relação `servico()`, scope `ordenadas()`
- `app/Models/EmpresaFaixaFaturamento.php` - model auditável, relação `company()`, scope `ordenadas()`
- `app/Models/Servico.php` - adiciona `faixasFaturamento(): HasMany`
- `app/Models/Company.php` - adiciona `faixasFaturamento(): HasMany`
- `tests/Feature/Phase137/Phase137FaixasSchemaTest.php` - 8 testes de schema, seed e auditoria
- `.planning/phases/137-.../deferred-items.md` - registra 2 achados fora de escopo (ver Deviations)

## Decisions Made
- Índices únicos com nomes curtos e explícitos (`sff_servico_ordem_unq`, `eff_empresa_ordem_unq`) seguindo a armadilha documentada do MariaDB (erro 1059 acima de 64 caracteres)
- `valor_e_piso` como coluna própria em vez de convenção implícita (ex.: `limite_superior = null` sozinho não distingue "faixa aberta comum" de "faixa-piso")
- Teste re-invoca `up()` da migration de seed manualmente (`require database_path(...)` + `->up()`) para popular Brigada/Shopee-ads como fixture, seguindo o padrão já usado em `SeedNpsShopeeTest`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `LogOptions::defaults()` sem `logFillable()` não gravava NADA em `activity_log`**
- **Found during:** Tarefa 2, ao provar o acceptance criteria "criar e alterar uma linha grava em activity_log com log_name = 'faixa_faturamento'"
- **Issue:** O molde usado (`BonusFaixa::getActivitylogOptions()`, Fase 74) chama `->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName(...)` mas nunca `->logFillable()`/`->logOnly([...])`. Sem isso, `LogOptions::defaults()` do Spatie nasce com `logAttributes = []` — nenhum atributo é rastreado, o log fica vazio e é descartado por `dontSubmitEmptyLogs()`. Confirmado empiricamente: `ServicoFaixaFaturamento::create()` + `->update()` não gerava nenhuma linha nova em `activity_log` até a correção.
- **Fix:** Adicionado `->logFillable()` em `getActivitylogOptions()` dos dois models novos (`ServicoFaixaFaturamento`, `EmpresaFaixaFaturamento`).
- **Files modified:** `app/Models/ServicoFaixaFaturamento.php`, `app/Models/EmpresaFaixaFaturamento.php`
- **Verification:** Teste `criar_e_alterar_faixa_de_servico_grava_activity_log` (novo, em `Phase137FaixasSchemaTest`) reconsulta `activity_log` via `DB::table` e confirma eventos `created`/`updated` com `log_name = 'faixa_faturamento'`.
- **Committed in:** `9fa947e7`

---

**Total deviations:** 1 auto-fixed (1 bug — Rule 1)
**Impact on plan:** Correção necessária para o acceptance criteria de auditoria da Tarefa 2 funcionar de verdade. Sem impacto de escopo — só os 2 models novos deste plano foram tocados.

## Issues Encountered

**Achado colateral, NÃO corrigido (fora de escopo — registrado em `deferred-items.md`):** o mesmo bug de `LogOptions::defaults()` sem `logFillable()` existe em `App\Models\BonusFaixa` (Fase 74) — criar/editar uma `BonusFaixa` hoje também não grava nada em `activity_log`, apesar do docblock da classe afirmar o contrário. Não corrigido por estar fora dos `files_modified` deste plano e por ser código sensível (régua de bônus) que merece atenção própria.

**Achado colateral, NÃO corrigido (fora de escopo — registrado em `deferred-items.md`):** `AdminFechamentoControllerTest` tem 5/16 testes falhando por razões pré-existentes e não relacionadas a este plano (4 testam `updateFechamento()`, que desde a Fase 14 Plano 14-06 só faz `return back()`; 1 é sensível a data, expondo a janela móvel de 30 dias do D-06 já documentada no CONTEXT). Confirmado por inspeção de código que nenhum arquivo deste plano toca `AdminController.php` ou os campos lidos por esses testes. Este teste não faz parte do filtro de gate da fase.

**Árvore compartilhada:** outra sessão executava o plano 137-02 (snapshots de fechamento) em paralelo, na mesma branch `main`. Todos os commits deste plano usaram `git add -- <paths>` explícitos, nunca `git add -A`; `deferred-items.md` foi editado de forma puramente aditiva (confirmado por `git diff` antes do commit) para não sobrescrever o conteúdo já registrado pela outra sessão.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- `ServicoFaixaFaturamento`/`EmpresaFaixaFaturamento` prontos para o resolver de classificação de faturamento (plano 03): a lógica de "qual faixa se aplica" deliberadamente NÃO mora nos models, conforme instrução do plano.
- As 3 tabelas medidas estão semeadas para os serviços que existirem no ambiente (nome exato); em produção, os 3 serviços (Gestão id 6, Brigada id 10, Gestão de ADS Shopee id 9) já existem, então a seed migration populará as 22 linhas completas no primeiro deploy.
- Nenhum comportamento observável da tela `/financeiro` mudou neste plano — confirmado pelo gate rodando 138 testes / 787 asserções, 0 falhas.

---
*Phase: 137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab*
*Completed: 2026-09-03*

## Self-Check: PASSED

Todos os 6 arquivos-chave e os 5 commits (2ebfcb60, 22f13f60, 9fa947e7, 9d625657, 6be5032c) foram
confirmados presentes por reconsulta ao filesystem/git log.
