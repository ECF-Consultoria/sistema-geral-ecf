# Phase 75: Empresas Shopee — habilitar NPS para clientes atendidos na Shopee (sem métricas/API) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** Exploração dirigida (3 agentes Explore) + 3 decisões travadas com o usuário via AskUserQuestion

<domain>
## Phase Boundary

**Problema:** Vamos começar a rodar o NPS, mas empresas que a ECF atende **apenas na Shopee** (sem Mercado Livre) ainda não existem no sistema. Como ainda **não há API da Shopee**, essas empresas **não terão métricas**. O objetivo único desta phase é **habilitar a criação de NPS em nome dessas empresas** — cadastrá-las pelo Comercial, dar-lhes uma aba própria com pendências + atribuição de responsáveis, e nada mais.

**Entrega desta phase (IN SCOPE):**
1. Modelagem de "empresa atendida na Shopee" via **contrato de serviço** (espelhando o ML, que se apoia em contrato de setor `performance`).
2. Serviço "Shopee" no catálogo (setor novo `shopee`) que aparece no wizard do Comercial.
3. Nova aba **"Empresas" da Shopee** — versão enxuta da aba ML, **sem** colunas de métrica/grant/cust_id.
4. Pendências mínimas voltadas ao NPS + atribuição de Analista/Estrategista.
5. Permission key `shopee.empresas` + grupo "Shopee" no menu (transformando o stub "Em breve").

**Fora de escopo (OUT):**
- Qualquer integração/API Shopee, sync de métricas, dashboards com dados reais.
- Mudanças no motor de NPS (ele já funciona sem métricas — ver decisões).
- O CRUD do "Setor Shopee" organizacional (RBAC) em si — **o usuário criará o setor manualmente** pela tela de setores já existente; esta phase só precisa **criar a permission key** que esse setor poderá receber.
</domain>

<decisions>
## Implementation Decisions

### DEC-1 — Gatilho/modelagem: Serviço "Shopee" no catálogo (LOCKED)
Uma empresa entra na aba Shopee por ter **contrato de serviço ativo de setor `shopee`** — mesma mecânica do ML (que filtra por contrato de setor `performance`). NÃO usar flag booleana nem reaproveitar `marketplaces_extras` (que significa "cliente já vende por conta própria", conceito distinto).
- Adicionar valor `'shopee'` ao ENUM `servicos.setor` (padrão da migration que adicionou `'polos'`: `2026_07_03_113103_add_polos_to_servicos_setor_enum.php`).
- Adicionar constante `Servico::SETOR_SHOPEE = 'shopee'` (ao lado de `SETOR_PERFORMANCE`/`SETOR_PUBLICACAO`/`SETOR_POLOS`/`SETOR_OUTROS` em `app/Models/Servico.php:52-65`).
- Criar (seeder/data-migration idempotente) o serviço **"Shopee"** com `setor='shopee'`, `ativo=true`, para aparecer automaticamente no wizard do Comercial (`servicos_disponiveis` já lê `Servico::ativo` em `ComercialController::create()`).
- Ajustar `ComercialController` para que o serviço Shopee **NÃO** dispare fluxo de implementação ML (`servicoDisparaImplementacao()` em `ComercialController.php:54` e `slugSetorParaServico()` em `ComercialController.php:867` devem retornar `null`/setor `shopee`, nunca `polos|assessoria|incubadora`).
- Garantir que uma empresa **sem nenhum dado ML** (`adman_account_id`/`ml_store_id` nulos) cadastre e salve sem quebrar (a origem exclusiva de cadastro é `ComercialController::store()` em `ComercialController.php:499`).

### DEC-2 — Pendências da aba Shopee: SOMENTE o essencial pro NPS (LOCKED)
A aba mostra apenas as pendências que importam para disparar NPS (sem métricas/grants):
- `sem_responsavel` — nenhum Analista nem Estrategista atribuído.
- `sem_contato` — sem email do cliente (`companies.email_cliente`) **E** sem grupo Digisac mapeado (as duas condições que o disparo automático mensal exige — ver `NpsDispararMensal.php:146-162`).
- `empresa_nova` — tag de empresa recém-cadastrada (`companies.empresa_nova`).
NÃO incluir `sem_cust_id`, `sem_grant_ativo` (não se aplicam à Shopee), nem qualquer pendência dependente de métrica.

### DEC-3 — Acesso/Menu: nova permission `shopee.empresas` (LOCKED)
- Criar a permission key `shopee.empresas` no catálogo estático `app/Support/Permissions.php` (`Permissions::catalog()` em `Permissions.php:123-180`), sob um grupo "Shopee".
- Essa key será atribuível ao **Setor Shopee** (que o usuário criará manualmente via `/setores`) + **admin sempre vê** (padrão do projeto: `is_system`/admin recebem todas as permissões).
- `resources/js/Layouts/AppLayout.jsx`: transformar o **stub de topo "Shopee — Em breve"** (`AppLayout.jsx:108-115`, hoje aponta pra `shopee.dashboard`/`Dashboard/ShopeeShell` com `badgeText: 'Em breve'`) num **grupo "Shopee"** real (espelhando o grupo "Mercado Livre" em `AppLayout.jsx:48-99`), com o filho **"Empresas"** (gate `shopee.empresas`). Manter o Dashboard stub existente como filho.

### DEC-4 — Aba "Empresas" da Shopee: espelhar o ML, versão enxuta (LOCKED)
Molde: `CompanyController@index` (`app/Http/Controllers/CompanyController.php:76`) + `resources/js/Pages/Companies/Index.jsx`.
- **Controller dedicado** (ex.: `ShopeeEmpresasController@index`) espelhando `CompanyController@index`, MAS: filtro `whereHas('contratosServico', setor='shopee')` (mesmo padrão de `CompanyController.php:113-118` que usa `Servico::SETOR_PERFORMANCE`), **sem** `withCount` de grants, **sem** colunas/payload de métrica/cust_id/MlStatusBadge.
- **Página** `resources/js/Pages/Shopee/Empresas.jsx` (versão enxuta do `Companies/Index.jsx`): abas "Todas"/"Pendências", coluna de pendências (dicionário DEC-2), atribuição de **Analista** (grava pivot `company_users.role='consultor'`) e **Estrategista** (`role='estrategista'`) — ver mapeamento label↔pivot em `Company.php:164-179` e `CompanyController.php:698`.
- **Botão "Gerar NPS"** por linha: deep-link para o fluxo de NPS já existente (`POST /nps/generate` → `NpsController::generate` em `NpsController.php:353`; modal "Gerar Link NPS" em `Nps/Index.jsx:1109`). Reaproveitar, não reimplementar.
- **Rotas dedicadas** `shopee.empresas.*` (index + endpoints de atribuição de responsáveis) gated por `permission:shopee.empresas`. Reusar a lógica de sync do pivot de `CompanyController::update` (`CompanyController.php:617-628`) / `bulkAssign` (`CompanyController.php:683-700`) — replicar em rotas Shopee para manter o RBAC limpo (não abrir `core.empresas` pra usuários Shopee).

### DEC-5 — NPS: sem mudanças no motor (LOCKED / confirmado por exploração)
`NpsController::generate` (`NpsController.php:353-412`) só exige `company_id` válido + autorização (admin OU membro do pivot `company_users`) + template resolvível (fallback garantido no `is_default`). **Não lê métricas.** O disparo automático mensal (`NpsDispararMensal.php`) exige apenas `active=true` + canal de contato (email/Digisac) + estrategista atribuído — nenhuma condição de métrica. Logo, empresa Shopee com responsável + contato entra no NPS normalmente **sem nenhuma alteração no NPS**.

### Claude's Discretion
- Nome exato do controller/rota/arquivo React (sugestões acima são orientativas).
- Se a atribuição de responsáveis reusa endpoints Shopee dedicados ou um controller compartilhado — desde que o gate seja `shopee.empresas` e não `core.empresas`.
- Forma exata do "sem_contato" (helper no controller vs accessor no model).
- Se o serviço "Shopee" é semeado por seeder dedicado ou data-migration (seguir o padrão dominante do projeto — migrations com `DB::table` puro, ver Phase 68 seed).
- Valor/preço default do contrato Shopee no cadastro (pode herdar a lógica de valor existente do wizard).
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Cadastro comercial (origem única de empresas)
- `app/Http/Controllers/ComercialController.php` — `create()` :71, `store()` :499 (DB::transaction :554, cria Company `status='pendente'` :556, 1 ContratoServico por serviço :587, roteamento MLB :609-633), helper `servicoDisparaImplementacao()` :54, `slugSetorParaServico()` :867, `calcularPendenciasComerciais()` :404.
- `resources/js/Pages/Comercial/NovaEmpresa.jsx` — wizard 2 passos; `MARKETPLACES_EXTRAS` :52-58 (NÃO reusar como gatilho — é "cliente já vende"), submit :179.
- `routes/web.php:366-408` — grupo `permission:comercial.cadastrar_empresa`.

### Catálogo de serviços / setor de serviço (ENUM)
- `app/Models/Servico.php:52-65` — constantes `SETOR_PERFORMANCE/PUBLICACAO/POLOS/OUTROS` (adicionar `SETOR_SHOPEE`).
- `database/migrations/2026_07_03_113103_add_polos_to_servicos_setor_enum.php` — **padrão de migration `ALTER TABLE ... MODIFY COLUMN setor ENUM(...)`** a copiar.
- `database/migrations/2026_06_18_100002_seed_servicos_setor.php` — padrão de seed de serviços.

### Aba "Empresas" do Mercado Livre (o molde a espelhar)
- `app/Http/Controllers/CompanyController.php` — `index()` :76; filtro ML `whereDoesntHave('mlbEmpresa')` :108 + `whereHas contratosServico setor=performance` :113-118; pendências :189-199; `usersPorCargo()` :206-222; `update()` sync pivot :617-628; `bulkAssign()` :683-700; `userCanViewCompany()` :49-66.
- `resources/js/Pages/Companies/Index.jsx` — dicionário `PENDENCIAS` :100-106, `PendenciaBadges` :108-119, aba Pendências :361/:498, atribuição no modal :712-729, bulk assign :544-558/:268-271.

### Responsáveis (pivot company_users)
- `app/Models/Company.php` — `users()` :157, `consultor()` (="Analista", role `consultor`) :164-168, `estrategista()` (role `estrategista`) :175-179.
- `database/migrations/2026_04_26_152217_create_company_users_table.php` + enum role evoluído (add `analista`; rename `mentor`→`estrategista`).
- **Mapeamento crítico:** label "Analista" grava `role='consultor'` no pivot (`CompanyController.php:698`).

### NPS (não modificar o motor)
- `app/Http/Controllers/NpsController.php` — `generate()` :353-412 (autorização :370-375, resolve template :385-391, cria NpsSurvey :393-406).
- `routes/web.php:100-102` — `POST /nps/generate`.
- `resources/js/Pages/Nps/Index.jsx` — modal Gerar Link NPS :1109-1153, form :864/:909.
- `app/Console/Commands/NpsDispararMensal.php` — elegibilidade :145-203 (active + email/Digisac + estrategista; sem métrica).
- `app/Services/Nps/NpsTemplateService.php` — `resolveForCompany()` :70-112 (fallback `is_default`, nunca toca métricas).

### Permissões + navegação
- `app/Support/Permissions.php` — catálogo estático `catalog()` :123-180; auto-permissões de liderança :92-115.
- `resources/js/Layouts/AppLayout.jsx` — `NAV_TREE` :23; grupo "Mercado Livre" :48-99; **stub Shopee de topo** :108-115; `itemVisivel()` gating :296-303; `effectiveRoles` :280-289.
- `app/Http/Middleware/EnsurePermission.php` — enforcement do `permission:` nas rotas.
- Setores (RBAC — o usuário criará o Setor Shopee aqui): `app/Http/Controllers/Admin/SetorController.php` (`store()` :70-88, `syncPermissoes()` :121-151), `Setor.php`, rotas `routes/web.php:828-846` (`permission:sistema.setores`).

### Colunas relevantes de companies
- `companies.email_cliente`, `companies.digisac_*` (grupo WhatsApp), `companies.empresa_nova` — usadas nas pendências Shopee. Fillable/casts em `app/Models/Company.php:28-58`.
</canonical_refs>

<specifics>
## Specific Ideas
- Empresa multi-marketplace (contrato ML **e** Shopee) deve aparecer nas DUAS abas — comportamento **correto**, não é bug. A aba ML exclui `mlbEmpresa` mas não exclui contratos Shopee; a aba Shopee filtra por setor `shopee`.
- Já existem ~33 empresas com `companies.marketplace='shopee'` (coluna operacional legada), mas **essa coluna NÃO é o gatilho** desta feature (DEC-1 usa contrato de serviço). A coluna `marketplace` fica intocada.
- Manter comentários em pt-BR (convenção do projeto). Rodar `npm run build` após mudanças de frontend.
</specifics>

<deferred>
## Deferred Ideas
- Integração/API Shopee, sync de métricas, dashboard Shopee com dados reais — v-futura, quando houver Open Platform.
- Dashboard Shopee funcional (hoje permanece o shell/stub `Dashboard/ShopeeShell.jsx`).
</deferred>

---

*Phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho*
*Context gathered: 2026-07-14 — via exploração dirigida + decisões travadas (AskUserQuestion)*
