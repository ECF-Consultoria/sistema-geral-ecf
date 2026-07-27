---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 04
subsystem: backend
tags: [nps, carteira, laravel, inertia, tdd, bonificacao]

requires:
  - phase: 116-01
    provides: "NpsImputationService (notasDoUsuario/materializarLote) + NpsImputedAssignment"
provides:
  - "PerformanceController::notasNpsDoUsuarioPorResposta() conta o NPS não respondido como nota 1 (3º ramo C) na coluna NPS, heatmap e últimas notas de dashboardCarteira"
  - "PortfolioController::renderPortfolio() conta o NPS não respondido como nota 1 no histórico NPS mensal do profissional"
  - "Shape uniforme 'imputada' => bool nas 3 fontes de nota do PerformanceController"
affects: [116-05, 116-06, dashboard-carteira, portfolio-show]

tech-stack:
  added: []
  patterns:
    - "União disjunta extensível a N ramos, agora também em PerformanceController (A atribuições / B legado / C imputadas) — mesmo padrão do DesempenhoScoreService::computeNpsMedio (Plan 116-02)"
    - "União de CHAVES de mês (reais ∪ imputadas) antes de agregar — necessário quando a query real só busca status=completed e um mês só com não-respondido nunca apareceria nela"
    - "Cast explícito pra Illuminate\\Support\\Collection antes de merge() quando o array mesclado não é de Models (mesma armadilha do Plan 116-03)"

key-files:
  created:
    - tests/Feature/Phase116/NpsFloorCarteiraTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
    - app/Http/Controllers/PortfolioController.php
    - .planning/phases/116-nps-n-o-respondido-conta-como-nota-m-nima-1/deferred-items.md

key-decisions:
  - "Nem PerformanceController nem PortfolioController filtram as notas imputadas por ->principal()/template — mesma semântica do ramo (C) do DesempenhoScoreService (Plan 116-02), que também não restringe por modelo. Colateral aceito: o histórico NPS mensal do Portfolio (que hoje só mostra RESPOSTAS ->principal()) passa a mostrar não-respondidos de QUALQUER modelo coberto — mantém paridade com o bônus multi-modal (C5) em vez de herdar a limitação legada do widget."
  - "PortfolioController::renderPortfolio precisou UNIR as chaves de mês (reais ∪ imputadas) antes de agregar — a query real só busca survey status=completed, então um mês com só não-respondido nunca apareceria nela sozinho."
  - "notasDoUsuario() chamada sem janela de data restrita (Carbon 1970-01-01 até now()) em PortfolioController — espelha a query real, que também não filtra por período (varre o histórico inteiro)."

requirements-completed: [NPSFLOOR-01, NPSFLOOR-02, NPSFLOOR-10]

duration: ~55min
completed: 2026-07-27
---

# Fase 116 Plano 04: Carteira do profissional conta o não respondido como nota mínima (1) Summary

**`PerformanceController::notasNpsDoUsuarioPorResposta()` (coluna NPS/heatmap/últimas notas de `dashboardCarteira`) e `PortfolioController::renderPortfolio()` (histórico NPS mensal) — os DOIS consumidores de carteira do profissional — passam a contar o NPS efetivamente disparado e não respondido como nota 1, delegando 100% para `NpsImputationService` (Plan 01).**

## Performance

- **Duration:** ~55 min
- **Completed:** 2026-07-27
- **Tasks:** 3
- **Files modified:** 3 (1 suíte nova + 2 controllers)

## Accomplishments

- `PerformanceController::notasNpsDoUsuarioPorResposta()` ganha o 3º ramo (C) — notas imputadas via `NpsImputationService::notasDoUsuario()`, filtrado por `$companyIds` (mesmo recorte já aplicado ao ramo A) — a coluna NPS da tabela de empresas, o heatmap e as "últimas respostas" da carteira agora contam o survey não respondido como nota 1. Data sintética `competencia_nps->copy()->endOfMonth()` documentada inline (não existe `completed_at` numa linha imputada).
- `'imputada' => false` adicionado aos ramos (A)/(B) pra uniformizar o shape com o novo ramo (C) — o front (fora do escopo deste plano) poderá diferenciar nota real de nota imputada no futuro (Plan 05/06).
- `PortfolioController::renderPortfolio()` (histórico NPS mensal do profissional — implementação PRÓPRIA, sem relação de código com `PerformanceController`) busca as notas imputadas do usuário via `notasDoUsuario()`, agrupa por `competencia_nps->format('Y-m')` e mescla com as notas reais ANTES de calcular a média de cada mês. Como a query real só busca `status=completed`, um mês só com survey não respondido nunca apareceria nela sozinho — foi preciso UNIR as chaves de mês (reais ∪ imputadas) antes de agregar.
- Disjunção por construção preservada nos dois controllers: ramos (A)/(B) exigem `status=completed`; o ramo/bloco (C)/imputado só existe para survey que nunca chegou a `completed` — nenhum survey conta duas vezes (provado pelo teste 6).
- Suíte nova `tests/Feature/Phase116/NpsFloorCarteiraTest.php` (6 testes, 85 assertions), exercitada 100% pelo caminho REAL (`GET /dashboard` e `GET /admin/users/{user}/portfolio` como o próprio profissional + leitura de props Inertia) — nenhum método privado invocado por reflection, conforme instrução explícita do plano.

## Task Commits

Cada task foi commitada atomicamente:

1. **Tarefa 1 (RED): suíte dos call-sites de carteira** - `87bf2148` (test)
2. **Tarefa 2 (GREEN): PerformanceController — 3º ramo imputado** - `804acdf6` (feat)
3. **Tarefa 3 (GREEN): PortfolioController — histórico NPS mensal** - `3d1129f3` (feat)

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `tests/Feature/Phase116/NpsFloorCarteiraTest.php` (novo) — 6 testes provando os 2 call-sites de carteira (coluna NPS + histórico mensal), sem dupla contagem, invariante D3 (empresa sem disparo), e disjunção (survey respondido não gera linha imputada).
- `app/Http/Controllers/PerformanceController.php` — ramo (C) em `notasNpsDoUsuarioPorResposta()`, `'imputada'` no shape dos 3 ramos, docblock atualizado (união DISJUNTA de três ramos).
- `app/Http/Controllers/PortfolioController.php` — bloco do histórico NPS mensal reescrito pra unir meses reais + imputados e mesclar as notas antes da média.
- `.planning/phases/116-.../deferred-items.md` — item 5: 5 falhas pré-existentes descobertas no gate de regressão do Plan 04 (Portfolio/Carteiras admin + margem `AdmanMetricDiffService`), nenhuma causada por este plano.

## Decisions Made

- Nenhum dos dois controllers filtra as notas imputadas por template `->principal()` — mesma escolha já feita em `DesempenhoScoreService::notasImputadas()` (Plan 116-02), que também agrega qualquer modelo coberto. Isso mantém a carteira consistente com o número do bônus (o objetivo central do Pitfall 4 herdado da Fase 96, citado no `<objective>` do plano) em vez de preservar a restrição legada do widget de Portfolio (que hoje só soma respostas REAIS do modelo principal). Efeito colateral aceito e documentado inline no código.
- `notasDoUsuario()` foi chamada em `PortfolioController` sem restringir a janela de datas (`Carbon::createFromDate(1970, 1, 1)` até `now()`) — a query real do histórico também não tem filtro de período (varre o histórico inteiro desde sempre), então restringir só o lado imputado quebraria a paridade entre as duas fontes.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixture de teste precisou de template `is_default=true` (principal) pra provar a coexistência com resposta real no Portfolio**
- **Found during:** Tarefa 1/3 (teste `test_survey_respondido_nao_gera_linha_imputada_nos_widgets_carteira`).
- **Issue:** `PortfolioController::renderPortfolio()` só conta respostas REAIS do template `->principal()` no histórico mensal (comportamento pré-existente, não alterado por este plano). O teste inicial criava um template comum (não principal), então a resposta real nunca aparecia na query real — o teste falhava por ausência de dado, não por bug de produção.
- **Fix:** parametrizado `criarTemplateEscopado(..., principal: true)` no cenário que precisa da resposta real refletida no histórico do Portfolio — mesma técnica já usada em `NpsInvalidacaoCallSitesTest::criarTemplateEscopado`.
- **Files modified:** `tests/Feature/Phase116/NpsFloorCarteiraTest.php`.
- **Verification:** `--filter=NpsFloorCarteiraTest` 6/6 verde.
- **Committed in:** `87bf2148` (parte do commit RED da Tarefa 1).

**2. [Rule 3 - Blocking] `grep -c "notasDoUsuario"` bateu 2 em vez de 1 (acceptance criteria da Tarefa 2)**
- **Found during:** Tarefa 2 (GREEN), após escrever o docblock do ramo (C).
- **Issue:** o docblock citava `NpsImputationService::notasDoUsuario` por nome, duplicando a única ocorrência de código esperada pelo grep de aceite.
- **Fix:** reescrito o trecho do docblock pra "leitura via `NpsImputationService`" (sem citar o nome literal do método) — mesma técnica documentada no `116-01-SUMMARY.md` pra não contaminar greps automatizados com menções em comentário.
- **Files modified:** `app/Http/Controllers/PerformanceController.php`.
- **Verification:** `grep -c "notasDoUsuario" app/Http/Controllers/PerformanceController.php` → `1`.
- **Committed in:** `804acdf6` (parte do commit da Tarefa 2).

---

**Total deviations:** 2 auto-fixed (1 ajuste de fixture de teste, 1 ajuste de redação de comentário). Nenhum desvio de comportamento de produção — ambos necessários pra fechar o ciclo RED→GREEN conforme especificado.

## Issues Encountered

Gate de regressão completo, comparado à baseline informada no prompt de execução (14 Desempenho / 5 Nps):

| Gate | Failed | Passed | vs. baseline |
|------|--------|--------|---------------|
| `--filter=NpsFloorCarteiraTest` | 0 | 6 | novo — GREEN |
| `--filter=Performance` | 2 | 76 | **mesma família da baseline** — `DesempenhoShopeeScoreTest::so_performance_regressao...` e `V16/PerformanceIndexMetadadosTest::ranking_inclui_os_6_metadados...` falham por `var_margem_pct`/`score_status` (instabilidade de `AdmanMetricDiffService`, debug aberto 2026-07-23); o método editado (`notasNpsDoUsuarioPorResposta`) é exclusivo de `dashboardCarteira`, nenhuma das duas suítes o exercita |
| `--filter=Nps` | 5 | 361 | **idêntico à baseline** (mesmos 5 nomes documentados em `116-01`/`116-02`/`116-03`-SUMMARY) |
| `--filter=Desempenho` | 14 | 90 | **idêntico à baseline** (confirmado em execução paralela durante esta plano, ver abaixo) |
| `--filter=Portfolio` | 3 | 48+ | **NOVA descoberta, PRÉ-EXISTENTE** — `Phase61/PortfolioMultiFonteE2ETest` (2) + `Phase61/PortfolioSourceEnrichmentTest` (1), rota `portfolio.own` como admin (`renderCarteirasConsolidadas`, método DIFERENTE do editado). Confirmado por reversão temporária de `PortfolioController.php` pra o commit anterior a este plano + reprodução isolada idêntica — ver `deferred-items.md` item 5 |
| `--filter=Carteira` | 4 | 105 | as 3 falhas de Portfolio acima + `V18/CarteiraPeriodoDiffTest` (2, `margem_variacao_pct`/`AdmanMetricDiffService`, rota exercitada é `renderCarteiraProfissional`, também não tocada por este plano) |

Nenhuma falha nova foi introduzida pelas mudanças deste plano. As 5 falhas de Portfolio/Carteira descobertas durante o gate de regressão (não estavam nos 4 itens já documentados em `deferred-items.md`) foram investigadas e confirmadas pré-existentes por reversão temporária do arquivo editado + reprodução isolada — documentadas como item 5 novo em `deferred-items.md`.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Os 3 consumidores mais visíveis da regra (bônus/Plan 02, área NPS/Plan 03, carteira/Plan 04) já delegam 100% para `NpsImputationService` — nenhum reimplementa a resolução de responsável/competência/invalidação.
- Payload do `PerformanceController::dashboardCarteira` já carrega `imputada` no shape interno (`notasNpsDoUsuarioPorResposta`), mas NENHUM campo novo foi exposto ao front nesta plano (fora do escopo — UI é Plan 05, conforme `<objective>` do PLAN.md: "Nenhuma mudança de frontend nesta wave").
- Bloqueador pendente e FORA do escopo desta fase: as falhas pré-existentes de `var_margem_pct`/`AdmanMetricDiffService` (agora confirmadas afetando também `--filter=Portfolio`/`--filter=Carteira`, não só `Desempenho`/`Nps`) devem ser resolvidas em debug dedicado antes do fechamento final da milestone, mas não bloqueiam o andamento da Fase 116.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os arquivos criados/modificados (`NpsFloorCarteiraTest.php`, `PerformanceController.php`, `PortfolioController.php`, `deferred-items.md`, este SUMMARY.md) confirmados no disco; os 3 hashes de commit (`87bf2148`, `804acdf6`, `3d1129f3`) confirmados via `git log --oneline --all`.
