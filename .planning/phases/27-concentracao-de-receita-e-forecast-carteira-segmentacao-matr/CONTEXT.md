# Phase 27: Concentração de Receita e Forecast 90d

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 22 (wrapper: carteiraSegmentacao, carteiraHistorico, grantsExpirandoEm, ranking, sellerMetricasMensal) + Phase 24 (KpiCard) + Phase 25 (formato fmtMesAno)
**Milestone:** v8.0 — Integração Estratégica ECF Drive

## Goal

Criar aba `/concentracao` que entrega **3 análises estratégicas** que hoje não são possíveis no ECF Admin:

1. **Matriz de concentração** programa × cluster — visualiza onde está concentrado o GMV da carteira. Identifica que `20 MeliPro CPP geram R$ 12,2M = 28% da carteira`. Insight imediato: tier de prioridade comercial #1.
2. **Forecast 90 dias** — projeta receita dos próximos 3 meses combinando regressão linear da série mensal `/carteira/historico` com renovações esperadas via grants expirando em 90d.
3. **Vacas leiteiras silenciosas** — top 20 sellers (ranking GMV) cruzados com **coeficiente de variação** das métricas mensais. Identifica sellers com alta receita + baixa variância = retenção prioritária.

## Origem da fase

API-GUIDE.md §13 "Insights estratégicos" documenta:
- "20 sellers MeliPro CPP geram R$ 12,2M = 28% do GMV. Perder 1 MeliPro derruba ~R$ 600k/mês. Tier de prioridade comercial #1."
- "Sellers consistentes (não voláteis) com alto GMV = receita previsível. Foque retenção neles."
- "Combined com `/clientes/grants?expirando_em_dias=90` (renovações esperadas), dá pra montar forecast de receita dos próximos 3 meses."

Phase 24 (Painel Executivo) já cobre **GMV total + breakdowns 1D**. Phase 27 entrega **análises 2D + temporais** que destravam decisão executiva diferente.

## Decisões já travadas

### D-01: Acesso

**Só admin** (mesma decisão Phase 24 — visão estratégica). Consultores/mentores não veem nesta fase. Pode estender em futuro se houver pedido.

### D-02: Item na sidebar

Logo abaixo de "Painel Executivo" (ambos são `role:admin`), ícone `Target` ou `TrendingUp` do lucide-react. Label: "Concentração e Previsão".

### D-03: 3 seções, página única

Sem tabs. Tudo na mesma view scroll-friendly. Mais simples, mais navegável.

### D-04: Matriz programa × cluster

Endpoint: `carteiraSegmentacao('programa,cluster')` (já no wrapper Phase 22).

UI: **tabela heatmap** com linhas = programa (POLOS, CPP) e colunas = cluster (Core, MeliPro, Emerging, etc). Células coloridas pelo % do GMV (gradient amarelo/verde escala). Hover mostra: cluster + programa + sellers + R$ + %.

Abaixo da tabela: **top 5 células por concentração** ("CPP × Core = R$ 17,2M = 40% do GMV — perda de 1 derruba R$ XXk médio").

### D-05: Forecast 90d

**Algoritmo simples e transparente:**
1. Pega `carteiraHistorico(periodicidade='mensal')` — 12 últimos meses do GMV
2. Calcula regressão linear simples (y = a*x + b) sobre os meses fechados
3. Projeta meses M+1, M+2, M+3 baseado na reta
4. Cruza com `grantsExpirandoEm(90)` — calcula GMV em risco (sellers que vencem nesses 90d e podem não renovar)
5. Mostra **3 cenários**: otimista (renovação 100%), base (renovação 80%), pessimista (renovação 60%)

UI: gráfico de linha com 12 meses históricos + 3 meses projetados (linha tracejada). Tooltip mostra valor + intervalo de confiança.

Abaixo: card "GMV em risco nos próximos 90d" com lista de top 10 grants vencendo + R$ médio histórico de cada seller.

### D-06: Vacas leiteiras silenciosas

**Cálculo per-seller:**
1. Pega `ranking('tgmv_lc', 50)` — top 50 sellers por faturamento (limita escopo — 1238 sellers seria pesado)
2. Para cada seller, chama `sellerMetricasMensal(custId)` — 12 meses
3. Calcula **coeficiente de variação** = `desvio_padrao(tgmvLc) / media(tgmvLc)`
4. Ordena por CV crescente (menor CV = mais previsível = mais vaca leiteira)
5. Top 20 lista

**UI:** tabela com colunas: rank, nome empresa (link `/empresas/{id}/analise-ecf`), GMV médio mensal, coeficiente variação %, badge cluster/programa.

**Performance:** 50 chamadas wrapper (sellerMetricasMensal cache 1h) → cold cache ~30s primeira carga, depois quente. Aceitar — admin acessa raramente.

### D-07: Try/catch global

Padrão Phase 23/24/25/26. Se ECF Drive cair, exibe banner "Análise indisponível agora" e seções ficam vazias.

### D-08: Cache no controller

Não duplicar — wrapper já cacheia 1h (`carteiraSegmentacao`, `sellerMetricasMensal`, `ranking`) e 24h (`carteiraHistorico`). Controller faz chamadas direto.

### D-09: Cálculos no PHP, não no JS

Forecast (regressão linear) + coeficiente de variação são feitos no controller. Frontend só renderiza.

### D-10: Sem persistência

Tudo on-demand, ECF Drive é fonte da verdade. Sem migration/model.

## Endpoints consumidos (todos já no wrapper Phase 22)

| Método | Endpoint | Cache TTL |
|---|---|---|
| `carteiraSegmentacao('programa,cluster')` | `/carteira/segmentacao` | 1h |
| `carteiraHistorico('mensal')` | `/carteira/historico` | 24h |
| `grantsExpirandoEm(90)` | `/clientes/grants?expirando_em_dias=90` | 5min |
| `ranking('tgmv_lc', 50)` | `/sellers/ranking` | 1h |
| `sellerMetricasMensal($custId)` × 50 | `/sellers/{custId}/metricas/mensal` | 1h |

**Total: ~54 chamadas em cold cache; quase 0 em hot cache.**

## Success Criteria

1. **Nova rota** `/concentracao` → `ConcentracaoController::index` → `Inertia::render('Concentracao/Index')`. Middleware: `auth, verified, role:admin`.

2. **Controller** carrega em try/catch global:
   - Matriz programa × cluster
   - Histórico 12 meses + cálculo de regressão linear + projeção M+1/M+2/M+3 + 3 cenários
   - Grants expirando em 90d (filtrado por empresas da carteira nossa? OU todas? **Decisão: todos os grants ECF Drive — análise estratégica vê o todo**)
   - Ranking + sellerMetricasMensal para top 50 → calcula CV → top 20 vacas leiteiras

3. **Frontend** com 3 seções verticalmente:
   - **Seção 1**: Matriz heatmap + top 5 concentrações
   - **Seção 2**: Gráfico forecast + card "GMV em risco" + lista grants vencendo
   - **Seção 3**: Tabela vacas leiteiras com link para ficha 360° (Phase 25)

4. **Item na sidebar** "Concentração e Previsão" (só admin).

5. **Banner explicativo** no topo (mesmo padrão Phase 24):
   > "Análise estratégica da carteira inteira do parceiro ECF Drive. Identifica onde está concentrado o faturamento, prevê os próximos 90 dias e destaca lojistas estáveis para retenção prioritária."

6. **Empty states amigáveis** quando algum endpoint falhar (segue padrão Phase 23-25).

7. **Tooltips em cada seção** com `ⓘ` explicando metodologia:
   - Matriz: "% do faturamento total que cada cluster representa em cada programa"
   - Forecast: "Projeção baseada em regressão linear dos últimos 12 meses. Três cenários consideram taxas de renovação de grants entre 60% e 100%."
   - Vacas leiteiras: "Top 20 sellers com menor variação mensal de faturamento. Coeficiente de variação baixo indica receita previsível — candidatos a retenção prioritária."

8. **Testes Feature** mínimo 5: 200 admin / 302 guest / 403 consultor + mentor / props estruturadas / fallback erro.

## Mapa de arquivos

### Backend novos
- `app/Http/Controllers/ConcentracaoController.php`
- `app/Services/ForecastService.php` — extrai cálculo de regressão linear + cenários

### Backend modificados
- `routes/web.php` — adiciona rota `/concentracao` com middleware `role:admin`

### Frontend novos
- `resources/js/Pages/Concentracao/Index.jsx`
- `resources/js/Pages/Concentracao/components/MatrizHeatmap.jsx`
- `resources/js/Pages/Concentracao/components/ForecastChart.jsx`
- `resources/js/Pages/Concentracao/components/VacasLeiteirasTabela.jsx`

### Frontend modificados
- `resources/js/Layouts/AppLayout.jsx` — adiciona item "Concentração e Previsão"

### Testes
- `tests/Feature/Phase27/ConcentracaoControllerTest.php`
- `tests/Unit/ForecastServiceTest.php` — regressão linear + cenários

### Não tocar
- `EcfDriveService` (Phase 22)
- Painel Executivo / Alertas / Análise por Empresa (independentes)

## Pitfalls antecipados

1. **50 chamadas `sellerMetricasMensal` em cold cache** — ~30s primeira carga. Mitigação: cache wrapper 1h cobre, deploy madrugada pra primeiro acesso já ser quente. Documentar no SUMMARY.

2. **Regressão linear ingênua falha em séries não-lineares** (ex: sazonalidade Black Friday) — aceitar como limitação MVP. Banner explicativo: "Projeção linear simples — não considera sazonalidade." Phase futura pode adicionar ARIMA/Prophet.

3. **GMV em risco assume renovação binária** — na vida real é gradual. 3 cenários (60/80/100%) cobrem variação. Documentar.

4. **Top 50 ranking pode excluir vacas leiteiras médio-pequenas** — explicit trade-off. Phase futura: ampliar pra top 100 com paginação.

5. **`sellerMetricasMensal` retorna shape paginado `{data, total, page, limit}`** — mesmo bug que peguei na Phase 25 Plano 03 e Phase 25 Plano 05. **Lembrete pro executor: extrair `.data` antes do slice.**

6. **`metricasMensais[].tgmvLc` vem como STRING** — `parseFloat` defensivo em cálculo de variância.

## Não-objetivos

- Forecast com ARIMA/Prophet (limitação MVP)
- Forecast por seller individual (só agregado)
- Comparação ano-a-ano
- Drilldown na célula do heatmap (Phase futura — abrir ranking de sellers daquela célula)
- Export PDF/CSV
- Alertas automáticos quando concentração exceder limiar (Phase 26 webhooks pode habilitar futuramente)

## Cross-cutting constraints

- pt-BR em tudo
- `npm run build` no fim
- Sem deploy automático (autorização permanente cobre)
- Try/catch global
- Reusar shadcn + recharts + lucide
- KpiCard da Phase 24 quando aplicável
- ECF Drive fonte da verdade

## Referências

- API-GUIDE.md §6 (Carteira), §5 (Sellers), §13 (Insights estratégicos)
- Phase 22 wrapper — endpoints prontos
- Phase 24 KpiCard, HistoricoChart pattern
- Phase 25 EmpresaAnaliseEcf (link de vacas leiteiras)
- Memory: `feedback_lean_planning`, `feedback_gsd_language_pt_br`, `feedback_autorizacao_permanente_deploy`

## Memory persistente relevante

- Lean planning (pular discuss/research)
- pt-BR
- Autorização permanente deploy
- Acertividade — algoritmos transparentes, sem caixa preta
- Praticidade — admin abre, vê 3 análises, decide
