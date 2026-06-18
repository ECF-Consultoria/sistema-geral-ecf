---
phase: 37-onboarding-comercial-unificado-via-hubspot-line-items
plan: 02
subsystem: hubspot-integration
tags: [schema, hubspot, line-items, mapping, comercial, tdd]
requires:
  - servicos.setor (Plan 37-01)
  - tabela servicos (Phase 14)
provides:
  - tabela hubspot_line_item_mapping
  - App\Models\HubspotLineItemMapping
  - HubspotLineItemMapping::paraNome() (lookup case-insensitive)
  - seed canonico (MAP/Polo/Brigada/Gestao/Mentoria/Publicacao)
affects:
  - Plan 37-04 (webhook HubSpot consumira paraNome)
  - Plan 37-07 (UI admin /sistema/hubspot-line-items)
tech-stack:
  added:
    - novo model Eloquent (HubspotLineItemMapping)
    - nova tabela hubspot_line_item_mapping
  patterns:
    - migration idempotente via Schema::hasTable
    - seed idempotente via firstOrCreate
    - lookup case-insensitive via whereRaw LOWER comparison
    - eager loading defensivo (with('servico')) no helper estatico
key-files:
  created:
    - database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php
    - database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php
    - app/Models/HubspotLineItemMapping.php
    - tests/Feature/Phase37LineItemMappingTest.php
  modified: []
decisions:
  - "paraNome() retorna ?self (nullable) — webhook (Plan 37-04) registra empresa com flag 'servico_nao_reconhecido' quando lookup falha"
  - "firstOrCreate no seed (NAO updateOrCreate) — preserva ajustes manuais de ativo/observacoes feitos via UI admin pos-deploy"
  - "Mentoria falls back para Gestao quando ausente do catalogo (Phase 14 nao garantia Mentoria; Phase 37 Plan 37-01 confirmou presenca)"
  - "MySQL utf8mb4_unicode_ci colapsa POLO/Polo em 1 row no seed prod (collation case-insensitive) — comportamento desejado, paraNome() ja case-insensitive"
  - "Test setUp() trunca rows seedadas para cenario controlado — RefreshDatabase nao isola seed data por padrao"
  - "Index composto (ativo, line_item_name) alimenta lookup do webhook (Plan 37-04)"
metrics:
  duration: ~22min
  tasks_completed: 3
  files_changed: 4
  commits: 4
  tests_added: 9
  assertions: 23
  completed_date: 2026-06-18
---

# Phase 37 Plan 37-02: Tabela hubspot_line_item_mapping Summary

Tabela `hubspot_line_item_mapping` (line_item_name → servico_id) com seed canonico
para 6 familias HubSpot ECF + model `HubspotLineItemMapping` com lookup case-insensitive
via `paraNome()`, eager-loading defensivo e scope `ativo()`. Schema + 9 testes TDD verdes.

## One-liner

Catalogo de mapeamento HubSpot line_item.name → Servico canonico, com lookup case-insensitive
e seed idempotente — fonte de verdade para o webhook HubSpot (Plan 37-04) resolver nomes
livres do Comercial em servicos do catalogo, editavel via UI admin (Plan 37-07) sem deploy.

## What Was Built

### Schema (Task 1)

**`database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php`**

| Coluna | Tipo | Constraint |
|--------|------|-----------|
| id | bigint | PK auto-increment |
| line_item_name | string(255) | UNIQUE |
| servico_id | bigint | FK servicos.id ON DELETE CASCADE |
| ativo | boolean | default true |
| observacoes | text | nullable |
| created_at / updated_at | timestamp | |

- Index composto `hsli_ativo_nome_idx` em `(ativo, line_item_name)` para alimentar o
  lookup do webhook (`WHERE ativo=1 AND LOWER(line_item_name)=LOWER(?)`).
- Guard `Schema::hasTable` no `up()` (re-run nao recria).
- `down()` via `dropIfExists`.

### Model (Task 2)

**`app/Models/HubspotLineItemMapping.php`**

- `$table = 'hubspot_line_item_mapping'`, `$fillable = [line_item_name, servico_id, ativo, observacoes]`.
- Cast `ativo => boolean`.
- Relacao `servico(): BelongsTo` para `App\Models\Servico`.
- Scope `scopeAtivo($q) => where('ativo', true)`.
- Helper estatico `paraNome(string $nome): ?self`:
  - `trim()` no input antes do match.
  - `whereRaw('LOWER(line_item_name) = LOWER(?)', [$nome])` — case-insensitive em qualquer driver.
  - `with('servico')` eager-load — evita N+1 no consumidor Plan 37-04.
  - Filtra `ativo=true` via scope encadeado.
  - Retorna `null` quando: nao existe mapping para o nome OU existe mas esta inativo.

### Seed (Task 2)

**`database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php`**

8 pares canonicos seedados:

| line_item_name | servico canonico | Justificativa |
|----------------|------------------|---------------|
| MAP | Gestão | Mentoria Acelerada Premium = produto Gestão |
| MAP PREMIUM | Gestão | Variante do MAP |
| Polo | Polos | Match direto |
| POLO | Polos | Capitalizacao alternativa do HubSpot |
| Brigada | Gestão | Cliente legado sem servico proprio |
| Gestão | Gestão | Match direto |
| Mentoria | Mentoria (fallback Gestão) | Phase 14 nao tinha Mentoria; Phase 37 Plan 37-01 confirmou |
| Publicação | Publicação | Match direto |

- `DB::transaction` envelopa todos os inserts.
- Lookup de servicos por `whereIn(['Gestão', 'Polos', 'Publicação', 'Mentoria'])`.
- `firstOrCreate(['line_item_name' => $nome], [...])` — idempotente, preserva ajustes manuais via UI admin.
- Pula par sem Servico alvo (silencioso) — re-run preenche depois.
- `down()` apaga apenas os 8 nomes seedados — preserva mappings criados via UI.

### Tests (Task 3)

**`tests/Feature/Phase37LineItemMappingTest.php`** — 9 testes / 23 assertions / SQLite RefreshDatabase

| # | Teste | Verifica |
|---|-------|----------|
| 1 | tabela_existe_com_colunas_esperadas | `Schema::hasTable` + 7 colunas |
| 2 | unique_em_line_item_name_impede_duplicata | QueryException no 2o insert |
| 3 | fk_cascade_em_servico_id | Deletar Servico apaga mapping |
| 4 | scope_ativo_filtra_apenas_ativos | 3 rows (2 ativos) → ativo()->count() === 2 |
| 5 | paraNome_case_insensitive | 'mAp PrEmIuM' bate 'MAP PREMIUM' |
| 6 | paraNome_ignora_inativos | ativo=false → null |
| 7 | paraNome_retorna_null_quando_inexistente | Nome ausente → null |
| 8 | paraNome_retorna_servico_eager_loaded | `relationLoaded('servico')` true |
| 9 | seed_inicial_cria_mapeamentos_canonicos | 8 firstOrCreate inline + re-run no-op |

setUp() trunca `hubspot_line_item_mapping` e `servicos` antes de cada teste para
cenario controlado (RefreshDatabase re-roda as migrations de seed automaticamente).

## How It Was Built

### TDD Sequence

1. **RED** (`a3085be`): Suite Phase37LineItemMappingTest com 9 testes falhando (Class not found + tabela ausente).
2. **GREEN Task 1** (`6b14a89`): Migration `create_hubspot_line_item_mapping_table` — tabela + FK + UNIQUE + index.
3. **GREEN Task 2** (`5543ab5`): Model `HubspotLineItemMapping` + Migration de seed canonico.
4. **GREEN Task 3** (`0557342`): Refinamento do `setUp()` para cenario controlado isolado das seeds.

### Commits

| Hash | Type | Message |
|------|------|---------|
| `a3085be` | test | add failing suite Phase37LineItemMappingTest (RED) |
| `6b14a89` | feat | cria tabela hubspot_line_item_mapping (Task 1 GREEN) |
| `5543ab5` | feat | model HubspotLineItemMapping + seed canonico (Task 2 GREEN) |
| `0557342` | test | consolida suite com setUp truncate (Task 3 GREEN) |

## Decisões

### D-01 — `paraNome()` retorna nullable (`?self`)

Quando line_item.name nao mapeia, retorna `null`. O webhook (Plan 37-04) interpreta como
"servico nao reconhecido" e registra a empresa com flag para aparecer na listagem comercial
(Plan 37-05) para o time cadastrar o mapeamento via UI (Plan 37-07). Alternativa rejeitada:
lancar excecao — quebraria fluxo de webhook por evento de dados (admin esquece de cadastrar
um line item novo nao pode parar o pipeline).

### D-02 — `firstOrCreate` no seed (NAO `updateOrCreate`)

Preserva ajustes manuais (`ativo=false`, `observacoes` editadas) feitos via UI admin entre
deploys. `updateOrCreate` sobrescreveria essas mudancas a cada migrate. Mesmo padrao da
Phase 14 Plan 14-02 (D-01 do plan original).

### D-03 — Mentoria com fallback para Gestão

Phase 14 nao garantia 'Mentoria' no catalogo. Plan 37-01 (deste mesmo phase) confirmou
presenca em dev. Mantive fallback `$mentoria = $servicos['Mentoria'] ?? $gestao` por
defesa em profundidade — se em algum ambiente prod o seed Phase 14 nao tiver corrido
ainda, 'Mentoria' line item nao quebra o seed.

### D-04 — Index composto `(ativo, line_item_name)`

O lookup do webhook (`paraNome`) filtra `ativo=1` primeiro e depois compara o nome. Index
em `(ativo, line_item_name)` permite ao otimizador eliminar rows inativos antes do LIKE
LOWER. UNIQUE em `line_item_name` ja cria index implicito separado — coexistem sem
conflito.

### D-05 — MySQL `utf8mb4_unicode_ci` colapsa POLO/Polo no seed prod

A collation default da app (MySQL `utf8mb4_unicode_ci`, case-insensitive) trata 'POLO' e
'Polo' como equivalentes no `firstOrCreate(['line_item_name' => ...])`. Em prod, o seed
cria apenas 1 row para a familia Polo. Em SQLite (testes), case-sensitive, ambos viram
rows separados (8 mappings). Comportamento desejado: `paraNome()` ja faz lookup
case-insensitive — 1 row vs 2 rows para a mesma familia nao afeta resolucao.

### D-06 — `setUp()` apaga rows seedadas (tabela hubspot_line_item_mapping + servicos)

RefreshDatabase re-aplica todas as migrations entre testes — inclusive os 3 seeds
(Phase 14 servicos catalog, Phase 37 Plan 37-01 setor update, Phase 37 Plan 37-02 line
item mapping). Para testes de contagem precisa (assertSame(2, count)) precisamos
cenario controlado. Alternativa rejeitada: usar nomes unicos por teste — fragil, polui
namespace dos line_item_names.

## Deviations from Plan

Nenhuma. Plan executado exatamente como escrito. setUp() com truncate foi adicionado em
Task 3 (cobertura do D-08 do plan original) — esperado pelo plan, nao deviation.

## Threat Surface Scan

Threats T-37-03 (Tampering) e T-37-04 (Information Disclosure) cobertos conforme threat
model do plan:

- T-37-03 mitigado: UNIQUE em line_item_name + FK cascadeOnDelete em servico_id + acesso
  restrito a admin (Plan 37-07).
- T-37-04 aceito: line_item_name + observacoes sao dados internos da operacao ECF, sem
  PII, visiveis apenas para role admin.

Sem novas superficies de ataque introduzidas alem do mapeado no plan.

## Verification

- [x] `php artisan migrate` aplica ambas migrations em dev (MySQL) — DONE
- [x] Tabela `hubspot_line_item_mapping` existe — verificado via tinker
- [x] Seed cria 7 mappings em dev (Mentoria=Servico Mentoria; POLO/Polo colapsado por collation MySQL)
- [x] `HubspotLineItemMapping::paraNome('map premium')->servico->nome === 'Gestão'` — verificado via tinker
- [x] 9/9 testes Phase37LineItemMappingTest verdes (23 assertions)
- [x] 26/26 testes da Phase 37 verdes (zero regressao)

## Known Stubs

Nenhum stub introduzido. UI admin `/sistema/hubspot-line-items` (Plan 37-07) ainda nao
existe — esperado, escopo do plan futuro. O model ja esta pronto para CRUD admin.

## Gotchas / Notas Operacionais

- **NAO fazer deploy do Plan 37-02 sozinho.** Tabela + seed sao consumidos pelo webhook
  no Plan 37-04. Agrupar deploy com os demais plans da Phase 37 (37-03 a 37-07).
- O comando `php artisan migrate` em prod aplicara as 2 migrations sem perda de dados —
  ambas idempotentes via `Schema::hasTable` + `firstOrCreate`.
- Reverter via `php artisan migrate:rollback --step=2` apaga rows seedadas e dropa a
  tabela. Mappings criados via UI admin (Plan 37-07) seriam perdidos se a tabela for
  dropada — o `down()` do seed apaga apenas os 8 nomes seedados originalmente.
- Em prod (MySQL `utf8mb4_unicode_ci`) a tabela tera 7 rows seedadas (POLO/Polo colapsam).
  Em testes (SQLite case-sensitive) tera 8. Diferenca documentada e nao afeta runtime.

## Next Steps

- Plan 37-03 (proximo da Wave 1, conforme dependencias do plan): aguardando despacho.
- Plan 37-04 (Wave 2): webhook HubSpot consumira `HubspotLineItemMapping::paraNome()`
  para cada line_item retornado por `/crm/v3/objects/deals/{dealId}/associations/line_items`.
- Plan 37-07 (Wave 3): UI admin `/sistema/hubspot-line-items` permitira CRUD direto da
  tabela sem deploy.

## Self-Check: PASSED

Arquivos confirmados via `[ -f ... ]`:

- FOUND: `database/migrations/2026_06_18_100003_create_hubspot_line_item_mapping_table.php`
- FOUND: `database/migrations/2026_06_18_100004_seed_hubspot_line_item_mapping.php`
- FOUND: `app/Models/HubspotLineItemMapping.php`
- FOUND: `tests/Feature/Phase37LineItemMappingTest.php`

Commits confirmados via `git log --oneline`:

- FOUND: `a3085be` (RED test)
- FOUND: `6b14a89` (Task 1 GREEN migration)
- FOUND: `5543ab5` (Task 2 GREEN model + seed)
- FOUND: `0557342` (Task 3 GREEN test consolidation)

## TDD Gate Compliance

- RED gate: `a3085be` test(37-02): add failing suite — CONFIRMADO
- GREEN gate: `6b14a89`, `5543ab5`, `0557342` — 3 commits feat/test apos RED, todos com testes verdes — CONFIRMADO
- REFACTOR gate: nao aplicavel (codigo final ja limpo, sem fase de refator distinta)
