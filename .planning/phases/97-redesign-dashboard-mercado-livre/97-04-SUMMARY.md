---
phase: 97-redesign-dashboard-mercado-livre
plan: 04
subsystem: ui
tags: [inertia, react, recharts, dashboard, nps, desempenho, mercado-livre]

# Dependency graph
requires:
  - phase: 97-02
    provides: "nps_ruins (respostas nota baixa do recorte, sem invalidadas), novas_empresas (cards D3), performance_equipe.breakdown (carteira/nps/margem/faturamento/tacos)"
  - phase: 97-03
    provides: "FiltrosDashboard.jsx (rascunho→aplicar), dashboard_route_name, 4 KPIs com delta/link, margin_chart"
provides:
  - "ChartEvolucao.jsx — gráfico 'Evolução no período' com abas Faturamento/Margem, hover interativo (tooltip + Pico/Menor via recharts)"
  - "NpsRuimCarrossel.jsx — carrossel de respostas NPS ruins com link para nps.index"
  - "ScoreEquipe.jsx — lista de nota 0-5 + breakdown, ordenada pior→melhor, link para performance.index"
  - "NovasEmpresas.jsx — cards condicionais das empresas novas do mês, link para companies.show"
  - "Admin.jsx com estados reais de carregando (router.on start/finish) e erro (router.on error + Tentar novamente)"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Estados reais via eventos globais do Inertia router (router.on('start'/'finish'/'error')) — mesmo padrão já usado em resources/js/app.jsx (before/success) — em vez de estado de demo/toggle"
    - "Tooltip customizado do recharts (prop `content=`) para compor '+X% vs média' sem reimplementar SVG manual — Pico/Menor via ReferenceDot"
    - "Componente condicional que retorna null quando vazio (NovasEmpresas) em vez de placeholder, para largura total 'aparecer só quando houver dado' (D3 do CONTEXT)"

key-files:
  created:
    - resources/js/Components/Dashboard/ChartEvolucao.jsx
    - resources/js/Components/Dashboard/NpsRuimCarrossel.jsx
    - resources/js/Components/Dashboard/ScoreEquipe.jsx
    - resources/js/Components/Dashboard/NovasEmpresas.jsx
  modified:
    - resources/js/Pages/Dashboard/Admin.jsx

key-decisions:
  - "ScoreEquipe.jsx reordena a própria cópia de performance_equipe (pior→melhor) sem alterar o array vindo do backend — o BarChart legado 'Desempenho da equipe' (Charts row 1) continua consumindo a ordem original (melhor→pior) sem regressão"
  - "breakdown.tacos (sempre null, decisão do 97-02) é tratado no ScoreEquipe.jsx trocando o rótulo por 'Faturamento' quando null — conforme a própria decisão documentada no 97-02-SUMMARY.md deixou em aberto para este plano"
  - "Layout: NÃO removidos os widgets legados 'Desempenho da equipe' (BarChart), TACOS chart e 'Performance por empresa' (tabela) — o plano pediu para adicionar os 4 componentes novos e substituir apenas o bloco específico do gráfico de Faturamento; manter os demais widgets evita risco de regressão nos testes existentes e mantém dados que o mockup simplificado não cobria"
  - "Link 'Abrir NPS completo →' usa route('nps.index', { empresa_id }) em vez de só route('nps.index') — filtra direto pela empresa da resposta (nps.index já aceita esse query param), mais útil que abrir a listagem geral"
  - "Estado de erro é acionado por router.on('error') (falha real de navegação Inertia ao aplicar filtro), não por um toggle manual — 'Tentar novamente' chama router.reload()"

requirements-completed: [DASH-97-4, DASH-97-5, DASH-97-6]

# Metrics
duration: ~55min
completed: 2026-07-21
---

# Phase 97 Plan 04: Gráfico interativo + NPS ruim/Score da equipe/Novas empresas + estados reais Summary

**Gráfico "Evolução no período" ganhou abas Faturamento/Margem com hover (tooltip + Pico/Menor via recharts), e o Dashboard/Admin.jsx ganhou 3 componentes novos (carrossel NPS ruim, lista Score da equipe pior→melhor, cards de Novas empresas) mais estados reais de carregando/erro — sem toggle de demo.**

## Performance

- **Duration:** ~55 min
- **Started:** 2026-07-21T09:00:00Z (aprox.)
- **Completed:** 2026-07-21T09:15:00Z (aprox.)
- **Tasks:** 2/2 automatizadas completas + 1 checkpoint visual pendente (Task 3)
- **Files modified:** 5 (4 componentes novos + `Admin.jsx`)

## Accomplishments
- **`ChartEvolucao.jsx`** (DASH-97-4): abas Faturamento/Margem (D4 — só essas duas, travado no CONTEXT) consumindo `revenue_chart`/`margin_chart` (séries diárias já filtradas pelo recorte, Plan 97-01). Hover via recharts: `Tooltip` customizado mostra data, valor formatado na unidade da aba e "+X% vs média" (média calculada da própria série filtrada); `ReferenceDot` marca visualmente o ponto de Pico (cor da métrica) e o Menor (vermelho) — reaproveitando o padrão recharts já usado no projeto em vez do SVG manual do mockup (Claude's Discretion do CONTEXT, já que recharts deu o mesmo controle pedido). Subtítulo dinâmico: "{métrica} {unidade} · {período} · {N empresas | nome da empresa}" — deriva `companyName` de `companies_list`+`filters.company_id`. Substituiu a `AreaChart` fixa de Faturamento no lugar exato do bloco antigo (mesma posição `lg:col-span-2`).
- **`NpsRuimCarrossel.jsx`** (DASH-97-5): carrossel horizontal (setas ‹ › com scroll suave) das respostas em `nps_ruins` (nota <=3, já sem invalidadas — Plan 97-02/Fase 96) — cada card: empresa, data, nota (cor computada dentro do `.map()`: vermelho forte se <=2, laranja se =3), comentário, analista, estrategista, link "Abrir NPS completo →" para `route('nps.index', { empresa_id })` (filtra direto pela empresa, mais útil que abrir a listagem geral). Badge de contagem no header + "Ver respostas completas →". Empty state neutro: "Nenhuma nota baixa no período" (sem o jargão "6 ou acima" herdado da escala 0-10 do mockup).
- **`ScoreEquipe.jsx`** (DASH-97-6): lista compacta por pessoa de `performance_equipe` — nota oficial 0-5 (`nota_final`, D1 do CONTEXT — a mesma que vale bônus) com barra + breakdown ("N empresas · NPS X · Margem Y · Faturamento Z", cor computada dentro do `.map()`), ORDENADA pior→melhor (reordena uma cópia local; não altera o array original consumido pelo BarChart legado "Desempenho da equipe"). Link "Área da equipe →" para `route('performance.index')`. `breakdown.tacos` (sempre `null`, decisão documentada no 97-02) resolvido: troca o rótulo por "Faturamento" quando `tacos` é `null`.
- **`NovasEmpresas.jsx`** (DASH-97-7): cards condicionais (retorna `null` quando `novas_empresas` está vazio, largura total quando há dados) das empresas com contrato Performance iniciado no mês (D3, Plan 97-02) — nome, grupo, status (Ramp-up/Atenção/Saudável, cor computada dentro do `.map()`), faturamento parcial, TACoS, responsáveis; card inteiro linka para `route('companies.show', id)`; rodapé "Listagem / cadastro →" para `companies.index`.
- **Estados reais** (DASH-97-7): `Admin.jsx` ganhou `isNavigating`/`navError` via `router.on('start'/'finish'/'error')` (mesmo padrão já usado em `resources/js/app.jsx`) — pill "Atualizando…" ao lado do badge D-1 Adman durante navegação de filtro; bloco "Não foi possível carregar os dados do recorte" + botão "Tentar novamente" (`router.reload()`) substituindo todo o conteúdo quando uma navegação falha de verdade. Nenhum toggle de demo — estados amarrados a eventos reais do Inertia.
- `tvMode` reavaliado e preservado sem mudanças (fora do escopo do redesign desta fase).

## Task Commits

Tasks 1 e 2 foram commitadas juntas (mesmo arquivo `Admin.jsx`, componentes fortemente entrelaçados — mesmo padrão adotado nos Plans 97-02/97-03 para tasks que compartilham o ponto de montagem):

1. **Task 1 + Task 2: ChartEvolucao + NpsRuimCarrossel + ScoreEquipe + NovasEmpresas + estados reais** - `5a9505b` (feat)

**Task 3 (checkpoint visual):** pendente — aguardando verificação humana (ver "Next Phase Readiness").

**Plan metadata:** (a ser commitado nesta etapa — docs: complete plan, após aprovação do checkpoint)

## Files Created/Modified
- `resources/js/Components/Dashboard/ChartEvolucao.jsx` (novo) — abas Faturamento/Margem, tooltip customizado, ReferenceDot Pico/Menor.
- `resources/js/Components/Dashboard/NpsRuimCarrossel.jsx` (novo) — carrossel de respostas NPS ruins.
- `resources/js/Components/Dashboard/ScoreEquipe.jsx` (novo) — lista de score 0-5 + breakdown pior→melhor.
- `resources/js/Components/Dashboard/NovasEmpresas.jsx` (novo) — cards condicionais de empresas novas.
- `resources/js/Pages/Dashboard/Admin.jsx` — monta os 4 componentes (ChartEvolucao no lugar da AreaChart antiga; nova linha `2.1fr/1fr` com NpsRuimCarrossel + ScoreEquipe; NovasEmpresas full-width condicional); adiciona `isNavigating`/`navError` (estados reais); prop `margin_chart`/`novas_empresas` adicionadas à assinatura.

## Decisions Made
- Ver `key-decisions` no frontmatter — resumo: reordenação local no `ScoreEquipe.jsx` (sem tocar o array do backend), `breakdown.tacos` null vira rótulo "Faturamento", widgets legados (BarChart/TACOS/tabela) mantidos por segurança de regressão, link do NPS ruim já filtra por `empresa_id`, erro via evento real do router (não toggle).

## Deviations from Plan

None - plano executado exatamente como escrito (Tasks 1 e 2). Nenhum auto-fix de Rule 1/2/3 foi necessário — o backend das Plans 97-01/97-02/97-03 já entregava todos os dados/props/rotas necessários para este plano.

## Issues Encountered

- **Concorrência de git com outra sessão (Fase 104, mesmo working tree):** após o primeiro `git add` + tentativa de commit, um `git commit`/reset da outra sessão (`c78cef0 feat(104-01): PortfolioController...`) coincidiu na janela de tempo e meus arquivos staged voltaram a aparecer como `??` (untracked) — sem perda de conteúdo (working tree preservado). Verifiquei `git show --stat c78cef0` (só tocou `PortfolioController.php`, nada meu) e `git diff --cached --name-only` ANTES do commit seguinte para confirmar que só meus 5 arquivos estavam staged. Nenhuma reescrita de histórico foi tentada. Commit final `5a9505b` verificado (`git show --stat`, sem deleções inesperadas via `git diff --diff-filter=D`).

## User Setup Required
None — nenhuma configuração externa necessária.

## Next Phase Readiness
- **Status: aguardando checkpoint humano (Task 3 do plano).** Todo o código automatizável está commitado (`5a9505b`) e `npm run build` roda verde; `php artisan test --filter=Dashboard` não regrediu (65 passed; a única falha, `PublicacaoDesempenhoRouteTest`, é pré-existente e não relacionada — já documentada no 97-03-SUMMARY.md).
- Fase 97 fica encerrada assim que o usuário aprovar o checkpoint visual (roteiro no retorno "CHECKPOINT REACHED" desta execução) — nenhuma mudança de código adicional é esperada a menos que o usuário aponte ajuste.
- `STATE.md`/`ROADMAP.md`/`REQUIREMENTS.md` ainda NÃO foram atualizados para reprovação da Fase 97 — isso deve ocorrer somente após a aprovação do checkpoint (evita marcar a fase como concluída antes do sign-off visual).

---
*Phase: 97-redesign-dashboard-mercado-livre*
*Completed: 2026-07-21 (pendente checkpoint humano)*

## Self-Check: PASSED

- FOUND: resources/js/Components/Dashboard/ChartEvolucao.jsx
- FOUND: resources/js/Components/Dashboard/NpsRuimCarrossel.jsx
- FOUND: resources/js/Components/Dashboard/ScoreEquipe.jsx
- FOUND: resources/js/Components/Dashboard/NovasEmpresas.jsx
- FOUND: resources/js/Pages/Dashboard/Admin.jsx
- FOUND: commit 5a9505b
