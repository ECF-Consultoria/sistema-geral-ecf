---
phase: 119-score-por-empresa-v21-0
plan: 04
subsystem: testing
tags: [phpunit, adman, shopee, dispatcher, status, quality, reconciliacao]

requires:
  - phase: 119-02
    provides: "CompanyScoreService::computeEmpresasScore() completo (universo, fonte vencedora, guard C-04, chamada única ao dispatcher)"
  - phase: 119-03
    provides: "Prova dura de EMPS-03 (diff_pp) e EMPS-05 (dispatcher 1x)"
provides:
  - "Prova de EMPS-06: Adman vence Shopee no desempate; empresa mista produz UMA linha apesar de dois vínculos"
  - "Prova de EMPS-06: empresa só-Shopee entra 'complete' com margem_pontos=1.0 fixo e quality.margin_source='placeholder_shopee'; caso âncora fecha em 3,07"
  - "Prova de EMPS-07: os quatro status (complete/partial/sem_fonte/sem_dados) mutuamente exclusivos e testáveis isoladamente"
  - "Prova de EMPS-07: quality.motivos determinístico (conteúdo E ordem) em todos os cenários"
  - "Reconciliação old×new: universo elegível e mapa de fontes idênticos; empresa invalidada ausente nos dois; divergência de nota no cenário de granularidade asserida como esperada — antecipa ROLL-01/02 da Fase 121"
affects: [120-flag-consumo-companyscoreservice, 121-comparacao-antigo-novo]

tech-stack:
  added: []
  patterns:
    - "Dublê do MetricDiffDispatcher que injeta um diff_pp fabricado na resposta Shopee, provando que a régua de margem nunca lê esse campo para fonte Shopee (ordem do match(true) no CompanyScoreService)"
    - "Http::fake() com wildcards por custId distinto (ex. '*/performance/CUST-STATUS-COMPLETE*') para simular múltiplas empresas Adman com respostas diferentes na MESMA suíte, sem o problema de acúmulo de stubs genéricos"
    - "Reconciliação por Reflection: computeUniverso() e reguaFaturamento() do DesempenhoScoreService invocados como SOMENTE LEITURA para comparar universo/fonte/régua com o caminho novo, sem tocar o arquivo (gate de hash)"

key-files:
  created:
    - tests/Feature/Phase119/CompanyScoreServiceFonteTest.php
    - tests/Feature/Phase119/CompanyScoreServiceStatusTest.php
    - tests/Feature/Phase119/CompanyScoreServiceReconciliacaoTest.php
  modified: []

key-decisions:
  - "Nenhuma mudança em CompanyScoreService.php nesta wave — a Wave 1/2 já implementava corretamente o desempate Adman×Shopee, o placeholder de margem e a taxonomia de status. As 3 suítes desta wave são prova, não implementação."
  - "Reconciliação com DesempenhoScoreService::computeUniverso() exige replicar manualmente o filtro de invalidadas (que na classe original roda DEPOIS de computeUniverso(), dentro de compute()) para comparar universo antigo × novo em pé de igualdade — sem isso o teste compararia um universo antigo CRU (pré-filtro) contra um universo novo já filtrado, dando falso-negativo."
  - "Divergência de nota no cenário de granularidade (régua-por-empresa × régua-da-média) é testada com valores literais fixos (2,5 vs 1,0), documentada como intencional (D3 da milestone), nunca como bug."

patterns-established: []

requirements-completed: [EMPS-06, EMPS-07]

duration: ~50min
completed: 2026-07-29
---

# Fase 119 Plano 04: EMPS-06/EMPS-07 — fonte financeira vencedora, taxonomia de status e reconciliação old×new — Summary

**Três suítes de teste fecham a Fase 119: Adman vence Shopee no desempate (empresa mista vira UMA linha), empresa só-Shopee entra `complete` com placeholder de margem (caso âncora 3,07), os quatro status (`complete`/`partial`/`sem_fonte`/`sem_dados`) provados mutuamente exclusivos com `quality.motivos` determinístico, e reconciliação por Reflection prova que o universo elegível e o mapa de fontes do caminho novo são idênticos ao antigo — zero mudança de código, a Wave 1/2 já estava correta.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-07-29
- **Tasks:** 3/3
- **Files modified:** 3 (todos novos, testes)

## Accomplishments

- **EMPS-06 provado** — empresa com dois vínculos elegíveis (performance + shopee, mesma empresa, mesmo profissional) resolve `fonte_financeira='adman'` e produz **uma única linha** na Collection, apesar da multiplicidade de vínculos na pivot (`project_company_users_multi_linha_servico`). Empresa só-Shopee entra `status='complete'` com `margem_pontos=1.0` fixo, `quality.margin_source='placeholder_shopee'`, e `quality.motivos` **nunca** contém `margem_pp_indisponivel` (o placeholder não é componente ausente — trava da Fase 109). Caso âncora D-02 fecha em `nota_empresa=3.07` ((4,2+4+1,0)/3). Um dublê do dispatcher que injeta um `diff_pp` **fabricado** (99.0) na resposta Shopee prova que a régua de margem nunca roda para essa fonte — `margem_pontos` continua `1.0`, nunca `5.0`.
- **EMPS-07 provado** — os quatro status são mutuamente exclusivos e cobertos isoladamente: `complete` (3 componentes, motivos vazio), `partial` (D-01: NPS 4,6+faturamento 5+margem ausente ⇒ `nota_empresa=null`, `nota_empresa_parcial=4.8`, motivo único `margem_pp_indisponivel`), `sem_fonte` (D-03: empresa `polos`, `nota_empresa_parcial=4.1`, motivo único `sem_fonte_financeira`), `sem_dados` (Adman 404 nos dois endpoints + NPS null: `quality.motivos` bate **exatamente** `['faturamento_sem_baseline', 'margem_pp_indisponivel', 'nps_janela_aberta']`, nesta ordem). Um teste de coleção com as 4 empresas juntas confirma `pluck('status')->unique()->sort()->values()->all() === ['complete','partial','sem_dados','sem_fonte']` — nenhum status legado (`blocked`/`invalidada`/`sem_baseline`) vaza para o nível de empresa.
- **Reconciliação antecipando ROLL-01/ROLL-02 (Fase 121)** — `DesempenhoScoreService::computeUniverso()` invocado por Reflection (somente leitura) sobre a MESMA fixture de carteira: o conjunto de `company_id` com fonte financeira e o mapa `company_id => fonte_financeira` são **idênticos** entre os dois caminhos; empresa invalidada na competência fica ausente nos dois; e o cenário de granularidade (empresa A −20%, empresa B +2%) prova a divergência **esperada**: caminho novo dá `(1,0+4,0)/2=2,5` (régua por empresa, média depois), caminho antigo dá `reguaFaturamento(-9%)=1,0` (média da variação, régua depois) — documentado como D3 da milestone, não regressão.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: EMPS-06 — Adman vence Shopee e placeholder de margem Shopee** — `ee91026a` (test)
2. **Task 2: EMPS-07 — taxonomia de status e quality.motivos + regressão ampla** — `07a4993e` (test)
3. **Task 3: Reconciliação caminho antigo × caminho novo (antecipa ROLL-01/02)** — `50bda50f` (test)

_Nenhum commit `feat`/`fix` nesta wave — o `CompanyScoreService` já satisfazia os dois requirements desde a Wave 1/2 (desempate Adman×Shopee, placeholder Shopee, ordem dos motivos, taxonomia de status)._

## Files Created/Modified

- `tests/Feature/Phase119/CompanyScoreServiceFonteTest.php` — 3 testes: empresa mista resolve `adman` e 1 linha; empresa só-Shopee `complete`+placeholder+caso âncora 3,07; dublê prova que a régua de margem nunca roda para Shopee mesmo com `diff_pp` fabricado.
- `tests/Feature/Phase119/CompanyScoreServiceStatusTest.php` — 5 testes: `complete`, `partial`, `sem_fonte`, `sem_dados` isolados + 1 teste de coleção com as 4 empresas juntas provando exclusividade mútua.
- `tests/Feature/Phase119/CompanyScoreServiceReconciliacaoTest.php` — 3 testes: universo/fonte idênticos; empresa invalidada ausente nos dois caminhos; divergência de granularidade esperada (não regressão).

## Decisions Made

- **Nenhuma mudança em `CompanyScoreService.php`.** Todas as 11 asserções de comportamento (fonte vencedora, placeholder, 4 status, ordem dos motivos, universo/fonte idênticos ao original) passaram na primeira execução — a Wave 1/2 já tinha implementado tudo corretamente. As 3 suítes desta wave são prova, não implementação.
- **Filtro de invalidadas replicado manualmente na reconciliação.** `DesempenhoScoreService::computeUniverso()` devolve o universo **pré-filtro** de invalidadas — o filtro roda depois, dentro do próprio `compute()` (linhas ~416-419). Para comparar "universo antigo" com "universo novo" em pé de igualdade (o novo já filtra invalidadas internamente, D-05), o teste de reconciliação replica esse MESMO filtro por fora, como leitura — nunca modificando `DesempenhoScoreService`.
- **Wildcards Http::fake() por custId distinto** para simular múltiplas empresas Adman com respostas diferentes (`complete`/`partial`/`sem_dados`) na mesma suíte, evitando o problema de acúmulo de stubs genéricos já documentado na Wave 1 (`119-02-SUMMARY.md`).

## Deviations from Plan

None — plano executado exatamente como escrito. Todos os testes passaram na primeira execução, sem necessidade de ajustar o `CompanyScoreService`.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Verificação

| Gate | Resultado |
|---|---|
| `--filter=CompanyScoreServiceFonteTest` | **3/3 verdes** (25 asserções) |
| `--filter=CompanyScoreServiceStatusTest` | **5/5 verdes** (40 asserções) |
| `--filter=CompanyScoreServiceReconciliacaoTest` | **3/3 verdes** (20 asserções) |
| `--filter=Phase119` (suíte completa da fase) | **29/29 verdes** (281 asserções) |
| Aditividade: `sha256sum app/Services/DesempenhoScoreService.php` | `cfc16da2a8404fba…9edd` — byte-a-byte intocado, verificado em toda task e ao final da wave |
| `git diff --name-only` | não inclui `DesempenhoScoreService.php` |
| `--filter=Desempenho` (regressão ampla) | **14 falhas** — exatamente a baseline pré-existente (`.planning/debug/resolved/audit-margem-baseline-negativo.md` e correlatos). Sem regressão. |
| Consumidor de produção | nenhum — `grep -rn "CompanyScoreService" app/ routes/` só encontra a referência documental pré-existente no docblock de `ProbeMargemPrevStability.php:279` (escrita ANTES desta fase existir, mesma achado da Wave 1) |
| Nenhuma chamada real à Adman | `Http::preventStrayRequests()` ativo em toda a suíte; toda fixture veio de `Http::fake()` |

## Risco registrado para a Fase 120 (obrigatório — `119-CONTEXT.md` `<risks>`)

**Régua-da-média ≠ média-das-réguas — o invariante da Fase 109 não vale no caminho novo.**

Hoje `DesempenhoScoreService::margemPontos()` aplica a régua de margem **uma vez** sobre a média agregada da carteira e depois pondera os placeholders Shopee por contagem (blend ponderado, Fase 109). O `CompanyScoreService` roda a régua **por empresa** e a média vem **depois** — é o ponto central da milestone v21.0 (D3), mas tem uma consequência concreta e comprovada nesta wave (`CompanyScoreServiceReconciliacaoTest::test_divergencia_de_granularidade_e_esperada_regua_por_empresa_diverge_da_regua_da_media`):

- Empresa A com faturamento −20% e empresa B com +2%: caminho **novo** dá `(reguaFaturamento(−20%)=1,0 + reguaFaturamento(+2%)=4,0) / 2 = 2,5`; caminho **antigo** dá `reguaFaturamento(−9%) = 1,0` (régua sobre a média da variação, −9% = (−20+2)/2).

O docblock de `DesempenhoScoreService::margemPontos()` declara como invariante testado em `DesempenhoShopeeScoreTest` que **"só-performance (`$nShopeePlaceholder=0`) devolve exatamente `reguaMargem($varMargemReal)` — IDÊNTICO ao comportamento pré-Fase 109 (regressão zero)."** Esse invariante **não vale** no caminho novo — o `CompanyScoreService` não é blend, é régua por empresa.

- **Na Fase 119 isso é inofensivo** — a fase é aditiva, `DesempenhoScoreService.php` permanece byte-a-byte intocado (hash verificado em toda task desta wave e das duas anteriores) e `DesempenhoShopeeScoreTest` continua 100% verde, sem nenhuma alteração.
- **Na Fase 120 vira problema real** quando a flag ligar: o plano da 120 vai precisar decidir explicitamente se `DesempenhoShopeeScoreTest` ganha cenários novos cobrindo o modo flag-ligada, ou se os invariantes documentados no docblock de `margemPontos()` são reescritos para refletir que a garantia de "regressão zero" só vale enquanto a flag estiver desligada.

## GATE MPP-04 — status da coleta (registro obrigatório, decisão do usuário 2026-07-29)

O gate foi **reposicionado para a Fase 120** — ele agora bloqueia **ligar a flag de consumo do `CompanyScoreService`**, não a escrita de código (que é o que esta fase fez). Estado da coleta registrado em `119-CONTEXT.md` no momento em que esta wave foi executada:

- **4 rodadas, 212 leituras, zero flips de nota, zero falhas de HTTP** — mas **todas em condição folgada**.
- **Falta a leitura sob contenção (`contencao_11h`)** antes de o gate poder aprovar a ativação da flag na Fase 120.

Nenhuma ação tomada aqui — só o registro exigido para que a Fase 120 não descubra o estado do gate na hora.

## Débito herdado (acumulado desde a Wave 1, para a Fase 120)

- **Réguas duplicadas** (`reguaFaturamento()`/`reguaMargem()`) — proteção via teste de equivalência por Reflection, não extração para classe compartilhada. Unificação real fica para a Fase 120, quando o gate de aditividade sair.
- **Régua-da-média ≠ média-das-réguas** (ver seção de risco acima) — decisão sobre os invariantes de `DesempenhoShopeeScoreTest` é da Fase 120.
- **Política de denominador** da nota do profissional (empresa incompleta entra ou sai da média) — decisão aberta, registrada em `REQUIREMENTS-v21.md`, resolvida na Fase 120.

## Next Phase Readiness

- Fase 119 fechada como **inteiramente aditiva**: `DesempenhoScoreService.php` byte-a-byte intocado do início ao fim das 3 waves (mesmo hash `cfc16da2a8404fba…9edd`), zero consumidor de produção do `CompanyScoreService`, zero número de produção alterado.
- `CompanyScoreService::computeEmpresasScore()` tem 29/29 testes verdes cobrindo EMPS-01 a EMPS-07 — contrato completo, réguas equivalentes, `diff_pp` vs `diff_pct`, dispatcher 1x, fonte vencedora, taxonomia de status, e reconciliação com o caminho antigo. A Fase 120 pode ligar a flag com a base de comportamento provada — mas **só depois** do GATE MPP-04 aprovar a leitura sob contenção.
- O risco de "régua-da-média ≠ média-das-réguas" está registrado e com teste que reproduz a divergência numericamente — a Fase 120 não precisa redescobrir isso, só decidir o que fazer com `DesempenhoShopeeScoreTest`.

## Self-Check: PASSED

Todos os arquivos criados (`CompanyScoreServiceFonteTest.php`, `CompanyScoreServiceStatusTest.php`, `CompanyScoreServiceReconciliacaoTest.php`, este SUMMARY) e os 3 commits (`ee91026a`, `07a4993e`, `50bda50f`) foram verificados como existentes no disco/git log.

---
*Phase: 119-score-por-empresa-v21-0*
*Completed: 2026-07-29*
