---
phase: 27-concentracao-de-receita-e-forecast-carteira-segmentacao-matr
plan: 01
subsystem: ui, api
tags: [ecf-drive, forecast, recharts, regressao-linear, heatmap, inertia, laravel]

# Dependency graph
requires:
  - phase: 22-ecf-drive-service
    provides: "EcfDriveService com 5 wrappers consumidos: carteiraSegmentacao, carteiraHistorico, grantsExpirandoEm, ranking, sellerMetricasMensal"
  - phase: 24-painel-executivo
    provides: "KpiCard reutilizável + pattern recharts + AppLayout NAV_ITEMS admin"
  - phase: 25-empresa-analise-ecf
    provides: "lib/ecfDriveLabels.js (CLUSTER_LABELS/PROGRAMA_LABELS/traduzirItem) + rota empresas.analise-ecf + lookup company por cust_id"
provides:
  - "Aba /concentracao — 3 análises estratégicas: matriz heatmap programa×cluster + forecast 90d + vacas leiteiras silenciosas"
  - "ForecastService — 3 funções puras estáticas: regressaoLinear, projetar, coeficienteVariacao"
  - "ConcentracaoController::show — 5 chamadas EcfDriveService + cálculos PHP + lookup batch Company"
  - "MatrizHeatmap.jsx — tabela heatmap com gradient amarelo + top 5 concentrações"
  - "ForecastChart.jsx — recharts ComposedChart 12m+3m + 3 cenários + card GMV em risco"
  - "VacasLeiteirasTabela.jsx — top 20 sellers menor CV com link para ficha 360°"
  - "Rota concentracao.index (GET /concentracao) middleware role:admin"
  - "AppLayout item 'Concentração e Previsão' (TrendingUp, só admin)"
affects:
  - phase-28  # Relatório Mensal Executivo — pode consumir dados do forecast
  - sidebar-admin  # item novo adicionado ao NAV_ITEMS

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ForecastService — service PHP puro (sem DI/cache/HTTP) para cálculos matemáticos testáveis em isolation"
    - "Loop 50 sellerMetricasMensal com try por seller — 1 falha não derruba o lote"
    - "Gradient rgba inline style para heatmap (arbitrary value — sem classes discretas Tailwind)"
    - "ComposedChart recharts com Line + Area para visualizar intervalo de confiança de forecast"

key-files:
  created:
    - app/Services/ForecastService.php
    - app/Http/Controllers/ConcentracaoController.php
    - resources/js/Pages/Concentracao/Index.jsx
    - resources/js/Pages/Concentracao/components/MatrizHeatmap.jsx
    - resources/js/Pages/Concentracao/components/ForecastChart.jsx
    - resources/js/Pages/Concentracao/components/VacasLeiteirasTabela.jsx
    - tests/Unit/ForecastServiceTest.php
    - tests/Feature/Phase27/ConcentracaoControllerTest.php
  modified:
    - routes/web.php
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "ForecastService é instância (não estática) — DI no controller facilita mock nos testes Feature"
  - "50 chamadas sellerMetricasMensal sequenciais com try por seller (wrapper cacheia 1h — cold cache ~30s aceito)"
  - "Lookup company batch em 1 query única (whereIn adman_account_id OR ml_store_id) — sem N+1"
  - "Gradient rgba(255,230,0, pct/40) por célula do heatmap — arbitrary value via inline style"
  - "GMV-em-risco distribuído uniformemente nos 3 meses projetados (sem dado de quando exatamente vence)"
  - "Forecast 3 cenários: otimista (100%), base (80%), pessimista (60%) de renovação de grants"
  - "Clamp max(0, valor) em cenários com slope negativo (R-06 — R$ negativo seria absurdo)"
  - "Extração de .data ANTES de processar em todo shape paginado (pitfall recorrente Phase 25-03/05)"

patterns-established:
  - "ForecastService puro: sem imports externos, testável via TestCase sem RefreshDatabase"
  - "ConcentracaoController: try/catch global retorna props vazias + erro pt-BR (não quebra pageload)"

requirements-completed:
  - ECF-27-01
  - ECF-27-02
  - ECF-27-03
  - ECF-27-04
  - ECF-27-05
  - ECF-27-06
  - ECF-27-07
  - ECF-27-08
  - ECF-27-09
  - ECF-27-10
  - ECF-27-11
  - ECF-27-12

# Metrics
duration: ~90min
completed: 2026-06-06
---

# Phase 27 Plan 01: Concentração de Receita e Forecast 90d — Summary (Parcial W1-W3)

**Aba `/concentracao` com matriz heatmap programa×cluster + forecast 90d (regressão linear + 3 cenários) + top 20 vacas leiteiras por coeficiente de variação — 17 testes verdes, build OK, aguardando smoke visual W4**

## Performance

- **Duration:** ~90 min
- **Started:** 2026-06-05T00:00:00Z
- **Completed (parcial):** 2026-06-06 (W4 pendente)
- **Tasks:** 8 de 9 concluídas (W4 checkpoint humano pendente)
- **Files modificados:** 10 (8 criados + 2 modificados)

## Accomplishments

- `ForecastService` com 3 funções puras (regressaoLinear + projetar + coeficienteVariacao) — testável em isolation, cast defensivo string→float
- `ConcentracaoController::show` com 5 chamadas EcfDriveService, cálculos PHP, lookup batch Company (sem N+1), try/catch global
- 4 componentes JSX: Index.jsx + MatrizHeatmap + ForecastChart + VacasLeiteirasTabela
- Sidebar item "Concentração e Previsão" visível apenas para admin (TrendingUp)
- 17 testes verdes: 10 Unit (ForecastServiceTest) + 7 Feature (ConcentracaoControllerTest)
- Build Vite OK sem erros — todos os 4 novos componentes compilados

## Task Commits

| Task | Nome | Hash | Tipo |
|------|------|------|------|
| W1-T1 | ForecastService | `1e7a8e7` | feat |
| W1-T2 | ConcentracaoController + rota | `365733c` | feat |
| W2-T1 | Concentracao/Index.jsx | `3c7b097` | feat |
| W2-T2 | MatrizHeatmap.jsx | `4267903` | feat |
| W2-T3 | ForecastChart.jsx | `92faf97` | feat |
| W2-T4 | VacasLeiteirasTabela + AppLayout + build | `483eece` | feat |
| W3-T1 | ForecastServiceTest (10 casos) | `da8ffd5` | test |
| W3-T2 | ConcentracaoControllerTest (7 casos) | `4c93199` | test |

## Files Created/Modified

- `app/Services/ForecastService.php` — 3 funções puras: regressaoLinear (mínimos quadrados + R²), projetar (N pontos a partir de startX), coeficienteVariacao (sigma/média adimensional)
- `app/Http/Controllers/ConcentracaoController.php` — 9 etapas no try + fallback catch com props vazias + erro pt-BR
- `routes/web.php` — rota GET /concentracao, nome concentracao.index, middleware auth+verified+role:admin
- `resources/js/Pages/Concentracao/Index.jsx` — header + banner + 3 seções com tooltips ⓘ
- `resources/js/Pages/Concentracao/components/MatrizHeatmap.jsx` — tabela heatmap + gradient rgba + top 5
- `resources/js/Pages/Concentracao/components/ForecastChart.jsx` — ComposedChart Line+Area + 3 KpiCards + top 10 grants
- `resources/js/Pages/Concentracao/components/VacasLeiteirasTabela.jsx` — 20 linhas + link condicional + badges traduzidos
- `resources/js/Layouts/AppLayout.jsx` — TrendingUp importado + entry "Concentração e Previsão"
- `tests/Unit/ForecastServiceTest.php` — 10 casos (regressão + projeção + CV)
- `tests/Feature/Phase27/ConcentracaoControllerTest.php` — 7 casos (200+302+403×3+fallback+snake_case)

## Decisions Made

- **ForecastService como instância (não estática):** DI no construtor facilita mock via `$this->mock(ForecastService::class)` nos testes Feature. Funções permanecem puras (sem estado interno).
- **Loop sequencial 50 sellerMetricasMensal:** Wrapper Phase 22 cacheia 1h — em hot cache ~250ms total, cold cache ~30s. Try individual por seller: 1 falha não derruba o lote.
- **Lookup batch Company único:** `whereIn(adman_account_id) OR whereIn(ml_store_id)` — 1 query, zero N+1. Padrão Phase 23/25.
- **Gradient rgba(255,230,0, pct/40):** Divisor 40 → célula com 40%+ fica opaque. Arbitrary inline style para flexibilidade total (R-09 PLAN).
- **GMV-em-risco distribuído uniformemente:** Sem dado de timing dos grants → distribuição uniforme nos 3 meses. Documentado para refinamento futuro.
- **Cast defensivo em todo shape paginado:** `$resp['data'] ?? []` SEMPRE antes de iterar (pitfall recorrente Phase 25-03 e 25-05).

## Deviations from Plan

Nenhum — plano executado exatamente como especificado.

## Issues Encountered

Nenhum.

## Stubs Conhecidos

Nenhum — todos os componentes consomem dados reais do ConcentracaoController via props Inertia.

## Threat Surface Scan

Nenhuma superfície nova fora do modelo de ameaças:
- Rota `/concentracao` protegida por `role:admin` (mesmo padrão Painel Executivo)
- Sem endpoints JSON paralelos
- Sem acesso direto a dados de usuário — apenas leitura de dados ECF Drive

## W4 Pendente

O checkpoint W4 (smoke visual em prod) ainda não foi executado. Após aprovação do usuário:
1. Executar deploy (`deploy.sh` ou `deploy_parcial.sh`)
2. Admin abre `https://admin.ecfconsultoria.com.br/concentracao`
3. Validar 3 seções + tooltips + links + edge case offline
4. Completar SUMMARY e fechar Phase 27

## Self-Check: PASSED

- `app/Services/ForecastService.php` — ENCONTRADO
- `app/Http/Controllers/ConcentracaoController.php` — ENCONTRADO
- `resources/js/Pages/Concentracao/Index.jsx` — ENCONTRADO
- `resources/js/Pages/Concentracao/components/MatrizHeatmap.jsx` — ENCONTRADO
- `resources/js/Pages/Concentracao/components/ForecastChart.jsx` — ENCONTRADO
- `resources/js/Pages/Concentracao/components/VacasLeiteirasTabela.jsx` — ENCONTRADO
- `tests/Unit/ForecastServiceTest.php` — ENCONTRADO
- `tests/Feature/Phase27/ConcentracaoControllerTest.php` — ENCONTRADO
- Commits `1e7a8e7`, `365733c`, `3c7b097`, `4267903`, `92faf97`, `483eece`, `da8ffd5`, `4c93199` — ENCONTRADOS

---
*Phase: 27-concentracao-de-receita-e-forecast-carteira-segmentacao-matr*
*Parcial: 2026-06-06 — aguardando W4*
