# Phase 51 — Pesquisa: Reestruturação /grants com Nova API ECF Drive

**Data:** 2026-06-30
**Domínio:** Laravel 12 + Inertia + React — extensão de pipeline existente
**Confiança geral:** HIGH (todos patterns têm precedente no projeto, mesma família de arquivos)

## Resumo

Phase 51 é puramente aditiva: 2 novos métodos em `EcfDriveService`, 1 migration nullable em `company_grants` (8 colunas), expansão do `mapToDb()` no command de sync, novo controller que consome `/grants/resumo` + `/grants/distribuicao`, e novos StatCards na UI. Zero risco arquitetural — todos os patterns já existem no projeto.

**Recomendação primária:** Replicar 1:1 os patterns das Phases 20/22 já em produção. Nenhuma decisão arquitetural nova.

---

## 1. Pattern de método em `EcfDriveService`

**Helper `get($path, $params)` confirmado** em `app/Services/EcfDriveService.php:557-568`:
- Usa `$this->http()->get($path, $params)` (linhas 559)
- Lança `\RuntimeException` com mensagem pt-BR em HTTP != 2xx (linhas 561-565)
- Retorna `$response->json() ?? []` (linha 567)
- `http()` já tem `retry(2, 500)` + `timeout(15)` configurados (`app/Services/EcfDriveService.php:600-609`)

**Modelo direto a copiar — `carteiraBreakdown()` em `app/Services/EcfDriveService.php:367-380`:**
```php
public function carteiraBreakdown(string $dimensao, ?string $timMonthId = null): array
{
    $params = array_filter([
        'dimensao'     => $dimensao,
        'tim_month_id' => $timMonthId,
    ], fn ($v) => $v !== null && $v !== '');

    $path = '/carteira/breakdown';
    return Cache::remember(
        $this->cacheKey($path, $params),
        3600,
        fn () => $this->get($path, $params),
    );
}
```

**Recomendação acionável para Phase 51:**

```php
// app/Services/EcfDriveService.php — adicionar na seção "── Clientes ──" após linha 155

/**
 * Resumo de grants com totais + buckets 7/15/30/60/90d + divergência CSV vs banco.
 * Cache: 5min (TTL=300s) — refresh on view, alinhado a outros endpoints de "resumo".
 * @see API-GUIDE.md §X (GET /grants/resumo)
 */
public function grantsResumo(): array
{
    return Cache::remember(
        $this->cacheKey('/grants/resumo'),
        300,
        fn () => $this->get('/grants/resumo'),
    );
}

/**
 * Distribuição de grants por dimensão (programa, iniciativa, nivelSolucion, etc).
 * Cache: 1h (TTL=3600s) — segue padrão de carteiraBreakdown().
 * @see API-GUIDE.md §X (GET /grants/distribuicao)
 */
public function grantsDistribuicao(string $dimensao): array
{
    $params = ['dimensao' => $dimensao];
    return Cache::remember(
        $this->cacheKey('/grants/distribuicao', $params),
        3600,
        fn () => $this->get('/grants/distribuicao', $params),
    );
}
```

**Mock helper específico:** NÃO existe helper customizado — testes usam `Http::fake` direto. Ver §4.

---

## 2. Pattern de Migration aditiva (colunas nullable)

**Modelo canônico — mesma tabela target:** `database/migrations/2026_06_05_120000_add_segmento_to_company_grants.php` (Phase 20, mesma origem ECF Drive). Linha 14: `$table->string('segmento')->nullable()->after('ml_cust_id');`

**Outro recente (2026-06-15):** `database/migrations/2026_06_15_160000_add_ads_desligado_to_mlb_empresas.php:14-29` — também usa anonymous class + `->nullable()->after(...)`.

**Recomendação acionável:**

```php
// database/migrations/2026_06_30_NNNNNN_add_metadata_fields_to_company_grants.php
return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->string('programa')->nullable()->after('segmento');
            $table->string('iniciativa')->nullable()->after('programa');
            $table->string('nivel_solucion')->nullable()->after('iniciativa');
            $table->string('nombre_solucion')->nullable()->after('nivel_solucion');
            $table->string('parceiro')->nullable()->after('nombre_solucion');
            $table->string('localidade')->nullable()->after('parceiro');
            $table->date('medalha_fecha_in')->nullable()->after('localidade');
            $table->date('medalha_fecha_out')->nullable()->after('medalha_fecha_in');
        });
    }

    public function down(): void
    {
        Schema::table('company_grants', function (Blueprint $table) {
            $table->dropColumn(['programa','iniciativa','nivel_solucion','nombre_solucion','parceiro','localidade','medalha_fecha_in','medalha_fecha_out']);
        });
    }
};
```

**Notas:** snake_case nas colunas (convenção do projeto, confirmada em `company_grants` existente). `medalha_fecha_in/out` como `date` (são datas, não datetime — verificar payload real; cair em `string` se vier formato custom). Sem index — campos são opcionais para filtros futuros.

---

## 3. Pattern `updateOrCreate` em `SyncGrantsFromEcfDrive`

**Local exato:** `app/Console/Commands/SyncGrantsFromEcfDrive.php:75-78`:

```php
$row = CompanyGrant::updateOrCreate(
    ['company_id' => $company->id, 'ml_cust_id' => $custId],
    $grantData,
);
```

**O array `$grantData` vem de `mapToDb($grant)` em `SyncGrantsFromEcfDrive.php:170-194`** — é AQUI que entram os 8 campos novos.

**Loop iter:** `foreach ($data as $grant)` em `SyncGrantsFromEcfDrive.php:51`, onde `$data = $r['data'] ?? []` (linha 42) vem direto de `$service->listGrants(['page' => $page, 'limit' => $limit])` (linha 41). Cada `$grant` JÁ É o item retornado por `clientesGrants()`/`listGrants()`. Não precisa de transformação adicional.

**Recomendação acionável — expandir `mapToDb()` (linhas 185-193):**

```php
return [
    'ml_cust_id'        => $grant['custId'],
    'ml_email'          => $grant['email']    ?: null,
    'ml_phone'          => $grant['telefone'] ?: null,
    'segmento'          => $grant['segmento'] ?: null,
    // Phase 51 — 8 campos opcionais novos vindos da API expandida
    'programa'          => $grant['programa']        ?? null,
    'iniciativa'        => $grant['iniciativa']      ?? null,
    'nivel_solucion'    => $grant['nivelSolucion']   ?? null,
    'nombre_solucion'   => $grant['nombreSolucion']  ?? null,
    'parceiro'          => $grant['parceiro']        ?? null,
    'localidade'        => $grant['localidade']      ?? null,
    'medalha_fecha_in'  => ! empty($grant['medalhaFechaIn'])  ? Carbon::parse($grant['medalhaFechaIn'])->toDateString()  : null,
    'medalha_fecha_out' => ! empty($grant['medalhaFechaOut']) ? Carbon::parse($grant['medalhaFechaOut'])->toDateString() : null,
    'granted_at'        => $grantInicio?->toDateString(),
    'expires_at'        => $grantFim?->toDateString(),
    'status'            => $status,
];
```

**IMPORTANTE:** Atualizar `CompanyGrant::$fillable` para incluir as 8 colunas — sem isso, `updateOrCreate` silenciosamente ignora os campos. Verificar `app/Models/CompanyGrant.php` antes.

---

## 4. Pattern de teste de `SyncGrantsFromEcfDrive`

**Fonte:** `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php`

**Como mocka EcfDriveService:** NÃO mocka o service — mocka HTTP direto via `Http::fake` (linha 11 + helper `fakeGrants` em linhas 37-45). Isso é melhor porque exercita o service real.

**Como fornece payload:** Helper `grantPayload(array $overrides = [])` em linhas 47-61 com defaults + overrides para variar caso a caso.

**Como assert persistência:** `CompanyGrant::where(...)->first()` + `assertSame()` em colunas (linhas 96-101).

**Teste completo modelo — copiar a estrutura de `test_apply_faz_upsert_e_persiste_segmento` em linhas 89-101:**

```php
public function test_apply_persiste_campos_metadata_phase51(): void
{
    $c = $this->makeCompany(['adman_account_id' => '999']);
    $this->fakeGrants([$this->grantPayload([
        'programa'        => 'CPP',
        'iniciativa'      => 'Mentor',
        'nivelSolucion'   => 'Gold',
        'nombreSolucion'  => 'Acelerador',
        'parceiro'        => 'ECF',
        'localidade'      => 'SP',
        'medalhaFechaIn'  => '2026-01-15',
        'medalhaFechaOut' => '2026-12-31',
    ])]);

    Artisan::call('grants:sync-ecf');

    $g = CompanyGrant::where('company_id', $c->id)->first();
    $this->assertSame('CPP',  $g->programa);
    $this->assertSame('Gold', $g->nivel_solucion);
    $this->assertSame('2026-01-15', $g->medalha_fecha_in?->toDateString());
}
```

**Mockery NÃO é usado neste teste.** Setup base em linhas 24-33 (config + cleanup órfãos). `RefreshDatabase` em linha 22.

---

## 5. Pattern `StatCard` em `Grants/Index.jsx`

**Local:** `resources/js/Pages/Grants/Index.jsx:21-34`. Props: `{ label, value, color, icon: Icon, alert }`.

**Como propaga cores:** `color` é string Tailwind aplicada no `<p>` do valor (linha 29). `alert` boolean controla a borda vermelha + cor do ícone (linhas 23, 25-26). Já tem 5 chamadas com cores diferentes em linhas 196-200:

```jsx
<StatCard label="Grants ativos" value={stats.active_grants} color="text-emerald-400" icon={CheckCircle2} />
<StatCard label="Expirando em 30d" value={stats.expiring_soon} color="text-ecf-yellow" icon={Clock} alert={stats.expiring_soon > 0} />
<StatCard label="Expirados" value={stats.expired_grants} color="text-red-400" icon={XCircle} />
```

**Recomendação acionável: REUSAR sem estender.** Para buckets 7/15/30/60/90d com cores progressivas (vermelho → laranja → amarelo → cinza claro → cinza), basta passar `color` + `alert`:

```jsx
<StatCard label="Expira em 7d"  value={stats.buckets.d7}  color="text-red-400"     icon={AlertTriangle} alert={stats.buckets.d7  > 0} />
<StatCard label="Expira em 15d" value={stats.buckets.d15} color="text-orange-400"  icon={Clock}         alert={stats.buckets.d15 > 0} />
<StatCard label="Expira em 30d" value={stats.buckets.d30} color="text-ecf-yellow"  icon={Clock}         alert={stats.buckets.d30 > 0} />
<StatCard label="Expira em 60d" value={stats.buckets.d60} color="text-white/60"    icon={Clock} />
<StatCard label="Expira em 90d" value={stats.buckets.d90} color="text-white/40"    icon={Clock} />
```

Grid existente em `Grants/Index.jsx:195` (`grid-cols-2 sm:grid-cols-5`) acomoda 5 cards. Se for renderizar 6+ cards (atuais + buckets), trocar para `sm:grid-cols-5 lg:grid-cols-10` ou criar uma segunda linha com novo grid. Não precisa tocar no componente.

---

## 6. Pattern Inertia render com fallback gracioso

**Local canônico:** `app/Http/Controllers/PolosController.php:447` e `app/Http/Controllers/PolosController.php:524-528`:

```php
} catch (\Throwable $e) {
    // Defensiva: Adman fora do ar NÃO quebra /polos — cai no CSV.
    Log::warning('[Polos] Falha ao buscar faturamento Adman do mês corrente: ' . $e->getMessage());
    return [];
}
```

**Convenção do pattern (4 ocorrências confirmadas no projeto):**
1. `try` chama API externa
2. `catch (\Throwable $e)` (NÃO `Exception` — convenção do CLAUDE.md)
3. `Log::warning('[Modulo] descrição: ' . $e->getMessage())` — bracket tag em prefixo
4. Retorna array vazio / dado local / fallback
5. Comentário pt-BR explicando "defensiva"

**Recomendação acionável para `GrantController::index()` em `app/Http/Controllers/GrantController.php:13-76`:**

```php
public function index(EcfDriveService $service)
{
    // ... contagens locais já existentes (linhas 15-61) ...

    // Phase 51 — busca resumo remoto com fallback gracioso
    $resumoRemoto = null;
    try {
        $resumoRemoto = $service->grantsResumo();
    } catch (\Throwable $e) {
        // Defensiva: API ECF Drive fora do ar NÃO quebra /grants — UI usa só dados locais.
        Log::warning('[Grants] Falha ao buscar /grants/resumo: ' . $e->getMessage());
    }

    return Inertia::render('Grants/Index', [
        'stats' => [
            // ... campos locais existentes ...
            'buckets'      => $resumoRemoto['buckets']      ?? null,
            'divergencia'  => $resumoRemoto['divergencia']  ?? null,
        ],
        'grants'        => $grants,
        'expiring_soon' => $expiringSoon,
        'no_grant'      => $noGrant,
        'sync_pending'  => session('sync_pending', false),
    ]);
}
```

UI trata `stats.buckets === null` como "API offline — mostrar apenas dados locais e um Badge sutil" (a definir no UI-SPEC). Não inventar — esse comportamento merece nota na Phase de UI.

---

## Phase Requirements

| ID | Descrição | Suporte em pesquisa |
|----|-----------|----------------------|
| REQ-51-01 | Adicionar `grantsResumo()` e `grantsDistribuicao()` ao EcfDriveService | §1 — modelo `carteiraBreakdown` linha 367 |
| REQ-51-02 | Migration aditiva nullable com 8 colunas em `company_grants` | §2 — modelo Phase 20 mesma tabela |
| REQ-51-03 | Mapear 8 campos novos no `mapToDb()` do sync command | §3 — linha 170 do arquivo |
| REQ-51-04 | Atualizar `$fillable` em `CompanyGrant` (verificar) | §3 — nota final |
| REQ-51-05 | Test feature dos 8 campos persistirem via `Http::fake` | §4 — modelo linha 89 |
| REQ-51-06 | StatCards de buckets 7/15/30/60/90d em Grants/Index | §5 — reusar componente linha 21 |
| REQ-51-07 | `GrantController::index` consome `/grants/resumo` com fallback | §6 — pattern PolosController linha 524 |

## Pontos de atenção

- **`CompanyGrant::$fillable`:** verificar e atualizar antes de testar (mass-assignment silently ignora colunas faltantes).
- **`Carbon::parse` de `medalhaFechaIn/Out`:** payload exato não confirmado neste research — se a API mandar `null` ou string vazia, `! empty(...)` cobre. Se mandar formato exótico, considerar `try { Carbon::parse(...) } catch (\Throwable) { null }`.
- **Cache TTL:** `grantsResumo` = 300s (5min, alinha com `carteiraResumo` linha 311). `grantsDistribuicao` = 3600s (1h, alinha com `carteiraBreakdown` linha 367). Se o usuário quer refresh imediato após sync, considerar `Cache::forget('ecf.grants.resumo')` no fim do command — mas isso é decisão de UX, não de implementação.
- **`config/cache.php` driver:** projeto usa `database` (CLAUDE.md). `Cache::remember` funciona transparente, mas se rolar deploy em paralelo com cache:clear ainda necessário pós-deploy (memória do user em `project_ecf_drive_cache_stale.md`).
- **Grid de 5 → 10 StatCards:** se UI ficar congestionada, separar "stats principais" (linha 195-201) de "buckets de expiração" em 2 cards/grids distintos — discutir em UI-SPEC, não bloqueia execução.

## Fontes

- `app/Services/EcfDriveService.php:23-610` (Service completo — Phase 20 + 22)
- `app/Console/Commands/SyncGrantsFromEcfDrive.php:14-227` (Command completo)
- `tests/Feature/Phase20/SyncGrantsFromEcfDriveTest.php:20-225` (Test class completa)
- `resources/js/Pages/Grants/Index.jsx:21-34, 195-201` (StatCard + uso)
- `database/migrations/2026_06_05_120000_add_segmento_to_company_grants.php:10-24` (Migration aditiva mesma tabela)
- `database/migrations/2026_06_15_160000_add_ads_desligado_to_mlb_empresas.php:14-29` (Migration recente nullable)
- `app/Http/Controllers/PolosController.php:524-528` (Pattern try/catch+warning+fallback)
- `app/Http/Controllers/GrantController.php:13-76` (Controller atual a expandir)
