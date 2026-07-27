---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 05
subsystem: backend
tags: [nps, dashboard, laravel, inertia, tdd, bonificacao]

requires:
  - phase: 116-01
    provides: "NpsImputationService (notasDaEmpresa) + NpsImputedAssignment"
provides:
  - "DashboardController: widget admin (stats.avg_nps), ranking 'Desempenho da equipe' (buildRanking) e dashboard do usuário (stats.avg_nps) contam o NPS não respondido como nota 1"
  - "CompanyController::show() expõe 'company.nps_avg' — média oficial com o piso, sem alterar 'nps_surveys' (lista 'NPS Respondidos' continua só com respostas reais)"
  - "CalculateGoalResults::computeNps() conta o não respondido como nota 1 na meta de NPS, preservando null quando não há nenhuma nota (D3)"
affects: [116-06, 116-07, 116-08, dashboard-admin, dashboard-usuario, company-show, metas-nps]

tech-stack:
  added: []
  patterns:
    - "avgNotaDimensao() ganha 4º parâmetro opcional Collection $notasImputadas — mesclado antes do avg(), preservando a sentinela 0.0/null de coleção vazia"
    - "Escopo de modelo consistente: qualquer widget cuja query REAL usa ->principal() deve passar templateIds=[NpsTemplate::principalId()] também para as notas imputadas (admin dashboard, user dashboard, meta de NPS); quem não filtra modelo na query real (ranking, página da empresa) passa null"
    - "Cast explícito para Illuminate\\Support\\Collection antes de merge() quando a collection alvo é Eloquent\\Collection (herdada via ->map()/->filter()) — mesma armadilha de 116-03/116-04"

key-files:
  created:
    - tests/Feature/Phase116/NpsFloorDashboardsTest.php
  modified:
    - app/Http/Controllers/DashboardController.php
    - app/Http/Controllers/CompanyController.php
    - app/Jobs/CalculateGoalResults.php

key-decisions:
  - "CalculateGoalResults::computeNps() passa a filtrar as notas imputadas por templateIds=[principalId()] — o plano não mencionava esse filtro explicitamente, mas a query REAL de $responses já usa ->principal(); sem o mesmo filtro do lado imputado, a meta misturaria modelos (regra explícita do PLAN para os outros widgets restritos a principal). Aplicado por consistência (Rule 1/2)."
  - "CompanyController::show() ganha o campo NOVO 'nps_avg' no payload (média oficial, com o piso) — não existia nenhum cálculo de média no backend antes; o front (Companies/Show.jsx) hoje calcula 'avgNps' 100% client-side a partir de 'nps_surveys' (só respostas reais). Trocar o frontend para consumir 'nps_avg' é fora do escopo deste plano (files_modified do 116-05 não inclui nenhum .jsx) — nenhum plano posterior da fase (116-06/07/08) também toca Companies/Show.jsx, então a UI da página da empresa continua mostrando a média SEM o piso até uma tarefa dedicada de frontend acontecer. Documentado como limitação conhecida."
  - "notasDaEmpresa() em CompanyController/CalculateGoalResults sem janela de data restrita (Carbon 1970-01-01 até now() no card da empresa) — mesmo racional do Plan 116-04 (PortfolioController), pois a lista 'NPS Respondidos' hoje não tem corte de período (só limit 10 mais recentes)."

requirements-completed: [NPSFLOOR-01, NPSFLOOR-03, NPSFLOOR-10, NPSFLOOR-12]

duration: ~90min
completed: 2026-07-27
---

# Fase 116 Plano 05: Dashboards, página da empresa e meta de NPS contam o não respondido como nota mínima (1) Summary

**Os 5 call-sites restantes de agregação de NPS por empresa — widget do dashboard admin, ranking "Desempenho da equipe", dashboard do usuário, card de média da página da empresa (`nps_avg`, novo) e a meta de NPS (`CalculateGoalResults::computeNps`) — passam a contar o NPS efetivamente disparado e não respondido como nota 1, delegando 100% para `NpsImputationService::notasDaEmpresa()` (Plan 01).**

## Performance

- **Duration:** ~90 min
- **Completed:** 2026-07-27
- **Tasks:** 3
- **Files modified:** 4 (1 suíte nova + 3 arquivos de produção)

## Accomplishments

- `DashboardController::avgNotaDimensao()` ganhou um 4º parâmetro opcional `?Collection $notasImputadas` — quando informado, mescla as notas do não respondido antes do `avg()`, preservando a sentinela `0.0` de coleção vazia.
- `buildRanking()` (ranking "Desempenho da equipe") passa a buscar as notas imputadas da MESMA carteira (`$companyIds`) já usada para os surveys reais, sem filtro de modelo (regra do plano: ranking não filtra `->principal()`). O guard `$surveys->count() > 0` foi ajustado para `$surveys->count() > 0 || $notasImputadas->isNotEmpty()` — uma pessoa que só tem survey não respondido (zero surveys `completed`) deixa de cair no sentinela `null` antigo e passa a mostrar `avg_nps = 1.0`.
- `adminDashboard()` (widget `stats.avg_nps` da home) mescla as notas imputadas da dimensão **empresa** (D7), restritas ao modelo PRINCIPAL (`templateIds = [NpsTemplate::principalId()]`) — o mesmo recorte que `$npsResponses` já usa (`->principal()`).
- `userDashboard()` aplica a mesma regra em `stats.avg_nps`, também restrita ao modelo principal (o widget do dashboard do usuário também só olha `->principal()`, mesma restrição do admin — ajuste feito durante a execução ao descobrir que a query real do usuário também filtra por principal).
- `CompanyController::show()` ganha o campo **novo** `company.nps_avg` no payload — média oficial (respostas reais + notas imputadas da dimensão empresa, sem janela de data restrita) — sem tocar em `nps_surveys` (a lista "NPS Respondidos" continua eager-loaded com `status = 'completed'`, só respostas reais, mesmo `limit(10)` de sempre).
- `CalculateGoalResults::computeNps()` mescla as notas imputadas (dimensão empresa, mês/ano da meta, restrito ao modelo principal — mesmo recorte de `$responses`) antes de calcular a média da meta, preservando o retorno `null` quando não há NENHUMA nota (nem real, nem imputada) — invariante D3 intacto.
- Suíte nova `tests/Feature/Phase116/NpsFloorDashboardsTest.php` (6 testes, 56+ assertions): 1 teste por call-site do `<behavior>` do plano + 1 teste de invariante D3 (empresa sem nenhum survey no período preserva o comportamento atual em TODOS os 5 call-sites simultaneamente).

## Task Commits

Ciclo TDD RED → GREEN:

1. **Tarefa 1 (RED): suíte do piso de NPS em dashboards, empresa e meta** — `55be2206` (test) — 6/6 falhando confirmado antes de qualquer mudança de produção.
2. **Tarefa 2 (GREEN): DashboardController — avgNotaDimensao, widget admin e ranking** — `c3ef35c8` (feat).
3. **Tarefa 3 (GREEN): CompanyController (nps_avg) + CalculateGoalResults (computeNps)** — `2bff3464` (feat).
4. **Ajuste de fixtures + deferred-items** — `127049ae` (test) — correção de 2 fixtures de teste (servico coberto + contrato ativo para dimensão analista/estrategista; template `is_default=true` para o dashboard do usuário) descobertas ao rodar o GREEN, e registro dos itens novos de `deferred-items.md`.

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `tests/Feature/Phase116/NpsFloorDashboardsTest.php` (novo) — 6 testes: dashboard admin, ranking, dashboard do usuário, página da empresa (sem sujar a lista de respondidos), meta de NPS, e invariante D3 combinado.
- `app/Http/Controllers/DashboardController.php` — construtor com `NpsImputationService`; `avgNotaDimensao()` com o 4º parâmetro; `buildRanking()` com notas imputadas + guard ajustado; `adminDashboard()`/`userDashboard()` mesclando notas imputadas restritas ao modelo principal.
- `app/Http/Controllers/CompanyController.php` — cálculo novo de `nps_avg` (real + imputada, dimensão empresa) exposto no payload de `show()`, sem alterar `nps_surveys`.
- `app/Jobs/CalculateGoalResults.php` — `computeNps()` mescla notas imputadas restritas ao modelo principal; retorno `null` preservado quando não há nenhuma nota.

## Decisions Made

- **Escopo de modelo consistente por query real:** a régua do PLAN.md ("widget admin filtra principal; ranking e página da empresa não filtram") foi generalizada para "quem já filtra `->principal()` na query REAL de respostas também filtra as notas imputadas pelo mesmo `templateIds`" — isso incluiu o dashboard do usuário (que o PLAN não mencionou explicitamente, mas cuja query real também usa `->principal()`) e a meta de NPS (`CalculateGoalResults`, idem). Sem esse alinhamento, os dois lados da mesclagem (real vs. imputada) misturariam modelos diferentes na mesma média.
- **`nps_avg` é campo NOVO no backend, frontend não foi tocado:** antes deste plano, `CompanyController::show()` não calculava NENHUMA média de NPS — `Companies/Show.jsx` computa `avgNps` 100% client-side a partir de `nps_surveys` (só respostas reais). Este plano expõe a média OFICIAL (com o piso) em `company.nps_avg`, mas a troca do cálculo client-side pelo valor do backend fica para uma tarefa de frontend — nenhum dos `files_modified` deste plano ou dos planos 116-06/07/08 subsequentes toca `Companies/Show.jsx`. **Limitação conhecida:** a UI da página da empresa continua mostrando a média SEM o piso até essa tarefa acontecer; o `must_have` "o card de média de NPS da página da empresa conta o não respondido" está satisfeito no backend (dado disponível, testado), não ainda visível na tela.
- **Sem janela de data restrita em `CompanyController`/dimensão empresa do card:** mesma decisão já tomada no Plan 116-04 para `PortfolioController` — a lista "NPS Respondidos" hoje não tem corte de período (só `limit(10)` mais recentes), então `notasDaEmpresa()` usa `Carbon::createFromDate(1970,1,1)` até `now()` para não inventar uma janela nova que a UI real não tem.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixture do ranking/dashboard do usuário faltava serviço coberto + contrato ativo**
- **Found during:** Tarefa 2 (GREEN), ao rodar `NpsFloorDashboardsTest` pela primeira vez.
- **Issue:** a imputação de dimensão `analista`/`estrategista` (diferente de `empresa`) só materializa linha para serviços cobertos pelo template que TAMBÉM têm contrato ATIVO na empresa (mesma régua de `responsavelDoServicoOuConsolidado`). Os testes de ranking e dashboard do usuário criavam o template sem nenhum `servicoId` escopado e sem contrato — a imputação nunca gerava linha, e os testes falhavam por ausência de dado, não por bug de produção.
- **Fix:** adicionado `criarServico(SETOR_PERFORMANCE)` + `criarContrato(..., ativo: true)` e o `servicoId` escopado no template nos dois testes.
- **Files modified:** `tests/Feature/Phase116/NpsFloorDashboardsTest.php`.
- **Verification:** `--filter=NpsFloorDashboardsTest` passou de 4/6 para 6/6.
- **Committed in:** `127049ae`.

**2. [Rule 1 - Bug] Widget do dashboard do usuário também restringe ao modelo PRINCIPAL — descoberto tarde**
- **Found during:** Tarefa 2 (GREEN), teste `test_dashboard_usuario_conta_nao_respondido_como_1` retornando `1` em vez de `3.0`.
- **Issue:** a query real de `$npsResponses` em `userDashboard()` usa `->principal()` (igual ao widget admin) — mas a implementação inicial passava `templateIds=null` para as notas imputadas do usuário, então a resposta REAL (template não-principal no teste) não era contada pelo widget, só a nota imputada aparecia.
- **Fix:** `userDashboard()` passa `templateIds=[NpsTemplate::principalId()]` na chamada de `notasDaEmpresa()`, mesmo padrão do `adminDashboard()`; teste ajustado para criar o template como principal (`is_default=true`).
- **Files modified:** `app/Http/Controllers/DashboardController.php`, `tests/Feature/Phase116/NpsFloorDashboardsTest.php`.
- **Verification:** `--filter=NpsFloorDashboardsTest` 6/6 verde.
- **Committed in:** `c3ef35c8` (controller) + `127049ae` (teste).

**3. [Rule 3 - Blocking] `use Carbon\Carbon;` faltando em `CalculateGoalResults.php`**
- **Found during:** Tarefa 3 (GREEN), erro `Class "App\Jobs\Carbon" not found`.
- **Issue:** o arquivo nunca precisou de `Carbon` diretamente antes (usava só `whereYear`/`whereMonth` com inteiros); a nova chamada a `Carbon::createFromDate(...)` expôs a ausência do import.
- **Fix:** adicionado `use Carbon\Carbon;`.
- **Files modified:** `app/Jobs/CalculateGoalResults.php`.
- **Verification:** `--filter=NpsFloorDashboardsTest` (teste da meta) passou de erro fatal para verde.
- **Committed in:** `2bff3464`.

---

**Total deviations:** 3 auto-fixed (2 ajustes de fixture de teste + 1 filtro de modelo alinhado em produção + 1 import faltando). Nenhum desvio de comportamento fora do pedido do plano — todos necessários para o comportamento correto pedido pelo `<behavior>`.

## Issues Encountered

Gate de regressão completo, comparado à baseline documentada no prompt de execução:

| Gate | Failed | Passed | vs. baseline |
|------|--------|--------|---------------|
| `--filter=NpsFloorDashboardsTest` | 0 | 6 | novo — GREEN |
| `--filter=Dashboard` | 1 | 71 | `PublicacaoDesempenhoRouteTest` (mesma família da baseline Desempenho, contém "dashboard" no nome do método — não é call-site tocado por este plano) |
| `--filter=Company` | 5 | 160 | **5 falhas NOVAS descobertas, mas PRÉ-EXISTENTES e sem relação com NPS** — `CompanyServiceTypeTest`, `Phase13MigrationTest`, `Phase42/AnalyzeCompanyMlWindowQuarantineTest` (2, dependem de HTTP real ao ML), `Phase75/RascunhoCompanyIdImutavelTest` (dependência de rede ML). Confirmado por `git diff` vazio nos arquivos envolvidos — nenhum tocado pelas 3 tasks deste plano. Documentado em `deferred-items.md` item 6. Os testes de `CompanyController::show()` efetivamente exercitados pelas mudanças deste plano (Phase61/62/68/72/96 + `CompanyPortfolioAccessTest`) — 19/19 verdes |
| `--filter=Goal` | 0 | 31 | GREEN, sem regressão |
| `--filter=Nps` | 5 | 367 | **idêntico à baseline** (mesmos 5 nomes: Phase31NpsSubmitTest, Phase69/NpsPhase69IntegrationTest, V18/ConsolidarMesJanelaNpsTest ×2, V18/JanelaNpsBonusTest) |
| `--filter=Desempenho` | 14 | 91 | **idêntico à baseline** (mesmas 6 classes documentadas) |
| `--filter=Performance` | 2 | 76 | **idêntico à baseline** (`DesempenhoShopeeScoreTest`, `V16/PerformanceIndexMetadadosTest`) |
| `--filter=Portfolio\|Carteira` | 5 | 125 | **idêntico à baseline** (Phase61/PortfolioMultiFonteE2ETest ×2, Phase61/PortfolioSourceEnrichmentTest, V18/CarteiraPeriodoDiffTest ×2) |

Nenhuma falha nova foi introduzida pelas mudanças deste plano nos gates de NPS/Desempenho/Performance/Portfolio/Carteira (contagens e nomes idênticos à baseline fornecida no prompt de execução). O único achado novo (`--filter=Company`, 5 falhas) foi investigado e confirmado pré-existente e sem relação com os arquivos deste plano — documentado como item 6 novo em `deferred-items.md`.

## Known Stubs

Nenhum. `company.nps_avg` é um campo novo, plenamente calculado e coberto por teste — não é stub, mas o frontend ainda não o consome (ver "Decisions Made" acima — troca de client-side para o valor do backend é tarefa de UI fora do escopo).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Os 5 call-sites restantes do checklist do Pitfall 4 (dashboard admin, ranking, dashboard do usuário, página da empresa, meta de NPS) agora delegam 100% para `NpsImputationService` — nenhum reimplementa a regra de elegibilidade.
- **Pendência para uma fase/plano futuro (fora do escopo do 116-05):** `Companies/Show.jsx` precisa trocar o cálculo client-side de `avgNps` (hoje só respostas reais) pelo campo `company.nps_avg` do backend para o piso ficar visível na tela — nenhum plano da Fase 116 (116-06/07/08) inclui esse arquivo em `files_modified`.
- Nenhum bloqueador introduzido por este plano. Os itens de `deferred-items.md` (incluindo o item 6 novo) continuam pendentes de debug dedicado, fora do escopo da Fase 116.
- Pronto para o Plan 116-06 (comando `nps:materializar-nao-respondidos`, ganchos no disparo, agendamento diário) — este plano não altera nenhum arquivo que o 116-06 vá tocar.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os 4 arquivos criados/modificados (`NpsFloorDashboardsTest.php`, `DashboardController.php`, `CompanyController.php`, `CalculateGoalResults.php`) e este SUMMARY.md confirmados no disco; os 4 hashes de commit (`55be2206`, `c3ef35c8`, `2bff3464`, `127049ae`) confirmados via `git log --oneline --all`.
