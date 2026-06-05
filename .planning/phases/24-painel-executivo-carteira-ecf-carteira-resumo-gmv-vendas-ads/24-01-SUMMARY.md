---
phase: 24-painel-executivo-carteira-ecf-carteira-resumo-gmv-vendas-ads
plan: 01
subsystem: painel-executivo
tags: [ecf-drive, dashboard, recharts, inertia, admin-only, kpi-cards, historico, breakdowns]
dependency_graph:
  requires:
    - "22-01: EcfDriveService::carteiraResumo + carteiraHistorico + carteiraBreakdown (wrapper)"
    - "23-01: Padrão de aba consumindo ECF Drive (try/catch global, item sidebar)"
  provides:
    - "PainelExecutivoController::index com 5 chamadas ao ECF Drive + try/catch global"
    - "Rota GET /painel-executivo (auth, verified, role:admin)"
    - "PainelExecutivo/Index.jsx com 3 seções (KPI cards + histórico + breakdowns)"
    - "KpiCard.jsx reutilizável com delta MoM colorido (TrendingUp/Down/Minus)"
    - "HistoricoChart.jsx recharts LineChart duplo eixo Y"
    - "BreakdownTabs.jsx 4 tabs com PieChart + tabela"
    - "Item 'Painel Executivo' na sidebar (topo, só admin)"
  affects:
    - "AppLayout.jsx (item de menu adicionado)"
    - "routes/web.php (nova rota)"
tech_stack:
  added: []
  patterns:
    - "try/catch Throwable global no controller (mesmo padrão Phase 23)"
    - "Props Inertia estruturadas com fallback vazio em erro"
    - "Helpers locais de formatação BRL/contagem abreviada com Intl.NumberFormat"
    - "Recharts duplo eixo Y (yAxisId left/right)"
    - "Tabs client-side sem refetch (useState + 4 datasets pré-carregados)"
key_files:
  created:
    - app/Http/Controllers/PainelExecutivoController.php
    - resources/js/Pages/PainelExecutivo/Index.jsx
    - resources/js/Pages/PainelExecutivo/components/KpiCard.jsx
    - resources/js/Pages/PainelExecutivo/components/HistoricoChart.jsx
    - resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx
    - tests/Feature/Phase24/PainelExecutivoControllerTest.php
  modified:
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx
decisions:
  - "D-01: role:admin single — apenas admin acessa (sem consultor/mentor)"
  - "D-02: 5 chamadas ECF Drive sequenciais (cache do wrapper absorve latência)"
  - "D-03: try/catch Throwable global — fallback props vazias + erro pt-BR"
  - "D-04: helpers locais fmtMoneyShort/fmtCountShort/fmtPercentSigned/fmtMesAno (não tocar lib/utils.js)"
  - "D-06: 4 breakdowns carregados de uma vez — troca de tab sem refetch"
  - "D-07: tab default 'programa' (dimensão estratégica mais lida)"
  - "D-08: localidade tabela slice(0,20) + pizza top10+Outros"
  - "D-09: Painel Executivo no topo da sidebar, entre Dashboard e Carteira"
  - "D-10: excludeRoles (não permission key) — admin entra porque NÃO está em excludeRoles"
  - "D-11: recharts duplo eixo Y (GMV esq amarelo, Sellers dir branco)"
metrics:
  duration: "~35 min"
  completed_date: "2026-06-05"
  tasks_completed: 6
  files_changed: 8
  tests_added: 8
---

# Phase 24 Plan 01: Painel Executivo Carteira ECF — SUMMARY

**One-liner:** Dashboard executivo com 5 chamadas ao ECF Drive, 8 KPI cards MoM coloridos, gráfico histórico 12 meses com duplo eixo Y, e 4 tabs de breakdown por dimensão.

## O que foi entregue

### Backend (W1)

**`PainelExecutivoController`** — controller novo com:
- Construtor DI `EcfDriveService $ecf`
- Método `index()` com try/catch Throwable global (padrão Phase 23)
- 5 chamadas sequenciais: `carteiraResumo()` + `carteiraHistorico('mensal')` + 4× `carteiraBreakdown(programa|frete|cluster|localidade)`
- Fallback: `resumo=null`, `historico=[]`, `breakdowns` com 4 chaves vazias, `erro='Não foi possível buscar dados do painel agora. Tente em alguns segundos.'`
- `report($e)` no catch para logging

**`routes/web.php`** — 1 rota nova:
```
GET /painel-executivo → PainelExecutivoController@index (auth, verified, role:admin)
```

### Frontend (W2)

**`PainelExecutivo/Index.jsx`** — página com 3 seções:
1. Grid responsivo 8 KpiCard (1/2/4 colunas mobile/tablet/desktop)
2. HistoricoChart 12 meses
3. BreakdownTabs 4 dimensões
+ Banner de erro condicional pt-BR

**`components/KpiCard.jsx`** — card reutilizável:
- `fmtMoneyShort`: BRL abreviado (R$ 42,8M / R$ 763k)
- `fmtCountShort`: contagem abreviada (1,24k / 357k)
- `fmtPercentSigned`: percentual com sinal explícito (+15,70% / -11,74%)
- Ícones `TrendingUp` (verde), `TrendingDown` (vermelho), `Minus` (cinza) por sinal do delta
- Trata null gracefully: exibe "—"

**`components/HistoricoChart.jsx`** — recharts LineChart:
- Duplo eixo Y: GMV (esq, #ffe600) + Sellers Ativos (dir, #ffffff80)
- `fmtMesAno('202605')` → `'mai/26'`
- Tooltip customizado pt-BR com valores formatados
- Empty state pt-BR

**`components/BreakdownTabs.jsx`** — 4 tabs:
- Mapa `itemKey`: programa='programa', frete='canal', cluster='cluster', localidade='localidade'
- PieChart recharts com 8 cores + Localidade agrega top10+'Outros'
- Tabela Localidade limitada a `slice(0,20)`
- Troca de tab sem refetch (D-06)

**`AppLayout.jsx`** — item adicionado:
```js
{ label: 'Painel Executivo', routeName: 'painel-executivo.index', page: 'PainelExecutivo', icon: LineChart, excludeRoles: ['consultor','mentor','publicador','analista','gestor','lider'] }
```
Posicionado imediatamente após Dashboard. Ícone `LineChart` (lucide-react).

### Testes (W3)

**`tests/Feature/Phase24/PainelExecutivoControllerTest.php`** — 8 testes (71 assertions):

| Teste | Status |
|-------|--------|
| test_index_admin_retorna_200_com_props_estruturadas | VERDE |
| test_index_guest_redireciona_para_login_302 | VERDE |
| test_index_consultor_retorna_403 | VERDE |
| test_index_mentor_retorna_403 | VERDE |
| test_index_publicador_retorna_403 | VERDE |
| test_index_props_estruturadas_caminho_feliz | VERDE |
| test_index_fallback_com_estrutura_vazia_quando_ecf_lanca | VERDE |
| test_index_chama_5_endpoints_ecf_corretos | VERDE |

## Decisões executadas vs planejadas

Todas as decisões D-01 a D-14 do PLAN foram seguidas conforme especificado. Nenhuma desvio de decisão.

## Deviations from Plan

None — o plano foi executado exatamente como escrito.

## Riscos materializados / mitigados

| Risco | Status |
|-------|--------|
| R-01: Cold cache ~5-10s primeira carga | Documentado no controller (comment). Cache do wrapper absorve nas subsequentes. |
| R-02: Duplo eixo Y pode confundir leitor | Mitigado: tooltip explícito + cores distintas (amarelo/branco). |
| R-03: Shape breakdown varia por dimensão (itemKey) | Mitigado: mapa TABS com itemKey correto por dimensão. |
| R-04: carteiraHistorico pode retornar <12 meses | Mitigado: gráfico desenha o que vier (sem preenchimento artificial). |
| R-05: Cache 24h historico pode ser "antigo" | Aceito como trade-off do wrapper. |
| R-06: 8 KPI cards mobile scroll longo | Mitigado: grid responsivo (1/2/4 colunas). |

## Build

`npm run build` concluído sem erros nem warnings novos. Chunks gerados:
- `KpiCard-7q8GooBf.js` (1,63 kB)
- `HistoricoChart-DPRLU1IU.js` (2,69 kB)
- `BreakdownTabs-C0i6giMv.js` (3,91 kB)

## Próximo passo da v8.0

**Phase 25 — Análise por Empresa (Ficha 360 Sellers)**: drilldown individual por empresa — KPIs, histórico de performance, signals e métricas de ADS por seller específico. Consome `/sellers/{id}` e `/sellers/{id}/historico` do ECF Drive.

## Known Stubs

Nenhum. Todos os 8 KPI cards, gráfico histórico e 4 tabs de breakdown consomem dados reais via ECF Drive. O campo `sublabel` no KpiCard está declarado mas não utilizado nesta phase — é extensão prevista para reutilização futura (não é stub de funcionalidade desta phase).

## Threat Flags

Nenhuma superfície nova introduzida além da rota `/painel-executivo` já coberta pelo middleware `role:admin`.

## Self-Check: PASSED

Arquivos criados verificados:
- `app/Http/Controllers/PainelExecutivoController.php` — FOUND
- `resources/js/Pages/PainelExecutivo/Index.jsx` — FOUND
- `resources/js/Pages/PainelExecutivo/components/KpiCard.jsx` — FOUND
- `resources/js/Pages/PainelExecutivo/components/HistoricoChart.jsx` — FOUND
- `resources/js/Pages/PainelExecutivo/components/BreakdownTabs.jsx` — FOUND
- `tests/Feature/Phase24/PainelExecutivoControllerTest.php` — FOUND

Commits verificados:
- `86a0516` — feat(24-01): PainelExecutivoController + rota /painel-executivo
- `5d6fdf1` — feat(24-01): PainelExecutivo/Index.jsx com 3 secoes + erro
- `c00e66f` — feat(24-01): KpiCard reutilizavel com delta MoM colorido
- `03c8fd0` — feat(24-01): HistoricoChart com duplo eixo Y + 12 meses
- `8ec5395` — feat(24-01): BreakdownTabs + AppLayout item Painel Executivo + build
- `8d8e6f6` — test(24-01): PainelExecutivoControllerTest 8 testes verdes

Testes Phase24: 8 passados / 71 assertions / 0 falhas.
