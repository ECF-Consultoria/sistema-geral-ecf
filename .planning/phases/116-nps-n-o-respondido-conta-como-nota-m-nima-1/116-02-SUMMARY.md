---
phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1
plan: 02
subsystem: backend
tags: [nps, desempenho, bonificacao, laravel, tdd, cache]

requires: ["116-01"]
provides:
  - "DesempenhoScoreService::notasImputadas() — 3º ramo da união disjunta de computeNpsMedio"
  - "DesempenhoScoreService::setIncluirImputadas() — toggle exclusivo do backfill/relatório de impacto (Plan 06)"
  - "cacheKey() bumpado para desempenho.compute.v12"
affects: [116-03, 116-04, 116-05, 116-06, 116-07, 116-08, dashboard-desempenho, bonificacao]

tech-stack:
  added: []
  patterns:
    - "União disjunta extensível a N ramos (A atribuições / B legado / C imputadas) — cada ramo novo só precisa repassar $invalidadas igual aos anteriores"
    - "Toggle de comportamento exclusivo de comando de backfill (setIncluirImputadas), nunca exposto a controller/tela — default true preserva 100% dos consumidores existentes"
    - "Bump de versão de cacheKey documentado com comentário histórico (v1..v12) no próprio método"

key-files:
  created:
    - tests/Feature/Phase116/NpsFloorDesempenhoTest.php
    - .planning/phases/116-nps-n-o-respondido-conta-como-nota-m-nima-1/deferred-items.md
  modified:
    - app/Services/DesempenhoScoreService.php
    - tests/Feature/DesempenhoShopeeScoreTest.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
    - tests/Feature/V18/DesempenhoMetadadosCacheTest.php
    - tests/Feature/BonusInvalidacaoEmpresaTest.php
    - tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php

key-decisions:
  - "notasImputadas() delega 100% para NpsImputationService::notasDoUsuario() — nenhuma lógica de resolução de responsável/competência/invalidação é reimplementada em DesempenhoScoreService"
  - "Os 3 ramos (A/B/C) são disjuntos por construção: A/B exigem nps_surveys.status='completed'; C só existe para survey que NUNCA chegou a completed (NpsImputationService::materializar limpa/nunca cria linha de survey respondido)"
  - "setIncluirImputadas(bool): static é EXCLUSIVO do comando de backfill (Plan 06/D1) — default true, nenhum controller/dashboard/job de consolidação pode desligar a regra"
  - "cacheKey() v11->v12 com comentário histórico no mesmo formato das versões anteriores (v1..v11) — chaves v11 órfãs expiram por TTL, sem precisar de cache:clear"
  - "Auditoria de fixtures pendentes (5 arquivos) concluiu que NENHUMA precisava de ajuste: nenhum survey 'pending' fica sem resposta nelas (todas respondem via POST /nps/{token} na mesma execução) e nenhuma chama NpsImputationService::materializar/materializarLote — logo o ramo C nunca contribui linha nenhuma para essas suítes"

requirements-completed: [NPSFLOOR-02, NPSFLOOR-04, NPSFLOOR-05, NPSFLOOR-07]
requirements-partial: [NPSFLOOR-10]

duration: ~65min
completed: 2026-07-27
---

# Fase 116 Plano 02: NPS não respondido conta no bônus (ramo C + cache v12) Summary

**`DesempenhoScoreService::computeNpsMedio()` ganha o 3º ramo da união disjunta (`notasImputadas()`, delegado 100% ao `NpsImputationService` do Plan 01) — NPS efetivamente disparado e não respondido agora entra como nota 1 na média do bônus, respeitando invalidação por competência e a transição provisório→definitivo; `cacheKey()` bumpado de v11 para v12.**

## Performance

- **Duration:** ~65 min
- **Completed:** 2026-07-27
- **Tasks:** 3
- **Files modified:** 8 (2 novos, 6 modificados)

## Accomplishments

- `DesempenhoScoreService::notasImputadas()` — novo método privado que delega 100% para `NpsImputationService::notasDoUsuario()`, sem reimplementar resolução de responsável, competência ou invalidação.
- `computeNpsMedio()` mescla o 3º ramo (C) junto aos ramos (A) atribuições congeladas e (B) legado — os três são disjuntos por construção (A/B exigem `status='completed'`; C só existe para survey que nunca chegou a `completed`).
- Toggle `setIncluirImputadas(bool): static` (default `true`) — abertura exclusiva para o comando de backfill/relatório de impacto do Plan 06 (D1) montar a coluna "antes" (sem a regra); nenhum controller, dashboard ou job de consolidação pode desligá-la.
- `cacheKey()` bumpado `v11` → `v12`, com o mesmo padrão de comentário histórico das 11 versões anteriores — sem o bump, o Redis serviria o bônus antigo por até 7 dias com o código novo em prod.
- Suíte nova `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` (7 testes, 9 assertions) prova: não respondido = 1; resposta real + não respondido = média sem dupla contagem; empresa invalidada não puxa 1; gap do consolidado em resposta real não vira 1; resposta tardia não apaga o 1 definitivo; empresa sem survey preserva a sentinela de vazio (D3); `cacheKey()` na v12.
- 7 asserções de `cacheKey` hardcoded em 3 arquivos (`DesempenhoShopeeScoreTest`, `Phase96/NpsInvalidacaoRespostaTest` ×2, `V18/DesempenhoMetadadosCacheTest` ×4) bumpadas de v11 para v12.
- 2 cenários novos em `BonusInvalidacaoEmpresaTest` (empresa invalidada + survey não respondido não puxa 1) e `AtribuicaoConsolidadoNpsTest` (consolidado sem resposta gera linha imputada correta; espelho do gap em resposta real não vira 1 indevida).
- Auditoria completa dos 5 arquivos de fixture listados no plano (`BonusAtribuicoesNpsTest`, `AtribuicaoPorServicoNpsTest`, `JanelaNpsBonusTest`, `ConsolidarMesJanelaNpsTest`, `BonusInvalidacaoEmpresaTest`): nenhum precisou de ajuste — nenhum survey "pending" fica sem resposta e nenhum chama a materialização, então o ramo C nunca contribui linha nenhuma para essas suítes hoje.

## Task Commits

Cada task foi commitada atomicamente (ciclo TDD RED → GREEN → reconciliação):

1. **Tarefa 1 (RED): suíte de bônus da regra "não respondido = 1"** - `aaafe5b9` (test) — 4 failed / 3 passed confirmado (RED esperado).
2. **Tarefa 2 (GREEN): 3º ramo notasImputadas() + toggle + bump cacheKey v11→v12** - `4328a0b9` (feat) — suíte nova 7/7 verde.
3. **Tarefa 3: reconciliação da suíte existente (cache v12 + fixtures + 2 cenários novos)** - `fd626404` (test).

**Plan metadata:** (próximo commit) `docs: complete plan`

## Files Created/Modified

- `app/Services/DesempenhoScoreService.php` - novo método `notasImputadas()`, wiring em `computeNpsMedio()`, toggle `setIncluirImputadas()`, bump de `cacheKey()` v11→v12, injeção de `NpsImputationService` no construtor.
- `tests/Feature/Phase116/NpsFloorDesempenhoTest.php` (novo) - 7 testes provando NPSFLOOR-02/04/05/07 no bônus.
- `tests/Feature/DesempenhoShopeeScoreTest.php` - bump de 1 asserção de cacheKey v11→v12.
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` - bump de 2 asserções de cacheKey v11→v12.
- `tests/Feature/V18/DesempenhoMetadadosCacheTest.php` - bump de 4 asserções de cacheKey v11→v12.
- `tests/Feature/BonusInvalidacaoEmpresaTest.php` - +1 teste (empresa invalidada + survey não respondido).
- `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` - +2 testes (consolidado sem resposta; gap em resposta real).
- `.planning/phases/116-.../deferred-items.md` (novo) - 4 achados pré-existentes fora de escopo, descobertos nos gates de regressão.

## Decisions Made

- `notasImputadas()` é um wrapper fino — a régua de negócio inteira (contrato ativo, fallback consolidado, competência, invalidação, provisório/definitivo) vive só em `NpsImputationService` (Plan 01), preservando a "fonte única" estabelecida naquele plano.
- Toggle `setIncluirImputadas()` documentado como uso EXCLUSIVO do comando de backfill (Plan 06) — decisão travada no PLAN.md, aplicada ao pé da letra: default `true`, docblock explícito proibindo uso em controller/tela.
- Auditoria de fixtures (Tarefa 3-b) concluiu que a preocupação do plano ("survey pendente de passagem passa a contar 1 e quebra asserção não relacionada") **não se materializa no estado atual do código**: a materialização (`NpsImputationService::materializar/materializarLote`) só acontece via chamada explícita — não existe ainda nenhum job/comando de produção que a dispare automaticamente (isso é escopo do Plan 06). Logo, fixtures que criam survey "pending" mas nunca chamam a materialização não geram nenhuma linha em `nps_imputed_assignments`, e o ramo C contribui coleção vazia para elas. Documentado no código/commit em vez de alterar fixtures que não precisavam de mudança.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio de Regra 1/2/3 — o plano foi executado como escrito. O único ajuste foi de forma (não de comportamento): para satisfazer o grep de aceite da Tarefa 2 (`notasImputadas` deve aparecer >=3× em linhas não-comentário), o merge do ramo (C) foi escrito como uma atribuição a variável local (`$notasImputadas = ... ? $this->notasImputadas(...) : collect();`) em vez de uma chamada inline dentro do `if` — mesma semântica, só reorganização para deixar a chamada, a declaração e o guard de toggle todos nomeando o método explicitamente (facilita leitura/grep futuro).

## Issues Encountered

Os gates de regressão (`--filter=Desempenho`, `--filter=Nps`, `--filter=Bonus`) expuseram **falhas pré-existentes fora do escopo desta fase**, todas confirmadas via `git diff` vazio no arquivo afetado (nenhum tocado por este plano) e/ou reprodução idêntica em execução 100% isolada (sem nenhum arquivo da Fase 116 carregado):

1. **Baseline herdada de 14 falhas em `--filter=Desempenho`** (`var_margem_pct`/`AdmanMetricDiffService`, commit `25a958b3` de outra sessão paralela hoje). Medida ANTES deste plano: 14 failed/83 passed. Medida DEPOIS (com o ramo C ativo e a suíte nova): **14 failed/90 passed** (355→364 assertions, +9 dos 7 testes novos). Contagem de falhas estável — nenhuma nova, nenhuma resolvida (fora de escopo, `AdmanMetricDiffService.php` intocado).
2. **`V18/JanelaNpsBonusTest::test_competencia_fechada_le_nps_de_m_mais_1`** — instabilidade de margem já documentada em `.planning/debug/` (`project_adman_margem_diff_instavel_bonus`, aberto 2026-07-23). A parte de NPS do teste (4.97) bate certinho — só `margemPontos` diverge.
3. **`Phase31NpsSubmitTest` / `Phase69/NpsPhase69IntegrationTest`** — `expires_at` do disparo manual do NPS sai ~3-4 dias menor que o esperado. Arquivo/controller intocados por este plano; reproduzido isoladamente.
4. **`V18/ConsolidarMesJanelaNpsTest`** (2 falhas) — já documentado como pré-existente no `116-01-SUMMARY.md`.

Detalhes completos em `.planning/phases/116-.../deferred-items.md`. Nenhum destes 4 itens foi corrigido (fora de escopo, conforme instrução explícita do executor).

**Comparação final de falhas vs. a baseline informada (14 em `--filter=Desempenho`):**

| Gate | Failed | Passed | Assertions | vs. baseline |
|------|--------|--------|------------|--------------|
| `--filter=NpsFloorDesempenhoTest` | 0 | 7 | 9 | novo — GREEN |
| `--filter=Desempenho` | 14 | 90 | 364 | **igual à baseline** (355→364 = +9 dos 7 testes novos; 83→90 = +7) |
| `--filter=Nps` | 5 | 344 | 2237 | +3 vs. o único item documentado no 116-01 (ConsolidarMesJanelaNpsTest, 2); os 3 adicionais (Phase31NpsSubmitTest, Phase69IntegrationTest, JanelaNpsBonusTest) confirmados pré-existentes/fora de escopo (ver acima) |
| `--filter=Bonus` | 1 | 40 | 216 | JanelaNpsBonusTest (instabilidade de margem, fora de escopo) |
| `--filter=BonusInvalidacaoEmpresaTest` | 0 | 5 | 13 | GREEN (inclui o cenário novo) |
| `--filter=AtribuicaoConsolidadoNpsTest` | 0 | 9 | 39 | GREEN (inclui os 2 cenários novos) |

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- O ponto de extensão real do bônus (`computeNpsMedio`) está ativo — Plan 03+ (área NPS) e Plan 06 (comando de backfill/relatório de impacto) podem consumir `NpsImputationService` diretamente ou observar o efeito via `DesempenhoScoreService`.
- `setIncluirImputadas()` está pronto para o Plan 06 montar a coluna "antes" do relatório antes/depois — nenhuma outra mudança necessária neste service.
- Bloqueador pendente e FORA do escopo desta fase: as 4 falhas pré-existentes documentadas em `deferred-items.md` (margem instável, `expires_at` manual, snapshot mensal) devem ser resolvidas em debug dedicado antes do fechamento final da milestone, mas não bloqueiam o andamento da Fase 116.

---
*Phase: 116-nps-n-o-respondido-conta-como-nota-m-nima-1*
*Completed: 2026-07-27*

## Self-Check: PASSED

Todos os arquivos criados (`NpsFloorDesempenhoTest.php`, `deferred-items.md`, este SUMMARY.md) confirmados no disco; os 3 hashes de commit (`aaafe5b9`, `4328a0b9`, `fd626404`) confirmados via `git log --oneline --all`.
