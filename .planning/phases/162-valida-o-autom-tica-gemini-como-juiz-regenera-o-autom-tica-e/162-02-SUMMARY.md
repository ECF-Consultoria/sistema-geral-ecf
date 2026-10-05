---
phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e
plan: 02
subsystem: ai
tags: [gemini, creative-engine, laravel, validacao-automatica, queue, laravel-jobs]

# Dependency graph
requires:
  - phase: 162-01
    provides: "CreativeJuiz::julgarEGravar(), ImageJudgementProvider, colunas validacao_status/validacao/validacao_pedida_em/validacao_em"
provides:
  - "ValidarCriativoIaJob na fila creative, chave de unicidade propria (validacao:{id})"
  - "Dispatch automatico no fim de GerarCriativoIaJob (VAL-01) -- toda imagem gerada entra em validacao sem clique"
  - "encerrarValidacaoSeTravada() em MlAnuncioCriativo -- trava anti-loop por tempo (VAL-06)"
  - "Gate de validacao em criativoAprovar() -- pendente recusa, reprovada so sobe com confirmar_risco (VAL-04)"
  - "MlAnuncioCriativoKit::prontasSemRisco()/reprovadas() -- aprovar o kit nunca sobe reprovada em silencio"
affects: [162-03, 162-04]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Job de validacao SEPARADO do job de geracao, com chave de unicidade PROPRIA (validacao:{id} vs criativo:{id}) -- a onda 3 (regeneracao automatica) precisa despachar o job de geracao para o MESMO criativo sem colidir com o lock do job de validacao"
    - "Fail-open dentro do job: falha do PROVEDOR de julgamento (RuntimeException/FalhaDeGeracaoTrocavel) e capturada no handle() e vira indisponivel na hora -- nao depende de tries/failed() para nao travar a aprovacao"
    - "Gate de estado no SERVIDOR em vez de status novo no model -- validacao_status e campo auxiliar, nunca entra no fluxo de recalcularStatus()/whitelist existente"
    - "whereNotIn + orWhereNull para nao excluir silenciosamente as linhas com coluna NULL (semantica SQL de NOT IN com NULL)"

key-files:
  created:
    - app/Jobs/ValidarCriativoIaJob.php
  modified:
    - app/Jobs/GerarCriativoIaJob.php
    - app/Models/MlAnuncioCriativo.php
    - app/Models/MlAnuncioCriativoKit.php
    - app/Http/Controllers/MlbAnuncioController.php
    - tests/Feature/Phase162/ValidacaoAutomaticaTest.php
    - tests/Feature/Phase162/AprovacaoComValidacaoTest.php

key-decisions:
  - "encerrarValidacaoSeTravada() foi criado na Task 1 (não na Task 2 como o plano sugeria) porque o ValidarCriativoIaJob depende dela para rodar -- deslocamento pragmatico de Rule 3 (blocking issue), documentado como desvio abaixo"
  - "ValidarCriativoIaJob captura (try/catch) qualquer Throwable do CreativeJuiz::julgarEGravar() e grava indisponivel na hora, em vez de deixar a excecao escalar para tries/failed() -- necessario porque QUEUE_CONNECTION=sync nos testes faz o dispatch rodar INLINE dentro do handle() do GerarCriativoIaJob, e sem o catch a suite inteira da Fase 161 que chama o job de geracao diretamente (sem Queue::fake()) quebraria com RuntimeException do juiz; tries/failed() seguem vivos para timeout do worker e falhas de infraestrutura antes do try"
  - "override de risco confirmado e gravado ANTES do upload (literal do plano) -- registra a intencao de quem assumiu o risco mesmo que o upload em si falhe depois"

patterns-established:
  - "Mensagem de recusa do gate de validacao montada no servidor com partes condicionais (so menciona reprovada/pendente quando a contagem e > 0) -- nunca string crua do juiz, nunca id interno, nunca nome de classe (T-162-13)"

requirements-completed: [VAL-01, VAL-04, VAL-06]

# Metrics
duration: ~40min (do primeiro ao ultimo commit de task, incluindo o ajuste de regressao da Task 1)
completed: 2026-10-05
---

# Phase 162 Plan 02: Validação automática ligada ao fluxo real Summary

**`ValidarCriativoIaJob` (fila `creative`, chave própria) despachado no fim de toda geração grava o veredito do juiz antes de qualquer aprovação chegar ao Mercado Livre — pendente recusa, reprovada só sobe com `confirmar_risco` auditado, e aprovar o kit nunca sobe imagem reprovada em silêncio.**

## Performance

- **Duration:** ~40 min entre o primeiro commit (Task 1, incluindo o ajuste de regressão descoberto durante a execução) e o terceiro commit (Task 3)
- **Tasks:** 3/3 completas
- **Files modified:** 7 (1 criado, 6 modificados)

## Accomplishments

- `ValidarCriativoIaJob` novo na fila `creative`, com `uniqueId() = 'validacao:{id}'` — chave DIFERENTE de `GerarCriativoIaJob` (`criativo:{id}`), para a regeneração automática da onda 3 poder despachar o job de geração para o mesmo criativo sem o Laravel descartar o despacho em silêncio (a armadilha que a 161-02 já pagou para descobrir)
- `GerarCriativoIaJob` grava `validacao_status = pendente` + `validacao_pedida_em` na MESMA `update()` que fecha `status = pronto`, e despacha o job de validação — exceto com `services.creative.validacao.ativa` desligada, que grava `indisponivel` direto sem job (desligamento sem deploy, OPS-03 do validador)
- `encerrarValidacaoSeTravada()` em `MlAnuncioCriativo` (VAL-06) — molde literal de `encerrarSeTravada()`; chamada pelo job e pelos dois endpoints de aprovação, garante que um `pendente` nunca fica preso para sempre
- `criativoAprovar()` ganhou o GATE no servidor: `pendente` recusa 422 sem tentar upload; `reprovada` recusa com a mensagem do veredito e só aceita com `confirmar_risco: true` no corpo, registrando o override (`user_id` + `em`) em `validacao.override` — auditado na TABELA, nunca só no log
- `MlAnuncioCriativoKit::prontasSemRisco()`/`reprovadas()` e o novo comportamento de `criativoKitAprovar()`: reprovada/pendente são PULADAS no lote (nunca contam como falha), voltam na resposta como `reprovadas[]`/`validando[]`, e o kit só fecha quando não há nenhuma pulada — preservando a foto de referência do portador para a regeneração do slot problemático
- 15 testes novos em `tests/Feature/Phase162/` (`ValidacaoAutomaticaTest` + `AprovacaoComValidacaoTest`), cobrindo os dois endpoints, o job de validação, a trava de tempo e a não-regressão com `validacao_status` NULL (os 12 criativos legados e o fluxo sem kit da Fase 160)

## Task Commits

1. **Task 1: ValidarCriativoIaJob + dispatch no fim da geração (VAL-01)** — `f4fe288d` (feat)
2. **Task 2: gate de validação no criativoAprovar, com confirmação de risco (VAL-01/04/06)** — `5bcec609` (feat)
3. **Task 3: aprovar o kit nunca sobe imagem reprovada em silêncio (VAL-04 em lote)** — `43a915d8` (feat)

**Plan metadata:** (este commit, docs — ver `<final_commit>`)

## Files Created/Modified

- `app/Jobs/ValidarCriativoIaJob.php` — job novo, fila `creative`, chave própria, fail-open capturado internamente
- `app/Jobs/GerarCriativoIaJob.php` — marca `validacao_status=pendente`/`validacao_pedida_em` e despacha a validação no fim da etapa "salvando"
- `app/Models/MlAnuncioCriativo.php` — `encerrarValidacaoSeTravada()` (VAL-06)
- `app/Models/MlAnuncioCriativoKit.php` — `prontasSemRisco()`/`reprovadas()`
- `app/Http/Controllers/MlbAnuncioController.php` — gate em `criativoAprovar()` e `criativoKitAprovar()` (ÚNICOS dois métodos tocados, confirmado por `git diff` dos hunks)
- `tests/Feature/Phase162/ValidacaoAutomaticaTest.php` — 6 testes (geração→validação, chave desligada, veredito gravado, `failed()`, já-aprovado não gasta chamada, gate de grep da fila)
- `tests/Feature/Phase162/AprovacaoComValidacaoTest.php` — 13 testes (pendente, reprovada com/sem override, aprovada/indisponível/nula, trava de tempo, mensagem sem detalhe interno, escopo, e os 4 casos de aprovação em lote do kit)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: (1) `encerrarValidacaoSeTravada()` foi antecipada para a Task 1 porque o job novo depende dela para existir — desvio documentado abaixo; (2) o job de validação captura exceções do provedor internamente e grava `indisponivel` na hora (fail-open), em vez de depender de `tries`/`failed()`; (3) o override de risco confirmado é gravado antes do upload, como o plano pediu literalmente.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking issue] `encerrarValidacaoSeTravada()` criada na Task 1, não na Task 2**
- **Found during:** Task 1 (escrevendo `ValidarCriativoIaJob::handle()`, que chama esse método antes de gastar qualquer chamada)
- **Issue:** O plano listava `app/Models/MlAnuncioCriativo.php` nos arquivos da Task 2, mas a ação da própria Task 1 já manda chamar `$criativo->encerrarValidacaoSeTravada()` dentro do `handle()` do job novo — sem o método existir, o job quebraria.
- **Fix:** O método foi implementado já na Task 1, no molde literal de `encerrarSeTravada()` (mesma classe). A Task 2 apenas CONSOME o método já existente dentro do gate do controller — nenhuma linha nova foi necessária em `MlAnuncioCriativo.php` na Task 2.
- **Files modified:** `app/Models/MlAnuncioCriativo.php` (commit da Task 1)
- **Commit:** `f4fe288d`

**2. [Rule 1 - Bug/regressão] Fail-open capturado dentro do job, não via `tries`/`failed()`**
- **Found during:** Task 1, ao rodar a suíte de regressão `tests/Feature/Phase161/CriativoKitGeracaoTest.php`
- **Issue:** `QUEUE_CONNECTION=sync` no `phpunit.xml` faz `ValidarCriativoIaJob::dispatch()` (chamado de dentro de `GerarCriativoIaJob::handle()`) executar INLINE, na mesma chamada. Os 5 testes de `CriativoKitGeracaoTest` que chamam `handle()` diretamente (sem `Queue::fake()`) usam um `Http::fake()` genérico (`gemini.teste/*` sempre devolve a forma de resposta de GERAÇÃO de imagem) — a chamada de julgamento, batendo no mesmo fake, recebia uma resposta sem texto e o `GeminiImageProvider::julgar()` lançava `RuntimeException`, que escapava do `handle()` do job de VALIDAÇÃO e quebrava o teste de GERAÇÃO que nem sabia que a validação existia.
- **Fix:** `ValidarCriativoIaJob::handle()` passou a envolver `$juiz->julgarEGravar($criativo)` em `try/catch(\Throwable)`, gravando `indisponivel` e retornando sem relançar — consistente com a Decisão 3 do plano ("fail-open, nunca fail-closed": provedor fora do ar deve liberar a aprovação, não travar esperando retentativa). `$tries`/`backoff`/`failed()` continuam existindo para o que pode matar o processo antes de chegar ao `try` (timeout do worker) ou falha de infraestrutura do Laravel.
- **Files modified:** `app/Jobs/ValidarCriativoIaJob.php`
- **Commit:** `f4fe288d`

**3. [Rule 3 - Blocking issue] Cache de categoria semeado nos testes novos do kit (Task 3)**
- **Found during:** Task 3, ao escrever os 4 testes de aprovação em lote
- **Issue:** Sem `Cache::put('ml_meta_categoria_MLB1574', ...)` antes de criar o kit, `aplicarPictures()` bateria na API real de metadados do ML (mascarada pelo `Http::fake()` genérico do upload), devolvendo uma forma sem `access_token` e estourando no refresh de token — molde já usado em `CriativoKitAprovacaoTest` (Fase 161) que eu tinha deixado de reproduzir.
- **Fix:** Cache semeado no helper `kitComSlotsProntos()` do novo teste, replicando exatamente o que `CriativoKitAprovacaoTest::kitComSlotsProntos()` já fazia.
- **Files modified:** `tests/Feature/Phase162/AprovacaoComValidacaoTest.php`
- **Commit:** `43a915d8`

## Issues Encountered

Nenhum bloqueio não resolvido. O único ponto de atenção real foi a interação `QUEUE_CONNECTION=sync` + dispatch dentro de `handle()` descrita no desvio 2 — resolvida sem alterar nenhum teste da Fase 160/161.

## User Setup Required

Nenhum. As chaves de config já existiam desde o Plano 01 (`.env.example` já documentado).

## Attribution Note

O commit da Task 1 (`f4fe288d`) foi feito sem a linha `Co-Authored-By` por um lapso meu — percebido só depois do commit, e não corrigido via `git commit --amend` porque o protocolo de segurança git deste ambiente proíbe amend fora de pedido explícito do usuário. Os commits das Tasks 2 e 3 incluem a linha corretamente.

## Self-Check: PASSED

- `app/Jobs/ValidarCriativoIaJob.php` (contém `onQueue('creative')` e `uniqueId`) — FOUND
- `app/Models/MlAnuncioCriativo.php` (contém `encerrarValidacaoSeTravada`) — FOUND
- `app/Models/MlAnuncioCriativoKit.php` (contém `prontasSemRisco`) — FOUND
- `app/Http/Controllers/MlbAnuncioController.php` (contém `confirmar_risco`) — FOUND
- `tests/Feature/Phase162/ValidacaoAutomaticaTest.php` — FOUND (6 testes, verde)
- `tests/Feature/Phase162/AprovacaoComValidacaoTest.php` — FOUND (13 testes, verde)
- Commit `f4fe288d` — FOUND em `git log`
- Commit `5bcec609` — FOUND em `git log`
- Commit `43a915d8` — FOUND em `git log`
- `php artisan route:list --name=mlb.anuncios.criativo` → 11 rotas (mesmo número de antes) — CONFIRMADO
- `git diff --stat` nos 4 arquivos travados pela coordenação com a Fase 165 + `Publicador/`/`PubProduto*`/`MlbPublicador*`/JSX do Publicador + `AnunciarML.jsx` + `composer.json/lock`/`package.json/lock` — TODOS VAZIOS

## Next Phase Readiness

- O Plano 03 (regeneração automática) pode despachar `GerarCriativoIaJob::dispatch()` a partir de qualquer lugar sem colidir com o lock do `ValidarCriativoIaJob` — as chaves de unicidade são deliberadamente diferentes.
- `MlAnuncioCriativoKit::reprovadas()`/`validacao_status` já existem e estão testados — o Plano 03 pode consumi-los diretamente para decidir quando regenerar automaticamente.
- Nenhum bloqueio conhecido. A trava de coordenação com a Fase 165 continua válida: `git diff --stat` nos 4 arquivos proibidos permanece vazio após as 3 tasks.

---
*Phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e*
*Plan: 02*
*Completed: 2026-10-05*
