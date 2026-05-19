# Phase 5: Fundação Fechamento - Research

**Researched:** 2026-05-19
**Domain:** Laravel migration + Eloquent model extension + Inertia PATCH form + React accordion inline
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Apenas o label do sidebar muda — de "Financeiro" para "Fechamento". A rota (`/administrativo/financeiro`), o `routeName` (`admin.financeiro`) e o arquivo de página (`Admin/Financeiro.jsx`) permanecem sem renomear neste milestone. Nenhuma migration de rota, apenas string em `AppLayout.jsx`.
- **D-02:** `service_type` implementado como `string` nullable (varchar) com validação PHP via `in:['polo','assessoria','incubadora']` — não usar MySQL ENUM para manter flexibilidade de evolução sem migration de schema.
- **D-03:** `contract_start` e `contract_end` como colunas `date` nullable.
- **D-04:** `additional_service` como `string` nullable (campo reservado, sem lógica neste milestone).
- **D-05:** Adicionar `service_type`, `contract_start`, `contract_end`, `additional_service` ao `$fillable` do model.
- **D-06:** `contract_start` e `contract_end` adicionados ao `$casts` como `'date'` para retornar Carbon automaticamente.
- **D-07:** Adicionar `service_type`, `contract_start`, `contract_end` ao `logOnly` do `getActivitylogOptions()` — auditoria.
- **D-08:** Adicionar método `fechamento()` ao `AdminController` existente (já thin, 4 renders). Suficiente para esta fase.
- **D-09:** O método `fechamento()` retorna `Inertia::render('Admin/Financeiro', [...])` com props: `companies` (array com campos de fechamento + `has_adman` flag).
- **D-10:** Listar apenas empresas com `active = true`.
- **D-11:** Ordenação: alfabética por `name`.
- **D-12:** Badge "Sem integração" para empresas onde `adman_account_id` é null — flag `has_adman = (bool)$company->adman_account_id` passada via props.
- **D-13:** Formulário de edição via accordion inline — clicar no nome da empresa expande uma seção com campos de tipo de serviço (select) e datas de contrato (date inputs). Não usar modal.
- **D-14:** Submissão via `useForm()` do Inertia com `PATCH /administrativo/financeiro/{company}`. Flash de sucesso/erro via `back()->with()`.
- **D-15:** Apenas um accordion aberto por vez.
- **D-16:** Adicionar rota `PATCH /administrativo/financeiro/{company}` no grupo admin de `routes/web.php`, apontando para `AdminController@updateFechamento`. Validação: `service_type in:polo,assessoria,incubadora|nullable`, `contract_start date|nullable`, `contract_end date|after_or_equal:contract_start|nullable`.

### Claude's Discretion

- Estrutura interna do JSX (sub-componentes locais como `ServiceBadge`, `FechamentoRow`).
- Formato de exibição das datas no acordeão (se populadas: "dd/mm/yyyy"; se nulas: "—").
- Ícone do item de sidebar — manter `Banknote` (Lucide).

### Deferred Ideas (OUT OF SCOPE)

- Lógica de valor para `additional_service`
- Histórico de fechamentos por empresa (v2.1+)
- Exportação CSV da lista (v2.1+)
- Agrupamento da lista por tipo de serviço (Phase 7 opcional)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FCH-01 | Admin pode ver todas as empresas cadastradas na tela Fechamento, incluindo empresas sem integração Adman (exibidas com badge "Sem integração") | `AdminController::fechamento()` retorna `Company::where('active', true)->orderBy('name')->get()` com `has_adman` flag; Financeiro.jsx itera e exibe badge quando `!company.has_adman` |
| FCH-02 | Admin pode configurar o tipo de serviço de cada empresa (POLO / Assessoria / Incubadora) | Coluna `service_type` (varchar nullable) + `$fillable` + select inline no accordion + PATCH `updateFechamento()` com validação `in:polo,assessoria,incubadora\|nullable` |
| FCH-03 | Admin pode registrar e ver as datas de início e encerramento do contrato de cada empresa | Colunas `contract_start`/`contract_end` (date nullable) + `$casts as date` + date inputs no accordion + validação `date\|nullable` + `after_or_equal:contract_start` |
| CFG-01 | O label "Financeiro" no sidebar é renomeado para "Fechamento" | Trocar string `'Financeiro'` → `'Fechamento'` na linha 51 de `AppLayout.jsx` (array `NAV_ITEMS`); rota, routeName e arquivo JSX permanecem inalterados (D-01) |
</phase_requirements>

---

## Summary

Esta fase entrega a fundação técnica do módulo Fechamento em quatro entregáveis independentes: uma migration que adiciona quatro colunas à tabela `companies`, a atualização do model `Company` para expor esses campos via `$fillable`/`$casts`/`logOnly`, a substituição do placeholder `Admin/Financeiro.jsx` por uma tela funcional que lista todas as empresas ativas com badge "Sem integração" para as sem `adman_account_id`, e a troca do label "Financeiro" por "Fechamento" no sidebar.

O código existente provê todos os padrões necessários: a migration `create_adman_sync_logs_table` e `add_fields_to_adman_metrics_table` demonstram o estilo `Schema::table()` com `up/down` explícito; o `DevControllerTest.php` demonstra como criar testes de controller com `RefreshDatabase` sem factory de `Company`; a `SyncAdmanSection` em `Desenvolvimento.jsx` é o padrão exato de accordion inline com `useState(null)` para controlar qual item está aberto; e `SugadorConfig.jsx` demonstra o ciclo completo de `useForm()` + `put(route(...))` + `preserveScroll`.

A única ambiguidade documentada é a discordância de traceability: REQUIREMENTS.md mapeia CFG-01 para Phase 7, mas ROADMAP.md e CONTEXT.md a incluem em Phase 5. A decisão está travada pelo CONTEXT.md (D-01) — CFG-01 faz parte desta fase. O REQUIREMENTS.md contém um artefato de documentação desatualizado que não precisa de correção de schema, apenas de implementação.

**Primary recommendation:** Implementar em 3 planos sequenciais — (1) migration + model, (2) controller + rota, (3) UI + sidebar label + npm run build.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Schema — novas colunas em `companies` | Database/Migration | — | Mudança de schema DDL; não tem lógica de aplicação |
| Model — fillable/casts/logOnly | API/Backend (Model) | — | Eloquent é a única camada que toca o schema |
| Listagem de empresas ativas com `has_adman` | API/Backend (Controller) | — | Agregação de dados server-side; entregue via Inertia props |
| Persistência de `service_type` e datas | API/Backend (Controller) | — | Validação e save no controller; sem lógica de negócio complexa |
| Badge "Sem integração" | Frontend (React) | — | Flag booleana já vem no prop; renderização pura |
| Accordion inline + formulário | Frontend (React) | — | Estado local UI (`useState`); não requer store global |
| Label sidebar "Fechamento" | Frontend (React) | — | String no array `NAV_ITEMS` em `AppLayout.jsx` |

---

## Standard Stack

Nenhum pacote novo é necessário. Todos os componentes são extensões de código existente no projeto.

### Core (já instalado)

| Biblioteca | Versão | Propósito nesta fase | Why Standard |
|------------|--------|---------------------|--------------|
| Laravel 12 + Eloquent | ^12.0 | Migration DDL + model extension | Stack obrigatório |
| Inertia.js Laravel | ^2.0 | `Inertia::render()` com props | Stack obrigatório |
| `@inertiajs/react` | ^2.0 | `useForm()`, `router.patch()` | Padrão estabelecido em toda a app |
| React 18 | ^18.2 | JSX accordion + form state | Stack obrigatório |
| `spatie/laravel-activitylog` | ^4.9 | `logOnly` audit trail no model | Já em uso no Company model |
| Tailwind CSS v3 + tokens `ecf-*` | ^3.2 | Dark theme, badges, cards | Design system estabelecido |
| `cn()` (clsx + tailwind-merge) | — | Composição de classes condicionais | Padrão em todos os componentes |

### Sem instalação nova

Esta fase não requer `npm install` ou `composer require`. Todos os pacotes listados acima já estão no lockfile.

---

## Package Legitimacy Audit

> Não aplicável. Nenhum pacote externo novo é instalado nesta fase.

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (click "Fechamento" no sidebar)
    │
    ▼
AppLayout.jsx — NAV_ITEMS[51] label: "Fechamento" → routeName: admin.financeiro
    │
    ▼
routes/web.php — grupo admin (/administrativo, middleware: auth+verified+role:admin)
    │   GET  /financeiro          → AdminController@fechamento
    │   PATCH /financeiro/{company} → AdminController@updateFechamento
    │
    ▼ GET
AdminController::fechamento()
    │  Company::where('active', true)->orderBy('name')
    │  → map → ['id', 'name', 'service_type', 'contract_start', 'contract_end',
    │            'additional_service', 'has_adman']
    │
    ▼
Inertia::render('Admin/Financeiro', ['companies' => [...]])
    │
    ▼
Financeiro.jsx
    ├── Header "Fechamento"
    ├── FechamentoRow (por empresa)
    │   ├── nome + badge "Sem integração" (se !has_adman)
    │   └── [accordion aberto] → ServiceForm
    │       ├── select service_type (polo/assessoria/incubadora)
    │       ├── input contract_start (date)
    │       └── input contract_end (date) → useForm().patch(route('admin.financeiro.update', id))
    │
    ▼ PATCH (submit)
AdminController::updateFechamento(Request, Company)
    ├── validate(['service_type' => 'nullable|in:polo,assessoria,incubadora',
    │             'contract_start' => 'nullable|date',
    │             'contract_end' => 'nullable|date|after_or_equal:contract_start'])
    ├── $company->update($validated)  ← Eloquent; LogsActivity captura dirty fields
    └── back()->with('success', 'Fechamento atualizado.')
         └── flash → HandleInertiaRequests → AppLayout toast
```

### Recommended Project Structure (mudanças desta fase)

```
app/
├── Http/
│   └── Controllers/
│       └── AdminController.php      # + fechamento() + updateFechamento()
├── Models/
│   └── Company.php                  # + fillable + casts + logOnly
database/
└── migrations/
    └── 2026_05_19_100001_add_service_fields_to_companies.php   # nova
resources/
└── js/
    ├── Layouts/
    │   └── AppLayout.jsx             # linha 51: 'Financeiro' → 'Fechamento'
    └── Pages/
        └── Admin/
            └── Financeiro.jsx        # reescrita completa (hoje: placeholder)
routes/
└── web.php                           # + PATCH /administrativo/financeiro/{company}
tests/
└── Feature/
    └── AdminFechamentoTest.php       # novo (Nyquist)
```

### Pattern 1: Accordion inline com único aberto (padrão Phase 1)

**What:** `useState(null)` armazena o `id` da linha atualmente expandida. Toggle fecha se o mesmo id é clicado de novo.
**When to use:** Lista de itens com edição inline; sem necessidade de múltiplos abertos simultâneos (D-15).

```jsx
// Source: resources/js/Pages/Dev/Desenvolvimento.jsx (SyncAdmanSection)
function FechamentoList({ companies }) {
    const [aberta, setAberta] = useState(null);

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {companies.map(empresa => (
                <div key={empresa.id}>
                    <FechamentoRow
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                    />
                    {aberta === empresa.id && <ServiceForm empresa={empresa} />}
                </div>
            ))}
        </div>
    );
}
```

### Pattern 2: useForm() + patch com preserveScroll

**What:** `useForm()` do Inertia inicializa com dados existentes do prop; `.patch()` submete PATCH ao servidor; `preserveScroll: true` mantém posição do scroll.
**When to use:** Formulários inline que editam um recurso existente sem navegar para nova página.

```jsx
// Source: resources/js/Pages/Sugadores/Config.jsx (padrão put; adaptar para patch)
function ServiceForm({ empresa }) {
    const { data, setData, patch, processing, errors } = useForm({
        service_type:    empresa.service_type   ?? '',
        contract_start:  empresa.contract_start ?? '',
        contract_end:    empresa.contract_end   ?? '',
    });

    function submit(e) {
        e.preventDefault();
        patch(route('admin.financeiro.update', empresa.id), {
            preserveScroll: true,
        });
    }

    return (
        <form onSubmit={submit} className="px-4 py-3 bg-black/30 border border-white/[0.04] space-y-3">
            {/* select + date inputs aqui */}
            <button type="submit" disabled={processing}>
                {processing ? 'Salvando...' : 'Salvar'}
            </button>
        </form>
    );
}
```

### Pattern 3: Migration addColumn com up/down

**What:** `Schema::table()` para adicionar colunas a tabela existente; `down()` usa `dropColumn([...])`.
**When to use:** Toda vez que se precisa adicionar colunas a tabela já criada.

```php
// Source: database/migrations/2026_04_27_100001_add_fields_to_adman_metrics_table.php
public function up(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->string('service_type')->nullable()->after('notes');
        $table->date('contract_start')->nullable()->after('service_type');
        $table->date('contract_end')->nullable()->after('contract_start');
        $table->string('additional_service')->nullable()->after('contract_end');
    });
}

public function down(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->dropColumn(['service_type', 'contract_start', 'contract_end', 'additional_service']);
    });
}
```

### Pattern 4: Model Company — extensão de fillable/casts/logOnly

**What:** Adicionar campos novos às três estruturas do model em um único passo.

```php
// Source: app/Models/Company.php (estado atual — verificado)
// Estado atual de $fillable:
protected $fillable = [
    'name', 'cnpj', 'adman_account_id', 'adman_store_id', 'ml_store_id',
    'segment', 'active', 'notes',
];

// Após Phase 5:
protected $fillable = [
    'name', 'cnpj', 'adman_account_id', 'adman_store_id', 'ml_store_id',
    'segment', 'active', 'notes',
    'service_type', 'contract_start', 'contract_end', 'additional_service',
];

// Estado atual de $casts:
protected $casts = ['active' => 'boolean'];

// Após Phase 5:
protected $casts = [
    'active'         => 'boolean',
    'contract_start' => 'date',
    'contract_end'   => 'date',
];

// logOnly atual: ['name', 'cnpj', 'segment', 'active', 'notes', 'adman_account_id', 'ml_store_id']
// Após Phase 5 adicionar: 'service_type', 'contract_start', 'contract_end'
```

### Pattern 5: Controller thin — adicionar métodos a AdminController

```php
// Source: app/Http/Controllers/AdminController.php (estado atual — verificado: 4 métodos, thin)
use App\Models\Company;
use Illuminate\Http\Request;

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

### Pattern 6: Rota PATCH no grupo admin

```php
// Source: routes/web.php linhas 232-238 (estado atual — verificado)
// Adicionar dentro do grupo middleware(['auth', 'verified', 'role:admin']) prefix('administrativo') name('admin.'):
Route::patch('/financeiro/{company}', [AdminController::class, 'updateFechamento'])
    ->name('financeiro.update');
```

### Anti-Patterns to Avoid

- **Usar ENUM MySQL para service_type:** Decisão D-02 trava `string` nullable. ENUM exige nova migration para adicionar valores; string com validação PHP é flexível.
- **Abrir múltiplos accordions:** D-15 trava único aberto por vez. Usar `useState(null)` com toggle — não `useState([])` com array de ids.
- **FormRequest separado:** Para validações simples como estas (3 campos), a convenção do projeto é `$request->validate()` inline no controller, não criar `AdminFechamentoRequest`.
- **Renomear rota ou arquivo JSX:** D-01 proíbe explicitamente. Só o label string muda.
- **Buscar dados extras por fetch dentro do accordion:** Props Inertia já trazem todos os campos de fechamento; accordion exibe o que já está em memória.
- **`$table->enum()` em SQLite de test:** Em testes PHPUnit, DB é SQLite in-memory; `dropColumn` com ENUM pode gerar problema em SQLite. Usar `string` (D-02) resolve isso automaticamente.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Form state + erros + loading | `useState` + `fetch` manual | `useForm()` de `@inertiajs/react` | Já cuida de processing, errors, dirty state, CSRF |
| Flash de sucesso | alert/toast manual no cliente | `back()->with('success', ...)` + `useEffect` já no AppLayout | Padrão estabelecido; `HandleInertiaRequests` já injeta `flash` |
| Audit trail de mudanças | Código de log manual | `spatie/laravel-activitylog` via `LogsActivity` já no Company | Já instalado; basta adicionar campos ao `logOnly` |
| Date formatting JS | Funções custom | `formatDate()` em `@/lib/utils` | Já existe, usa `Intl.DateTimeFormat` com timezone `America/Sao_Paulo` |
| Scroll preservation após save | Scroll manual | `preserveScroll: true` no `useForm().patch()` | Inertia cuida disso nativamente |

---

## Common Pitfalls

### Pitfall 1: Carbon serialization no Inertia prop

**What goes wrong:** Se `contract_start` e `contract_end` são passados como objetos Carbon direto no prop (sem `->toDateString()`), a serialização JSON gera um objeto com chaves de timezone que o input `type="date"` do HTML não consegue pré-preencher.
**Why it happens:** `$casts = ['contract_start' => 'date']` retorna Carbon; `compact()` serializa o objeto inteiro.
**How to avoid:** No `map()` do controller, usar `$c->contract_start?->toDateString()` para entregar string `"YYYY-MM-DD"` — formato que o `<input type="date">` aceita como `value`.
**Warning signs:** Input de data aparece vazio mesmo quando empresa tem data salva.

### Pitfall 2: `dropColumn` com múltiplas colunas no SQLite (testes)

**What goes wrong:** SQLite não suporta `ALTER TABLE DROP COLUMN` para múltiplas colunas em versões antigas. Ao rodar `php artisan test` (DB SQLite in-memory), o `down()` pode falhar.
**Why it happens:** `$table->dropColumn(['a', 'b', 'c'])` usa múltiplas operações internas; suporte varia.
**How to avoid:** Testar o `up()` — o `down()` em SQLite pode ser omitido dos testes (PHPUnit usa `RefreshDatabase` que recria tudo a cada teste). Não executar `migrate:rollback` em testes.
**Warning signs:** Erro `General error: 1 Cannot drop column` ao rodar `php artisan test`.

### Pitfall 3: Rota PATCH não encontrada pelo Ziggy

**What goes wrong:** `route('admin.financeiro.update', empresa.id)` lança `Error: Ziggy route not found`.
**Why it happens:** O nome da rota no `routes/web.php` usa o prefixo `name('admin.')` do grupo, então o nome completo é `admin.financeiro.update`. Se a rota for nomeada `.update` fora do grupo, o prefixo não é aplicado.
**How to avoid:** A rota PATCH deve ser declarada dentro do grupo `name('admin.')` existente — nome resultante: `admin.financeiro.update`. Verificar com `php artisan route:list --name=admin.financeiro`.
**Warning signs:** 500 no frontend com "Ziggy route not found"; ou 404 se a rota estiver fora do grupo de middleware.

### Pitfall 4: Badge "Sem integração" confundida com empresas inativas

**What goes wrong:** A lista mostra empresas sem `adman_account_id` com badge de erro/warning, dando impressão de que há problema quando é apenas ausência de integração esperada.
**Why it happens:** Usar a mesma cor de badge de erro (vermelho) para indicar ausência de integração.
**How to avoid:** Badge "Sem integração" deve usar cor neutra/amber (ex: `ecf-yellow/10` text-ecf-yellow ou branco/40), não vermelho destrutivo. Contexto: faturamento virá na Phase 6 — badge é informativo, não erro.

### Pitfall 5: `back()->with()` não retorna props atualizadas

**What goes wrong:** Após salvar com sucesso via PATCH, os valores do formulário na UI continuam com os dados antigos se o accordion permanece aberto.
**Why it happens:** `back()` faz redirect para a mesma rota GET, mas o Inertia precisa re-renderizar os props. Se usado `preserveScroll` mas não `preserveState: false`, props antigas podem ficar em memória.
**How to avoid:** `useForm()` + `patch(route(...), { preserveScroll: true })` sem `preserveState` — Inertia vai re-buscar os props frescos do controller no GET. O accordion vai fechar se quisermos (via `onSuccess: () => setAberta(null)`), ou permanecer aberto com dados atualizados.

---

## Code Examples

### Service Badge (sub-componente local)

```jsx
// Padrão: sub-componente local definido no mesmo arquivo da página (convenção do projeto)
const SERVICE_LABELS = {
    polo:        'POLO',
    assessoria:  'Assessoria',
    incubadora:  'Incubadora',
};

function ServiceBadge({ tipo }) {
    if (!tipo) return <span className="text-white/30 text-[12px]">—</span>;
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-white/[0.06] text-white/60 border border-white/[0.08]">
            {SERVICE_LABELS[tipo] ?? tipo}
        </span>
    );
}

function SemIntegracaoBadge() {
    return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-ecf-yellow/10 text-ecf-yellow/70 border border-ecf-yellow/20">
            Sem integração
        </span>
    );
}
```

### Formato de data para exibição (pt-BR)

```jsx
// Source: resources/js/lib/utils.js — formatDate() já existe
import { formatDate } from '@/lib/utils';

// Uso no accordion (se populada, "dd/mm/yyyy"; se nula, "—"):
{empresa.contract_start ? formatDate(empresa.contract_start) : '—'}
```

### Controller — map com Carbon nullsafe

```php
// Source: padrão a partir de app/Models/Company.php + castagem Carbon
->map(fn (Company $c) => [
    'id'                 => $c->id,
    'name'               => $c->name,
    'service_type'       => $c->service_type,
    'contract_start'     => $c->contract_start?->toDateString(), // "YYYY-MM-DD" ou null
    'contract_end'       => $c->contract_end?->toDateString(),   // nullsafe — Carbon cast
    'additional_service' => $c->additional_service,
    'has_adman'          => (bool) $c->adman_account_id,
])
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|---|---|---|---|
| `AdminController::financeiro()` retorna render vazio (placeholder) | Substituir por render com props `companies` | Phase 5 | Controller deixa de ser stub |
| `Financeiro.jsx` mostra "Em desenvolvimento" | Reescrita completa com lista + accordion + formulário | Phase 5 | Primeira tela funcional do módulo Fechamento |

**Deprecated/outdated:**
- Placeholder `EmDesenvolvimento` em `Financeiro.jsx`: removido e substituído pela tela funcional nesta fase.

---

## CFG-01 Traceability Discrepancy

**Discrepância encontrada:** `REQUIREMENTS.md` (linha 51) mapeia CFG-01 para Phase 7. `ROADMAP.md` (linha 93) e `CONTEXT.md` (linha 11 e 74) incluem CFG-01 no escopo da Phase 5.

**Resolução:** CONTEXT.md é o documento de decisão mais recente e específico para esta fase. A decisão D-01 do CONTEXT.md confirma: "Apenas o label do sidebar muda — de 'Financeiro' para 'Fechamento'... nenhuma migration de rota, apenas string em AppLayout.jsx." CFG-01 é implementado na Phase 5. O REQUIREMENTS.md contém um artefato de mapeamento desatualizado — não requer nenhuma ação corretiva além de implementar CFG-01 nesta fase como decidido.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `after('notes')` é a posição correta para as novas colunas em MySQL produção | Migration pattern | Colunas aparecem em posição diferente — sem impacto funcional, apenas cosmético |

**Todas as demais afirmações foram verificadas diretamente no código-fonte da aplicação.**

---

## Open Questions

1. **CFG-01 no REQUIREMENTS.md mapeia Phase 7**
   - What we know: CONTEXT.md e ROADMAP.md Phase 5 incluem CFG-01; REQUIREMENTS.md diz Phase 7.
   - What's unclear: Intenção original do traceability.
   - Recommendation: Implementar em Phase 5 conforme CONTEXT.md. Atualizar linha 51 de REQUIREMENTS.md para `Phase 5` ao final da fase para manter consistência documental.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Node.js / npm | `npm run build` após edição JSX | Verificado via bash | v24.15.0 / 11.12.1 | — |
| PHP artisan | migration, testes | Disponível via XAMPP (não acessível via bash, mas disponível no Windows) | Laravel 12 | — |
| PHPUnit | `php artisan test` | Detectado em `vendor/` + `phpunit.xml` | PHPUnit 11.x | — |
| SQLite in-memory | Testes PHPUnit | Configurado em `phpunit.xml` (`DB_DATABASE=:memory:`) | — | — |

**Missing dependencies with no fallback:** Nenhum.

---

## Validation Architecture

> `workflow.nyquist_validation: true` — seção obrigatória.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `php artisan test --filter AdminFechamento` |
| Full suite command | `php artisan test` |

### Padrão de teste estabelecido (Phase 1)

`DevControllerTest.php` demonstra o padrão exato para esta fase:
- `use RefreshDatabase` — recria schema SQLite in-memory para cada teste
- `Company::create([...])` direto (sem factory) — padrão já estabelecido
- `$this->actingAs($admin)->get('/...')` — autenticação inline
- `->assertInertia(fn ($page) => $page->component('...')->has('...', N)->where(...))` — assertions Inertia
- `User::factory()->create(['role' => 'admin'])` — factory de User disponível

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FCH-01 | `fechamento()` retorna apenas empresas `active=true`, ordenadas por name, com `has_adman` flag | Feature | `php artisan test --filter test_fechamento_retorna_empresas_ativas_com_has_adman` | Wave 0 |
| FCH-01 | Empresa sem `adman_account_id` recebe `has_adman=false` | Feature | `php artisan test --filter test_empresa_sem_adman_recebe_has_adman_false` | Wave 0 |
| FCH-01 | Empresa inativa não aparece na listagem | Feature | `php artisan test --filter test_empresa_inativa_nao_aparece` | Wave 0 |
| FCH-02 | `updateFechamento()` persiste `service_type` válido | Feature | `php artisan test --filter test_update_persiste_service_type` | Wave 0 |
| FCH-02 | `updateFechamento()` rejeita `service_type` inválido (422) | Feature | `php artisan test --filter test_update_rejeita_service_type_invalido` | Wave 0 |
| FCH-03 | `updateFechamento()` persiste `contract_start` e `contract_end` | Feature | `php artisan test --filter test_update_persiste_datas_contrato` | Wave 0 |
| FCH-03 | `updateFechamento()` rejeita `contract_end` anterior a `contract_start` (422) | Feature | `php artisan test --filter test_update_rejeita_contract_end_anterior` | Wave 0 |
| FCH-01+02+03 | Não-admin recebe 403 em GET e PATCH | Feature | `php artisan test --filter test_nao_admin_recebe_403` | Wave 0 |
| CFG-01 | Sidebar exibe "Fechamento" (não "Financeiro") | Manual | Inspeção visual pós-build | Manual-only |
| Migration | Colunas existem na tabela companies | Unit | `php artisan test --filter test_migration_adiciona_colunas` | Wave 0 |

**Manual-only justificativa:** CFG-01 (label sidebar) é uma mudança de string em JSX; verificação é visual via browser após `npm run build`. Não há teste automatizado necessário para uma string literal — o controle de qualidade é o checkpoint humano.

### Sampling Rate

- **Por task commit:** `php artisan test --filter AdminFechamento`
- **Por wave merge:** `php artisan test`
- **Phase gate:** Full suite verde antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/AdminFechamentoTest.php` — cobre FCH-01, FCH-02, FCH-03, controle de acesso, e teste de migration (5–8 testes)
- [ ] Nenhum `conftest.php` necessário — `DevControllerTest.php` mostra que o projeto usa método privado no próprio test class para setup

*(Infraestrutura de teste existente (phpunit.xml, TestCase.php, RefreshDatabase) cobre todos os requisitos da fase — apenas o arquivo de teste novo precisa ser criado.)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---|---|---|
| V2 Authentication | Sim | Middleware `auth` já aplicado no grupo admin |
| V3 Session Management | Sim | Sessão Laravel padrão (database driver); CSRF via token Inertia |
| V4 Access Control | Sim | Middleware `role:admin` via `EnsureUserHasRole` já no grupo `/administrativo` |
| V5 Input Validation | Sim | `$request->validate()` inline no `updateFechamento()` com regras `in:`, `date`, `after_or_equal` |
| V6 Cryptography | Não | Nenhum dado sensível armazenado nesta fase |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| PATCH para company de outro tenant | Tampering | Não aplicável — não há multi-tenancy nesta app; `role:admin` garante que só admin acessa |
| Service_type injetando valor arbitrário | Tampering | Validação `in:polo,assessoria,incubadora\|nullable` no controller |
| SQL injection via date input | Tampering | Eloquent parameterized queries — `$company->update($validated)` |
| Acesso não autenticado a `/administrativo/financeiro` | Elevation of Privilege | Middleware `auth+verified+role:admin` no grupo de rotas |

---

## Sources

### Primary (HIGH confidence)

- Código-fonte verificado diretamente:
  - `app/Models/Company.php` — fillable atual, casts, logOnly, relacionamentos
  - `app/Http/Controllers/AdminController.php` — 4 métodos thin; `financeiro()` é stub vazio
  - `routes/web.php` linhas 232-238 — grupo admin, rota GET `/financeiro` existente
  - `resources/js/Layouts/AppLayout.jsx` linha 51 — `NAV_ITEMS` entry: `{ label: 'Financeiro', routeName: 'admin.financeiro', ... }`
  - `resources/js/Pages/Admin/Financeiro.jsx` — placeholder "Em desenvolvimento"
  - `resources/js/Pages/Dev/Desenvolvimento.jsx` — padrão accordion `SyncAdmanSection` (referência D-13)
  - `resources/js/Pages/Sugadores/Config.jsx` — padrão `useForm()` + `put()` + `preserveScroll`
  - `database/migrations/2026_04_27_100001_add_fields_to_adman_metrics_table.php` — padrão `Schema::table` + `dropColumn`
  - `database/migrations/2026_04_26_152217_create_companies_table.php` — schema atual de `companies`
  - `tests/Feature/DevControllerTest.php` — padrão de teste PHPUnit para controllers Inertia
  - `phpunit.xml` — configuração SQLite in-memory
  - `tailwind.config.js` — tokens `ecf.*` verificados
  - `resources/js/lib/utils.js` — `formatDate()` verificado

### Secondary (MEDIUM confidence)

- Inertia.js docs: `useForm().patch()` com `preserveScroll` [ASSUMED — padrão amplamente evidenciado nos 29 arquivos com `useForm` no projeto]

### Tertiary (LOW confidence)

- Nenhuma fonte de baixa confiança utilizada nesta pesquisa.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — tudo verificado no código-fonte; nenhum pacote novo
- Architecture: HIGH — baseado em código existente (DevController + SugadorConfig) como referência
- Pitfalls: HIGH — identificados a partir de padrões concretos do projeto e SQLite constraints verificadas
- Validation: HIGH — modelo de teste copiado de DevControllerTest.php

**Research date:** 2026-05-19
**Valid until:** Estável — fase usa apenas código interno; sem dependências externas que mudem
