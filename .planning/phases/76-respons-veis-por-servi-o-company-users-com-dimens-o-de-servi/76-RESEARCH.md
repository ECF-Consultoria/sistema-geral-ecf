# Phase 76: Responsáveis por serviço — `company_users` com dimensão de serviço - Research

**Researched:** 2026-07-14
**Domain:** Laravel 12 — migration cross-driver (MySQL/MariaDB prod + SQLite testes), pivot N:N estendida, relações Eloquent `belongsToMany` com dedup, data-migration idempotente
**Confidence:** HIGH (código real inspecionado linha a linha; comportamento SQL cross-driver validado contra padrão já em uso no projeto)

## Summary

A Phase 76 adiciona `servico_id` (nullable) à pivot `company_users` e troca o unique de `(company_id, user_id, role)` para `(company_id, user_id, role, servico_id)`, migrando as linhas ML existentes para o `servico_id` do contrato performance ativo (ou NULL = consolidado/legado). O risco central **não é o schema** — é preservar 100% o comportamento consolidado (carteira → **bônus**) enquanto se habilita responsável por-serviço.

O ponto técnico mais delicado é que **em Phase 76 nenhuma linha duplicada é criada** (cada empresa continua com no máx. 1 linha por `role`, só ganha `servico_id`), então os leitores consolidados devem retornar resultado idêntico ao de hoje **sem nenhuma mudança de lógica**. A duplicação real (mesma empresa+role com 2 `servico_id`) só chega na Phase 78 — mas as relações consolidadas e a carteira do bônus precisam ser blindadas com dedup **agora**, de forma defensiva, e provadas por teste que simula a linha Shopee futura.

A segunda armadilha é a **escrita**: os 3 pontos de atribuição hoje apagam por `(company_id, role)` (e o `CompanyController::update` chega a fazer `detach()` de TUDO). Isso, com a dimensão de serviço, apagaria o responsável do outro canal. A reescrita precisa apagar/gravar filtrando por `(company_id, role, servico_id)` — com atenção ao gotcha de `servico_id IS NULL` (SQL `= NULL` nunca casa → usar `whereNull`).

**Primary recommendation:** Migration em passos separados (add coluna nullable → FK só no MySQL → swap unique → data-migration idempotente com `whereNull`), relações consolidadas com `->distinct()` sem expor `servico_id` no SELECT, e reescrita das 3 escritas filtrando por `servico_id` (com `whereNull` para o slot consolidado). Sem libs novas. Testes em `tests/Feature/V16/`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Schema da pivot + índice unique | Database / Migration | — | ALTER cross-driver; SQLite enforça constraints nos testes |
| Data-migration ML→performance | Database / Migration | Models (Servico/ContratoServico) | resolução em massa via `DB::table` (padrão do projeto, sem N+1) |
| Leitura consolidada (carteira/bônus) | Models (`Company`/`User`) | — | invariante: dedup no relacionamento, não no consumidor |
| Escrita por-serviço | Controllers (Company/ShopeeEmpresas) | Models | resolve `servico_id` do contrato ativo e escreve escopado |
| Regressão dos ~15 leitores | Tests (Feature/V16) | — | prova que consolidado não muda + ML×Shopee não colidem |

## Standard Stack

Nenhuma dependência nova (constraint explícita do CONTEXT: "sem libs novas"). Toda a fase usa o que já existe:

| Ferramenta | Versão | Papel nesta fase | Provenance |
|------------|--------|------------------|-----------|
| Laravel Schema/Migration | 12.x | ALTER pivot, swap unique, FK condicional | [VERIFIED: composer.json `laravel/framework ^12.0`] |
| Eloquent `belongsToMany` + `withPivot` | 12.x | relações consolidadas + service-aware | [VERIFIED: `Company.php:157-179`, `User.php:195-218`] |
| `DB::table` (query builder) | 12.x | data-migration em massa + escrita escopada | [VERIFIED: padrão em `NpsDispararMensal`, `bulkAssign`] |
| PHPUnit | 11.5.x | Feature tests `tests/Feature/V16/` | [VERIFIED: phpunit.xml, `phpunit/phpunit ^11.5`] |
| SQLite `:memory:` | — | driver de testes (enforça constraints) | [VERIFIED: `phpunit.xml` `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`] |

> Sem `## Package Legitimacy Audit` — a fase não instala pacote externo algum.

## Architecture Patterns

### Fluxo de dados (o que muda)

```
                    ┌─────────────────────────────────────────────┐
ESCRITA             │  ShopeeEmpresasController::bulkAssign :160   │  resolve servico_id
(3 pontos)          │  CompanyController::bulkAssign      :683     │  do contrato ATIVO →
                    │  CompanyController::update (sync)   :617     │  grava/apaga escopado
                    └───────────────────┬─────────────────────────┘  por (company,role,servico_id)
                                        ▼
                          ┌──────────────────────────────┐
                          │  company_users               │  + servico_id (nullable, FK servicos)
                          │  unique(company_id,user_id,  │  NULL = consolidado/legado
                          │         role, servico_id)     │  valor = responsável daquele serviço
                          └───────────────┬──────────────┘
                                          ▼
        ┌─────────────────────────────────┴──────────────────────────────┐
        ▼ (consolidado — DEDUP obrigatório)          ▼ (service-aware — Phase 78)
  Company::consultor()/estrategista()          Company::consultorDoServico($id) / porSetor
  User::companies()/consultorCompanies()/      (novos — habilitam aba Shopee, não usados
       estrategistaCompanies()                  pelos leitores atuais)
        │
        ▼ LEITORES (bônus/carteira/pendências) — devem manter MESMO resultado
  DesempenhoScoreService · PortfolioController · PortfolioGoal · NpsDispararMensal · Goal · ...
```

### Pattern 1: Migration cross-driver em passos separados

**What:** ADD COLUMN nullable → FK só no MySQL → swap do unique → data-migration idempotente, cada um num `Schema::table`/bloco próprio.

**When to use:** Sempre que a coluna nova participa de um índice novo e há FK — a ordem importa e o SQLite tem limitações de ALTER.

```php
// Source: padrão validado contra 2026_05_22_200001_rename_mentor_to_estrategista_in_company_users.php
public function up(): void
{
    // 1) Coluna nullable — ADD COLUMN funciona nativo em MySQL E SQLite (sem doctrine/dbal).
    //    NÃO encadear ->constrained() aqui: SQLite não adiciona FK em ALTER TABLE.
    Schema::table('company_users', function (Blueprint $t) {
        $t->unsignedBigInteger('servico_id')->nullable()->after('role');
        $t->index('servico_id'); // ajuda os filtros service-aware
    });

    // 2) FK apenas no MySQL/MariaDB — SQLite dos testes dispensa (não valida integridade referencial de FK adicionada em alter).
    if (DB::getDriverName() === 'mysql') {
        Schema::table('company_users', function (Blueprint $t) {
            $t->foreign('servico_id')->references('id')->on('servicos')->nullOnDelete();
        });
    }

    // 3) Swap do unique — dropUnique(array) recompute o nome derivado
    //    'company_users_company_id_user_id_role_unique' (existe em ambos os drivers
    //    porque a migração 2026_05_22 o recriou no branch SQLite). Funciona nos dois.
    Schema::table('company_users', function (Blueprint $t) {
        $t->dropUnique(['company_id', 'user_id', 'role']);
        $t->unique(['company_id', 'user_id', 'role', 'servico_id']);
    });

    // 4) Data-migration idempotente (ver Pattern 2) — depois do schema estar pronto.
    $this->migrarLinhasExistentes();
}
```

**Confidence:** HIGH — `ADD COLUMN` e `CREATE/DROP UNIQUE INDEX` são nativos em ambos os drivers sem doctrine/dbal. [CITED: laravel.com/docs/12.x/migrations — "Modifying columns"] A restrição de FK-em-alter do SQLite é conhecida e já contornada pelo projeto com branch por driver.

### Pattern 2: Data-migration idempotente em massa (sem N+1)

**What:** Um mapa `company_id → servico_id` (contrato performance ativo) em 1 query; UPDATE filtrado por `whereNull('servico_id')` para idempotência.

```php
// Source: padrão DB::table puro (ver Phase 68 seed + NpsDispararMensal). pt-BR.
private function migrarLinhasExistentes(): void
{
    // 1 query: para cada empresa, o servico_id do contrato ATIVO de setor 'performance'.
    // MIN() torna determinístico caso a empresa tenha >1 contrato performance ativo.
    $perfPorEmpresa = DB::table('contratos_servico as ct')
        ->join('servicos as s', 's.id', '=', 'ct.servico_id')
        ->where('ct.ativo', true)
        ->where('s.setor', 'performance')
        ->groupBy('ct.company_id')
        ->pluck(DB::raw('MIN(ct.servico_id)'), 'ct.company_id'); // [company_id => servico_id]

    foreach ($perfPorEmpresa as $companyId => $servicoId) {
        DB::table('company_users')
            ->where('company_id', $companyId)
            ->whereNull('servico_id')          // idempotente: só toca linhas ainda consolidadas
            ->update(['servico_id' => $servicoId]);
    }
    // Empresas SEM contrato performance ativo → permanecem servico_id = NULL (consolidado/legado).
    // Nunca inventar serviço (DEC-A1).
}
```

**Idempotência:** rodar 2×/backfill nunca sobrescreve linha já migrada (o `whereNull`). Reversível no `down()`: `DB::table('company_users')->update(['servico_id' => null])` → drop unique novo → restaura unique 3-col → drop FK/coluna.

**Confidence:** HIGH — `groupBy` + `pluck` com chave é resolução O(1 query). Nenhum loop de model. `whereNull` é a chave da idempotência.

### Pattern 3: Relação consolidada com dedup defensivo

**What:** `Company::consultor()/estrategista()` e as carteiras de `User` continuam consolidadas, mas com `->distinct()` para blindar contra a linha Shopee que a Phase 78 vai adicionar.

**Por que funciona:** `consultor()`/`estrategista()` **não** fazem `withPivot('servico_id')`. O SELECT gerado é `users.* + company_users.company_id + company_users.user_id` (chaves da pivot que o Laravel sempre injeta). Duas linhas do mesmo user com `servico_id` diferente têm `users.*`, `company_id` e `user_id` **idênticos** e o `servico_id` **não está no SELECT** → `->distinct()` colapsa corretamente para 1 linha.

```php
// Company.php — consolidado (comportamento de hoje, blindado). pt-BR.
public function consultor()
{
    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', 'consultor')
        ->distinct();                 // dedup: servico_id NÃO está no select → colapsa ML+Shopee
}
public function estrategista()
{
    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', 'estrategista')
        ->distinct();
}

// Variantes service-aware (Phase 78 consome; leitores atuais NÃO usam):
public function consultorDoServico(int $servicoId)
{
    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', 'consultor')
        ->wherePivot('servico_id', $servicoId);
}
```

**Carteira do bônus (`User`) — atenção redobrada:** `companies()` faz `withPivot('role','assigned_at')`. Duas linhas (ML+Shopee) do mesmo par podem ter `assigned_at` diferentes → `assigned_at` entra no SELECT → `->distinct()` sozinho NÃO deduplica. Solução: restringir o SELECT a `companies.*`:

```php
// User.php — carteira consolidada blindada. Consumidores checados: só usam
// pluck('companies.id') / get([...]) / where(...)->exists() — NÃO leem ->pivot->role.
public function companies()
{
    return $this->belongsToMany(Company::class, 'company_users')
        ->withPivot('role', 'assigned_at')
        ->select('companies.*')->distinct()   // dedup robusto a assigned_at divergente
        ->withTimestamps();
}
public function consultorCompanies()
{
    return $this->belongsToMany(Company::class, 'company_users')
        ->wherePivot('role', 'consultor')
        ->select('companies.*')->distinct();
}
public function estrategistaCompanies() { /* idem, role='estrategista' */ }
```

**Confidence:** MEDIUM-HIGH — o comportamento de `distinct()` depende das colunas no SELECT do Laravel; validar por teste que simula a linha Shopee futura (ver Validation Architecture, item c). Em Phase 76 puro (sem dupes) o resultado é idêntico **mesmo sem distinct** — o distinct é a apólice para 78.

### Pattern 4: Escrita escopada por `servico_id`

**ShopeeEmpresasController::bulkAssign** (`:160-186`, hoje apaga por `(company_id, role)` em `:180`):
```php
foreach (Company::whereIn('id', $data['ids'])->get() as $c) {
    // servico_id do contrato Shopee ATIVO da empresa (o guard IDOR :168-172 garante que existe)
    $servicoShopeeId = DB::table('contratos_servico as ct')
        ->join('servicos as s', 's.id', '=', 'ct.servico_id')
        ->where('ct.company_id', $c->id)->where('ct.ativo', true)
        ->where('s.setor', 'shopee')->value('ct.servico_id');

    // Apaga SÓ o slot Shopee daquele papel — não toca linhas ML nem consolidadas.
    DB::table('company_users')->where('company_id', $c->id)
        ->where('role', $data['role'])->where('servico_id', $servicoShopeeId)->delete();

    $c->users()->attach($data['user_id'], [
        'role' => $data['role'], 'servico_id' => $servicoShopeeId,
        'assigned_at' => now()->toDateString(),
    ]);
}
```

**CompanyController::bulkAssign** (`:683-700`, apaga por role em `:694`) e **`update` sync** (`:617-628`, hoje `detach()` de TUDO em `:626` — o mais destrutivo): resolver `servico_id` do contrato **performance** ativo (ou **NULL** consolidado para empresas ML puras — DEC-A3, discrição). **Gotcha crítico:** ao gravar/apagar o slot consolidado, `->where('servico_id', null)` **nunca casa** (SQL `= NULL`). Usar `whereNull`:
```php
$servicoMlId = /* contrato performance ativo, ou null p/ ML puro */;
$q = DB::table('company_users')->where('company_id', $c->id)->where('role', $role);
$servicoMlId === null ? $q->whereNull('servico_id') : $q->where('servico_id', $servicoMlId);
$q->delete();
```
No `update()`, **substituir o `$company->users()->detach()` (apaga tudo)** por detach escopado ao(s) slot(es) performance/consolidado — nunca tocar linhas Shopee.

**Nota `attach`:** passar `servico_id` no array de pivot do `attach()` persiste a coluna **independente** de `withPivot` (withPivot só afeta leitura). Não é preciso adicionar `withPivot('servico_id')` nas relações consolidadas.

### Anti-Patterns to Avoid
- **`->where('servico_id', null)`** — nunca casa; usar `whereNull`/`whereNotNull`. (Vetor #1 de bug silencioso nesta fase.)
- **Confiar no unique para bloquear duplicata consolidada** — em MySQL/MariaDB **e** SQLite, múltiplos NULL num índice unique **não colidem** (NULL ≠ NULL). Duas linhas `(empresa, user, role, NULL)` passam pelo unique. Manter o padrão delete-then-attach nas escritas (já existe) — não confiar só na constraint.
- **`->constrained()` num `Schema::table` (alter)** — SQLite não adiciona FK em ALTER; branch por driver.
- **Deduplicar no consumidor** (ex.: `->unique('id')` espalhado por 15 leitores) — frágil; centralizar o dedup na relação.

## Don't Hand-Roll

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Resolver contrato ativo por empresa em massa | loop de `Company::with('contratosServico')` | 1 query `join`+`groupBy`+`pluck` (Pattern 2) | evita N+1 em ~168 empresas; determinístico |
| Dedup consolidado | `->unique('id')` em cada leitor | `->distinct()`/`->select('companies.*')->distinct()` na relação (Pattern 3) | 1 ponto de verdade; blinda os 15 leitores de uma vez |
| Enum/constraint cross-driver | pular SQLite (armadilha latente) | branch por `DB::getDriverName()` | SQLite dos testes **enforça** constraints [[project_enum_setor_sqlite_check]] |
| Idempotência da data-migration | flag externa / checagem manual | `whereNull('servico_id')` no UPDATE | seguro para backfill/re-run |

## Runtime State Inventory

Fase de migração/refactor de schema — inventário obrigatório.

| Categoria | Itens encontrados | Ação |
|-----------|-------------------|------|
| **Dados armazenados** | Linhas atuais de `company_users` (todas com `servico_id` NULL hoje) → precisam receber o `servico_id` do contrato performance ativo. **Data-migration** (não só code edit). | Data-migration idempotente (Pattern 2) |
| **Config de serviço vivo** | Nenhum serviço externo referencia `company_users.servico_id`. Verificado: a pivot é lida só por código PHP interno (grep de `company_users` → só `app/`). | Nenhuma |
| **Estado registrado no SO** | Nenhum. `company_users` não aparece em Task Scheduler/Supervisor/pm2. | Nenhuma |
| **Secrets/env vars** | Nenhum. Nenhuma env var referencia a pivot ou `servico_id`. | Nenhuma |
| **Build artifacts / cache** | **`DesempenhoScoreService` cacheia score** (chave v2 — `:139-141`). A carteira alimenta o score; se o dedup mudasse resultado, o cache mascararia. Em Phase 76 o resultado consolidado **não muda**, então **não** é preciso bump de cache aqui (o bump v2→v3 é da Phase 80). Confirmar por teste que a carteira consolidada é idêntica. Após deploy, `php artisan cache:clear` é prudente mas não obrigatório. | Rodar teste de regressão; cache:clear opcional no VPS |

**Canônico:** *depois de a migration rodar, que sistema em runtime ainda tem estado do modelo antigo?* → apenas o **cache do Desempenho** (`desempenho:*`), e só importaria se a carteira mudasse — o que o teste de regressão (item c) prova que não acontece.

## Common Pitfalls

### Pitfall 1: `= NULL` no filtro do slot consolidado
**O que quebra:** `->where('servico_id', null)->delete()` não apaga nada; a re-atribuição consolidada cria linha órfã/duplicada.
**Como evitar:** `whereNull`/`whereNotNull` em todo filtro por `servico_id` que possa ser NULL (escritas + variantes consolidadas).
**Sinal de alerta:** teste de re-atribuição consolidada deixa 2 linhas.

### Pitfall 2: Múltiplos NULL não colidem no unique
**O que quebra:** espera-se que o unique impeça 2 responsáveis consolidados iguais — não impede (NULL ≠ NULL em MySQL/MariaDB e SQLite).
**Como evitar:** manter delete-then-attach nas escritas; não confiar na constraint para deduplicar o slot NULL.
**Sinal:** duplicatas consolidadas aparecendo após atribuições repetidas.

### Pitfall 3: `CompanyController::update` faz `detach()` de tudo
**O que quebra:** `:626` `$company->users()->detach()` apaga **todas** as linhas da pivot — incluindo o responsável Shopee (Phase 78) — antes de re-attach só ML.
**Como evitar:** detach escopado ao slot performance/consolidado; nunca `detach()` sem filtro.
**Sinal:** atribuir ML pela tela de edição zera o responsável Shopee.

### Pitfall 4: `distinct()` não deduplica quando pivot varia
**O que quebra:** `companies()` com `withPivot('assigned_at')` — `assigned_at` divergente entre linha ML e Shopee entra no SELECT e furos o `distinct()` → carteira dupla → **bônus dobrado**.
**Como evitar:** `->select('companies.*')->distinct()` nas carteiras (Pattern 3); provar por teste com 2 `servico_id`.
**Sinal:** `DesempenhoScoreService::computeUniverso` conta a mesma empresa 2×.

### Pitfall 5: FK adicionada em ALTER quebra no SQLite
**O que quebra:** `->constrained()` no `Schema::table` alter → erro no driver de testes.
**Como evitar:** coluna nullable pura + FK em branch `DB::getDriverName()==='mysql'`.
**Sinal:** suite V16 falha no `migrate` antes de qualquer assert.

## State of the Art

| Abordagem antiga | Abordagem desta fase | Impacto |
|------------------|----------------------|---------|
| Responsável **por-empresa** (`company_id,user_id,role`) | Responsável **por-serviço** (`+servico_id`, NULL=consolidado) | Habilita ML×Shopee distintos sem duplicar empresa |
| Escrita apaga por `(company_id, role)` / `detach()` total | Escrita escopada por `(company_id, role, servico_id)` | Corrige o risco da Phase 75 (sobrescrita cross-serviço) |
| Leitura consolidada implícita (1 linha/role) | Leitura consolidada explícita com `->distinct()` | Blinda o bônus para a duplicação da Phase 78 |

**Não deprecado nesta fase:** o cargo canônico continua vindo de `user_setores→cargos.slug` (analista/estrategista), **não** de `company_users.role` — a pivot `role` segue sendo o vínculo Empresa↔Pessoa, e `'consultor'` continua sendo o slot do "Analista" do setor Performance (ver `SugadorController` UAT 2026-07-02). Não confundir os dois eixos.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `dropUnique(['company_id','user_id','role'])` casa o nome de índice existente em ambos os drivers | Pattern 1 | migration falha no drop; mitigar checando `SHOW INDEX`/nome explícito |
| A2 | Nenhum consumidor de `User::companies()` lê `->pivot->role` a partir dessa relação (só pluck/get/exists) | Pattern 3 | `->select('companies.*')` removeria pivot e quebraria consumidor; validar caso-a-caso antes de aplicar |
| A3 | ML puro deve receber `servico_id` do contrato performance ativo quando existir, senão NULL (discrição DEC-A3) | Pattern 4 | decisão de produto; confirmar com usuário se ML puro deve ser sempre NULL consolidado |

## Open Questions

1. **ML puro: `servico_id` = performance ativo ou sempre NULL?**
   - O que sabemos: DEC-A3 deixa à discrição, "sem quebrar o fluxo ML atual".
   - O que não está claro: se a tela de edição de empresa deve gravar o `servico_id` performance (consistente com a data-migration) ou manter NULL.
   - Recomendação: gravar o `servico_id` performance ativo (mesma regra da data-migration) para consistência; cair em NULL só quando a empresa não tem contrato performance. Confirmar com o usuário no plan/discuss.

2. **`dropUnique` — nome do índice.**
   - Recomendação: se houver qualquer dúvida sobre o nome derivado, dropar por nome explícito `company_users_company_id_user_id_role_unique` e re-criar. Testar o `migrate:fresh` na suite V16 antes de fechar.

## Environment Availability

| Dependência | Requerida por | Disponível | Versão | Fallback |
|-------------|---------------|-----------|--------|----------|
| SQLite `:memory:` | Feature tests V16 | ✓ | driver PHP `pdo_sqlite` | — |
| MySQL/MariaDB local | validar branch MySQL da migration | ✗ (frequentemente corrompido — [[project_mariadb_local_corrompido]]) | — | testar branch MySQL só no VPS/staging; suite roda em SQLite |

**Impacto:** o branch MySQL da FK/enum **não é exercitado** pelos testes locais (caem em SQLite). Validar a migration MySQL manualmente contra o VPS antes de deploy, e confirmar `tasklist | grep mysqld` antes de qualquer comando que dependa de DB local.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.x |
| Config file | `phpunit.xml` (DB_CONNECTION=sqlite, :memory:) |
| Quick run command | `php artisan test tests/Feature/V16 --stop-on-failure` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req | Comportamento | Tipo | Comando | Arquivo existe? |
|-----|---------------|------|---------|-----------------|
| DEC-A1 (a) | Migration roda cross-driver; unique novo `(company_id,user_id,role,servico_id)` ativo; múltiplos NULL coexistem | Feature | `php artisan test tests/Feature/V16/MigrationCompanyUsersServicoTest.php` | ❌ Wave 0 |
| DEC-A1 (b) | Data-migration: linha de empresa COM contrato performance ativo → `servico_id` preenchido; SEM contrato → NULL; re-run idempotente | Feature | `.../DataMigrationServicoTest.php` | ❌ Wave 0 |
| DEC-A2 (c) | **Invariante regressão**: com uma 2ª linha (mesma empresa+role, `servico_id` Shopee), `Company::consultor()`/`estrategista()` retornam 1 user; `User::companies()`/`consultorCompanies()`/`estrategistaCompanies()` retornam a empresa 1× | Feature | `.../ResponsaveisConsolidadoInvarianteTest.php` | ❌ Wave 0 |
| DEC-A2 (c) | Carteira do bônus: `DesempenhoScoreService::computeUniverso` conta a empresa 1× mesmo com linha ML+Shopee | Feature | `.../CarteiraBonusNaoDobraTest.php` | ❌ Wave 0 |
| DEC-A3 (d) | `ShopeeEmpresasController::bulkAssign` não apaga responsável ML; `CompanyController::bulkAssign`/`update` não apaga responsável Shopee | Feature | `.../AtribuicaoPorServicoIsolamentoTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Por task commit:** `php artisan test tests/Feature/V16 --stop-on-failure`
- **Por merge de wave:** `php artisan test tests/Feature/V16`
- **Phase gate:** suite completa (`php artisan test`) verde antes de `/gsd:verify-work` — garante que nenhum dos ~15 leitores regrediu.

### Wave 0 Gaps
- [ ] `tests/Feature/V16/` — diretório não existe; criar.
- [ ] `database/factories/ServicoFactory.php` e `ContratoServicoFactory.php` — **não existem** (só há `CompanyFactory`, `UserFactory`, `CompanyMarketplaceFactory`); criar factories ou usar `DB::table` direto no setup dos testes para serviço performance/shopee + contrato ativo.
- [ ] Fixture helper: empresa com contrato performance ativo + (opcional) contrato shopee ativo + par de users (analista/estrategista) na pivot — reutilizado pelos 5 testes.
- [ ] Sem framework install — PHPUnit já presente.

### Leitores para teste de REGRESSÃO — priorização (confirmado por inspeção)

**Grupo A — leem `->consultor`/`->estrategista` (relação consolidada).** Naturalmente seguros contra duplicata quando usam `->first()`/`->merge()->unique('id')`, mas o `->distinct()` os blinda de vez:
- `NpsDispararMensal.php:198,206` (`estrategista()->first()`, `consultor()->first()`) — **prioridade alta** (disparo/pendência).
- `Goal.php:28-33` + `CalculateGoalResults.php:91-99` (`with(['consultor','estrategista'])`, `merge()->unique('id')` — já deduplica).
- `CompanyController.php:158-159,190,453-454`; `ShopeeEmpresasController.php:86-87,110`; `ComercialController.php:322-323`; `GoalController.php:38-39`; `MeetingController.php:39-40`; `DashboardController.php:262,908-909`; `HubspotWebhookController.php:580-584`; `NpsController.php:449-450`.
- `DashboardController.php:680-688` (self-join `company_users`×`company_users` + `->distinct()`) — já tem `distinct()` no par (analista_id, estrategista_id); duplicata por serviço colapsa. Verificar por teste.

**Grupo B — carteira via `companies()`/`wherePivot` (vetor de bônus dobrado).** **Prioridade crítica** — são os que `->get()` + iteram/contam:
- `DesempenhoScoreService.php:251,289` (`companies()->get()` iterado → carteira do **bônus**) ★★★
- `PortfolioController.php:517-518,615-616,1406` (`estrategistaCompanies()/consultorCompanies()->get()` iterado) ★★
- `PortfolioGoal.php:98-103` (`whereHas('users', role)->get()` iterado) ★★
- `NpsPendingService.php:157-162` (`companies()->get()`) ★

**Grupo C — dup-safe por construção** (usam `pluck('companies.id')` → `whereIn` que deduplica, ou `->exists()`): `SugadorController.php:93-195,345,782,1153,1198`, `Sugador.php:147-152` (scope subquery), `SugadorPolicy.php:103`, `EmpresaAnaliseEcfController.php:53`, `MeetingController.php:25,45,56`, `GoalController.php:134,160`, `NpsController.php:123,229,276,294,371`. Documentar como "verificado dup-safe" — não precisam de assert dedicado, mas o teste (c) cobre o risco na fonte.

## Security Domain

`security_enforcement` não está `false` no config → incluir.

### Applicable ASVS Categories
| Categoria | Aplica | Controle |
|-----------|--------|----------|
| V4 Access Control | **sim** | Guard anti-IDOR da Phase 75 em `ShopeeEmpresasController::bulkAssign:168-172` (fail-closed por escopo Shopee) — **preservar** ao reescrever a escrita |
| V5 Input Validation | sim | `$request->validate` já presente nas 3 escritas (`ids.*` exists, `role` in-list, `user_id` exists) — manter |
| V6 Cryptography | não | — |

### Known Threat Patterns
| Padrão | STRIDE | Mitigação |
|--------|--------|-----------|
| IDOR — atribuir responsável a empresa fora do escopo Shopee | Elevation/Tampering | manter o closure guard `:168-172` (rejeita ID fora do builder Shopee com 422, fail-closed) |
| Cross-serviço tampering — atribuição ML apaga responsável Shopee (e vice-versa) | Tampering | escrita escopada por `servico_id` (Pattern 4) + teste de isolamento (d) |

## Sources

### Primary (HIGH confidence)
- Código real inspecionado: `Company.php:157-179`, `User.php:79-218`, `ShopeeEmpresasController.php:59-187`, `CompanyController.php:600-700`, `DesempenhoScoreService.php:245-291`, `NpsDispararMensal.php:190-212`, `PortfolioGoal.php:90-104`, `DashboardController.php:675-688`, `Servico.php`, `ContratoServico.php`.
- Migrations: `2026_04_26_152217_create_company_users_table.php`, `2026_05_07_000005_add_analista_to_company_users_role_enum.php`, `2026_05_22_200001_rename_mentor_to_estrategista_in_company_users.php` (padrão cross-driver por `DB::getDriverName()`).
- `phpunit.xml` (sqlite :memory:), `database/factories/` (ausência de Servico/ContratoServico factory).
- Memória: `project_enum_setor_sqlite_check` (SQLite enforça CHECK/constraints nos testes), `project_mariadb_local_corrompido`, `project_desempenho_compute_cache`, `project_desempenho_metrica_carteira_alinhada`.
- CONTEXT `76-CONTEXT.md` (DEC-A1..A3) + `v16.0-brief.md` (DEC-A, âncoras).

### Secondary (MEDIUM confidence)
- [CITED: laravel.com/docs/12.x/migrations] — comportamento de `dropUnique`/`unique`/coluna nullable em ALTER e limitações do SQLite para FK-em-alter.

## Metadata

**Confidence breakdown:**
- Migration cross-driver + data-migration: HIGH — padrão idêntico já em produção no projeto.
- Escrita escopada + gotcha `whereNull`: HIGH — 3 pontos mapeados linha a linha.
- Dedup consolidado (`distinct`/`select`): MEDIUM-HIGH — depende do SELECT do Laravel; obrigatório provar por teste (c).
- Lista de leitores de regressão: HIGH — grep exaustivo classificado em A/B/C.

**Research date:** 2026-07-14
**Valid until:** ~30 dias (stack estável; reconciliar com `origin/main` do dev paralelo anunciar-ml antes do deploy).
