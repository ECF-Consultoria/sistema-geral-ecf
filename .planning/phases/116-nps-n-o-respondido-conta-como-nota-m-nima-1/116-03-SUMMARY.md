---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 03
subsystem: backend
tags: [nps, laravel, eloquent, inertia, tdd, bonificacao]

requires:
  - phase: 116-01
    provides: "NpsImputationService (materializarLote/materializar) + NpsImputedAssignment + BonusInvalidacao::companyIdsInvalidadas"
provides:
  - "NpsController::index() conta NPS não respondido como nota 1 nos 3 cards (estrategista/analista/empresa), na série de 12 meses e nos contadores"
  - "Área NPS respeita invalidação por competência (bonus_invalidacoes) — capacidade NOVA nesta tela (D5)"
  - "Payload da tela expõe cards.*.nao_respondidos + regra_nao_respondido"
affects: [116-04, 116-05, 116-06, nps-controller, dashboard-nps]

tech-stack:
  added: []
  patterns:
    - "Reuso LITERAL da mesma closure de filtro (survey scope) tanto para respostas reais quanto para notas imputadas — carteira/pessoa/modelo nunca podem divergir entre os dois conjuntos"
    - "Dedupe por survey_id (1 nota por survey por dimensão) — diferente da régua do bônus (dedupe por survey+role/pessoa), intencionalmente, documentado inline"
    - "Cast explícito para Illuminate\\Support\\Collection antes de merge() quando o array mesclado não é de Models — Eloquent Collection::merge() assume getKey()"

key-files:
  created:
    - tests/Feature/Phase116/NpsFloorAreaNpsTest.php
    - tests/Feature/Phase116/NpsFloorMultiModeloTest.php
  modified:
    - app/Http/Controllers/NpsController.php

key-decisions:
  - "notasImputadasPorDimensao() reusa NpsImputedAssignment::vigentes() + whereHas('survey', $responsesFilter) em vez de reimplementar filtros — garante paridade total de escopo (T-116-03-01)"
  - "D5 (invalidação por competência) é capacidade NOVA nesta tela — antes NpsController só respeitava NpsResponse::invalidated_at (invalidação manual de resposta, outro conceito)"
  - "D2 (nota definitiva ganha da resposta tardia) implementado via exclusão de $responsesMes/$responsesM cujo survey_id está em surveyIdsComNotaDefinitiva() — blindagem de invariante, raramente dispara na prática"
  - "Série de 12 meses recomputa notasImputadasPorDimensao() por mês (12 x 3 queries extra) em vez de 1 query agregada para os 12 meses — preferida a simplicidade/correção sobre a otimização sugerida pelo plano, dado o volume mensal baixo (~150 responses/mês) já aceito pelo código pré-existente"

requirements-completed: [NPSFLOOR-01, NPSFLOOR-03, NPSFLOOR-04, NPSFLOOR-06, NPSFLOOR-11, NPSFLOOR-12]

duration: ~70min
completed: 2026-07-27
---

# Fase 116 Plano 03: Área NPS conta o não respondido como nota mínima (1) Summary

**`NpsController::index()` passa a mesclar as notas imputadas (Plan 01) nos 3 cards de média, na série de 12 meses e nos contadores da área NPS, incluindo a dimensão empresa (D7) e respeitando `bonus_invalidacoes` por competência — capacidade nova nesta tela (D5).**

## Performance

- **Duration:** ~70 min
- **Completed:** 2026-07-27
- **Tasks:** 3
- **Files modified:** 3 (1 controller, 2 suítes de teste novas)

## Accomplishments

- `NpsController` ganhou injeção de `NpsImputationService` via construtor e o método privado novo `notasImputadasPorDimensao(Carbon $mesInicio, Carbon $mesFim, callable $responsesFilter): array` — reusa LITERALMENTE a mesma closure `$responsesFilter` aplicada às respostas reais (carteira/pessoa/modelo/empresa), consulta `NpsImputedAssignment::vigentes()` por dimensão, aplica o filtro D5 (`BonusInvalidacao::companyIdsInvalidadas` na competência mês-1) e dedupe por `survey_id`.
- `$agregarMedia` mescla as notas imputadas (1.0 cada) às respostas reais antes de calcular média/total, e agora expõe a chave nova `nao_respondidos` por card — a UI do Plan 05 vai usar para explicar a regra sem jargão.
- Os 3 cards (`estrategista`/`analista`/`empresa`) e a série de 12 meses recebem a regra igualmente — a dimensão **empresa** também conta o não respondido como 1 (D7), preservando a coerência entre os 3 números da mesma tela.
- Regra D2 (nota definitiva ganha da resposta tardia): antes de agregar, `$responsesMes`/`$responsesM` excluem respostas cujo `survey_id` já tem linha `definitivo` na janela (`surveyIdsComNotaDefinitiva`) — blindagem de invariante, comentada como tal (na prática quase nunca dispara, pois o link expira em `expires_at=endOfMonth`).
- Payload ganha `regra_nao_respondido: true`, sinalizando para a UI que a regra está ativa.
- Suítes novas: `NpsFloorAreaNpsTest` (9 testes, 120 assertions) cobrindo NPSFLOOR-01/03/04/11/12 + D2 + payload novo + sentinela de vazio; `NpsFloorMultiModeloTest` (2 testes, 15 assertions) cobrindo NPSFLOOR-06 (não respondido parcial multi-modelo).

## Task Commits

Ciclo TDD RED → GREEN → reconciliação:

1. **Tarefa 1 (RED): suítes da área NPS e do não respondido parcial** - `55a239e4` (test) — 7 failed / 1 passed confirmado (RED esperado; a suíte multi-modelo tinha 1 teste já verde por medir só a fundação de dados do Plan 01).
2. **Tarefa 2 (GREEN): cards, série e contadores com o piso de nota 1** - `6f7545c0` (feat) — 17/17 verde após corrigir um bug de tipo (Eloquent Collection::merge() esperando Models).
3. **Tarefa 3: regressão da área NPS + teste de sentinela de vazio** - `ab2e8934` (test).

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `app/Http/Controllers/NpsController.php` — construtor com `NpsImputationService`, método privado `notasImputadasPorDimensao()`, `$agregarMedia` mesclando notas imputadas + expondo `nao_respondidos`, exclusão D2 em `$responsesMes`/`$responsesM`, `regra_nao_respondido` no payload.
- `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` (novo) — 9 testes provando NPSFLOOR-01/03/04/11/12, D2 e a sentinela de vazio (não-regressão).
- `tests/Feature/Phase116/NpsFloorMultiModeloTest.php` (novo) — 2 testes provando NPSFLOOR-06 (não respondido parcial entre 2 modelos/serviços da mesma empresa).

## Decisions Made

- `notasImputadasPorDimensao()` reusa a MESMA closure `$responsesFilter`/`$responsesFilterMes` já usada para as respostas reais, em vez de escrever um filtro paralelo — garante que carteira (não-admin), empresa, estrategista/analista e modelo nunca divirjam entre notas imputadas e respondidas (mitigação de T-116-03-01 do threat model do plano).
- Dedupe **por survey_id** nesta tela (1 nota por survey por dimensão) — deliberadamente diferente da régua do bônus (`DesempenhoScoreService`/`NpsImputationService::notasDoUsuario`, que dedupe por survey+role/pessoa). Ambas as réguas são corretas para seus respectivos consumidores; unificá-las quebraria um dos dois números (documentado no PLAN.md e nos comentários do código).
- D5 (invalidação por competência) implementado como capacidade NOVA: a área NPS não conhecia `bonus_invalidacoes` antes desta plano — só respeitava `NpsResponse::invalidated_at` (invalidação manual de resposta, conceito diferente). Comentário `// Fase 116 D5` explica a régua "competência = mês do survey menos 1".
- Escolha de simplicidade sobre otimização de query na série de 12 meses: cada mês roda sua própria `notasImputadasPorDimensao()` (3 queries extra por mês, 36 no total) em vez de agregar os 12 meses de uma vez em memória (sugestão do PLAN.md). Optado por manter a mesma estrutura de "1 conjunto de queries por mês" já usada pelo código pré-existente para `$responsesM`, priorizando legibilidade/consistência sobre a redução marginal de queries — volume mensal de NPS é baixo (~150 responses/mês, comentário já existente no arquivo).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `Illuminate\Eloquent\Collection::merge()` quebrava ao mesclar notas sintéticas (floats)**
- **Found during:** Tarefa 2 (GREEN), ao rodar `NpsFloorAreaNpsTest` pela primeira vez após o wiring.
- **Issue:** `$responses->map(...)->filter(...)` preserva o tipo `Illuminate\Database\Eloquent\Collection` mesmo depois de virar uma lista de floats/null (map não recasta a classe). Chamar `->merge(array_fill(...))` nessa collection dispara `Illuminate\Database\Eloquent\Collection::merge()`, que assume itens com `getKey()` (Models) — erro `Call to a member function getKey() on float`.
- **Fix:** `collect($responses->map(...)->all())` — cast explícito para `Illuminate\Support\Collection` de base antes do merge, comentado inline com a causa raiz.
- **Files modified:** `app/Http/Controllers/NpsController.php` (dentro de `$agregarMedia`).
- **Verification:** `php artisan test --filter=NpsFloor` passou de 500 (erro fatal) para GREEN.
- **Committed in:** `6f7545c0` (parte do commit da Tarefa 2 — o bug só existiu durante a implementação, nunca chegou a ser commitado quebrado).

**2. [Rule 1 - Bug de teste] Filtro `template_id` no teste de invalidação (D5) excluía a resposta legada de comparação**
- **Found during:** Tarefa 2 (GREEN), 1 teste falhando com `media=0` esperando `4.0`.
- **Issue:** o teste `test_empresa_invalidada_na_competencia_nao_puxa_1_mas_resposta_real_continua` passava `template_id => $template->id` no filtro da tela, mas a resposta "real" de comparação (nota 4) foi criada pelo caminho legado (`template_id=null`) — o filtro a excluía do card, mascarando o comportamento sob teste.
- **Fix:** removido o filtro `template_id` do request de teste (mesmo padrão do teste 1, que já não usava esse filtro) — a resposta legada e o survey não respondido (com template) agora aparecem juntos no card sem filtro de modelo.
- **Files modified:** `tests/Feature/Phase116/NpsFloorAreaNpsTest.php`.
- **Verification:** `php artisan test --filter=NpsFloorAreaNpsTest` 17/17 (depois 9/9 isolado) verde.
- **Committed in:** `6f7545c0` (parte do commit da Tarefa 2, junto com o fix do controller).

---

**Total deviations:** 2 auto-fixed (1 bug de tipo no controller, 1 bug de fixture no teste). Nenhum desvio de escopo — ambos necessários para a suíte GREEN corresponder ao comportamento real pedido pelo plano.

## Issues Encountered

Gate de regressão completo (`--filter=Nps`, 359 testes, 2354 assertions): **5 failed / 354 passed** — EXATAMENTE a mesma contagem e os mesmos nomes da baseline herdada documentada em `deferred-items.md` e no prompt de execução:

| Teste | Causa (pré-existente, fora de escopo) |
|---|---|
| `V18/ConsolidarMesJanelaNpsTest::cron_no_ultimo_dia_do_mes_congela_competencia_m_com_nps_de_m_mais_1` | congelamento de snapshot mensal (`desempenho:consolidar-mes`) — já documentado no 116-01/116-02-SUMMARY |
| `V18/ConsolidarMesJanelaNpsTest::override_mes_continua_funcionando_e_idempotente` | idem acima |
| `V18/JanelaNpsBonusTest::competencia_fechada_le_nps_de_m_mais_1` | instabilidade de `margemPontos` (`AdmanMetricDiffService`, debug aberto 2026-07-23, "NÃO é regressão da Fase 109") — a parte de NPS do teste (4.97) bate certinho |
| `Phase31NpsSubmitTest::generate_cria_survey_com_auto_generated_false` | `expires_at` do disparo manual sai ~3-4 dias menor que `now()->addDays(7)` esperado — `NpsController::generate()` não foi tocado por este plano |
| `Phase69/NpsPhase69IntegrationTest::fluxo_2_generate_manual_por_admin_estrategista` | mesmo sintoma de `expires_at` acima |

Nenhum destes 5 é NOVO nem foi causado pelas mudanças desta plano — `NpsController::generate()` (rota de disparo manual, fonte dos 2 últimos) e `app/Services/Metrics/AdmanMetricDiffService.php`/comando de consolidação (fonte dos 3 primeiros) não foram tocados por nenhuma task deste plano. Confirmado por comparação direta com a tabela de baseline fornecida no prompt de execução (14 Desempenho / 5 Nps) — contagem e nomes idênticos.

`--filter=Phase96` (call-sites de invalidação manual de resposta): **30/30 verde**, sem nenhuma regressão.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- Área NPS (`NpsController::index()`) e bônus (`DesempenhoScoreService::computeNpsMedio`, Plan 02) agora consomem a MESMA fonte (`NpsImputationService`) com réguas de dedupe intencionalmente diferentes e documentadas — os dois números (tela e bônus) não devem mais divergir por "esquecer o não respondido" em um dos dois lados.
- Payload pronto para o Plan 05 (UI explicativa): `cards.*.nao_respondidos` e `regra_nao_respondido` já chegam no front — falta só o texto/visual (fora do escopo deste plano, conforme `<objective>` do PLAN.md: "A UI (texto explicativo) é o plano 116-05").
- Nenhum bloqueador introduzido por este plano. Os 5 itens de `deferred-items.md`/baseline continuam pendentes de debug dedicado (fora do escopo da Fase 116, conforme instrução explícita do executor).

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os 3 arquivos (controller + 2 suítes) confirmados no disco; os 3 hashes de commit (`55a239e4`, `6f7545c0`, `ab2e8934`) confirmados via `git log --oneline --all`.
