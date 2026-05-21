# Phase 1: Diagnóstico Adman - Pattern Map

**Mapped:** 2026-05-18
**Files analyzed:** 8 (6 novos + 2 modificados)
**Analogs found:** 8 / 8

---

## File Classification

| Arquivo Novo/Modificado | Role | Data Flow | Analog Mais Próximo | Qualidade |
|-------------------------|------|-----------|---------------------|-----------|
| `app/Http/Controllers/DevController.php` | controller | request-response | `app/Http/Controllers/SugadorController.php` | exact |
| `app/Models/AdmanSyncLog.php` | model | CRUD | `app/Models/AdmanMetric.php` | exact |
| `app/Services/AdmanService.php` (modificar) | service | request-response | `app/Services/AdmanService.php` — `syncCompany()` existente | próprio arquivo |
| `app/Models/Company.php` (modificar) | model | CRUD | `app/Models/Company.php` — `latestMetrics()` existente | próprio arquivo |
| `database/migrations/2026_05_18_*_create_adman_sync_logs_table.php` | migration | CRUD | `database/migrations/2026_04_26_152220_create_adman_metrics_table.php` | exact |
| `resources/js/Pages/Dev/Desenvolvimento.jsx` (modificar) | component | request-response | `resources/js/Pages/Dev/Desenvolvimento.jsx` (próprio) + `resources/js/Pages/Sugadores/Index.jsx` | exact |
| `routes/web.php` (modificar) | route | request-response | `routes/web.php` — grupo `role:admin` existente | próprio arquivo |
| `tests/Feature/DevControllerTest.php` | test | request-response | `tests/Feature/ProfileTest.php` | role-match |

---

## Pattern Assignments

### `app/Http/Controllers/DevController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/SugadorController.php` (index com Inertia::render + props) e `app/Http/Controllers/DashboardController.php` (query complexa → props)

**Imports pattern** (baseado em `SugadorController.php` linhas 1-12 e `DashboardController.php` linhas 1-16):
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use Inertia\Inertia;
```

**Core pattern — index() com Inertia::render** (baseado em `SugadorController.php` linhas 89-97 e `DashboardController.php` linhas 170-219):
```php
public function index(): \Inertia\Response
{
    // Apenas empresas ativas com conta Adman configurada
    $empresas = Company::where('active', true)
        ->whereNotNull('adman_account_id')
        ->where('adman_account_id', '!=', '')
        ->with(['latestMetrics', 'latestAdmanSyncLog'])
        ->get()
        ->map(fn(Company $c) => [
            'id'          => $c->id,
            'name'        => $c->name,
            'synced_at'   => $c->latestMetrics?->synced_at?->toIso8601String(),
            'raw_data'    => $c->latestMetrics?->raw_data,
            'criados'     => $c->latestAdmanSyncLog?->created_count,
            'atualizados' => $c->latestAdmanSyncLog?->updated_count,
            'ignorados'   => $c->latestAdmanSyncLog?->skipped_count,
            'error'       => $c->latestAdmanSyncLog?->error_message,
            'status'      => $c->latestAdmanSyncLog?->error_message ? 'erro' : 'ok',
        ]);

    return Inertia::render('Dev/Desenvolvimento', [
        'empresas' => $empresas,
    ]);
}
```

**Core pattern — dispatchSync() com redirect flash** (baseado em `SugadorController.php` linhas 119-155 — `updateStatus()` como referência de resposta redirect):
```php
public function dispatchSync(Company $company): \Illuminate\Http\RedirectResponse
{
    // Segurança: rejeitar empresa sem conta Adman configurada
    abort_unless($company->adman_account_id, 422, 'Empresa sem conta Adman configurada.');

    SyncAdmanCompanyJob::dispatch($company);

    return back()->with('success', "Sync enfileirado para {$company->name}.");
}
```

**Error handling pattern** (`AdmanController.php` linhas 17-27 como anti-pattern — NÃO usar `response()->json()` em rota Inertia):
```php
// CORRETO para rota Inertia — usar back()->with()
return back()->with('success', "Sync enfileirado para {$company->name}.");

// ERRADO para rota Inertia — não usar response()->json()
// return response()->json(['message' => ...], 422);  ← padrão do AdmanController, mas esse é JSON puro
```

---

### `app/Models/AdmanSyncLog.php` (model, CRUD)

**Analog:** `app/Models/AdmanMetric.php` (linhas 1-49) — mesma estrutura: `$fillable`, `$casts`, `belongsTo(Company::class)`

**Imports e namespace** (baseado em `AdmanMetric.php` linhas 1-8):
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
```

**Core model pattern** (baseado em `AdmanMetric.php` linhas 9-36):
```php
class AdmanSyncLog extends Model
{
    protected $fillable = [
        'company_id', 'created_count', 'updated_count', 'skipped_count',
        'error_message', 'synced_at',
    ];

    protected $casts = [
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'synced_at'     => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
```

---

### `app/Services/AdmanService.php` — modificação de `syncCompany()` (service, request-response)

**Arquivo próprio:** `app/Services/AdmanService.php` linhas 55-117

**Ponto de inserção exato:** Após `$metric = AdmanMetric::updateOrCreate(...)` (linha 86) e antes do bloco `try { $this->syncCampaigns(...) }` (linha 110).

**Pattern de `wasRecentlyCreated` + `wasChanged()`** (Eloquent nativo — confirmado no projeto):
```php
// Após o updateOrCreate existente (linha 86-108 de AdmanService.php):
$metric = AdmanMetric::updateOrCreate(
    ['company_id' => $company->id, 'reference_date' => $date],
    [ /* campos existentes — não alterar */ ]
);

// INSERIR AQUI — rastreamento de diff para adman_sync_logs
$foiCriado      = $metric->wasRecentlyCreated;
$foiAtualizado  = !$foiCriado && $metric->wasChanged();
$foiIgnorado    = !$foiCriado && !$metric->wasChanged();

AdmanSyncLog::create([
    'company_id'    => $company->id,
    'created_count' => $foiCriado     ? 1 : 0,
    'updated_count' => $foiAtualizado ? 1 : 0,
    'skipped_count' => $foiIgnorado   ? 1 : 0,
    'error_message' => null,
    'synced_at'     => now(),
]);
```

**Error handling pattern para capturar falha HTTP** (baseado no padrão `catch (\Throwable $e)` em `syncAll()` linhas 42-49):
```php
// No catch do método que chama syncCompany() — salvar log de erro
// Padrão do projeto: Log::error("[Adman] mensagem")
try {
    $this->syncCompany($company, $date);
} catch (\Throwable $e) {
    AdmanSyncLog::create([
        'company_id'    => $company->id,
        'created_count' => 0,
        'updated_count' => 0,
        'skipped_count' => 0,
        'error_message' => $e->getMessage(),
        'synced_at'     => now(),
    ]);
    throw $e; // re-throw para o job registrar em failed()
}
```

**Import a adicionar no topo de `AdmanService.php`** (linha 4, após `use App\Models\AdmanCampaignMetric;`):
```php
use App\Models\AdmanSyncLog;
```

---

### `app/Models/Company.php` — adição de relacionamentos (model, CRUD)

**Arquivo próprio:** `app/Models/Company.php` linhas 69-77

**Analog exato:** método `latestMetrics()` (linha 74-77) — padrão `hasOne(...)->latestOfMany()`

```php
// Inserir após latestMetrics() — mesma estrutura latestOfMany
public function admanSyncLogs()
{
    return $this->hasMany(AdmanSyncLog::class)->orderBy('created_at', 'desc');
}

public function latestAdmanSyncLog()
{
    return $this->hasOne(AdmanSyncLog::class)->latestOfMany('created_at');
}

public function latestAdmanMetric()
{
    // Alias explícito para uso no DevController (já existe como latestMetrics())
    // Usar latestMetrics() se já existir para não duplicar
    return $this->hasOne(AdmanMetric::class)->latestOfMany('reference_date');
}
```

**Import a adicionar** no bloco `use` de `Company.php`:
```php
use App\Models\AdmanSyncLog;
```

---

### `database/migrations/2026_05_18_*_create_adman_sync_logs_table.php` (migration, CRUD)

**Analog:** `database/migrations/2026_04_26_152220_create_adman_metrics_table.php` (linhas 1-40) — estrutura idêntica: `Schema::create`, `foreignId`, `cascadeOnDelete`, índice composto, `timestamps()`

**Core migration pattern** (baseado nas linhas 7-40 do analog):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adman_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->integer('created_count')->default(0);   // AdmanMetric criado (wasRecentlyCreated)
            $table->integer('updated_count')->default(0);   // AdmanMetric atualizado
            $table->integer('skipped_count')->default(0);   // sem alteração (wasChanged() == false)
            $table->text('error_message')->nullable();       // mensagem de erro HTTP se sync falhou
            $table->timestamp('synced_at')->nullable();      // timestamp do sync
            $table->timestamps();
            // Índice para query de último sync por empresa (usado em latestOfMany)
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adman_sync_logs');
    }
};
```

---

### `resources/js/Pages/Dev/Desenvolvimento.jsx` — modificação (component, request-response)

**Analogs:**
1. `resources/js/Pages/Dev/Desenvolvimento.jsx` (linhas 1-105) — `DevCard`, `LinkRow`, design tokens, estrutura geral
2. `resources/js/Pages/Sugadores/Index.jsx` (linhas 1-80) — padrão de sub-componentes locais, `useState`, `cn()`, `router`, constantes de UI

**Imports a adicionar** (baseado em `Desenvolvimento.jsx` linha 1-3 + `Sugadores/Index.jsx` linhas 1-8):
```jsx
import { router } from '@inertiajs/react';
import { useState } from 'react';      // já existe — verificar antes de duplicar
import { RefreshCw, ChevronDown, AlertTriangle, Activity } from 'lucide-react';
import { format } from 'date-fns';
import { cn } from '@/lib/utils';      // já existe no arquivo
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
```

**Adição de props na função default** (baseado no padrão do projeto — props diretas do Inertia):
```jsx
// DE:
export default function Desenvolvimento() {

// PARA:
export default function Desenvolvimento({ empresas = [] }) {
```

**Padrão de sub-componentes locais** (baseado em `Sugadores/Index.jsx` linhas 52-68 — `StatusBadge`, `MotivoBadge`):
```jsx
// Sub-componentes definidos antes do export default, no mesmo arquivo
// Seguir convenção: função nomeada PascalCase, sem arquivo separado se < 120 linhas total

function DiffBadge({ label, count, variant }) {
    const styles = {
        criados:     'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
        atualizados: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
        ignorados:   'bg-white/[0.05] text-white/40 border-white/[0.08]',
    };
    return (
        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded text-[12px] font-mono border', styles[variant])}>
            <span className="font-semibold">{count ?? 0}</span>
            <span className="text-[11px]">{label}</span>
        </span>
    );
}
```

**Padrão de accordion com `useState`** (baseado em padrão local do projeto — sem Radix Accordion para este caso):
```jsx
function SyncAdmanSection({ empresas }) {
    const [aberta, setAberta] = useState(null); // company_id ou null

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    if (!empresas || empresas.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-8 text-center">
                <AlertTriangle size={24} className="text-white/20" />
                <p className="text-white/40 text-[13px]">Nenhuma empresa com conta Adman configurada.</p>
            </div>
        );
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <div key={empresa.id}>
                    <EmpresaRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                    />
                    {aberta === empresa.id && <EmpresaAccordion empresa={empresa} />}
                </div>
            ))}
        </div>
    );
}
```

**Padrão de dispatch via `router.post()`** (baseado em padrão `@inertiajs/react` — ver `Sugadores/Index.jsx` uso de `router` linha 2):
```jsx
function EmpresaRow({ empresa, expandida, onToggle }) {
    const [disparando, setDisparando] = useState(false);

    function disparar(e) {
        e.stopPropagation(); // não propaga para o accordion
        router.post(route('dev.adman.sync', empresa.id), {}, {
            onStart:  () => setDisparando(true),
            onFinish: () => setDisparando(false),
        });
    }

    const tsFormatado = empresa.synced_at
        ? format(new Date(empresa.synced_at), 'dd/MM HH:mm')
        : null;

    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-4 px-2 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]'
            )}
        >
            {/* estrutura conforme UI-SPEC.md */}
        </div>
    );
}
```

**Padrão de `DevCard` existente** (linhas 24-39 de `Desenvolvimento.jsx`):
```jsx
// DevCard já definido no arquivo — reutilizar sem modificar:
// <DevCard icon={Activity} title="Sync Adman" subtitle="Status e controle do sync de dados por empresa">
//     <SyncAdmanSection empresas={empresas} />
// </DevCard>
```

**Posição de inserção na estrutura do JSX** (baseado nas linhas 80-101 de `Desenvolvimento.jsx`):
```jsx
// Inserir ENTRE o DevCard da extensão Chrome (linha 80-94) e o placeholder dashed (linha 96-101)
<DevCard icon={Activity} title="Sync Adman" subtitle="Status e controle do sync de dados por empresa">
    <SyncAdmanSection empresas={empresas} />
</DevCard>

{/* placeholder existente — não remover */}
<div className="rounded-xl border border-dashed border-white/[0.08] p-5 text-center">
    ...
</div>
```

---

### `routes/web.php` — modificação (route, request-response)

**Arquivo próprio:** `routes/web.php` linhas 134-173 — grupo `role:admin`

**Ponto de modificação exato** (linhas 139-140 — closure atual):
```php
// DE (linha 139-140):
Route::get('/dev/desenvolvimento', fn () => \Inertia\Inertia::render('Dev/Desenvolvimento'))
    ->name('dev.desenvolvimento');

// PARA:
Route::get('/dev/desenvolvimento', [DevController::class, 'index'])
    ->name('dev.desenvolvimento');
Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])
    ->name('dev.adman.sync');
```

**Import a adicionar** (bloco de `use` no topo, linhas 1-23 — ordem alfabética por convenção):
```php
use App\Http\Controllers\DevController;
```

**Padrão do grupo** (linhas 134-173 — o grupo `role:admin` já existe, apenas inserir as novas rotas dentro):
```php
Route::middleware('role:admin')->group(function () {
    // ... rotas existentes ...

    // INSERIR: substituir closure + adicionar POST dispatch
    Route::get('/dev/desenvolvimento', [DevController::class, 'index'])
        ->name('dev.desenvolvimento');
    Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])
        ->name('dev.adman.sync');

    // ... demais rotas existentes ...
});
```

---

### `tests/Feature/DevControllerTest.php` (test, request-response)

**Analog:** `tests/Feature/ProfileTest.php` (linhas 1-99) — estrutura PHPUnit com `RefreshDatabase`, `actingAs()`, assertions de response

**Imports e estrutura base** (baseado em `ProfileTest.php` linhas 1-10):
```php
<?php

namespace Tests\Feature;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DevControllerTest extends TestCase
{
    use RefreshDatabase;
```

**Padrão de test com `actingAs()`** (baseado em `ProfileTest.php` linhas 15-22):
```php
public function test_index_retorna_empresas_com_synced_at(): void
{
    // Criar usuário admin (role necessária para acessar a rota)
    $admin = User::factory()->create(['role' => 'admin']);
    $company = Company::factory()->create(['adman_account_id' => 'ACC123', 'active' => true]);
    AdmanMetric::factory()->create(['company_id' => $company->id, 'synced_at' => now()]);

    $response = $this->actingAs($admin)->get('/dev/desenvolvimento');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dev/Desenvolvimento')
        ->has('empresas', 1)
        ->where('empresas.0.synced_at', fn($v) => !is_null($v))
    );
}
```

**Padrão de teste de autorização** (baseado em `ProfileTest.php` — padrão `actingAs()` com usuário diferente):
```php
public function test_dispatch_sync_rejeita_nao_admin(): void
{
    $consultor = User::factory()->create(['role' => 'consultor']);
    $company   = Company::factory()->create(['adman_account_id' => 'ACC123']);

    $response = $this->actingAs($consultor)
        ->post("/dev/adman/{$company->id}/sync");

    $response->assertStatus(403);
}
```

**Padrão de teste de Queue** (Queue::fake() — padrão Laravel para jobs):
```php
public function test_dispatch_sync_enfileira_job(): void
{
    Queue::fake();

    $admin   = User::factory()->create(['role' => 'admin']);
    $company = Company::factory()->create(['adman_account_id' => 'ACC123', 'active' => true]);

    $response = $this->actingAs($admin)
        ->post("/dev/adman/{$company->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('success');
    Queue::assertPushed(SyncAdmanCompanyJob::class, fn($job) => $job->company->id === $company->id);
}
```

---

## Shared Patterns

### Flash + Toast (todas as ações de escrita)
**Source:** `resources/js/Layouts/AppLayout.jsx` linhas 92-98 (leitura de `flash`) + padrão `back()->with()` nos controllers
**Apply to:** `DevController::dispatchSync()` (PHP) + `EmpresaRow` (JSX — sem código extra, AppLayout já gerencia)
```php
// Controller — padrão universal do projeto
return back()->with('success', "Sync enfileirado para {$company->name}.");
return back()->with('error', 'Mensagem de erro.');
```
```jsx
// AppLayout já lê flash.success/flash.error e exibe toast automaticamente
// Não adicionar lógica de toast em Desenvolvimento.jsx
useEffect(() => {
    if (flash?.success || flash?.error) {
        setToast({ message: flash.success || flash.error, type: flash.success ? 'success' : 'error' });
    }
}, [flash]);
```

### Autorização admin via middleware (todas as novas rotas)
**Source:** `routes/web.php` linhas 134-173 — grupo `middleware('role:admin')`
**Apply to:** Ambas as rotas de `DevController` (GET index + POST dispatchSync)
```php
// Não precisar adicionar verificação no controller — middleware já aplica
Route::middleware('role:admin')->group(function () {
    Route::get('/dev/desenvolvimento', [DevController::class, 'index'])->name('dev.desenvolvimento');
    Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])->name('dev.adman.sync');
});
```

### Inertia::render com props estruturadas (DevController::index)
**Source:** `app/Http/Controllers/SugadorController.php` linhas 89-97 + `DashboardController.php` linhas 170-219
**Apply to:** `DevController::index()`
```php
// Padrão: map() de Collection para array puro antes de passar ao Inertia
->map(fn(Company $c) => [
    'id'   => $c->id,
    'name' => $c->name,
    // ... campos escalares, não Eloquent models
]);
return Inertia::render('ComponentName', ['prop' => $data]);
```

### Logging de erros com prefixo de módulo
**Source:** `app/Services/AdmanService.php` linhas 47-48 + `app/Jobs/SyncAdmanCompanyJob.php` linhas 40-42
**Apply to:** `AdmanService::syncCompany()` (bloco de erro do log) + `SyncAdmanCompanyJob::failed()`
```php
// Padrão do projeto — sempre usar prefixo [Módulo] + entidade
Log::error("[Adman] Erro empresa {$company->id} ({$company->name}): " . $e->getMessage());
Log::warning("[Adman] Detalhe não-crítico: " . $e->getMessage());
```

### Constantes de UI e helpers locais no topo do arquivo JSX
**Source:** `resources/js/Pages/Sugadores/Index.jsx` linhas 10-50
**Apply to:** `resources/js/Pages/Dev/Desenvolvimento.jsx` — adicionar antes dos novos sub-componentes
```jsx
// Padrão: constantes SCREAMING_SNAKE_CASE para lookup tables
const DIFF_VARIANTS = {
    criados:     'criados',
    atualizados: 'atualizados',
    ignorados:   'ignorados',
};

// Padrão: helpers de formato como arrow functions no escopo do módulo
const fmtTs = (iso) => iso ? format(new Date(iso), 'dd/MM HH:mm') : null;
```

### Route model binding para IDs de empresa
**Source:** `routes/web.php` linhas 124-132 — padrão `{company}` em rotas Sugadores e Companies
**Apply to:** `routes/web.php` — rota POST `dev.adman.sync`
```php
// Laravel resolve Company automaticamente pelo ID — 404 se não existir
Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])
    ->name('dev.adman.sync');
// No controller: public function dispatchSync(Company $company) — sem findOrFail manual
```

---

## No Analog Found

Nenhum arquivo sem analog. Todos os 8 arquivos têm correspondência direta no codebase.

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Models/`, `app/Services/`, `app/Jobs/`, `database/migrations/`, `resources/js/Pages/`, `routes/`, `tests/Feature/`
**Files scanned:** 14 arquivos lidos diretamente
**Pattern extraction date:** 2026-05-18

### Notas de implementação críticas

1. **`latestAdmanMetric` vs `latestMetrics`:** `Company` já tem `latestMetrics()` que usa `latestOfMany('reference_date')`. Para o `DevController`, usar `latestMetrics()` em vez de criar `latestAdmanMetric()` duplicado — economiza uma migration de relacionamento.

2. **`wasChanged()` após `updateOrCreate`:** Verificado como API nativa do Eloquent 12. Deve ser chamado imediatamente após o `updateOrCreate` antes de qualquer outro save/refresh no model.

3. **`format()` de date-fns requer import explícito:** O projeto não tem auto-import. Linha obrigatória: `import { format } from 'date-fns';` em `Desenvolvimento.jsx`.

4. **`phpunit.xml` usa `QUEUE_CONNECTION=sync`:** Jobs executam sincronamente nos testes de feature — `Queue::fake()` deve ser chamado antes para interceptar.

5. **Factory de `Company` para testes:** Verificar se `Company::factory()` existe antes de usar em testes. Se não existir, criar o admin e a company manualmente com `Company::create([...])`.
