---
phase: 105-correcao-janela-nps-bonus-competencia-v18-0
plan: 02
subsystem: bonus-desempenho-cron
tags: [nps, bonus, janela-competencia, cron, v18.0]
dependency-graph:
  requires:
    - "105-01 (DesempenhoScoreService::compute() com janela de NPS deslocada +1)"
  provides:
    - "cron desempenho:consolidar-mes reagendado para lastDayOfMonth('14:00')"
    - "--mes=YYYY-MM sem overflow de dia-do-mês (safe em qualquer dia do mês corrente)"
  affects:
    - "105-03 (ajuste dos literais v5→v6 e fixtures em-curso nos testes de âncora, incl. ConsolidarMesDesempenhoCommandTest)"
tech-stack:
  added: []
  patterns:
    - "espelha isolamento HTTP e fixtures de tests/Feature/V18/JanelaNpsBonusTest.php (105-01) para o teste de fim de mês"
key-files:
  created:
    - tests/Feature/V18/ConsolidarMesJanelaNpsTest.php
  modified:
    - routes/console.php
    - app/Console/Commands/ConsolidarMesDesempenho.php
decisions:
  - "Cálculo de $mes (Carbon::today()->subMonthNoOverflow()) NÃO mudou — já estava correto para o novo timing; só o AGENDAMENTO (routes/console.php) e a documentação (docblock/description) mudaram, conforme o plano."
  - "Rule 1 (bug pré-existente, auto-fix): --mes=YYYY-MM usava Carbon::createFromFormat('Y-m', ...) sem dia explícito — o PHP preenche o dia faltante com o de 'hoje', e como o D2 move o cron pro último dia do mês (28-31), rodar --mes com um mês mais curto que o dia de hoje estourava pro mês seguinte (ex.: hoje=31/07, --mes=2026-06 virava 2026-07-01, não 2026-06-01). Fix: Carbon::createFromFormat('Y-m-d', $mesOption.'-01') ancora no dia 1 explícito."
metrics:
  duration: "~35min"
  completed: 2026-07-21
---

# Fase 105 Plano 02: Cron consolidar-mes congela no fim do mês de coleta (D2) Summary

Reagenda o cron `desempenho:consolidar-mes` de `monthlyOn(1, '14:00')` para `lastDayOfMonth('14:00')`, fazendo o congelamento do snapshot mensal capturar a coleta COMPLETA de NPS de M+1 (em vez de rodar no início da coleta, quando quase nenhuma resposta existe ainda). Sem essa mudança, o deslocamento +1 aplicado na 105-01 congelaria 0 NPS todo mês — pior que o bug original.

## O que foi entregue

**Task 1** — `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php`, 2 testes:
1. `test_cron_no_ultimo_dia_do_mes_congela_competencia_m_com_nps_de_m_mais_1` — Carbon congelado em 31/07/2026 14:05, roda `desempenho:consolidar-mes` sem `--mes`. Assere: snapshot gravado é o de **junho** (mes_referencia=2026-06-01, não julho), e `breakdown_json.componentes.nps_medio` reflete o NPS semeado em **julho** (M+1, 4.5), não 0.0 (o que aconteceria se lesse junho, onde não há resposta nenhuma).
2. `test_override_mes_continua_funcionando_e_idempotente` — `--mes=2026-06` explícito grava exatamente 1 snapshot; rerun não duplica (`updateOrCreate`).

Ao rodar o Teste A pela primeira vez, ele já passou verde (o cálculo de `$mes` no command já estava correto, conforme o plano previa). O Teste B falhou — investigação revelou um bug pré-existente no parsing do `--mes` (ver Deviations), não relacionado ao timing do cron em si, mas exposto por ele.

**Task 2** — `routes/console.php` + `app/Console/Commands/ConsolidarMesDesempenho.php`:
- `routes/console.php`: `->monthlyOn(1, '14:00')` → `->lastDayOfMonth('14:00')` no bloco `desempenho-consolidar-mes`; comentário atualizado explicando a semântica D2 (congela no fim do mês de coleta, pagamento desliza pro fim de M+1, sem snapshot provisório).
- `ConsolidarMesDesempenho.php`: docblock de classe e `$description` atualizados para o novo timing ("último dia do mês" em vez de "dia 1"). Cálculo de `$mes` (linha do `subMonthNoOverflow`) **não foi tocado** — já estava certo.
- Fix do bug do `--mes` (Rule 1): trocado `createFromFormat('Y-m', $mesOption)` por `createFromFormat('Y-m-d', $mesOption . '-01')`, eliminando o overflow de dia-do-mês.

Suíte alvo: **2/2 verde**. `php artisan schedule:list` confirma o novo agendamento (`0 14 31 * *` em julho — Laravel recalcula o dia do cron a cada boot do console kernel via `Carbon::now()->endOfMonth()->day`, comportamento nativo do `lastDayOfMonth()`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Overflow de dia-do-mês no parsing do `--mes=YYYY-MM`**
- **Found during:** Task 1, ao investigar por que `test_override_mes_continua_funcionando_e_idempotente` gravava 0 snapshots em vez de 1.
- **Issue:** `Carbon::createFromFormat('Y-m', $mesOption)` não especifica o dia — o PHP `DateTime::createFromFormat` preenche o dia faltante com o dia de "hoje". Rodando em 31/07 (`Carbon::setTestNow('2026-07-31 14:05:00')`, cenário que agora é o timing REAL do cron D2) com `--mes=2026-06`, o dia 31 não existe em junho (30 dias) e o PHP estoura para `2026-07-01` — o override gravava a competência ERRADA (julho, não junho).
- **Fix:** `Carbon::createFromFormat('Y-m-d', $mesOption . '-01')->startOfMonth()` — ancora o dia em 1 explicitamente, eliminando o overflow independente do dia de "hoje".
- **Files modified:** `app/Console/Commands/ConsolidarMesDesempenho.php`
- **Commit:** `9c55412`
- **Nota:** este bug é PRÉ-EXISTENTE (já existia antes da 105-02), mas era latente porque o cron antigo sempre rodava no dia 1 (nunca estourava). O D2 (rodar no último dia do mês) tornou o `--mes` de catch-up manual, executado no mesmo dia do cron, uma via realista para o overflow — por isso o fix é aplicado aqui, dentro da fronteira do arquivo já listado no plano.

## Fallout esperado (fora do escopo deste plano)

Rodei `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` (regressão exigida pelos constraints):

- **6/7 verde.** 1 falha: `test_comando_grava_snapshot_com_mes_referencia_do_mes_anterior_quando_sem_flag` — `breakdown_json['nota_final']` vem `null`. Causa raiz: o fixture semeia NPS no MESMO mês da competência (não em M+1); sob a regra da 105-01 (D1), o componente NPS de uma competência cujo M+1 ainda está em coleta é EXCLUÍDO (`null`), não lido do mês errado. Esse fixture já estava listado como fallout esperado no `105-01-SUMMARY.md` ("`ConsolidarMesDesempenhoCommandTest.php` — 1 falha... mesma causa raiz, mês corrente") — não é uma regressão introduzida por esta plan (não toquei em `compute()`/`DesempenhoScoreService`), e a constraint do 105-02-PLAN.md instrui explicitamente a NÃO consertar aqui (é do 105-03).
- `tests/Feature/V18/JanelaNpsBonusTest.php` (105-01) — 5/5 verde, sem regressão.

## Known Stubs

Nenhum.

## Self-Check: PASSED

- `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php` — FOUND (criado)
- `routes/console.php` — FOUND (modificado)
- `app/Console/Commands/ConsolidarMesDesempenho.php` — FOUND (modificado)
- Commit `3932778` (test) — FOUND em `git log --oneline`
- Commit `9c55412` (fix — reagendamento + docblocks + bugfix `--mes`) — FOUND em `git log --oneline`
- `tests/Feature/V18/ConsolidarMesJanelaNpsTest.php` — 2/2 passed na última execução
- `tests/Feature/V18/JanelaNpsBonusTest.php` (regressão 105-01) — 5/5 passed
- `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php` (regressão) — 6/7 passed (1 fallout esperado, documentado acima)
- `php artisan schedule:list` — confirma `lastDayOfMonth('14:00')` ativo para `desempenho:consolidar-mes`
