---
phase: 57
name: Modelo de dados multi-marketplace
milestone: v13.0
captured: 2026-07-03
requirements: [DATA-01, DATA-02, DATA-03]
---

# Phase 57 — CONTEXT

## Domain

Fundação de dados para multi-marketplace. Formaliza o modelo N:N entre `Company` e marketplaces (ML, Shopee, Amazon, futuros) através de uma tabela pivot nova, sem quebrar o schema atual nem os consumidores existentes. Habilita phases 58 (Dashboard ECF agregado) e 59 (Desacoplamento áreas transversais).

**Estado do schema pré-Phase 57 (importante):**

O modelo NÃO está no zero — Phase 18.5 já introduziu `companies.marketplace` (ENUM), e Phase 34/35 introduziram `companies.marketplaces_extras` (JSON). Phase 57 formaliza um modelo N:N vazio mas **não remove** as colunas legado nesta milestone.

```
companies (schema atual — MANTIDO)
├─ marketplace           ENUM('meli','shopee','amazon')  ← Phase 18.5
├─ marketplaces_extras   JSON nullable                    ← Phase 34/35
├─ adman_account_id      VARCHAR    ← ID Adman ML
├─ ml_store_id           VARCHAR    ← ID nativo ML (OAuth)
├─ adman_store_id        VARCHAR    ← ID Adman secundário
└─ ...
```

**Distribuição real (baseada em migration Phase 18.5):**
- 135 empresas `marketplace='meli'`
- 33 empresas `marketplace='shopee'`
- 1 empresa `marketplace='amazon'`

## Canonical refs

- [app/Models/Company.php](../../../app/Models/Company.php) — model alvo dos helpers novos + accessors de fallback
- [database/migrations/2026_06_02_190000_add_marketplace_to_companies.php](../../../database/migrations/2026_06_02_190000_add_marketplace_to_companies.php) — Phase 18.5 (marketplace ENUM)
- [database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php](../../../database/migrations/2026_06_12_300001_add_close_fields_to_companies_table.php) — Phase 34/35 (marketplaces_extras JSON)
- [app/Services/AdmanService.php](../../../app/Services/AdmanService.php) — consumidor crítico de `companies.marketplace` (91 refs)
- [app/Console/Commands/ImportMarketplaceFromCsv.php](../../../app/Console/Commands/ImportMarketplaceFromCsv.php) — comando de backfill do CSV oficial (Phase 18.5)
- [.planning/phases/56-.../PHASE-SUMMARY.md](../56-menu-lateral-multi-marketplace-stubs-em-desenvolvimento/PHASE-SUMMARY.md) — contexto visual estabelecido em Phase 56

## Locked decisions

### 1. Modelo: N:N vazio + helpers (não remove schema legado)

**Decisão:** Criar tabela pivot nova `company_marketplaces` com FK para companies. Backfill: 1 row primary por empresa a partir do `marketplace` ENUM atual + rows adicionais a partir do `marketplaces_extras` JSON. **Colunas legado permanecem** (marketplace ENUM + marketplaces_extras JSON + adman_account_id + ml_store_id) — servem de "fonte de fallback" até consumidores serem migrados.

**Racional:**
- Shopee/Amazon são stubs nesta milestone (v13.0) — sem integração real; N:N completo com refactor de 8 consumidores seria over-engineering.
- Ao mesmo tempo, sem tabela N:N formal, Phase 58 (Dashboard ECF agregado) fica sem estrutura pra query "empresa X mostra dados de N marketplaces".
- Meio-termo: estrutura N:N pronta pra crescer, JSON legacy fica em paralelo por 1-2 milestones até refactor completo.

### 2. Schema da tabela `company_marketplaces`

```php
Schema::create('company_marketplaces', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->enum('marketplace', ['meli', 'shopee', 'amazon', 'magalu']);
    $table->string('store_id')->nullable()->comment('ID nativo do seller no marketplace (ex: ml_store_id, shopee_store_id)');
    $table->string('adman_id')->nullable()->comment('ID Adman API (historicamente ML; fica NULL para marketplaces novos)');
    $table->boolean('is_primary')->default(false)->comment('MP primário da empresa (ex-companies.marketplace)');
    $table->boolean('active')->default(true);
    $table->enum('integracao_status', ['ativa', 'sem_token', 'erro', 'pendente'])->default('ativa');
    $table->timestamps();

    $table->unique(['company_id', 'marketplace'], 'company_mp_unique');
    $table->index(['marketplace', 'active']);
});
```

**Notas de design:**
- **`is_primary`**: mantém a semantic do `companies.marketplace` — cada empresa tem EXATAMENTE 1 primary (guard em model via observer/scope, não constraint de DB pra facilitar migrations futuras).
- **`store_id` + `adman_id` separados**: `adman_account_id` histórico ≠ `ml_store_id` histórico — dois IDs distintos hoje. Modelo antecipa isso (store_id = ID nativo do MP, adman_id = ID Adman para marketplaces cobertos por Adman).
- **`unique(company_id, marketplace)`**: uma empresa não pode ter 2 registros do mesmo marketplace.
- **`integracao_status`** ENUM: prepara terreno pra Phase 50 (que virou WONT_DO em v13.0 mas pode voltar) — status por MP, não por empresa global.

### 3. Store IDs (`adman_account_id`, `ml_store_id`) — MOVEM pra pivot via accessor de fallback

**Decisão:** Migration copia `adman_account_id`, `ml_store_id`, `adman_store_id` das colunas de `companies` para a row primary da pivot (marketplace='meli'). Os **campos flat continuam existindo em companies** para não quebrar consumidores. Adicionar **accessor no Company.php** que lê da pivot como fonte-de-verdade e escreve em ambos (pivot + coluna flat) via mutator. Consumidores continuam usando `$company->adman_account_id` sem alteração.

**Racional:**
- Zero blast radius nos 8 consumidores atuais (AdmanService, SugadorProviders, comandos de diagnóstico).
- Pivot vira fonte-de-verdade, colunas flat viram cache/backup — permitem read direto se needed.
- Preparado para Phase 43 futura (Remoção da Adman) OU migração multi-marketplace real: quando refactorar consumidores para usar a pivot, drop das colunas flat vira uma migration trivial.

**Snippet do accessor** (para incluir no Company.php):

```php
// Le da pivot; fallback pra coluna flat se pivot vazia (migration parcial)
public function getAdmanAccountIdAttribute(): ?string
{
    $row = $this->marketplaces()->where('marketplace', 'meli')->first();
    return $row?->adman_id ?? $this->attributes['adman_account_id'] ?? null;
}

public function getMlStoreIdAttribute(): ?string
{
    $row = $this->marketplaces()->where('marketplace', 'meli')->first();
    return $row?->store_id ?? $this->attributes['ml_store_id'] ?? null;
}

// Mutator: escreve em ambos (pivot + coluna flat) para consistência
public function setAdmanAccountIdAttribute(?string $value): void
{
    $this->attributes['adman_account_id'] = $value;
    if ($this->exists) {
        $this->marketplaces()->updateOrCreate(
            ['marketplace' => 'meli'],
            ['adman_id' => $value, 'is_primary' => true]
        );
    }
}
```

**Trade-off consciente:** custo de +1 query por accessor call. Aceitável porque Company é geralmente instanciado com eager loading de relacionamentos em rotas críticas (Dashboard, Sugadores).

### 4. Helpers novos em Company.php

```php
// Relacionamento
public function marketplaces(): HasMany
{
    return $this->hasMany(CompanyMarketplace::class);
}

// Helpers de consulta
public function isInMarketplace(string $slug): bool
{
    return $this->marketplaces()->where('marketplace', $slug)->where('active', true)->exists();
}

public function marketplacesAtivos(): Collection
{
    return $this->marketplaces()->where('active', true)->pluck('marketplace');
}

public function primaryMarketplace(): ?string
{
    return $this->marketplaces()->where('is_primary', true)->value('marketplace');
}

public function storeIdFor(string $slug): ?string
{
    return $this->marketplaces()->where('marketplace', $slug)->value('store_id');
}
```

### 5. Backfill (comando one-shot)

Comando `php artisan companies:backfill-marketplaces` (idempotente, safe para rerun):

```
Para cada Company:
  1. Cria row primary: marketplace = $company->marketplace, is_primary=true,
     store_id = $company->ml_store_id (para meli),
     adman_id = $company->adman_account_id
  2. Se $company->marketplaces_extras é array não-vazio:
     Para cada slug em marketplaces_extras (que NÃO é o primary):
       Cria row: marketplace=$slug, is_primary=false, store_id=null, adman_id=null

Log final:
  - Empresas processadas
  - Rows primary criadas
  - Rows extras criadas
  - Empresas com marketplaces_extras inválido (log e skip)
```

**Idempotência**: uso `updateOrCreate` na chave (`company_id`, `marketplace`) — rerun não duplica.

### 6. Testes DATA-03 — Feature tests + smoke queries dos 3 consumidores críticos

**Testes obrigatórios:**

1. **Model — helpers e relacionamento** (`tests/Feature/Phase57/CompanyMarketplaceHelpersTest.php`):
   - `isInMarketplace('shopee')` retorna true quando pivot tem row ativa
   - `marketplacesAtivos()` retorna coleção correta
   - `primaryMarketplace()` retorna o marketplace primary
   - `storeIdFor('meli')` retorna o store_id da row correspondente

2. **Model — accessors com fallback** (`tests/Feature/Phase57/CompanyLegacyAccessorsTest.php`):
   - Company com pivot preenchida: `$company->adman_account_id` retorna valor da pivot
   - Company com pivot VAZIA: `$company->adman_account_id` retorna valor da coluna flat (fallback)
   - Setar `$company->adman_account_id = 'X'` grava em ambos: pivot + coluna flat

3. **Backfill idempotente** (`tests/Feature/Phase57/BackfillMarketplacesTest.php`):
   - Comando roda em DB vazia → cria N rows corretas
   - Rerun não duplica (idempotência)
   - Empresa com marketplaces_extras inválido é skipada + logada

4. **Smoke — Dashboard não quebra** (`tests/Feature/Phase57/DashboardSmokeTest.php`):
   - GET `/dashboard` como admin retorna 200 com pivot preenchida
   - Nenhuma prop crítica vira null

5. **Smoke — Sugadores não quebra** (`tests/Feature/Phase57/SugadoresSmokeTest.php`):
   - GET `/sugadores` como admin retorna 200
   - GET `/sugadores/empresa/{id}` retorna 200 com pivot preenchida

6. **Smoke — /performance não quebra** (`tests/Feature/Phase57/PerformanceSmokeTest.php`):
   - GET `/performance` como admin retorna 200

**Não testar** (fora do escopo lean):
- Cada uma das 91 refs de `marketplace` no AdmanService — comportamento não mudou.
- Regressão em SugadoresProvider (unit tests existentes cobrem).
- UAT em prod cobre regressão real.

## Escopo — O que ENTRA

1. Nova migration `create_company_marketplaces_table.php` com schema decidido
2. Novo model `CompanyMarketplace.php` (Eloquent) com fillable + casts + relacionamento inverso
3. Ajustes em `Company.php`:
   - Novo relacionamento `marketplaces()`
   - Helpers: `isInMarketplace`, `marketplacesAtivos`, `primaryMarketplace`, `storeIdFor`
   - Accessors de fallback: `adman_account_id`, `ml_store_id`
   - Mutator: escreve pivot + coluna flat
4. Comando `companies:backfill-marketplaces` idempotente
5. Rodar backfill em DEV + PROD
6. 6 feature tests (helpers, accessors, backfill idempotente, 3 smoke tests)
7. ADR curto `.planning/adrs/DATA-01-multi-marketplace-model.md` documentando a decisão do modelo híbrido intencional

## Escopo — O que NÃO ENTRA

- **Drop de `companies.marketplace` ENUM** — legado fica pra milestone futura
- **Drop de `companies.marketplaces_extras` JSON** — idem
- **Drop de `adman_account_id`, `ml_store_id`, `adman_store_id`** — idem
- **Refactor de AdmanService** — continua lendo `$company->marketplace` sem mudança
- **Refactor de Providers Sugador (Adman/ML)** — continuam lendo `adman_account_id`/`ml_store_id` sem mudança
- **UI para gerenciar marketplaces por empresa** — Phase 58/59 (se necessário)
- **Store IDs específicos por marketplace novo** (shopee_store_id, amazon_seller_id) — Phase futura quando integrar
- **Migration de `companies:importar-drive`** — continua populando `email_cliente`/`telefone` como antes

## Deferred ideas

- **Adicionar `marketplace` ao NpsSurvey/PortfolioGoal/etc** — Phase 59 audita se faz sentido.
- **Dropar campos flat depois da migração de consumidores** — plano de retirada gradual em milestone v14+.
- **UI de gestão de marketplaces por empresa** — se e quando necessário para o Comercial.
- **Sync bidirecional pivot ↔ flat via observer** — hoje mutator faz manualmente; pode virar observer se sync ficar complexo.

## Code context

**Arquivos que serão criados:**
- `database/migrations/YYYY_MM_DD_HHMMSS_create_company_marketplaces_table.php`
- `app/Models/CompanyMarketplace.php`
- `app/Console/Commands/BackfillCompanyMarketplaces.php`
- `.planning/adrs/DATA-01-multi-marketplace-model.md`
- `tests/Feature/Phase57/CompanyMarketplaceHelpersTest.php`
- `tests/Feature/Phase57/CompanyLegacyAccessorsTest.php`
- `tests/Feature/Phase57/BackfillMarketplacesTest.php`
- `tests/Feature/Phase57/DashboardSmokeTest.php`
- `tests/Feature/Phase57/SugadoresSmokeTest.php`
- `tests/Feature/Phase57/PerformanceSmokeTest.php`

**Arquivos que serão modificados:**
- `app/Models/Company.php` — helpers + accessors + mutator + relacionamento

**Não modificar:**
- `app/Services/AdmanService.php` — continua igual
- `app/Services/Sugadores/*` — continua igual
- `app/Services/SugadorAnalysisService.php` — continua igual
- Migrations existentes de `companies` — não tocar

**Padrões a preservar:**
- Nomenclatura pt-BR nos comentários
- `snake_case` em colunas do DB
- Convenção do projeto: comando com signature `nome:verbo` (`companies:backfill-marketplaces`)
- Testes agrupados em `tests/Feature/Phase57/`
- Migration com docblock explicando motivação (padrão Phase 18.5)

## Risk summary

| Risco | Severidade | Mitigação |
|-------|------------|-----------|
| Accessor de fallback tem edge case (Company novo, sem pivot) | Média | Fallback pra coluna flat cobre; teste específico do accessor com pivot vazia |
| Backfill em prod cria dados inconsistentes | Alta | Comando idempotente + dry-run flag + validação pré/pós contagem |
| Mutator escreve em pivot ANTES de model ser saved (ID = null) | Média | Guard `if ($this->exists)` no mutator; teste específico |
| Query N+1 em Dashboard/Sugadores por causa de accessor | Baixa-Média | Eager load `->with('marketplaces')` em routes críticas — anotar como follow-up se profiling mostrar |
| DB local corrompido (memory: mariadb) atrapalha dev/test local | Alta | Todo teste em SQLite in-memory (padrão Laravel) + rodar em prod após deploy |

## Success criteria

Ao final desta phase:

1. Tabela `company_marketplaces` existe em DEV e PROD com backfill executado
2. `$company->marketplaces` retorna coleção de rows (usando ORM)
3. `$company->isInMarketplace('meli')` funciona para todas as 169 empresas
4. `$company->adman_account_id` continua retornando o mesmo valor de antes (via accessor)
5. Dashboard, /sugadores, /performance carregam sem erro em prod após deploy
6. Todos os 6 testes verdes
7. ADR-DATA-01 documenta o modelo híbrido intencional para consulta futura
