# Phase 75: Empresas Shopee — habilitar NPS para clientes atendidos na Shopee - Mapa de Padrões

**Mapeado:** 2026-07-14
**Arquivos analisados:** 11 (novos/modificados)
**Analogs encontrados:** 11 / 11

> Todos os analogs vivem no próprio codebase. Esta phase é 100% aditiva — nenhum arquivo tem "sem analog". Onde a CONTEXT/RESEARCH já fixou `file:linha`, este documento extrai o trecho concreto a copiar.

## Classificação de Arquivos

| Arquivo novo/modificado | Papel | Fluxo de dados | Analog mais próximo | Qualidade |
|-------------------------|-------|----------------|---------------------|-----------|
| `database/migrations/XXXX_add_shopee_to_servicos_setor_enum.php` (novo) | migration (schema) | transform (DDL) | `database/migrations/2026_07_03_113103_add_polos_to_servicos_setor_enum.php` | exact — **com desvio obrigatório no branch SQLite** |
| `database/migrations/XXXX_seed_servico_shopee.php` (novo, timestamp > enum) | migration (data/seed) | batch idempotente | `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` | exact |
| `app/Models/Servico.php` (modificado) | model | — | ele mesmo (`SETOR_POLOS` :54-88) | exact (in-file) |
| `app/Http/Controllers/ComercialController.php` (modificado) | controller | request-response | ele mesmo (`servicoDisparaImplementacao` :54, `slugSetorParaServico` :867) | exact (in-file) — **no-op na prática** |
| `app/Http/Controllers/ShopeeEmpresasController.php` (novo) | controller | CRUD / request-response | `app/Http/Controllers/CompanyController.php` (`index` :76, `bulkAssign` :683, `update` :610-628) | role+flow match (versão enxuta) |
| `resources/js/Pages/Shopee/Empresas.jsx` (novo) | component (page) | request-response (Inertia props) | `resources/js/Pages/Companies/Index.jsx` | role match (versão enxuta) |
| `app/Support/Permissions.php` (modificado) | config (catálogo estático) | — | ele mesmo (grupo `Publicações (MLB)` + `MLB_*` :54-60, :140-154) | exact (in-file) |
| `resources/js/Layouts/AppLayout.jsx` (modificado) | config (nav tree) | — | grupo `Mercado Livre` :48-99 (transformar stub Shopee :108-115) | exact (in-file) |
| `routes/web.php` (modificado) | route | — | grupo `permission:core.empresas` :502-504 + mutations :603-613 | exact (in-file) |
| `tests/Feature/Phase75MigrationTest.php` (novo) | test | — | `tests/Feature/Phase37ServicoSetorTest.php` | role match |
| `tests/Feature/Phase75ShopeeEmpresasTest.php` (+ `Phase75NpsShopeeTest.php`) (novo) | test | — | `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` | exact |

---

## Pattern Assignments

### `XXXX_add_shopee_to_servicos_setor_enum.php` (migration, transform DDL)

**Analog:** `database/migrations/2026_07_03_113103_add_polos_to_servicos_setor_enum.php`

**CRÍTICO — desvio do analog (RESEARCH Pitfall 1):** a migration de polos **pula o SQLite inteiro** (`if driver !== 'mysql') return;`, linhas 35-37). Isso NÃO pode ser copiado: os Feature tests da Phase 75 precisam persistir `Servico` com `setor='shopee'` no SQLite, e o CHECK constraint é **enforçado** (comprovado empiricamente: `SQLSTATE[23000] CHECK constraint failed`). O branch SQLite precisa recriar a coluna com todos os valores.

**Estrutura a copiar do analog (branch MySQL, linhas 29-47):**
```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','shopee','outros') NOT NULL DEFAULT 'outros'");
        } else {
            // DESVIO obrigatório (Pitfall 1): SQLite enforça o CHECK. Recomendação
            // RESEARCH (mais robusta): string sem CHECK — encerra a classe de bug
            // polos/shopee/próximos. INCLUIR todos os valores se optar por enum()->change().
            Schema::table('servicos', function (Blueprint $table) {
                $table->string('setor')->default('outros')->change();
            });
        }
    }
    // down(): reverter update p/ 'outros' ANTES de estreitar o enum (padrão :55-58)
};
```

> O planner deve travar entre `string()->change()` (recomendado) vs `enum([...])->change()` no branch SQLite; o executor **DEVE rodar o teste da migration antes de seguir** (RESEARCH Open Question 1).

---

### `XXXX_seed_servico_shopee.php` (migration, batch idempotente)

**Analog:** `database/migrations/2026_05_27_100001_seed_servicos_catalog.php` (linhas 44-55)

**Padrão idempotente a copiar (firstOrCreate por `nome`, NÃO updateOrCreate — preserva `valor_padrao` editado via UI):**
```php
use App\Models\Servico;

Servico::firstOrCreate(
    ['nome' => 'Shopee'],          // exato "Shopee" — não casa prefixos ML (Pitfall 3)
    [
        'valor_padrao'  => 0,
        'tipo_cobranca' => Servico::TIPO_MENSAL,
        'ativo'         => true,
        'setor'         => Servico::SETOR_SHOPEE,  // 'shopee'
    ],
);
```
`down()` vazio como no analog (:58-64) — contratos vinculados (restrictOnDelete) impedem o drop.

> **Ordem (Pitfall 2):** o timestamp deste arquivo DEVE ser > o da migration de enum. Em MySQL o INSERT com `setor='shopee'` falha se o ALTER ainda não rodou.

---

### `app/Models/Servico.php` (model — adicionar SETOR_SHOPEE)

**Analog:** o próprio arquivo, padrão do `SETOR_POLOS`.

**3 pontos de edição espelhando `polos`:**
```php
// 1) Constante (após :54, junto às demais):
public const SETOR_SHOPEE      = 'shopee';

// 2) Array SETORES (:60-65) — adicionar self::SETOR_SHOPEE

// 3) setoresLabels() (:81-89) — adicionar:
self::SETOR_SHOPEE => 'Shopee',
```
Casts/fillable já cobrem `setor` (:35). Opcional: helper `isShopee()` espelhando `isPerformance()` (:134-137).

---

### `app/Http/Controllers/ComercialController.php` (controller — não disparar impl ML)

**Analog:** o próprio arquivo. **Ação real = verificar, não editar** — nome exato "Shopee" já resolve para `null` nos dois helpers (RESEARCH Pitfall 3, A3).

**`servicoDisparaImplementacao()` (:54-62)** — `str_contains` só casa `Polos|Assessoria|Incubadora`. "Shopee" → `null` → nenhuma `MlbEmpresa` criada. **Nada a mudar** (comportamento já correto).

**`slugSetorParaServico()` (:867-878)** — só casa `Polos|Assessoria|Publicação|Publicidade|Gestão|Incubadora`. "Shopee" → `null` → nenhuma notificação de setor ML. **Nada a mudar** (a menos que se opte por notificar líder Shopee — discricionário, RESEARCH Open Question 2).

**`store()` roteamento (:601-633)** — o loop de `MlbEmpresa` só executa para tipos não-nulos; empresa Shopee cai no ramo "helper retorna null → sem mlb_empresas, apenas company" (:631-632). Company criada com `adman_account_id`/`ml_store_id` NULOS (esses campos nem aparecem no `Company::create` :556-576). **Confirmar via teste, não editar.**

---

### `app/Http/Controllers/ShopeeEmpresasController.php` (controller novo — listagem enxuta)

**Analog:** `app/Http/Controllers/CompanyController.php`

**Construtor — NÃO injetar dependências ML/métrica** (contraste com o analog :23-27 que injeta `AdmanService`/`EcfDriveService`/`MetricsProviderFactory`). Construtor vazio ou só o necessário.

**`index()` — copiar o esqueleto de `CompanyController::index` :76-243, com estas mudanças:**

*Filtro do gatilho* — trocar `SETOR_PERFORMANCE` por `SETOR_SHOPEE` e **remover** `whereDoesntHave('mlbEmpresa')` (:108) — empresa multi-marketplace deve aparecer nas duas abas:
```php
$companies = Company::with([
        'consultor', 'estrategista',
        'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
        'grupo:id,name,color',
    ])
    // SEM ->withCount(grants) (analog :107), SEM ->whereDoesntHave('mlbEmpresa') (:108),
    // SEM 'mlToken' (:104)
    ->whereHas('contratosServico', fn($q) =>
        $q->where('contratos_servico.ativo', true)
          ->whereHas('servico', fn($qs) => $qs->where('setor', Servico::SETOR_SHOPEE))
    )
    ->orderBy('name')
    ->get();
```

*Payload map* — copiar `CompanyController.php :129-200` **removendo** `cust_id_status` (:138), `adman_account_id`/`adman_store_id`/`ml_store_id` (:155-157), `ml_token_status` (:172). Manter `id/name/cnpj/segment/active/status/email_cliente/telefone/empresa_nova/consultor/estrategista/contratos_servico/grupo`.

*Pendências Shopee (DEC-2)* — substituir o bloco :189-199 por (só 3 pendências, sem `sem_cust_id`/`sem_email_colaborador`/`sem_grant_ativo`):
```php
'pendencias' => array_values(array_filter([
    ($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null,
    // "sem_contato" = email_cliente vazio E digisac_group_contact_id vazio
    // (as duas condições do disparo mensal — NpsDispararMensal.php:146-162)
    (empty($c->email_cliente) && empty($c->digisac_group_contact_id)) ? 'sem_contato' : null,
    $c->empresa_nova ? 'empresa_nova' : null,
])),
```

*Listas de responsáveis* — copiar o helper `usersPorCargo()` (:206-222) e as vars `$estrategistas`/`$analistas` (:221-222) verbatim. `$grupos` (:225-227) opcional. **Não** copiar `$servicoCounts` (:234-243, é métrica de chip Performance).

**`bulkAssign()` (novo) — copiar de `CompanyController::bulkAssign` :683-700 verbatim.** Mesma validação (`role in:consultor,estrategista`, `ids.* exists`), mesmo sync do pivot:
```php
foreach (Company::whereIn('id', $data['ids'])->get() as $c) {
    DB::table('company_users')->where('company_id', $c->id)->where('role', $data['role'])->delete();
    $c->users()->attach($data['user_id'], ['role' => $data['role'], 'assigned_at' => now()->toDateString()]);
}
```
Label "Analista" → `role='consultor'`, "Estrategista" → `role='estrategista'` (mapeamento de `Company.php` :164-179).

**`update()` de responsável por linha (opcional) — reusar o sync de `CompanyController::update` :617-628** (monta `$sync` de consultor_id/estrategista_id, `detach()` + `attach()`).

---

### `resources/js/Pages/Shopee/Empresas.jsx` (page nova — versão enxuta)

**Analog:** `resources/js/Pages/Companies/Index.jsx`

**Imports (copiar de :1-15)** — mesmos primitives shadcn/ui (`Card`, `Button`, `Select`, `Dialog`, `Table`), `useForm/router/usePage` do `@inertiajs/react`, `cn`/`formatDate` de `@/lib/utils`, ícones `lucide-react`. Reaproveitar `NpsPendingBadge` (:17) se quiser badge NPS na linha. **Remover** `IMaskInput`, `MARKETPLACES_EXTRAS` e `ServicoBadges` se não forem usados na versão enxuta.

**Dicionário de pendências (adaptar de :100-106)** — só as 3 chaves da DEC-2 (trocar `sem_contato` no lugar de `sem_cust_id`):
```jsx
const PENDENCIAS = {
    sem_responsavel: { label: 'Sem responsável', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
    sem_contato:     { label: 'Sem contato',     cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
    empresa_nova:    { label: 'Empresa nova',    cls: 'bg-yellow-500/15 text-ecf-yellow border-ecf-yellow/30' },
};
```
**`PendenciaBadges` (copiar :108-119 verbatim)** — genérico, funciona com qualquer dicionário.

**Abas "Todas"/"Pendências", atribuição no modal (:712-729) e bulk assign (:544-558/:268-271)** — copiar da estrutura do analog. Atribuição posta para `route('shopee.empresas.bulk-assign')` (não `companies.bulk-assign`).

**Botão "Gerar NPS" por linha (DEC-5 / RESEARCH Pattern 5)** — reaproveitar `POST /nps/generate`:
```jsx
const { data, setData, post } = useForm({ company_id: '', template_id: '' });
const gerarNps = (companyId) => {
    setData('company_id', companyId);
    post(route('nps.generate'), { onSuccess: () => {/* usePage().props.flash.nps_link abre modal */} });
};
```
`NpsController::generate` (:353-412) já valida auth (admin OU membro do pivot :370-375) e resolve template com fallback `is_default` (:390) — **sem tocar métricas**. Link volta em `flash.nps_link` (:410).

> Rodar `npm run build` após criar/editar a página (convenção do projeto).

---

### `app/Support/Permissions.php` (config — nova key + grupo Shopee)

**Analog:** o próprio arquivo — grupo `Publicações (MLB)` e constantes `MLB_*`.

**1) Constante (após `MLB_ANUNCIAR` :60):**
```php
/** Aba Empresas do marketplace Shopee (listagem enxuta + atribuição p/ NPS). */
public const SHOPEE_EMPRESAS = 'shopee.empresas';
```

**2) Novo grupo em `catalog()` (:123-180, espelhando o shape de `'Publicações (MLB)'` :140-154):**
```php
'Shopee' => [
    ['key' => self::SHOPEE_EMPRESAS, 'label' => 'Shopee · Empresas', 'description' => 'Empresas atendidas na Shopee (habilita NPS)'],
],
```
`Permissions::all()` (:187-196) já agrega o grupo novo automaticamente. Admin recebe a key via short-circuit `isAdmin()` — **nada a fazer para admin** (RESEARCH Pattern 6).

---

### `resources/js/Layouts/AppLayout.jsx` (config — stub Shopee → grupo real)

**Analog:** grupo `Mercado Livre` (:48-99) para o *shape de grupo*; stub atual `Shopee` (:108-115) é o que será substituído.

**Transformar o item de topo (:108-115) num grupo com `children`:**
```jsx
{
    group: 'Shopee',
    icon: ShoppingCart,                 // já importado (:7)
    iconSrc: '/images/shopee-icon.svg', // já usado no stub atual
    children: [
        { label: 'Empresas',  routeName: 'shopee.empresas.index', page: 'Shopee/Empresas', icon: Building2, permission: 'shopee.empresas' },
        { label: 'Dashboard', routeName: 'shopee.dashboard', page: 'Dashboard/ShopeeShell', icon: LayoutDashboard, badgeText: 'Em breve' },
    ],
},
```
`itemVisivel()` (:296-303) já gate por `permission`; grupo some se nenhum filho for visível. `Building2`/`LayoutDashboard`/`ShoppingCart` já importados (:4-9). Manter o Dashboard stub como filho (rota `shopee.dashboard` já existe, `routes/web.php:325`).

---

### `routes/web.php` (route — grupo shopee.empresas.*)

**Analog:** grupo `permission:core.empresas` (:502-504) para o GET + mutations `companies.*` (:603-613).

**Padrão a espelhar (gate por `permission:shopee.empresas`, NÃO `core.empresas`):**
```php
Route::middleware('permission:shopee.empresas')->group(function () {
    Route::get('/shopee/empresas', [ShopeeEmpresasController::class, 'index'])->name('shopee.empresas.index');
    Route::post('/shopee/empresas/bulk-assign', [ShopeeEmpresasController::class, 'bulkAssign'])->name('shopee.empresas.bulk-assign');
    Route::put('/shopee/empresas/{company}', [ShopeeEmpresasController::class, 'update'])->name('shopee.empresas.update'); // se usar update por linha
});
```
Espelha `companies.index` (:503), `companies.bulk-assign` (:613) e `companies.update` (:603). **Não** reabrir `core.empresas` para usuários Shopee (DEC-4 / Security V4).

---

### `tests/Feature/Phase75MigrationTest.php` (test — enum + seed)

**Analog:** `tests/Feature/Phase37ServicoSetorTest.php`

- Cabeçalho `RefreshDatabase` + `namespace Tests\Feature` + `extends Tests\TestCase` (:31-33).
- **Teste-chave (prova o fix do Pitfall 1):** `Servico::create([... 'setor' => 'shopee'])` deve persistir sem `CHECK constraint failed` no SQLite. Padrão de asserção de coluna via `Schema::hasColumn` (:63-68).
- Seed idempotente: rodar `firstOrCreate` 2× não duplica (padrão de "roda 2× sem duplicar" do `seed_servicos_catalog` docblock :24).
- Helper `criarServico($nome, $setor)` — copiar de `Phase37CompaniesPerformanceFilterTest::criarServico` (:54-63). **Não existe `ServicoFactory`** — usar `Servico::create`/`firstOrCreate` direto.

---

### `tests/Feature/Phase75ShopeeEmpresasTest.php` + `Phase75NpsShopeeTest.php` (test — aba/pendências/gate/NPS)

**Analog:** `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` (molde quase 1:1 da aba)

Helpers a copiar verbatim (:41-94):
- `actingAsAdmin()` (:41-52) — cria admin e `actingAs`.
- `criarServico()` (:54-63), `criarEmpresa()` (:65-78), `criarContrato()` (:80-89).
- `payloadCompanies($response)` (:91-94) — extrai `viewData('page')['props']['companies']` (Inertia).

Cenários a espelhar (trocando Performance→Shopee, sem `whereDoesntHave` MlbEmpresa):
- Filtro por setor `shopee` — empresa com contrato ativo aparece; publicacao/outros/inativo/sem-contrato não (analog :100-184).
- **Multi-marketplace aparece nas DUAS abas** (contraste com o teste 7 do analog :211-235 que exclui MlbEmpresa — na Shopee NÃO exclui).
- Pendências = `sem_responsavel`/`sem_contato`/`empresa_nova`; NÃO retorna `sem_cust_id`/`sem_grant_ativo` (padrão dos testes 8-9 :241-289).
- Atribuição grava pivot `role=consultor`/`estrategista` sob gate `shopee.empresas`.
- Permission gate: 403 sem a key, 200 para admin/setor com a key.
- `Phase75NpsShopeeTest`: empresa Shopee (sem métrica) gera NPS via `nps.generate` com fallback `is_default`.

---

## Shared Patterns

### Gate de acesso (RBAC dupla camada)
**Source:** `app/Support/Permissions.php` (catálogo) + `app/Http/Middleware/EnsurePermission.php` (rota) + `AppLayout.itemVisivel()` (:296-303, menu).
**Aplicar a:** `ShopeeEmpresasController` (todas as rotas), `AppLayout` (item Empresas).
Key única `shopee.empresas`; admin recebe via short-circuit `isAdmin()`. Nunca confiar só no menu — o middleware é a fonte de verdade (Security V4).

### Atribuição de responsável (pivot company_users)
**Source:** `app/Models/Company.php` :157-179 (relações `users`/`consultor`/`estrategista`) + `CompanyController::bulkAssign` :683-700.
**Aplicar a:** `ShopeeEmpresasController::bulkAssign`/`update`.
Mapeamento crítico: label "Analista" → `role='consultor'`; "Estrategista" → `role='estrategista'`. Validação `role in:consultor,estrategista`.

### Idempotência de seed
**Source:** `2026_05_27_100001_seed_servicos_catalog.php` :44-55.
**Aplicar a:** seed do serviço "Shopee".
`firstOrCreate` por `nome` (NÃO `updateOrCreate`) — preserva edições manuais de `valor_padrao`.

### Enrichment ML-free (não injetar Adman/Drive)
**Source:** contraste com `CompanyController` construtor :23-27.
**Aplicar a:** `ShopeeEmpresasController` (construtor vazio), payload map (sem cust_id/métrica), pendências (sem grant).
A aba Shopee é deliberadamente ML-free — injetar `AdmanService`/`MetricsProviderFactory` dispararia jobs/HTTP inúteis (Anti-Pattern RESEARCH).

### Motor NPS intocado (reaproveitar, DEC-5)
**Source:** `NpsController::generate` :353-412 + `route('nps.generate')` (`routes/web.php:100`).
**Aplicar a:** botão "Gerar NPS" em `Shopee/Empresas.jsx`.
Auth já cobre membro do pivot; template resolve via fallback `is_default`; não lê métricas. **Zero mudança no motor.**

---

## No Analog Found

Nenhum. Todos os 11 arquivos têm analog direto no codebase. Os únicos "desvios" são deliberados e documentados:
- Branch SQLite da migration de enum (o analog de polos pula o driver; a Phase 75 **não pode** — Pitfall 1).
- Remoção de `whereDoesntHave('mlbEmpresa')` na aba Shopee (multi-marketplace nas duas abas).
- Payload/pendências enxutos (sem métrica/cust_id/grant).

## Metadata

**Escopo de busca de analogs:** `database/migrations/`, `app/Http/Controllers/`, `app/Models/`, `app/Support/`, `resources/js/Pages/`, `resources/js/Layouts/`, `routes/`, `tests/Feature/`.
**Arquivos lidos:** 15 (migrations 2, models 2, controllers 3, support 1, layouts 1, pages 1, routes 1, tests 2, + CONTEXT/RESEARCH).
**Data de extração:** 2026-07-14
