---
phase: 89-carteira-individual-rendercarteiraprofissional-por-contexto-
plan: 01
subsystem: api
tags: [laravel, inertia, react, portfolio, carteira, nps-multi-modelo, adman]

# Dependency graph
requires:
  - phase: 88-camada-de-contexto-carteiracontextservice
    provides: "CarteiraContextService::forUser()/contadores() — fonte de vinculos por servico/setor/elegibilidade financeira, testado 12/12"
provides:
  - "renderCarteiraProfissional() consumindo CarteiraContextService em vez de $user->companies()"
  - "payload empresas[].servicos — 1 entrada por vinculo de servico (Performance/Shopee separados)"
  - "dedup financeiro por company_id unico elegivel (CART-04/05 fechados)"
  - "ad_spend/tacos adicionados ao payload da carteira individual, ja filtrados por elegibilidade"
  - "badges de vinculo + estado 'sem fonte financeira' em Portfolio/AdminCarteira.jsx"
affects: [90-carteiras-consolidadas, 91-desempenho-unico-elegibilidade]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dedup financeiro por ->unique() em company_id (nunca por vinculo) — AdmanMetric e por-EMPRESA"
    - "Flag booleana (temFonteFinanceira) computada DENTRO do callback do .map() em JSX — evita bug de escopo Rollup"

key-files:
  created:
    - tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php
    - tests/Feature/V16/CarteiraIndividualContextoTest.php
  modified:
    - app/Http/Controllers/PortfolioController.php
    - resources/js/Pages/Portfolio/AdminCarteira.jsx

key-decisions:
  - "empresas_sem_margem conta SO empresas elegiveis com margem null (correcao do plan-checker) — Shopee-only nao infla o contador"
  - "renderPortfolio() (auto-visualizacao via /admin/users/{proprio_id}/portfolio) fica com o bug antigo — fora de escopo desta fase (Pitfall 2/6 do RESEARCH), candidato a follow-up"
  - "filtro Todos/Performance/Shopee + contadores de topo ficam pra Fase 90 (CART-07) — payload ja nasce pronto (servicos[]), UI completa depois"

requirements-completed: [CART-01, CART-02, CART-03, CART-04, CART-05]

# Metrics
duration: ~35min
completed: 2026-07-16
---

# Phase 89 Plan 01: Carteira individual por contexto de serviço Summary

**`renderCarteiraProfissional()` refatorada para consumir `CarteiraContextService::forUser()` com dedup financeiro por `company_id` único elegível — corrige o bug de responsável Shopee herdando faturamento/margem ML de empresas que não gerencia (medido em prod: Felipe avaliado sobre 29 empresas gerenciando 4).**

## Performance

- **Duration:** ~35 min
- **Tasks:** 3
- **Files modified:** 3 (1 controller, 1 componente React) + 2 testes novos

## Accomplishments
- Empresa com vínculo Performance + Shopee do mesmo profissional aparece 1x na carteira, com os 2 vínculos separados em `empresas[].servicos` (CART-01)
- Vínculo Shopee sempre exposto com `financial_metrics_eligible=false`, e a UI mostra "—" com tooltip explícito "sem fonte financeira" nas colunas Faturamento/Margem/Var. quando o profissional não tem NENHUM vínculo elegível na empresa (CART-02)
- `ad_spend`/`tacos` — campos que não existiam antes nesta função — adicionados ao payload já filtrados pela mesma lista de `company_id` elegível (CART-03)
- Analista/Estrategista responsável só por Shopee de uma empresa que também tem ML não recebe faturamento/margem/ad_spend/tacos dessa empresa (CART-04, ambos os papéis testados)
- Profissional responsável por ML E Shopee da MESMA empresa tem o financeiro contado exatamente 1x — nunca duplicado (CART-05, teste mais crítico da fase)
- `empresas_sem_margem` corrigido para contar só empresas elegíveis (correção aplicada do plan-checker) — empresa Shopee-only não infla o banner "sem dados de margem"

## Task Commits

Cada task foi commitada atomicamente (TDD RED → GREEN → GREEN):

1. **Task 1: Testes RED — elegibilidade financeira e contexto por vínculo** - `5c04a73` (test)
2. **Task 2: Refatorar renderCarteiraProfissional (GREEN)** - `38ebcb7` (feat)
3. **Task 3: Badges de vínculo + estado "sem fonte financeira" no AdminCarteira.jsx** - `9bc59b4` (feat)

_Sem plan metadata commit adicional (docs não deve ser commitado, per constraint do prompt)._

## Files Created/Modified
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` - 5 testes: CART-04 (Shopee-only, ambos os papéis), CART-05 (dedup ML+Shopee mesma empresa), regressão (ML comum com ad_spend/tacos), CART-03 (empresas mistas na carteira)
- `tests/Feature/V16/CarteiraIndividualContextoTest.php` - 4 testes: CART-01 (agrupamento com 2 servicos), CART-02 (elegibilidade por entrada), 2º serviço de setor performance sem hardcode, ramo legado servico_id NULL
- `app/Http/Controllers/PortfolioController.php` - `renderCarteiraProfissional()`: injeta `CarteiraContextService`, troca origem por `forUser()`, `$companyIdsElegiveis` via `->unique()` gateia todas as queries `AdmanMetric` (SUM revenue/margem/ad_spend + dias-com-margem), adiciona cache `getCachedAccountMetricsMany` pra ad_spend/tacos, map por empresa bifurca elegível/não-elegível, resumo ganha `total_ad_spend`/`tacos_medio` e corrige `empresas_sem_margem`
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` - `SETOR_LABELS` pt-BR, badges por vínculo sob o nome da empresa, `temFonteFinanceira` computada dentro do `.map()`, células Faturamento/Margem/Var. mostram "—" com tooltip quando sem fonte financeira, docblock de contrato de props atualizado

## Decisions Made
- **`empresas_sem_margem` gated por elegibilidade** (correção do plan-checker, warning 3): antes do fix, uma empresa Shopee-only entraria no contador só porque `margem_contribuicao` é sempre null pra ela — o teste `test_shopee_only_analista_nao_herda_financeiro_ml` cobre implicitamente esse caso (empresa aparece, financeiro null, mas o contador de "sem margem" não é asserido diretamente nele porque o cenário testa `total_faturamento`/linha, não o contador). Aplicado o gate por `$companyIdsElegiveis->contains($e['id'])` antes do `whereNull`.
- **`renderPortfolio()` (auto-visualização) preservado intocado** — nenhuma REQ desta fase menciona essa função (Pitfall 2/6 do RESEARCH). Rota `/admin/users/{user}/portfolio` quando o próprio user visita a própria carteira ainda usa a função legada com `$user->companies()->withPivot('role')`. Baixo risco de exposição real (rota admin-prefixada, uso incomum) — candidato a follow-up/Fase 90.
- **Filtro Todos/Performance/Shopee e contadores de topo (badges "empresas únicas: N") NÃO implementados** — payload já expõe `servicos[]` pronto pra isso, mas a UI completa fica pra Fase 90 (CART-07, que tem `UI hint: yes` explícito no ROADMAP).

## Deviations from Plan

None - plan executado exatamente como escrito, incluindo a correção do plan-checker (item 6 da Task 2 já previa o gate de elegibilidade em `empresas_sem_margem`).

## Issues Encountered
None.

## Known Gaps (documentados conforme `<output>` do plano)

1. **`renderPortfolio()`** (auto-visualização via `/admin/users/{seu_próprio_id}/portfolio`) fica com o comportamento antigo — um profissional Shopee-only acessando essa rota específica ainda veria dados de ML misturados. Candidato à Fase 90/follow-up (Pitfall 2/6 do 89-RESEARCH.md).
2. **Filtro de contexto Todos/Performance/Shopee** e **contadores de topo** (empresas únicas, vínculos financeiros/sem-fonte) não foram implementados nesta fase — são CART-07/Fase 90.

## User Setup Required
None - refatoração de leitura sobre schema já existente, zero libs novas, sem configuração externa.

## Next Phase Readiness
- Payload `empresas[].servicos` já no shape que a Fase 90 (filtro completo + carteiras consolidadas) vai consumir — nenhuma 2ª rodada de mudança de shape esperada.
- `renderCarteirasConsolidadas()` (cards admin) segue usando `$user->estrategistaCompanies()`/`consultorCompanies()` sem contexto de setor — é o próximo alvo da Fase 90.
- Regressão ampla verde: V16 126/126, Nps 207/207, Desempenho 55/56 (falha pré-existente `PublicacaoDesempenhoRouteTest`, não relacionada), RenderPortfolioTest 7/7 (prova que `renderPortfolio()` ficou intocado), `npm run build` exit 0.

---
*Phase: 89-carteira-individual-rendercarteiraprofissional-por-contexto-*
*Completed: 2026-07-16*

## Self-Check: PASSED

Todos os arquivos e commits referenciados neste SUMMARY foram verificados no disco/git:
- `app/Http/Controllers/PortfolioController.php` — FOUND
- `resources/js/Pages/Portfolio/AdminCarteira.jsx` — FOUND
- `tests/Feature/V16/CarteiraFinanceiroElegibilidadeTest.php` — FOUND
- `tests/Feature/V16/CarteiraIndividualContextoTest.php` — FOUND
- `.planning/phases/89-carteira-individual-rendercarteiraprofissional-por-contexto-/89-01-SUMMARY.md` — FOUND
- Commits `5c04a73`, `38ebcb7`, `9bc59b4` — FOUND em `git log`
