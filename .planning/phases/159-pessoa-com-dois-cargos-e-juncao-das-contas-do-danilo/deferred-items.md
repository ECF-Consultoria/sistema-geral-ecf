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

**Reconferido pelo orquestrador contra `origin/main` PURO (2026-09-30):** o teste acima
reverteu só o `PortfolioController.php`, com os planos 01–03 ainda aplicados — não provava
nada sobre a migration de `user_setores`. Rodado num worktree destacado de `origin/main`
(`5d0a3997`, sem nenhuma mudança da fase, com `public/build` copiado):
`tests/Feature/Phase61/` = **31 testes, 3 falhas — as mesmas três**. Pré-existentes de fato.
Sem o manifest do Vite o mesmo diretório dá 30/31 falhas (`Vite manifest not found`) — não
confundir com regressão.

Não estavam na baseline (`159-BASELINE-TESTES.md`, que cobre 18 grupos + Phase119 +
`npm run test:js`) nem no escopo de verificação do `159-04-PLAN.md` (que lista
`RankingDoisCargosTest`, `NpsPorEmpresaContratoTest`,
`Phase123/AuditoriaBonusNotaEmpresaTest`, `Phase119`, `V16/ComparacaoContextualBlockedTest`
e o diff vazio do motor). Registrado aqui por disciplina (SCOPE BOUNDARY) — não corrigido,
não investigado a causa raiz.

## Rodada de correção (pós-revisão) — mais 3 falhas pré-existentes

`tests/Feature/Phase38Publicador/MeuPainelControllerTest.php` (2: `test_meu_painel_passa_props_novas`,
`test_sem_publicacoes`) e `tests/Feature/PublicacaoDesempenhoRouteTest.php` (1:
`test_user_com_mlb_dashboard_acessa_rota_e_recebe_200`). **Reconferido pelo orquestrador contra
`origin/main` PURO** (`d01ef00b`, worktree destacado, sem nenhuma mudança da fase, com
`public/build`): 9 testes, as MESMAS 3 falhas. Não são regressão da Fase 159.

## 159-07 — Task 1 (regressão final)

**`tests/Feature/Dashboard/DashboardWidgetsRecorteTest.php` — 2 falhas por fronteira de
calendário (dia 1 do mês), não relacionadas à fase.**

- `test_novas_empresas_traz_cards_por_inicio_de_contrato` e
  `test_novas_empresas_sem_faturamento_fica_ramp_up`.
- `git diff 5d0a3997 HEAD -- app/Http/Controllers/DashboardController.php` e o próprio arquivo
  de teste: ambos vazios — a Fase 159 não toca nenhum dos dois.
- Causa: `DashboardController.php:465-477` filtra `novas_empresas` por
  `whereBetween(data_contratacao, [inicio_do_mes, hoje])`. Os testes criam a empresa com
  `Carbon::now()->startOfMonth()->addDays(2)`/`addDays(1)`. Em 30/09 (data do baseline) isso
  caía no passado (início de setembro); em 01/10 (data desta rodada) cai no FUTURO (02/10,
  03/10), fora da janela — por isso passava no baseline e falha hoje. Reproduz em `origin/main`
  puro rodado hoje; é propriedade do calendário, não do código desta fase. Não corrigido (fora
  do escopo de arquivos desta fase — SCOPE BOUNDARY).

**`tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` — 2 erros + 1 falha, de outra sessão
já em `origin/main`.**

- `ArgumentCountError` em `SyncPolosFaturamentoJob::handle()` (2 erros) e
  `test_cache_fresco_prevalece_sobre_snapshot` (1 falha).
- `git log --oneline origin/main..HEAD -- app/Jobs/SyncPolosFaturamentoJob.php` vazio — quem
  mudou a assinatura do job foi `a4e95842 feat(polos): ADS ligado/desligado automatico pela
  Adman (TKT-0003)`, commit de outra sessão (30/09 16:53) que entrou em `origin/main` via
  rebase, não um commit da Fase 159.
- Já documentado em `.planning/learnings/painel-polos-status-e-meta.md` §2 como padrão de
  falha pré-existente conhecido da suíte de Polos. Esta suíte não fazia parte do
  `159-BASELINE-TESTES.md` original (coletado antes de `a4e95842` existir); entrou nesta
  rodada por exigência do `<project_overrides>` do plano 159-07. Não corrigido (fora do
  escopo de arquivos desta fase).

Detalhes completos da investigação em `159-REGRESSAO-FINAL.md` §3.3.
