---
phase: 54-refinamentos-sugadores-uat-filtros
plan: "54-02"
status: complete
completed_at: 2026-07-02
subsystem: sugadores/frontend-drilldown
tags: [ui, refactor, sugadores, empresa-listagem, layout, filtros]
requires:
  - 54-01
provides:
  - "Layout 2 colunas responsivo em EmpresaListagem (main 3/4 + sidebar sticky 1/4)"
  - "Filtro de periodo com <select> nativo consumindo props periodo + periodo_presets do backend Wave 1"
  - "Click row inteiro navega para /sugadores/{id} com stopPropagation nos controles interativos"
affects:
  - "resources/js/Pages/Sugadores/EmpresaListagem.jsx (538 -> 593 linhas, +55 net)"
tech-stack:
  patterns:
    - "grid-cols-1 lg:grid-cols-4 + col-span-3 + col-span-1 lg:sticky lg:top-4 lg:self-start (research §1)"
    - "<select> nativo com classes ECF dark theme — evita shadcn/ui/select que quebra dark"
    - "router.get(route, { param }, { preserveState: true, preserveScroll: true }) (pattern Portfolio/Carteiras.jsx:25-30)"
    - "onClick={() => router.visit()} na <tr> + stopPropagation em <td> interativos (pattern Performance/Index.jsx:324)"
key-files:
  modified:
    - resources/js/Pages/Sugadores/EmpresaListagem.jsx
decisions:
  - "Wrapper lg:grid-cols-4 (col-span-3 + col-span-1) em vez de lg:grid-cols-[minmax(0,1fr)_320px] — consistencia com o header antigo do mesmo arquivo e com Show.jsx (research §1 recomenda)"
  - "Link 'Detalhes' inline REMOVIDO — click-row cobre 100% da navegacao (CONTEXT decision discretion linha 65 aprova; research §5 recomenda)"
  - "<option class='bg-ecf-card'> nas opcoes do select — evita fundo branco padrao do browser em <select> nativo dark theme"
  - "min-w-[80px] no <td> das acoes — previne colapso horrendo quando isElegivel=false (sugadores tipo=campanha)"
metrics:
  duration_min: 25
  commits: 3
  tasks_completed: 3
  files_touched: 1
  lines_added: 87
  lines_removed: 32
---

# Phase 54 Plan 54-02: Refactor UI de EmpresaListagem — Summary

Refactor puro de UI em `EmpresaListagem.jsx`. Zero mudanca de backend
(Plan 54-01 ja cobriu). Tres mudancas coordenadas no mesmo arquivo,
uma por commit atomico, todas com build verde.

## Objetivo alcancado

Drilldown `/sugadores/empresa/{id}` agora tem layout 2 colunas com sidebar
sticky (`ConfigResumoCard` + botao "Rodar analise" + cronometro sempre
visiveis ao rolar), filtro de periodo persistido em URL query string, e
row inteira clicavel para o Show individual. Bulk copy MLBs e cronometro
100% funcionais — regressao zero.

Pronto para o Plan 54-03 (header do Index com busca de empresa + filtro
analista) e 54-04 (limpeza da Show.jsx individual).

## Commits

| SHA       | Tipo | Mensagem curta                                                    |
| --------- | ---- | ----------------------------------------------------------------- |
| `340fc8b` | feat | layout 2 colunas em EmpresaListagem (A1)                          |
| `083c6e4` | feat | filtro de periodo com select nativo no topo da tabela (B1)        |
| `4861029` | feat | click row navega para detalhes do sugador (B2)                    |

## Arquivos modificados

### `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (538 → 593 linhas, +55 net)

**T1 — Layout 2 colunas (commit `340fc8b`):**

- Props novas aceitas (linhas 175-186): `periodo = 'hoje'` + `periodo_presets = []`
  (defaults seguros; backend Wave 1 ja envia).
- Wrapper externo `<div className="grid grid-cols-1 lg:grid-cols-4 gap-4">`
  envolve todo o conteudo (linha 350).
- Coluna esquerda `<div className="lg:col-span-3 min-w-0 space-y-4">`
  (linha 351): header simplificado (so nome empresa + contagem) + filtro
  periodo + bulk bar + tabela. `min-w-0` OBRIGATORIO (research §1) — sem
  ele a tabela empurra o grid.
- Sidebar direita `<aside className="lg:col-span-1 lg:sticky lg:top-4 lg:self-start space-y-4">`
  (linha 580): so o `ConfigResumoCard` (com botao Rodar analise + cronometro
  passados via props existentes).
- Grid interno antigo do header (linhas 347 e 367 originais) REMOVIDO — o
  wrapper externo cuida do span.
- ConfigResumoCard NAO aparece duplicado — grep confirma apenas 1 uso
  em runtime (definicao linha 78 + uso linha 580).

**T2 — Filtro periodo (commit `083c6e4`):**

- Handler `aplicarPeriodo(value)` (linhas 219-227) dispara
  `router.get(route('sugadores.empresa.listagem', company.id), { periodo }, { preserveState: true, preserveScroll: true })`
  — pattern canonico Portfolio/Carteiras.jsx:25-30.
- Rota nomeada confirmada: `sugadores.empresa.listagem`
  (`routes/web.php:308`).
- Bloco `<select>` nativo (linhas 388-411) posicionado entre o card do
  nome da empresa e a barra bulk (visivel sempre, nao so quando ha
  selecao):
  - `<label>` "Período" em uppercase ECF.
  - `value={periodo}` (echo backend, default `hoje`).
  - `onChange={e => aplicarPeriodo(e.target.value)}`.
  - Classes ECF: `h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80`.
  - `<option className="bg-ecf-card">` evita fundo branco do browser.
  - Contador contextual `X no período` ao lado do select.
- Fallback defensivo: `periodo_presets` vazio → `map` render vazio, sem
  crash.

**T3 — Click row (commit `4861029`):**

- `<tr>` (linha 501) recebe `onClick={() => router.visit(route('sugadores.show', s.id))}`.
- Classes atualizadas: `hover:bg-white/[0.05]` (mais escuro que os
  `[0.02]` originais — affordance visual), `cursor-pointer`,
  `transition-colors`.
- `<td>` do checkbox (linha 508): `onClick={e => e.stopPropagation()}`
  — clicar no checkbox nao dispara navegacao.
- `<td>` das acoes (linha 546): `onClick={e => e.stopPropagation()}`
  + `min-w-[80px]` para nao colapsar quando isElegivel=false.
- `<Link "Detalhes">` INTEIRO removido (linhas 519-526 originais).
  Click-row cobre 100% da navegacao — reduz ruido conforme discretion
  aprovada.
- Import `ExternalLink` removido de `lucide-react` (nao mais usado).
- Import `Link` do `@inertiajs/react` MANTIDO — ainda usado no
  breadcrumb (linha 355).

## `npm run build` resultado

Verde nas 3 execucoes (uma por tarefa):

- T1: `built in 13.10s`
- T2: `built in 14.23s`
- T3: `built in 15.92s`

Zero warning JSX, bundle gerado sem erros. Chunk `EmpresaListagem-*.js`
compilou dentro do range dos outros drilldowns.

## Verificacoes do plan (linhas 251-254)

Todas passaram:

- Grep `ConfigResumoCard`: 5 matches — 3 em comentarios/docblock + 1
  definicao + 1 uso runtime (na aside). Sem duplicacao.
- Grep `stopPropagation`: 4 matches — 2 comentarios explicativos + 2
  handlers reais (checkbox `<td>` + acoes `<td>`).
- Grep `hover:bg-white/[0.05]`: 4 matches — 1 na `<tr>` clicavel + 3
  reusos existentes em botoes (ConfigResumoCard + botao MLBs).
- Import `ExternalLink` ausente do topo do arquivo.
- Import `Link` mantido (breadcrumb continua funcional).

## Desvios (deviations rules)

Nenhum. O plan foi seguido linha a linha:

- Rota nomeada era `sugadores.empresa.listagem` (previsto pelo research
  §4). Grep em `routes/web.php:308` confirmou antes da mudanca. Zero
  ajuste.
- Layout wrapper: `lg:grid-cols-4 col-span-3/col-span-1` (research
  recomendou como canonico do projeto vs `lg:grid-cols-[minmax(0,1fr)_320px]`).
  Ja era a decisao do plan (linha 88).
- Sidebar `lg:sticky lg:top-4 lg:self-start` sem precedente exato no
  projeto (research nota no §1) mas Tailwind puro — nenhum comportamento
  inesperado.

## Success criteria status

- [x] Layout 2 colunas responsivo funcionando (desktop = 3+1, mobile = stack)
- [x] ConfigResumoCard aparece SO na sidebar (nao duplicado)
- [x] Sidebar sticky em desktop (`lg:sticky lg:top-4 lg:self-start`)
- [x] Select de periodo visivel, presets vindos do backend, onChange dispara `router.get`
- [x] Click em qualquer parte da linha (exceto checkbox e botao MLBs) navega para /sugadores/{id}
- [x] Checkbox e botao MLBs mantem comportamento (nao navegam) via `stopPropagation`
- [x] Link "Detalhes" removido
- [x] Bulk copy MLBs 100% funcional (regressao zero — handlers e state intactos)
- [x] `npm run build` verde

## Debito tecnico aberto

Nenhum. Mudanca fechada em 1 arquivo, sem dependencias externas.

## Self-Check: PASSED

- Arquivos modificados:
  - `resources/js/Pages/Sugadores/EmpresaListagem.jsx` → FOUND (593 linhas)
- Commits:
  - `340fc8b` → FOUND
  - `083c6e4` → FOUND
  - `4861029` → FOUND
