---
phase: 54-refinamentos-sugadores-uat-filtros
plan: "54-03"
status: complete
completed_at: 2026-07-02
subsystem: sugadores/frontend-header-cleanup
tags: [ui, refactor, sugadores, show, index, filtros, cleanup]
requires:
  - 54-01
provides:
  - "Show.jsx limpo — sem ConfigResumoCard, sem botao Rodar analise, sem cronometro (A2)"
  - "Index.jsx header com busca de empresa (client-side) + filtro por analista (server-side, admin only) (A3)"
  - "Consumo das props is_admin + analistas + analista_id_selecionado enviadas pelo backend Wave 1"
affects:
  - "resources/js/Pages/Sugadores/Show.jsx (1038 -> 866 linhas, -172 net)"
  - "resources/js/Pages/Sugadores/Index.jsx (516 -> 567 linhas, +51 net)"
tech-stack:
  patterns:
    - "useState + useMemo client-side sobre `name.toLowerCase().includes()` (pattern ConfigPickerModal:245-247)"
    - "router.get(route, { param }, { preserveState: true, preserveScroll: true }) (pattern Portfolio/Carteiras.jsx:25-30)"
    - "<input> / <select> NATIVOS com classes ECF (evita Components/ui/*.jsx que quebra dark theme — research §3)"
  removed:
    - "ConfigResumoCard local em Show.jsx (definicao canonica agora vive apenas em EmpresaListagem.jsx)"
    - "Hooks analyzing/elapsed + handler rodarAnalise (contexto errado — view individual e' sobre 1 sugador)"
    - "Imports SlidersHorizontal e Settings em Show.jsx (usados so por ConfigResumoCard)"
key-files:
  modified:
    - resources/js/Pages/Sugadores/Show.jsx
    - resources/js/Pages/Sugadores/Index.jsx
decisions:
  - "Novas props Inertia com defaults defensivos (is_admin = false, analistas = [], analista_id_selecionado = null) — previne crash se backend antigo cachear"
  - "ConfigPickerModal deixado como codigo morto — trigger removido mas componente e state preservados (debito tecnico)"
  - "Mensagem de estado vazio distingue 3 casos: busca-sem-match, filtro-analista-sem-empresa, carteira-vazia"
  - "Show.jsx tipo=campanha: nada substitui o ConfigResumoCard removido (era o unico componente que renderizava para esse branch)"
metrics:
  duration_min: 20
  commits: 2
  tasks_completed: 2
  files_touched: 2
  lines_added: 96
  lines_removed: 217
---

# Phase 54 Plan 54-03: Show.jsx sem ConfigResumoCard + Index.jsx com busca + filtro analista — Summary

Duas mudancas UI independentes que compartilharam a mesma wave por nao conflitar
em arquivos. Zero mudanca de backend (Plan 54-01 ja cobriu). Sem TDD (UI puro,
verificacao via UAT humano na Wave 4).

## Objetivo alcancado

**A2:** `Show.jsx` (view individual do sugador) volta a ser focado em 1 sugador
apenas — ConfigResumoCard, botao "Rodar analise" e cronometro foram removidos.
Config de sugadores agora e' acessivel exclusivamente via drilldown per-empresa
(`EmpresaListagem`), coerente com a decisao arquitetural do CONTEXT §A2 (config
e' per-empresa, nao per-sugador).

**A3:** `Index.jsx` header substituiu o botao "Configurar" por dois filtros
operacionais que consomem as props do backend Wave 1:
- `<input>` de busca client-side por nome de empresa (case-insensitive)
- `<select>` de filtro server-side por analista, visivel SO para admins

Pronto para UAT na Wave 4 (Phase 54 completa).

## Commits

| SHA       | Tipo | Mensagem curta                                                     |
| --------- | ---- | ------------------------------------------------------------------ |
| `cf172f5` | feat | Show.jsx sem ConfigResumoCard e Rodar analise (A2)                 |
| `f540a9b` | feat | Index.jsx header com busca de empresa + filtro analista (A3)       |

## Arquivos modificados

### `resources/js/Pages/Sugadores/Show.jsx` (1038 → 866 linhas, **-172 net**)

**Commit `cf172f5` (T1):**

- **Hooks removidos** (~44 linhas): `useState(analyzing)`, `useState(elapsed)`,
  `useEffect` do cronometro (30s tick), handler `rodarAnalise` completo. Todo
  o bloco linhas 145-188 do arquivo original virou 3 linhas de comentario
  explicativo Phase 54 (A2).
- **Props removidas do destructuring**: `sugador_config = null` e
  `can_manage_config = false`. Comentario explicativo mantido — backend
  continua enviando por compatibilidade; cleanup do backend vira seed futuro
  (props ignoradas pelo React sem warning).
- **Bloco render removido** (~33 linhas): o wrapper `<div className="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-4">`
  que embrulhava ConfigResumoCard (col-span-1) + MlbsDoAdgroup (col-span-3).
  Substituido por: MlbsDoAdgroup largura total apenas para tipo=adgroup;
  branch tipo=campanha nao renderiza mais nada nesta posicao.
- **Componente local removido** (~90 linhas): a funcao `function ConfigResumoCard(...)`
  inteira (com docblock JSDoc). Definicao canonica agora vive apenas em
  `EmpresaListagem.jsx:78-171`.
- **Imports orfaos removidos**: `SlidersHorizontal` e `Settings` (usados
  apenas pelo ConfigResumoCard). Verificados via grep — nao aparecem em
  nenhum outro lugar.
- **Preservado 100%**: formulario status/acao_tomada, MoveToSgiModal,
  MlbsDoAdgroup, MlbHighlight fallback, breadcrumb, timeline de acoes,
  ações do sugador (pausar/removido/reduzido_lance/reativado), links
  url_anuncio + url_ads, informacoes do adgroup, chip "Continuar com [X]".

### `resources/js/Pages/Sugadores/Index.jsx` (516 → 567 linhas, **+51 net**)

**Commit `f540a9b` (T2):**

- **Import atualizado**: `useMemo` adicionado ao import do React (linha 3).
- **Props novas consumidas** (backend Wave 1):
  - `is_admin = false` — gate do dropdown de analista.
  - `analistas = []` — lista `{id, name}` dos users com pivot role='analista'
    (vazio se nao-admin).
  - `analista_id_selecionado = null` — eco do `?analista_id=X` aplicado.
  - Defaults defensivos previnem crash se backend antigo cachear props.
- **State + memo + handler adicionados** (linhas 380-405 aprox):
  - `const [q, setQ] = useState('')` — estado local da busca.
  - `companiesFiltradas` via `useMemo` (case-insensitive `.includes()` sobre
    `card.name`).
  - `aplicarFiltroAnalista(value)` — dispara `router.get` com
    `preserveState/preserveScroll` (pattern canonico `Portfolio/Carteiras.jsx:25-30`).
- **Header header refeito** (linhas 442-471 aprox): o botao "Configurar" foi
  substituido por:
  - `<input>` NATIVO de busca com icone Search absoluto, classes ECF dark
    (`w-56 h-9 pl-8 pr-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/40`).
  - `<select>` NATIVO de analistas com `{is_admin && ...}` gate;
    `<option className="bg-ecf-card">` para evitar fundo branco default do
    browser em dark theme.
- **Render dos cards**: `companies_summary.map` substituido por
  `companiesFiltradas.map`; mesma substituicao no length check.
- **Mensagens de estado vazio** distinguem 3 casos:
  - busca sem match: `Nenhuma empresa encontrada para "{q}"`
  - filtro analista sem empresa: `Nenhuma empresa para este analista.`
  - carteira vazia: `Nenhuma empresa visível.`
  Sub-texto de cada estado orienta acao ("Ajuste o filtro..." vs "Configure
  thresholds..." vs "Nenhum sugador pendente na sua carteira.").

## `npm run build` resultado

Verde em todas as execucoes:

- Apos T1 (Show.jsx): `✓ built in 12.24s`
- Apos T2 (Index.jsx): `✓ built in 12.01s`
- Final consolidado: `✓ built in 11.97s`

Zero warning JSX. Chunks Show/Index recompilados dentro do range esperado
(Show 30.89 kB, Index 22.71 kB).

## Verificacoes do plan (linhas 219-225)

Todas passaram:

- Show.jsx `grep -c "ConfigResumoCard"` ignorando comentarios → 0 codigo executavel
  (as 3 ocorrencias restantes sao em comentarios explicativos Phase 54).
- Show.jsx `grep -c "rodarAnalise\|analyzing\|elapsed"` ignorando comentarios → 0.
- Index.jsx `grep -c "is_admin && "` → 1 (dropdown gated).
- Index.jsx `grep -c "companiesFiltradas"` → 4 (definicao + length check + map + comentario).
- Index.jsx `grep -c "aplicarFiltroAnalista"` → 2 (definicao + onChange).

## Desvios (deviations rules)

Nenhum desvio de Rule 1-4. Duas escolhas de discretion documentadas no plan:

- **Contagem de linhas removidas em Show.jsx** ficou -172 net (vs estimado ~-85
  no plan). Ampliacao vem de remocao completa do docblock JSDoc do
  ConfigResumoCard (~15 linhas) + limpeza dos comentarios inline dos hooks
  removidos (~10 linhas alem do bloco funcional). Semantica preservada 100%.

- **Contagem de linhas adicionadas em Index.jsx** ficou +51 net (vs estimado
  ~+30 no plan). Ampliacao vem de comentarios pt-BR expandidos + mensagens
  de estado vazio distinguindo 3 casos (busca / filtro / carteira vazia).
  UX melhor que o plan sugeria (que so aceitava 2 casos).

## Debito tecnico aberto

Documentados para seed futuro (nao bloqueiam a Phase):

1. **`ConfigPickerModal` orfao em Index.jsx**: o modal (linhas 243-300)
   ainda existe no arquivo mas nao tem mais trigger — o botao "Configurar"
   que chamava `setConfigPickerOpen(true)` foi removido. State
   `configPickerOpen` e render condicional (`{configPickerOpen && ...}`)
   permanecem como codigo morto. Nao causa warning nem crash porque nunca
   e' setado como true. Remocao completa em phase futura.

2. **Bug latente pre-existente em `ConfigPickerModal`**: o modal usa `<Link>`
   do `@inertiajs/react` (linha 285) mas o import NAO existe no topo do
   arquivo (grep confirmou). Se o modal for aberto em runtime, quebra com
   ReferenceError. Como o trigger sumiu na Phase 54 (A3), o bug fica
   dormente ate cleanup. Documentar tambem no seed do item 1.

3. **Props Inertia `sugador_config` e `can_manage_config` continuam
   sendo enviadas por `SugadorController::show`** (linhas 380-399 do
   controller). Nao afeta o React (props ignoradas silenciosamente), mas
   consome memoria/serializacao. Cleanup do controller vira seed futuro
   junto com a limpeza do modal orfao acima.

## Success criteria status

- [x] Show.jsx sem ConfigResumoCard, sem botao "Rodar analise", sem cronometro
- [x] Show.jsx mantem MlbsDoAdgroup + formulario status + acoes + breadcrumb
- [x] Index.jsx header substitui "Configurar" por busca + select analista
- [x] Busca filtra client-side via useMemo (case-insensitive)
- [x] Select analista aparece SO para is_admin=true
- [x] Handler dispara router.get com preserveState/preserveScroll
- [x] `npm run build` verde
- [x] Regressao zero: ConfigPickerModal (embora orfao) nao quebra runtime

## Self-Check: PASSED

- Arquivos modificados:
  - `resources/js/Pages/Sugadores/Show.jsx` → FOUND (866 linhas)
  - `resources/js/Pages/Sugadores/Index.jsx` → FOUND (567 linhas)
- Commits:
  - `cf172f5` → FOUND
  - `f540a9b` → FOUND
