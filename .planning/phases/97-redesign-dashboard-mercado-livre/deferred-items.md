# Deferred Items — Fase 97

Itens encontrados durante a execução do Plan 97-01 que estão FORA do escopo
desta fase (não tocados, conforme regra de escopo do executor).

## 1. `PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200` falha pré-existente

- **Onde:** `tests/Feature/PublicacaoDesempenhoRouteTest.php` (rota `/publicacao/desempenho`, `PerformanceController`).
- **Sintoma:** `assertStatus(200)` recebe `403`.
- **Por que é fora de escopo:** não tem relação com `DashboardController::adminDashboard` (arquivo único desta fase 97-01). Falha reproduzida em isolamento (`--filter=PublicacaoDesempenhoRouteTest`), antes e depois das mudanças deste plano — não foi introduzida pelas alterações de janela período-anterior/margem ponderada/marketplace.
- **Ação:** não corrigido aqui. Investigar em quick task ou fase própria de Performance/Publicação.

## 2b. `Phase31NpsSubmitTest`/`NpsPhase69IntegrationTest` — falhas pré-existentes de `expires_at` (generate manual), fora de escopo do Plan 97-02

- **Onde:** `tests/Feature/Phase31NpsSubmitTest.php::generate_cria_survey_com_auto_generated_false` e `tests/Feature/Phase69/NpsPhase69IntegrationTest.php::fluxo_2_generate_manual_por_admin_estrategista`.
- **Sintoma:** `expires_at` do survey gerado manualmente não bate com "hoje + 7 dias" (ex.: espera `2026-07-27`, recebe `2026-07-31`).
- **Por que é fora de escopo:** reproduzido em isolamento (`--filter=Phase31NpsSubmitTest`, sem tocar em nenhum arquivo do Plan 97-02) — falha antes e depois das mudanças deste plano. Não tem relação com `DashboardController::adminDashboard` nem com os arquivos declarados em `97-02-PLAN.md` (`files_modified`). Provável drift de data do ambiente de teste (hoje = 2026-07-20 no projeto; o fixture pode assumir uma data-base diferente) — não investigado a fundo.
- **Ação:** não corrigido aqui. Investigar em quick task própria do módulo NPS (geração manual, REQ-31-08).

## 2. Bug latente — `whereBetween('reference_date', ...)` com string `Y-m-d` pura no limite superior

- **Onde:** `app/Http/Controllers/DashboardController.php::adminDashboard` — bloco híbrido de revenue (`$sumDbPorEmpresa`, `$sumDb`, `$adSpendDbPorEmpresa`), todos usando `whereBetween('reference_date', [$dateFromN, $dateToN])` PRÉ-EXISTENTE (não introduzido pelo Plan 97-01).
- **Descoberta:** ao investigar o Plan 97-01 (janela período-anterior), confirmou-se que a coluna `adman_metrics.reference_date` (cast `date` no model) persiste no SQLite com sufixo `" 00:00:00"`. Uma comparação `whereBetween` com string pura `"Y-m-d"` no limite superior exclui o último dia do intervalo (comparação lexicográfica: `"2026-06-19 00:00:00" > "2026-06-19"`).
- **Impacto potencial em produção:** quando `$dateToN` é "hoje" e já existe sync do dia corrente em `adman_metrics`, o dado de hoje seria silenciosamente excluído do fallback DB. Na prática o sync roda para D-1, então o impacto real é provavelmente baixo, mas não foi auditado.
- **Ação:** minha própria query nova (janela período-anterior) foi corrigida usando limite superior EXCLUSIVO (`< $currentFromN`) para não herdar o bug. As queries PRÉ-EXISTENTES (revenue/ad_spend híbrido) não foram tocadas — fora do escopo do Plan 97-01 (scope boundary: só o que este plano introduziu). Recomendado abrir quick task para auditar/corrigir os `whereBetween` pré-existentes se afetar produção (usar MySQL, não SQLite — confirmar se o comportamento se repete lá).
