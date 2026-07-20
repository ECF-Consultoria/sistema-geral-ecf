# Phase 97: Redesign da Dashboard Mercado Livre — Context

**Gathered:** 2026-07-17
**Status:** Ready for planning
**Source:** Mockup do usuário (`Dashboard Mercado Livre (standalone).html`, extraído/descomprimido) + decisões 2026-07-17 + mapeamento da dashboard atual (Explore).

<domain>
## Phase Boundary

Reformulação total da dashboard do setor Mercado Livre (`DashboardController::adminDashboard` → `resources/js/Pages/Dashboard/Admin.jsx`), seguindo o mockup do usuário. Objetivos: filtros práticos/combinados/explícitos que **realmente propagam a TODOS os widgets**, informações úteis ao negócio, gráficos interativos, e links de cada resumo para a área completa do sistema.

**FORA desta fase:** módulo MLB/Publicações (`/mlb/*` — outra coisa); dashboard de carteira do Analista/Estrategista (`Performance/Dashboard`, servido por delegação quando o user é consultor/mentor não-líder — permanece como está); criar novas fontes de dados (usa `adman_metrics`/services existentes).

</domain>

<decisions>
## Decisões travadas (usuário, 2026-07-17)

- **D1 — Score da equipe:** usar a NOTA OFICIAL 0–5 do `DesempenhoScoreService::computeCached()` (`nota_final`, a mesma que vale bônus), NÃO um score composto ad-hoc do mock. Exibir com barra + breakdown (carteira, NPS, margem, TACoS).
- **D2 — Escopo do componente:** REFATORAR o `Dashboard/Admin.jsx` compartilhado (serve `/dashboard` genérica E `/dashboard/mercadolivre`). O novo design é data-driven e vale para as duas rotas (genérica = todo Performance; ML = filtro `marketplace='meli'`). **Obrigatório não regredir a `/dashboard` genérica** e **corrigir o bug do `marketplace` sumindo nos filtros** (ver Riscos).
- **D3 — "Empresa nova no mês":** início de `contratos_servico` ativo no mês corrente (quando a ECF começou a atender), NÃO `companies.created_at`.
- **D4 — Abas do gráfico de evolução:** apenas Faturamento + Margem (como no mock).

## Design do mockup (o que construir)

**Cabeçalho:** título "Mercado Livre" + subtítulo "Dashboard operacional do setor · ECF"; indicador "Atualizado há X min" (de `adman_last_sync`).

**Filtros (repensados — resolve a reclamação central):**
- Painel COLAPSÁVEL (botão "Filtros" com contador de chips).
- Padrão RASCUNHO → APLICAR: alterar selects muda um `draft`; só ao clicar "Aplicar" o recorte vale (`applied`). Botão "Aplicar" destaca (amarelo) quando há mudança pendente.
- CHIPS de filtros ativos abaixo, removíveis individualmente (×) + "Limpar tudo".
- Estado vazio de filtro: "Nenhum — mostrando todo o setor (últimos 30 dias)".
- Filtros: Período (7/30/60/90, default 30), Empresa, Grupo de empresas, Estrategista, Analista.
- **TODOS os widgets consomem o recorte aplicado** — sem exceção (ver Riscos: hoje 2 widgets ignoram).

**4 KPIs** (cada um: label, link↗ para área completa, valor, delta vs PERÍODO ANTERIOR, sub):
1. Faturamento total — `SUM(revenue)` do recorte; delta % vs janela anterior; link → Performance por empresa (área `#detalhe` / `companies.index` ou seção da própria dash).
2. Margem contrib. média — média PONDERADA por faturamento de `contribution_margin_pct`; delta em pontos percentuais (pp); link → relatório de margem.
3. NPS médio — média das notas (dimensão empresa); sub "N respostas · M ruins"; link → `nps.index`.
4. Empresas ativas — contagem do recorte; sub "+N novas no mês"; link → `companies.index`.

**Gráfico "Evolução no período":** abas Faturamento/Margem; série DIÁRIA somada sobre o recorte; SVG interativo com hover (tooltip data/valor/"+X% vs média"), marcações Pico/Menor. Subtítulo: "{métrica} {unidade} · {período} · {N empresas | nome da empresa}".

**Linha (2.1fr / 1fr):**
- **"NPS ruim"** (esquerda): carrossel horizontal das respostas com nota ≤5 no recorte — empresa, data, nota (colorida), comentário, analista, estrategista, link "Abrir NPS completo →". Badge com contagem, setas ‹ ›. "Ver respostas completas →". Empty: "Nenhuma nota ruim… todas em 6 ou acima." **Respeitar `invalidated_at` (Fase 96) — resposta invalidada NÃO aparece.**
- **"Score da equipe"** (direita): lista compacta por pessoa (analista + estrategista das empresas do recorte) — nome, papel, nota 0–5 (D1), barra, e linha "carteira · NPS · Margem · TACoS". Ordenar pior→melhor (surfacing quem precisa de atenção). Link "Área da equipe →" (`performance.index`).

**"Novas empresas no mês"** (largura total, condicional): cards das empresas com contrato iniciado no mês (D3) — nome, grupo, status (Atenção/Ramp-up/Saudável), fat. parcial, TACoS, responsáveis; link "Listagem / cadastro →" e card→`companies.show`.

**Estados reais:** carregando (skeletons), vazio, erro (com "Tentar novamente"). No mock há um toggle de estados só para DEMO — no sistema real são estados de verdade (loading via Inertia, empty quando recorte sem dados, error se a base falhar), SEM toggle manual.

## Claude's Discretion
- Cálculo das janelas período-anterior: `adman_metrics` diário permite `SUM` da janela atual (últimos N dias) e da anterior (N dias antes) — implementar no controller.
- Reusar recharts vs SVG manual do mock: preferir recharts (padrão do projeto) desde que preserve o hover/tooltip/marcações; SVG manual aceitável se recharts não der o mesmo controle.
- Extrair `KpiCard`/`FiltrosDashboard`/`ChartCard` como componentes reutilizáveis vs inline — decidir no plano; hoje `KpiCard` é local ao arquivo.
- "Grupo de empresas": confirmar a fonte real (a dash atual já tem `group_id`/`grupos_list`).
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Backend (fonte dos dados + onde mexer)
- `app/Http/Controllers/DashboardController.php` — `mercadolivre()` (~149), `index()` (~68), `adminDashboard()` (~233, monta `Inertia::render('Dashboard/Admin', ...)` ~843). Props atuais: `stats`, `revenue_chart`, `tacos_chart`, `performance_equipe` (~872), `companies_performance` (~897), `adman_last_sync` (~917), `nps_pendentes`, `filters`.
- `app/Services/AdmanService.php` — faturamento/ads/tacos (cache + fallback `SUM` em `adman_metrics`: `revenue`, `ad_spend`, `tacos`, `contribution_margin_pct`).
- `app/Services/DesempenhoScoreService.php` — `computeCached($user, $mes)` → `nota_final` 0–5 (D1). ATENÇÃO ao cache (chave `desempenho.compute.v4`).
- `app/Models/NpsSurvey.php` / `NpsResponse.php` / `NpsScoreCalculator` — NPS por empresa; respostas ruins (≤5); **`scopeValida()` da Fase 96** para excluir invalidadas.
- Universo "setor Mercado Livre": `contratos_servico.ativo=true` + `servicos.setor='performance'`, `whereDoesntHave('mlbEmpresa')`, + `companies.marketplace='meli'` na rota ML. `contratos_servico` também é a fonte de "empresa nova no mês" (D3).

### Frontend
- `resources/js/Pages/Dashboard/Admin.jsx` — componente a refatorar (D2). `KpiCard` local (~56), `applyFilter` (~124 — BUG do marketplace), filtros (~294), widgets (KPIs ~372, gráfico ~380, `performance_equipe` ~408, `tacos_chart` ~510, `companies_performance` ~533, `nps_pendentes` ~575), modo TV (~184).
- Design system: tokens `ecf-*` (`tailwind.config.js`), `.card-ecf` (`app.css`), `cn()`/`formatCurrency`/`formatPercent` (`@/lib/utils`), recharts, `SourceBadge` (`Components/ui/source-badge.jsx`), `NpsPendingWidget.jsx` (molde de "resumo + link").

### Rotas para linkar (Ziggy `route()`)
`performance.index` (equipe), `portfolio.show`/`portfolio.own` (carteira), `companies.index`/`companies.show`, `nps.index`, `sugadores.index`, `mercadolivre.dashboard`, `dashboard`.

### Mockup extraído (referência de layout/lógica)
- `scratchpad/template.txt` (HTML+lógica do mock, descomprimido) — `buildData`/`filtered`/`windows`/`chartSeries`/`buildChart`/`renderVals`.
</canonical_refs>

<specifics>
## Riscos / pontos de atenção (achados no mapeamento)

1. **BUG a corrigir — filtro apaga o `marketplace`:** `applyFilter` usa `route('dashboard')` hardcoded e `filters` do backend não inclui `marketplace`. Ao filtrar em `/dashboard/mercadolivre`, cai na `/dashboard` genérica perdendo o recorte ML. O redesign DEVE preservar `marketplace` na navegação de filtros.
2. **Widgets que HOJE ignoram os filtros (reclamação central do usuário):**
   - `performance_equipe` (`DashboardController` ~759-817): query não aplica `company_id`/`consultor_id`/`estrategista_id`/`group_id`/`marketplace`. No redesign, "Score da equipe" DEVE respeitar o recorte.
   - `nps_pendentes` via `NpsPendingService::forCarteira()` (~841): para admin busca TODAS as companies; existe `forCompanies($companies)` pronto que aceita o recorte filtrado — usar esse.
3. **Não regredir a `/dashboard` genérica** (mesmo componente, D2) — parametrizar título/marketplace.
4. **Fase 96 (invalidação):** a lista "NPS ruim" e o NPS médio DEVEM usar `scopeValida()`.
5. **Regra do projeto:** zero jargão sem explicação; comentários pt-BR; `npm run build` ao final; tokens `ecf-*`; pitfall Rollup (flags computadas dentro do `.map()`).

</specifics>

<deferred>
## Deferred
- Modo TV do mock (se o usuário quiser depois — hoje já existe um `tvMode` no Admin.jsx que precisa continuar funcionando ou ser reavaliado).
- Métricas TACoS/ADS como abas do gráfico (D4 fechou em Faturamento+Margem; TACoS/ADS ficam disponíveis nos dados se quiser reabrir).
</deferred>

---

*Phase: 97-redesign-dashboard-mercado-livre*
*Context gathered: 2026-07-17*
