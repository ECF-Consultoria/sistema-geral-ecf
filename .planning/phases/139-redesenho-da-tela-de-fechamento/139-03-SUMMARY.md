---
phase: 139-redesenho-da-tela-de-fechamento
plan: 03
subsystem: ui
tags: [react, inertia, tailwind, fechamento, dashboard-widgets]

# Dependency graph
requires:
  - phase: 139-02
    provides: "prop `totais` na resposta de Inertia de AdminController::fechamento(), nos dois ramos (ao vivo e congelado)"
provides:
  - "Cabeçalho redesenhado (título do mês + pill de estado + ações) em Financeiro.jsx"
  - "function TotalAReceberCard — consome `totais`, trata piso/ausência sem recalcular nada"
  - "function SubiramDeFaixaCard — card em destaque com atalho onFocarEmpresa(id)"
  - "function ServicosContratadosBar — barra empilhada + legenda substituindo o donut"
  - "estados filtroChip/empresaFocada elevados ao componente de página, com o handler focarEmpresaSubiuFaixa já ligado ao widget de upgrades"
affects: [139-04, 139-05, 139-06, 139-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "widgets do topo consomem só a prop `totais` já agregada pelo backend — nenhum cálculo financeiro no JSX (T-139-07)"
    - "classes Tailwind fora da escala padrão (ex.: px-4.5, gap-4.5, py-5.5) não geram CSS nenhum e falham em silêncio — usar valor arbitrário em px (px-[18px]) quando a escala de 4px não bate com o espaçamento do handoff"

key-files:
  created: []
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx

key-decisions:
  - "RefazerFechamentoDialog manteve a cor vermelha/destrutiva (não virou ecf-yellow) — é uma ação corretiva sobre um mês já fechado, semanticamente distinta de 'Fechar o mês'; só padding/radius foram alinhados ao ritmo do cabeçalho novo"
  - "ServicosContratadosBar usa dois totais diferentes: o denominador da largura das barras inclui o balde 'Sem contratos' (barra soma 100%), mas o rótulo 'N contratos ativos' do topo exclui esse balde — uma empresa sem contrato não é um contrato"
  - "GraficoServico foi reescrito em ServicosContratadosBar reaproveitando literalmente a mesma agregação (voto por contrato ativo, balde 'Sem contratos', corPorNome) — só a renderização mudou de donut para barra"
  - "filtroChip/empresaFocada e o handler focarEmpresaSubiuFaixa foram declarados neste plano mas ainda não têm consumidor (chips de filtro e abertura automática da linha entram no Plano 04) — comportamento intencional, não código morto esquecido"

patterns-established:
  - "widget-card do topo: rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-6, métrica herói em font-mono tabular-nums, rodapé com border-t e pares label/valor"

requirements-completed: [D-01, D-02, D-05]

# Metrics
duration: ~45min
completed: 2026-09-04
---

# Phase 139 Plan 03: Cabeçalho e três widgets do topo do Fechamento Summary

**Financeiro.jsx perde recharts, Tipo de cobrança, Distribuição de faixas e o Total consolidado de três colunas; ganha cabeçalho reestilizado (pill de estado, ações no formato do handoff) e três widgets novos — Total a receber, Subiram de faixa este mês (com atalho por empresa) e Serviços contratados em barra empilhada — todos lendo só a prop `totais` do backend, sem recalcular nada no front.**

## Performance

- **Duration:** ~45 min
- **Completed:** 2026-09-04
- **Tasks:** 3/3
- **Files modified:** 1

## Accomplishments

- **Tarefa 1** — removidos `GraficoCobranca`, `GraficoFaixas`, `MiniPie`, `ChartCard`, `TOOLTIP_STYLE`, `TotalConsolidado` e o import do `recharts` (nenhum componente do arquivo usa mais a biblioteca); removido o grid de 3 gráficos e a chamada de `TotalConsolidado` do JSX da página. `SERVICO_PALETTE`/`corPorNome` preservados.
- **Tarefa 2** — cabeçalho reescrito: `StatusCompetenciaBadge` virou pill com pontinho de 6px (âmbar em "Em aberto", esmeralda em "Fechado" — as palavras e a data de fechamento não mudaram); ações reordenadas (seletor de mês, sincronizar, gerar relatórios, ação primária por último) no formato secundário/primário do handoff; novo helper `tituloDoMes()` (só o mês quando o ano é o corrente). Novo `function TotalAReceberCard({ totais })`: métrica herói de 52px em `font-mono tabular-nums`, prefixo "a partir de" quando `total_e_piso`, aviso de empresas sem valor definido, e os três estados de ausência do rodapé escritos por extenso (nunca R$ 0 nem traço mudo).
- **Tarefa 3** — novo `function SubiramDeFaixaCard({ totais, companies, onFocarEmpresa })`: card em destaque com o número de upgrades, ganho total (prefixo "no mínimo" quando `upgrades_ganho_parcial`), lista de atalhos ordenada por `ganho_faixa` desc (sem ganho conhecido por último), e o card permanece visível com "Nenhuma empresa mudou de faixa neste mês." quando `upgrades_quantidade === 0`. `GraficoServico` virou `function ServicosContratadosBar({ companies })`: barra horizontal empilhada + legenda, reaproveitando a mesma agregação por `servico_nome` com o balde "Sem contratos". Estados `filtroChip`/`empresaFocada` elevados ao componente de página com o handler `focarEmpresaSubiuFaixa`, prontos para o Plano 04 consumir.
- Corrigidas 4 classes Tailwind fora da escala padrão (`px-4.5`, `gap-4.5`, `py-5.5`) que eu mesmo havia introduzido ao traduzir os espaçamentos do handoff — a escala padrão do Tailwind pula de `3.5` pra `5`, então essas classes não geram CSS nenhum (falha silenciosa, sem erro de build). Substituídas por valores arbitrários em px (`px-[18px]`, `gap-[18px]`, `py-[22px]`) e confirmado no CSS compilado que as regras existem.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Remover os widgets aposentados e o recharts** - `71604c6c` (refactor)
2. **Tarefa 2: Cabeçalho + widget "Total a receber"** - `e81dc8a5` (feat)
3. **Tarefa 3: Widgets "Subiram de faixa este mês" e "Serviços contratados"** - `cbad2b49` (feat)

**Plan metadata:** (este commit, incluído no fechamento do plano)

## Files Created/Modified

- `resources/js/Pages/Admin/Financeiro.jsx` — cabeçalho, `TotalAReceberCard`, `SubiramDeFaixaCard`, `ServicosContratadosBar`; remoção de `recharts` e dos widgets aposentados.

## Decisions Made

- **RefazerFechamentoDialog manteve o vermelho.** O plano descreve a ação primária do cabeçalho como `bg-ecf-yellow`, mas "Refazer fechamento" é uma ação corretiva sobre um mês já fechado — diferente semanticamente de "Fechar o mês". Recolorir para amarelo apagaria esse sinal de atenção que já existia em produção. Só padding/radius foram alinhados ao ritmo visual do cabeçalho novo (px-[18px] py-2.5 rounded-[10px]), a cor e a lógica ficaram intactas.
- **Dois totais diferentes em ServicosContratadosBar.** O denominador da largura dos segmentos da barra inclui o balde "Sem contratos" (a barra soma 100% visualmente, igual ao donut antigo). O rótulo "N contratos ativos" do topo exclui esse balde — uma empresa sem contrato não é um contrato, e misturar os dois números confundiria o time Administrativo.
- **Classes Tailwind arbitrárias em vez de frações fora da escala.** Documentado acima em Accomplishments; ver também `tech-stack.patterns`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Classes Tailwind fora da escala padrão não geravam CSS**
- **Found during:** Tarefa 3 (revisão antes do commit)
- **Issue:** Ao traduzir os espaçamentos do handoff (`padding 10px 18px`, `gap:18px`, `padding 22px 26px`) usei `px-4.5`, `gap-4.5` e `py-5.5` — essas frações não existem na escala padrão do Tailwind (que pula de `3.5` para `5`), então o build passava normalmente mas a regra CSS correspondente nunca era gerada. O botão primário, o `TotalAReceberCard` e o `ServicosContratadosBar` ficariam com padding/gap zerados em produção, sem nenhum erro visível em lugar nenhum.
- **Fix:** Substituídas pelas classes arbitrárias equivalentes em pixels: `px-[18px]`, `gap-[18px]`, `py-[22px]`.
- **Files modified:** `resources/js/Pages/Admin/Financeiro.jsx`
- **Verification:** `npx vite build` + grep no CSS compilado (`public/build/assets/app-*.css`) confirmando `padding-left:18px`, `gap:18px`, `padding-top:22px` etc. presentes.
- **Committed in:** `cbad2b49` (parte do commit da Tarefa 3, antes do commit final)

---

**Total deviations:** 1 auto-fixado (Rule 1 — bug introduzido por mim mesmo na mesma tarefa, corrigido antes do commit).
**Impact on plan:** Nenhum — a correção é interna à implementação desta tarefa, não muda nada do que o plano pediu.

## Issues Encountered

Nenhum além do já documentado acima. A conexão caiu uma vez por erro de rede (ENOTFOUND) no meio da Tarefa 3, depois de todo o código já estar escrito e sem commitar; o trabalho não foi perdido e a execução retomou exatamente do ponto de rodar o gate final antes do commit.

## User Setup Required

None — nenhuma configuração externa. Não houve deploy (fora do escopo deste plano e das travas da fase).

## Next Phase Readiness

- O widget "Subiram de faixa este mês" já emite `onFocarEmpresa(id)` chamando `setFiltroChip('subiu')` + `setEmpresaFocada(id)` — o Plano 04 só precisa consumir esses dois estados nos chips de filtro e na abertura automática da linha.
- Gate `Phase122|Phase136|Phase137|Phase138|Phase139`: **302 testes / 1584 asserções / 0 falhas** — idêntico ao baseline medido antes deste plano (nenhuma regressão).
- `npm run build` roda limpo; `grep -c recharts`, `grep -c "GraficoCobranca\|GraficoFaixas\|GraficoServico\|MiniPie\|TotalConsolidado"` e `grep -c "Instrument Sans\|JetBrains"` retornam todos 0.
- Filtros, cabeçalho de colunas, lista de empresas e área expandida (Plano 04 em diante) continuam no estado da Fase 138 — nada deles foi tocado.

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*

## Self-Check: PASSED

Arquivo `resources/js/Pages/Admin/Financeiro.jsx` conferido por reconsulta (`function TotalAReceberCard`, `function SubiramDeFaixaCard`, `function ServicosContratadosBar` presentes). Os 3 commits de task confirmados por `git log --oneline` (`71604c6c`, `e81dc8a5`, `cbad2b49`). Gate completo rodado uma última vez após a queda de rede: 302 testes / 1584 asserções / 0 falhas.
