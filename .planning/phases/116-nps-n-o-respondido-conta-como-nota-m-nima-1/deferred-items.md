# Fase 116 — Itens Deferidos (fora de escopo)

Registrado durante a execução do Plan 02 (2026-07-27). Estes achados foram
descobertos ao rodar os gates de regressão (`--filter=Desempenho`,
`--filter=Nps`, `--filter=Bonus`) e são **PRÉ-EXISTENTES** — confirmados via
`git diff` vazio nos arquivos envolvidos (nenhum tocado por este plano) e/ou
reprodução idêntica em execução isolada (`--filter=<ClasseDoTeste>`), sem
nenhum arquivo da Fase 116 carregado.

## 1. Baseline de 14 falhas em `--filter=Desempenho` (var_margem_pct/AdmanMetricDiffService)

Já documentado no prompt de execução do Plan 02 e no `116-01-SUMMARY.md`.
Causa raiz: commit `25a958b3` de outra sessão paralela (fallback de billing em
`app/Services/Metrics/AdmanMetricDiffService.php`), feito no mesmo dia. Arquivo
explicitamente fora de escopo — não editado.

Confirmado estável em 3 execuções do Plan 02 (antes e depois do wiring do
ramo C): sempre **14 failed / 90 passed** em `--filter=Desempenho` (as 90
incluem os 7 testes novos de `NpsFloorDesempenhoTest`).

## 2. `V18/JanelaNpsBonusTest::test_competencia_fechada_le_nps_de_m_mais_1` — instabilidade de margem

- **Sintoma:** `nota_final` esperado 3.32, obtido 3.99 (delta = variação de
  ~2 pontos em `margemPontos`; a parte de NPS do teste, 4.97, bate certinho —
  não é o ramo novo desta fase que está errado).
- **Causa:** mesma instabilidade documentada em
  `.planning/debug/project_adman_margem_diff_instavel_bonus` (aberto
  2026-07-23, "NÃO é regressão da Fase 109") — o `.diff` nativo da Adman
  retorna valores diferentes a cada recompute para o mesmo mês fechado.
- **Confirmado pré-existente:** `git diff --stat -- tests/Feature/V18/JanelaNpsBonusTest.php`
  vazio (arquivo não tocado por este plano); falha reproduzida em execução
  100% isolada (`--filter=JanelaNpsBonusTest`, sem nenhum outro arquivo da
  Fase 116 carregado).
- **Ação:** não corrigido — `AdmanMetricDiffService` está fora do escopo
  desta fase (instrução explícita do executor).

## 3. `Phase31NpsSubmitTest` / `Phase69/NpsPhase69IntegrationTest` — `expires_at` de survey manual

- **Sintoma:** `expires_at` de survey manual (`generate()`) sai ~3-4 dias
  menor do que `now()->addDays(7)` esperado pelo teste.
- **Confirmado pré-existente e NÃO relacionado à Fase 116:**
  - `git diff --stat` vazio para `tests/Feature/Phase31NpsSubmitTest.php`,
    `tests/Feature/Phase69/NpsPhase69IntegrationTest.php` e
    `app/Http/Controllers/NpsController.php` — nenhum tocado por este plano.
  - Falha reproduzida em execução 100% isolada
    (`--filter=Phase31NpsSubmitTest`, sem nenhum outro arquivo carregado).
- **Ação:** não investigado a fundo (fora do escopo do Plan 02 — a rota de
  disparo manual do NPS, `NpsController::generate()`, não foi tocada por
  nenhuma task deste plano). Recomenda-se abrir debug dedicado se persistir
  quando a Fase 116 fechar.

## 4. `V18/ConsolidarMesJanelaNpsTest` (2 falhas)

Já documentado no `116-01-SUMMARY.md` como pré-existente (congelamento de
snapshot mensal via `desempenho:consolidar-mes`). Confirmado novamente aqui
— `git diff --stat` vazio para o arquivo.

## 5. `--filter=Portfolio` / `--filter=Carteira` — 3+2 falhas descobertas no Plan 04, PRÉ-EXISTENTES

Descoberto ao rodar o gate de regressão do Plan 04 (`PortfolioController::renderPortfolio` —
histórico NPS mensal). Nenhuma tem relação com NPS.

- **`Phase61/PortfolioMultiFonteE2ETest`** (2 falhas) + **`Phase61/PortfolioSourceEnrichmentTest`**
  (1 falha) — `user_portfolios` vem com tamanho 0 (esperado 1) em
  `Portfolio/Carteiras` (rota `portfolio.own` como admin →
  `renderCarteirasConsolidadas()`, método DIFERENTE do editado por este plano).
  **Confirmado pré-existente:** substituí temporariamente
  `app/Http/Controllers/PortfolioController.php` pela versão do commit anterior
  ao Plan 04 (`git show HEAD:...`), rodei os 2 arquivos de teste isoladamente —
  as mesmas 3 falhas se reproduziram identicamente sem nenhuma linha do Plan 04
  presente. Restaurada a versão editada em seguida (confirmado via
  `git diff --stat`).
- **`V18/CarteiraPeriodoDiffTest`** (2 falhas) — `margem_variacao_pct` vem
  `null`/divergente do esperado. Mesma família de instabilidade de
  `AdmanMetricDiffService` (item 1/2 acima). As rotas exercitadas neste teste
  (`portfolio.show` como ADMIN vendo OUTRO profissional) chamam
  `renderCarteiraProfissional()` — método que o Plan 04 **não tocou** (o Plan 04
  editou só `renderPortfolio()`, usado exclusivamente na self-view).

**Ação:** não corrigido — fora do escopo do Plan 04 (`AdmanMetricDiffService` e
`renderCarteirasConsolidadas`/`renderCarteiraProfissional` não fazem parte dos
`files_modified` do plano). Recomenda-se abrir debug dedicado se persistir
quando a Fase 116 fechar.

---

**Resumo para o verificador:** nenhum destes 5 itens foi causado pelas
mudanças do Plan 02 (`app/Services/DesempenhoScoreService.php`,
`NpsImputationService` consumido via `notasImputadas()`, ou os testes novos/
modificados listados no `116-02-SUMMARY.md`) nem do Plan 04
(`app/Http/Controllers/PerformanceController.php::notasNpsDoUsuarioPorResposta`,
`app/Http/Controllers/PortfolioController.php::renderPortfolio`). Todos
confirmados por `git diff` vazio no arquivo afetado e/ou reprodução isolada
(incluindo, no caso do item 5, reversão temporária do arquivo editado).

## 6. `--filter=Company` — 5 falhas descobertas no Plan 05, PRÉ-EXISTENTES e SEM RELAÇÃO com NPS

Descoberto ao rodar o gate de regressão do Plan 05 (`CompanyController::show()` —
card `nps_avg`). Nenhuma tem relação com NPS ou com os arquivos editados por
este plano (`DashboardController.php`, `CompanyController.php`,
`CalculateGoalResults.php`).

- **`Tests\Unit\CompanyServiceTypeTest::service_type_aceita_polo`** — teste de
  enum/valor de `service_type` no model `Company`, não relacionado a NPS.
- **`Tests\Feature\Phase13MigrationTest::migracao_retroativa_preenche_todos_company_ids`**
  — teste de migration retroativa de `company_id` em `mlb_empresas` (Phase 13,
  2026-05).
- **`Tests\Feature\Phase42\AnalyzeCompanyMlWindowQuarantineTest`** (2 falhas:
  `fetch_adgroups_metrics_retorna_contrato_completo_22_chaves` e
  `fetch_adgroups_metrics_fail_open_em_list_campaigns_quebrado`) — testes que
  dependem de chamada HTTP real à API do Mercado Livre (uma delas levou 63s,
  sintoma de tentativa de rede real em vez de mock), sem qualquer relação com
  NPS/Company.
- **`Tests\Feature\Phase75\RascunhoCompanyIdImutavelTest::atualizar_rascunho_ignora_company_id_e_mlb_empresa_id`**
  — falha `[MLB Coleta] Falha ao obter app token: HTTP 400` (dependência de
  rede externa ML, não mockada nesta execução).

**Confirmado pré-existente:** `git diff --stat` (commits deste plano) vazio
para `app/Models/Company.php`, as migrations envolvidas, os 4 arquivos de
teste acima e `app/Http/Controllers/MlbAnuncioController.php` — nenhum tocado
pelas 3 tasks do Plan 05. Os testes de `CompanyController::show()`
efetivamente exercitados pelas mudanças deste plano (`Phase61/CompanyShowSourceTest`,
`Phase62/CompanyShowGoalsPayloadTest`, `Phase68/NpsBackwardCompatTest`,
`CompanyPortfolioAccessTest`, `Phase96/NpsInvalidacaoCallSitesTest` callsite
#10, `Phase72/DashboardPendencyPropsTest`) — 19/19 verdes.

**Ação:** não corrigido — fora do escopo do Plan 05 (dependências de rede
externa e migrations/models não tocados). Recomenda-se abrir debug dedicado
se persistir quando a Fase 116 fechar.
