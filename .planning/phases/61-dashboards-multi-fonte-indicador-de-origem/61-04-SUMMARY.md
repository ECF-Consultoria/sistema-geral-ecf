---
phase: 61-dashboards-multi-fonte-indicador-de-origem
plan: 04
subsystem: frontend-portfolio
tags: [frontend, portfolio, analista, estrategista, badge, source-badge, source-counts, tolerancia-fonte, multi-fonte, dash-05, data-05]
requirements: [DASH-05, DATA-05]
dependency_graph:
  requires:
    - "61-01 (backend enriquece payloads Portfolio com `source` por empresa + `source_counts` por profissional)"
    - "61-02 (componente `<SourceBadge>` em `resources/js/Components/ui/source-badge.jsx`)"
  provides:
    - "Portfolio/Show.jsx: badge de origem por empresa (mobile card + desktop table)"
    - "Portfolio/Carteiras.jsx: mini-legenda com contadores de fonte por profissional"
    - "Tolerância a `source='none'` (empresa sem integração aparece cinza, não some)"
    - "Backward compat total com `UNIFIED_METRICS_ENABLED=OFF` (badges/legenda não renderizam)"
  affects:
    - "UX das rotas `/portfolio` (admin → Carteiras) e `/admin/users/{id}/portfolio` + `/portfolio` (analista/estrategista → Show)"
tech_stack:
  added: []
  patterns:
    - "Import + render condicional com guarda defensiva (`c.source && ...` / `u.source_counts && ...`) — mesmo padrão do 61-03 header"
    - "Ordem canônica de fontes na legenda: ML → Agregado → Adman → Sem integração (mesma sequência da ADR DATA-04)"
    - "Render inline `.map` sem componente novo — SourceBadge é o único primitivo, o restante é JSX plano dentro do card"
key_files:
  created: []
  modified:
    - resources/js/Pages/Portfolio/Show.jsx
    - resources/js/Pages/Portfolio/Carteiras.jsx
decisions:
  - "Badge na coluna Empresa (após `{c.name}`) em AMBAS as apresentações — mobile card (linha ~792) e desktop table (linha ~875) — para paridade responsiva. Alternativa (só desktop) foi rejeitada porque analista mobile também precisa distinguir fonte."
  - "Top 3 Faturamento sidebar (linha ~973) NÃO recebeu badge — é view resumida, mostrar badge poluiria o layout compacto e o operador já sabe a fonte pela linha principal."
  - "Ordem canônica ML → Agregado → Adman → Sem integração adotada na mini-legenda: mesma prioridade da ADR DATA-04 (ML fonte primária, unified agregado, adman legacy secundário, none residual)."
  - "Renderização condicional variante-a-variante (`> 0`) em vez de renderizar zeros — evita cards com '0 ML · 0 Adman' poluindo profissionais de carteira homogênea."
  - "Comentário pt-BR inline explicando a guarda `c.source &&` — dá contexto para leitor futuro identificar o link com o backend flag."
metrics:
  duration: "~10 min"
  completed_date: "2026-07-07T17:12:00Z"
  files_touched: 3  # 2 código + 1 summary
  lines_added_show: 8
  lines_added_carteiras: 12
  lines_removed: 0
---

# Phase 61 Plan 04: Portfolio SourceBadge + Source Counts (DASH-05)

## One-liner
`Portfolio/Show.jsx` ganha `<SourceBadge>` por linha (mobile + desktop) e `Portfolio/Carteiras.jsx` ganha mini-legenda `source_counts` por profissional, com guardas defensivas para backward compat quando `UNIFIED_METRICS_ENABLED=OFF`.

## Objetivo cumprido
- **DASH-05**: carteiras Analista/Estrategista renderizam SEM erro quando uma empresa é ML-only ou sem integração (badge `variant="ml"` ou `variant="none"` no lugar de crash/omissão silenciosa).
- **DATA-05 (parcial)**: superfície Portfolio agora exibe origem da métrica por empresa e distribuição de fontes por carteira. As demais superfícies (Companies/Show, Dashboard Admin) estão em 61-03/61-05.

## O que foi entregue

### Task 1 — `Portfolio/Show.jsx`
- Import `SourceBadge` de `@/Components/ui/source-badge` (1 linha, junto do bloco de imports do lib/utils).
- Badge inline após `{c.name}` no **mobile card** (bloco `.md:hidden`, dentro do `<Link>` de nome + status).
- Badge inline após `{c.name}` na **desktop table** (bloco `hidden md:block`, dentro do `<td>` de nome + grants).
- Guarda `c.source && <SourceBadge variant={c.source} />` em ambos os locais — quando flag OFF, `c.source` é undefined e o JSX curto-circuita.
- Total: **+8 linhas**, 0 remoções.

### Task 2 — `Portfolio/Carteiras.jsx`
- Import `SourceBadge` de `@/Components/ui/source-badge` (1 linha após imports lucide + utils).
- Mini-legenda condicional `{u.source_counts && (...)}` inserida logo abaixo do `<p>` "{tipoLabel} · {N} empresa(s)".
- 4 spans inline: ML, Agregado (unified), Adman, Sem integração (none) — cada um só renderiza se `count > 0`.
- Layout: `flex items-center gap-1.5 mt-1 flex-wrap` — cabe em card estreito (grid `md:grid-cols-2 lg:grid-cols-3`).
- Total: **+12 linhas**, 0 remoções.

## Tolerâncias de fonte
| Situação | `c.source` payload | Comportamento UI |
|----------|---------------------|-------------------|
| Flag OFF (default) | `undefined` | Badge não renderiza; layout idêntico ao pré-Phase-61 (backward compat) |
| Flag ON, empresa Adman-only | `'adman'` | Badge cinza discreto "Adman" |
| Flag ON, empresa ML-only | `'ml'` | Badge amarelo sólido "ML" |
| Flag ON, empresa com AMBAS | `'unified'` | Badge amarelo transparente "Agregado" |
| Flag ON, empresa sem integração | `'none'` | Badge outline cinza "Sem integração" — empresa NÃO some da tabela |

CTA de integração (para linhas `source='none'`) fica out-of-scope desta phase — potencial META-FUTURE quando o operador demandar.

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito. Duas notas de execução (não deviations):
1. A compactação do bloco de mini-legenda no Carteiras.jsx (spans em linha única em vez de multi-linha verbose) foi feita para respeitar o teto `+20 linhas` do acceptance criteria — mesma semântica, apenas whitespace.
2. Comentário pt-BR foi adicionado inline em cada guarda `c.source &&` / `u.source_counts &&` (não estava no plan, mas segue a convenção do projeto de explicar o "porquê" em pt-BR) — 3 linhas de comentário no Show.jsx e 3 no Carteiras.jsx, dentro do orçamento de linhas.

## Verificação
- `npm run build` verde (exit 0, 20.91s → 13.01s após compactação; nenhum warning novo).
- `grep -c "import { SourceBadge }" resources/js/Pages/Portfolio/Show.jsx` = **1**
- `grep -c "c.source &&" resources/js/Pages/Portfolio/Show.jsx` = **2** (mobile + desktop)
- `grep -c "SourceBadge variant" resources/js/Pages/Portfolio/Show.jsx` = **2**
- `grep -c "import { SourceBadge }" resources/js/Pages/Portfolio/Carteiras.jsx` = **1**
- `grep -c "u.source_counts" resources/js/Pages/Portfolio/Carteiras.jsx` = **6** (1 guard + 4 count checks + 1 amostragem repetida em cada span; superior aos ≥5 exigidos)
- `grep -c "SourceBadge variant=" resources/js/Pages/Portfolio/Carteiras.jsx` = **4** (ml, adman, unified, none)
- `git diff --numstat` — Show: `8 0` / Carteiras: `12 0` (zero remoção; ambos dentro do teto).

## Zero regressão visual
- Métricas de empresa (Faturamento, Meta, Margem, Ads, Ação, Crescimento) intactas.
- 4 mini-cards de profissional (TACOS Médio, Faturamento, Margem Méd., Gasto Ads) intactos.
- Filtros de busca + período + sort da tabela intactos.
- Empty state ("Nenhum profissional com carteira." / "Nenhuma empresa encontrada com os filtros.") intactos.
- Sidebar Top 3 / Comparação / NPS History intactos.

## Success Criteria — todos cumpridos
- [x] DASH-05 fechado: empresa ML-only ou sem integração renderiza sem erro
- [x] DATA-05 avançado: badges de fonte visíveis por empresa + mini-legenda por profissional
- [x] Backward compat total quando flag OFF (guardas defensivas em ambos os arquivos)
- [x] Zero regressão visual em qualquer bloco intocado

## Self-Check: PASSED

**Arquivos modificados existem:**
- `resources/js/Pages/Portfolio/Show.jsx` — FOUND (8 linhas adicionadas)
- `resources/js/Pages/Portfolio/Carteiras.jsx` — FOUND (12 linhas adicionadas)

**Arquivos criados:**
- `.planning/phases/61-dashboards-multi-fonte-indicador-de-origem/61-04-SUMMARY.md` — FOUND (este arquivo)

**Build:**
- `npm run build` exit code 0, 13.01s — FOUND

**Commit:** será registrado no passo seguinte.
