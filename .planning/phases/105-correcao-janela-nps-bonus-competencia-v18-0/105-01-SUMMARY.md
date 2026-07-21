---
phase: 105-correcao-janela-nps-bonus-competencia-v18-0
plan: 01
subsystem: bonus-desempenho
tags: [nps, bonus, janela-competencia, v18.0]
dependency-graph:
  requires: []
  provides:
    - "DesempenhoScoreService::compute() com janela de NPS deslocada +1 no caminho fechado"
    - "componentes_disponiveis.nps_medio dinâmico (reflete exclusão)"
    - "cacheKey v6"
  affects:
    - "105-02 (cron desempenho:consolidar-mes — D2, congelamento no fim de M+1)"
    - "105-03 (ajuste dos literais v5 e fixtures em-curso nos testes de âncora)"
tech-stack:
  added: []
  patterns:
    - "seed direto das 3 tabelas do snapshot NPS (nps_surveys/nps_responses/nps_response_scores/nps_score_assignments) em vez do fluxo POST /nps/{token} completo — mais rápido e isolado do dual-path"
key-files:
  created:
    - tests/Feature/V18/JanelaNpsBonusTest.php
  modified:
    - app/Services/DesempenhoScoreService.php
decisions:
  - "Mecânica exclui-vs-0.0 comparada por DATA (now()->startOfDay() >= endOfMonth($mesNps)->startOfDay()), nunca por timestamp — Blocker 1 do plan-checker, trava o boundary do cron D2 (105-02) às 14h do último dia de M+1"
  - "computeNpsWindow() é método privado novo, separado de computeNpsMedio (que permanece 100% intocado — assinatura e corpo)"
metrics:
  duration: "~45min"
  completed: 2026-07-21
---

# Fase 105 Plano 01: Motor do deslocamento da janela de NPS Summary

Desloca +1 mês a janela de leitura do componente NPS no motor de bônus (`DesempenhoScoreService::compute()`), só no caminho oficial/mês fechado — corrigindo o bug de prod onde a competência lia o NPS do próprio mês (0 respostas, porque o NPS só é coletado no mês seguinte) em vez do mês seguinte (M+1).

## O que foi entregue

**Task 1 (RED)** — `tests/Feature/V18/JanelaNpsBonusTest.php`, 5 testes cobrindo os cenários obrigatórios do plano:
1. Competência fechada (junho) lê o NPS de julho (M+1) — cenário Felipe.
2. Mês em curso exclui o componente NPS (`null`, nunca `0.0`).
3. Fechada, janela M+1 já encerrada, 0 respostas reais → `0.0` (penaliza).
4. Fechada, janela M+1 ainda em coleta, 0 respostas → `null` (exclui).
5. Boundary do cron — último dia de M+1 às 14h, 0 respostas → `0.0` (trava o Blocker 1: comparação por DATA, não timestamp).

Confirmado RED: os 5 testes falharam pelo motivo certo antes da Task 2 (implementação ainda lia a janela M sem deslocamento e sempre retornava `0.0`, nunca `null`).

**Task 2 (GREEN)** — `app/Services/DesempenhoScoreService.php`:
- Novo método privado `computeNpsWindow(User $user, Carbon $mes, bool $mesFechado): ?float`, chamado em `compute()` no lugar da chamada direta a `computeNpsMedio()`:
  - Mês em curso (`$periodo['is_closed']=false`) → retorna `null` direto (sem deslocar, sem 0.0).
  - Mês fechado (`is_closed=true`) → desloca com `$mes->copy()->addMonthNoOverflow()` e chama `computeNpsMedio($user, $mesNps)`. Se o resultado for `0.0` (sentinela de "vazio" — notas são 1..5, então qualquer média real é >= 1.0), decide entre excluir e penalizar comparando **datas** (`now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay())`), nunca timestamps.
- `componentes_disponiveis['nps_medio']` deixa de ser hardcoded `true` e passa a ser `$nps !== null`.
- `cacheKey()` bump v5→v6 com bloco de comentário histórico explicando a mudança de régua.
- `computeNpsMedio`, `resolvePeriodo`, `computeOficial` e os componentes financeiros (`computeVarFaturamento`/`computeVarMargem`) permanecem 100% intocados.

Suíte alvo: **5/5 verde**.

## Deviations from Plan

None — plano executado conforme escrito (fixes do plan-checker aplicados: Blocker 1 comparação por DATA, sinal `is_closed` já resolvido usado diretamente).

## Fallout esperado (fora do escopo deste plano — documentado no `<verification>` do 105-01-PLAN.md)

Rodei a suíte ampla (`tests/Feature/V18/`, `tests/Feature/V16/`, `tests/Feature/Phase74/`) para mapear o impacto do bump v5→v6 e da mudança de comportamento em-curso. Resultado:

- **`tests/Feature/V18/DesempenhoMetadadosCacheTest.php`** — 3 falhas, literais `'desempenho.compute.v5.'` hardcoded nos asserts. Fallout puro do bump de versão — cabe ao Plan 105-03 (conforme a constraint do prompt).
- **`tests/Feature/V16/DesempenhoElegibilidadeTest.php`** — 3 falhas. Fixtures chamam `compute($u, '2026-08-01')` com `Carbon::setTestNow('2026-08-15')` — ou seja, mês CORRENTE — e ainda esperam `nps_medio=0.0` (comportamento antigo, DESEMP-03 "força 0.0"). Sob a regra nova (D1), mês em curso exclui o componente (`null`), então a nota final também muda.
- **`tests/Feature/Phase74/DesempenhoScoreServiceTest.php`** — 4 falhas (mesma causa: fixtures em mês corrente esperando `nps_medio=0.0`/valor numérico onde a regra nova retorna `null`).
- **`tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`** — 1 falha (`breakdown_json['nota_final']` null onde antes não era — mesma causa raiz, mês corrente).

Todas as 11 falhas são estritamente do tipo "a implementação está certa pela regra NOVA, a fixture do teste ainda espera a regra ANTIGA" — nenhuma delas indica bug na Task 2. Não toquei nesses arquivos (fora da fronteira do 105-01: só `DesempenhoScoreService.php` + `JanelaNpsBonusTest.php`). Ficam RED até o Plan 105-03 (ou o plano que ajustar as fixtures de âncora) atualizar os literais `v5`→`v6` e os asserts de `nps_medio`/`nota_final` em mês corrente.

## Known Stubs

Nenhum.

## Self-Check: PASSED

- `app/Services/DesempenhoScoreService.php` — FOUND (modificado)
- `tests/Feature/V18/JanelaNpsBonusTest.php` — FOUND (criado)
- Commit `6a6cf26` (test RED) — FOUND em `git log --oneline`
- Commit `62b78ad` (feat GREEN) — FOUND em `git log --oneline`
- `tests/Feature/V18/JanelaNpsBonusTest.php` — 5/5 passed na última execução
