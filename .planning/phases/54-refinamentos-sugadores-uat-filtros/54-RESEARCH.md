# Phase 54: Refinamentos /sugadores UAT + filtros — Research

**Researched:** 2026-07-02
**Domain:** UI Inertia/React + backend Laravel de filtros na trilha `/sugadores`
**Confidence:** HIGH (todos os pontos confirmados no código)

## Summary

Ajustes cirúrgicos em 3 páginas React + 2 métodos do controller. Tudo mapeado no
código — nenhuma criação de componente necessária. O padrão de "layout 2 colunas"
já é usado na própria `EmpresaListagem.jsx` (header + card) e em `Show.jsx`.
Filtro por analista já tem 90% da lógica pronta no `SugadorController::index`
(bloco `user_id`); só precisa ser replicado no `porEmpresa` e exposto no header
do Index como `<select>`. Filtro de período é greenfield (nada existe hoje em
`porEmpresa`). Click-row + `stopPropagation()` tem precedente canônico em
`Pages/Performance/Index.jsx:324`.

**Primary recommendation:** reusar padrões já existentes no projeto (inputs
nativos com classes ECF, `router.get()` com `preserveState/preserveScroll`,
loop pivot `company_users`). **NÃO usar** `Components/ui/input.jsx` nem
`Components/ui/select.jsx` shadcn — as classes deles (`bg-background border-input`)
quebram o dark theme; usar `<input>` / `<select>` nativos com Tailwind ECF.

---

## 1. A1 — Layout 2 colunas em `EmpresaListagem.jsx`

### Estado atual (bloco a mudar)

Header já usa grid 4 colunas — mas o layout é `nome_empresa (3/4) + ConfigResumoCard (1/4)`
NO TOPO, seguido de **tabela abaixo em largura total**:

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx:346-378` — grid 4 col do header (nome + card)
- `resources/js/Pages/Sugadores/EmpresaListagem.jsx:381-421` — barra bulk sticky (largura total)
- `resources/js/Pages/Sugadores/EmpresaListagem.jsx:423-535` — bloco tabela (largura total, fora do grid)

O card `ConfigResumoCard` (com botão "Rodar análise" + cronômetro) está
definido em `EmpresaListagem.jsx:78-171`.

### Estrutura sugerida

Envolver **breadcrumb + header + barra bulk + tabela** (linhas 336-535) num
grid 2 colunas, movendo o `ConfigResumoCard` do header para a coluna direita
sticky:

```jsx
<div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-6">
    <div className="min-w-0"> {/* coluna esquerda: header simplificado + bulk + tabela */}
        {/* breadcrumb + nome empresa + tabela */}
    </div>
    <aside className="lg:sticky lg:top-4 lg:self-start space-y-4"> {/* coluna direita */}
        <ConfigResumoCard ... />
    </aside>
</div>
```

`min-w-0` na coluna esquerda é obrigatório — sem isso, a tabela empurra o grid
e a sidebar some no mobile-first (padrão CSS grid + `truncate` nas linhas).

### Modelo no projeto

Não existe **exatamente** `lg:grid-cols-[minmax(0,1fr)_320px]` no repo (grep
retornou 0 matches), mas o **próprio `EmpresaListagem.jsx:347`** já usa
`grid-cols-1 lg:grid-cols-4` no header (`lg:col-span-3` + `lg:col-span-1`),
que é equivalente semântico. **Padrão canônico do projeto:** manter
`grid-cols-4` com `col-span-3 / col-span-1` — mais consistente com o resto do
código. Sidebar 320px vs `1/4` do container: em telas ≥1024px `1/4` já dá
~256-320px conforme viewport. Recomendação: **`lg:grid-cols-4` com sticky
sidebar** — mantém consistência com Show.jsx e header atual.

**Precedente sticky sidebar:** não achado no repo (`grep sticky top-4` retorna
só o próprio bulk bar do `EmpresaListagem.jsx:382` "sticky top-2"). Padrão
válido, criar seguindo Tailwind puro.

---

## 2. A2 — Remoção de `ConfigResumoCard` + "Rodar análise" em `Show.jsx`

### Blocos a remover

Ambos vivem **dentro do mesmo componente wrapper**, colados um no outro. Não
são adjacentes plain — o Show.jsx tem 2 branches (adgroup vs campanha) e o
card aparece em cada branch:

- `resources/js/Pages/Sugadores/Show.jsx:500-532` — bloco condicional
  `sugador.tipo === 'adgroup' ? (grid com ConfigResumoCard lateral + MlbsDoAdgroup)
   : (ConfigResumoCard sozinho em coluna full)`
- `resources/js/Pages/Sugadores/Show.jsx:145-188` — hooks `useState(analyzing)`,
  `useEffect` do cronômetro, função `rodarAnalise()`
- `resources/js/Pages/Sugadores/Show.jsx:671-760` — definição do componente
  local `ConfigResumoCard` (duplicado com o de `EmpresaListagem.jsx` — remover)
- `resources/js/Pages/Sugadores/Show.jsx:137-141` — prop `sugador_config`
- `resources/js/Pages/Sugadores/Show.jsx:141` — prop `can_manage_config`

### Após remoção

Para o branch `tipo === 'adgroup'`, o `MlbsDoAdgroup` (linhas 513-519) deixa
de ser `lg:col-span-3` — pode ir para largura total (remover o grid wrapper
inteiro linhas 501-520 e deixar `MlbsDoAdgroup` direto). Para
`tipo === 'campanha'`, remover o `<div className="mb-4">` (linhas 522-531)
inteiro — nada substitui.

### Estimativa

~85 linhas removidas total (bloco render 33 + hooks/handler 44 + componente 90).
O componente `ConfigResumoCard` inteiro (671-760) sai — não é mais usado em Show.jsx.

### Backend (opcional cleanup)

`SugadorController::show` linhas 380-399 continuam enviando `sugador_config`
+ `can_manage_config`. Deixar como está — não quebra nada (props não consumidas
são ignoradas pelo React). Cleanup pode virar seed futuro.

---

## 3. A3 — Busca + filtro analista em `Index.jsx`

### Botão "Configurar" a substituir

- `resources/js/Pages/Sugadores/Index.jsx:436-445` — `<button onClick={() => setConfigPickerOpen(true)}>`
  dentro do `{can_manage && ...}` no header (área `flex items-center gap-2`).

O botão fica dentro de um `<div className="flex items-center gap-2">`
(linhas 432-450) que atualmente só contém esse botão. Substituir por
input de busca + select de analista (admin only).

### Componentes shadcn disponíveis

Todos existem, mas com ressalva:

- `resources/js/Components/ui/input.jsx` (18 linhas) — usa
  `border-input bg-background text-sm` (design system shadcn cru,
  **NÃO combina com dark ECF**)
- `resources/js/Components/ui/select.jsx` — Radix wrapper com trigger em
  `h-10 border-input bg-background` (mesmo problema)

### Recomendação: `<input>` / `<select>` nativos com classes ECF

O próprio `Sugadores/Index.jsx:267-275` (dentro do `ConfigPickerModal`) já
implementa input de busca com o pattern correto do projeto:

```jsx
<input
    type="text"
    value={q}
    onChange={e => setQ(e.target.value)}
    placeholder="Buscar empresa..."
    className="w-full h-9 pl-8 pr-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40"
/>
```

Para o `<select>` de analista, usar o pattern de `Portfolio/Carteiras.jsx:46-54`
(também `<select>` nativo):

```jsx
<select
    value={analistaId ?? ''}
    onChange={e => applyFiltro('analista_id', e.target.value)}
    className="appearance-none h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
>
    <option value="">Todos analistas</option>
    {analistas.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
</select>
```

### Backend: prop `analistas` + filtro no `companies_summary`

**A infraestrutura já existe.** O `SugadorController::index` linhas 92-101
JÁ tem exatamente o pattern de filtro por `user_id`:

```php
if ($user->isAdmin() && $request->filled('user_id')) {
    $userId = (int) $request->user_id;
    $query->whereIn('company_id', function ($sub) use ($userId) {
        $sub->select('company_id')
            ->from('company_users')
            ->where('user_id', $userId);
    });
}
```

Só que ele filtra `$sugadores` (pagination global). Para Phase 54 precisa filtrar
`companies_summary` (linhas 155-228) — a variável `$visibleIds` (linha 155) é
o que alimenta o `companies_summary`. Aplicar o filtro em `$visibleIds` (ou em
`$companies` linhas 118-122) resolve com 1 whereIn.

**Prop `analistas`:** o controller JÁ envia `users` (linhas 126-130) — lista
de users que aparecem em `company_users`. Basta filtrar `pivot.role='analista'`:

```php
$analistas = $user->isAdmin()
    ? \App\Models\User::whereIn('id', function ($sub) {
        $sub->select('user_id')->from('company_users')->where('role', 'analista');
    })->orderBy('name')->get(['id', 'name'])
    : collect();
```

Ou renomear `users` → `analistas` na prop (o call-site frontend `users` foi
removido junto com a tabela lista global — nada consome mais). Verificar
`Index.jsx:315` que documenta essa remoção.

### Helper `isAdmin` no frontend

- Backend: `app/Models/User.php:60` — `public function isAdmin(): bool { return $this->role === 'admin'; }`
- Frontend: consumir via `auth.user.role === 'admin'` ou expor `auth.user.is_admin`
  no `HandleInertiaRequests` (verificar shape existente). Prop atual `can_manage`
  (linha 311) NÃO substitui — analista também tem `manage=true` (SugadorPolicy).
  Precisa checar `role === 'admin'` explicitamente para esconder o dropdown de
  analista dos próprios analistas.

Como o `Index.jsx` não importa `usePage`, o mais simples é: backend envia nova
prop `is_admin` explícita ao lado de `can_manage`:

```php
'is_admin' => $user->isAdmin(),
```

E frontend renderiza `{is_admin && <select>...}`.

### Busca client-side

`companies_summary` já vem completo no `Index.jsx:318`. Filtro por nome é
`useMemo` client-side sobre `card.name.toLowerCase().includes(q.toLowerCase())`
— mesmo pattern do `ConfigPickerModal` linhas 245-247. Não precisa hit no backend.

---

## 4. B1 — Filtro de período em `EmpresaListagem.jsx`

### Estado atual no backend

`SugadorController::porEmpresa` (linhas 277-359) **NÃO tem filtro de data**
hoje. Traz TODOS os sugadores da empresa com `status IN (pendente, em_acao)`:

- `app/Http/Controllers/SugadorController.php:298-301`:
  ```php
  Sugador::where('company_id', $company->id)
      ->whereIn('status', [Sugador::STATUS_PENDENTE, Sugador::STATUS_EM_ACAO])
      ->orderBy('reference_date', 'desc')
      ->orderBy('id', 'desc')
  ```

Adicionar filtro por `reference_date` conforme `?periodo`:

```php
$periodo = $request->input('periodo', 'hoje');
$query = Sugador::where('company_id', $company->id)
    ->whereIn('status', [Sugador::STATUS_PENDENTE, Sugador::STATUS_EM_ACAO]);

match ($periodo) {
    '7d'    => $query->where('reference_date', '>=', now()->subDays(7)->toDateString()),
    '30d'   => $query->where('reference_date', '>=', now()->subDays(30)->toDateString()),
    'todos' => null, // sem filtro
    default => $query->whereDate('reference_date', today()), // 'hoje'
};
```

### Padrão canônico no projeto para filtro persistido em query string

`resources/js/Pages/Portfolio/Carteiras.jsx:25-30`:

```jsx
const applyPeriod = (value) => {
    router.get(route('portfolio.own'), { period: value }, {
        preserveState: true,
        preserveScroll: true,
    });
};
```

E o `<select>` linhas 46-54 (já colado no ponto 3). **Recomendação: replicar
este pattern exato em `EmpresaListagem.jsx`** — chamada
`router.get(route('sugadores.empresa.listagem', company.id), { periodo }, { preserveState: true, preserveScroll: true })`.

### Padrão `whereDate('reference_date', today())`

Já usado em produção no mesmo controller:

- `app/Http/Controllers/SugadorController.php:109` — `$query->whereDate('reference_date', today());`
  (bloco "vista hoje" default do Index)

Portanto zero risco de comportamento inesperado — mesmo helper Carbon, mesmo cast.

---

## 5. B2 — Click row em `EmpresaListagem.jsx`

### Row atual

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx:462-465` — `<tr>` com classes
  `border-b border-white/[0.03] last:border-0 hover:bg-white/[0.02]`, sem
  `onClick` nem `cursor-pointer`.

### Precedente `stopPropagation()` no projeto

**Precedente canônico exato do padrão (click linha → visit, botões param
propagação):** `resources/js/Pages/Performance/Index.jsx:324`:

```jsx
<button
    type="button"
    onClick={(e) => { e.stopPropagation(); router.visit(route('portfolio.show', u.id)); }}
    ...
>
```

Outros usos de `stopPropagation()` no projeto (26 arquivos) são majoritariamente
em modais (fechar-ao-clicar-fora) — o de `Performance/Index.jsx:324` é o
único caso 1:1 com o padrão pedido para B2 (row-click + botões-que-navegam).

Outro precedente relevante:
`resources/js/Pages/Dev/SugadoresMlOnboarding/Index.jsx:388` — `<td onClick={(e) => e.stopPropagation()}>`
que envolve controles interativos dentro de uma linha clicável. Mesma tabela
dark ECF — copiar o pattern estrutural.

### Implementação recomendada

```jsx
<tr
    key={s.id}
    onClick={() => router.visit(route('sugadores.show', s.id))}
    className={cn(
        'border-b border-white/[0.03] last:border-0 hover:bg-white/[0.05] cursor-pointer',
        isSelected && 'bg-ecf-yellow/[0.03]',
    )}
>
    <td onClick={(e) => e.stopPropagation()} className="p-3">
        <input type="checkbox" ... />
    </td>
    {/* ...outras <td> não interativas: propagam normalmente */}
    <td onClick={(e) => e.stopPropagation()} className="p-3 text-right">
        {/* botão "MLBs" + Link "Detalhes" (se mantidos) */}
    </td>
</tr>
```

`hover:bg-white/[0.02]` atual (linha 463) → subir para `hover:bg-white/[0.05]`
para dar affordance visual de row clicável.

### Botão "Ver detalhes" atual

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx:519-526` — `<Link href={route('sugadores.show', s.id)}>Detalhes</Link>`

Após o click-row, ele fica **redundante**. Recomendação (decisão de discretion
do CONTEXT.md linha 65): **remover** para reduzir ruído — o click-row cobre
100% do fluxo. Se mantiver, envolver a `<td>` em `onClick={e => e.stopPropagation()}`
para o click no ícone `<ExternalLink>` não disparar dupla navegação.

---

## 6. Backend: relação analistas ↔ empresa

### Model `Company.php`

- `app/Models/Company.php:151-156` — `users()`: `belongsToMany(User::class, 'company_users')->withPivot('role', 'assigned_at')`
- `app/Models/Company.php:158-162` — `consultor()`: mesma pivot com `wherePivot('role', 'consultor')`
- `app/Models/Company.php:169-173` — `estrategista()`: `wherePivot('role', 'estrategista')`

**NÃO existe** relação `analistas()` explícita no Company — precisa criar (opcional)
ou usar `users()->wherePivot('role', 'analista')` inline.

### Tabela pivot `company_users`

- Migration base: `database/migrations/2026_04_26_152217_create_company_users_table.php`
- Enum atual: `database/migrations/2026_05_07_000005_add_analista_to_company_users_role_enum.php:22-24`
  ```sql
  ALTER TABLE company_users
  MODIFY COLUMN role ENUM('consultor', 'mentor', 'analista') NOT NULL
  ```
  (Nota: `mentor` foi renomeado para `estrategista` em `2026_05_22_200001_rename_mentor_to_estrategista_in_company_users.php`
  — enum atual = `('consultor', 'estrategista', 'analista')`)
- Colunas: `id`, `company_id`, `user_id`, `role` (enum), `assigned_at`, `timestamps`

### Model `User.php`

- `app/Models/User.php:60` — `isAdmin(): bool`
- `app/Models/User.php:61` — `isConsultor(): bool` (role principal, não pivot)
- `app/Models/User.php:159-164` — `companies()`: `belongsToMany(Company::class, 'company_users')`
- `app/Models/User.php:166-171` — `consultorCompanies()`: pivot role=`consultor`
- `app/Models/User.php:177-182` — `estrategistaCompanies()`: pivot role=`estrategista`

**NÃO existe** `analistaCompanies()` nem `isAnalista()`. Padrão do projeto é
**checar via pivot** (`company_users.role='analista'`), NÃO via
`User.role` (que só tem `admin/consultor/mentor`).

### Filtro "empresas do analista X"

**Pronto no código, exato pattern reusável:**

- `app/Http/Controllers/SugadorController.php:94-101` — subquery em
  `company_users` filtrada por `user_id`. Copiar para o Phase 54, adicionando
  `->where('role', 'analista')` se quiser restringir ao vínculo específico
  (senão qualquer pivot com aquele user passa).

Exemplo pronto para o novo filtro em `porEmpresa` OU no filter de `companies_summary`
do `index`:

```php
if ($request->filled('analista_id')) {
    $analistaId = (int) $request->analista_id;
    $visibleIds = collect($visibleIds)->intersect(
        DB::table('company_users')
            ->where('user_id', $analistaId)
            ->where('role', 'analista')
            ->pluck('company_id')
    )->values()->all();
}
```

Aplicar **antes** do `$aggregates` query (linha 161) — reduz o universo antes
do SUM/CASE. Zero N+1.

---

## Common Pitfalls

### 1. `Components/ui/input.jsx` e `Components/ui/select.jsx` NÃO usar

Ambos têm classes `border-input bg-background` (design system shadcn com
CSS variables) que ficam **invisíveis no dark ECF** (`bg-ecf-bg = #050507`).
Nenhuma outra página do projeto usa esses componentes hoje (grep confirmou:
0 imports de `@/Components/ui/input` ou `@/Components/ui/select` em
`Pages/Sugadores`). **Usar `<input>` / `<select>` nativos** com classes ECF
como fez `Portfolio/Carteiras.jsx:46-54` e `Sugadores/Index.jsx:267-275`.

### 2. `can_manage` NÃO é `is_admin`

`SugadorPolicy` dá `manage=true` para analistas também (Phase 52 Wave 1).
Filtrar dropdown "por analista" com `can_manage` mostraria para analistas
também. Enviar prop `is_admin` explícita.

### 3. Filtro periodo default = `hoje` → esconde histórico

`porEmpresa` hoje traz tudo (sem WHERE de data). Passar a filtrar `hoje` por
default é breaking — sugadores em_acao antigos somem. Aceitável conforme
CONTEXT.md decision B1 (default `hoje` é locked). Documentar no PLAN.md para
alertar UAT.

### 4. Click row + tabelas com `<button>` dentro de `<td>`

`onClick` na `<tr>` propaga por padrão — TODOS os controles interativos das
`<td>` precisam `stopPropagation` explícito. A row atual tem 2 controles:
checkbox `<td>` (linha 466) e botões `<td>` (linha 500). **Ambos precisam
do handler**, não só um.

### 5. `porEmpresa` não checa `analista_id` — só `index` checa

Se a UI persistir `?analista_id=X` na URL no `EmpresaListagem`, o backend
`porEmpresa` ignora — não filtra empresas visíveis. Como `porEmpresa` opera
sobre 1 empresa específica (via route model binding), o filtro `analista_id`
não faz sentido lá — só no `index`. Confirmar no PLAN se query params sobrevivem
ao navegar entre páginas via `router.visit`.

---

## Sources

### Primary (HIGH confidence — verificado via Read/Grep neste projeto)

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (538 linhas, lida integral)
- `resources/js/Pages/Sugadores/Index.jsx` (516 linhas, lida integral)
- `resources/js/Pages/Sugadores/Show.jsx` (1038 linhas, lida integral)
- `app/Http/Controllers/SugadorController.php` (métodos `index`, `porEmpresa`, `show`)
- `app/Models/Company.php:140-190` (relacionamentos)
- `app/Models/User.php:55-190` (helpers + companies pivot)
- `database/migrations/2026_05_07_000005_add_analista_to_company_users_role_enum.php`
- `resources/js/Components/ui/input.jsx` + `select.jsx` (avaliação de aptidão)
- `resources/js/Pages/Portfolio/Carteiras.jsx:1-55` (padrão canônico router.get + select)
- `resources/js/Pages/Performance/Index.jsx:320-332` (padrão stopPropagation + visit)

---

## Metadata

**Confidence breakdown:**
- Layout 2 colunas (A1): HIGH — padrão grid-cols-4 já em uso no mesmo arquivo
- Remoção Show (A2): HIGH — linhas exatas identificadas
- Busca + filtro analista (A3): HIGH — 90% do backend pronto (linhas 94-101)
- Filtro período (B1): HIGH — backend greenfield mas simples; frontend replicável
  de `Portfolio/Carteiras.jsx`
- Click row (B2): HIGH — pattern 1:1 em `Performance/Index.jsx:324`
- Backend analistas: HIGH — pivot enum confirmado via migration

**Research date:** 2026-07-02
**Valid until:** 30 dias (código estável, sem migrations pendentes no domínio)
