---
phase: 74-m-dulo-desempenho-simplifica-o-para-4-par-metros-bonifica-o
plan: 02
subsystem: desempenho
tags: [migration, schema, model, factory, bonus, seed]
requires: []
provides:
  - Tabela `bonus_faixas` (9 colunas + unique slug + índice `(ativo, ordem)`)
  - 4 rows seed canônicas (sem_bonus / basico / intermediario / maximo)
  - Model `App\Models\BonusFaixa` com LogsActivity + `classificar(float)`
  - Factory `BonusFaixaFactory` com estados nomeados replicando D-16
affects:
  - Plan 74-03 (DesempenhoScoreService.classificarFaixa reusa BonusFaixa::classificar)
  - Plan 74-05 (DesempenhoConfigController — CRUD REST)
  - Plan 74-07 (Desempenho/Configuracao.jsx — UI)
  - Plan 74-08 (Manual/Artigos/DesempenhoBonificacao.jsx — artigo dinâmico)
  - Plans 74-09/74-10 (testes Feature que exigem factory)
tech-stack:
  added: []
  patterns:
    - LogsActivity com log name dedicado por Model (padrão NpsTemplate)
    - Seed idempotente via migration transacional com DB::table + updateOrInsert
    - Factory com estados nomeados replicando fixtures canônicas de negócio
key-files:
  created:
    - database/migrations/2026_07_09_140002_create_bonus_faixas_table.php
    - database/migrations/2026_07_09_140003_seed_bonus_faixas_iniciais.php
    - app/Models/BonusFaixa.php
    - database/factories/BonusFaixaFactory.php
  modified: []
decisions:
  - D-14 · Schema completo `bonus_faixas` com 9 colunas + unique(slug) + index(ativo, ordem)
  - D-15 · Model `BonusFaixa` com LogsActivity + casts decimal:2 + scopes ativas/ordenadas
  - D-16 · Seed inicial das 4 faixas canônicas via migration transacional idempotente
metrics:
  duration: 15min
  completed: 2026-07-09
  tasks: 3
  files: 4
  commits: [1c30bd5, dea7de3, 01b16f3]
---

# Phase 74 Plan 02: Fundação de dados e modelo da régua de bônus

Cria a tabela `bonus_faixas` como fonte editável da régua de bonificação, semeia as 4 faixas canônicas definidas pela diretoria/gestão da ECF em 2026-07-09, expõe o Model `BonusFaixa` com LogsActivity + método `classificar()` reutilizado pelo Service (Plan 74-03), e adiciona Factory para os testes das Plans 74-09/74-10.

## O que foi feito

### Task 1 — Migration `create bonus_faixas` (commit `1c30bd5`)

Criado `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php`. Guard `Schema::hasTable('bonus_faixas')` no `up()` garante idempotência. Schema alinhado ao D-14:

- `id` bigInteger PK
- `slug` varchar(50) UNIQUE
- `nome` varchar(100)
- `descricao` TEXT NULL
- `nota_min` DECIMAL(3,2)
- `nota_max` DECIMAL(3,2)
- `ordem` unsignedSmallInteger default 0
- `ativo` boolean default true
- `timestamps`
- Índice composto `(ativo, ordem)` como `bonus_faixas_ativo_ordem_idx`

`down()` faz `Schema::dropIfExists`.

**Validação executada:** `PRAGMA table_info(bonus_faixas)` em SQLite tmp mostra as 10 colunas esperadas (id, slug, nome, descricao, nota_min, nota_max, ordem, ativo, created_at, updated_at) com tipos corretos.

### Task 2 — Migration `seed 4 faixas` (commit `dea7de3`)

Criado `database/migrations/2026_07_09_140003_seed_bonus_faixas_iniciais.php`. Usa `DB::transaction` + `DB::table('bonus_faixas')->updateOrInsert(['slug' => ...], [...])` para garantir idempotência sem depender de autoload do Model.

Faixas (D-16, valores em português com acentuação preservada):

| slug            | nome           | nota_min | nota_max | ordem | ativo |
|-----------------|----------------|----------|----------|-------|-------|
| sem_bonus       | Sem bônus      | 0.00     | 3.99     | 1     | 1     |
| basico          | Básico         | 4.00     | 4.49     | 2     | 1     |
| intermediario   | Intermediário  | 4.50     | 4.99     | 3     | 1     |
| maximo          | Máximo         | 5.00     | 5.00     | 4     | 1     |

Descrições em pt-BR citam DESEMP-08 (regra de promoção por 2 meses consecutivos). `down()` remove APENAS os slugs canônicos — preserva faixas customizadas que o admin possa ter adicionado depois.

**Validação executada** (SQLite tmp):
- `DB::table('bonus_faixas')->count()` → `4`
- `orderBy('ordem')->pluck('slug')->join(',')` → `sem_bonus,basico,intermediario,maximo`
- Sequência `migrate:rollback --step=1` + `migrate` reaplica sem duplicar rows.

### Task 3 — Model + Factory (commit `01b16f3`)

**`app/Models/BonusFaixa.php`:**
- `use HasFactory, LogsActivity;`
- `$table = 'bonus_faixas'`
- `$fillable = ['slug','nome','descricao','nota_min','nota_max','ordem','ativo']`
- `$casts = ['nota_min'=>'decimal:2','nota_max'=>'decimal:2','ordem'=>'int','ativo'=>'bool']`
- `getActivitylogOptions()` retorna `LogOptions::defaults()->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('bonus_faixa')->setDescriptionForEvent(...)` — padrão pt-BR seguindo `NpsTemplate`.
- Scopes `ativas()` (`where('ativo', true)`) e `ordenadas()` (`orderBy('ordem')`).
- Método estático `classificar(float $nota): ?self` — query com `ativas()->ordenadas()->where('nota_min','<=',$nota)->where('nota_max','>=',$nota)->first()`. Retorna `null` quando nenhuma faixa cobre.
- Docblock cita D-14/D-15/D-16 + explica que a promoção DESEMP-08 fica no Service.

**`database/factories/BonusFaixaFactory.php`:**
- `definition()` default: slug único faker, nome 2 palavras, nota_min=0, nota_max=5, ordem aleatória 1-10, ativo=true.
- Estados nomeados: `semBonus()`, `basico()`, `intermediario()`, `maximo()` replicando D-16.
- Estado `inativa()` seta `ativo => false`.

**Validação executada** (tinker):
- `BonusFaixa::classificar(4.35)` → `basico`
- `BonusFaixa::classificar(3.35)` → `sem_bonus`
- `BonusFaixa::classificar(5.00)` → `maximo`
- `BonusFaixa::classificar(4.99)` → `intermediario`
- `BonusFaixa::classificar(-1.0)` → `null`
- `BonusFaixa::classificar(4.00)` → `basico` (fronteira inclusiva confirmada)
- `BonusFaixa::factory()->intermediario()->make()` gera row com `slug=intermediario`, `nota_min=4.50`, `nota_max=4.99`.
- `BonusFaixa::factory()->inativa()->make()` gera row com `ativo=false`.
- `in_array('Spatie\Activitylog\Traits\LogsActivity', class_uses(BonusFaixa::class))` → `true`.

## Decisões implementadas

- **D-14** · Schema completo com 9 colunas + unique(slug) + índice `(ativo, ordem)`.
- **D-15** · Model com LogsActivity + `getActivitylogOptions()` pt-BR + fillable/cast completos.
- **D-16** · Seed idempotente das 4 faixas canônicas via `updateOrInsert`.

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito.

Observação operacional (não é desvio): assim como no Plan 74-01, a validação usou SQLite em `scratchpad/74-test.sqlite` já que MariaDB local está corrompido (`MEMORY.md → project_mariadb_local_corrompido.md`).

## Success Criteria

- [x] Tabela `bonus_faixas` existe com 9 colunas + unique(slug) + índice `(ativo, ordem)`.
- [x] 4 rows seed persistidas conforme D-16 (slug, nota_min, nota_max, ordem, ativo).
- [x] Model `BonusFaixa` com LogsActivity + método estático `classificar(float)`.
- [x] Factory permite criação em testes com estados nomeados (`semBonus`, `basico`, `intermediario`, `maximo`, `inativa`).
- [x] Rerun das migrations é idempotente (validado via rollback+reapply).
- [x] Chamadas de `classificar()` retornam slugs exatos para as bordas críticas (4.35, 3.35, 5.00, 4.99, 4.00, -1.0).

## Links

- SPEC: `.planning/phases/74-.../74-SPEC.md` DESEMP-07
- CONTEXT decisões: `.planning/phases/74-.../74-CONTEXT.md` §D-14, D-15, D-16
- Plan que consome `classificar()`: `.planning/phases/74-.../74-03-PLAN.md` (Wave 2)

## Self-Check: PASSED

- FOUND: `database/migrations/2026_07_09_140002_create_bonus_faixas_table.php`
- FOUND: `database/migrations/2026_07_09_140003_seed_bonus_faixas_iniciais.php`
- FOUND: `app/Models/BonusFaixa.php`
- FOUND: `database/factories/BonusFaixaFactory.php`
- FOUND commit `1c30bd5` (Task 1 — create table)
- FOUND commit `dea7de3` (Task 2 — seed)
- FOUND commit `01b16f3` (Task 3 — Model + Factory)
