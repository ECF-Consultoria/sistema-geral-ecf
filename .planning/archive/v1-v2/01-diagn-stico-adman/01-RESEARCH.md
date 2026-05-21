# Phase 1: Diagnóstico Adman - Research

**Researched:** 2026-05-18
**Domain:** Laravel 12 + Inertia.js + React — diagnóstico e controle de sync Adman
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** O diagnóstico Adman deve ser uma **seção inline** na página `/dev/desenvolvimento` existente — não criar sub-página `/dev/adman`. Adicionar abaixo do card da extensão Chrome usando o `DevCard` já existente.
- **D-02:** Detalhes de cada empresa (payload bruto, diff) devem ser exibidos via **accordion inline** — clicar na empresa expande uma linha com os detalhes abaixo, sem mudança de página ou modal.

### Claude's Discretion

- **Armazenamento do log de sync:** Criar nova tabela `adman_sync_logs` (estruturada) ou aproveitar o `activity_log` do Spatie ou buscar da API sob demanda. Claude decide com base no que for mais simples de implementar e manter.
- **Campos do payload exibido:** Exibir campos-chave resumidos (grossBilling, netBilling, TACOS, soldQty, profitMargin) com JSON bruto expansível, ou mostrar apenas o JSON bruto. Claude decide o que for mais útil para debug.
- **Disparo do sync manual:** Enfileirar `AnalyzeCompanySugadoresJob` existente ou criar novo job específico; feedback ao usuário (toast/polling). Claude decide a abordagem mais robusta.
- **Formato de timestamp:** Exibir como "2h atrás" (relativo) ou data/hora absoluta (dd/mm HH:mm). Claude decide.

### Deferred Ideas (OUT OF SCOPE)

- Sub-página `/dev/adman` com histórico completo e paginação — pode ser v2 se a seção inline ficar lotada
- Filtro de empresas por status (só com erro, só com sync atrasado) — Fase 2 ou v2
- Alertas automáticos quando sync falha — v2 (DEV-ALERT-01 no backlog)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DEV-01 | Admin pode ver data/hora do último sync Adman por empresa | `AdmanMetric.synced_at` existe e é atualizado em cada `syncCompany()` — disponível imediatamente via query por empresa |
| DEV-02 | Admin pode ver o payload bruto retornado pela API Adman (ou erro HTTP) de cada sync | `AdmanMetric.raw_data` (JSON) armazena `summarizedData` da API; erro HTTP requer nova coluna (tabela nova) |
| DEV-03 | Admin pode ver o diff do sync: quantos registros foram criados, atualizados e ignorados | `AdmanMetric::updateOrCreate()` não rastreia diff; requer nova tabela `adman_sync_logs` com colunas de contagem |
| DEV-04 | Admin pode disparar o sync Adman de uma empresa específica manualmente via botão | `SyncAdmanCompanyJob` já existe e aceita `Company` — basta criar rota POST e dispatchar |
</phase_requirements>

---

## Summary

A Phase 1 constrói uma seção de diagnóstico inline na página `/dev/desenvolvimento` existente, permitindo que admins inspecionem o estado do sync Adman por empresa sem acesso ao servidor. A análise do codebase revelou que **a maior parte da infraestrutura já existe**: `AdmanService::syncCompany()` é o método principal de sync, `SyncAdmanCompanyJob` é o job correto para dispatch manual, e `AdmanMetric` já armazena `synced_at` e `raw_data`. O gap crítico é rastreamento de diff: `updateOrCreate()` não conta criações vs atualizações, exigindo uma nova tabela `adman_sync_logs`.

A UI-SPEC aprovada define 5 componentes novos (`SyncAdmanSection`, `EmpresaRow`, `EmpresaAccordion`, `DiffBadge`, `JsonViewer`) todos inline em `Desenvolvimento.jsx`. O sistema de flash/toast já existe via `HandleInertiaRequests` + `AppLayout` — `return back()->with('success', ...)` é o padrão correto para feedback do dispatch. A rota `/dev/desenvolvimento` atualmente usa closure sem controller; migrar para `DevController` é necessário para passar props de dados.

**Recomendação principal:** Criar tabela `adman_sync_logs` + `DevController` + `SyncAdmanCompanyJob` dispatch. Reutilizar `AdmanMetric.synced_at` + `raw_data` para DEV-01 e DEV-02. Tabela de logs cobre DEV-03 (diff) e o campo `error_message` cobre DEV-02 em caso de erro HTTP.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Listar empresas com status de sync | API / Backend (Controller) | Database/Storage (AdmanMetric) | Query de dados — controller agrega Company + AdmanMetric + adman_sync_logs |
| Exibir payload bruto e diff | Frontend (React/JSX) | — | Renderização de dados já montados no controller |
| Disparar sync manual | API / Backend (Controller + Job) | Queue Worker | POST Inertia → controller → `SyncAdmanCompanyJob::dispatch()` |
| Accordion de empresa | Browser / Client | — | Estado local React (`useState`) — não é estado global |
| Feedback de disparo (toast) | Frontend Server (Inertia flash) | Browser (AppLayout toast) | `flash.success` via `HandleInertiaRequests` → `setToast` em `AppLayout` |
| Armazenamento de log de sync | Database / Storage (nova tabela) | — | `adman_sync_logs` persiste diff + erro + raw_data por execução |

---

## Standard Stack

### Core (já existente — sem instalação necessária)

| Biblioteca | Versão | Propósito | Por que usar |
|------------|--------|-----------|--------------|
| Laravel 12 | ^12.0 | Controller, migration, job, rota | Stack base do projeto |
| Inertia.js (Laravel) | ^2.0 | Bridge server→React, flash messages | Padrão do projeto — sem API REST separada |
| `@inertiajs/react` | ^2.0 | `router.post()`, `usePage()` | Feedback de dispatch e leitura de flash |
| React 18 | ^18.2.0 | Componentes da seção (accordion, rows) | Único framework frontend |
| Tailwind CSS | ^3.2.1 | Dark theme ECF, tokens `ecf-*` | Sistema de design do projeto |
| `lucide-react` | ^1.11.0 | Ícones (`RefreshCw`, `ChevronDown`, `AlertTriangle`) | Ícones já usados no projeto |
| `class-variance-authority` + `clsx` + `tailwind-merge` | já instalados | `cn()` para composição de classes | Padrão de todo componente ECF |

### Componentes UI reutilizados (já existem em `@/Components/ui/`)

| Componente | Variantes relevantes | Uso nesta fase |
|-----------|---------------------|----------------|
| `Button` | `variant="ghost" size="sm"` | Botão "Disparar sync" por empresa |
| `Badge` | `variant="success"`, `variant="destructive"` | Status OK / Erro por empresa |

### Alternativas consideradas e descartadas

| Em vez de | Poderia usar | Descartado porque |
|-----------|-------------|-------------------|
| Nova tabela `adman_sync_logs` | `activity_log` do Spatie | Spatie não estrutura diff (criados/atualizados/ignorados) nem erro HTTP de forma consultável; query seria frágil |
| `SyncAdmanCompanyJob` existente | Job novo `DispatchAdmanSyncJob` | Job existente já aceita `Company + ?date`, tem backoff correto (60/300/900s), 3 tentativas — perfeito |
| Flash Inertia para feedback | Polling HTTP / WebSocket | Requisitos não demandam tempo real; flash é suficiente para "enfileirado com sucesso" |
| Migrar rota para `DevController` | Continuar com closure | Closure não pode passar props; controller é necessário para `Inertia::render('Dev/Desenvolvimento', [...])` |

**Instalação:** Nenhuma instalação de pacote necessária. Toda stack já presente.

---

## Package Legitimacy Audit

> Esta fase não instala nenhum pacote externo novo. Toda a implementação usa dependências já presentes no `composer.json` e `package.json` do projeto.

**Pacotes removidos por slopcheck [SLOP]:** nenhum
**Pacotes suspeitos [SUS]:** nenhum

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (Admin)
    │
    │  GET /dev/desenvolvimento
    ▼
DevController::index()
    ├── Company::whereNotNull('adman_account_id')->get()
    ├── AdmanMetric::latestPerCompany()  [synced_at, raw_data]
    └── AdmanSyncLog::latestPerCompany() [criados, atualizados, ignorados, error_message]
    │
    │  Inertia::render('Dev/Desenvolvimento', { empresas: [...] })
    ▼
Desenvolvimento.jsx
    ├── DevCard (existente — Extensão Chrome)
    ├── DevCard (NOVO — Sync Adman)
    │   └── SyncAdmanSection
    │       ├── EmpresaRow × N
    │       │   ├── nome, synced_at, StatusBadge
    │       │   ├── [Disparar sync] → router.post('/dev/adman/{company}/sync')
    │       │   └── click → accordion expand
    │       └── EmpresaAccordion (expandido)
    │           ├── DiffBadge (criados / atualizados / ignorados)
    │           └── JsonViewer (raw_data JSON)
    └── Placeholder dashed (existente)

    │  POST /dev/adman/{company}/sync
    ▼
DevController::dispatchSync(Company $company)
    └── SyncAdmanCompanyJob::dispatch($company)
    └── return back()->with('success', "Sync enfileirado para {$company->name}.")

Queue Worker
    └── SyncAdmanCompanyJob::handle(AdmanService)
        └── AdmanService::syncCompany($company)
            ├── AdmanMetric::updateOrCreate([...])  ← atualiza synced_at, raw_data
            └── (NOVO) AdmanSyncLog::create([criados, atualizados, ignorados, error_message])
```

### Estrutura de arquivos recomendada

```
app/
├── Http/Controllers/
│   └── DevController.php          # NOVO — index() + dispatchSync()
├── Jobs/
│   └── SyncAdmanCompanyJob.php    # EXISTENTE — sem alteração
├── Models/
│   └── AdmanSyncLog.php           # NOVO — model da tabela de logs
├── Services/
│   └── AdmanService.php           # ALTERADO — syncCompany() retorna diff

database/migrations/
└── 2026_05_18_XXXXXX_create_adman_sync_logs_table.php  # NOVO

resources/js/Pages/Dev/
└── Desenvolvimento.jsx             # ALTERADO — recebe props + novos componentes inline

routes/
└── web.php                         # ALTERADO — rota dev.desenvolvimento vira DevController::index
                                    #           + rota POST dev.adman.sync
```

### Padrão 1: Controller passando props de dados via Inertia

**O que é:** Controller substitui closure, faz query e passa props estruturadas.

**Quando usar:** Toda página que precisa de dados do banco — padrão universal do projeto.

```php
// Source: padrão estabelecido — vide DashboardController, SugadorController
class DevController extends Controller
{
    public function index(): \Inertia\Response
    {
        // Busca empresas com adman_account_id preenchido
        $empresas = Company::where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
            ->with(['latestMetrics', 'latestAdmanSyncLog'])
            ->get()
            ->map(fn(Company $c) => [
                'id'           => $c->id,
                'name'         => $c->name,
                'synced_at'    => $c->latestMetrics?->synced_at?->toIso8601String(),
                'raw_data'     => $c->latestMetrics?->raw_data,
                'criados'      => $c->latestAdmanSyncLog?->created_count,
                'atualizados'  => $c->latestAdmanSyncLog?->updated_count,
                'ignorados'    => $c->latestAdmanSyncLog?->skipped_count,
                'error'        => $c->latestAdmanSyncLog?->error_message,
                'status'       => $c->latestAdmanSyncLog?->error_message ? 'erro' : 'ok',
            ]);

        return Inertia::render('Dev/Desenvolvimento', [
            'empresas' => $empresas,
        ]);
    }

    public function dispatchSync(Company $company): \Illuminate\Http\RedirectResponse
    {
        SyncAdmanCompanyJob::dispatch($company);
        return back()->with('success', "Sync enfileirado para {$company->name}.");
    }
}
```

### Padrão 2: Nova tabela `adman_sync_logs` para rastreamento de diff

**O que é:** Tabela estruturada com uma linha por execução de sync, registrando contagens e erro.

**Quando usar:** Necessário porque `updateOrCreate()` do `AdmanMetric` não expõe se o registro foi criado ou atualizado.

```php
// Migration: database/migrations/2026_05_18_XXXXXX_create_adman_sync_logs_table.php
Schema::create('adman_sync_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->integer('created_count')->default(0);   // AdmanMetric criado (wasRecentlyCreated)
    $table->integer('updated_count')->default(0);   // AdmanMetric atualizado
    $table->integer('skipped_count')->default(0);   // sem alteração (dirty == false)
    $table->text('error_message')->nullable();       // mensagem de erro HTTP se sync falhou
    $table->timestamp('synced_at')->nullable();      // timestamp do sync (redundante mas útil)
    $table->timestamps();
    // índice para query de último sync por empresa
    $table->index(['company_id', 'created_at']);
});
```

**Como capturar diff em `AdmanService::syncCompany()`:**

```php
// Source: padrão wasRecentlyCreated do Eloquent
$metric = AdmanMetric::updateOrCreate(
    ['company_id' => $company->id, 'reference_date' => $date],
    [ /* campos */ ]
);

$wasCreated = $metric->wasRecentlyCreated;
$wasUpdated = !$wasCreated && $metric->wasChanged();
$wasSkipped = !$wasCreated && !$metric->wasChanged();

AdmanSyncLog::create([
    'company_id'    => $company->id,
    'created_count' => $wasCreated ? 1 : 0,
    'updated_count' => $wasUpdated ? 1 : 0,
    'skipped_count' => $wasSkipped ? 1 : 0,
    'error_message' => null,
    'synced_at'     => now(),
]);
```

**Para capturar erros HTTP:**

```php
// No catch do syncCompany() — salvar log de erro mesmo quando falha
try {
    $metric = $this->syncCompany($company, $date);
    // ... criar AdmanSyncLog de sucesso
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

### Padrão 3: Accordion inline com `useState` — um aberto por vez

**O que é:** Estado local React controla qual empresa está expandida (null = nenhuma).

**Quando usar:** Accordion simples sem dependência externa — padrão do projeto (StatusBadge em Sugadores).

```jsx
// Source: padrão local em Sugadores/Index.jsx
function SyncAdmanSection({ empresas }) {
    const [aberta, setAberta] = useState(null); // company_id ou null

    function toggleEmpresa(id) {
        setAberta(prev => prev === id ? null : id);
    }

    return (
        <div className="divide-y divide-white/[0.04]">
            {empresas.map(empresa => (
                <>
                    <EmpresaRow
                        key={empresa.id}
                        empresa={empresa}
                        expandida={aberta === empresa.id}
                        onToggle={() => toggleEmpresa(empresa.id)}
                    />
                    {aberta === empresa.id && (
                        <EmpresaAccordion empresa={empresa} />
                    )}
                </>
            ))}
        </div>
    );
}
```

### Padrão 4: Dispatch com feedback via `router.post()` + flash

**O que é:** Inertia `router.post()` com callbacks `onStart`/`onFinish` para controlar loading local; flash do server vira toast via `AppLayout`.

**Quando usar:** Toda ação de escrita no projeto (padrão CompanyController, GrantController).

```jsx
// Source: padrão Inertia router.post() — @inertiajs/react docs
const [disparando, setDisparando] = useState(false);

function disparar(e, empresaId) {
    e.stopPropagation(); // não propaga para accordion
    router.post(route('dev.adman.sync', empresaId), {}, {
        onStart:  () => setDisparando(true),
        onFinish: () => setDisparando(false),
    });
}
```

### Anti-Patterns a evitar

- **Chamar `AdmanService::syncCompany()` diretamente no controller:** A chamada leva até 2 minutos — estoura timeout do nginx. Sempre enfileirar via `SyncAdmanCompanyJob::dispatch()`.
- **Usar `activity_log` para rastrear diff:** O Spatie não estrutura contagens por tipo de operação; query de diff seria string parsing frágil.
- **Criar sub-componentes em arquivo separado se couberem em `Desenvolvimento.jsx`:** Projeto usa sub-componentes inline (vide `StatusBadge`, `MotivoBadge` em Sugadores). Mover para arquivo próprio apenas se ultrapassar ~120 linhas.
- **Usar `response()->json()` no controller de dispatch:** Rota é Inertia — usar `back()->with('success', ...)` para que `AppLayout` renderize o toast via `flash.success`.
- **Modificar `SyncAdmanCompanyJob`:** O job existente já está correto. Não adicionar lógica de logging dentro do job — mantê-la em `AdmanService::syncCompany()`.

---

## Don't Hand-Roll

| Problema | Não construir | Usar em vez | Por quê |
|----------|---------------|-------------|---------|
| Toast/feedback de ação | Toast personalizado | `flash.success` via `return back()->with(...)` + `AppLayout` toast existente | AppLayout já lê `flash.success`/`flash.error` e exibe toast automaticamente |
| Job com retry e backoff | Lógica de retry no controller | `SyncAdmanCompanyJob` (já existe) | 3 tentativas, backoff 60/300/900s, `failed()` com log — tudo implementado |
| Accordion animado | Componente Radix/headless | `useState` + `tailwindcss-animate` (keyframes já em tailwind.config.js) | Anims `animation-accordion-down/up` já definidas; sem dependência externa |
| Detecção de criado vs atualizado | Comparar timestamps manualmente | `$model->wasRecentlyCreated` + `$model->wasChanged()` do Eloquent | API nativa do Eloquent — confiável e sem query extra |
| Autorização admin | Guard manual | Middleware `role:admin` já aplicado ao grupo de rotas dev | `EnsureUserHasRole` já configura 403 automaticamente |

**Insight chave:** O projeto já tem todas as peças — o trabalho desta fase é conectar componentes existentes (job, service, flash, layout) com cola mínima (controller, migration, componentes React inline).

---

## Runtime State Inventory

> Esta fase cria nova tabela (`adman_sync_logs`) e novo controller (`DevController`). Não há renomeação ou refatoração de estado existente.

| Categoria | Itens encontrados | Ação necessária |
|-----------|-------------------|-----------------|
| Dados armazenados | `adman_metrics` — dados existentes preservados; nova tabela `adman_sync_logs` criada vazia | Migration `create_adman_sync_logs_table` |
| Config de serviço externo | Nenhum serviço externo novo; `AdmanService` configuração via `.env` (`ADMAN_API_KEY`) inalterada | Nenhuma |
| Estado registrado no OS | Nenhum | Nenhuma |
| Segredos/env vars | `ADMAN_API_KEY` já existe — nenhuma variável nova necessária | Nenhuma |
| Artefatos de build | `npm run build` necessário após alterações em `Desenvolvimento.jsx` | Build obrigatório |

---

## Common Pitfalls

### Pitfall 1: Closure sem props na rota `/dev/desenvolvimento`

**O que ocorre:** A rota atual é `fn () => Inertia::render('Dev/Desenvolvimento')` (closure sem dados). Ao adicionar a seção de sync, o componente React precisa de `empresas` como prop.

**Por que ocorre:** Closure não permite injeção de dependências (AdmanMetric, Company).

**Como evitar:** Migrar para `DevController::index()` que faz a query e passa `empresas` via `Inertia::render('Dev/Desenvolvimento', ['empresas' => $empresas])`.

**Sinais de alerta:** Prop `empresas` chega como `undefined` no React — `usePage().props.empresas` retorna `undefined`.

---

### Pitfall 2: `updateOrCreate` não distingue criado de atualizado sem `wasRecentlyCreated`

**O que ocorre:** Rastrear diff como "criados vs atualizados" parece trivial, mas `updateOrCreate` não retorna essa informação por padrão.

**Por que ocorre:** O método retorna o model mas não indica explicitamente se foi INSERT ou UPDATE.

**Como evitar:** Usar `$metric->wasRecentlyCreated` (bool) imediatamente após `updateOrCreate()`. Se `false`, verificar `$metric->wasChanged()` para distinguir atualizado de ignorado.

**Sinais de alerta:** Diff mostra sempre 0 criados e 1 atualizado por empresa — indica que `wasRecentlyCreated` não foi verificado antes de salvar o log.

---

### Pitfall 3: `stopPropagation` no botão "Disparar sync" dentro da linha clicável

**O que ocorre:** O botão fica dentro da `EmpresaRow` que é clicável para expandir o accordion. Clique no botão propaga e abre/fecha o accordion junto.

**Por que ocorre:** Eventos de clique sobem na árvore DOM por padrão.

**Como evitar:** Adicionar `onClick={e => { e.stopPropagation(); disparar(empresa.id); }}` no botão.

**Sinais de alerta:** Clicar em "Disparar sync" faz o accordion abrir e fechar ao mesmo tempo que dispara o sync.

---

### Pitfall 4: Queue worker não rodando em desenvolvimento

**O que ocorre:** `SyncAdmanCompanyJob::dispatch()` retorna sucesso mas o job nunca executa; `synced_at` não atualiza.

**Por que ocorre:** `QUEUE_CONNECTION=database` — job vai para tabela `jobs` mas precisa de worker rodando.

**Como evitar:** Garantir que `php artisan queue:work` (ou `concurrently` via `npm run dev`) esteja ativo. Em testes PHPUnit, `phpunit.xml` define `QUEUE_CONNECTION=sync` — jobs executam imediatamente.

**Sinais de alerta:** Tabela `jobs` acumula registros mas `adman_sync_logs` não recebe novas linhas.

---

### Pitfall 5: Exibir `raw_data` diretamente pode expor objeto `summarizedData` nested

**O que ocorre:** `AdmanMetric.raw_data` armazena `summarizedData` da API (objeto com sub-objetos `{value, prev}`). Exibir via `JSON.stringify(raw_data, null, 2)` no `JsonViewer` é verboso mas correto.

**Por que ocorre:** O campo foi pensado para armazenar o payload bruto para debug — não é um array plano.

**Como evitar:** O `JsonViewer` deve formatar com `JSON.stringify(rawData, null, 2)` em bloco `<pre>`. Campos resumidos na UI-SPEC (grossBilling, netBilling, etc.) podem ser extraídos de `raw_data.grossBilling?.value`.

**Sinais de alerta:** Painel mostra `[object Object]` em vez de JSON formatado.

---

## Code Examples

### Relacionamento `latestAdmanSyncLog` no model Company

```php
// Source: padrão latestOfMany já usado em Company::latestMetrics()
public function admanSyncLogs()
{
    return $this->hasMany(AdmanSyncLog::class)->orderBy('created_at', 'desc');
}

public function latestAdmanSyncLog()
{
    return $this->hasOne(AdmanSyncLog::class)->latestOfMany('created_at');
}
```

### Rota do DevController no grupo `role:admin`

```php
// Source: routes/web.php — padrão grupo role:admin existente
Route::middleware('role:admin')->group(function () {
    // Substituir closure por controller:
    Route::get('/dev/desenvolvimento', [DevController::class, 'index'])
        ->name('dev.desenvolvimento');

    // Nova rota de dispatch:
    Route::post('/dev/adman/{company}/sync', [DevController::class, 'dispatchSync'])
        ->name('dev.adman.sync');
});
```

### EmpresaRow — estrutura básica alinhada com UI-SPEC

```jsx
// Source: UI-SPEC.md + padrão LinkRow em Desenvolvimento.jsx
function EmpresaRow({ empresa, expandida, onToggle }) {
    const [disparando, setDisparando] = useState(false);

    function disparar(e) {
        e.stopPropagation();
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
            <ChevronDown
                size={14}
                className={cn(
                    'text-white/40 transition-transform duration-200 shrink-0',
                    expandida && 'rotate-180 text-ecf-yellow'
                )}
            />
            <span className="flex-1 text-white font-semibold text-[13px] truncate">
                {empresa.name}
            </span>
            {tsFormatado ? (
                <code
                    className="text-white/60 text-[12px] font-mono shrink-0"
                    title={empresa.synced_at}
                >
                    {tsFormatado}
                </code>
            ) : (
                <span className="text-white/30 text-[12px] italic shrink-0">
                    Nunca sincronizado
                </span>
            )}
            <Badge variant={empresa.status === 'erro' ? 'destructive' : 'success'} className="shrink-0">
                {empresa.status === 'erro' ? 'Erro' : 'OK'}
            </Badge>
            <Button
                variant="ghost"
                size="sm"
                disabled={disparando}
                onClick={disparar}
                className="bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px] shrink-0"
            >
                <RefreshCw size={12} className={cn(disparando && 'animate-spin')} />
                {disparando ? 'Disparando...' : 'Disparar sync'}
            </Button>
        </div>
    );
}
```

### JsonViewer — bloco scrollável de payload bruto

```jsx
// Source: padrão <pre> + overflow do projeto
function JsonViewer({ data }) {
    if (!data) return (
        <p className="text-white/30 text-[12px] italic">Payload não disponível.</p>
    );
    return (
        <pre className="text-[12px] font-mono text-white/70 bg-black/40 rounded-lg p-3 overflow-x-auto max-h-64 leading-relaxed">
            {JSON.stringify(data, null, 2)}
        </pre>
    );
}
```

---

## State of the Art

| Abordagem antiga | Abordagem atual | Quando mudou | Impacto |
|-----------------|-----------------|--------------|---------|
| `routes/console.php` com `Kernel.php` | `routes/console.php` direto (Laravel 11+) | Laravel 11 | Scheduler já em `routes/console.php` — sem mudança necessária |
| `Inertia::render()` sem tipos | PHP array → React props | Padrão do projeto | Props digitadas pelo controller; sem validação de schema no frontend |
| Closure de rota passando props | Controller de rota | Esta fase | Rota `/dev/desenvolvimento` deve migrar de closure para `DevController` |

**Depreciado/obsoleto:**
- `App\Console\Kernel`: não existe no projeto (Laravel 11 style) — `routes/console.php` é o único lugar para scheduler.
- `SyncAdmanCompanyJob` com lógica de logging interna: logging deve ficar em `AdmanService` — job é apenas orquestrador.

---

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-----------------|
| A1 | `$metric->wasChanged()` retorna `true` quando campos mudam em `updateOrCreate()` [ASSUMED] | Padrão 2 | Diff pode mostrar counts incorretos — verificar com teste unitário |
| A2 | `format()` de `date-fns` disponível globalmente sem import específico [ASSUMED] | Code Examples | Compilação Vite falha — necessário `import { format } from 'date-fns'` explícito |

**Nota A1:** `wasRecentlyCreated` é documentado pelo Eloquent [VERIFIED: codebase — usado no projeto]. `wasChanged()` é parte da API Eloquent Model e deve funcionar após `updateOrCreate`. Verificar com teste feature que cobre DEV-03.

**Nota A2:** `date-fns` está em `package.json` como dependência (`^4.1.0`). Import explícito é sempre necessário — sem auto-import global no projeto.

---

## Open Questions (RESOLVED)

1. **`AdmanService::syncCompany()` deve retornar o log de sync ou só `AdmanMetric`?**
   - O que sabemos: Atualmente retorna `AdmanMetric`. Lógica de log precisa ser adicionada dentro do método.
   - O que não estava claro: Se o log deve ser criado dentro do `AdmanService` (acoplamento) ou fora (no job ou no controller de disparo).
   - RESOLVED: Criar log dentro de `AdmanService::syncCompany()` — é o único lugar com acesso ao resultado do `updateOrCreate`. O job não precisa saber sobre o log. Implementado em Plan 01-02 Task 1.

2. **`DevController::dispatchSync()` deve rejeitar empresa sem `adman_account_id`?**
   - O que sabemos: A query no `index()` já filtra por `whereNotNull('adman_account_id')` — empresas sem Adman não aparecem na lista.
   - O que não estava claro: Se um request POST direto para `/dev/adman/{company}/sync` com empresa sem Adman deve ser bloqueado.
   - RESOLVED: Adicionar `abort_unless($company->adman_account_id, 422, 'Empresa sem conta Adman configurada.')` no controller para segurança. Implementado em Plan 01-02 Task 2.

---

## Environment Availability

| Dependência | Requerida por | Disponível | Versão | Fallback |
|-------------|--------------|-----------|--------|----------|
| Node.js | `npm run build` (após editar JSX) | ✓ | v24.15.0 | — |
| PHP (CLI) | Migrations, artisan commands | Verificar via XAMPP | 8.2+ | — |
| Queue worker | DEV-04 (dispatch manual) | Precisa rodar manualmente | `database` driver | `QUEUE_CONNECTION=sync` em .env para teste local imediato |
| SQLite (testes) | PHPUnit (in-memory) | ✓ | via `phpunit.xml` | — |

**Dependências faltando sem fallback:** Nenhuma para a fase atual.

**Dependências faltando com fallback:**
- Queue worker em dev local: pode usar `QUEUE_CONNECTION=sync` no `.env` temporariamente para testar o fluxo completo sem worker separado. Reverter para `database` em produção.

---

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|-------------|-------|
| Framework | PHPUnit 11.x |
| Config | `phpunit.xml` (raiz do projeto) |
| Comando rápido | `php artisan test --filter DevController` |
| Suite completa | `php artisan test` |

### Mapa Requisitos → Testes

| Req ID | Comportamento | Tipo de teste | Comando | Arquivo existe? |
|--------|---------------|---------------|---------|-----------------|
| DEV-01 | GET `/dev/desenvolvimento` retorna `empresas[*].synced_at` para empresa com `adman_account_id` | Feature | `php artisan test --filter DevControllerTest::test_index_retorna_empresas_com_synced_at` | ❌ Wave 0 |
| DEV-02 | `empresas[*].raw_data` contém payload bruto do último sync | Feature | `php artisan test --filter DevControllerTest::test_index_retorna_raw_data_no_payload` | ❌ Wave 0 |
| DEV-03 | `empresas[*].criados` / `atualizados` / `ignorados` refletem contagens do `AdmanSyncLog` | Feature | `php artisan test --filter DevControllerTest::test_index_retorna_diff_do_ultimo_log` | ❌ Wave 0 |
| DEV-04 | POST `/dev/adman/{company}/sync` enfileira `SyncAdmanCompanyJob` e redireciona com flash success | Feature | `php artisan test --filter DevControllerTest::test_dispatch_sync_enfileira_job` | ❌ Wave 0 |
| DEV-04 | POST retorna 403 para usuário sem role admin | Feature | `php artisan test --filter DevControllerTest::test_dispatch_sync_rejeita_nao_admin` | ❌ Wave 0 |

### Taxa de amostragem

- **Por task commit:** `php artisan test --filter DevControllerTest`
- **Por wave merge:** `php artisan test`
- **Phase gate:** Suite completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/DevControllerTest.php` — cobre DEV-01, DEV-02, DEV-03, DEV-04
- [ ] `tests/Feature/AdmanSyncLogTest.php` (opcional) — cobre lógica de diff em `AdmanService::syncCompany()`
- Framework PHPUnit já instalado e configurado — nenhuma instalação nova necessária.

---

## Security Domain

### Categorias ASVS aplicáveis

| Categoria ASVS | Aplica | Controle padrão |
|----------------|--------|-----------------|
| V2 Authentication | não (já tratado pelo middleware `auth`) | — |
| V3 Session Management | não | — |
| V4 Access Control | **sim** | Middleware `role:admin` via `EnsureUserHasRole` — já aplicado ao grupo de rotas dev |
| V5 Input Validation | sim (company_id no dispatch) | Route model binding (`Company $company`) — Laravel rejeita IDs inválidos com 404 automático |
| V6 Cryptography | não | — |

### Ameaças conhecidas para este stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|------------------|
| Dispatch de sync para empresa de outro tenant | Tampering | Admin tem visão global — sem risco de tenant isolation; `role:admin` já garante |
| CSRF em POST de dispatch | Tampering | CSRF habilitado por padrão em todas as rotas autenticadas; Inertia envia `X-XSRF-TOKEN` automaticamente |
| Exposição de payload Adman via prop React | Information Disclosure | Rota protegida por `role:admin`; props Inertia não são públicas |

---

## Sources

### Primary (HIGH confidence)

- Codebase — `app/Services/AdmanService.php` lido diretamente: `syncCompany()`, `raw_data`, `synced_at`
- Codebase — `app/Models/AdmanMetric.php`: campos, casts, relacionamentos
- Codebase — `app/Jobs/SyncAdmanCompanyJob.php`: padrão de job existente para dispatch
- Codebase — `app/Http/Controllers/AdmanController.php`: padrão de dispatch e JSON response
- Codebase — `routes/web.php`: closure atual da rota `/dev/desenvolvimento`, grupo `role:admin`
- Codebase — `resources/js/Pages/Dev/Desenvolvimento.jsx`: `DevCard`, `LinkRow`, design system
- Codebase — `app/Http/Middleware/HandleInertiaRequests.php`: `flash.success`, `flash.error`
- Codebase — `resources/js/Layouts/AppLayout.jsx`: toast system via flash props
- Codebase — `resources/js/Components/ui/badge.jsx`: variantes `success`, `destructive`
- Codebase — `resources/js/Components/ui/button.jsx`: variantes e sizes
- Codebase — `database/migrations/2026_04_26_152220_create_adman_metrics_table.php`: schema atual
- Planning — `.planning/phases/01-diagn-stico-adman/01-UI-SPEC.md`: contrato visual aprovado
- Planning — `.planning/phases/01-diagn-stico-adman/01-CONTEXT.md`: decisões travadas

### Secondary (MEDIUM confidence)

- Padrão `wasRecentlyCreated` + `wasChanged()` do Eloquent — confirmado via uso no projeto (não verificado em docs oficiais nesta sessão, mas API padrão do Laravel 12)

### Tertiary (LOW confidence)

- Nenhum item de baixa confiança nesta pesquisa.

---

## Metadata

**Breakdown de confiança:**
- Stack padrão: HIGH — todo código verificado diretamente no codebase
- Arquitetura: HIGH — baseada em padrões existentes do projeto (AdmanController, SugadorController)
- Pitfalls: HIGH — identificados por análise direta do código (closure sem props, updateOrCreate sem diff)
- Segurança: HIGH — middleware `role:admin` verificado em `routes/web.php`

**Data da pesquisa:** 2026-05-18
**Validade estimada:** 60 dias (stack estável; sem dependências de terceiros voláteis)
