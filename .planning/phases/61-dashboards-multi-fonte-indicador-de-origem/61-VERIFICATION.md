---
phase: 61
verified_at: 2026-07-07
status: passed
requirements_covered: [DATA-05, DASH-04, DASH-05, DASH-06]
success_criteria_covered: [1, 2, 3, 4]
tests_green: 31
tests_assertions: 450
regression_delta: 0
baseline_phase60: "46 passed (188 assertions)"
score: 4/4 must-haves verified
---

# Phase 61 — Dashboards multi-fonte + indicador de origem — VERIFICATION

**Phase Goal (ROADMAP linha 45):** Dashboards e carteiras passam a exibir métricas corretas independentemente da fonte da empresa, com indicador visual claro de origem em cada métrica exibida.

**Verificado em:** 2026-07-07
**Modo:** initial (goal-backward)
**Veredito:** PASSED (4/4 SC + 4/4 REQ + 31/31 testes + 0 regressão Phase 60)

---

## 1. Success Criteria (ROADMAP) — cada critério contra o código

### SC #1 — Dashboard ML unifica fontes num KPI único

**O que precisa ser verdade:** Dashboard ML (`/dashboard/mercadolivre`) exibe KPI unificado que soma empresas fonte-ML + fonte-Adman num único número, sem duplicar nem ignorar.

| Nível | Evidência |
|-------|-----------|
| Backend enriquece stats | `app/Http/Controllers/DashboardController.php:794` — mescla `source_counts` em `stats` quando flag ON |
| Contagem correta | `DashboardController.php:764-769` — soma 4 buckets (adman/ml/unified/none) via `caseFor()`; total = `count()` do universo intocado |
| Frontend renderiza legenda | `Dashboard/Admin.jsx:117` lê `stats?.source_counts`; linhas 326-333 renderizam legenda ordenada ML→Agregado→Adman→Sem integração |
| Teste E2E dos 4 casos | `DashboardMultiFonteE2ETest::test_flag_on_dashboard_ml_expoe_source_counts_agregado_4_casos` — PASS |
| Teste "não duplica nem ignora" | `DashboardSourceEnrichmentTest::test_flag_on_stats_source_counts_soma_igual_ao_total_companies` — PASS |

**Status:** VERIFIED

---

### SC #2 — Analista/Estrategista tolerantes a fonte (não quebra ML-only, mantém Adman-only)

**O que precisa ser verdade:** Dashboards de Analista/Estrategista não lançam erro em empresas ML-only; Adman-only continuam aparecendo normalmente.

| Nível | Evidência |
|-------|-----------|
| `caseFor()` I/O-free | Phase 60 — sem HTTP outbound (memória `project_ml_only_companies_adman_endpoints` mitigada) |
| Portfolio enriquece companies + user_portfolios | `PortfolioController.php:275, 781` — `factoryToSource()` alimenta ambos, source_counts por analista |
| Portfolio/Show render tolerante | `Portfolio/Show.jsx:798, 884` — guard `c.source && <SourceBadge>` (não quebra flag OFF) |
| Teste ML-only Dashboard | `DashboardMultiFonteE2ETest::test_flag_on_empresa_so_ml_renderiza_sem_crash_com_source_ml` — PASS (assertOk + source=ml) |
| Teste ML-only Portfolio | `PortfolioMultiFonteE2ETest::test_flag_on_portfolio_show_empresa_ml_only_nao_quebra_render` — PASS |
| Teste Adman-only aparece | `DashboardMultiFonteE2ETest::test_flag_on_empresa_so_adman_recebe_source_adman` — PASS |

**Status:** VERIFIED

---

### SC #3 — Badge "ML" ao lado do nome na carteira individual

**O que precisa ser verdade:** Carteira individual exibe badge visual "ML" ao lado do nome de cada empresa conectada ao Mercado Livre.

| Nível | Evidência |
|-------|-----------|
| Backend enriquece UNCONDITIONAL | `CompanyController.php:431` — `'source' => $this->factoryToSource($company)` — SEM `if unifiedMetricsEnabled` (decisão consciente 61-03; badge é obrigatório do ROADMAP) |
| Confirmação de ausência de flag | `grep -q "unified_metrics_enabled" CompanyController.php` — NENHUM match |
| Frontend renderiza badge | `Companies/Show.jsx:562-564` — `{company.source && company.source !== 'none' && <SourceBadge variant={company.source} />}` |
| Guard anti-poluição | Badge `!== 'none'` no header individual (não polui empresa sem integração) |
| Testes 4 casos ADR | `CompanyShowSourceTest` — 4 casos PASS (só_adman, só_ml, ambos, none) |

**Status:** VERIFIED

---

### SC #4 — Indicador visual de fonte em cada métrica (badge ou tooltip: ML, Adman, ou Agregado)

**O que precisa ser verdade:** Cada métrica renderizada na UI carrega indicador visual da fonte.

| Superfície | Onde | Evidência |
|-----------|------|-----------|
| Dashboard/Admin — legenda + linha | `Admin.jsx:326-333, 533` | Legenda no header + badge por linha em `companies_performance` |
| Portfolio/Show — mobile + desktop | `Portfolio/Show.jsx:798, 884` | Badge por empresa em card mobile E tabela desktop |
| Portfolio/Carteiras — legenda | `Portfolio/Carteiras.jsx:87-93` | Mini-legenda `source_counts` por profissional |
| Companies/Show — header | `Companies/Show.jsx:562-564` | Badge no header da carteira individual |
| Componente com tooltip | `source-badge.jsx:70-84` | `title` HTML nativo com explicação pt-BR por variante |
| Vocabulário anti-jargão | `source-badge.jsx:56` | `unified: 'Agregado'` (NÃO "unified" cru) — `grep ">unified<" pages` retorna vazio |

**Status:** VERIFIED

---

## 2. Requirements Coverage

| REQ | Descrição | Superfície | Status | Evidência |
|-----|-----------|------------|--------|-----------|
| DATA-05 | Cada métrica UI carrega indicador visual da fonte | Dashboard/Portfolio/Companies | SATISFIED | SC #4 acima |
| DASH-04 | Dashboard ML unifica ML+Adman num único KPI | `/dashboard/mercadolivre` (Admin) | SATISFIED | SC #1 acima |
| DASH-05 | Analista/Estrategista tolerante à fonte, não quebra ML-only | Portfolio/Show + Portfolio/Carteiras | SATISFIED | SC #2 acima |
| DASH-06 | Badge "ML" na carteira individual | Companies/Show | SATISFIED | SC #3 acima |

Nenhum requirement ORPHANED. Nenhum requirement BLOCKED.

---

## 3. Spot-checks executáveis (bash)

| # | Verificação | Comando | Resultado | Status |
|---|-------------|---------|-----------|--------|
| 1 | Feature flag existe | `test -f config/metrics.php && grep -q "unified_metrics_enabled" config/metrics.php` | match linha 46 | PASS |
| 2 | SourceBadge component existe + exporta | `test -f source-badge.jsx && grep -q "export { SourceBadge"` | match linha 86 | PASS |
| 3 | Factory injetada em DashboardController | `grep -q "MetricsProviderFactory" DashboardController.php` | match linhas 15, 26 | PASS |
| 4 | Factory injetada em PortfolioController | `grep -q "MetricsProviderFactory" PortfolioController.php` | match linhas 14, 27 | PASS |
| 5 | Factory injetada em CompanyController | `grep -q "MetricsProviderFactory" CompanyController.php` | match linhas 13, 25 | PASS |
| 6 | Chave `source_counts` usada corretamente | `grep -q "'source_counts'" DashboardController.php` | match linha 794 | PASS |
| 7 | Flag respeitada em DashboardController | `grep -q "unified_metrics_enabled" DashboardController.php` | match linhas 37, 762 (guard) | PASS |
| 8 | Flag respeitada em PortfolioController | `grep -q "unified_metrics_enabled" PortfolioController.php` | match linha 38 (guard) | PASS |
| 9 | DASH-06 UNCONDITIONAL (sem flag em CompanyController) | `! grep -q "unified_metrics_enabled" CompanyController.php` | NENHUM match — correto | PASS |
| 10 | Badge aplicado em Companies/Show | `grep -q "SourceBadge" Companies/Show.jsx` | match linha 563 | PASS |
| 11 | Badge aplicado em Portfolio/Show | `grep -q "SourceBadge" Portfolio/Show.jsx` | match linhas 798, 884 | PASS |
| 12 | Legenda aplicada em Portfolio/Carteiras | `grep -q "SourceBadge" Portfolio/Carteiras.jsx` | match linhas 89-92 | PASS |
| 13 | Badge aplicado em Dashboard/Admin | `grep -q "SourceBadge" Dashboard/Admin.jsx` | match linhas 329-332, 533 | PASS |
| 14 | Rótulo "Agregado" (anti-jargão) | `grep -q "Agregado" source-badge.jsx` | match linhas 13, 34, 39, 56 | PASS |
| 15 | Nenhum "unified" cru na UI | `! grep -q ">unified<" resources/js/Pages/**/*.jsx` | 0 hits — correto | PASS |

**15/15 spot-checks PASS.**

---

## 4. Testes automatizados

### Phase 61 (novo)

```
$ php artisan test tests/Feature/Phase61
Tests:    31 passed (450 assertions)
Duration: 76.49s
```

| Test file | Tests |
|-----------|-------|
| `CompanyShowSourceTest` | 4 |
| `DashboardMultiFonteE2ETest` | 5 |
| `DashboardSourceEnrichmentTest` | 6 |
| `FeatureFlagRegressionTest` | 5 |
| `PortfolioMultiFonteE2ETest` | 6 |
| `PortfolioSourceEnrichmentTest` | 5 |
| **Total** | **31** |

Bate com claim do PHASE-SUMMARY (31/450).

### Phase 60 baseline (regressão)

```
$ php artisan test tests/Feature/Phase60
Tests:    46 passed (188 assertions)
Duration: 12.61s
```

Zero regressão. **regression_delta: 0.**

---

## 5. Zero regressão em legado — git diff HEAD~15

| Alvo | Comando | Resultado |
|------|---------|-----------|
| `app/Services/Metrics/` | `git diff HEAD~15 --stat` | vazio (Phase 60 intocada) |
| `app/Services/AdmanService.php` + `MercadoLivreService.php` | `git diff HEAD~15 --stat` | vazio (services intactos) |
| Controllers modificados | `git log HEAD~20..HEAD` | apenas 3 commits (`a48583e`, `1aebc85`, `5f0f331`) — todos add-only para wiring de source |

---

## 6. Feature flag ON/OFF

- `FeatureFlagRegressionTest::test_flag_default_false_em_config` — PASS (0.05s, sem HTTP)
- `test_flag_off_route_dashboard_ml_permanece_200` — PASS
- `test_flag_off_route_portfolio_own_permanece_200` — PASS
- `test_flag_off_route_companies_show_permanece_200` — PASS (badge DASH-06 UNCONDITIONAL vs flag)
- `test_flag_off_route_portfolio_show_permanece_200` — PASS
- Payload flag OFF: `DashboardSourceEnrichmentTest::test_flag_off_admin_dashboard_nao_contem_source_metadata` — PASS (`->missing('stats.source_counts')`)
- Payload flag OFF: `PortfolioSourceEnrichmentTest::test_flag_off_portfolio_show_nao_contem_source_em_companies` — PASS

Cast explícito com `filter_var(FILTER_VALIDATE_BOOLEAN)` em `config/metrics.php:46-49` — defesa T-61-01-01 do threat model.

---

## 7. Working tree

```
$ git status --short
 M app/Http/Controllers/GoalController.php
 M app/Http/Controllers/MercadoLivreOAuthController.php
?? briefing-carteira-analistas-ui.md
?? dashboard-performance-ui-proposta.html
... (untracked scratchpad/docs)
```

**Análise:**

- `GoalController.php` e `MercadoLivreOAuthController.php` estão modified porém **NÃO fazem parte da Phase 61** (o diff mostra bloco de authorization/RBAC unrelated). Não bloqueia veredito da Phase 61 — pertencem a outra linha de trabalho em paralelo.
- Todos os arquivos Phase 61 (`DashboardController`, `PortfolioController`, `CompanyController`, `Companies/Show.jsx`, `Portfolio/*.jsx`, `Dashboard/Admin.jsx`, `source-badge.jsx`, `config/metrics.php`) estão CLEAN (commitados nos 20 últimos commits).

Nota: task-context menciona edits do usuário em `CompanyController.php` e `Show.jsx` — commit `a48583e` (feat 61-03) inclui ambos disjuntamente com o wiring de `source`, então não há conflito residual.

---

## 8. Vocabulário anti-jargão ([[feedback_evitar_jargao_ui]])

**Regra:** "unified" (termo interno ADR DATA-04) NUNCA deve aparecer para o usuário — rótulo travado é "Agregado".

| Verificação | Resultado |
|-------------|-----------|
| Rótulo "Agregado" em `LABEL.unified` | `source-badge.jsx:56` — `unified: 'Agregado'` |
| Comentário explicando decisão | `source-badge.jsx:12-14, 51-52` — regra anti-jargão documentada |
| Fallback anti-desconhecido | `source-badge.jsx:73` — `safeVariant = LABEL[variant] ? variant : 'none'` |
| Superfícies UI sem "unified" cru | `grep ">unified<" Pages/**/*.jsx` — 0 hits |
| Tooltip pt-BR sem jargão | `TOOLTIP.unified` explica em pt-BR: "ML dita valores operacionais, Adman enriquece campos exclusivos..." |

**Status:** CONFORME.

---

## 9. Debt markers (TBD/FIXME/XXX/TODO)

```
$ grep -n "TBD\|FIXME\|XXX" [8 arquivos Phase 61]
(vazio)
```

**Zero debt markers em qualquer arquivo Phase 61.**

---

## 10. Deferred / Riscos residuais / Notas

- **PerformanceController, AdminController, AdmanController** — ainda leem `adman_metrics` diretamente (Phase 60/61 não migraram). Documentado no PHASE-SUMMARY seção "Rollout — próximos passos". Decisão explícita: fora do escopo da Phase 61. Nenhuma SC ou REQ da Phase 61 exige essas migrações.
- **Painel de divergências TACOS** — log `[UnifiedMetrics] TACOS divergente` escreve warning, mas UI de monitoramento fica pra phase futura. Fora do escopo.
- **Tabela local `ml_metrics_daily`** — rejeitada na ADR DATA-04 alternativa B; não é gap.
- **Deploy pendente** — memory `feedback_perguntar_antes_deploy_v9` requer confirmação explícita (outro dev em paralelo no v14.0). NÃO deployar sem autorização.

Nenhum gap real. Nenhum item deferred para verificação futura.

---

## 11. Human verification (opcional, se desejarem)

Todos os critérios foram verificados automaticamente via testes + grep de wiring. Rendering visual dos badges pode ser conferido manualmente em:

1. `/dashboard/mercadolivre` (com flag OFF → legenda ausente; flag ON → legenda ML→Agregado→Adman→Sem integração no header)
2. `/portfolio` (Carteiras) → mini-legenda por analista
3. `/portfolio/{userId}` (Show) → badge por empresa (mobile + desktop)
4. `/companies/{id}` (Show) → badge no header sempre (DASH-06 é unconditional; oculto só para `source==='none'`)

Não é BLOQUEANTE — 31 testes E2E cobrem os payloads Inertia enviados a cada uma dessas rotas.

---

## Veredito final

## VERIFICATION PASSED

- 4/4 Success Criteria do ROADMAP atendidos e provados no código + testes
- 4/4 Requirements (DATA-05, DASH-04, DASH-05, DASH-06) SATISFIED
- 31/31 testes Phase 61 verdes (450 assertions)
- 46/46 testes Phase 60 baseline preservados (188 assertions) — zero regressão
- 15/15 spot-checks bash PASS
- 0 debt markers em arquivos Phase 61
- Vocabulário anti-jargão conforme (rótulo "Agregado", nunca "unified" no UI)
- Feature flag `UNIFIED_METRICS_ENABLED` com cast estrito + guard consistente

Phase 61 pode ser encerrada. Working tree tem edits UNRELATED (`GoalController`, `MercadoLivreOAuthController`) que não são responsabilidade desta phase.

**Deploy gate ativo — deploy só com autorização explícita ([[feedback_perguntar_antes_deploy_v9]]).**

---

_Verified: 2026-07-07_
_Verifier: Claude (gsd-verifier / goal-backward)_
