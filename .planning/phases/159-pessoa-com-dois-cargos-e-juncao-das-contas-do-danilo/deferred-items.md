# Itens fora de escopo — Fase 159

## 159-04 — Task 3

**3 falhas pré-existentes em `tests/Feature/Phase61/` não tocadas por este plano.**

- `PortfolioMultiFonteE2ETest::test_flag_on_portfolio_own_admin_...` (nome truncado no log)
- `PortfolioMultiFonteE2ETest::test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts`
- `PortfolioSourceEnrichmentTest::test_flag_on_portfolio_own_admin_enriquece_user_portfolios_com_source_counts`

Sintoma: `Property [user_portfolios] does not have the expected size. Failed asserting
that actual size 0 matches expected size 1.`

**Confirmado pré-existente:** `renderCarteirasConsolidadas()` (método que monta a prop
`user_portfolios`, linha ~1189-1519 de `PortfolioController.php`) não referencia
`cargo`/`user_setores`/`CargosDesempenho` em nenhum ponto — nenhuma edição da Task 3
(159-04) passa perto deste método. Verificado ao vivo: revertido temporariamente
`app/Http/Controllers/PortfolioController.php` para o estado do commit `70268a4a`
(fim da Task 2, antes de qualquer edição da Task 3) via `git checkout -- <arquivo>` e
reexecutado `tests/Feature/Phase61/` — as MESMAS 3 falhas ocorrem, byte a byte. Edições
da Task 3 restauradas em seguida (cópia local, sem `git stash`).

Não estavam na baseline (`159-BASELINE-TESTES.md`, que cobre 18 grupos + Phase119 +
`npm run test:js`) nem no escopo de verificação do `159-04-PLAN.md` (que lista
`RankingDoisCargosTest`, `NpsPorEmpresaContratoTest`,
`Phase123/AuditoriaBonusNotaEmpresaTest`, `Phase119`, `V16/ComparacaoContextualBlockedTest`
e o diff vazio do motor). Registrado aqui por disciplina (SCOPE BOUNDARY) — não corrigido,
não investigado a causa raiz.
