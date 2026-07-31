---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
plan: 03
subsystem: database
tags: [laravel, artisan-command, phpunit, mockery, desempenho, comparador, decomposicao]

# Dependency graph
requires:
  - phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0 (Plano 02)
    provides: "Comando desempenho:comparar-score-empresa coletando nota antiga x nota nova por competência fixa, com linhas reconsultáveis nas duas tabelas do comparador"
provides:
  - "decomposicao (JSON) e maior_causa_delta preenchidos por profissional na competência alvo — três parcelas (margem pp × relativa, régua-por-empresa × régua-da-média, denominador) mais o resíduo da interação, sempre visível"
  - "faixa_antiga_inicial/faixa_nova_inicial/mudou_faixa via BonusFaixa::classificar() direto (comparação pré-promoção, D-06)"
  - "DesempenhoScoreService::reguaFaturamento()/reguaMargem() públicas (D-07) — reutilizáveis por qualquer consumidor futuro sem duplicação"
affects: [121-04, 121-05]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Contrafactual por variável isolada: recalcula a nota trocando UMA entrada por vez, mede a diferença contra a nota real — resíduo = delta menos a soma das parcelas, nunca escondido"
    - "Fixture de teste com dois pipelines desacoplados: nota antiga roda pelo compute() REAL (MetricDiffDispatcher mockado por company_id), nota nova 100% controlada via mock de CompanyScoreService::computeEmpresasScore() — permite escolher os dois lados do delta à mão"

key-files:
  created:
    - tests/Feature/Phase121/DecomposicaoDeltaTest.php
  modified:
    - app/Console/Commands/CompararScoreEmpresa.php
    - app/Services/DesempenhoScoreService.php

key-decisions:
  - "D-06/D-07 (correção pós-plan-check, aplicada exatamente como escrito no <correcao_pos_plan_check> do plano): NENHUMA função espelho foi criada. Faixa usa BonusFaixa::classificar() direto; réguas de faturamento/margem viraram PÚBLICAS em DesempenhoScoreService (só visibilidade, corpo/assinatura intocados) e o comando chama $this->scoreService->reguaFaturamento()/reguaMargem() direto"
  - "O blend ponderado por contagem da margem (P2, réplica da fórmula de margemPontos()) é implementado como aritmética simples inline sobre médias de C — não uma cópia de método, já que margemPontos() continua privada e intocada"
  - "P1 exclui empresa Adman sem margem_diff_pct do cálculo (nunca trata ausência como zero); se TODAS as empresas de C forem excluídas, P1 fica null (não 0.0) e o resíduo é calculado só com as parcelas disponíveis"
  - "faixa_antiga_oficial (pós-promoção DESEMP-08, gravada no Plano 02) nunca entra na comparação de mudança de faixa — só as duas iniciais, produzidas pela mesma função, evitando fabricar mudança de faixa inexistente (T-121-22)"

patterns-established:
  - "Réguas puras (sem estado, sem I/O) promovidas de private para public quando um segundo consumidor legítimo aparece, em vez de duplicá-las — paga débito da C-03 da Fase 119 registrado desde que o gate de aditividade (Fase 120) saiu"

requirements-completed: [ROLL-01]

# Metrics
duration: ~40min
completed: 2026-07-31
---

# Phase 121 Plan 03: Decomposição do delta (P1/P2/P3 + resíduo, faixas pré-promoção) Summary

**O comando `desempenho:comparar-score-empresa` passa a atribuir a causa do delta a três parcelas isoladas por variável (margem pp × relativa, régua-por-empresa × régua-da-média, denominador ampliado) mais o resíduo da interação — sempre persistido, nunca escondido — e classifica as faixas pré-promoção via `BonusFaixa::classificar()` direto, sem nenhuma função "espelho".**

## Performance

- **Duration:** ~40 min
- **Tasks:** 2/2
- **Files created:** 1
- **Files modified:** 2

## Accomplishments
- `calcularDecomposicao()` em `CompararScoreEmpresa.php` implementa as três contrafactuais do D-02: P1 recalcula a nota de cada empresa de C trocando `reguaMargem(margem_var_pp)` por `reguaMargem(margem_diff_pct)` (Shopee mantém o placeholder 1.0); P2 aplica a régua UMA vez sobre as médias de C (NPS clamp [1,5], faturamento via média + régua, margem via o mesmo blend ponderado por contagem de `margemPontos()`, implementado como aritmética inline); P3 amplia o denominador para `nota_empresa_parcial` de TODAS as linhas (inclui `partial`/`sem_fonte`)
- Resíduo = `delta − (P1+P2+P3)`, sempre persistido — mesmo quando é a maior magnitude (`maior_causa_delta = 'interacao_nao_decomposta'`), empate resolvido pela ordem determinística margem → régua → denominador → resíduo
- Guardas T-121-21: `delta` nulo nunca vira zero (`decomposicao = {'motivo':'delta_indefinido'}`, `maior_causa_delta = null`); empresa Adman sem `margem_diff_pct` sai de P1 e é contada em `avisos.p1_empresas_sem_diff_pct` (nunca escondida); se P1 ficar sem nenhuma empresa recalculável, a parcela fica `null` (nunca `0.0`)
- `faixa_antiga_inicial`/`faixa_nova_inicial` via `BonusFaixa::classificar()` direto (D-06); `mudou_faixa` só quando as duas existem e diferem; `faixa_antiga_oficial` (pós-promoção DESEMP-08) permanece fora dessa comparação (T-121-22)
- **Correção pós-plan-check aplicada literalmente**: `reguaFaturamento()`/`reguaMargem()` de `DesempenhoScoreService` viraram `public` (D-07, só visibilidade) e são chamadas direto pelo comando — nenhuma `*Espelho()` foi criada, corrigindo os dois blockers (D-06/D-07) travados pelo plan-check antes da execução
- Suíte `DecomposicaoDeltaTest` (5 testes) prova, com números escolhidos à mão e conferíveis por leitura: cenário onde P1 domina, cenário onde P3 domina, cenário onde o resíduo domina (comprovadamente ≠ 0), empresa Adman sem `diff_pct` saindo de P1 sem virar zero, e `delta_indefinido` nunca tratado como zero — usando um dublê de `MetricDiffDispatcher` chaveado por `company_id` que alimenta tanto a agregação legada REAL (nota antiga) quanto a releitura interleaved do comando (nota nova via P1), enquanto `CompanyScoreService::computeEmpresasScore()` fica 100% mockado (nota nova)
- `--filter=Phase121` 19/19 verde (14 herdados dos Planos 01/02 + 5 novos); `--filter=Desempenho` 14 failed/100 passed (baseline exata, zero regressão); `--filter=Phase120` 18/18 verde

## Task Commits

Each task was committed atomically:

1. **Task 1: As três parcelas, o resíduo e a maior causa** - `0b56fed4` (feat)
2. **Task 2: Gate nº 2 — resíduo visível e réguas espelho equivalentes** - `4f6fe65c` (test)

## Files Created/Modified
- `app/Console/Commands/CompararScoreEmpresa.php` - `calcularDecomposicao()` (P1/P2/P3/resíduo/maior_causa_delta) chamado só na competência alvo; `persistirEmpresas()` agora retorna também as linhas cruas capturadas (3º elemento) para a decomposição não reconsultar o banco; faixas pré-promoção via `BonusFaixa::classificar()` no `handle()`
- `app/Services/DesempenhoScoreService.php` - `reguaFaturamento()`/`reguaMargem()` de `private` para `public` (D-07, só visibilidade — corpo e assinatura intocados)
- `tests/Feature/Phase121/DecomposicaoDeltaTest.php` - gate nº 2: 5 testes de decomposição com fixtures hand-computadas

## Decisions Made
- Nenhuma decisão nova além da correção pós-plan-check já registrada no `<correcao_pos_plan_check>` do `121-03-PLAN.md` (D-06/D-07) — plano executado exatamente conforme a correção instrui.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking/documentação desatualizada] `<done>` da Task 1 pedia `grep -c "Espelho" ... >= 3`, mas a correção pós-plan-check proíbe criar funções espelho**
- **Found during:** Task 1, ao verificar o critério `<done>` literal do plano
- **Issue:** O corpo da Task 1 (escrito ANTES do plan-check) ainda descreve "Réguas espelho... três métodos privados — `reguaFaturamentoEspelho`, `reguaMargemEspelho`, `classificarFaixaEspelho`" e o `<done>` cobra `grep -c "Espelho" >= 3`. O bloco `<correcao_pos_plan_check>` no topo do arquivo — que tem precedência explícita ("Onde este plano disser 'espelho', vale o que está aqui") — instrui EXATAMENTE o oposto: nenhuma função espelho, réguas viram públicas em `DesempenhoScoreService`, faixa usa `BonusFaixa::classificar()` direto
- **Fix:** Segui a correção (autoritativa) em vez do corpo original da Task 1: `grep -c "Espelho"` no comando retorna 1 (só o comentário pré-existente "Espelho no console/log", não relacionado a réguas/faixa) — não ≥3. Nenhuma função `*Espelho()` foi criada; a equivalência de réguas continua coberta por `CompanyScoreServiceReguasTest` (Fase 119), que não foi duplicada
- **Files modified:** nenhum arquivo adicional — é uma decisão de qual critério seguir, não um bug de código
- **Verification:** `--filter=Phase121`/`--filter=Desempenho`/`--filter=Phase120` verdes na baseline esperada; `CompanyScoreServiceReguasTest` continua passando sem alteração (réguas de `DesempenhoScoreService` públicas não quebram Reflection, que funciona independente de visibilidade)
- **Committed in:** `0b56fed4`

---

**Total deviations:** 1 (resolução de conflito entre o corpo original da Task 1 e a correção pós-plan-check no topo do mesmo arquivo — a correção prevaleceu, como o próprio plano instrui)
**Impact on plan:** Nenhum impacto na funcionalidade entregue — o `<done>` textual da Task 1 ficou desatualizado pela correção, mas os `must_haves.artifacts`/`key_links` do frontmatter (que exigem `contains: "residuo"` e a suíte de testes, não a string "Espelho") permanecem satisfeitos.

## Issues Encountered
None além da deviation documentada acima.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- `decomposicao`/`maior_causa_delta`/`faixa_antiga_inicial`/`faixa_nova_inicial`/`mudou_faixa` prontos e reconsultáveis nas duas tabelas do comparador para o Plano 04 (relatório/histograma) consumir
- Rótulos de `decomposicao` (snake_case, estáveis: `parcelas.margem_pp_vs_relativa`, `parcelas.regua_por_empresa_vs_regua_da_media`, `parcelas.denominador`, `residuo`, `delta_total`, `notas_contrafactuais.*`, `avisos.*`) documentados no docblock de `calcularDecomposicao()` — o Plano 04 lê estas chaves
- `reguaFaturamento()`/`reguaMargem()` públicas em `DesempenhoScoreService` ficam disponíveis para qualquer consumidor futuro sem precisar de nova duplicação
- Nenhuma flag de produção foi tocada; `metrics.performance_company_first_score` continua `false`

---
*Phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0*
*Completed: 2026-07-31*

## Self-Check: PASSED

- FOUND: app/Console/Commands/CompararScoreEmpresa.php
- FOUND: app/Services/DesempenhoScoreService.php
- FOUND: tests/Feature/Phase121/DecomposicaoDeltaTest.php
- FOUND commit: 0b56fed4
- FOUND commit: 4f6fe65c
