---
phase: 42-sugadores-api-ml
verified: 2026-06-26T14:30:00Z
status: human_needed
score: 10/10 must-haves verificados
verifier: orchestrator (gsd-verifier hit session limit; verificacao goal-backward inline via grep + suite Phase 42)
human_verification:
  - test: "Logar como admin no navegador e acessar /dev/sugadores-ml-onboarding via URL direta"
    expected: "Pagina abre normalmente (rota preservada conforme D-02), mas item de sidebar NAO aparece em nenhum lugar."
    why_human: "AppLayout.jsx renderiza condicionalmente; grep nao captura visual final em browser real."
  - test: "Acessar /sugadores/configs/{company} de ByMobille e configurar cpc_maximo=4 + cpc_minimo_cliques=5"
    expected: "Campo 'Cliques minimos para validar CPC' aparece ao lado de cpc_maximo. Salvar persiste corretamente."
    why_human: "Validacao UX em browser — testes cobrem persistencia + validation, nao a renderizacao real."
  - test: "Rodar `php artisan sugadores:analyze --company=298` (ByMobille) no VPS apos deploy"
    expected: "Comando roda usando provider=ml automaticamente (cut-over D-05). Sugadores aparecem em /sugadores na tela normal — analista nao percebe a troca de motor."
    why_human: "Smoke real do path completo em prod com tokens ML reais + MariaDB real."
  - test: "Validar que sugador de origem ML em /sugadores/{id} mostra metricas corretas + botao 'Painel de Ads' abre Mercado Ads"
    expected: "Cards de investimento/vendas/faturamento/ACOS/cliques/impressoes/CPC/ROAS preenchidos. Botao 'Painel de Ads' abre `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId={X}` (nao Adman)."
    why_human: "Confere a integracao end-to-end com a API ML real."
  - test: "Configurar SGI - Lentes (ou campanha que comece com 'SGI') no Mercado Livre e validar que adgroups dela NAO aparecem em /sugadores"
    expected: "Quarentena §12 funcionando — campanha pulada antes do evaluator."
    why_human: "Confere a regra de quarentena em payload ML real (nao mockado)."
re_verification:
  previous_status: in_progress
  blockers_resolved:
    - "Bug real propagacao mlb_id no SugadorAnalysisService — corrigido em commit 7d6544a (path adgroup hardcodava null; agora propaga ad['mlb_id'] e ad['mlb_titulo'])"
    - "Bug raiz no padrao de teste — Http::fake([...]) acumula stubs em Laravel; fix via reflection no Factory singleton garantiu que tests de re-analise funcionassem corretamente"
  gaps_remaining: []
  regressions: []
---

# Phase 42: Sugadores via API ML — Relatorio de Verificacao

**Phase Goal (ROADMAP §951):** "Trocar a fonte de dados dos sugadores do Adman para a API oficial do Mercado Livre SEM criar novas telas, menus ou fluxos paralelos. A /sugadores, /sugadores/{id} e /sugadores/config/{company} continuam sendo as unicas telas operacionais. A API ML alimenta o mesmo contrato normalizado, o mesmo SugadorAnalysisService e a mesma tabela sugadores. Janela 30d fechados (ontem-29d → ontem). Item sidebar 'Onboarding ML' (Phase 41) escondido — rota permanece como ferramenta tecnica admin acessivel por URL direta."

**Verificado:** 2026-06-26T14:30:00Z
**Status:** human_needed (5 itens de verificacao humana em ambiente real)
**Verificador:** orchestrator inline (gsd-verifier atingiu session limit — verificacao goal-backward executada via grep + suite Phase 42)

## Achievement do Goal

### Truths Observaveis

| # | Truth (derivado de Success Criteria + briefing §1-15) | Status | Evidencia |
|---|------|--------|-----------|
| 1 | **Contrato §3 normalizado** — `MercadoLivreSugadoresProvider::fetchAdgroupsMetrics` retorna chaves `adgroup_id`, `campaign_id`, `campaign_name`, `campaign_status`, `investment`, `revenue`, `sold_quantity`, `clicks`, `impressions`, `cpc`, `ctr`, `acos`, `roas`, `mlb_id`, `mlb_titulo` etc | VERIFIED | `grep -c` em MercadoLivreSugadoresProvider.php retorna 27 ocorrencias dos campos do contrato. Tests AnalyzeCompanyMlWindowQuarantineTest::fetchAdgroupsMetrics_retorna_contrato_completo + fetchAdgroupsMetrics_resolve_campaign_name_via_merge PASS. |
| 2 | **Idempotencia preservada** — `Sugador::upsert` mantem chave `(company_id, reference_date, tipo, campaign_id, adgroup_id)`; re-analyze mesmo dia NAO duplica | VERIFIED | `SugadorAnalysisService.php:332` mantem chave canonica intacta. Tests CutOverMlPrimaryTest::idempotencia_re_analise_mesmo_dia + AceitacaoMlFluxoCompletoTest sc05_sc06 idempotencia PASS. |
| 3 | **Janela 30d fechados (D-03)** — `reference_date=hoje`, `periodo_fim=ontem`, `periodo_inicio=ontem-29d`. Briefing §4 exemplo: 25/06 → 26/05 a 24/06 | VERIFIED | `SugadorAnalysisService.php:142-143`: `periodoFim = referenceDate->subDay(); periodoInicio = periodoFim->subDays($config->dias_analise - 1)`. Test AnalyzeCompanyMlWindowQuarantineTest::janela_30d_fechada PASS. |
| 4 | **cpc_minimo_cliques (D-01 / Opcao B briefing §8)** — campo nullable int em `sugador_configs`, com cast integer, $fillable populado, UI form em `/sugadores/configs/{company}`, gate composto em criterio `cpc_alto` | VERIFIED | Migration `2026_06_26_420101_*.php` cria coluna. `SugadorConfig.php` linhas 19/35/55. `Config.jsx` linhas 139/293/294 (form). `SugadorAnalysisService.php:517` aplica gate `(cpc_minimo_cliques === null \|\| clicks >= cpc_minimo_cliques)`. Tests CpcMinimoCliquesSchemaTest (4) + EvaluateMetricsCpcCompostoTest (5) + SugadorConfigCpcMinimoCliquesUiTest (5) + AceitacaoMlFluxoCompletoTest sc06a PASS. |
| 5 | **Quarentena SGI (D-07)** — campanhas com nome contendo SGI/Sugador/Sugadores OU status paused/closed/ended sao puladas pelo analyzer antes do evaluator (mesma regra Adman + ML) | VERIFIED | `SugadorAnalysisService.php` linhas 44 (regex `SGI\|Sugador\|Sugadores` com word boundary) + 149 (skip logic). Tests AnalyzeCompanyMlWindowQuarantineTest quarentena_pula_adgroup_em_campanha_SGI + quarentena_pula_adgroup_em_campanha_paused PASS. AceitacaoMlFluxoCompletoTest sc07 PASS. |
| 6 | **STATUS_TRAVADOS preservados (D-06)** — sugadores em `em_acao/resolvido/ignorado/movido/auto_resolvido` NAO retornam a `pendente` em re-analise via ML; metricas atualizam normalmente | VERIFIED | `SugadorAnalysisService.php:423-425`: `$status = ($existing && in_array($existing->status, Sugador::STATUS_TRAVADOS, true)) ? $existing->status : Sugador::STATUS_PENDENTE`. Tests CutOverMlPrimaryTest::status_travado_preservado_em_re_analise_ml + AceitacaoMlFluxoCompletoTest sc08 (cobre em_acao + resolvido) PASS. |
| 7 | **Item sidebar 'Onboarding ML' escondido (D-02 / REQ-42-07)** — AppLayout.jsx nao renderiza item; controller `Dev/SugadoresMlOnboardingController` + Index.jsx + Show.jsx preservados (Phase 41 intacta) | VERIFIED | `grep -c "label: 'Onboarding ML'" resources/js/Layouts/AppLayout.jsx` retorna 0. Rota `dev.sugadores_ml_onboarding` ainda em `routes/web.php`. Tests SidebarAndAdsLinkTest sidebar_nao_mostra_onboarding_ml + rota_continua_acessivel_via_url_direta PASS. |
| 8 | **Empresa ML-only (ByMobille #298) aceita (REQ-42-08)** — `SugadorController::analyzeCompany` aceita empresa sem `adman_account_id` quando tem `mlToken status='active'` | VERIFIED | `SugadorController.php:343-382` aceita ambos hasAdman OR hasMl (Phase 42 D-05 comment). Test CutOverMlPrimaryTest::controller_aceita_empresa_ml_only PASS. AceitacaoMlFluxoCompletoTest sc03_sc04 ByMobille E2E PASS. |
| 9 | **linkAdsML deep link Mercado Ads para origem ML (REQ-42-09)** — sugador com origem ML em raw_data retorna `mercadolivre.com.br/anuncios/product-ads/anuncios?campaignId={X}`; sugador Adman mantem link legacy | VERIFIED | `Sugador.php:191-210` switching por heuristica de raw_data (metrics OR item_id+type). Tests LinkAdsMlUnitTest (5 tests) + SidebarAndAdsLinkTest (6 tests, 1 skipped intencional) PASS. |
| 10 | **Tests Feature/Sugadores legados continuam passando (REQ-42-10)** — Phase 42 zero regressao em suite Sugadores+Phase30-41 | VERIFIED | Suite Sugador+Phase 30-42 acumulada: 390/396 PASS. As 6 falhas restantes sao TODAS em `Phase38/PolosControllerTest::*` e foram CONFIRMADAS pre-existentes (reproduzem em commit pre-Phase-41 `b9441ed`). Phase 42 adicionou 0 regressoes. RegressaoSugadoresExistentesTest do Plan 42-06 documenta + roda esse guard. |

**Score:** 10/10 truths verificados

## Suite Phase 42 — 49 tests verdes (1 skipped)

| Plan | Suite | Tests |
|------|-------|-------|
| 42-01 | CpcMinimoCliquesSchemaTest + EvaluateMetricsCpcCompostoTest | 4 + 5 = 9 |
| 42-02 | SugadorConfigCpcMinimoCliquesUiTest | 5 |
| 42-03 | AnalyzeCompanyMlWindowQuarantineTest | 7 |
| 42-04 | CutOverMlPrimaryTest | 8 |
| 42-05 | SidebarAndAdsLinkTest + LinkAdsMlUnitTest | 7 (1 skipped) + 5 = 12 |
| 42-06 | AceitacaoMlFluxoCompletoTest + RegressaoSugadoresExistentesTest | 6 + 2 = 8 |
| **Total** | | **49 (1 skipped)** |

## Bugs reais detectados e resolvidos durante a execucao

### Bug 1 — Propagacao mlb_id no SugadorAnalysisService (commit 7d6544a)

`SugadorAnalysisService.php` linha 206 hardcodava `'mlb_id' => null` no path de adgroup, ignorando o contrato §3 do briefing que prove o campo. Provider ML mapeia `mlb_id` de `item_id` corretamente, mas o service nao propagava — sugadores de origem ML ficariam sem `mlb_id` em producao.

**Fix:** `'mlb_id' => $ad['mlb_id'] ?? null`, idem `mlb_titulo`. Path Adman ja retorna null no `$ad` (AdmanProvider nao mapeia esse campo), preservando comportamento legacy.

**Origem do achado:** test 42-06 `sc03_sc04_bymobille_e2e_analise_ml` falhou no `assertSame('MLB123', $sugador->mlb_id)`. Sem o test E2E, este bug iria em producao.

### Bug 2 — Http::fake([...]) acumula stubs em Laravel (commit 7d6544a)

Quando suites rodam multiplos cenarios em sequencia (ex: 1a analise depois 2a no mesmo teste; SC#5a depois SC#6a), `Http::fake([...])` em Laravel **acumula** stubs (nao substitui). O stub antigo continua ativo e ganha o "first match" contra wildcards. Resultado: 2a chamada HTTP recebia payload da 1a, mascarando assercoes corretas e impedindo atualizacao de metricas em re-analise.

**Fix:** limpar `stubCallbacks` via reflection no Factory singleton antes de adicionar novos fakes. Aplicado em AceitacaoMlFluxoCompletoTest e CutOverMlPrimaryTest.

**Impacto sem o fix:** assercoes de re-analise (status travado + metricas atualizando) nunca passariam, mesmo com o codigo correto.

## Decisoes Locked (D-01..D-08) Honradas

- **D-01:** Opcao B do briefing §8 — campo `cpc_minimo_cliques` adicionado + UI + gate composto no evaluator. Plans 42-01 + 42-02 + 42-03.
- **D-02:** Item sidebar 'Onboarding ML' escondido; rota + controller + Index.jsx + Show.jsx intactos. Plan 42-05.
- **D-03:** Janela 30 dias fechados (ontem-29d → ontem) com comentario rastreabilidade. Plan 42-03.
- **D-04:** `sugador_configs` reaproveitado. Nenhuma tabela nova criada para "sugador ML". Todos os plans.
- **D-05:** Fluxo `API ML → normalizer → SugadorAnalysisService → tabela sugadores → /sugadores`. Plan 42-03 + 42-04.
- **D-06:** STATUS_TRAVADOS preservados em re-analise ML. Plan 42-04.
- **D-07:** Quarentena SGI por nome de campanha. Plan 42-03.
- **D-08:** ByMobille - Teste (#298) e o piloto. Plan 42-04 + 42-06.

## Por que `human_needed` e nao `passed`

Conforme decisao tree padrao do verifier: mesmo com 10/10 truths verificados via codebase + suite Phase 42 verde, o status `passed` so vale quando nenhuma verificacao humana esta pendente. Phase 42 entrega integracao com sistemas externos (API ML real, MariaDB de prod, navegador admin) que exigem:

1. Validacao visual da UI no browser
2. Smoke real do `sugadores:analyze --company=298 --provider=ml` no VPS
3. Validacao de paridade ML vs Adman antes da Phase 43

5 itens detalhados no frontmatter `human_verification:`.

## Regression Gate

- Suite Phase 42 acumulada: 49 verdes (1 skipped intencional — cenario impossivel por schema NOT NULL)
- Sugador suite legacy: pass
- Phase 30-41 suites: pass
- 6 falhas em Phase 38 PolosControllerTest: PRE-EXISTENTES (confirmadas em commit pre-Phase-41 `b9441ed`). Phase 42 adicionou ZERO regressoes.

Automated verification completa. Aguardando verificacao humana antes de marcar `passed`.
