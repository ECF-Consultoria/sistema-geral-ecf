---
phase: 17
phase_name: Coleta de Dados ML (Fase 1 — sem IA)
mapped: 2026-06-01
files_analyzed: 12
analogs_found: 12
---

# Phase 17: Coleta de Dados ML — Mapa de Padrões

**Mapeado em:** 2026-06-01
**Arquivos analisados:** 12 novos/modificados
**Analogs encontrados:** 12 / 12

---

## Classificação de Arquivos

| Arquivo novo/modificado | Role | Data Flow | Analog mais próximo | Qualidade |
|------------------------|------|-----------|---------------------|-----------|
| `app/Services/MlColetaService.php` | service | request-response | `app/Services/MercadoLivreService.php` | exact |
| `app/Services/MlKeywordMinerService.php` | service | transform | `app/Support/CobrancaCalculator.php` (stateless, PHP puro) | role-match |
| `app/Jobs/MlbColetaJob.php` | job | event-driven | `app/Jobs/SyncMlCompanyJob.php` | exact |
| `app/Models/MlbColeta.php` | model | CRUD | `app/Models/MlbSyncVendasLog.php` | exact |
| `database/migrations/*_create_mlb_coletas_table.php` | migration | — | `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php` | exact |
| `app/Http/Controllers/MlbController.php` (novas actions) | controller | request-response | `app/Http/Controllers/MlbController.php` (actions existentes) | exact |
| `routes/web.php` (rotas `mlb.coleta.*`) | route | — | `routes/web.php` bloco `mlb.*` linhas 240–291 | exact |
| `app/Support/Permissions.php` (nova key `mlb.coleta`) | config | — | `app/Support/Permissions.php` bloco `MLB_*` | exact |
| `resources/js/Pages/Mlb/Coleta.jsx` | component | request-response + polling | `resources/js/Pages/Grants/Index.jsx` (polling) + `resources/js/Pages/Mlb/Historico.jsx` (layout) | exact |
| `resources/js/Layouts/AppLayout.jsx` (novo nav item) | config | — | `resources/js/Layouts/AppLayout.jsx` bloco `NAV_ITEMS` linhas 44–54 | exact |
| `tests/Unit/Ml*.php` (3 arquivos) | test | — | `tests/Unit/CobrancaCalculatorTest.php` | role-match |
| `tests/Feature/Phase17ColetaTest.php` | test | — | `tests/Feature/Phase14MlbControllerFiltroTest.php` | role-match |

---

## Padrões por Arquivo

---

### `app/Services/MlColetaService.php` (service, request-response)

**Analog:** `app/Services/MercadoLivreService.php`

**Imports** (linhas 1–14):
```php
<?php

namespace App\Services;

use App\Models\MlbColeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
```

**Constantes de API** (análogo às linhas 22–25 do analog):
```php
private const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';
private const API_BASE  = 'https://api.mercadolibre.com';
private const SITE_ID   = 'MLB';
```

**Padrão de cache de app token** — copiar de `MercadoLivreService::resolveAdvertiserId()` linhas 438–459 com adaptação para `client_credentials`:
```php
// Fonte: padrão Cache::remember de MercadoLivreService::resolveAdvertiserId() (linhas 438-459)
private function mlAppToken(): string
{
    return Cache::remember('ml_app_token_coleta', function () {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type'    => 'client_credentials',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('[MLB Coleta] Falha ao obter app token: ' . $response->body());
        }

        $data      = $response->json();
        $expiresIn = (int) ($data['expires_in'] ?? 21600);
        // Guarda o TTL dinâmico junto ao token para que Cache::remember use o valor correto
        Cache::put('ml_app_token_coleta_ttl', $expiresIn, now()->addSeconds($expiresIn));
        return $data['access_token'];
    }, now()->addSeconds((int) (Cache::get('ml_app_token_coleta_ttl', 21600) - 300)));
}
```
> Nota: o padrão mais simples e seguro para produção é usar `Cache::put()` fora do `remember`, com TTL = `expires_in - 300`. Ver RESEARCH.md §Pitfall 1.

**Padrão de GET autenticado + tratamento 429** — copiar de `MercadoLivreService::get()` linhas 271–317 com substituição do user token por app token:
```php
// Fonte: MercadoLivreService::get() linhas 271-317
private function mlGet(string $endpoint, array $query = []): array
{
    $token    = $this->mlAppToken();
    $response = Http::withToken($token)
        ->timeout(15)
        ->get(self::API_BASE . $endpoint, $query);

    if ($response->status() === 429) {
        $retryAfter = (int) ($response->header('Retry-After') ?? 2);
        sleep(max(1, min($retryAfter, 30)));
        throw new \RuntimeException("[MLB Coleta] Rate limit (429) em {$endpoint}");
    }

    if ($response->status() === 401 || $response->status() === 403) {
        // Best-effort para endpoints opcionais (questions, reviews) — lançar para o caller tratar
        throw new \RuntimeException("[MLB Coleta] Acesso negado ({$response->status()}) em {$endpoint}: " . $response->body());
    }

    if (! $response->successful()) {
        throw new \RuntimeException(
            "[MLB Coleta] Erro {$response->status()} em {$endpoint}: " . $response->body()
        );
    }

    return $response->json() ?? [];
}
```

**Padrão de tratamento de erro best-effort no loop dos top-5** — copiar de `MercadoLivreService::syncCompany()` linhas 702–711 (bloco try/catch best-effort):
```php
// Fonte: MercadoLivreService::syncCompany() linhas 702-711
foreach ($topCinco as $itemId) {
    try {
        $detalhe     = $this->mlGet("/items/{$itemId}");
        $descricao   = $this->mlGet("/items/{$itemId}/description");
        $perguntas   = $this->fetchPerguntas($itemId); // best-effort interno
    } catch (\Throwable $e) {
        Log::warning("[MLB Coleta] Falha ao buscar detalhe item {$itemId}: {$e->getMessage()}");
        // Continua o lote — falha de 1 item não derruba a coleta
    }
    usleep(200000); // 200 ms entre chamadas (D-03 backoff)
}
```

**Padrão de logging** — copiar tag `[MercadoLivre]` substituindo por `[MLB Coleta]`:
```php
// Fonte: MercadoLivreService linhas 696, 753
Log::info("[MLB Coleta] Iniciando pipeline coleta {$coleta->id} keyword='{$coleta->keyword}'");
Log::info("[MLB Coleta] Concluído coleta {$coleta->id} em {$segundos}s");
Log::warning("[MLB Coleta] Falha ao buscar item {$id}: {$e->getMessage()}");
```

---

### `app/Services/MlKeywordMinerService.php` (service, transform)

**Analog:** `app/Support/CobrancaCalculator.php` (stateless helper PHP puro, sem DI externa)

**Imports** (sem dependências Laravel — PHP puro):
```php
<?php

namespace App\Services;

/**
 * Mineração estatística de keywords de títulos de produtos ML.
 * Implementado em PHP puro — sem intl/Normalizer (não disponível no XAMPP 8.2.12).
 * Usa mb_strtolower + strtr (mapa de acentos) para normalização pt-BR.
 */
class MlKeywordMinerService
{
```

**Padrão de constante estática** — igual a `CobrancaCalculator` e `MlbSyncVendasLog::STATUS_*`:
```php
// Fonte: padrão de const nos Models (MlbSyncVendasLog linhas 16-18) e CobrancaCalculator
private const ACCENT_MAP = [
    'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
    // ... (ver RESEARCH.md §Padrão 3 para lista completa)
];

private const STOPWORDS_PT = [
    'o','a','os','as','um','uma','uns','umas',
    'de','do','da','dos','das','em','no','na','nos','nas',
    // ... (ver RESEARCH.md §Exemplos para lista ~120 palavras)
];
```

**Core: tokenizar e normalizar** — PHP puro, padrão documentado em RESEARCH.md §Padrão 3:
```php
public function normalizarToken(string $token): string
{
    return strtr(mb_strtolower($token, 'UTF-8'), self::ACCENT_MAP);
}

public function tokenizar(string $texto): array
{
    $normalizado = $this->normalizarToken($texto);
    $limpo  = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalizado);
    $tokens = preg_split('/\s+/u', trim($limpo), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($tokens, fn($t) =>
        mb_strlen($t) > 2 && ! in_array($t, self::STOPWORDS_PT, true)
    ));
}

public function ngrams(array $tokens, int $n): array
{
    $result = [];
    $count  = count($tokens);
    for ($i = 0; $i <= $count - $n; $i++) {
        $result[] = implode(' ', array_slice($tokens, $i, $n));
    }
    return $result;
}
```

**Core: ranking de frequência** — `array_count_values` + `arsort`, PHP nativo:
```php
public function rankingKeywords(array $titulos, array $trends = []): array
{
    $todos = [];
    foreach ($titulos as $titulo) {
        $tokens = $this->tokenizar($titulo);
        $todos  = array_merge($todos, $tokens);                          // unigramas
        $todos  = array_merge($todos, $this->ngrams($tokens, 2));        // bigramas
        $todos  = array_merge($todos, $this->ngrams($tokens, 3));        // trigramas
    }
    $freq = array_count_values($todos);
    arsort($freq);
    $resultado = [];
    foreach (array_slice($freq, 0, 30, true) as $termo => $contagem) {
        $resultado[] = [
            'termo'       => $termo,
            'frequencia'  => $contagem,
            'eh_tendencia'=> in_array($termo, $trends, true),
            'tipo'        => str_word_count($termo) === 1 ? 'unigrama'
                           : (str_word_count($termo) === 2 ? 'bigrama' : 'trigrama'),
        ];
    }
    return $resultado;
}
```

---

### `app/Jobs/MlbColetaJob.php` (job, event-driven)

**Analog:** `app/Jobs/SyncMlCompanyJob.php`

**Imports** — copiar exatamente de `SyncMlCompanyJob` linhas 1–13, substituindo use:
```php
<?php

namespace App\Jobs;

use App\Models\MlbColeta;
use App\Services\MlColetaService;
use App\Services\MlKeywordMinerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
```

**Estrutura completa do Job** — copiar de `SyncMlCompanyJob` linhas 15–52:
```php
class MlbColetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Maior timeout que SyncMlCompanyJob (120s) pois pipeline é mais longo
    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public readonly int $coletaId) {}

    // Backoff análogo a SyncMlCompanyJob::backoff() linhas 29-32
    public function backoff(): array
    {
        return [60, 300]; // 1 min, 5 min
    }

    public function handle(MlColetaService $service, MlKeywordMinerService $miner): void
    {
        $coleta = MlbColeta::findOrFail($this->coletaId);
        $coleta->update(['status' => 'rodando', 'started_at' => now()]);

        try {
            $resultado = $service->executarPipeline($coleta, $miner);
            $coleta->update([
                'status'      => 'concluido',
                'resultado'   => $resultado,
                'finished_at' => now(),
            ]);
            Log::info("[MLB Coleta] Concluído coleta {$coleta->id} keyword='{$coleta->keyword}'");
        } catch (\Throwable $e) {
            $coleta->update([
                'status'         => 'erro',
                'erro_mensagem'  => $e->getMessage(),
                'finished_at'    => now(),
            ]);
            Log::error("[MLB Coleta] Erro coleta {$coleta->id}: {$e->getMessage()}");
            throw $e; // re-lança para queue registrar em failed_jobs se tries esgotados
        }
    }

    // Padrão de failed() — copiar exatamente de SyncMlCompanyJob linhas 39-51
    public function failed(\Throwable $e): void
    {
        Log::error("[MLB Coleta] Falha definitiva coleta {$this->coletaId}: {$e->getMessage()}");
        MlbColeta::where('id', $this->coletaId)->update([
            'status'        => 'erro',
            'erro_mensagem' => 'Falha definitiva: ' . $e->getMessage(),
            'finished_at'   => now(),
        ]);
    }
}
```

---

### `app/Models/MlbColeta.php` (model, CRUD)

**Analog:** `app/Models/MlbSyncVendasLog.php`

**Estrutura** — copiar de `MlbSyncVendasLog` linhas 1–46:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cabeçalho e resultado de uma coleta de dados ML.
 * Cada linha representa uma coleta sob demanda disparada pelo usuário.
 */
class MlbColeta extends Model
{
    // ─── Constantes de status ─────────────────────────────────────────────────

    // Copiar padrão de MlbSyncVendasLog linhas 16-18
    public const STATUS_PENDENTE  = 'pendente';
    public const STATUS_RODANDO   = 'rodando';
    public const STATUS_CONCLUIDO = 'concluido';
    public const STATUS_ERRO      = 'erro';

    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'user_id', 'keyword', 'categoria_id', 'faixa_preco', 'condicao',
        'status', 'erro_mensagem', 'resultado', 'started_at', 'finished_at',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    // Copiar padrão de casts de MlbSyncVendasLog linhas 42-46
    protected $casts = [
        'resultado'   => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
```

---

### `database/migrations/*_create_mlb_coletas_table.php` (migration)

**Analog:** `database/migrations/2026_05_22_100001_create_mlb_sync_vendas_logs_table.php`

**Estrutura** — copiar de linhas 1–53 do analog, substituindo colunas:
```php
<?php

// pt-BR: Migration que cria a tabela de coletas de dados ML.
// Cada linha representa uma coleta sob demanda — keyword + status + resultado JSON.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Padrão de Schema::create de mlb_sync_vendas_logs linhas 15-46
        Schema::create('mlb_coletas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Entrada do usuário
            $table->string('keyword');
            $table->string('categoria_id')->nullable();
            $table->string('faixa_preco')->nullable();
            $table->string('condicao')->nullable();

            // Ciclo de vida — padrão status enum igual ao analog
            $table->enum('status', ['pendente', 'rodando', 'concluido', 'erro'])->default('pendente');
            $table->text('erro_mensagem')->nullable();

            // Resultado: JSON com estrutura documentada em RESEARCH.md
            $table->json('resultado')->nullable();

            // Copiar padrão de timestamps de ciclo de vida do analog linhas 39-44
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Índices para histórico e reuso — análogo ao index('started_at') do analog linha 44
            $table->index('keyword');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_coletas');
    }
};
```

---

### `app/Http/Controllers/MlbController.php` — novas actions (controller, request-response)

**Analog:** `app/Http/Controllers/MlbController.php` actions existentes

**Padrão de permission gating** — copiar `checkPubAccess()` linhas 32–50 do controller:
```php
// Fonte: MlbController::checkPubAccess() linhas 32-50 — copiar SEM modificar
private function checkPubAccess(?string $permission = null): void
{
    $user = auth()->user();
    if ($user->isAdmin()) return;

    $temAcessoMlb = $user->setores()->where('slug', 'publicacao')->exists()
        || collect(\App\Support\Permissions::all())
            ->filter(fn($k) => str_starts_with($k, 'mlb.'))
            ->some(fn($k) => $user->hasPermission($k));

    if (!$temAcessoMlb) {
        abort(403, 'Acesso restrito ao módulo de publicações MLB.');
    }

    if ($permission && !$user->hasPermission("mlb.{$permission}")) {
        abort(403, 'Permissão insuficiente para esta área.');
    }
}
```

**Padrão de action de listagem** — copiar de `MlbController::historico()` ou `vendas()` (linha 945, 594):
```php
public function coletaIndex(Request $request): Response
{
    $this->checkPubAccess('coleta'); // nova key

    $coletas = MlbColeta::latest()->get()->map(fn($c) => [
        'id'          => $c->id,
        'keyword'     => $c->keyword,
        'status'      => $c->status,
        'created_at'  => $c->created_at?->format('d/m/Y H:i'),
        'duracao'     => $this->formatarDuracao($c),
    ]);

    return Inertia::render('Mlb/Coleta', compact('coletas'));
}
```

**Padrão de action de store com dispatch** — copiar do padrão `MlbController::syncTodasVendasAdman()` (linha 275) + `SyncTodasVendasAdmanJob`:
```php
public function coletaStore(Request $request): \Illuminate\Http\RedirectResponse
{
    $this->checkPubAccess('coleta');

    // Padrão de validação inline do controller — RESEARCH.md §Domínio de Segurança
    $request->validate([
        'keyword'     => 'required|string|max:255',
        'categoria_id'=> 'nullable|string|max:50',
        'faixa_preco' => 'nullable|string|max:20',
        'condicao'    => 'nullable|in:new,used',
    ]);

    $coleta = MlbColeta::create([
        'user_id'     => auth()->id(),
        'keyword'     => $request->keyword,
        'categoria_id'=> $request->categoria_id,
        'faixa_preco' => $request->faixa_preco,
        'condicao'    => $request->condicao,
        'status'      => MlbColeta::STATUS_PENDENTE,
    ]);

    MlbColetaJob::dispatch($coleta->id);

    return redirect()->route('mlb.coleta.show', $coleta->id);
}
```

**Padrão de action de status JSON** — copiar do padrão `grants.sync.status` do `GrantsController`:
```php
public function coletaStatus(int $id): \Illuminate\Http\JsonResponse
{
    $this->checkPubAccess('coleta');

    $coleta = MlbColeta::findOrFail($id);

    // Pitfall 5 de RESEARCH.md: status=rodando por > 10 min → retornar como timeout
    $timedOut = $coleta->status === 'rodando'
        && $coleta->started_at
        && $coleta->started_at->lt(now()->subMinutes(10));

    return response()->json([
        'status'    => $timedOut ? 'erro' : $coleta->status,
        'progresso' => $timedOut ? 0 : null,
    ]);
}
```

---

### `routes/web.php` — rotas `mlb.coleta.*` (route)

**Analog:** `routes/web.php` bloco `mlb.*` linhas 240–291

**Padrão de adição de rotas ao grupo mlb** — inserir DENTRO do grupo existente `Route::middleware(['auth', 'verified'])->prefix('mlb')->name('mlb.')`:
```php
// Copiar padrão de nomeação das linhas 278-280 (metas) e 283-290 (implementação)
// Inserir após o bloco de Metas (linha 281) e antes de Implementação (linha 282)

// Coleta de dados ML (inteligência de anúncios) — Phase 17
Route::get('/coleta',                    [MlbController::class, 'coletaIndex'])->name('coleta.index');
Route::post('/coleta',                   [MlbController::class, 'coletaStore'])->name('coleta.store');
Route::get('/coleta/{id}',               [MlbController::class, 'coletaShow'])->name('coleta.show');
Route::get('/coleta/{id}/status',        [MlbController::class, 'coletaStatus'])->name('coleta.status');
```

---

### `app/Support/Permissions.php` — nova key `mlb.coleta` (config)

**Analog:** `app/Support/Permissions.php` bloco `MLB_*` linhas 46–56

**Padrão de constante** — inserir após `MLB_METAS` linha 56:
```php
// Fonte: padrão das constantes MLB linhas 46-56
/** Inteligência de anúncios MLB — coleta e mineração de keywords. */
public const MLB_COLETA = 'mlb.coleta';
```

**Padrão de entrada no catalog()** — inserir no grupo `'Publicações (MLB)'` após a entrada de `MLB_METAS` (linha 130):
```php
// Fonte: padrão das entradas do catalog() linhas 119-130
['key' => self::MLB_COLETA, 'label' => 'Pub · Int. Anúncios', 'description' => 'Coleta e mineração de keywords de concorrentes MLB'],
```

---

### `resources/js/Pages/Mlb/Coleta.jsx` (component, request-response + polling)

**Analog principal:** `resources/js/Pages/Grants/Index.jsx` (polling) + `resources/js/Pages/Mlb/Historico.jsx` (layout MLB)

**Imports** — copiar de `Grants/Index.jsx` linhas 1–12 + ícones adicionais:
```jsx
// Fonte: Grants/Index.jsx linhas 1-12
import AppLayout from '@/Layouts/AppLayout';
import { Badge } from '@/Components/ui/badge';
import { Progress } from '@/Components/ui/progress';
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { RefreshCw, Search, MessageSquare, Info, Lightbulb, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';
```

**Polling com setInterval + fetch + router.reload** — copiar EXATAMENTE de `Grants/Index.jsx` linhas 45–75:
```jsx
// Fonte: Grants/Index.jsx linhas 43-75 — padrão canônico de polling do projeto
const pollRef = useRef(null);

const startPolling = (coletaId) => {
    const deadline = Date.now() + 10 * 60 * 1000; // 10 min (vs 5 min do Grants)
    pollRef.current = setInterval(async () => {
        if (Date.now() > deadline) {
            clearInterval(pollRef.current);
            setStatus('erro');
            return;
        }
        try {
            const res  = await fetch(route('mlb.coleta.status', coletaId));
            const data = await res.json();
            setStatus(data.status);
            if (data.status === 'concluido' || data.status === 'erro') {
                clearInterval(pollRef.current);
                if (data.status === 'concluido') {
                    router.reload({ only: ['coleta'] }); // padrão Grants linha 64
                }
            }
        } catch { /* silencioso — polling não deve quebrar UI */ }
    }, 3000); // 3 000 ms — igual ao Grants/Index.jsx linha 68
};

useEffect(() => {
    if (coleta?.status === 'pendente' || coleta?.status === 'rodando') {
        startPolling(coleta.id);
    }
    return () => { if (pollRef.current) clearInterval(pollRef.current); };
}, []);
```

**Padrão de useForm Inertia para submit** — copiar de `Grants/Index.jsx` linhas 86–125:
```jsx
// Fonte: Grants/Index.jsx linhas 86-125
const form = useForm({ keyword: '', categoria_id: '', faixa_preco: '', condicao: '' });

const submit = (e) => {
    e.preventDefault();
    form.post(route('mlb.coleta.store'), {
        onError: () => {}, // erros de validação ficam em form.errors
    });
};
```

**Padrão de layout de página MLB** — copiar de `Historico.jsx` linhas 80–82 (h1 + subtítulo):
```jsx
// Fonte: Historico.jsx linhas 80-82 (padrão de cabeçalho MLB)
<AppLayout title="Inteligência de Anúncios">
    <div className="space-y-5 max-w-[1200px]">
        <h1 className="text-white font-display font-bold text-2xl">Inteligência de Anúncios</h1>
        <p className="text-white/40 text-[13px] mt-1">
            Pesquise palavras-chave e veja o que os concorrentes top usam nos anúncios do Mercado Livre.
        </p>
```

**Padrão de card-ecf com formulário** — copiar de `Historico.jsx` ou `Vendas.jsx` (card-ecf rounded-2xl p-5):
```jsx
// Fonte: padrão card-ecf p-5 de Historico.jsx / Vendas.jsx
<div className="card-ecf rounded-2xl p-5">
    <form onSubmit={submit} className="space-y-4">
        {/* Campo keyword */}
        <div>
            <label htmlFor="keyword"
                className="text-white/50 text-[11px] uppercase tracking-wide font-bold block mb-1">
                Palavra-chave
            </label>
            <input
                id="keyword"
                type="text"
                placeholder="Ex: fone bluetooth esportivo"
                value={form.data.keyword}
                onChange={e => form.setData('keyword', e.target.value)}
                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40"
            />
            {form.errors.keyword && (
                <p className="text-red-400 text-[13px] mt-1">{form.errors.keyword}</p>
            )}
        </div>
        {/* Botão primário */}
        <button
            type="submit"
            disabled={form.processing}
            aria-disabled={form.processing}
            className="h-10 px-6 rounded-xl bg-ecf-yellow text-black font-bold text-[13px] hover:bg-ecf-yellow-2 transition-colors disabled:opacity-50"
        >
            {form.processing ? 'Coletando…' : 'Iniciar Coleta'}
        </button>
    </form>
</div>
```

**Padrão de barra de progresso** — copiar padrão de `Grants/Index.jsx` alert block + `Progress` do shadcn:
```jsx
// Fonte: Grants/Index.jsx padrão de alerta border-ecf-yellow/20
{(localStatus === 'pendente' || localStatus === 'rodando') && (
    <div className="card-ecf rounded-2xl p-4 flex items-center gap-3 border border-ecf-yellow/20">
        <RefreshCw size={16} className="text-ecf-yellow animate-spin shrink-0" aria-hidden="true" />
        <div className="flex-1">
            <p className="text-ecf-yellow text-[13px]">
                Coleta em andamento — analisando <span className="font-bold">{coleta?.keyword}</span>…
            </p>
            <Progress className="mt-2 h-2 [&>div]:bg-ecf-yellow" value={undefined} />
        </div>
    </div>
)}
```

**Padrão de tabela de histórico** — copiar estrutura de grid div de `Grants/Index.jsx` linhas 237–295:
```jsx
// Fonte: Grants/Index.jsx linhas 237-295 — substituir grid-cols por colunas da coleta
<div className="card-ecf rounded-2xl overflow-hidden">
    <div className="divide-y divide-white/[0.04]">
        {/* Header */}
        <div className="grid grid-cols-[1fr_8rem_8rem_7rem_6rem] gap-3 px-5 py-2.5 text-white/30 text-[11px] font-bold uppercase tracking-wide">
            <span>Keyword</span>
            <span>Categoria</span>
            <span>Status</span>
            <span>Duração</span>
            <span className="text-right">Ação</span>
        </div>
        {coletas.map(c => (
            <div key={c.id} className={cn(
                'grid grid-cols-[1fr_8rem_8rem_7rem_6rem] gap-3 px-5 py-3.5 items-center hover:bg-white/[0.02] transition-colors',
                coletaSelecionada?.id === c.id && 'bg-ecf-yellow/5 border-l-2 border-ecf-yellow'
            )}>
                {/* ... */}
            </div>
        ))}
        {coletas.length === 0 && (
            <div className="py-10 text-center text-white/25 text-[13px]">
                Nenhuma coleta realizada ainda. Use o formulário acima para iniciar.
            </div>
        )}
    </div>
</div>
```

**Padrão de badge de status** — mapear cores da UI-SPEC para `<Badge>` do shadcn (padrão `Grants/Index.jsx` linha 260):
```jsx
// Fonte: Grants/Index.jsx linha 260
const STATUS_BADGE_CLASS = {
    pendente:  'bg-blue-500/10 border border-blue-500/30 text-blue-400',
    rodando:   'bg-ecf-yellow/10 border border-ecf-yellow/30 text-ecf-yellow',
    concluido: 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    erro:      'bg-red-500/10 border border-red-500/30 text-red-400',
};
// Uso: <span className={cn('inline-flex px-2 py-1 rounded-full text-[11px] font-bold', STATUS_BADGE_CLASS[c.status])}>{c.status}</span>
```

---

### `resources/js/Layouts/AppLayout.jsx` — novo nav item (config)

**Analog:** `resources/js/Layouts/AppLayout.jsx` linhas 43–54 (bloco `NAV_ITEMS` MLB)

**Padrão de adição de item** — inserir após a linha 54 (`Metas`) e antes da linha 55 (Administrativo):
```jsx
// Fonte: AppLayout.jsx linhas 44-54 — copiar forma exata do objeto nav item
// Inserir APÓS { label: 'Metas', ... permission: 'mlb.metas' } linha 54

{ label: 'Int. Anúncios', routeName: 'mlb.coleta.index', page: 'Mlb/Coleta', icon: Search, permission: 'mlb.coleta' },
```

**Import do ícone** — adicionar `Search` ao import existente na linha 6–10:
```jsx
// Fonte: AppLayout.jsx linhas 6-10 — adicionar Search ao destructuring existente
import {
    ..., Search  // adicionar aqui
} from 'lucide-react';
```

---

### `tests/Unit/MlKeywordMinerTest.php` (test, Unit)

**Analog:** `tests/Unit/CobrancaCalculatorTest.php`

**Estrutura base** — copiar de `CobrancaCalculatorTest` linhas 1–30:
```php
<?php

namespace Tests\Unit;

use App\Services\MlKeywordMinerService;
use Tests\TestCase;

/**
 * Testes unitários do MlKeywordMinerService.
 * Não usa DB — PHP puro (análogo a CobrancaCalculatorTest).
 *
 * @group phase17
 */
class MlKeywordMinerTest extends TestCase
{
    private MlKeywordMinerService $miner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->miner = new MlKeywordMinerService();
    }

    public function test_normaliza_token(): void
    {
        // D-04-a de RESEARCH.md §Mapeamento Requisito → Teste
        $this->assertSame('fone estereo', $this->miner->normalizarToken('Fone Estéreo'));
    }

    public function test_stopwords_filtradas(): void
    {
        // D-04-b
        $tokens = $this->miner->tokenizar('fone de ouvido bluetooth esportivo');
        $this->assertNotContains('de', $tokens);
        $this->assertContains('fone', $tokens);
    }

    public function test_ranking_keywords(): void
    {
        // D-04-c
        $titulos  = ['Fone Bluetooth Esportivo', 'Fone Bluetooth Sem Fio', 'Fone Bluetooth 5.0'];
        $ranking  = $this->miner->rankingKeywords($titulos);
        $top      = $ranking[0]['termo'];
        $this->assertSame('bluetooth', $top); // aparece 3x
        $this->assertSame(3, $ranking[0]['frequencia']);
    }
}
```

---

### `tests/Unit/MlColetaServiceTest.php` (test, Unit)

**Analog:** `tests/Unit/CobrancaCalculatorTest.php` + `Http::fake()` do Laravel

**Estrutura** — copiar de `CobrancaCalculatorTest` + adicionar Http::fake():
```php
<?php

namespace Tests\Unit;

use App\Services\MlColetaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @group phase17
 */
class MlColetaServiceTest extends TestCase
{
    public function test_app_token_cacheado(): void
    {
        // D-01: segunda chamada não faz nova requisição HTTP
        Http::fake(['*/oauth/token' => Http::response(['access_token' => 'tok123', 'expires_in' => 21600], 200)]);
        Cache::flush();

        $service = new MlColetaService();
        $t1 = $service->getAppToken(); // força visibilidade via método público de teste
        $t2 = $service->getAppToken();

        $this->assertSame($t1, $t2);
        Http::assertSentCount(1); // só 1 chamada HTTP — segunda vem do cache
    }

    public function test_pipeline_sem_questions(): void
    {
        // D-02 fallback: 401 em questions não aborta pipeline
        Http::fake([
            '*/oauth/token'       => Http::response(['access_token' => 'tok', 'expires_in' => 21600], 200),
            '*/domain_discovery*' => Http::response(['domain_id' => 'MLB-TEST', 'category_id' => 'MLB1'], 200),
            '*/products/search*'  => Http::response(['results' => []], 200),
            '*/questions/search*' => Http::response(['error' => 'forbidden'], 401),
        ]);
        // Verificar que executarPipeline() retorna resultado com questions_disponivel=false sem lançar
        $this->assertTrue(true); // substituir por assert no resultado real
    }
}
```

---

### `tests/Unit/MlbColetaJobTest.php` (test, Unit)

**Analog:** `tests/Unit/CobrancaCalculatorTest.php` + padrão de Job mock

```php
<?php

namespace Tests\Unit;

use App\Jobs\MlbColetaJob;
use App\Models\MlbColeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group phase17
 */
class MlbColetaJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_marca_erro(): void
    {
        // D-06: failed() atualiza status=erro e erro_mensagem
        $coleta = MlbColeta::create([
            'user_id' => null, 'keyword' => 'teste',
            'status' => 'rodando',
        ]);

        $job = new MlbColetaJob($coleta->id);
        $job->failed(new \RuntimeException('Erro simulado'));

        $this->assertDatabaseHas('mlb_coletas', [
            'id'     => $coleta->id,
            'status' => 'erro',
        ]);
    }
}
```

---

### `tests/Feature/Phase17ColetaTest.php` (test, Feature)

**Analog:** `tests/Feature/Phase14MlbControllerFiltroTest.php`

**Estrutura** — copiar de `Phase14MlbControllerFiltroTest` linhas 1–50:
```php
<?php

namespace Tests\Feature;

use App\Models\MlbColeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Testes de integração da feature de Coleta de Dados ML (Phase 17).
 *
 * @group phase17
 */
class Phase17ColetaTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        // Copiar helper de Phase14MlbControllerFiltroTest linha 33-36
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_store_cria_coleta_pendente(): void
    {
        // D-06
        Queue::fake();
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->post('/mlb/coleta', ['keyword' => 'fone bluetooth']);

        $response->assertRedirect();
        $this->assertDatabaseHas('mlb_coletas', ['keyword' => 'fone bluetooth', 'status' => 'pendente']);
    }

    public function test_status_endpoint_json(): void
    {
        // D-06
        $admin = $this->criarAdmin();
        $coleta = MlbColeta::create(['user_id' => $admin->id, 'keyword' => 'teste', 'status' => 'rodando']);

        $response = $this->actingAs($admin)->getJson("/mlb/coleta/{$coleta->id}/status");

        $response->assertOk()->assertJsonFragment(['status' => 'rodando']);
    }

    public function test_acesso_403_sem_pub_role(): void
    {
        // D-07
        $user = User::factory()->create(['role' => 'consultor']);
        $response = $this->actingAs($user)->get('/mlb/coleta');
        $response->assertForbidden();
    }
}
```

---

## Padrões Compartilhados (Cross-cutting)

### Permission gating `checkPubAccess()`
**Fonte:** `app/Http/Controllers/MlbController.php` linhas 32–50
**Aplicar a:** todas as actions novas (`coletaIndex`, `coletaStore`, `coletaShow`, `coletaStatus`)
**Chamada:** `$this->checkPubAccess('coleta')` — usa a nova key `mlb.coleta` registrada em `Permissions.php`

### Error handling `\Throwable` → logar e continuar
**Fonte:** `app/Services/MercadoLivreService::syncCompany()` linhas 702–711 e `AdmanService::syncAll()`
**Aplicar a:** loop dos top-5 em `MlColetaService::executarPipeline()` e todos os endpoints best-effort (questions, reviews)
```php
// Padrão: catch \Throwable → Log::warning → continue (não re-lança)
try { /* chamada best-effort */ } catch (\Throwable $e) {
    Log::warning("[MLB Coleta] Falha best-effort em {$context}: {$e->getMessage()}");
}
```

### Logging com tag de módulo
**Fonte:** `app/Services/MercadoLivreService.php` linhas 182, 696, 753
**Aplicar a:** `MlColetaService`, `MlbColetaJob`
**Tag:** `[MLB Coleta]` — consistente com tag `[MercadoLivre]` e `[MLB SyncVendas]` já existentes

### Cache de token OAuth
**Fonte:** `app/Services/MercadoLivreService::resolveAdvertiserId()` linhas 438–459
**Aplicar a:** `MlColetaService::mlAppToken()`
**Chave de cache:** `ml_app_token_coleta` (prefixo diferente de `ml_advertiser_*`)
**TTL:** `expires_in - 300` segundos (margem de 5 min), lido dinamicamente da resposta

### Design system ECF nos componentes React
**Fonte:** `resources/js/Pages/Grants/Index.jsx` e `resources/js/Pages/Mlb/Historico.jsx`
**Aplicar a:** `resources/js/Pages/Mlb/Coleta.jsx`
- Container: `space-y-5 max-w-[1200px]`
- Cards: `card-ecf rounded-2xl p-5`
- Bordas sutis: `border-white/[0.08]`
- Inputs: `h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:border-ecf-yellow/40`
- Botão primário: `h-10 px-6 rounded-xl bg-ecf-yellow text-black font-bold text-[13px]`
- Labels: `text-white/50 text-[11px] uppercase tracking-wide font-bold block mb-1`
- Helper `cn()` de `@/lib/utils` em todas as classes condicionais

---

## Arquivos Sem Analog

Nenhum — todos os 12 arquivos têm analog de alta qualidade no codebase.

---

## Metadados

**Escopo de busca de analogs:**
- `app/Services/`, `app/Jobs/`, `app/Models/`, `app/Http/Controllers/`, `app/Support/`
- `database/migrations/`, `routes/web.php`
- `resources/js/Pages/Grants/`, `resources/js/Pages/Mlb/`, `resources/js/Layouts/`
- `tests/Unit/`, `tests/Feature/`

**Arquivos analisados:** 14 arquivos existentes lidos em detalhe
**Data de extração:** 2026-06-01
