# Phase 134: "Meus Anúncios" — Mapa de Padrões

**Mapeado em:** 2026-08-10
**Arquivos analisados:** 24 (novos + modificados)
**Análogos encontrados:** 22 / 24 (2 sem análogo real no codebase — ver seção dedicada)

---

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dado | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `database/migrations/*_create_ml_acervo_itens_table.php` | migration | CRUD (upsert) | `2026_07_10_120000_create_ml_anuncio_rascunhos_table.php` | role-match |
| `database/migrations/*_create_ml_acervo_item_metricas_diarias_table.php` | migration | batch (série temporal) | `2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` + `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` (nomes de índice) | role-match |
| `app/Models/MlAcervoItem.php` | model | CRUD | `app/Models/MlAnuncioRascunho.php` | exact |
| `app/Models/MlAcervoItemMetricaDiaria.php` (se virar Model; pode ser só inserts em massa) | model | batch | `app/Models/MlbSyncVendasLog.php` (estrutura simples de log/série) | role-match |
| `app/Services/Mlb/Acervo/MlAcervoService.php` (nome sugerido) — camada barata (multiget + scroll) | service | batch + streaming (paginação) | `app/Services/MercadoLivreService.php` (cliente HTTP a reusar) + `app/Services/Shopee/ShopeeService.php::fetchOrdersSummary` (cursor) | role-match |
| `app/Services/Mlb/Acervo/AnuncioSaudeService.php` (nome sugerido) — port PHP do score | service | transform (cálculo puro) | `resources/js/lib/mlAnuncioRegras.js` (fonte a portar) + `app/Services/Mlb/Publicacao/MlCatalogoMetaService.php` (cache por categoria) | **sem análogo PHP** — ver seção dedicada |
| `app/Jobs/SyncMlAcervoCompanyJob.php` (camada barata, por empresa) | job | event-driven (fila) | `app/Jobs/SyncMlCompanyJob.php` | exact |
| `app/Jobs/SyncMlAcervoDetalheJob.php` (camada cara, rotação por fatia) | job | event-driven (fila) | `app/Jobs/PublicarAnuncioMlJob.php` (`ShouldBeUnique`, backoff) | role-match |
| `app/Console/Commands/SyncMlAcervo.php` (nome sugerido) | command | batch (fan-out) | `app/Console/Commands/SyncMlData.php` | exact |
| `app/Console/Commands/MlAcervoMetricasCleanup.php` (nome sugerido) | command | batch (retenção) | `app/Console/Commands/SyncVendasLogsCleanup.php` | exact |
| `app/Http/Controllers/MlbAnuncioController.php` — actions `meus()`, `atualizarAgora()`, `detalheAnuncio()` (nomes sugeridos) | controller | request-response | mesmo arquivo, `historico()` (linha 203) + `wizard()` (linha 78) | exact |
| `routes/mlb_anuncios.php` — 3 rotas novas | route | request-response | mesmo arquivo, rota `historico` (linha 50) + rota `shopee/sync` em `routes/web.php:765` | exact |
| `routes/console.php` — 1 entrada de scheduler | config | batch (agendamento) | mesmo arquivo, bloco `ml:sync` (linha 117) | exact |
| `resources/js/Pages/Mlb/MeusAnuncios.jsx` (novo) | component (página) | request-response (Inertia) | `resources/js/Pages/Mlb/AnunciosHistorico.jsx` | role-match |
| `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` (editar) | component | request-response (troca de rota) | o próprio arquivo (só editar array `MODOS`) | exact |
| `resources/js/Pages/Mlb/AnunciarML.jsx` (editar — remover bloco Rascunhos) | component | request-response | o próprio arquivo, bloco a mover linhas 2831-2976 | exact |
| Card de Rascunho redesenhado (local a `MeusAnuncios.jsx` ou `Pages/Mlb/components/RascunhoCard.jsx`) | component | request-response | `AnunciosHistorico.jsx::CardAnuncio`/`GradeCards` (linhas 33-103) + bloco atual em `AnunciarML.jsx:2877-2975` | exact |
| Modal de Detalhe do Anúncio (local a `MeusAnuncios.jsx`) | component | request-response (lazy fetch) | `Dialog`/`DialogContent` já usado em `AnunciarML.jsx:2982-2983` + gráfico `MeuPainel.jsx:729-745` (Recharts) | role-match |
| `resources/js/lib/mlAnuncioRegras.js` (NÃO editar a fórmula — só ler) | utility (fonte da régua) | transform | já existe — fonte, não destino | exact |
| `tests/Feature/Phase134/MeusAnunciosTest.php` | test | request-response | `tests/Feature/Phase86/HistoricoAnunciosTest.php` | exact |
| `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` | test | transform | nenhum teste Unit puro de ordenação por gravidade no módulo — ver seção dedicada | role-match (fraco) |
| `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` | test | transform (fixture compartilhado) | sem análogo direto (par PHP×JS) — ver seção dedicada | **sem análogo** |
| `tests/js/estrutura-anunciar-ml.test.js` | test | transform (leitura de fonte) | `tests/js/estrutura-grade-glide.test.js` | exact |

---

## Pattern Assignments

### 1. `database/migrations/*_create_ml_acervo_itens_table.php`

**Análogo:** `database/migrations/2026_07_10_120000_create_ml_anuncio_rascunhos_table.php`

**Padrão a copiar** (arquivo inteiro, 39 linhas — estrutura de migration de módulo MLB):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_anuncio_rascunhos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['rascunho', 'validado', 'publicando', 'publicado', 'erro'])->default('rascunho');
            $table->string('category_id')->nullable();
            $table->json('payload')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('ml_item_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_anuncio_rascunhos');
    }
};
```

**Nomes de índice explícitos (PEGADINHA D-07/§4 do RESEARCH — MariaDB estoura 64 chars, SQLite dos testes não pega):** copiar o padrão de `database/migrations/2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` linhas 94-99:
```php
$table->unique(['user_id', 'company_id', 'mes_referencia'], 'dcss_user_company_mes_unique');
$table->index(['mes_referencia', 'user_id'], 'dcss_mes_user_idx');
$table->index(['company_id', 'mes_referencia'], 'dcss_company_mes_idx');
```
`ml_acervo_itens` e `ml_acervo_item_metricas_diarias` já são nomes longos — QUALQUER índice composto automático provavelmente estoura 64 chars. Nomear manualmente com abreviação tipo `mai_` (ml_acervo_itens) e `maimd_` (ml_acervo_item_metricas_diarias).

**Índices que o RESEARCH §4 exige (D-07):**
- `(company_id, ml_item_id)` único — chave de upsert
- `(company_id, status)` — filtro "só ativos" (D-03)
- coluna derivada de severidade (ou compor no `ORDER BY` direto) para D-12

**Coluna de variações (D-17):** `has_variations` (bool) + `variations` (json) — mesma filosofia de `payload` (json) já usada em `ml_anuncio_rascunhos`.

---

### 2. `database/migrations/*_create_ml_acervo_item_metricas_diarias_table.php`

**Análogo:** `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php`

**Padrão a copiar** — comentário pt-BR no topo do arquivo explicando o propósito da tabela (linhas 1-5), `enum`/tipos simples, `$table->index(...)` no fim:
```php
// pt-BR: Migration que cria a tabela de log de execuções do SyncTodasVendasAdmanJob...
Schema::create('mlb_sync_vendas_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
    // ...
    $table->timestamps();
    $table->index('started_at');
});
```

**PK composta recomendada pelo RESEARCH §4** (`~45 milhões de linhas em regime`): `(company_id, ml_item_id, date)` — nomear manualmente (ver item 1). `connectNulls={false}` no gráfico (UI-SPEC seção "Modal de Detalhe") depende de a série ter buracos reais, não interpolados — não preencher dias sem coleta com zero.

---

### 3. `app/Models/MlAcervoItem.php`

**Análogo:** `app/Models/MlAnuncioRascunho.php` (arquivo inteiro, 74 linhas)

**Padrão a copiar:**
```php
class MlAnuncioRascunho extends Model
{
    protected $table = 'ml_anuncio_rascunhos';

    // ─── Status (espelham o enum da migration) ───
    public const STATUS_RASCUNHO   = 'rascunho';
    // ...

    protected $fillable = [ /* ... */ ];
    protected $casts = [
        'payload'           => 'array',
        'validation_errors' => 'array',
        'published_at'      => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
```
Aplicar o mesmo formato: `$casts['variations' => 'array']`, constantes de origem (`ORIGEM_ECF`, `ORIGEM_TIME`, `ORIGEM_LEGADO` — D-04) como `public const` na classe, não hardcoded em string solta no controller.

---

### 4. `app/Services/Mlb/Acervo/MlAcervoService.php` — camada barata (multiget + scroll)

**Análogo primário (cliente HTTP a REUSAR, não reimplementar):** `app/Services/MercadoLivreService.php`

**`get()` já cobre token/refresh/401/429** (linhas 276-323) — usar diretamente, nunca reimplementar:
```php
public function get(Company $company, string $endpoint, array $query = [], array $headers = []): array
{
    $token = $this->ensureValidToken($company);
    if (! $token) {
        throw new \RuntimeException("[MercadoLivre] Empresa {$company->id} sem token válido.");
    }
    $send = fn (MlToken $t) => Http::withToken($t->access_token)->withHeaders($headers)->get(self::API_BASE . $endpoint, $query);
    $response = $this->comRetry429(fn () => $send($token), "GET {$endpoint}");
    // 401 → tenta refresh 1x, senão RuntimeException
    // ...
    return $response->json();
}
```

**`comRetry429()` já honra `Retry-After` + backoff** (linhas 414-451) — citado explicitamente pelo `134-CONTEXT.md` Claude's Discretion como "não reimplementar":
```php
private function comRetry429(callable $send, string $contexto): \Illuminate\Http\Client\Response
{
    $tentativa = 0;
    while (true) {
        $response = $send();
        if ($response->status() !== 429) return $response;
        $tentativa++;
        if ($tentativa > self::MAX_429_RETRIES) { /* loga e devolve o 429 */ }
        $espera = $this->esperaRetry429($response, $tentativa);
        sleep($espera);
    }
}
```

**Padrão de fetch com fail-open + cache** (`fetchItemStatus()`, linhas 478-510) — molde para qualquer leitura que não pode derrubar a coleta:
```php
public function fetchItemStatus(Company $company, string $mlbId): array
{
    $cacheKey = "ml:item-status:{$mlbId}";
    return Cache::remember($cacheKey, 3600, function () use ($company, $mlbId) {
        try {
            $body = $this->get($company, "/items/{$mlbId}", ['attributes' => '...']);
            return [/* shape completo */];
        } catch (\Throwable $e) {
            Log::warning("[MercadoLivre] fetchItemStatus falhou para MLB {$mlbId} ...");
            return [/* shape completo com nulls — NUNCA propaga exception */];
        }
    });
}
```

**Paginação por cursor — NÃO existe análogo `scroll_id`/ML no codebase.** O único precedente de paginação por cursor contra API externa é `app/Services/Shopee/ShopeeService.php::fetchOrdersSummary` (linhas 415-441), que usa `cursor`/`next_cursor`/`more` da Shopee — mecanismo diferente do `scroll_id` do ML (TTL de 5min, `search_type=scan`), mas a FORMA do loop (`do { ...; $cursor = $data['next_cursor']; } while ($more && ...)`) é o precedente mais próximo de "paginação por cursor, não offset" no projeto:
```php
$orderSns = [];
$cursor   = '';
do {
    $data = $this->get($company, '/api/v2/order/get_order_list', [
        'page_size' => 100,
        'cursor'    => $cursor,
    ]);
    foreach ($data['order_list'] ?? [] as $o) { $orderSns[] = $o['order_sn']; }
    $cursor = $data['next_cursor'] ?? '';
    $more   = (bool) ($data['more'] ?? false);
} while ($more && $cursor !== '' && count($orderSns) < 2000);
```
**O código real do `scroll_id` do ML já está validado no `134-RESEARCH.md` §2 e "Code Examples"** (`search_type=scan` + `scroll_id`, loop sem delay artificial) — usar aquele trecho como fonte primária; o padrão acima só empresta a FORMA do loop `do...while`.

**Multiget de 20 ids** — já validado por chamada real no `134-RESEARCH.md` §"Code Examples" (`GET /items?ids=`, resposta em formato `[{code, body}, ...]`, item individual que falha não aborta o lote). Usar aquele trecho diretamente.

**Cache por categoria (para "ficha obrigatória completa" — sinal de peso 20 do D-22):** `app/Services/Mlb/Publicacao/MlCatalogoMetaService.php` linhas 87-94:
```php
public function atributos(string $categoryId): array
{
    return Cache::remember("ml_meta_atributos_{$categoryId}", self::TTL_META, function () use ($categoryId) {
        $resp = $this->http()->get(self::API_BASE . "/categories/{$categoryId}/attributes");
        return $resp->successful() ? (array) $resp->json() : [];
    });
}
```
`TTL_META = 604800` (7 dias) — poucas categorias por conta vs. milhares de itens, mesmo padrão a reusar para o novo cálculo de "obrigatórios completos" pós-publicação.

---

### 5. `app/Services/Mlb/Acervo/AnuncioSaudeService.php` — port PHP do score (D-10, D-22)

**ATENÇÃO — este é o arquivo mais delicado do mapeamento.** Não existe hoje NENHUM port PHP de `calcularScore()`/`analisarAnuncio()`. A fonte de verdade dos pesos é 100% JS:

**`resources/js/lib/mlAnuncioRegras.js` linhas 174-256 (`analisarAnuncio`)** — função pura, recebe um objeto `p` com os campos do formulário (`titulo`, `categoryId`, `temVariacoes`, `variacoes`, `imagemUrl`, `descricao`, `obrigatorios`, `opcionais`, `preenchido`, `pesoG/comprimentoCm/larguraCm/alturaCm`, `precoNum`...) e devolve `{ erros, avisos, ficha, score }`.

**`mlAnuncioRegras.js` linhas 263-278 (`calcularScore`) — OS 8 PESOS EXATOS a portar (menos o de descrição, por D-22):**
```javascript
function calcularScore({ titulo, categoryId, temVars, variacoes, imagemUrl, descricao, ficha, precoNum, pesoG, comprimentoCm, larguraCm, alturaCm }) {
    let pontos = 0;
    const add = (cond, peso) => { if (cond) pontos += peso; };

    add(!!titulo && titulo.trim().length >= 20, 12);              // título com substância
    add(!!categoryId, 12);                                         // categoria escolhida
    add(ficha.obrCompleta, 20);                                    // obrigatórios completos (gate forte)
    add(ficha.opcTotal === 0 || ficha.opcPct >= 60, 14);          // ficha técnica rica
    const temFoto = temVars ? (variacoes || []).some(v => (v.picture_ids || []).length) : !!imagemUrl;
    add(temFoto, 16);                                              // ao menos 1 foto
    add(!!descricao && descricao.trim().length >= 120, 14);       // descrição decente — FICA DE FORA no PHP (D-22)
    add(!!(pesoG && comprimentoCm && larguraCm && alturaCm), 8);  // dimensões completas (frete)
    add(precoNum != null && precoNum > 0, 4);                     // preço definido

    return Math.min(100, pontos);
}
```

**Mapeamento campo-a-campo já feito pelo `134-RESEARCH.md` §5 (tabela completa)** — reusar aquela tabela como especificação de onde cada sinal vem no payload do multiget:

| Sinal (peso) | Fonte no payload ML | Onde cachear |
|---|---|---|
| Título ≥20 chars (12) | `title` | — direto |
| Categoria escolhida (12) | `category_id` | — direto |
| Ficha obrigatória completa (20) | `attributes[]` do item + `GET /categories/{id}/attributes` | cache por categoria (`MlCatalogoMetaService::atributos()`) |
| Ficha opcional ≥60% (14) | idem | idem |
| Ao menos 1 foto (16) | `pictures[]` / `variations[].picture_ids` | — direto |
| ~~Descrição ≥120 chars (14)~~ | **FORA (D-22)** — exige `GET /items/{id}/description`, 1 call/item | — |
| Dimensões completas (8) | `PACKAGE_HEIGHT/LENGTH/WEIGHT/WIDTH` em `attributes[]` | — direto |
| Preço definido (4) | `price` | — direto |

**Onde os pesos devem viver "num lugar citável pelos dois scorers" (D-22, texto literal do CONTEXT.md):** NÃO existe hoje nenhum mecanismo de config compartilhada entre PHP e JS neste projeto — busca confirmou (`grep` por `json_decode(file_get_contents` e por `.json` em `resources/js/lib/`) que a convenção estabelecida é a documentada no `CLAUDE.md`: *"Domain constants defined as `public const` on the Model class — Mirrored as plain JS objects in the corresponding React page file — No shared enum type between PHP and JS — kept in sync manually."* Ou seja: **não há um analog de "single source of truth" técnico a copiar** — o projeto sempre espelhou manualmente. Para D-22, isso significa que o planner precisa decidir explicitamente entre (a) manter a duplicação manual de sempre, mas com comentário cruzado citando o arquivo/linha do outro lado (mínimo), ou (b) introduzir um mecanismo novo (ex.: JSON lido por ambos) — o que seria a PRIMEIRA vez que o projeto faz isso. **O teste de regressão do fixture compartilhado (`NotaEcfFecharComContaTest.php`) é o que really garante a concordância, não a estrutura do arquivo de pesos.**

**Precedente da "pegadinha" que motivou D-22** (não é código a copiar, é o aviso a não repetir): `.planning/learnings/desempenho-bonificacao.md` — caso `nps_medio` ≠ `pontos_componentes.nps`, onde o card não fechava com a própria conta por duas implementações divergentes da mesma métrica.

---

### 6. `app/Jobs/SyncMlAcervoCompanyJob.php` — camada barata, por empresa

**Análogo:** `app/Jobs/SyncMlCompanyJob.php` (arquivo inteiro, 52 linhas)

```php
class SyncMlCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly Company $company,
        public readonly ?string $date = null,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900]; // 1min, 5min, 15min
    }

    public function handle(MercadoLivreService $ml): void
    {
        $date = $this->date ?? now()->toDateString();
        $ml->syncCompany($this->company, $date);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncMlCompanyJob] Falha definitiva empresa {$this->company->id} ({$this->company->name}): {$e->getMessage()}");
        AdmanSyncLog::create([/* ... */]);
    }
}
```

**PEGADINHA já documentada no `134-RESEARCH.md` "Pitfall 1"** (e no learning `project_polos_sync_job_retry_after_bug.md`): `timeout`/`tries`/`retry_after` da fila precisam ser dimensionados para o pior caso (66.747 itens). O RESEARCH recomenda **separar camada barata (job rápido) de camada cara (job de rotação) em jobs DIFERENTES** — não um job monolítico. Este job cobre só a camada barata.

---

### 7. `app/Jobs/SyncMlAcervoDetalheJob.php` — camada cara, rotação por fatia (D-23)

**Análogo:** `app/Jobs/PublicarAnuncioMlJob.php` (arquivo inteiro, 88 linhas) — pelo padrão `ShouldBeUnique` + backoff, não pelo domínio:

```php
class PublicarAnuncioMlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function uniqueId(): string { return (string) $this->rascunhoId; }

    public function uniqueFor(): int { return 300; } // TTL do lock > timeout + backoff máximo

    public function backoff(): array { return [30, 120, 300]; }

    public function handle(MlPublicacaoService $service): void { /* ... */ }

    public function failed(\Throwable $e): void { /* marca estado de erro p/ a UI */ }
}
```
`N` da rotação (D-23) é parâmetro do comando, não constante — ver item 8.

---

### 8. `app/Console/Commands/SyncMlAcervo.php` — fan-out diário

**Análogo:** `app/Console/Commands/SyncMlData.php` (arquivo inteiro, 149 linhas)

**Padrão de signature + fan-out com delay escalonado** (linhas 17-24, 66-93):
```php
protected $signature = 'ml:sync
    {--company= : Sincroniza apenas uma empresa específica (ID)}
    {--date=    : Data específica YYYY-MM-DD (padrão: ontem — ML é D-1)}
    ...';

// Fan-out: todas as empresas com token ML ativo
$companies = Company::query()
    ->where('active', true)
    ->whereHas('mlToken', fn($q) => $q->where('status', 'active'))
    ->with('mlToken')
    ->get();

foreach ($companies as $i => $company) {
    // Delay de 2s entre jobs — ML tem rate limit de 1500 req/min
    SyncMlCompanyJob::dispatch($company, $date)->delay(now()->addSeconds($i * 2));
}
```
O comando novo precisa de um `{--n=}` (tamanho da fatia da rotação D-23) e reusar exatamente esse `whereHas('mlToken', ...)` de escopo — não reinventar o filtro de "quem tem token ativo".

---

### 9. `app/Console/Commands/MlAcervoMetricasCleanup.php` — retenção da série diária

**Análogo:** `app/Console/Commands/SyncVendasLogsCleanup.php` (arquivo inteiro, 52 linhas) — copiar a FORMA inteira:

```php
class SyncVendasLogsCleanup extends Command
{
    protected $signature = 'mlb:sync-vendas-logs-cleanup
                            {--stale-hours=1 : Marca como failed logs presos em running há mais de N horas}
                            {--keep-days=30 : Remove logs encerrados com mais de N dias}';

    public function handle(): int
    {
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $keepDays   = max(1, (int) $this->option('keep-days'));

        $removidos = MlbSyncVendasLog::whereIn('status', [/* ... */])
            ->where('started_at', '<', now()->subDays($keepDays))
            ->delete();

        $this->info("Logs antigos removidos (> {$keepDays} dias): {$removidos}");
        return self::SUCCESS;
    }
}
```
Para a série diária (D-07b), a retenção é por `date` (não `started_at`) e ~90 dias (não 30) — `--keep-days=90` como default. `delete()` direto, sem soft delete — mesma disciplina.

**Agendamento** — copiar o bloco de `routes/console.php` linhas 154-159:
```php
Schedule::command('mlb:sync-vendas-logs-cleanup')
    ->dailyAt('03:20')
    ->name('cleanup-sync-vendas-logs')
    ->withoutOverlapping();
```

---

### 10. `routes/console.php` — agendamento do `SyncMlAcervo`

**Análogo:** bloco `ml:sync`, linhas 115-120:
```php
// Sync direto ML (D-1) — roda às 11:05 logo após o adman:sync, enquanto migração está em curso
Schedule::command('ml:sync')
    ->dailyAt('11:05')
    ->name('sync-ml-direct')
    ->withoutOverlapping();
```
`134-RESEARCH.md` §7 já resolveu o horário: entrar entre 11:05 (`ml:sync`) e 11:20 (`adman:sync-margem`), ou depois — a critério do planner. Copiar `->withoutOverlapping()` sempre.

---

### 11. `app/Http/Controllers/MlbAnuncioController.php` — nova action `meus()`

**Análogo:** `historico()`, linhas 203-290 do mesmo arquivo (topo idêntico ao pedido pelo CONTEXT.md):
```php
public function historico(Request $request, Company $company)
{
    // Só empresas com conta ML conectada (mesma trava do wizard e da grade)
    $company->loadMissing('mlToken');
    abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

    $busca = trim((string) $request->query('busca', ''));

    $publicados = MlAnuncioRascunho::where('company_id', $company->id)
        ->where('status', MlAnuncioRascunho::STATUS_PUBLICADO)
        ->when($busca !== '', function ($q) use ($busca) {
            $q->where(function ($s) use ($busca) {
                $s->where('payload->title', 'like', "%{$busca}%")
                  ->orWhere('sku_origem', 'like', "%{$busca}%");
            });
        })
        ->orderByDesc('published_at')
        ->orderByDesc('id')
        ->get();

    // ... paginação via LengthAwarePaginator ...

    return Inertia::render('Mlb/AnunciosHistorico', [
        'empresa'  => ['id' => $company->id, 'nome' => $company->name],
        'grupos'   => $gruposPagina,
        'resumo'   => [/* ... */],
        'filtros'  => ['busca' => $busca],
    ]);
}
```
**PEGADINHA de segurança já documentada no comentário original** (linhas 218-222): todo `orWhere` de busca precisa estar AGRUPADO dentro de um `where(function ...)`, senão sobe ao topo do WHERE e vaza escopo de empresa — o teste `busca_nao_fura_o_escopo_de_empresa_nem_de_status()` em `HistoricoAnunciosTest.php` (linhas 75-89) trava exatamente isso.

**Ordenação por gravidade (D-12)** não tem análogo direto no controller — é lógica nova. Mas o PADRÃO de "computar no PHP para permitir `ORDER BY`" já está resolvido pela decisão de onde fica a Nota ECF (item 5) — a query deve ordenar por colunas persistidas, nunca por valor calculado no request.

---

### 12. `app/Http/Controllers/MlbAnuncioController.php` — nova action `atualizarAgora()` (botão "Atualizar agora", D-05)

**Análogo mais próximo (padrão exato: enfileira job de UMA empresa, feedback via flash, não bloqueia o request):** `app/Http/Controllers/ShopeeOAuthController.php::sync()`, linhas 306-317:
```php
public function sync(Company $company)
{
    if (! $company->shopeeToken || $company->shopeeToken->status !== 'active') {
        return back()->with('error', "{$company->name} não tem conexão Shopee ativa — gere o link e conecte a loja primeiro.");
    }

    SyncShopeeCompanyJob::dispatch($company->id);

    Log::info("[Shopee] Sync manual enfileirado empresa {$company->id} ({$company->name}) por " . auth()->user()?->name);

    return back()->with('success', "Sincronização da {$company->name} iniciada — os números aparecem em 1–2 min (atualize a página).");
}
```
**Rota correspondente** em `routes/web.php:765`:
```php
Route::post('/companies/{company}/shopee/sync', [ShopeeOAuthController::class, 'sync'])->name(...);
```
**Copiar esta forma, NÃO** a de `MercadoLivreOAuthController::syncNow()` (linha 161) — aquele é SÍNCRONO (`$this->ml->syncCompany()` direto no request), o que viola D-05 explicitamente ("nenhuma chamada síncrona ao ML no caminho do request"). O `sync()` da Shopee é o único no módulo que dispara 1 job para 1 empresa a partir de um clique e devolve feedback sem bloquear — exatamente o contrato do botão "Atualizar agora".

---

### 13. `routes/mlb_anuncios.php` — 3 rotas novas

**Análogo:** o próprio arquivo — grupo já existente, padrão de comentário + nome de rota (linhas 23-50):
```php
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('mlb/anuncios')
    ->name('mlb.anuncios.')
    ->group(function () {
        // ─── Momento 2: wizard com empresa fixada (âncora = company com ml_token) ───
        Route::get('/wizard/{company}', [MlbAnuncioController::class, 'wizard'])->name('wizard');
        // ...
        Route::get('/historico/{company}', [MlbAnuncioController::class, 'historico'])->name('historico');
    });
```
Rota nova: `Route::get('/meus/{company}', [MlbAnuncioController::class, 'meus'])->name('meus')` — **dentro do MESMO grupo** (`role:admin`, nenhum gate novo — D-15). Rota de "Atualizar agora": `Route::post('/meus/{company}/atualizar', [MlbAnuncioController::class, 'atualizarAgora'])->name('meus.atualizar')`. Rota do detalhe lazy (modal, A10 do UI-SPEC): `Route::get('/meus/{company}/{item}/detalhe', ...)->name('meus.detalhe')`.

---

### 14. `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` — 4ª aba

**Arquivo inteiro já lido (58 linhas)** — editar só o array `MODOS` (linhas 15-20):
```javascript
const MODOS = [
    { chave: 'individual', label: 'Individual', rota: 'mlb.anuncios.wizard',    Icone: FileText },
    { chave: 'massa',      label: 'Em massa',    rota: 'mlb.anuncios.massa',     Icone: Grid3x3 },
    { chave: 'historico',  label: 'Histórico',   rota: 'mlb.anuncios.historico', Icone: History },
];
```
UI-SPEC já define o array final (seção "1. Barra de abas nível 1"):
```javascript
const MODOS = [
    { chave: 'meus',       label: 'Meus Anúncios', rota: 'mlb.anuncios.meus',      Icone: Gauge },
    { chave: 'individual', label: 'Individual',     rota: 'mlb.anuncios.wizard',    Icone: FileText },
    { chave: 'massa',      label: 'Em massa',       rota: 'mlb.anuncios.massa',     Icone: Grid3x3 },
    { chave: 'historico',  label: 'Histórico',      rota: 'mlb.anuncios.historico', Icone: History },
];
```
Import de `Gauge` já existe em `lucide-react` (usado em `AnunciarML.jsx:2767`). Resto do componente (troca de rota via `router.get`, estilo pill ativa/inativa) **não muda**.

---

### 15. `resources/js/Pages/Mlb/AnunciarML.jsx` — remover bloco "Rascunhos recentes"

**Bloco a REMOVER (mover para `MeusAnuncios.jsx`), linhas 2831-2976** — início e fim exatos:
```jsx
{/* Rascunhos recentes desta empresa — BULK-01/04: seleção múltipla + lote */}
{rascunhos.length > 0 && (
    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
        {/* ... checkbox "selecionar todos" + botão "Publicar lote (N)" + errosLote + <ul> de rascunhos ... */}
    </div>
)}
```
**Bloco que FICA intacto (D-16), linhas 2761-2810** — "Saúde do anúncio", NÃO TOCAR:
```jsx
{categoryId && (
    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
        <p className="...">
            <Gauge size={13} /> Saúde do anúncio
        </p>
        {/* score + erros + avisos de analisarAnuncio() */}
    </div>
)}
```
**Funções/estado a mover junto do bloco** (não deixar código morto em `AnunciarML.jsx`):
- `const [selecionados, setSelecionados]` (linha 1128)
- `const [publicandoLote, setPublicandoLote]` (linha 1129)
- `const [errosLote, setErrosLote]` (linha 1130)
- `rascunhosSelecionaveis` (linha 1500), `toggleSelecionado`/`toggleTodos` (linhas 1506-1521), `publicarLote` (linhas 1524-1540)
- `STATUS_BADGE`/`STATUS_LABEL` (linhas 15-25) — **migram junto**, mesmas classes (UI-SPEC seção 9 confirma: "não redesenhar as cores de status").

**Teste estrutural de regressão (D-16):** ver item 20 abaixo — `estrutura-anunciar-ml.test.js` deve confirmar que o texto "Saúde do anúncio" continua na fonte e que o bloco de Rascunhos (`toggleTodos`, por exemplo) NÃO está mais lá.

---

### 16. Card de Rascunho redesenhado (D-14) — novo componente

**Análogo do grid + card (a estrutura visual):** `resources/js/Pages/Mlb/AnunciosHistorico.jsx`, `GradeCards`/`CardAnuncio` (linhas 33-103):
```jsx
function GradeCards({ itens, clonando, onSemelhante }) {
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {itens.map((a) => <CardAnuncio key={a.id} a={a} ... />)}
        </div>
    );
}
```

**Análogo do conteúdo/dados a exibir (foto, título, categoria, tier, status — o que o usuário pediu literalmente):** o próprio bloco que está sendo removido, `AnunciarML.jsx` linhas 2877-2975 — reaproveitar os DADOS (`STATUS_BADGE`, `STATUS_LABEL`, `rotuloTier`), mas o CONTAINER/alvo-de-clique muda conforme UI-SPEC seção 9 (card inteiro clicável, não mais o botão "Abrir" de 10px em `AnunciarML.jsx:2942-2949`):
```jsx
<button
    type="button"
    onClick={() => abrirRascunho(r)}
    title="Abrir este rascunho para editar"
    className="flex items-center gap-1 rounded-md border border-white/10 bg-white/[0.03] px-2 py-0.5 text-[10px] font-medium text-white/70 ..."
>
    <Pencil size={10} /> Abrir
</button>
```
Este é o botão de 10px que o usuário reclamou explicitamente ("Do jeito que está não gostei") — o UI-SPEC já resolve isso (seção 9, card inteiro como alvo). O padrão HTML de "irmãos, não aninhados" (checkbox + botão excluir fora do `<button>` principal) segue o precedente documentado em `AnunciosHistorico.jsx::BlocoLote` (comentário linhas 106-108): botão aninhado é HTML inválido.

---

### 17. Modal de Detalhe do Anúncio — novo componente

**Análogo do Dialog:** `AnunciarML.jsx` linhas 2982-2983 (override de largura já em produção):
```jsx
<Dialog open={confirmPublicar} onOpenChange={setConfirmPublicar}>
    <DialogContent className="max-w-sm">
```
UI-SPEC pede `className="max-w-2xl"` (mesmo padrão de override).

**Análogo do gráfico Recharts:** `resources/js/Pages/Dashboard/MeuPainel.jsx` linhas 729-745 (citado tanto pelo RESEARCH quanto pelo UI-SPEC) — `ResponsiveContainer` + `LineChart` + `tick={{ fontSize: 10, fill: 'rgba(255,255,255,0.4)' }}` já é o padrão de estilo de eixo usado no projeto; o UI-SPEC já fornece o JSX completo do gráfico novo (seção "Modal de Detalhe do Anúncio", item 4) — usar aquele bloco diretamente, incluindo `connectNulls={false}` (obrigatório, não estético — a rotação D-23 deixa buracos reais).

**Análogo do fetch lazy ao abrir:** `usarComoTemplate()` em `AnunciarML.jsx` linhas 1544-1557 — mesmo padrão de `window.axios.post`/`.get` com `try/finally` controlando estado de loading local, sem vir no payload da página.

---

### 18. `app/Console/Commands/SyncMlAcervo.php` × Job — separação camada barata/cara

Não há análogo de "job que roda 1/N do acervo por execução" no codebase — é lógica nova pedida por D-23. O precedente de PARÂMETRO de comando controlando volume por execução é `SyncVendasLogsCleanup` (`--stale-hours`, `--keep-days`) — mesma disciplina de opção nomeada com default sensato, não constante hardcoded.

---

### 19. `tests/Feature/Phase134/MeusAnunciosTest.php`

**Análogo:** `tests/Feature/Phase86/HistoricoAnunciosTest.php` (arquivo inteiro, 276 linhas) — copiar a ESTRUTURA inteira:
- `use RefreshDatabase;` + `Http::fake()` no `setUp()` — nunca ML real
- `@group phase86` → `@group phase134`
- Helpers privados `criarFixture()` (cria `Company` + `MlToken` ativo + `MlbEmpresa`) e `criarAdmin()` — reusar quase palavra-por-palavra
- Teste de escopo por empresa (`*_nao_vaza_anuncio_de_outra_empresa`)
- Teste de 404 sem `MlToken` (`empresa_sem_conta_ml_devolve_404`)
- Teste de 403 para não-admin (`consultor_nao_acessa_o_historico`)

**Adaptações específicas da Phase 134 (D-01, D-04, D-05, D-08, D-09):**
- D-05 exige um teste NOVO que o Phase 86 não tinha: `Http::fake()->assertNothingSent()` dentro do teste da rota GET — a tela não pode fazer NENHUMA chamada síncrona ao ML.
- D-04 (selo de origem) precisa de fixture com os 3 casos: item com `MlAnuncioRascunho.ml_item_id` batendo, item com `Publicacao.mlb_code` batendo, item sem nenhum dos dois.
- D-08 (degradação graciosa) precisa de fixture com `collected_at` velho — nunca tela em branco.

---

### 20. `tests/js/estrutura-anunciar-ml.test.js`

**Análogo:** `tests/js/estrutura-grade-glide.test.js` (arquivo inteiro, lido integralmente) — mesmo mecanismo `lerSemComentarios()`:
```javascript
import { lerSemComentarios } from './_fonte.js';

const fonte = lerSemComentarios('resources/js/Pages/Mlb/AnunciarML.jsx');

test('bloco "Saúde do anúncio" continua no aside após a Fase 134', () => {
    assert.match(fonte, /Saúde do anúncio/);
});

test('bloco de Rascunhos recentes saiu do wizard', () => {
    assert.doesNotMatch(fonte, /Rascunhos recentes/);
    assert.doesNotMatch(fonte, /toggleTodos/);
});
```
`lerSemComentarios()` (helper em `tests/js/_fonte.js`, linhas 19-31) remove comentários pt-BR antes de gravar — evita falso-positivo porque a prosa do próprio teste cita os identificadores.

---

### 21. `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` + par JS

Sem análogo direto no codebase (é a PRIMEIRA vez que o projeto precisa comparar um cálculo PHP com um cálculo JS sobre o mesmo fixture). Estrutura recomendada pelo `134-RESEARCH.md` §5, item 3: um fixture JSON único, consumido por `node --test` (molde: `tests/js/mlAnuncioRegras.test.js`, que já importa `analisarAnuncio` e monta objetos-base tipo `anuncioBase()` linhas 23-43) e por PHPUnit (`AnuncioSaudeService` novo). O padrão de "objeto-base + overrides" de `mlAnuncioRegras.test.js`:
```javascript
function anuncioBase(over = {}) {
    return {
        titulo: 'Tênis de corrida masculino leve confortável',
        categoria: { settings: { /* ... */ } },
        categoryId: 'MLB1234',
        // ...
        ...over,
    };
}
```
é reaproveitável como gerador do fixture compartilhado — só trocar o shape de "formulário do wizard" para "payload de item do multiget ML".

---

## Shared Patterns

### Cliente HTTP com token/refresh/retry — NÃO REIMPLEMENTAR
**Fonte:** `app/Services/MercadoLivreService.php` — `get()`/`post()`/`put()`/`comRetry429()`
**Aplicar a:** `MlAcervoService` (item 4) inteiro — toda chamada nova (multiget, scroll, `price_to_win`, `visits`) passa por `$ml->get($company, $endpoint, $query)`.

### Fail-open em coleta externa — nunca propagar exception que derrube a tela/job
**Fonte:** `MercadoLivreService::fetchItemStatus()` linhas 478-510 — `try { ... } catch (\Throwable $e) { Log::warning(...); return [shape com nulls]; }`
**Aplicar a:** `MlAcervoService` (D-08 — degradação graciosa) e ao job de camada cara (D-23 — item "não avaliado" em vez de erro).

### Cache por categoria (poucos valores, reusados por milhares de itens)
**Fonte:** `MlCatalogoMetaService::atributos()`/`categoria()` — `Cache::remember($chave, self::TTL_META, fn () => ...)`, TTL 7 dias
**Aplicar a:** `AnuncioSaudeService` (sinal "ficha obrigatória completa", peso 20).

### Fan-out por empresa com delay escalonado
**Fonte:** `SyncMlData.php` linhas 83-90 — `foreach ($companies as $i => $company) { Job::dispatch(...)->delay(now()->addSeconds($i * 2)); }`
**Aplicar a:** `SyncMlAcervo` (camada barata, diária) — 2s por posição, mesmo padrão já em produção.

### Botão de sync manual de 1 empresa — enfileira, não bloqueia
**Fonte:** `ShopeeOAuthController::sync()` linhas 306-317 — `Job::dispatch($company->id); return back()->with('success', '...')`
**Aplicar a:** `atualizarAgora()` (D-05).

### Comando de retenção com opções nomeadas, sem soft delete
**Fonte:** `SyncVendasLogsCleanup.php` — `--stale-hours`/`--keep-days`, `->delete()` direto
**Aplicar a:** `MlAcervoMetricasCleanup` (D-07).

### `abort_unless($company->mlToken !== null, 404, ...)` — trava de escopo por empresa
**Fonte:** repetida em `wizard()` (linha 82), `massa()` (linha 145), `historico()` (linha 207)
**Aplicar a:** TODA action nova do controller (`meus()`, `atualizarAgora()`, `detalheAnuncio()`) — nenhuma exceção.

### Nome de índice explícito em migration (MariaDB >64 chars)
**Fonte:** `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php` linhas 94-99
**Aplicar a:** as 2 migrations novas (item 1/2) — `ml_acervo_itens`/`ml_acervo_item_metricas_diarias` já são nomes longos.

---

## No Analog Found

| Arquivo | Papel | Fluxo de dado | Razão |
|---|---|---|---|
| `app/Services/Mlb/Acervo/AnuncioSaudeService.php` (port PHP do score) | service | transform | Nenhum port PHP de regra JS existe hoje no projeto. A fonte de verdade é 100% JS (`mlAnuncioRegras.js`); o `CLAUDE.md` documenta que o projeto historicamente espelha manualmente (não há mecanismo técnico de config compartilhada PHP↔JS a copiar). O planner deve tratar isso como escolha explícita de arquitetura, não como "seguir um padrão existente" — ver item 5 acima para o mapeamento campo-a-campo já feito pelo RESEARCH. |
| `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` (+ par JS) | test | transform (fixture compartilhado) | Primeira vez que o projeto precisa de um teste de concordância PHP×JS sobre o mesmo fixture. `tests/js/mlAnuncioRegras.test.js` fornece o padrão de "objeto-base + overrides" reaproveitável (item 21), mas o mecanismo de comparação cross-linguagem em si é novo. |
| Paginação `scroll_id` (TTL 5min, `search_type=scan`) contra a API do ML | (parte do service) | streaming/batch | Não existe no codebase. O precedente mais próximo é a paginação por `cursor` da Shopee (`ShopeeService::fetchOrdersSummary`, item 4) — mecanismo diferente (sem TTL, sem `scroll_id`). O código real e testado do `scroll_id` do ML já está no `134-RESEARCH.md` §2/"Code Examples" — usar aquele trecho como fonte primária, não o padrão Shopee (que só empresta a FORMA do loop). |

---

## Metadata

**Diretórios pesquisados:** `app/Http/Controllers/`, `app/Services/`, `app/Services/Mlb/`, `app/Services/Shopee/`, `app/Jobs/`, `app/Console/Commands/`, `app/Models/`, `database/migrations/`, `routes/`, `resources/js/Pages/Mlb/`, `resources/js/Pages/Dashboard/`, `resources/js/lib/`, `resources/js/Components/ui/`, `tests/Feature/Phase86/`, `tests/js/`
**Arquivos lidos integralmente ou em trechos direcionados:** 24
**Data de extração:** 2026-08-10
