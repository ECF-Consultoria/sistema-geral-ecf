# Deferred Items — Fase 97

Itens encontrados durante a execução do Plan 97-01 que estão FORA do escopo
desta fase (não tocados, conforme regra de escopo do executor).

## 1. `PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200` falha pré-existente

- **Onde:** `tests/Feature/PublicacaoDesempenhoRouteTest.php` (rota `/publicacao/desempenho`, `PerformanceController`).
- **Sintoma:** `assertStatus(200)` recebe `403`.
- **Por que é fora de escopo:** não tem relação com `DashboardController::adminDashboard` (arquivo único desta fase 97-01). Falha reproduzida em isolamento (`--filter=PublicacaoDesempenhoRouteTest`), antes e depois das mudanças deste plano — não foi introduzida pelas alterações de janela período-anterior/margem ponderada/marketplace.
- **Ação:** não corrigido aqui. Investigar em quick task ou fase própria de Performance/Publicação.

## 2. Bug latente — `whereBetween('reference_date', ...)` com string `Y-m-d` pura no limite superior

- **Onde:** `app/Http/Controllers/DashboardController.php::adminDashboard` — bloco híbrido de revenue (`$sumDbPorEmpresa`, `$sumDb`, `$adSpendDbPorEmpresa`), todos usando `whereBetween('reference_date', [$dateFromN, $dateToN])` PRÉ-EXISTENTE (não introduzido pelo Plan 97-01).
- **Descoberta:** ao investigar o Plan 97-01 (janela período-anterior), confirmou-se que a coluna `adman_metrics.reference_date` (cast `date` no model) persiste no SQLite com sufixo `" 00:00:00"`. Uma comparação `whereBetween` com string pura `"Y-m-d"` no limite superior exclui o último dia do intervalo (comparação lexicográfica: `"2026-06-19 00:00:00" > "2026-06-19"`).
- **Impacto potencial em produção:** quando `$dateToN` é "hoje" e já existe sync do dia corrente em `adman_metrics`, o dado de hoje seria silenciosamente excluído do fallback DB. Na prática o sync roda para D-1, então o impacto real é provavelmente baixo, mas não foi auditado.
- **Ação:** minha própria query nova (janela período-anterior) foi corrigida usando limite superior EXCLUSIVO (`< $currentFromN`) para não herdar o bug. As queries PRÉ-EXISTENTES (revenue/ad_spend híbrido) não foram tocadas — fora do escopo do Plan 97-01 (scope boundary: só o que este plano introduziu). Recomendado abrir quick task para auditar/corrigir os `whereBetween` pré-existentes se afetar produção (usar MySQL, não SQLite — confirmar se o comportamento se repete lá).
