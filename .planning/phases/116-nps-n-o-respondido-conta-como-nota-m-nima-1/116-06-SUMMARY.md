---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 06
subsystem: backend
tags: [nps, desempenho, bonificacao, laravel, console, tdd, cron]

requires:
  - phase: 116-01
    provides: "NpsImputationService (materializar/materializarLote) + NpsImputedAssignment"
  - phase: 116-02
    provides: "DesempenhoScoreService::setIncluirImputadas() + notasImputadas() + cacheKey v12"
provides:
  - "Comando `nps:materializar-nao-respondidos` (--dry-run/--force/--mes/--desfazer) com relatório de impacto antes/depois por pessoa e competência (D1)"
  - "Reconsolidação do DesempenhoScoreSnapshot mensal (registro autoritativo do bônus) das competências financeiras backfilladas, via reuso de `desempenho:consolidar-mes`"
  - "Conferência nominal por re-consulta ao banco (nunca parsing de stdout) quando o gate de margem FIXMARG-03 recusa o congelamento"
  - "Rollback completo (--desfazer) com reconsolidação e conferência simétricas"
  - "Nota 1 valendo desde o disparo (D2): hook em NpsDispararMensal e NpsController::generate"
  - "Agendamento diário (09:30 BRT) em routes/console.php"
affects: [116-07, 116-08, desempenho-bonificacao, nps-controller, nps-disparo]

tech-stack:
  added: []
  patterns:
    - "Comando de backfill calcula o 'depois' materializando DE VERDADE dentro de DB::transaction() com exception de controle para rollback — só assim compute() (que lê nps_imputed_assignments do banco) enxerga o efeito da regra sem gravar nada de fato"
    - "Reconsolidação de registro autoritativo é SEMPRE reuso via Artisan::call(), nunca reimplementação do updateOrCreate/gate de margem do comando compartilhado"
    - "Conferência pós-reconsolidação por RE-CONSULTA ao banco (nunca parsing de stdout agregado) — nomeia divergências em vez de confiar em contagens"
    - "Fixture de teste 'saudável' usa Shopee (ShopeeMetricDiffService, 100% local/determinístico) em vez de Adman/Performance para evitar a instabilidade conhecida do AdmanMetricDiffService neste working tree"

key-files:
  created:
    - app/Console/Commands/NpsMaterializarNaoRespondidos.php
    - tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php
  modified:
    - app/Console/Commands/NpsDispararMensal.php
    - app/Http/Controllers/NpsController.php
    - routes/console.php

key-decisions:
  - "Fixture 'saudável' da suíte de testes usa empresa Shopee (sem Adman/margem real) em vez do molde Performance/Adman herdado do Phase110/Phase74 — evita que os testes NOVOS herdem a instabilidade conhecida do AdmanMetricDiffService (var_margem_pct instável/null nesta árvore, debug aberto project_adman_margem_diff_instavel_bonus); a dimensão margem de empresa Shopee é sempre o placeholder determinístico 1.0 (Fase 109 SHOP-DES-02) e nunca aciona o gate FIXMARG-03 (n_elegivel=0)"
  - "Descoberta de pitfall de ambiente (documentada, NÃO corrigida — arquivo intocável): `ConsolidarMesDesempenho::updateOrCreate(['mes_referencia' => 'YYYY-MM-01'])` usa uma STRING bare-date na cláusula WHERE; em MySQL/MariaDB (produção) a coluna DATE nativa trunca a hora e o WHERE casa normalmente contra uma linha já existente, mas em SQLite (testes) a linha persiste como 'YYYY-MM-01 00:00:00' e o WHERE bare-date nunca casa — colide com o unique key no 2º write em vez de atualizar. Os testes que precisavam reconsolidar a MESMA competência duas vezes (forward + desfazer) foram redesenhados para reconsolidar só uma vez por teste (comparando contra `compute()` direto com `setIncluirImputadas(false)`, ou materializando via `NpsImputationService` diretamente antes do `--desfazer`), sem tocar em `ConsolidarMesDesempenho.php`"
  - "Relatório de impacto materializa DE VERDADE dentro de `DB::transaction()` com uma exception de controle (marcador fixo) para desfazer a escrita — é a única forma de `compute()` (que lê `nps_imputed_assignments` via `NpsImputationService`) enxergar o 'depois' sem gravar nada até a confirmação real do operador"
  - "Exit code FAILURE (não sucesso silencioso) quando a conferência nominal encontra divergência — as linhas imputadas permanecem gravadas (a recusa é do congelamento, não do backfill), mas o operador é avisado explicitamente com nome + competência"

requirements-completed: [NPSFLOOR-08, NPSFLOOR-08b, NPSFLOOR-08c, NPSFLOOR-07, NPSFLOOR-11]

duration: ~110min
completed: 2026-07-27
---

# Fase 116 Plano 06: Comando de materialização do NPS não respondido, com reconsolidação verificada do bônus Summary

**Comando `nps:materializar-nao-respondidos` (`--dry-run`/`--force`/`--mes`/`--desfazer`) que produz o relatório de impacto antes/depois por pessoa e competência, reconsolida o `DesempenhoScoreSnapshot` mensal reusando `desempenho:consolidar-mes`, e confere por re-consulta ao banco (nunca por parsing de stdout) se o congelamento de fato aconteceu — nomeando divergências quando o gate de margem FIXMARG-03 recusa; mais os ganchos no disparo (automático e manual) e o agendamento diário.**

## Performance

- **Duration:** ~110 min
- **Completed:** 2026-07-27
- **Tasks:** 3
- **Files modified:** 5 (2 novos, 3 modificados)

## Accomplishments

- `app/Console/Commands/NpsMaterializarNaoRespondidos.php` — comando completo:
  - Relatório antes/depois (D1) por (pessoa × competência financeira), calculado com `compute()` puro (`setIncluirImputadas` alternado entre `false`/`true`), materializando de verdade dentro de uma transação com rollback de controle (única forma de `compute()` enxergar o "depois" sem gravar nada até a confirmação).
  - Plano de reconsolidação explícito: lista as competências que terão o snapshot congelado reescrito e quantas pessoas mudam de faixa NO SNAPSHOT (comparando a `classificacao` já gravada com a esperada).
  - Reconsolidação por REUSO — `Artisan::call('desempenho:consolidar-mes', ['--mes' => ...])` — nunca reimplementa `updateOrCreate` nem o gate de margem FIXMARG-03.
  - Conferência nominal (`conferirSnapshotsReconsolidados`) — re-consulta o `DesempenhoScoreSnapshot` de cada par (pessoa, competência) do relatório aprovado; divergência nomeia pessoa + competência (nunca uma contagem agregada) e retorna `self::FAILURE`.
  - `--dry-run` (não grava nada), `--force` (pula confirmação), `--mes=YYYY-MM` (restringe a materialização e a reconsolidação à competência do disparo), `--desfazer --mes=YYYY-MM` (rollback completo: apaga linhas, busta cache, reconsolida e confere de volta ao valor pré-backfill).
  - Cache-bust do bônus (`Cache::forget(cacheKey(...))`) para cada pessoa afetada.
- `NpsDispararMensal` e `NpsController::generate()` chamam `NpsImputationService::materializar($survey)` logo após criar o survey — a nota 1 vale desde o disparo (D2), sem esperar o cron; falha isolada nunca aborta o disparo (try/catch com `Log::warning`).
- `routes/console.php` — agenda `nps:materializar-nao-respondidos --force` diariamente às 09:30 BRT (`onOneServer()->withoutOverlapping()`), 30min após o disparo mensal e bem antes do fechamento do bônus.
- Suíte `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` — 14 testes (56 assertions) provando: relatório antes/depois, plano de reconsolidação sem tocar o snapshot em dry-run, idempotência do `--force`, reconsolidação mudando o score do snapshot congelado, restrição a competências efetivamente backfilladas, aviso NOMINAL quando o gate de margem degradada recusa o congelamento (com contraste — cobertura saudável não imprime o aviso), confirmação interativa, `--mes` válido/inválido, cenário "nada a fazer", cache-bust, rollback completo e marcação de mudança de faixa.

## Task Commits

Ciclo TDD RED → GREEN, mais o gancho de disparo:

1. **Tarefa 1 (RED): suíte do comando de materialização** - `5709e6c3` (test)
2. **Tarefa 2 (GREEN): comando com relatório, reconsolidação verificada e rollback** - `00acce63` (feat) — inclui ajustes na suíte RED descobertos durante o GREEN (ver Deviations)
3. **Tarefa 3: ganchos no disparo + agendamento diário** - `4ac9727e` (feat)

**Plan metadata:** (este commit) `docs: complete plan`

## Files Created/Modified

- `app/Console/Commands/NpsMaterializarNaoRespondidos.php` (novo) — comando de operação da regra: relatório antes/depois, plano de reconsolidação, reconsolidação por reuso, conferência nominal, cache-bust, rollback.
- `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` (novo) — 14 testes de Console cobrindo todo o `<behavior>` da Tarefa 1.
- `app/Console/Commands/NpsDispararMensal.php` — gancho `materializar($survey)` após cada `NpsSurvey::create` bem-sucedido no loop de modelos aplicáveis (fora do ramo `--dry-run`).
- `app/Http/Controllers/NpsController.php` — gancho `materializar($survey)` em `generate()` (disparo manual) + import de `Log`.
- `routes/console.php` — entrada de schedule `nps-materializar-nao-respondidos` (09:30 BRT diário).

## Decisions Made

- Fixture "saudável" da suíte nova usa empresa **Shopee** (não Adman/Performance) — decisão tomada durante a execução ao descobrir que o molde herdado (Phase74/Phase110, baseado em `AdmanMetricDiffService`) está instável nesta árvore (mesma causa raiz documentada em `deferred-items.md`: commit de outra sessão, `var_margem_pct` null). `ShopeeMetricDiffService` é 100% local/determinístico (sem HTTP, sem instabilidade), e a dimensão margem de empresa Shopee é sempre o placeholder fixo 1.0 (Fase 109), nunca acionando o gate FIXMARG-03. O teste que PRECISA acionar o gate (margem degradada) continua usando o molde Adman/Performance herdado do Phase110 — cobertura baixa (`n_real/n_elegivel < 0.7`) é determinística nesse cenário independentemente da instabilidade do valor real de margem.
- Relatório antes/depois materializa DE VERDADE dentro de `DB::transaction()` com uma exception de controle (marcador fixo `__NPS_MATERIALIZAR_IMPACTO_ROLLBACK__`) para desfazer a escrita — documentado inline como a única forma de `compute()` (que lê `nps_imputed_assignments` via `NpsImputationService`) enxergar o "depois" sem gravar nada até a confirmação real do operador.
- Conferência (`conferirSnapshotsReconsolidados`) usa `faixa_bonus` de `compute()` (que já inclui a promoção DESEMP-08) como valor "esperado", em vez de `BonusFaixa::classificar()` puro — porque é exatamente o que `ConsolidarMesDesempenho` persiste em `classificacao`. `BonusFaixa::classificar()` puro é usado só no relatório antes/depois (colunas "Faixa antes"/"Faixa depois"), que não precisa refletir a promoção histórica.
- Exit code `FAILURE` (não sucesso silencioso) quando há divergência na conferência — decisão travada pelo plano (NPSFLOOR-08c), fixada tanto no comando quanto no teste.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug de teste] `expectsOutputToContain()` só credita UMA expectativa por chamada de output**
- **Found during:** Tarefa 2 (GREEN), rodando a suíte RED pela primeira vez contra o comando implementado.
- **Issue:** o mock de `$this->artisan()->expectsOutputToContain(...)` (Mockery, via `PendingCommand`) só marca UMA expectativa como satisfeita por cada chamada de `doWrite()` (1 linha de tabela = 1 chamada). Quando múltiplos textos esperados (`'Pessoa'`, `'Competência'`, `'NPS antes'`, etc.) coexistem na MESMA linha da tabela do relatório de impacto, apenas a PRIMEIRA expectativa declarada "rouba" a linha — as demais nunca são satisfeitas, mesmo o texto estando visivelmente presente na saída real.
- **Fix:** o teste de dry-run (`test_dry_run_nao_grava_nada_e_mostra_relatorio_antes_depois`) passou a usar `Illuminate\Support\Facades\Artisan::call()` + `Artisan::output()` com `assertStringContainsString()` (checagem direta na string completa, sem a limitação de 1-expectativa-por-chamada do mock).
- **Files modified:** `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php`.
- **Verification:** teste passou a verde sem alterar o comando.
- **Committed in:** `00acce63` (parte do commit da Tarefa 2 — a suíte RED nunca chegou a ser commitada com esse bug, o ajuste faz parte do ciclo GREEN).

**2. [Rule 1 - Bug de teste + pitfall de ambiente] Limitação do `updateOrCreate(mes_referencia)` em SQLite**
- **Found during:** Tarefa 2 (GREEN), 2 testes (`test_reconsolidacao_do_snapshot_muda_score_apos_o_backfill` e `test_desfazer_remove_linhas_reconsolida_e_devolve_score_anterior`) falhando com exit code 1 em vez de 0.
- **Issue:** `ConsolidarMesDesempenho::handle()` faz `DesempenhoScoreSnapshot::updateOrCreate(['user_id' => ..., 'mes_referencia' => $mesStr], [...])` com `$mesStr` uma STRING bare-date (`'2026-07-01'`). Em MySQL/MariaDB (produção) a coluna `mes_referencia` é `DATE` nativa — o motor trunca a hora tanto na gravação quanto na comparação, então o WHERE bare-date casa normalmente contra uma linha já existente. Em SQLite (testes) a coluna não tem tipagem real — a linha é persistida como `'2026-07-01 00:00:00'` (formato completo aplicado pelo cast `date` do Eloquent na gravação) e o WHERE bare-date NUNCA casa contra ela, então `updateOrCreate` tenta um INSERT que colide com o unique key `(user_id, ref_date, mes_referencia)` em vez de atualizar. Reproduzido em isolamento total (sem Adman, sem margem, sem NPS) confirmando que é um artefato do ambiente de teste, não desta fase — e que os próprios testes de idempotência pré-existentes (`Phase74/ConsolidarMesDesempenhoCommandTest::test_idempotencia_do_command_consolidar_mes`, `Phase110/ConsolidarMesMargemResilienteTest::test_idempotencia_preservada_apos_o_gate`) já estão na baseline de falhas herdada (por outro motivo visível primeiro — a instabilidade Adman —, mas sujeitos ao MESMO artefato SQLite por baixo).
- **Fix:** os 2 testes foram redesenhados para reconsolidar a MESMA competência apenas UMA vez dentro do teste: `test_reconsolidacao_do_snapshot_muda_score_apos_o_backfill` compara o snapshot pós-comando contra um valor "antes" calculado DIRETO via `DesempenhoScoreService::compute()` com `setIncluirImputadas(false)` (em vez de uma chamada real anterior a `desempenho:consolidar-mes`); `test_desfazer_remove_linhas_reconsolida_e_devolve_score_anterior` materializa as linhas direto via `NpsImputationService::materializarLote()` (sem passar pelo comando, que reconsolidaria automaticamente) e usa o `--desfazer` como a ÚNICA reconsolidação de julho no teste. `ConsolidarMesDesempenho.php` permanece INTOCADO, conforme a decisão travada do PLAN.md.
- **Files modified:** `tests/Feature/Phase116/NpsMaterializarNaoRespondidosCommandTest.php` (nenhum arquivo de produção).
- **Verification:** `php artisan test --filter=NpsMaterializarNaoRespondidosCommandTest` 14/14 verde; `php artisan test --filter=Nps` mantém a mesma baseline de 5 falhas (nenhuma nova).
- **Committed in:** `00acce63` (parte do commit da Tarefa 2).

---

**Total deviations:** 2 auto-fixed (1 bug de teste de forma/mock, 1 pitfall de ambiente SQLite descoberto e contornado sem tocar em arquivo compartilhado). Nenhum desvio de escopo — ambos necessários para a suíte refletir corretamente o comportamento real do comando sem violar a restrição explícita de não editar `ConsolidarMesDesempenho.php`.

## Issues Encountered

Gate de regressão completo, rodado após as 3 tarefas:

| Suíte | Resultado | vs. baseline informada |
|---|---|---|
| `--filter=NpsMaterializarNaoRespondidosCommandTest` | 14 passed (56 assertions) | novo — GREEN |
| `--filter=NpsDisparar` | 17 passed (82 assertions) | GREEN, sem regressão |
| `--filter=Nps` | 5 failed / 381 passed (2570 assertions) | **igual à baseline** — mesmos 5 testes nomeados (2x `ConsolidarMesJanelaNpsTest`, `JanelaNpsBonusTest`, `Phase31NpsSubmitTest`, `Phase69/NpsPhase69IntegrationTest`) |
| `--filter=Desempenho` | 14 failed / 91 passed (366 assertions) | **igual à baseline** (14 failed/91 passed) |
| `--filter=Performance` | 2 failed / 76 passed (365 assertions) | **igual à baseline** (2 failed) |
| `--filter=Portfolio` | 3 failed / 48 passed (475 assertions) | pré-existente, fora de escopo |
| `--filter=Carteira` | 4 failed / 105 passed (626 assertions) | pré-existente, fora de escopo (baseline informada agrupava Portfolio/Carteira como 5; filtros têm sobreposição de arquivos) |
| `--filter=Company` | 5 failed / 160 passed (812 assertions) | **igual à baseline** (5 failed) |

Nenhuma falha nova em nenhuma suíte. `deferred-items.md` não recebeu itens novos — o pitfall SQLite descoberto (item 2 acima) foi resolvido no lado do teste, sem precisar de entrada de "pendência" (não afeta produção, só o ambiente de teste local).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- **GATE DE DEPLOY (lembrete do PLAN.md, não verificado nesta execução):** este plano liga a escrita real do backfill (gancho no disparo + cron diário). Antes de qualquer `deploy.sh`, confirmar que os SUMMARYs de 116-03, 116-04 e 116-05 existem e que os arquivos deles estão commitados na `main` — caso contrário a área NPS, a carteira do profissional e os dashboards ficariam discordando do Desempenho em produção. Este plano (116-06) NÃO deve subir isolado.
- Comando pronto para o backfill retroativo real: `php artisan nps:materializar-nao-respondidos --dry-run` (sem `--mes`) mostra o relatório de impacto completo do histórico antes de qualquer aplicação — é o checkpoint humano previsto para o plano 116-08.
- Nenhum bloqueador introduzido por este plano. A baseline herdada (Adman/margem instável, `expires_at` de disparo manual, snapshot mensal `ConsolidarMesJanelaNpsTest`) permanece idêntica e documentada em `deferred-items.md`.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os 3 arquivos (comando + suíte de teste + este SUMMARY.md) confirmados no disco; os 3 hashes de commit (`5709e6c3`, `00acce63`, `4ac9727e`) confirmados via `git log --oneline --all`.
