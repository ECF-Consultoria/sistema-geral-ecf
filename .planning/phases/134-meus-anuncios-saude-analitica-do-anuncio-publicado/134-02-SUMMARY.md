---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 02
subsystem: database
tags: [laravel, migrations, eloquent, mariadb, mercadolivre, phase134]

# Dependency graph
requires:
  - phase: 134-01
    provides: "Veredicto D-21 (DISPONIVEL) que definiu as 5 colunas de saúde do ML acrescentadas ao schema (health_ml x2, performance_score, performance_level, performance_acoes)"
provides:
  - "Tabela ml_acervo_itens — linha corrente por anúncio, upsert (company_id, ml_item_id), índices nomeados à mão (mai_*)"
  - "Tabela ml_acervo_metricas_diarias — série diária imutável (ml_item_id, data), índices mamd_*"
  - "Model MlAcervoItem — constantes de origem/motivo/severidade + helpers puros naoAvaliadoBuyBox/visitasNaoAvaliadas/saudeMlNaoSeAplica/saudeMlNaoAvaliada"
  - "Model MlAcervoMetricaDiaria — série imutável (UPDATED_AT=null)"
  - "Teste de contrato tests/Unit/Phase134/SchemaAcervoTest.php, incluindo o gate de nome de índice do MariaDB"
affects: [134-03, 134-04, 134-05, 134-06, 134-07, 134-08, 134-09, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "rascunho_id sem foreignId/constrained — vínculo lógico, evita erro 1830 e ordem de drop entre tabelas"
    - "Índice nomeado à mão em toda tabela com nome longo (prefixo curto: mai_/mamd_) — evita erro 1059 do MariaDB, que o SQLite dos testes não reproduz"
    - "Teste de contrato de schema lê a FONTE da migration (regex sobre ->index(/->unique() em vez de consultar o banco, para pegar em SQLite um erro que só aconteceria em MariaDB"
    - "Relação Eloquent NÃO declarada quando o HasMany mentiria sobre o escopo por empresa (metricas() em MlAcervoItem)"

key-files:
  created:
    - database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php
    - database/migrations/2026_08_11_090100_create_ml_acervo_metricas_diarias_table.php
    - app/Models/MlAcervoItem.php
    - app/Models/MlAcervoMetricaDiaria.php
    - tests/Unit/Phase134/SchemaAcervoTest.php
  modified: []

key-decisions:
  - "Nome da tabela de série diária é ml_acervo_metricas_diarias, não ml_acervo_item_metricas_diarias como o 134-PATTERNS.md sugeriu — divergência deliberada do plano, documentada no comentário do arquivo, para dar folga extra contra o teto de 64 caracteres do MariaDB."
  - "Três escalas de saúde (health_ml 0.00-1.00, performance_score 0-100, nota_ecf 0-86) convivem em ml_acervo_itens sem nenhuma conversão entre elas — documentado em comentário de migration e de model, nunca preencher uma a partir da outra."
  - "rascunho_id é unsignedBigInteger sem FK — vínculo lógico via origem, não relacional."
  - "Nenhum dos dois models usa trait de auditoria (Spatie) — snapshot alimentado por job, não ação humana a auditar."
  - "Relação metricas() em MlAcervoItem deliberadamente NÃO declarada — HasMany simples só casaria por ml_item_id, que sozinho não é único entre empresas; quem precisar da série consulta explicitamente pelos dois campos."

requirements-completed: [D-04, D-07, D-08, D-12, D-17, D-18]

# Metrics
duration: ~15min
completed: 2026-08-10
---

# Phase 134 Plan 02: Schema do acervo — duas tabelas + models Summary

**Duas migrations com 6 índices nomeados à mão (prefixos `mai_`/`mamd_`), dois models Eloquent com as 11 constantes de domínio e 4 helpers puros de "não avaliado", e teste de contrato de 4 casos que trava o gate de índice do MariaDB direto na fonte da migration.**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-08-10T16:33Z (aprox.)
- **Completed:** 2026-08-10T16:39Z
- **Tasks:** 3/3
- **Files modified:** 5 (todos criados)

## Accomplishments

- `ml_acervo_itens` criada com as 38 colunas do contrato do plano (incluindo as 4 colunas de saúde do ML que entraram após o veredicto DISPONIVEL do D-21: `health_ml`, `performance_score`, `performance_level`, `performance_acoes`), 4 índices nomeados à mão, sem `enum`, sem FK que exija ordem de drop.
- `ml_acervo_metricas_diarias` criada com 8 colunas (incluindo `health_ml`), imutável (`created_at` sem `updated_at`), 2 índices nomeados à mão. Nome divergente do sugerido em `134-PATTERNS.md` — encurtado deliberadamente, documentado no comentário do arquivo.
- `MlAcervoItem` com as 11 constantes de domínio (`ORIGEM_*`, `MOTIVO_*`, `SEVERIDADE_*`) e 4 helpers puros: `naoAvaliadoBuyBox()`, `visitasNaoAvaliadas()`, `saudeMlNaoSeAplica()`, `saudeMlNaoAvaliada()` — os dois últimos materializam o achado da sondagem D-21 (ML não pontua item de catálogo nem encerrado).
- `MlAcervoMetricaDiaria` com `UPDATED_AT = null` (mesmo padrão já usado em `CompanyManagerHistory`), série imutável.
- `tests/Unit/Phase134/SchemaAcervoTest.php` com 4 testes: colunas contratadas nas duas tabelas, gate de nome de índice lendo a FONTE da migration (não o banco), e unicidade do upsert `(company_id, ml_item_id)` coexistindo entre empresas distintas.
- Migrations validadas com `migrate` + `migrate:rollback --step=2` contra o **MySQL/MariaDB local real** (XAMPP), não só contra o SQLite dos testes — os 6 índices `mai_*`/`mamd_*` conferidos via `SHOW INDEX`.
- Gate de nome de índice provado manualmente: removido o segundo argumento de `mai_company_item_unq`, o teste 3 caiu (`assertCount` 3≠4), revertido, suíte volta a verde.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migrations das duas tabelas, com índices nomeados à mão** - `826a3328` (feat)
2. **Task 2: Models MlAcervoItem e MlAcervoMetricaDiaria** - `abad8112` (feat)
3. **Task 3: Teste de contrato do schema, incluindo o gate de nome de índice** - `42e97608` (test)

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php` - linha corrente por anúncio, 38 colunas, 4 índices `mai_*`
- `database/migrations/2026_08_11_090100_create_ml_acervo_metricas_diarias_table.php` - série diária imutável, 8 colunas, 2 índices `mamd_*`
- `app/Models/MlAcervoItem.php` - model com 11 constantes de domínio, casts completos, 4 helpers puros de "não avaliado"
- `app/Models/MlAcervoMetricaDiaria.php` - model da série, `UPDATED_AT = null`
- `tests/Unit/Phase134/SchemaAcervoTest.php` - 4 testes de contrato de schema

## Decisions Made

- **Nome da série encurtado para `ml_acervo_metricas_diarias`** (não `ml_acervo_item_metricas_diarias` como o `134-PATTERNS.md` sugeriu) — o próprio `134-02-PLAN.md` já mandava essa divergência; documentada em comentário no topo do arquivo.
- **`rascunho_id` sem FK** — vínculo lógico via `origem`, evita o erro 1830 (`nullOnDelete` exige `nullable()`) e dependência de ordem de drop entre tabelas.
- **Relação `metricas()` NÃO declarada em `MlAcervoItem`** — decisão explícita do próprio plano: um `HasMany` simples só casaria por `ml_item_id`, que sozinho não é único entre empresas (só `(company_id, ml_item_id)` é), e uma relação que "esquece" o `company_id` mentiria sobre o escopo protegido por T-134-01. Quem precisar da série consulta `MlAcervoMetricaDiaria` explicitamente pelos dois campos.
- **Nenhum dos dois models usa trait de auditoria do Spatie** — tabelas de snapshot alimentadas por job; registrar cada upsert no `activity_log` inflaria a auditoria em centenas de milhares de linhas por dia sem ganho de rastreabilidade.
- **Comentário de aviso sobre as três escalas de saúde replicado na migration e no model** (`health_ml` 0.00-1.00, `performance_score` 0-100, `nota_ecf` 0-86) — nunca converter uma na outra, é o modo de falha já vivido com `nps_medio` ≠ `pontos_componentes.nps`.

## Deviations from Plan

None - plano executado exatamente como escrito. As colunas de saúde do ML (`health_ml`, `performance_score`, `performance_level`, `performance_acoes`) já estavam no `134-02-PLAN.md` emendado após o veredicto do D-21 — não foram um desvio desta execução, apenas seguidas conforme escrito.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano.

## Next Phase Readiness

- Schema pronto para o plano 134-03 (`AnuncioSaudeService`, port PHP da nota ECF) consumir `nota_ecf`/`nota_sinais`/`motivos`/`severidade`.
- Colunas de saúde do ML (`health_ml`, `performance_score`, `performance_level`, `performance_acoes`) prontas para o service de coleta (planos 134-04/05) gravar — os helpers `saudeMlNaoSeAplica()`/`saudeMlNaoAvaliada()` já existem para o consumidor da tela distinguir "não se aplica" de "ainda não coletamos" sem reimplementar a regra do D-21.
- `rascunho_id` e o par `publicacao_vendas_qty`/`publicacao_desconsiderado` prontos para o join do D-04 (selo de origem) no service de sync.
- Nenhum bloqueio conhecido para os próximos planos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php
- FOUND: database/migrations/2026_08_11_090100_create_ml_acervo_metricas_diarias_table.php
- FOUND: app/Models/MlAcervoItem.php
- FOUND: app/Models/MlAcervoMetricaDiaria.php
- FOUND: tests/Unit/Phase134/SchemaAcervoTest.php
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-02-SUMMARY.md
- FOUND commit: 826a3328
- FOUND commit: abad8112
- FOUND commit: 42e97608
