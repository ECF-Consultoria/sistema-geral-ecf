# Phase 75: Empresas Shopee — habilitar NPS para clientes atendidos na Shopee - Research

**Researched:** 2026-07-14
**Domain:** Laravel 12 + Inertia/React — modelagem por contrato de serviço, enum cross-driver, RBAC por permission key, aba de listagem enxuta, deep-link NPS
**Confidence:** HIGH (todos os achados verificados por leitura direta do código-fonte do projeto + teste empírico do CHECK constraint SQLite)

## Summary

Esta phase é 100% **aditiva** dentro da stack existente (Laravel 12 + Inertia + React) — **nenhuma dependência nova**, nenhuma mudança de motor NPS. O trabalho é replicar padrões já consolidados no projeto: (1) adicionar um valor ao enum `servicos.setor`, (2) semear um serviço no catálogo `Servico`, (3) espelhar `CompanyController@index` numa versão enxuta filtrada por contrato de setor `shopee`, (4) registrar uma permission key `shopee.empresas` no catálogo estático, e (5) transformar o stub "Shopee — Em breve" da sidebar num grupo real. O CONTEXT.md já travou todas as decisões; esta pesquisa documenta o **COMO técnico**, linha a linha.

O achado mais importante e não-óbvio: **o SQLite (usado nos testes) ENFORÇA o CHECK constraint do enum** — confirmado empiricamente (`SQLSTATE[23000] CHECK constraint failed`). A migration que adicionou `polos` **pulou o SQLite inteiro**, o que funcionou só porque nenhum teste cria `Servico` com `setor='polos'`. Para a Phase 75 isso **não** vale: os Feature tests OBRIGATORIAMENTE precisam criar um `Servico` com `setor='shopee'` para exercitar o cadastro, o filtro da aba e as pendências. Portanto a migration de enum desta phase **NÃO pode simplesmente pular o SQLite** como a de polos fez — precisa recriar a coluna/CHECK também no driver de teste (via `->change()` ou string sem CHECK).

**Primary recommendation:** Migration de enum com split por driver: branch MySQL usa `ALTER TABLE ... MODIFY COLUMN setor ENUM(...)` (padrão da migration de polos); branch SQLite usa `Schema::table(...->enum('setor', [...])->change())` incluindo TODOS os valores (`performance`, `publicacao`, `polos`, `shopee`, `outros`) para que os testes possam persistir `setor='shopee'`. Seed do serviço "Shopee" via data-migration com `Servico::firstOrCreate(['nome'=>'Shopee'], [...'setor'=>'shopee'])` (idempotente, preserva edições manuais). Controller/página/rotas dedicados sob `permission:shopee.empresas` espelhando o molde ML, sem qualquer payload de métrica.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Valor `shopee` no enum + seed do serviço | Database / Migration | Model (`Servico`) | Enum vive no schema; constante espelha no model |
| Gatilho "empresa é Shopee" | Database (contrato de setor `shopee`) | — | DEC-1: mesma mecânica do ML (contrato de setor `performance`), não flag |
| Cadastro sem ML (adman/ml nulos) | API / Backend (`ComercialController::store`) | — | Origem única de companies; precisa não pressupor ML |
| Aba "Empresas" Shopee (payload enxuto) | API / Backend (`ShopeeEmpresasController`) | Frontend (`Shopee/Empresas.jsx`) | Controller monta props; React renderiza |
| Pendências mínimas pro NPS | API / Backend (helper de pendências) | — | Derivadas de colunas `companies` (email/digisac/empresa_nova) |
| Atribuição Analista/Estrategista | API / Backend (pivot `company_users`) | Frontend (modal/bulk) | Grava pivot `role=consultor|estrategista` |
| Gate de acesso `shopee.empresas` | API (middleware `EnsurePermission`) | Frontend (`AppLayout.itemVisivel`) | RBAC dupla camada: rota + menu |
| Geração de link NPS | API (`NpsController::generate`, já existe) | Frontend (deep-link/form) | DEC-5: motor NPS intocado; só reaproveitar |

## Standard Stack

**Nenhum pacote novo.** Toda a phase usa o que já está instalado. Tabela apenas para referência de versão do que será tocado.

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^12.0 | Migrations, Eloquent, rotas, middleware | Stack travada (CLAUDE.md) |
| @inertiajs/react | ^2.0 | Bridge SPA server→client props | Stack travada |
| react / react-dom | ^18.2 | UI das páginas `.jsx` | Stack travada |
| tailwindcss | ^3.2 | Tokens `ecf-*`, dark theme | Design system do projeto |
| lucide-react | ^1.11 | Ícones (`ShoppingCart` já usado no stub Shopee) | Convenção |
| tightenco/ziggy | ^2.0 | `route()` helper no JSX (rotas `shopee.empresas.*`) | Convenção |

**Installation:** N/A — sem instalação. Após mudanças de frontend, rodar `npm run build` (convenção do projeto).

*Verificação de versão dispensada: nenhum pacote adicionado à árvore de dependências.*

## Package Legitimacy Audit

**N/A — esta phase não instala nenhum pacote externo** (npm ou composer). Nada a auditar. Todo o código novo usa APIs de bibliotecas já presentes em `composer.lock` / `package-lock.json`.

## Architecture Patterns

### System Architecture Diagram

```
                    ┌─────────────────────────────────────────────┐
                    │  Cadastro (origem única de companies)        │
  Comercial ─────►  │  ComercialController::store()                │
  (wizard)          │   ├─ Company::create (adman/ml = NULL) OK    │
                    │   ├─ ContratoServico(servico Shopee, setor=  │
                    │   │    'shopee', ativo=true)                  │
                    │   └─ servicoDisparaImplementacao('Shopee')   │
                    │        => null  (NÃO cria MlbEmpresa)         │
                    └───────────────┬─────────────────────────────┘
                                    │ contrato setor='shopee'
                                    ▼
        ┌───────────────────────────────────────────────────────────┐
        │  Aba "Empresas" Shopee (leitura, enxuta)                   │
        │  GET /shopee/empresas  [permission:shopee.empresas]        │
        │  ShopeeEmpresasController@index                            │
        │   ├─ whereHas(contratosServico ativo, servico.setor=      │
        │   │    'shopee')                                           │
        │   ├─ SEM withCount(grants), SEM métrica, SEM cust_id      │
        │   └─ pendencias = [sem_responsavel, sem_contato,          │
        │        empresa_nova]                                       │
        │            │ props Inertia                                 │
        │            ▼                                               │
        │  resources/js/Pages/Shopee/Empresas.jsx                   │
        │   ├─ abas Todas | Pendências                               │
        │   ├─ atribuição Analista(role=consultor)/Estrategista     │
        │   │    ──► POST /shopee/empresas/bulk-assign (pivot)      │
        │   └─ botão "Gerar NPS" por linha                          │
        └────────────────────────────┬──────────────────────────────┘
                                      │ POST /nps/generate {company_id}
                                      ▼
                    ┌─────────────────────────────────────────────┐
                    │  NPS (INTOCADO — DEC-5)                       │
                    │  NpsController::generate                     │
                    │   ├─ auth: admin OU membro do pivot          │
                    │   ├─ resolveForCompany → fallback is_default │
                    │   └─ NÃO lê métricas → funciona sem ML       │
                    └─────────────────────────────────────────────┘

  Gate de menu:  AppLayout.jsx  grupo "Shopee" ──► itemVisivel(shopee.empresas)
  Gate de rota:  EnsurePermission middleware ──► permission:shopee.empresas
  Fonte de acesso: admin (implícito) + Setor Shopee (criado manualmente em /setores)
```

### Component Responsibilities

| Componente | Responsabilidade | Path (referência) |
|------------|------------------|-------------------|
| Migration enum | Adiciona `'shopee'` ao `servicos.setor` (MySQL + SQLite) | espelhar `2026_07_03_113103_add_polos_to_servicos_setor_enum.php` |
| Migration seed | Cria `Servico` "Shopee" idempotente | espelhar `2026_05_27_100001_seed_servicos_catalog.php` (firstOrCreate) |
| `Servico::SETOR_SHOPEE` | Constante + entrada em `SETORES` + `setoresLabels()` | `app/Models/Servico.php:52-89` |
| `ComercialController` | Não disparar impl ML para Shopee | `servicoDisparaImplementacao()` :54, `slugSetorParaServico()` :867 |
| `ShopeeEmpresasController@index` | Payload enxuto filtrado por setor shopee | espelhar `CompanyController@index` :76-200 |
| `Shopee/Empresas.jsx` | UI enxuta (abas, pendências, atribuição, Gerar NPS) | espelhar `Companies/Index.jsx` |
| `Permissions::SHOPEE_EMPRESAS` | Nova key + grupo "Shopee" no catálogo | `app/Support/Permissions.php:123-180` |
| `AppLayout` NAV_TREE | Stub "Em breve" → grupo "Shopee" real | `resources/js/Layouts/AppLayout.jsx:108-115` |

### Pattern 1: Migration de enum cross-driver (split MySQL / SQLite)

**What:** MySQL aceita `ALTER TABLE ... MODIFY COLUMN`; SQLite **não** suporta ALTER de CHECK, mas **enforça** o CHECK existente.
**When to use:** Sempre que adicionar valor a um enum que os testes precisam persistir.

```php
// Source: projeto — combinação de 2026_07_03_113103 (branch MySQL) +
//         behavior nativo de ->change() do Laravel 12 (branch SQLite)
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

public function up(): void
{
    if (Schema::getConnection()->getDriverName() === 'mysql') {
        // Prod: ALTER direto (padrão da migration de polos).
        DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','shopee','outros') NOT NULL DEFAULT 'outros'");
    } else {
        // SQLite (tests): CHECK é ENFORÇADO — ver Pitfall 1. ->change() rebuilda
        // a tabela preservando dados e regenerando o CHECK com 'shopee'.
        // INCLUIR TODOS os valores (polos nunca entrou no CHECK do SQLite pois a
        // migration de polos pulou este driver).
        Schema::table('servicos', function (Blueprint $table) {
            $table->enum('setor', ['performance','publicacao','polos','shopee','outros'])
                  ->default('outros')
                  ->change();
        });
    }
}

public function down(): void
{
    if (Schema::getConnection()->getDriverName() === 'mysql') {
        DB::table('servicos')->where('setor', 'shopee')->update(['setor' => 'outros']);
        DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','outros') NOT NULL DEFAULT 'outros'");
    } else {
        DB::table('servicos')->where('setor', 'shopee')->update(['setor' => 'outros']);
        Schema::table('servicos', function (Blueprint $table) {
            $table->enum('setor', ['performance','publicacao','polos','outros'])
                  ->default('outros')->change();
        });
    }
}
```

> **CONFIANÇA MÉDIA** no branch SQLite via `->change()`: o Laravel 11+/12 implementa `->change()` nativamente (sem doctrine/dbal) e recria a tabela SQLite preservando dados e CHECK. `[ASSUMED]` que o rebuild regenera o CHECK do enum corretamente. **Alternativa mais robusta e recomendada** (elimina de vez esta classe de bug): no branch SQLite, tornar a coluna um `string` puro sem CHECK — `$table->string('setor')->default('outros')->change();`. Assim qualquer `setor` futuro (polos, shopee, ou o próximo marketplace) nunca mais quebra teste. A paridade de domínio com prod já é garantida pelo enum MySQL + validação de aplicação. **O planner deve escolher e o executor deve VALIDAR rodando o teste da migration antes de seguir** (Pitfall 1).

### Pattern 2: Seed idempotente de serviço no catálogo

**What:** `firstOrCreate` por `nome` (não `updateOrCreate`) — preserva `valor_padrao` editado manualmente via UI.
**When to use:** Semear a entrada "Shopee" no catálogo `Servico`.

```php
// Source: 2026_05_27_100001_seed_servicos_catalog.php:44-55
use App\Models\Servico;

public function up(): void
{
    Servico::firstOrCreate(
        ['nome' => 'Shopee'],
        [
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_SHOPEE, // 'shopee'
        ],
    );
}
```

> **Ordem de migrations importa:** o seed do serviço (setor='shopee') precisa rodar **depois** da migration de enum (timestamp posterior). Caso contrário, em MySQL o INSERT do serviço com `setor='shopee'` falha (enum ainda não aceita o valor). Verificar os timestamps dos dois arquivos.

> **Nota de Discretion (DEC/Claude):** o projeto usa tanto `Servico::firstOrCreate` (model) quanto `DB::table` puro. Para um único serviço de catálogo, `firstOrCreate` é o padrão dominante e preferível (dispara activity log e respeita casts). Data-migration vs seeder dedicado: o projeto semeia catálogo via **migration** (não `database/seeders`), então usar migration mantém consistência e garante execução em prod via `migrate --force`.

### Pattern 3: Controller de listagem enxuto (espelhar `CompanyController@index`)

**What:** Copiar a estrutura de `CompanyController@index` (`CompanyController.php:76-243`) removendo tudo que é ML/métrica.

**Manter:** filtro por contrato de setor, eager-load `consultor`/`estrategista`/`contratosServico.servico`/`grupo`, listas `estrategistas`/`analistas` via `usersPorCargo()` (:206-222), `grupos`.

**Remover (versão Shopee):**
- `->withCount(['grants as grants_ativos_count'...])` (:107)
- `->whereDoesntHave('mlbEmpresa')` (:108) — **não** aplicar; empresa multi-marketplace deve aparecer nas duas abas (specifics do CONTEXT).
- eager `mlToken` (:103), payload `cust_id_status`/`adman_account_id`/`ml_store_id`/`ml_token_status` (:138,155-157,172)
- `MetricsProviderFactory`/`AdmanService`/`EcfDriveService` do constructor (:23-27) — **não** injetar
- `factoryToSource()` (:39-47)
- pendências `sem_cust_id`, `sem_email_colaborador`, `sem_grant_ativo` (:191-193)

**Filtro do gatilho (mesmo padrão de `CompanyController.php:113-118`):**
```php
// Source: adaptado de CompanyController.php:113-118 (troca SETOR_PERFORMANCE por SETOR_SHOPEE)
->whereHas('contratosServico', fn($q) =>
    $q->where('contratos_servico.ativo', true)
      ->whereHas('servico', fn($qs) => $qs->where('setor', Servico::SETOR_SHOPEE))
)
```

**Pendências Shopee (DEC-2):**
```php
// email_cliente e digisac_group_contact_id são as duas condições que o disparo
// mensal usa (NpsDispararMensal.php:146-162). "sem_contato" = ausência de AMBOS.
'pendencias' => array_values(array_filter([
    ($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null,
    (empty($c->email_cliente) && empty($c->digisac_group_contact_id)) ? 'sem_contato' : null,
    $c->empresa_nova ? 'empresa_nova' : null,
])),
```

### Pattern 4: Atribuição de responsáveis (pivot `company_users`) sob gate Shopee

**What:** Reaproveitar a lógica de `bulkAssign` (`CompanyController.php:683-700`) e o sync de `update` (:617-628), mas em rotas `shopee.empresas.*` gated por `permission:shopee.empresas` — **não** reabrir `core.empresas`.

**Mapeamento crítico de label→pivot (Company.php:164-179):**
- Label "Analista" → `role='consultor'` no pivot
- Label "Estrategista" → `role='estrategista'` no pivot

```php
// Source: CompanyController.php:692-696 — replicar em ShopeeEmpresasController::bulkAssign
foreach (Company::whereIn('id', $data['ids'])->get() as $c) {
    DB::table('company_users')->where('company_id', $c->id)->where('role', $data['role'])->delete();
    $c->users()->attach($data['user_id'], ['role' => $data['role'], 'assigned_at' => now()->toDateString()]);
}
// $data['role'] validado in:consultor,estrategista
```

### Pattern 5: Deep-link "Gerar NPS" (reaproveitar, não reimplementar — DEC-5)

**What:** O motor NPS já expõe `POST /nps/generate` (rota nomeada `nps.generate`, `routes/web.php:100`). No React basta um `useForm({ company_id, template_id: '' })` e `post(route('nps.generate'))`. O controller cria a survey, e o link volta via flash `nps_link` (`NpsController.php:408-411`), capturado por `flash?.nps_link` para abrir o modal.

```jsx
// Source: Nps/Index.jsx:864,909-914 — padrão a reusar por linha da aba Shopee
const { data, setData, post } = useForm({ company_id: '', template_id: '' });
const gerarNps = (companyId) => {
    setData('company_id', companyId);
    post(route('nps.generate'), { onSuccess: () => {/* flash.nps_link abre modal */} });
};
// O link gerado chega em usePage().props.flash.nps_link (useEffect como em Nps/Index.jsx:866-871)
```

> **Autorização (NpsController.php:370-375):** admin passa; usuário não-admin só gera se a empresa estiver em `user->companies()` (qualquer role do pivot). Como a aba Shopee atribui Analista/Estrategista via pivot, o gate NPS já funciona sem alteração. **Sem template específico**, cai no `resolveForCompany` → fallback `is_default` (garantido). Empresa Shopee sem métrica gera NPS normalmente.

### Pattern 6: Permission key + grupo no catálogo + gate de menu

**Backend (`app/Support/Permissions.php`):**
```php
// 1) Constante (junto às demais, ex. após MLB_* :60)
/** Aba Empresas do marketplace Shopee (listagem enxuta + atribuição p/ NPS). */
public const SHOPEE_EMPRESAS = 'shopee.empresas';

// 2) Novo grupo no catalog() :123-180 (espelha 'Publicações (MLB)')
'Shopee' => [
    ['key' => self::SHOPEE_EMPRESAS, 'label' => 'Shopee · Empresas', 'description' => 'Empresas atendidas na Shopee (habilita NPS)'],
],
```
- `Permissions::all()` já agrega automaticamente qualquer grupo novo (:187-196) → admin recebe a key via `effectivePermissions()` short-circuit (`User.php:159-161`). **Nada a fazer para admin.**
- `EnsurePermission` middleware casa `permission:shopee.empresas` na rota chamando `hasPermission()` (que faz short-circuit para admin, `User.php:140-145`).
- Setor Shopee (criado manualmente em `/setores`) recebe a key via `SetorPermissao` e `syncPermissoes()`.

**Frontend (`AppLayout.jsx:108-115`) — transformar stub em grupo:**
```jsx
// ANTES: item de topo com badge "Em breve" (:108-115)
// DEPOIS: grupo espelhando "Mercado Livre" (:48-99), mantendo o Dashboard stub como filho
{
    label: 'Shopee', // (usar shape de grupo)
    group: 'Shopee',
    icon: ShoppingCart,
    iconSrc: '/images/shopee-icon.svg',
    children: [
        { label: 'Empresas', routeName: 'shopee.empresas.index', page: 'Shopee/Empresas', icon: Building2, permission: 'shopee.empresas' },
        { label: 'Dashboard', routeName: 'shopee.dashboard', page: 'Dashboard/ShopeeShell', icon: LayoutDashboard, badgeText: 'Em breve' },
    ],
},
```
- `itemVisivel()` (:296-303) já gate por `permission`. Grupo sem `permission` própria é descartado só se **nenhum** filho for visível (:308-311+). Admin vê ambos; usuário Shopee vê "Empresas" (Dashboard stub tem só `badgeText`, sem permission → visível a todos autenticados — **decidir** se quer gate; hoje o stub não tem permission).

### Anti-Patterns to Avoid
- **Reusar `marketplaces_extras` como gatilho:** DEC-1 proíbe — significa "cliente já vende por conta própria", conceito distinto. O gatilho é contrato de setor `shopee`.
- **Usar `companies.marketplace='shopee'` (coluna legada, ~33 empresas) como gatilho:** CONTEXT specifics — essa coluna fica intocada; NÃO é o filtro.
- **Pular o SQLite na migration de enum:** quebra os Feature tests (ver Pitfall 1).
- **Abrir `core.empresas` para usuários Shopee:** DEC-4 — usar rotas dedicadas gated por `shopee.empresas`.
- **Aplicar `whereDoesntHave('mlbEmpresa')` na aba Shopee:** empresa multi-marketplace deve aparecer nas duas abas (comportamento correto).
- **Injetar `AdmanService`/`MetricsProviderFactory` no controller Shopee:** dispara jobs/HTTP inúteis; a aba é ML-free.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Gerar link NPS | Nova lógica de survey/token | `POST /nps/generate` existente (`nps.generate`) | DEC-5 — motor intocado; token/expiry/template já resolvidos |
| Atribuir responsável | Novo pivot/tabela | `company_users` + padrão `bulkAssign` (:683-700) | Pivot e mapeamento label→role já existem |
| Listar empresas | Query nova do zero | Espelhar `CompanyController@index` | Eager-loads, `usersPorCargo`, grupos já resolvidos |
| Resolver template NPS | Escolher template no controller Shopee | `NpsTemplateService::resolveForCompany` (fallback is_default) | Garante template sempre resolvível sem métrica |
| Gate de admin | Conceder key manualmente ao admin | `Permissions::all()` + short-circuit `isAdmin()` | Admin recebe TODAS as keys automaticamente |
| Enum widening MySQL | String livre / tabela lookup | `ALTER ... MODIFY COLUMN ENUM(...)` (padrão polos) | Consistência com o schema atual |

**Key insight:** Praticamente tudo que a phase precisa já existe como padrão testado no projeto. O risco não é complexidade nova — é **replicar o molde ML sem arrastar acoplamento a Adman/ML** e **acertar o comportamento cross-driver do enum**.

## Runtime State Inventory

> Phase majoritariamente aditiva, mas há efeitos de schema/dados em produção.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `servicos.setor` (enum MySQL prod) ganha valor `'shopee'` via ALTER. ~33 companies com `marketplace='shopee'` (coluna legada) **NÃO** são migradas nem usadas como gatilho (CONTEXT specifics). | ALTER enum (migration) + seed 1 linha em `servicos`. Nenhuma migração das 33 empresas legadas. |
| Live service config | Nenhuma — sem integração/API Shopee (OUT of scope). | Nenhuma. |
| OS-registered state | Nenhuma — sem cron/worker novo (disparo NPS mensal já existente cobre Shopee automaticamente por `active=true` + contato). | Nenhuma. |
| Secrets/env vars | Nenhuma — sem credenciais Shopee nesta phase. | Nenhuma. |
| Build artifacts | Bundle Vite muda (novo `Shopee/Empresas.jsx` + NAV_TREE). | `npm run build` após frontend (convenção). |

**Nada encontrado além do acima:** verificado por leitura de `ComercialController`, `CompanyController`, `NpsDispararMensal`, `Permissions`, `AppLayout` e rotas.

## Common Pitfalls

### Pitfall 1: SQLite ENFORÇA o CHECK do enum — a migration de polos "mentiu" ao pular o driver
**What goes wrong:** Feature test cria `Servico::factory()->create(['setor'=>'shopee'])` (ou `firstOrCreate`) e recebe `SQLSTATE[23000]: CHECK constraint failed: setor IN ('performance','publicacao','outros')`. Toda a suite Shopee falha no setup.
**Why it happens:** `add_setor_to_servicos_table` (:34) criou a coluna com `enum(['performance','publicacao','outros'])` → em SQLite vira `CHECK (setor IN (...))` **enforçado**. A migration de polos (:35-37) **pulou o SQLite** — logo o CHECK do banco de teste **nunca** ganhou nem `polos` nem, obviamente, `shopee`. Nenhum teste cria serviço `polos`, então ninguém percebeu.
**How to avoid:** Migration de enum da Phase 75 **deve** tratar o branch SQLite (Pattern 1) — `->change()` com todos os valores, ou coluna `string` sem CHECK (mais robusto).
**Warning signs:** `CHECK constraint failed: setor` no `setUp()` dos testes. **Verificado empiricamente nesta pesquisa** (`SQLSTATE[23000]`).

### Pitfall 2: Ordem das migrations (enum antes do seed)
**What goes wrong:** Em MySQL, o seed do serviço `setor='shopee'` roda antes do ALTER do enum → INSERT rejeitado.
**Why it happens:** Migrations rodam por ordem de timestamp.
**How to avoid:** Garantir timestamp do arquivo de seed > timestamp do arquivo de enum.
**Warning signs:** `Data truncated for column 'setor'` em `migrate` no deploy.

### Pitfall 3: `servicoDisparaImplementacao('Shopee')` acidentalmente casar um prefixo ML
**What goes wrong:** Se o nome do serviço contiver "Assessoria"/"Polos"/"Incubadora", o helper (`ComercialController.php:54-62`) dispara criação de `MlbEmpresa` indevida.
**Why it happens:** O helper usa `str_contains` case-sensitive nos prefixos ML.
**How to avoid:** Nome exato **"Shopee"** não casa nenhum prefixo → helper retorna `null` (correto, DEC-1). **Não** renomear para algo como "Assessoria Shopee". `slugSetorParaServico('Shopee')` (:867) também retorna `null` → nenhuma notificação de setor ML (aceitável). Se desejar notificar um líder do Setor Shopee, adicionar `str_contains($nome,'Shopee') => 'shopee'` ao `slugSetorParaServico` (opcional, discricionário).
**Warning signs:** Empresa Shopee aparecendo em `/mlb/empresas` ou com `MlbEmpresa` associada.

### Pitfall 4: Empresa Shopee sem responsável nunca dispara NPS mensal
**What goes wrong:** `NpsDispararMensal` (:196-203) **exige estrategista** — pula empresa sem estrategista. Empresa Shopee cadastrada sem atribuir Estrategista não recebe NPS automático (só manual).
**Why it happens:** Regra D-07 Phase 31 (estrategista obrigatório no disparo mensal).
**How to avoid:** É por isso que a pendência `sem_responsavel` existe na aba (DEC-2). Não é bug — é o fluxo esperado; a UI sinaliza a pendência. Documentar para o usuário.
**Warning signs:** "sem estrategista atribuido, pulando disparo" no log do comando.

## Code Examples

Já cobertos inline nos Patterns 1-6 (todos com `// Source:` apontando para o arquivo/linha real do projeto).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Enum change via doctrine/dbal | `->change()` nativo do Laravel (sem dbal) | Laravel 11 | SQLite rebuilda tabela nativamente; `doctrine/dbal` não é dependência |
| Flag/coluna por marketplace | Contrato de serviço por setor | Phase 37+ | Setor derivado do catálogo, sem coluna paralela em `companies` |
| Disparo NPS por serviço | Sempre modelo principal `is_default` | 2026-07-13 | Empresa Shopee usa o mesmo template principal, sem config extra |

**Deprecated/outdated:**
- `companies.marketplace` como fonte de verdade de marketplace — legado operacional; NÃO usar como gatilho.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `->change()` do Laravel 12 recria o CHECK do enum corretamente no SQLite preservando dados | Pattern 1 | Migration de teste falha; mitigável com fallback `string` sem CHECK (já recomendado) |
| A2 | Nenhum teste existente depende do CHECK do SQLite bloquear valores inválidos de `setor` | Pattern 1 | Improvável — nenhum teste testa rejeição de setor inválido (grep não achou) |
| A3 | Nome exato "Shopee" não casa prefixos ML em `servicoDisparaImplementacao`/`slugSetorParaServico` | Pitfall 3 | Verificado por leitura: `str_contains` só casa Polos/Assessoria/Incubadora/Publicação/Publicidade/Gestão |
| A4 | Stub Dashboard Shopee (`shopee.dashboard`) permanece acessível como filho do grupo | Pattern 6 / DEC-3 | Baixo — rota já existe (`routes/web.php:325`) |

## Open Questions (RESOLVED)

> Resoluções adotadas nos plans (2026-07-14): Q1 → Plan 01 trava `string()->change()` no branch SQLite (com teste antes de seguir); Q2 → mantido `null` (sem notificação ao líder Shopee) — discricionário, fora de escopo; Q3 → Dashboard stub segue sem gate (só "Em breve"), grupo aparece pelo filho "Empresas" gated. Nenhuma pendência bloqueante.

1. **Branch SQLite: `enum()->change()` vs `string()->change()`?** _(RESOLVED — `string()->change()`, Plan 01)_
   - What we know: SQLite enforça CHECK; `->change()` rebuilda a tabela.
   - What's unclear: se o rebuild do enum regenera o CHECK corretamente em todas as versões-patch do Laravel 12 instaladas (A1).
   - Recommendation: usar `string` sem CHECK no branch SQLite (mais robusto, encerra a classe de bug polos/shopee/próximos). O executor DEVE rodar o teste da migration antes de prosseguir.

2. **Notificar líder do Setor Shopee no cadastro?**
   - What we know: `slugSetorParaServico('Shopee')` retorna `null` hoje → nenhuma notificação.
   - What's unclear: se o usuário quer notificar o líder do Setor Shopee ao cadastrar.
   - Recommendation: fora de escopo estrito; deixar `null` (sem notificação) salvo pedido explícito. Discricionário.

3. **Gate do Dashboard stub Shopee dentro do grupo.**
   - What we know: o stub hoje não tem `permission` (visível a todos autenticados).
   - Recommendation: manter sem gate (é só um "Em breve"), ou gate por `shopee.empresas` se quiser esconder o grupo inteiro de quem não tem a permission. Decisão de UX menor.

## Environment Availability

**SKIPPED** — phase é código/config puro dentro da stack existente (Laravel + Inertia + React já instalados). Sem tools/serviços/runtimes externos novos. MySQL (prod) e SQLite (`:memory:` tests) já em uso.

## Validation Architecture

> `workflow.nyquist_validation = true` em `.planning/config.json` → seção obrigatória.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^11.5 (Feature tests, `RefreshDatabase`) |
| Config file | `phpunit.xml` (DB_CONNECTION=sqlite, DB_DATABASE=:memory:) |
| Quick run command | `php artisan test --filter Phase75` |
| Full suite command | `php artisan test` |

> Nota: `php` está em `/c/xampp/php/php.exe` (não no PATH do bash). Frontend build: `npm run build`.

### Phase Requirements → Test Map
| Req (DEC) | Behavior | Test Type | Automated Command | File Exists? |
|-----------|----------|-----------|-------------------|-------------|
| DEC-1 | Migration adiciona `'shopee'` e persiste em MySQL **e** SQLite | unit/migration | `php artisan test --filter Phase75MigrationTest` | ❌ Wave 0 |
| DEC-1 | Seed cria `Servico` "Shopee" idempotente (rodar 2× não duplica) | integration | `php artisan test --filter test_seed_shopee_idempotente` | ❌ Wave 0 |
| DEC-1 | `ComercialController::store` cadastra empresa Shopee com `adman_account_id`/`ml_store_id` NULOS sem quebrar e **sem** criar `MlbEmpresa` | feature | `php artisan test --filter test_cadastro_shopee_sem_ml` | ❌ Wave 0 |
| DEC-4 | Aba filtra só empresas com contrato ativo setor `shopee`; multi-marketplace aparece nas duas abas | feature | `php artisan test --filter test_aba_shopee_filtra_por_setor` | ❌ Wave 0 |
| DEC-2 | Pendências = `sem_responsavel` / `sem_contato` (email E digisac vazios) / `empresa_nova`; **não** retorna `sem_cust_id`/`sem_grant_ativo` | feature | `php artisan test --filter test_pendencias_shopee` | ❌ Wave 0 |
| DEC-4 | Atribuição grava pivot `role=consultor` (Analista) / `role=estrategista` sob gate `shopee.empresas` | feature | `php artisan test --filter test_atribuicao_responsaveis_shopee` | ❌ Wave 0 |
| DEC-3 | Rota `shopee.empresas.index` retorna 403 sem a permission e 200 para admin / setor com a key | feature | `php artisan test --filter test_permission_gate_shopee` | ❌ Wave 0 |
| DEC-3 | `Permissions::all()` inclui `shopee.empresas`; admin a recebe via `effectivePermissions` | unit | `php artisan test --filter test_permission_no_catalogo` | ❌ Wave 0 |
| DEC-5 | Empresa Shopee (sem métrica) gera NPS via `nps.generate` com fallback template `is_default` | feature | `php artisan test --filter test_gerar_nps_empresa_shopee` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter Phase75`
- **Per wave merge:** `php artisan test` (suite completa — verificar zero regressão em NPS/Comercial/Companies)
- **Phase gate:** suite verde + `npm run build` sem erro antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase75MigrationTest.php` — enum aceita `shopee` em SQLite (prova o fix do Pitfall 1) + seed idempotente
- [ ] `tests/Feature/Phase75ShopeeEmpresasTest.php` — filtro da aba, pendências, atribuição, permission gate, cadastro sem ML
- [ ] `tests/Feature/Phase75NpsShopeeTest.php` — geração de NPS para empresa Shopee sem métrica
- [ ] Factory: usar `Servico::factory()` (existe? verificar `database/factories` — **não há `ServicoFactory`**; criar ou usar `Servico::create`/`firstOrCreate` direto nos testes, padrão dos testes atuais em `Phase37ComercialListagemTest::criarServico`)
- [ ] Reusar helper `criarServico($nome, $setor)` do `Phase37ComercialListagemTest.php:58` como referência

## Security Domain

> `security_enforcement` ausente = habilitado. Foco: controle de acesso (RBAC) — a única superfície de segurança relevante desta phase.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não | Sessão Laravel/Breeze já existente |
| V3 Session Management | não | Inalterado |
| V4 Access Control | **sim** | `permission:shopee.empresas` via `EnsurePermission` middleware nas rotas + `itemVisivel` no menu (defesa dupla); atribuição de responsável separada de `core.empresas` |
| V5 Input Validation | sim | `$request->validate` nos endpoints (mesmos rules do molde: `ids.*` exists, `role` in:consultor,estrategista, `user_id` exists) |
| V6 Cryptography | não | Nenhuma cripto nova |

### Known Threat Patterns for Laravel RBAC + Inertia

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Usuário Shopee acessa CRUD completo de `/companies` | Elevation of Privilege | Rotas Shopee dedicadas gated por `shopee.empresas`, NÃO `core.empresas` (DEC-4) |
| IDOR ao atribuir responsável a empresa fora do escopo | Tampering | `exists:companies,id` + gate por permission (endpoint admin-equivalente); considerar limitar a empresas com contrato Shopee se o Setor Shopee não for admin |
| Mass assignment via payload extra no store | Tampering | `$validated` já filtra; Shopee não adiciona campos fillable novos |
| Menu vaza item sem backend gate | Info Disclosure | `itemVisivel` (frontend) + `EnsurePermission` (backend) — nunca confiar só no menu |

> **Nota de escopo:** o disparo NPS (DEC-5) já valida autorização (`NpsController.php:370-375`) — admin OU membro do pivot. Nenhuma mudança de segurança no motor NPS.

## Sources

### Primary (HIGH confidence)
- Código-fonte do projeto (leitura direta): `ComercialController.php`, `CompanyController.php`, `Servico.php`, `Company.php`, `User.php`, `Permissions.php`, `NpsController.php`, `NpsDispararMensal.php`, `AppLayout.jsx`, `Companies/Index.jsx`, `Nps/Index.jsx`, `routes/web.php`
- Migrations: `2026_07_03_113103_add_polos_to_servicos_setor_enum.php`, `2026_06_18_100001_add_setor_to_servicos_table.php`, `2026_05_27_100001_seed_servicos_catalog.php`, `2026_07_07_100004_seed_nps_template_padrao...php`
- Teste empírico: SQLite CHECK constraint **enforça** enum (`SQLSTATE[23000]`) — executado via `/c/xampp/php/php.exe`
- `.planning/phases/75-.../75-CONTEXT.md` (decisões travadas) + `CLAUDE.md`

### Secondary (MEDIUM confidence)
- Comportamento nativo de `->change()` no Laravel 11/12 (sem doctrine/dbal) — inferido de `composer.json ^12.0` + ausência de `doctrine/dbal` como dep direta + precedente de `->change()` em 7 migrations do projeto. `[ASSUMED]` que regenera CHECK de enum no rebuild SQLite.

### Tertiary (LOW confidence)
- Nenhuma — não foi necessária busca externa; domínio é 100% interno ao codebase.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — sem pacote novo; tudo verificado em código
- Architecture / patterns: HIGH — todos os moldes lidos linha a linha com refs de arquivo/linha
- Enum cross-driver: HIGH no problema (CHECK enforçado — testado empiricamente), MEDIUM na solução `->change()` SQLite (A1 — mitigação `string` recomendada)
- Pitfalls: HIGH — derivados de leitura direta + teste empírico

**Research date:** 2026-07-14
**Valid until:** 2026-08-13 (estável — codebase interno; re-verificar só se `Servico`/`ComercialController`/`Permissions` mudarem)
