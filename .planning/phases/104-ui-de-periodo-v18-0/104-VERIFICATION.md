---
phase: 104-ui-de-periodo-v18-0
verified: 2026-07-21T12:30:15Z
status: human_needed
score: 9/9 must-haves verificados a nível de código
overrides_applied: 0
human_verification:
  - test: "Checkpoint visual pós-deploy nas 3 telas (Task 2 do 104-02-PLAN.md, autonomous: false)"
    expected: "/performance → segmento Em curso/Bônus atual/Mês fechado; 'Bônus atual' mostra 'Competência junho/2026 · pago em julho' e o ranking muda pros números de junho fechado; 'Em curso' marca parcial. Carteira individual → toggle ao lado do ?contexto=; trocar preserva o contexto; modo fechado mostra competência. Carteira consolidada → toggle no lugar do rolante antigo; números coerentes com a janela. Nenhum slug cru aparece ('em_curso'/'official'/etc.) na tela."
    why_human: "Validação visual/UX real (renderização de CSS, formatação percebida, comportamento de clique em navegador) não é verificável por grep/teste automatizado; o próprio plano marca esta task como checkpoint:human-verify (autonomous: false) e o SUMMARY confirma que não foi executada pelo agente. Requer deploy prévio (MySQL local quebrado, conforme nota do plano)."
---

# Phase 104: UI de período (v18.0) Verification Report

**Phase Goal:** O ranking `/performance` e a carteira exibem um toggle de contexto de período (Em curso / Bônus atual / Mês fechado) e o payload carrega `periodo` + `bonus.competence_month`/`payment_month` — a tela nunca deixa confundir número em curso com número de pagamento.
**Verified:** 2026-07-21T12:30:15Z
**Status:** human_needed
**Re-verificação:** Não — verificação inicial

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 (SC1/UIP-01) | Ranking e carteira exibem toggle "Em curso"/"Bônus atual"/"Mês fechado" com rótulos sem jargão | ✓ VERIFIED | `PERIODO_SEGMENTOS`/`PeriodoToggle` presentes e renderizados nos 3 arquivos (`Performance/Index.jsx:150-181`, `Portfolio/AdminCarteira.jsx:90+`, `Portfolio/Carteiras.jsx:54+`); rótulos `{opt.label}` renderizados via `.map()` |
| 2 (SC2/UIP-02) | Payload Inertia carrega `periodo` (janelas+label) e, no modo bônus, `bonus.competence_month`/`payment_month`; tela mostra competência e mês de pagamento | ✓ VERIFIED | `PeriodoBonusPayloadTest.php` (7 testes, todos verdes) prova as 3 chaves em `PerformanceController::index()` e nas 2 funções de `PortfolioController`; JSX renderiza `Competência {mês}/{ano} · pago em {mês}` a partir de `bonus.competence_month`/`payment_month` nas 3 telas |
| 3 (SC3/UIP-03) | Filtro de período disponível nas telas do núcleo (carteira individual, consolidada, ranking); comparação vem da janela resolvida, não de cálculo próprio da tela | ✓ VERIFIED | `?modo=bonus_atual` reconhecido simetricamente em `PerformanceController::index()` (linhas ~95-125) E nas 2 funções de `PortfolioController` (`renderCarteiraProfissional` ~161-193, `renderCarteirasConsolidadas` ~575-599) — mesma chamada `$this->periodResolver->resolve()`, nenhuma comparação calculada inline na view |
| 4 (SC4/UIP-04) | Tela indica claramente modo operacional/parcial vs. oficial de bônus | ✓ VERIFIED | Badge "parcial · mês em andamento" (`Performance/Index.jsx:329`) + banner "Parcial · mês em andamento — a consolidação mensal fecha dia 1 do mês seguinte" (`Carteiras.jsx:205`) + banner pré-existente de contexto (`AdminCarteira.jsx`, Fase 89, preservado) |
| 5 (plan 104-01) | Preset "Bônus atual" resolve o último mês fechado via `MetricPeriodResolver` (`last_closed_month`) — competência do mês fechado, não em curso | ✓ VERIFIED | Teste `test_ranking_modo_bonus_atual_resolve_ultimo_mes_fechado` com `now=2026-07-20`: `periodo.current_start=2026-06-01`, `bonus.competence_month=2026-06`, `bonus.payment_month=2026-07`, `mes_selecionado=2026-06` — todos verdes |
| 6 (plan 104-01) | Payload `periodo`/`bonus` adicionado no `index()` do ranking (~250-262), NÃO na linha 563 (`dashboardCarteira`) | ✓ VERIFIED | Linha 302 (`'periodo' => $periodoResolvido`) e 303 (`'bonus' => $bonusMeta`) dentro do `Inertia::render('Performance/Index', ...)`; linha 605 (`'periodo' => 'Últimos 30 dias'`) confirmada intocada dentro de `Inertia::render('Performance/Dashboard', ...)` (função `dashboardCarteira`, fora de escopo) |
| 7 (plan 104-01) | Carteira (individual + consolidada) mantém `periodo` (Fase 103) e só ADICIONA `bonus` quando fechado | ✓ VERIFIED | `bonus=null` quando `is_current_month`/em curso (`test_carteira_individual_sem_modo_permanece_mes_em_curso_com_bonus_null`, `test_carteira_consolidada_sem_modo_permanece_mes_em_curso_com_bonus_null`) |
| 8 (plan 104-02) | Trocar o toggle preserva outros params na URL (`?contexto=` da carteira, `?setor=`/`?cargo=` do ranking) via `router.get`/`visit` + `preserveScroll`, sem recarga crua | ✓ VERIFIED | `applyFilter`/`applyPeriodo` (`Performance/Index.jsx:233-263`) preserva `setor`/`cargo`; `navigate()` unificado (`AdminCarteira.jsx:168-174`) preserva `contexto`/`mes`/`modo` com `router.visit(..., {preserveScroll:true})`; `Carteiras.jsx:113-115` idem |
| 9 (plan 104-02) | Rótulos sem jargão: nunca `'em_curso'`/`'official'`/`'closed_period'` crus na tela | ✓ VERIFIED | `grep` confirma que esses valores só aparecem como `key`/valor de param (`modo=bonus_atual`, comparações `===`), nunca como texto JSX renderizado; labels visíveis são sempre "Em curso"/"Bônus atual"/"Mês fechado"/"Competência {mês}/{ano}" |

**Score:** 9/9 truths verificadas a nível de código

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Http/Controllers/PerformanceController.php` | Payload `periodo`/`bonus` no ranking + preset `?modo=bonus_atual` | ✓ VERIFIED | Linhas 87-125 (resolução), 302-303 (payload); commit `3e91479` isolado (+46/-2, só este arquivo + teste) |
| `app/Http/Controllers/PortfolioController.php` | `?modo=bonus_atual` simétrico nas 2 funções de carteira + bloco `bonus` | ✓ VERIFIED | Linhas 161-193 (individual), 575-599 (consolidada), payload em 494-521 e 851-863; commit `c78cef0` isolado (+62/-7, só este arquivo) |
| `tests/Feature/V18/PeriodoBonusPayloadTest.php` | Prova periodo/bonus nos 3 modos, nas 3 telas | ✓ VERIFIED | 7 testes, todos os cenários do briefing (em_curso, bonus_atual com now=2026-07-20, mês específico fechado) cobertos e verdes |
| `resources/js/Pages/Performance/Index.jsx` | Toggle + indicador competência/pagamento + parcial | ✓ VERIFIED | `PeriodoToggle`, `segmentoAtivo`, `formatCompetencia` presentes e wired a `periodo`/`bonus` props |
| `resources/js/Pages/Portfolio/AdminCarteira.jsx` | Toggle ao lado de `?contexto=` existente | ✓ VERIFIED | `PeriodoToggle` + `navigate()` unificado presentes; banner de competência/pagamento wired |
| `resources/js/Pages/Portfolio/Carteiras.jsx` | Toggle substitui seletor rolante legado 1/7/30/180 | ✓ VERIFIED | `PERIOD_OPTIONS` legado removido (só comentários residuais mencionando a substituição); `PeriodoToggle` + `<input type="month">` presentes |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `Performance/Index.jsx` toggle | `PerformanceController::index()` | `router.get(route('performance.index'), {modo, mes, setor, cargo})` | ✓ WIRED | `applyPeriodo()` monta params e chama `applyFilter()` → `router.get` |
| `AdminCarteira.jsx` toggle | `PortfolioController::renderCarteiraProfissional` | `router.visit(pathname + '?' + params)` | ✓ WIRED | `navigate()` inclui `modo`/`mes`/`contexto` |
| `Carteiras.jsx` toggle | `PortfolioController::renderCarteirasConsolidadas` | `router.visit(route('portfolio.own') + '?' + params)` | ✓ WIRED | idem, `preserveScroll: true` |
| `payload.periodo`/`payload.bonus` | `MetricPeriodResolver::resolve()` | chamada direta 1x por request nos 2 controllers | ✓ WIRED | Nenhum `now()`/cálculo de janela inline nas views; todas as datas exibidas vêm do resolver |
| `?modo=bonus_atual` | `computeCached($u, $mesReferencia)` | `$mesReferencia` derivado de `periodo['bonus_competence_month']` | ✓ WIRED | Sem segundo score — `computeCached`/`compute` são os únicos pontos de cálculo de nota (grep confirmado, sem uso de `computeOficial` no ranking) |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|--------------|--------|----------|
| UIP-01 | 104-02 | Toggle de contexto de período nas 3 telas, rótulos sem jargão | ✓ SATISFIED | Truths 1, 9 |
| UIP-02 | 104-01 | Payload carrega `periodo`+`bonus.competence_month`/`payment_month` | ✓ SATISFIED | Truths 2, 5, 6, 7 |
| UIP-03 | 104-01, 104-02 | Filtro de período disponível nas 3 telas, comparação vem da janela resolvida | ✓ SATISFIED | Truths 3, 8 |
| UIP-04 | 104-02 | Indicação clara operacional/parcial vs. oficial de bônus | ✓ SATISFIED | Truth 4 |

Nenhum requisito órfão encontrado — `REQUIREMENTS-v18.md` mapeia UIP-01..04 exclusivamente para a Fase 104, e os 2 plans cobrem os 4 IDs.

### Anti-Patterns Found

Nenhum bloqueador. Varredura em `PerformanceController.php`, `PortfolioController.php` e nos 3 `.jsx` não encontrou `TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER` reais (os únicos matches de "todos"/"placeholder" são falsos positivos — palavra "todos" em pt-BR e um atributo HTML `placeholder="Buscar empresa…"` de campo de busca).

### Testes executados (verificador, não confiando no SUMMARY)

```
C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/V18/PeriodoBonusPayloadTest.php tests/Feature/V18/CarteiraPeriodoDiffTest.php tests/Feature/V18/CarteiraConsolidadaPeriodoTest.php
→ OK (17 tests, 104 assertions)

C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/V16/PerformanceIndexMetadadosTest.php
→ OK (4 tests, 51 assertions)  — gate textual DESEMP-02 (sem segundo score)

npm run build
→ exit 0, build Vite completo (~27s), sem erros/warnings novos
```

### Fronteira (checagem de escopo)

`git show --stat` dos 3 commits da fase confirma escopo fechado:
- `3e91479` — só `PerformanceController.php` + `PeriodoBonusPayloadTest.php`
- `c78cef0` — só `PortfolioController.php`
- `ce4c002` — só os 3 `.jsx` (`Performance/Index.jsx`, `Portfolio/AdminCarteira.jsx`, `Portfolio/Carteiras.jsx`)

Nenhum commit toca `DesempenhoScoreService`, `MetricPeriodResolver`, `AdmanMetricDiffService`, `CarteiraContextService`, arquivos NPS ou `Dashboard*`.

### Human Verification Required

#### 1. Checkpoint visual pós-deploy (Task 2 do 104-02-PLAN.md)

**Teste:** Após deploy (MySQL local está quebrado — validação só é possível em produção/staging):
1. `/performance` → segmento Em curso/Bônus atual/Mês fechado; "Bônus atual" mostra "Competência junho/2026 · pago em julho" e o ranking muda pros números de junho fechado; "Em curso" marca parcial.
2. Carteira individual (`/portfolio`) → toggle ao lado do `?contexto=`; trocar preserva o contexto; modo fechado mostra competência.
3. Carteira consolidada (`/portfolio` aba consolidada) → toggle no lugar do rolante antigo; números coerentes com a janela.
4. Conferir que nenhum slug cru aparece (`em_curso`/`official`/etc.) na tela renderizada (não só no código-fonte).

**Esperado:** As 3 telas mostram os rótulos corretos, o toggle funciona sem reload cru, o número de bônus bate com o mês fechado e nunca é confundido visualmente com o número em curso.

**Por que humano:** Renderização real em navegador (CSS, layout, cliques, formatação percebida) não é verificável por grep/teste automatizado. O próprio plano marca esta etapa como `checkpoint:human-verify` (`autonomous: false`) e o SUMMARY do 104-02 confirma explicitamente que não foi executada pelo agente ("NÃO executado por este agente"). Depende de deploy prévio.

### Gaps Summary

Nenhum gap de código encontrado — os 9 must-haves (4 Success Criteria do ROADMAP + 5 truths adicionais dos 2 PLANs) estão implementados, testados e sem sinais de stub. O único item pendente é o checkpoint visual humano pós-deploy, já previsto no próprio plano (`Task 2`, `autonomous: false`) e não uma lacuna de execução — por isso o status é `human_needed`, não `gaps_found`.

---

_Verified: 2026-07-21T12:30:15Z_
_Verifier: Claude (gsd-verifier)_
