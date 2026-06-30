# Phase 49 — Research

**Data:** 2026-06-29
**Status:** Ready for planner

---

## §1 — /performance hoje (controller + page + filtro)

### Rota atual

`routes/web.php` linhas 242–247:
```php
Route::get('/performance', [PerformanceController::class, 'index'])
    ->middleware('permission:core.performance')
    ->name('performance.index');
Route::get('/performance/{user}', [PerformanceController::class, 'show'])
    ->middleware('permission:core.performance')
    ->name('performance.show');
```

### Filtro canônico de users (Phase 45 fix)

`app/Http/Controllers/PerformanceController.php` linhas 41–48:
```php
$users = User::where('active', true)
    ->whereExists(function ($q) {
        $q->from('user_setores as us')
          ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
          ->whereColumn('us.user_id', 'users.id')
          ->whereIn('c.slug', ['analista', 'estrategista']);
    })
    ->get(['id', 'name', 'role']);
```

Este é o filtro canônico pós-Phase 45. **NÃO** usa mais `whereIn('role', ['consultor','mentor'])` com `whereNull('publication_role')`.

### Parâmetros do request (linhas 24–37)

- `$setor = $request->get('setor', 'consultoria')` — despacha para `indexPolos()` se `'polos'`
- `$period = $request->get('period', '30')` — aceita `'7'|'30'|'90'|'180'`; **NÃO propagado** para `PortfolioScoreService` (janela hardcoded 30d no service)
- **Nenhum parâmetro `?cargo`** existe atualmente — Phase 49 deve adicionar

### Prop Inertia enviada

`Inertia::render('Performance/Index', ['ranking' => $ranking, 'period' => $period, 'setor' => 'consultoria'])` — linha 110–114.

Cada item de `$ranking` inclui `cargo_slug` e `cargo_label` (linhas 72–73) — **dado já existe no array do ranking**:
```php
$cargoSlug  = $cargosPorUser->get($u->id)?->slug ?? ($u->isMentor() ? 'estrategista' : 'analista');
// ...
'cargo_slug'  => $cargoSlug,
'cargo_label' => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
```

### Estrutura atual de `Performance/Index.jsx`

`resources/js/Pages/Performance/Index.jsx`

Assinatura do componente (linha 75):
```jsx
export default function PerformanceIndex({ ranking = [], period = '30', setor = 'consultoria', mes, meses = [] })
```

Estrutura da página:
- **Header** com bloco de filtros (linhas 86–131):
  - Seletor `setor` existente — botões `Consultoria` / `Publicações` (estilo toggle pill, não tabs)
  - Seletor `period`/`mes` (SelectBox)
- **Legenda** das categorias de score (linhas 134–140, só no setor consultoria)
- **Tabela** — condicional `isPolos ? <RankingPolos> : <RankingConsultoria>` (linha 148–151)

### Onde inserir o filtro de cargo (tab Geral/Analistas/Estrategistas)

O seletor `setor` já usa o pattern de toggle pill (botões inline, linhas 94–119). As 3 tabs de cargo devem seguir o **mesmo padrão visual**: grupo de botões inline `Geral | Analistas | Estrategistas`, visível somente no setor `consultoria` (não aparece no setor `polos`).

Localização ideal de inserção: **após o seletor de setor** e **antes do seletor de period** — mesma div `flex items-center gap-2` (linha 92).

URL pattern: `/performance?setor=consultoria&cargo=analista` — `router.get(route('performance.index'), params, { preserveState: true })`.

### Mapeamento `cargo_slug` no backend

O controller já tem `$cargosPorUser` (linhas 57–62) que mapeia `user_id → cargo_slug`. O filtro por cargo pode ser aplicado **depois** de montar o `$ranking` completo (filtragem no array PHP) **ou** na query de `$users`. A abordagem mais simples é filtrar `$ranking` pós-cálculo (todos os scores já calculados, depois filtrar por `cargo_slug`), evitando mudar `PortfolioScoreService`.

---

## §2 — PortfolioScoreService query base + onde plugar cargoFilter

### Query base de users

`PortfolioScoreService::compute()` **não filtra users** — recebe um `User $user` único (linha 56):
```php
public function compute(User $user): array
```

A listagem/filtragem de users é responsabilidade do **controller**. O service apenas computa o score de um user individual. A iteração `.map(fn($u) => $this->scoreService->compute($u))` está no `PerformanceController::index()` linhas 64–90.

### Onde inserir o filtro de cargo

**No `PerformanceController::index()`**, não no service. Duas opções:

**Opção A (recomendada) — filtrar pós-cálculo no array PHP:**
```php
$cargo = $request->get('cargo'); // null = Geral
// ... (cálculo existente de $ranking) ...
if ($cargo && in_array($cargo, ['analista', 'estrategista'])) {
    $ranking = $ranking->filter(fn($r) => $r['cargo_slug'] === $cargo)->values();
}
```
Prós: zero mudança no service, scores todos pré-calculados, trivial. Contras: calcula todos os scores mesmo quando filtra por um cargo (mas a base atual é pequena — sem impacto).

**Opção B — filtrar `$users` antes do cálculo:**
```php
if ($cargo && in_array($cargo, ['analista', 'estrategista'])) {
    $users = $users->filter(fn($u) => $cargosPorUser->get($u->id)?->slug === $cargo);
}
```
Prós: menor carga de cálculo. Contras: `$cargosPorUser` é construído depois de `$users`, requer reordenar o código (linhas 57–90) ou duplicar a query.

**Recomendação ao planner:** Opção A. Adicionar `$cargo = $request->get('cargo')` no início do método e filtrar `$ranking` pós-cálculo — mudança de 3 linhas, sem tocar no service.

### Assinatura do `PortfolioScoreService::compute()` — não precisa mudar

A Phase 49 **não precisa alterar** `PortfolioScoreService::compute()`. A filtragem por cargo é cosmética (filtrar quem aparece na lista), não afeta o cálculo do score.

---

## §3 — Sidebar dropdown Publicação (estrutura + entry nova)

### Localização no NAV_TREE

`resources/js/Layouts/AppLayout.jsx` — `NAV_TREE` declarado linha 22. O grupo de Publicação se chama **"Publicações"** (não "Publicação"):

```js
// ── Grupo: Publicações MLB ─────────────────────────────────────────────────
{
    group: 'Publicações',
    icon: BarChart2,
    children: [
        { label: 'Pub · Dashboard', routeName: 'mlb.dashboard',    ... permission: 'mlb.dashboard' },
        { label: 'Treinamentos',    routeName: 'mlb.treinamentos', ... permission: 'mlb.treinamento' },
        { label: 'Meu Painel',      routeName: 'mlb.meu-painel',   ... permission: 'mlb.meu_painel', excludeRoles: ['admin'] },
        { label: 'Publicação',      routeName: 'mlb.publicacoes',  ... permission: 'mlb.publicacoes' },
        { label: 'Vendas',          routeName: 'mlb.vendas',       ... permission: 'mlb.vendas' },
        { label: 'Histórico',       routeName: 'mlb.historico',    ... permission: 'mlb.historico' },
        { label: 'Revisão',         routeName: 'mlb.revisao',      ... permission: 'mlb.revisao' },
        { label: 'Empresas',        routeName: 'mlb.empresas',     ... permission: 'mlb.empresas' },
        { label: 'Metas',           routeName: 'mlb.metas.index',  ... permission: 'mlb.metas' },
    ],
},
```
`AppLayout.jsx` linhas 128–143.

### Como uma entry é declarada

Estrutura de um filho:
```js
{
    label: 'Label visível',
    routeName: 'nome.da.rota',       // usado em route()
    routeParams: { chave: 'valor' }, // opcional — query string ou path params
    page: 'Pasta/ComponentePage',    // prefixo da page path para isActive()
    icon: IconeDoLucide,
    permission: 'mlb.permissao',     // requer essa key no effectivePermissions()
    excludeRoles: ['admin', '...'],  // esconde se qualquer role efetiva bater
    showBadge: 'chave_do_badgeCounter', // opcional
}
```

Gating: `itemVisivel(item)` em `AppLayout.jsx` linhas 222–225:
```js
const itemVisivel = (item) => {
    if (item.excludeRoles?.some(r => effectiveRoles.has(r))) return false;
    return item.permission ? permissions.includes(item.permission) : true;
};
```

### Proposta de entry nova

```js
{ label: 'Desempenho', routeName: 'publicacao.desempenho.index', page: 'Publicacao/Desempenho', icon: Trophy, permission: 'mlb.dashboard' }
```

**Localização no grupo:** depois de `'Pub · Dashboard'` (primeiro filho) — faz sentido semântico como segunda entry do grupo.

**Importar Trophy** — já importado no AppLayout? Verificar linha 7: `Trophy` aparece na linha de imports. **Confirmado: já importado** (linha 7: `import { ... Trophy ... } from 'lucide-react';`).

---

## §4 — Sistema de permissões publicação

### Como `hasPermission()` funciona

`app/Models/User.php` linha 104–109:
```php
public function hasPermission(string $key): bool
{
    if ($this->isAdmin()) return true; // admin sempre vê tudo
    return \in_array($key, $this->effectivePermissions(), true);
}
```

`effectivePermissions()` (linhas 117–155): resolve via `SetorPermissao::whereIn('setor_id', ...)` — as permissões concedidas ao setor em que o user é membro.

### Permission keys de publicação existentes

`app/Support/Permissions.php` linhas 46–58 — grupo MLB:
```php
public const MLB_DASHBOARD    = 'mlb.dashboard';
public const MLB_PROJETOS     = 'mlb.projetos';
public const MLB_TREINAMENTO  = 'mlb.treinamento';
public const MLB_MEU_PAINEL   = 'mlb.meu_painel';
public const MLB_PUBLICACOES  = 'mlb.publicacoes';
public const MLB_VENDAS       = 'mlb.vendas';
public const MLB_HISTORICO    = 'mlb.historico';
public const MLB_REVISAO      = 'mlb.revisao';
public const MLB_EMPRESAS     = 'mlb.empresas';
public const MLB_IMPLEMENTACAO = 'mlb.implementacao';
public const MLB_METAS        = 'mlb.metas';
public const MLB_COLETA       = 'mlb.coleta';
```

**Não existe** `mlb.desempenho` ou `mlb.performance` nem `publicacao.desempenho`.

### Helpers de cargo de publicação no User.php

`app/Models/User.php` linhas 210–241:
- `hasPublicationRole(): bool` — true se setor slug = 'publicacao' (qualquer cargo)
- `isGestor(): bool` — cargo slug = 'gestor-de-publicacao'
- `isLiderPub(): bool` — cargo slug = 'lider-de-publicacao'
- `isPublicador(): bool` — cargo slug = 'publicador'
- `hasPubPermission(string $perm): bool` — proxy para `hasPermission("mlb.{$perm}")`

### Recomendação de permission para a rota nova

**Reusar `mlb.dashboard`** para a rota `/publicacao/desempenho`.

Justificativa:
1. Quem tem `mlb.dashboard` já é do setor Publicações (gestor/lider/publicador/analista-pub) — mesma audiência correta para ver o ranking de publicação.
2. Não precisa criar nova permission key + atualizar `Permissions::catalog()` + propagar para os setores existentes no banco.
3. O `'Pub · Dashboard'` no menu já usa `permission: 'mlb.dashboard'` — a entry nova seguiria o mesmo gating.
4. Admin sempre vê tudo (short-circuit `isAdmin()`), sem configuração extra.

Se o operador quiser granularidade fina (ex: "só gestor e líder veem o ranking, não publicador"), criar `MLB_DESEMPENHO = 'mlb.desempenho'` e configurar no admin de setores — mas isso é YAGNI para Phase 49.

**Recomendação final:** `permission: 'mlb.dashboard'` no sidebar e `->middleware('permission:mlb.dashboard')` na rota.

---

## §5 — Métricas de scoring para publicação (existência + recomendação)

### O que já existe no codebase

**`PerformanceController::indexPolos()`** (linhas 117–210) já implementa um ranking de publicação completo:
- Users filtrados por `publication_role IN ('publicador', 'lider')` (linha 125) — **ATENÇÃO: usa coluna `publication_role` que NÃO é legada** (coluna `publication_role` sem sufixo `_legacy` é a nova, `publication_role_legacy` é a legacy). Confirmar.
- Métricas calculadas: `feito` (count de publicações no mês), `vendas` (count de publicações com `vendido=true`), `meta` (de `mlb_meta_historico` ou `publication_meta`), `projecao`, `status`
- Score final `score_final` (linhas 177–197): fórmula ponderada `pctMeta * 0.4 + vendasNorm * 0.4 + conversaoNorm * 0.2`
- Período: mensal (`$mesRef = Y-m`)

**Tabelas usadas:**
- `publicacoes` (via Model `Publicacao`) — count + filter `vendido=true`
- `mlb_meta_historico` — meta por user_id + mes
- `users.publication_meta` — fallback da meta (default 220)

**Resultado:** o ranking de publicação **já existe funcionando** dentro do `PerformanceController` como `indexPolos()`. O método renderiza `Inertia::render('Performance/Index', ...)` com `setor=polos` — ou seja, **a mesma página** `Performance/Index.jsx` com `isPolos=true`.

A UI em `Performance/Index.jsx` já tem o botão "Publicações" que navega para `?setor=polos` (linha 107–118) e exibe `<RankingPolos>` quando `isPolos=true` (linha 148).

### Decisão: caminho A vs B

**Caminho A — reusar `PerformanceController::indexPolos()` em nova rota:**
- Criar rota `GET /publicacao/desempenho` que chama `PerformanceController::indexPolos()` (ou um alias dele)
- Renderiza `Performance/Index` com `setor='polos'` — exatamente o que acontece quando admin clica "Publicações" na página `/performance`
- Não cria nenhum novo service

**Caminho B — controller dedicado `PublicacaoDesempenhoController`:**
- Extrai `indexPolos()` para `app/Http/Controllers/PublicacaoDesempenhoController.php`
- Mesma lógica, mesmo output Inertia
- Permite futura divergência sem tocar em `PerformanceController`

**Recomendação ao planner: Caminho A com método público extraído.**
- Mover `indexPolos()` para um método público no `PerformanceController` ou criar `PublicacaoDesempenhoController` simples que chama a mesma lógica
- Ou, mais simples ainda: uma nova rota `publicacao.desempenho.index` pode apontar para `PerformanceController@indexPolos` diretamente via alias, com middleware `permission:mlb.dashboard`
- A page `Performance/Index` já suporta `setor='polos'` — zero mudança de frontend necessária para o ranking

### Alerta sobre `publication_role` (linha 125 de `indexPolos`)

`PerformanceController::indexPolos()` linha 125:
```php
->whereIn('publication_role', ['publicador', 'lider'])
```

**Verificar se `publication_role` (sem sufixo `_legacy`) é a coluna nova ou legada.** A memória `project_legacy_columns_rename` diz que Phase 7 renomeou `publication_role` → `publication_role_legacy`. Mas no código atual (linha 36 do User.php fillable) `publication_role_legacy` está no fillable legado. A linha 125 de `indexPolos` usa `publication_role` — pode ser bug ou pode ser que essa coluna ainda exista. **Planner deve confirmar** antes de usar esse filtro. Se for legada, substituir por `whereExists(user_setores → cargos.slug IN ('publicador', 'lider-de-publicacao', 'gestor-de-publicacao'))`.

---

## §6 — Pitfalls

1. **`publication_role` vs `publication_role_legacy`** — `PerformanceController::indexPolos()` linha 125 usa `->whereIn('publication_role', ['publicador', 'lider'])`. Memória `project_legacy_columns_rename` indica que Phase 7 renomeou `publication_role` → `publication_role_legacy`. Se a coluna `publication_role` não existe mais (foi renomeada), essa query falha com erro de schema. **Verificar via `php artisan tinker` ou schema antes de deployar.** Fix: substituir por filtro canônico via `user_setores → cargos`.

2. **MariaDB local corrompido** — testes via SQLite in-memory. Não executar `php artisan migrate` localmente sem checar `tasklist | grep mysqld` (memória `project_mariadb_local_corrompido`).

3. **Filtro canônico via `user_setores → cargos.slug` (NÃO via `users.role` ou `users.publication_role`)** — Phase 45 fix no setor performance. O mesmo princípio deve ser aplicado ao filtro de users de publicação.

4. **Tabs de cargo vs seletor de setor no frontend** — `Performance/Index.jsx` já tem seletor `Consultoria|Publicações`. As 3 tabs de cargo (`Geral|Analistas|Estrategistas`) só fazem sentido no setor `consultoria`. Proteger com `!isPolos &&` antes de renderizá-las.

5. **`cargo_slug` já no ranking** — `PerformanceController::index()` já popula `cargo_slug` e `cargo_label` em cada item do ranking (linhas 72–73). A filtragem por cargo pode ser feita no array PHP pós-cálculo sem mudar o service.

6. **Inertia navigation preserveState** — ao navegar entre tabs de cargo, usar `router.get(..., { preserveState: true })` para não remontar o componente. Idem para o seletor `setor` existente (já implementado assim na linha 77).

7. **`performance.index` vs `publicacao.desempenho.index` — rotas distintas** — a nova rota de publicação deve ter nome próprio (para o sidebar funcionar com `isActive()`). A entry do sidebar usa `page: 'Publicacao/Desempenho'` — mas se a nova rota renderizar `Performance/Index`, o `isActive` vai verificar se `pageComponent.startsWith('Publicacao/Desempenho')`, o que falhará. Duas opções: (a) criar nova page `Publicacao/Desempenho/Index.jsx` que reusa componentes do `Performance/Index`; (b) manter `Performance/Index` e ajustar o `page` da entry do sidebar para `'Performance'`. **Planner deve decidir**.

8. **Ausência de `MLB_DESEMPENHO` no catálogo `Permissions`** — se usar `mlb.dashboard` como permission, nenhuma mudança de PHP é necessária. Se criar key nova, precisa: (a) adicionar constante em `Permissions.php`, (b) adicionar ao `catalog()`, (c) configurar nos setores de publicação via admin de setores no banco.

---

## §7 — Recomendações pro planner

1. **Wave 1 — Backend: adicionar parâmetro `?cargo` ao `PerformanceController::index()`**
   - Ler `$cargo = $request->get('cargo')` (linha 24 do método)
   - Filtrar `$ranking` pós-cálculo: `if ($cargo) $ranking = $ranking->filter(fn($r) => $r['cargo_slug'] === $cargo)->values()`
   - Propagar `'cargo' => $cargo` no Inertia (junto com `ranking`, `period`, `setor`)
   - Testes PHPUnit: Feature test com SQLite in-memory verificando 3 responses (sem filtro, cargo=analista, cargo=estrategista)

2. **Wave 1 — Frontend: 3 tabs de cargo no `Performance/Index.jsx`**
   - Adicionar prop `cargo` ao componente (padrão `null` = Geral)
   - Grupo de botões inline `Geral | Analistas | Estrategistas` visível apenas quando `!isPolos`
   - `applyFilter({ setor: 'consultoria', period, cargo: 'analista' })` etc.
   - Botão "Geral" não passa `cargo` (ou passa `cargo: undefined`)

3. **Wave 2 — Rota de publicação separada**
   - Verificar se `publication_role` (sem `_legacy`) existe no schema (`SHOW COLUMNS FROM users LIKE 'publication_role'`)
   - Se existir: `PerformanceController::indexPolos()` funciona como está
   - Se não existir: corrigir filtro de users para `user_setores → cargos.slug IN ('publicador', 'lider-de-publicacao', 'gestor-de-publicacao')`
   - Adicionar rota em `routes/web.php`: `Route::get('/publicacao/desempenho', [PerformanceController::class, 'indexPublicacao'])->middleware('permission:mlb.dashboard')->name('publicacao.desempenho.index')`
   - Extrair/renomear `indexPolos()` → `public function indexPublicacao(Request $request)` (o método pode ser público)
   - Inertia render: decidir se usa `'Performance/Index'` (reuso) ou `'Publicacao/Desempenho/Index'` (nova page)

4. **Wave 2 — Sidebar: entry nova no grupo "Publicações"**
   - `AppLayout.jsx` linha 133: adicionar entry logo após `Pub · Dashboard`:
     ```js
     { label: 'Desempenho', routeName: 'publicacao.desempenho.index', page: 'Performance', icon: Trophy, permission: 'mlb.dashboard' }
     ```
   - Se a nova rota renderizar page diferente de `'Performance/Index'`, ajustar `page:` adequadamente
   - `Trophy` já importado na linha 7 do AppLayout

5. **Decisão de page React para `/publicacao/desempenho`**
   - **Recomendação lean:** Reusar `Performance/Index.jsx` com `setor='polos'` — zero novo componente
   - A sidebar entry usa `page: 'Performance'` — vai acender quando o pageComponent for `'Performance/Index'` (já acontece quando admin clica "Publicações" na `/performance`)
   - Se quiser page isolada: criar `resources/js/Pages/Publicacao/Desempenho/Index.jsx` importando `RankingPolos` do `Performance/Index`

6. **Ordem recomendada de waves:**
   - Wave 1 (independente, baixo risco): tabs de cargo no `/performance` — backend + frontend
   - Wave 2 (depende apenas de verificar schema): rota publicação + sidebar
   - Wave 3: testes PHPUnit (Feature test cobrindo filtro por cargo + permission test para `/publicacao/desempenho`)

7. **Confirmações que o executor deve fazer antes de implementar Wave 2:**
   - `SHOW COLUMNS FROM users LIKE 'publication_role'` no VPS — determina se filtro `indexPolos` está correto ou precisa migrar para `user_setores → cargos`
   - Se coluna não existe: adicionar migration OU mudar a query (provavelmente a query precisa mudar — Phase 7 renomeou a coluna)

---

*Phase: 49-rankings-de-desempenho-por-fun-o-ranking-separado-de-publica*
*Research gerado: 2026-06-29 — pesquisa cirúrgica do codebase*
