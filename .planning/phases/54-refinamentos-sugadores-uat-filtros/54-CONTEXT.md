# Phase 54: Refinamentos /sugadores UAT (correções + filtros) — Context

**Gathered:** 2026-07-02
**Status:** Ready for research
**Source:** Síntese lean — briefing UAT do operador 2026-07-02 (blocos A + B do TODO `270702-refinamentos-sugadores-uat-e-magic-ui.md`)

<domain>
## Phase Boundary

Phase 52 entregou os 9 itens A1-A9 do briefing original. Durante o UAT o operador identificou **5 refinamentos** que ajustam decisões que ficaram fora do ponto ideal + adicionam filtros que fazem falta operacionalmente.

**Não** é redesign — só ajustes cirúrgicos de layout, remoção de widgets em página errada, busca/filtros funcionais e click da linha. A modernização visual + Magic UI fica para **Phase 55** (depende desta).

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

- **A1** Layout `ConfigResumoCard` em `/sugadores/empresa/{id}` — hoje está **em cima da lista**; mudar para **ao lado** (layout 2 colunas: main list ~2/3 + sidebar ~1/3). Sidebar acomoda também o botão "Rodar análise" e o cronômetro. Mobile responsivo: cai para 1 coluna com sidebar em cima.

- **A2** View individual `/sugadores/{id}` (Show.jsx) — **remover** `ConfigResumoCard` + botão "Rodar análise". Wave 3 da Phase 52 adicionou os 2 mas a view individual mostra 1 sugador só (contexto errado para config/análise). Deixar Show.jsx focado em detalhes do sugador único (adgroup, MLBs, status, ações do próprio sugador).

- **A3** Cards de empresas em `/sugadores` (Index.jsx) — substituir botão "Configurar" no header por **filtros**:
  - **Busca de empresa** — input livre, filtra `companies_summary` client-side por `name` (case-insensitive)
  - **Filtro por analista** (SÓ admin) — dropdown listando analistas (users com cargo `analista`); mostra apenas empresas atribuídas ao selecionado. Backend adiciona relação carteira → user
  - Botão "Configurar" continua acessível via drilldown (ConfigResumoCard em EmpresaListagem já leva pra `sugadores.config.show`)

- **B1** Filtro de **período** em `/sugadores/empresa/{id}` — coluna "Detectado em" filtrada. Presets:
  - **Hoje** (default) ← locked
  - Últimos 7 dias
  - Últimos 30 dias
  - Todos
  Backend `porEmpresa` recebe query param `?periodo=hoje|7d|30d|todos` (default hoje) e filtra sugadores via `whereDate('detected_at', ...)` ou range. Persistir seleção via query string (Inertia keep filters em refresh).

- **B2** **Click na linha** do sugador → detalhes:
  - Tabela em `EmpresaListagem.jsx` — inteira `<tr>` clicável (cursor pointer, hover bg mais escuro)
  - Handler: `router.visit(route('sugadores.show', sugador.id))`
  - Checkbox e botões inline **não propagam** (`e.stopPropagation()`)
  - Botão "Ver detalhes" pode ficar (redundância aceitável) OU remover (linha inteira já cobre)

### FORA da Phase 54 (locked)

- **Redesign visual** estilo shadcn/ui + Magic UI → **Phase 55**
- Detector de sugadores (3 casos falso-positivo) → **Phase 53**
- Modificações na Show.jsx individual além de remover ConfigResumoCard + botão análise (A2)
- Filtro por status ou por analista dentro da drilldown (Show/EmpresaListagem)

### Abordagem técnica

- **A1 (layout 2 colunas)**: em `EmpresaListagem.jsx`, wrap main em `<div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_320px] gap-6">`. Coluna esquerda: tabela + bulk actions. Coluna direita: sticky sidebar com ConfigResumoCard + botão Rodar análise + cronômetro. Mobile: `lg:` breakpoint faz sidebar cair pra cima (grid-cols-1).
- **A2 (remover na Show)**: em `Show.jsx`, deletar bloco do ConfigResumoCard + bloco do botão "Rodar análise". Simples remoção. Backend `SugadorController::show` continua enviando `sugador_config` e `can_manage_config` — deixar (não é urgente limpar; se causar warning, remover das props).
- **A3 (busca + filtro analista)**: em `Index.jsx` header, substituir botão "Configurar" por:
  - `<Input>` de busca (client-side filter em `companies_summary`)
  - `<Select>` de analista (só renderiza se `auth.user.is_admin`; carrega lista via prop `analistas` nova do backend)
  - Backend `SugadorController::index` recebe `?analista_id=X` e filtra `companies_summary` pelas empresas atribuídas ao user selecionado (via pivot `company_users` ou relação `carteira`)
- **B1 (filtro período)**: em `EmpresaListagem.jsx`, adicionar `<Select>` de período no topo da tabela. Estado via URL search params (`?periodo=hoje`). Handler dispara `router.get()` com merge de params. Backend `SugadorController::porEmpresa` já recebe request; ler `$request->get('periodo', 'hoje')` e aplicar `whereBetween('detected_at', [from, to])`.
- **B2 (click row)**: `<tr onClick={() => router.visit(route('sugadores.show', s.id))} className="cursor-pointer hover:bg-white/5">`. Checkbox `<td>` e botões precisam `onClick={e => e.stopPropagation()}` para não navegar. Não afeta o bulk copy MLBs.

### Claude's Discretion

- Tamanho exato da sidebar (`320px` vs `380px`) — research confirma o que outros dashboards do projeto usam
- Presets do filtro período — plan pode adicionar "Últimos 90d" se fizer sentido; default `hoje` é locked
- Manter ou remover botão "Ver detalhes" da linha após adicionar click row — decisão do plan (recomendo remover para reduzir ruído)
- Persistência da busca/filtro analista na URL vs só estado local — plan decide (recomendo URL para permitir bookmark)

</decisions>

<specifics>
## Specific Ideas

### Layout esperado `/sugadores/empresa/{id}`

```
┌──────────────────────────────────────────┬───────────────────────┐
│ [Voltar] Nome da empresa                  │ ConfigResumoCard       │
│                                           │ ─────────────────      │
│ [Filtro período: Hoje ▼]  [Buscar ...]    │ Threshold: 30% ROAS    │
│                                           │ Status: Ativa           │
│ ┌ ─ Bulk actions bar (se ≥1 selected) ─┐ │ [Configurar]           │
│                                           │                         │
│ Tabela de sugadores (click row → Show)    │ ─────────────────      │
│                                           │ [Rodar análise]        │
│ ...                                       │ (cronômetro aparece    │
│                                           │  aqui quando rodando)  │
└──────────────────────────────────────────┴───────────────────────┘
```

Mobile:
```
┌──────────────────────────────────────────┐
│ ConfigResumoCard + Rodar análise          │ ← sidebar em cima
├──────────────────────────────────────────┤
│ Filtros + Tabela                          │
└──────────────────────────────────────────┘
```

### Header `/sugadores` (Index.jsx)

**Antes (pós-Phase 52):**
```
Sugadores  [3 pendentes]              [⚙ Configurar]
Adgroups (e opcionalmente ...)
```

**Depois (Phase 54):**
```
Sugadores  [3 pendentes]  [Buscar empresa ...]  [Analista ▼ (só admin)]
Adgroups (e opcionalmente ...)
```

### Click row na EmpresaListagem

- Toda `<tr>` recebe `cursor-pointer` + `onClick`
- Hover: bg mais escuro (padrão table)
- Checkbox `<td>`, botão MLBs `<td>`, botão "Ver detalhes" (se mantido) `<td>` recebem `onClick={e => e.stopPropagation()}`

### Backend precisa

- `SugadorController::index` — nova prop `analistas` (só quando auth.user.is_admin=true) + filtro `?analista_id=X` aplicado em `companies_summary`
- `SugadorController::porEmpresa` — filtro `?periodo=hoje|7d|30d|todos` (default `hoje`) aplicado em `sugadores` via `whereDate('detected_at')` ou `whereBetween`

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefing

- `.planning/todos/pending/270702-refinamentos-sugadores-uat-e-magic-ui.md` — blocos A + B (Phase 54); bloco C fica pra Phase 55

### Patterns existentes (a investigar/reusar)

- `app/Http/Controllers/SugadorController.php` — `index` (linha 30ish, envia `companies_summary`) e `porEmpresa` (linha 277, recebe filtros)
- `app/Models/Company.php` — relação `analistas()` ou pivot `company_users` para filtro de A3
- `app/Models/User.php` — helper `isAnalista()` / `hasCargo('analista')` — research confirma qual usar
- `resources/js/Pages/Sugadores/Index.jsx` (516 linhas pós-Phase 52) — header a mudar (linhas 434-448 pós-cleanup)
- `resources/js/Pages/Sugadores/Show.jsx` (1038 linhas) — remover ConfigResumoCard + botão análise (Wave 3 da Phase 52)
- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (538 linhas) — layout 2 colunas + filtro período + click row
- `resources/js/Components/ui/select.jsx` — Select shadcn/ui para filtro analista + período
- `resources/js/Components/ui/input.jsx` — Input shadcn/ui para busca
- Router filtro pattern — algum lugar do projeto usa `router.get()` com merge de query — grep

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/plan-check overhead — APLICADO

</canonical_refs>

<deferred>
## Deferred Ideas

- Redesign visual completo (headers cinza, checkbox quadrado, drag handles, tabs superiores, "Customize Columns") → Phase 55
- Magic UI (Number Ticker, Animated List, Blur Fade) → Phase 55
- Filtros adicionais na drilldown (por status, por analista dentro da empresa) → seed futuro
- Ordenação de colunas na tabela → seed futuro
- Backfill de sugadores antigos → não pertinente

</deferred>

---

*Phase: 54-refinamentos-sugadores-uat-filtros*
*Context gerado: 2026-07-02 (síntese lean pós-UAT Phase 52)*
