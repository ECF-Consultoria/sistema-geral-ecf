# Phase 5: Fundação Fechamento - Pattern Map

**Mapped:** 2026-05-19
**Files analyzed:** 8 (5 new, 3 modified)
**Analogs found:** 8 / 8

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/YYYY_add_service_fields_to_companies.php` | migration | batch (DDL) | `database/migrations/2026_04_27_100001_add_fields_to_adman_metrics_table.php` | exact |
| `app/Models/Company.php` (modify) | model | CRUD | `app/Models/Company.php` itself | exact (self) |
| `app/Http/Controllers/AdminController.php` (modify) | controller | request-response | `app/Http/Controllers/AdminController.php` (current thin renders) + `SugadorController::updateStatus()` | exact |
| `routes/web.php` (modify) | route | request-response | `routes/web.php` lines 127, 232-238 (PATCH sugadores + admin group) | exact |
| `resources/js/Pages/Admin/Financeiro.jsx` (rewrite) | component | request-response | `resources/js/Pages/Dev/Desenvolvimento.jsx` (accordion) + `resources/js/Pages/Sugadores/Config.jsx` (useForm) | exact |
| `resources/js/Layouts/AppLayout.jsx` (modify) | layout/config | — | `resources/js/Layouts/AppLayout.jsx` line 51 itself | exact (self) |
| `tests/Feature/AdminFechamentoTest.php` | test | request-response | `tests/Feature/DevControllerTest.php` | exact |
| `tests/Unit/CompanyServiceTypeTest.php` | test | CRUD | `tests/Unit/ExampleTest.php` (skeleton only) | partial |

---

## Pattern Assignments

### `database/migrations/YYYY_add_service_fields_to_companies.php` (migration, DDL)

**Analog:** `database/migrations/2026_04_27_100001_add_fields_to_adman_metrics_table.php`

**Full file pattern** (lines 1-32):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adman_metrics', function (Blueprint $table) {
            $table->decimal('net_billing', 15, 2)->nullable()->after('revenue');
            $table->decimal('sales_fee', 15, 2)->nullable()->after('net_billing');
            // ... mais colunas com ->after() encadeado
        });
    }

    public function down(): void
    {
        Schema::table('adman_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'net_billing', 'sales_fee', 'taxes', 'shipping_cost',
                'product_cost', 'return_cost', 'profit_share', 'sold_quantity',
            ]);
        });
    }
};
```

**Adaptation for Phase 5:**
- Substituir `Schema::table('adman_metrics', ...)` por `Schema::table('companies', ...)`
- Colunas: `string('service_type')->nullable()->after('notes')`, `date('contract_start')->nullable()->after('service_type')`, `date('contract_end')->nullable()->after('contract_start')`, `string('additional_service')->nullable()->after('contract_end')`
- `down()`: `dropColumn(['service_type', 'contract_start', 'contract_end', 'additional_service'])`
- Sem docblock PHPDoc no `up()`/`down()` — o analog mais recente (`add_fields_to_adman_metrics`) não tem; o `create_adman_sync_logs` tem; usar julgamento de brevidade (sem docblock para addColumn simples)

---

### `app/Models/Company.php` (model, CRUD) — extensão

**Analog:** `app/Models/Company.php` (self — leitura direta do arquivo)

**Estado atual de `$fillable`** (lines 28-31):
```php
protected $fillable = [
    'name', 'cnpj', 'adman_account_id', 'adman_store_id', 'ml_store_id',
    'segment', 'active', 'notes',
];
```

**Estado atual de `$casts`** (line 33):
```php
protected $casts = ['active' => 'boolean'];
```

**Estado atual de `getActivitylogOptions()` — logOnly** (lines 14-26):
```php
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly(['name', 'cnpj', 'segment', 'active', 'notes', 'adman_account_id', 'ml_store_id'])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
            'created' => 'Empresa criada',
            'updated' => 'Empresa atualizada',
            'deleted' => 'Empresa excluída',
            default   => $eventName,
        });
}
```

**Mudanças Phase 5 — aplicar diferencialmente:**
1. `$fillable`: adicionar `'service_type', 'contract_start', 'contract_end', 'additional_service'` ao final do array existente
2. `$casts`: expandir para array multi-chave: `'active' => 'boolean', 'contract_start' => 'date', 'contract_end' => 'date'`
3. `logOnly(...)`: adicionar `'service_type', 'contract_start', 'contract_end'` ao array existente (não substituir os campos já lá)

---

### `app/Http/Controllers/AdminController.php` (controller, request-response) — extensão

**Analog A (thin renders):** `app/Http/Controllers/AdminController.php` (self, lines 1-28)

**Padrão atual — thin render sem import de Model** (lines 1-28):
```php
<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class AdminController extends Controller
{
    public function empresas()
    {
        return Inertia::render('Admin/Empresas');
    }

    public function financeiro()
    {
        return Inertia::render('Admin/Financeiro');
    }
    // ...
}
```

**Analog B (PATCH validate+update pattern):** `app/Http/Controllers/SugadorController.php` — `updateStatus()` (lines 118-126):
```php
public function updateStatus(Request $request, Sugador $sugador)
{
    Gate::authorize('update', $sugador);

    $data = $request->validate([
        'status'      => 'required|in:pendente,em_acao,resolvido,ignorado',
        'acao_tomada' => 'nullable|in:pausado,removido,reduzido_lance,reativado,outro',
        'observacao'  => 'nullable|string|max:5000',
    ]);
    // ... update e back()->with()
}
```

**Novos imports a adicionar no topo do AdminController:**
```php
use App\Models\Company;
use Illuminate\Http\Request;
```

**Método `fechamento()` — padrão de map com Carbon nullsafe:**
```php
public function fechamento()
{
    $companies = Company::where('active', true)
        ->orderBy('name')
        ->get()
        ->map(fn (Company $c) => [
            'id'                 => $c->id,
            'name'               => $c->name,
            'service_type'       => $c->service_type,
            'contract_start'     => $c->contract_start?->toDateString(),
            'contract_end'       => $c->contract_end?->toDateString(),
            'additional_service' => $c->additional_service,
            'has_adman'          => (bool) $c->adman_account_id,
        ]);

    return Inertia::render('Admin/Financeiro', compact('companies'));
}
```

**Método `updateFechamento()` — padrão validate+update+back:**
```php
public function updateFechamento(Request $request, Company $company)
{
    $validated = $request->validate([
        'service_type'   => 'nullable|in:polo,assessoria,incubadora',
        'contract_start' => 'nullable|date',
        'contract_end'   => 'nullable|date|after_or_equal:contract_start',
    ]);

    $company->update($validated);

    return back()->with('success', 'Fechamento atualizado.');
}
```

**Nota:** `back()->with('success', '...')` é o padrão universal do projeto — HandleInertiaRequests já injeta `flash` como shared prop para o AppLayout exibir.

---

### `routes/web.php` (route, request-response) — extensão

**Analog:** `routes/web.php` linhas 232-238 (grupo admin existente)

**Grupo admin existente** (lines 232-238):
```php
// ─── Módulo Administrativo ───────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(function () {
    Route::get('/empresas',   [AdminController::class, 'empresas'])->name('empresas');
    Route::get('/relatorio',  [AdminController::class, 'relatorio'])->name('relatorio');
    Route::get('/financeiro', [AdminController::class, 'financeiro'])->name('financeiro');
    Route::get('/inventario', [AdminController::class, 'inventario'])->name('inventario');
});
```

**Rota PATCH a adicionar dentro do grupo (nome completo resultante: `admin.financeiro.update`):**
```php
Route::patch('/financeiro/{company}', [AdminController::class, 'updateFechamento'])->name('financeiro.update');
```

**Referência de padrão PATCH com route model binding** (line 127):
```php
Route::patch('/sugadores/{sugador}/status', [SugadorController::class, 'updateStatus'])->name('sugadores.update-status');
```

**Aviso Ziggy:** o nome da rota dentro do grupo `name('admin.')` resulta em `admin.financeiro.update`. No JSX usar `route('admin.financeiro.update', empresa.id)`.

---

### `resources/js/Pages/Admin/Financeiro.jsx` (component, request-response) — reescrita

**Analog A (accordion pattern):** `resources/js/Pages/Dev/Desenvolvimento.jsx`

**Padrão accordion `SyncAdmanSection` — estado único aberto** (lines 180-211):
```jsx
function SyncAdmanSection({ empresas }) {
    const [aberta, setAberta] = useState(null);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
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

**Padrão `EmpresaRow` — linha clicável com chevron animado** (lines 126-177):
```jsx
function EmpresaRow({ empresa, expandida, onToggle }) {
    return (
        <div
            onClick={onToggle}
            className={cn(
                'flex items-center gap-4 px-2 py-3 cursor-pointer transition-colors',
                expandida ? 'bg-white/[0.05]' : 'hover:bg-white/[0.03]'
            )}
        >
            <ChevronDown
                size={14}
                className={cn('text-white/40 transition-transform duration-200 shrink-0', expandida && 'rotate-180 text-ecf-yellow')}
            />
            <span className="flex-1 text-white font-semibold text-[13px] truncate">{empresa.name}</span>
            {/* badges e botões de ação à direita */}
        </div>
    );
}
```

**Padrão `EmpresaAccordion` — painel expandido com fundo escuro** (lines 102-123):
```jsx
function EmpresaAccordion({ empresa }) {
    return (
        <div className="px-4 py-3 bg-black/30 border border-white/[0.04]">
            {/* conteúdo do accordion */}
        </div>
    );
}
```

**Analog B (useForm + submissão):** `resources/js/Pages/Sugadores/Config.jsx`

**Padrão `useForm()` inicializado com dados existentes** (lines 67-84):
```jsx
const { data, setData, put, processing, errors } = useForm({
    dias_analise: config?.dias_analise ?? 30,
    // ...
});

function submit(e) {
    e.preventDefault();
    put(route('sugadores.config.update', company.id), { preserveScroll: true });
}
```

**Adaptação para Phase 5 — usar `patch` em vez de `put`:**
```jsx
const { data, setData, patch, processing, errors } = useForm({
    service_type:   empresa.service_type   ?? '',
    contract_start: empresa.contract_start ?? '',
    contract_end:   empresa.contract_end   ?? '',
});

function submit(e) {
    e.preventDefault();
    patch(route('admin.financeiro.update', empresa.id), {
        preserveScroll: true,
        onSuccess: () => setAberta(null), // fecha accordion após save
    });
}
```

**Padrão de imports para Financeiro.jsx (a partir dos analogs):**
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Banknote, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatDate } from '@/lib/utils';
```

**Padrão de sub-componentes locais (convenção do projeto):**
- Definir `ServiceBadge`, `SemIntegracaoBadge`, `FechamentoRow`, `ServiceForm`, `FechamentoList` no mesmo arquivo
- Constante lookup local: `const SERVICE_LABELS = { polo: 'POLO', assessoria: 'Assessoria', incubadora: 'Incubadora' }`
- Inputs de data: `<input type="date" value={data.contract_start} onChange={e => setData('contract_start', e.target.value)}>`
- Classe de input (padrão Config.jsx): `'w-full h-9 px-3 rounded-lg border bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 border-white/[0.08]'`

**`formatDate()` disponível em `@/lib/utils`** (utils.js lines 18-21):
```js
export function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });
}
```
Usar: `{empresa.contract_start ? formatDate(empresa.contract_start) : '—'}` na exibição; `empresa.contract_start` (string `YYYY-MM-DD` do controller) como `value` do `<input type="date">`.

---

### `resources/js/Layouts/AppLayout.jsx` (layout, config) — 1 linha

**Analog:** `AppLayout.jsx` self, line 51

**Linha atual** (line 51):
```jsx
{ label: 'Financeiro', routeName: 'admin.financeiro', page: 'Admin/Financeiro', icon: Banknote, roles: ['admin'] },
```

**Mudança:** apenas o valor da propriedade `label` — `'Financeiro'` → `'Fechamento'`. Todas as outras propriedades permanecem inalteradas (D-01).

---

### `tests/Feature/AdminFechamentoTest.php` (test, request-response)

**Analog:** `tests/Feature/DevControllerTest.php`

**Estrutura completa do analog** (lines 1-148 — padrão a copiar diretamente):

**Namespace e use declarations** (lines 1-13):
```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFechamentoTest extends TestCase
{
    use RefreshDatabase;
```

**Padrão `criarAdmin()` — helper privado de setup** (adaptado de lines 22-34):
```php
private function criarAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}
```

**Padrão `Company::create()` sem factory** (lines 26-32):
```php
$company = Company::create([
    'name'   => 'Empresa Teste',
    'cnpj'   => '00000000000000',
    'active' => true,
    // adman_account_id omitido para testar has_adman=false
]);
```

**Padrão de assertion Inertia** (lines 49-57):
```php
$response = $this->actingAs($admin)->get('/administrativo/financeiro');

$response->assertOk();
$response->assertInertia(fn ($page) => $page
    ->component('Admin/Financeiro')
    ->has('companies', 1)
    ->where('companies.0.has_adman', false)
);
```

**Padrão de teste PATCH** (adaptado de lines 119-127):
```php
$response = $this->actingAs($admin)
    ->patch("/administrativo/financeiro/{$company->id}", [
        'service_type' => 'polo',
    ]);

$response->assertRedirect();
$response->assertSessionHas('success');
$this->assertDatabaseHas('companies', ['id' => $company->id, 'service_type' => 'polo']);
```

**Padrão de teste 403 não-admin** (lines 132-147):
```php
$consultor = User::factory()->create(['role' => 'consultor']);
$response  = $this->actingAs($consultor)->get('/administrativo/financeiro');
$response->assertStatus(403);
```

**Padrão de teste validação 422:**
```php
$response = $this->actingAs($admin)
    ->patch("/administrativo/financeiro/{$company->id}", [
        'service_type' => 'invalido',
    ]);
$response->assertStatus(422);
```

---

### `tests/Unit/CompanyServiceTypeTest.php` (test, CRUD)

**Analog:** `tests/Unit/ExampleTest.php` (skeleton — sem padrão rico no projeto)

**Nota:** Não existe padrão estabelecido de Unit test no projeto além do skeleton. O RESEARCH.md indica que `DevControllerTest.php` serve como referência primária para testes desta fase — os testes de model simples (ex: colunas existem) podem ser integrados ao `AdminFechamentoTest.php` Feature test usando `assertDatabaseHas` após migration, evitando necessidade de Unit test separado.

**Se criado, seguir estrutura mínima:**
```php
<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyServiceTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_type_aceita_valores_validos(): void
    {
        // Testa via Eloquent que os valores são persistidos corretamente
    }
}
```

---

## Shared Patterns

### Autenticação e controle de acesso
**Source:** `routes/web.php` linhas 232-233
**Apply to:** Controller methods `fechamento()` e `updateFechamento()`, rota PATCH
```php
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(function () {
    // Todas as rotas aqui exigem usuário autenticado, verificado e com role=admin
    // Middleware EnsureUserHasRole lança abort(403) automaticamente para não-admin
});
```
Nenhuma verificação adicional dentro dos métodos do AdminController é necessária — o middleware cobre tudo.

### Flash message via `back()->with()`
**Source:** `app/Http/Controllers/SugadorController.php` (padrão universal do projeto)
**Apply to:** `AdminController::updateFechamento()`
```php
return back()->with('success', 'Fechamento atualizado.');
```
HandleInertiaRequests já injeta `flash.success` como shared prop. AppLayout já exibe via toast. Nenhum código adicional necessário no frontend.

### `cn()` para composição de classes
**Source:** `resources/js/lib/utils.js` (lines 1-6) — importado em todos os componentes
**Apply to:** `Financeiro.jsx` — todos os elementos com classes condicionais
```jsx
import { cn } from '@/lib/utils';
// Uso: className={cn('base-class', condicao && 'conditional-class')}
```

### Tailwind tokens ECF — dark theme
**Source:** `tailwind.config.js` (verificado) + padrão de `Desenvolvimento.jsx`
**Apply to:** `Financeiro.jsx` — todas as classes de cor e borda
```
ecf-bg (#050507)        — fundo da página
ecf-card (#0f1116)      — cards/containers
ecf-yellow (#ffe600)    — ações primárias, chevron ativo, bordas de destaque
border-white/[0.08]     — bordas sutis
bg-white/[0.03]         — fundo de hover suave
bg-black/30             — fundo de accordion expandido (de EmpresaAccordion)
text-white/40           — texto secundário/placeholder
divide-white/[0.04]     — divisores de lista
```

### `useForm()` do Inertia — convenção do projeto
**Source:** `resources/js/Pages/Sugadores/Config.jsx` (lines 67-77)
**Apply to:** `ServiceForm` dentro de `Financeiro.jsx`
- Sempre inicializar com dados existentes do prop (não string vazia quando dado existe)
- Usar `processing` para desabilitar botão de submit
- Usar `errors.field` para exibir erros inline
- Usar `preserveScroll: true` em todos os submits de formulário inline

---

## No Analog Found

Nenhum arquivo desta fase ficou sem analog. Todos os 8 arquivos têm correspondência direta no codebase.

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Models/`, `database/migrations/`, `resources/js/Pages/`, `resources/js/Layouts/`, `routes/`, `tests/Feature/`, `tests/Unit/`
**Files scanned:** 15 arquivos lidos diretamente; ~65 migrations identificadas via Glob
**Pattern extraction date:** 2026-05-19
