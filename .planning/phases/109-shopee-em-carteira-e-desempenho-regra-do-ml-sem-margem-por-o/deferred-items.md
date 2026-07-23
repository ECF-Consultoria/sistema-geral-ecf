# Deferred Items — Fase 109 Plano 02

## Pré-existente (NÃO causado pelo Plano 02) — Phase61 `attachCarteira` sem vínculo resolvível

**Descoberto durante:** verificação de regressão ampla do Plano 02 (2026-07-23).

**Testes afetados (2, ambos em `renderCarteirasConsolidadas` via `portfolio.own`):**
- `tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php::test_flag_on_portfolio_carteiras_admin_expoe_source_counts_por_user`
- `tests/Feature/Phase61/PortfolioMultiFonteE2ETest.php::test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts`
- `tests/Feature/Phase61/PortfolioSourceEnrichmentTest.php::test_flag_on_portfolio_own_admin_enriquece_user_portfolios_com_source_counts` (mesma causa raiz)

**Causa raiz:** o helper `attachCarteira()` desses testes usa `$user->companies()->attach($company->id, ['role' => 'consultor', ...])` — grava em `company_users` com `servico_id` NULL e SEM criar `contratos_servico` ativo pra empresa. Desde a Fase 88/89 (`CarteiraContextService::forUser()`), um vínculo `servico_id` NULL só resolve como Performance legado SE a empresa tiver contrato Performance ativo (`vinculosLegadoNull()`, CTX-05). Sem esse contrato, `forUser()` devolve vínculos vazios pro profissional, e `renderCarteirasConsolidadas()` já tinha (desde a Fase 90, `if ($vinculos->isEmpty()) return null;`) o comportamento de OMITIR o card do profissional sem vínculo algum — o card nunca aparece, `user_portfolios` fica vazio, e os testes (que esperam 1 card) falham.

**Confirmação de que é pré-existente:** reproduzido rodando a MESMA suíte contra o commit `HEAD` anterior a qualquer trabalho da Fase 109 (via `git worktree add` read-only em `d5a52cd2`, sem tocar a árvore principal) — os 2 testes já falhavam da mesma forma, com a mesma mensagem (`user_portfolios` tamanho 0 vs esperado 1). Nenhuma linha tocada pelo Plano 02 está no caminho que causa a falha (o gate `$vinculos->isEmpty()` é da Fase 90, intocado).

**Por que não corrigido aqui:** fora do escopo declarado do Plano 02 (files_modified não inclui esses testes; a causa raiz é uma fixture desatualizada de uma suíte de OUTRA fase — Phase 61 — que nunca foi migrada pro contrato do `CarteiraContextService` da Fase 88/89). Corrigir exigiria decidir se `attachCarteira()` passa a criar contrato Performance ativo (mudança de fixture, não de produto) ou se `renderCarteirasConsolidadas()` deveria voltar a aceitar vínculos legado sem contrato (mudança de comportamento, fora do escopo Shopee desta fase).

**Ação sugerida:** próxima fase/quick-task que tocar `PortfolioMultiFonteE2ETest`/`PortfolioSourceEnrichmentTest` deve atualizar `attachCarteira()` pra também criar um contrato Performance ativo (`contratos_servico`) — alinha a fixture ao contrato real do `CarteiraContextService` sem mudar nenhum comportamento de produto.
