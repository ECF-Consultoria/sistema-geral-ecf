---
phase: 41-onboarding-ml-por-empresa
plan: 01
subsystem: sugadores-ml-migration
tags: [schema, migration, eloquent, ml, shadow-mode, foundation]
dependency_graph:
  requires:
    - Phase 40 fechada (sugador_provider_runs/_items, ShadowRunService, ProviderComparisonService, comando sugadores:shadow-ml)
    - app/Models/Company.php (acessor cust_id, relacao mlToken)
  provides:
    - "Tabela ml_advertisers — cache do discoverAdvertiser do MercadoLivreAdsService"
    - "Tabela sugador_ml_company_config — fonte de --company=all do sugadores:shadow-ml a partir do Plan 41-03"
    - "Model App\\Models\\MlAdvertiser (casts + relacao company)"
    - "Model App\\Models\\SugadorMlCompanyConfig (casts + relacao company)"
    - "Company.mlAdvertiser() hasOne — relacao consumivel via eager loading"
    - "Company.sugadorMlConfig() hasOne — relacao consumivel via eager loading"
  affects:
    - app/Models/Company.php (+19 linhas, 0 remocoes — adicoes puras de 2 relacoes hasOne)
tech_stack:
  added: []
  patterns:
    - "Migration idempotente (Schema::hasTable guard) — herdado do Plan 40-01"
    - "FK cascadeOnDelete em ambas tabelas auxiliares"
    - "PHPUnit 11 #[Test] attribute (sem doccomment /** @test */)"
    - "SQLite em-memory + PRAGMA foreign_keys=ON no setUp"
key_files:
  created:
    - database/migrations/2026_06_25_410101_create_ml_advertisers_table.php
    - database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php
    - app/Models/MlAdvertiser.php
    - app/Models/SugadorMlCompanyConfig.php
    - tests/Feature/Phase41/Phase41SchemaTest.php
  modified:
    - app/Models/Company.php (+19/-0 — 2 metodos hasOne adicionados apos mlToken)
decisions:
  - "$table explicito em SugadorMlCompanyConfig (singular 'sugador_ml_company_config') pra Eloquent nao pluralizar pra 'sugador_ml_company_configs'"
  - "Models SEM LogsActivity — pattern Plan 40-01: tabelas auxiliares delegam audit pra Companies/Sugadores"
  - "Sem alteracao em SugadorAnalysisService, MercadoLivreAdsService, factory, providers, AdmanService — schema isolado"
  - "Acceptance criteria do PLAN exigia 8+ tests #[Test]; entreguei 9 (acrescentei Test 8 e 9 separados pra unique cada tabela individualmente — facilita debug se uma quebrar)"
  - "Cast discovered_at como immutable_datetime (Carbon\\CarbonImmutable) pra evitar mutacao acidental do timestamp de descoberta"
metrics:
  duration: "~15min"
  completed_date: "2026-06-25"
  tasks_total: 2
  tasks_completed: 2
  tests_added: 9
  tests_passing: 9
  files_created: 5
  files_modified: 1
  lines_added: 223
  lines_removed: 0
requirements_closed: [REQ-41-01, REQ-41-02]
---

# Phase 41 Plan 41-01: Fundacao de Dados ML Onboarding — Summary

**One-liner:** 2 tabelas auxiliares (`ml_advertisers` cache discovery + `sugador_ml_company_config` flags por empresa) + 2 Models Eloquent + 2 relacoes hasOne em Company — destravam refactor do MercadoLivreAdsService (Plan 41-02), command shadow-ml priorizar DB (Plan 41-03) e UI admin (Plan 41-05).

## O que foi entregue

### Migrations (2 arquivos novos)

**`database/migrations/2026_06_25_410101_create_ml_advertisers_table.php`**

Schema EXATO do `41-CONTEXT.md §1`:

| Coluna | Tipo | Nota |
|--------|------|------|
| id | bigInt PK auto | — |
| company_id | bigInt FK unique cascade | 1 advertiser por empresa |
| advertiser_id | string(64) idx | ID retornado pelo `/advertising/advertisers` |
| seller_id | string(64) nullable | seller ML quando ja conhecido |
| site_id | string(8) default 'MLB' | Mercado Livre Brasil |
| raw_data | json nullable | payload completo da chamada ML |
| discovered_at | timestampTz | momento da descoberta (cache logico 7d gerenciado pelo Plan 41-02) |
| created_at / updated_at | timestamps | — |

Guard `if (!Schema::hasTable('ml_advertisers'))` no `up()`; `dropIfExists` no `down()`.

**`database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php`**

Schema EXATO do `41-CONTEXT.md §2`:

| Coluna | Tipo | Nota |
|--------|------|------|
| id | bigInt PK auto | — |
| company_id | bigInt FK unique cascade | 1 config por empresa |
| shadow_enabled | bool default false idx | controla scheduler 13h BRT (Plan 41-03 vai ler) |
| primary_enabled | bool default false idx | so respeitado em Phase 42 (cut-over real) |
| shadow_started_at | date nullable | timestamp de quando entrou em shadow |
| primary_promoted_at | date nullable | timestamp de promocao pra primary (Phase 42) |
| notes | text nullable | observacoes do operador (UI Plan 41-05) |
| created_at / updated_at | timestamps | — |

Guard `if (!Schema::hasTable('sugador_ml_company_config'))`; `dropIfExists` no down.

**Nome no singular intencional** — Model declara `$table` explicito pra Eloquent nao pluralizar pra `sugador_ml_company_configs`.

### Models (2 arquivos novos)

**`app/Models/MlAdvertiser.php`**

```php
$fillable = ['company_id', 'advertiser_id', 'seller_id', 'site_id', 'raw_data', 'discovered_at'];
$casts = [
    'raw_data'      => 'array',
    'discovered_at' => 'immutable_datetime',
];

public function company(): BelongsTo {
    return $this->belongsTo(Company::class);
}
```

Sem `LogsActivity` (pattern Plan 40-01: tabela auxiliar — audit fica em Companies).

**`app/Models/SugadorMlCompanyConfig.php`**

```php
protected $table = 'sugador_ml_company_config'; // singular explicito
$fillable = ['company_id', 'shadow_enabled', 'primary_enabled', 'shadow_started_at', 'primary_promoted_at', 'notes'];
$casts = [
    'shadow_enabled'      => 'boolean',
    'primary_enabled'     => 'boolean',
    'shadow_started_at'   => 'date',
    'primary_promoted_at' => 'date',
];

public function company(): BelongsTo {
    return $this->belongsTo(Company::class);
}
```

### Edit Company.php (+19/-0)

Adicionadas 2 relacoes hasOne IMEDIATAMENTE APOS `mlToken()`:

```php
public function mlAdvertiser() {
    return $this->hasOne(MlAdvertiser::class);
}

public function sugadorMlConfig() {
    return $this->hasOne(SugadorMlCompanyConfig::class);
}
```

ZERO modificacao em qualquer outro metodo, fillable, casts, accessor ou relacao existente. Diff oficial confirma:

```text
19  0  app/Models/Company.php
```

### Suite de Tests (1 arquivo novo)

**`tests/Feature/Phase41/Phase41SchemaTest.php`** — 9 tests com atributo `#[\PHPUnit\Framework\Attributes\Test]` (sem doccomment legacy):

1. `tabela_ml_advertisers_tem_colunas_esperadas` — Schema::hasColumns das 9 colunas
2. `tabela_sugador_ml_company_config_tem_colunas_esperadas` — Schema::hasColumns das 9 colunas
3. `fk_company_id_cascade_em_ml_advertisers` — deleta Company, MlAdvertiser apagado
4. `fk_company_id_cascade_em_sugador_ml_company_config` — idem
5. `model_ml_advertiser_casts_e_relacoes` — raw_data array round-trip + discovered_at CarbonImmutable + company()
6. `model_sugador_ml_company_config_casts_e_relacoes` — booleans + dates + notes string + company()
7. `company_relacoes_novas` — Company::mlAdvertiser e Company::sugadorMlConfig retornam instances
8. `company_id_unique_em_ml_advertisers` — QueryException ao tentar 2 advertisers pra mesma company
9. `company_id_unique_em_sugador_ml_company_config` — QueryException pra duplicacao

Setup ativa `PRAGMA foreign_keys = ON` em SQLite (sem isso o cascade nao seria testado — pattern do Plan 40-01).

## Verificacao

### Tests

```text
Phase41SchemaTest:  9/9 PASS (25 assertions, 1.16s)
Sugador suite:     96/96 PASS (522 assertions, 40.87s) — ZERO regressao
```

### Greps de acceptance criteria

```text
grep Schema::create('ml_advertisers')                    =  1
grep Schema::create('sugador_ml_company_config')         =  1
grep cascadeOnDelete em ambas migrations                 =  2 (1 por migration)
grep mlAdvertiser|sugadorMlConfig em Company.php         =  2
grep hasOne(MlAdvertiser|SugadorMlCompanyConfig)         =  2
git diff app/Models/Company.php                          = 19+/0-
```

### Schema confirmado via SQLite em-memory (RefreshDatabase)

Migrations executadas no `setUp` do test rodam idempotentemente; testes 1+2 validam todas as 9 colunas por tabela; testes 3+4 validam cascade real (com `PRAGMA foreign_keys = ON`).

## Deviations from Plan

**Nenhuma.** Plan executado exatamente como escrito.

Unico ajuste minor: o PLAN sugere "8+ testes" — entreguei 9 (separei o Test 8 unique em dois testes individuais, um pra ml_advertisers e outro pra sugador_ml_company_config). Acceptance criteria continua atendido (>=8) e ganho debug isolado se uma das constraints quebrar.

## Notas operacionais

- **Sem deploy nesta plan.** Schema novo + Models novos so passam a ter efeito pratico quando o Plan 41-02 (refactor MercadoLivreAdsService) for entregue + a UI admin do Plan 41-05 escrever em `sugador_ml_company_config`.
- **Migrations idempotentes** garantem re-run safe quando o orquestrador for fazer merge desta plan em main + rodar `php artisan migrate --force` no VPS (autorizado por feedback permanente do usuario).
- **MariaDB local segue deferred** (Aria recovery em quick task `dev:reparar-mariadb-local`). Testes Phase 41 nao dependem de MariaDB — RefreshDatabase + SQLite em-memory cobre tudo.

## Self-Check: PASSED

- [x] tests/Feature/Phase41/Phase41SchemaTest.php — FOUND
- [x] app/Models/MlAdvertiser.php — FOUND
- [x] app/Models/SugadorMlCompanyConfig.php — FOUND
- [x] database/migrations/2026_06_25_410101_create_ml_advertisers_table.php — FOUND
- [x] database/migrations/2026_06_25_410102_create_sugador_ml_company_config_table.php — FOUND
- [x] commit 962ca4e (RED) — FOUND
- [x] commit 46c9d9c (GREEN) — FOUND

## TDD Gate Compliance

- [x] RED commit `962ca4e`: `test(41-01): Suite Phase41Schema RED — 9 tests pra schema/casts/relacoes/FK cascade/unique`
- [x] GREEN commit `46c9d9c`: `feat(41-01): GREEN schema Phase 41 — 2 migrations + 2 Models + 2 relacoes Company`
- [ ] REFACTOR — nao necessario (codigo entregue ja minimo e limpo)
