---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 05
subsystem: api
tags: [laravel, php, mercadolivre, rotation, queue-job, phase134]

# Dependency graph
requires:
  - phase: 134-02
    provides: "Schema ml_acervo_itens/ml_acervo_metricas_diarias + helpers naoAvaliadoBuyBox()/saudeMlNaoSeAplica()/saudeMlNaoAvaliada()"
  - phase: 134-03
    provides: "AnuncioSaudeService::triagem() — motivos/severidade a partir de status/available_quantity/buyboxStatus"
  - phase: 134-04
    provides: "COLUNAS_CAMADA_BARATA e o precedente de fronteira de escrita entre camadas (T-134-26), agora espelhado na direção inversa"
provides:
  - "MlAcervoDetalheService::selecionarFatia() — rotação 1/N do acervo ativo, sem cursor persistido (D-23)"
  - "MlAcervoDetalheService::coletarDetalhe() — visitas (janela 30d), buy box (price_to_win) e performance do ML (D-21), fail-open por item"
  - "SyncMlAcervoDetalheJob — job de lote curto (ShouldBeUnique por lote, não por empresa)"
  - "COLUNAS_CAMADA_CARA (8 colunas, incluindo as 3 de performance) — fronteira provada por teste nos dois sentidos"
affects: [134-06, 134-07, 134-08, 134-09, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Rotação sem tabela de controle — o próprio timestamp de frescor (detalhe_coletado_em) é o cursor; item que falha volta ao topo sozinho"
    - "update() nomeado com array literal inline (não variável intermediária) para manter os gates de fonte por sed/grep auditáveis"
    - "Pseudo-payload montado das colunas já persistidas para rechamar uma função de domínio pura (triagem()) sem reabrir o payload bruto do ML"
    - "Resolver 'linha de hoje' pelo getter com cast (comparação em memória) em vez de where()/updateOrCreate() com igualdade direta contra coluna 'date' — evita o mismatch de formato de armazenamento do Eloquent"

key-files:
  created:
    - app/Services/Mlb/Acervo/MlAcervoDetalheService.php
    - app/Jobs/SyncMlAcervoDetalheJob.php
    - tests/Unit/Phase134/RotacaoDetalheTest.php
  modified: []

key-decisions:
  - "COLUNAS_CAMADA_CARA tem 8 colunas, não 5 como o texto original do 134-05-PLAN.md previa — performance_score/performance_level/performance_acoes entraram por instrução explícita do orchestrator (veredicto D-21 repassado após o plano ter sido escrito), a mesma sondagem que já havia feito health_ml entrar em COLUNAS_CAMADA_BARATA no 134-04."
  - "GET /item/{id}/performance só é chamado quando MlAcervoItem::saudeMlNaoSeAplica() é falso (não catálogo, não encerrado) — chamada cara nunca desperdiçada onde o ML não pontua (achado da sondagem 134-01)."
  - "performance_acoes grava só as variables com status=PENDING (key + title), com title preservado exatamente como veio do ML (já em pt-BR) — nunca reescrito, alimenta a triagem do D-09 numa fase futura."
  - "Falha em qualquer chamada HTTP de um item (visits, price_to_win OU performance) aborta a escrita do item INTEIRO — nenhuma coluna da camada cara é tocada, nem as que já tinham sido obtidas com sucesso antes da falha. Escrita pela metade seria um estado mais difícil de diagnosticar do que repetir o item na próxima fatia."
  - "SyncMlAcervoDetalheJob é ShouldBeUnique por LOTE (companyId + hash dos ids), não por empresa — ao contrário de SyncMlAcervoCompanyJob (134-04), múltiplos lotes da mesma empresa podem rodar em paralelo; só o mesmo lote não pode duplicar."

requirements-completed: [D-08, D-11, D-18, D-19, D-21, D-23]

# Metrics
duration: ~21min
completed: 2026-08-10
---

# Phase 134 Plan 05: Camada cara — visitas, buy box e performance por fatia de rotação Summary

**`MlAcervoDetalheService` cobre 100% do acervo ativo em N execuções sem cursor persistido, coletando visitas (janela 30d), buy box (`price_to_win`) e a saúde do ML (`performance`, D-21) só onde o ML de fato pontua, com escrita nomeada de 8 colunas que nunca invade o que a camada barata gravou.**

## Performance

- **Duration:** ~21 min
- **Started:** 2026-08-10T17:34:15-03:00 (logo após o 134-04)
- **Completed:** 2026-08-10T17:55:36-03:00
- **Tasks:** 3/3
- **Files modified:** 3 (todos criados)

## Accomplishments

- `MlAcervoDetalheService::selecionarFatia()` — 1/N do acervo com `status=active` da empresa, ordenação portável entre MariaDB e SQLite (`orderByRaw('detalhe_coletado_em IS NULL DESC')` + `orderBy('detalhe_coletado_em')` + `orderBy('ml_item_id')`), sem nenhuma tabela de controle de rotação — o próprio `detalhe_coletado_em` é o cursor.
- `coletarDetalhe()` — para cada item da fatia: visitas dos últimos 30 dias (`GET /items/{id}/visits`, somando `visits_detail[].quantity`), buy box só para item de catálogo (`GET /items/{id}/price_to_win?version=v2`, validando contra os 4 valores documentados — T-134-14), e performance do ML só onde `! saudeMlNaoSeAplica()` (`GET /item/{id}/performance`, D-21) — item de catálogo ou encerrado nunca gera essa chamada cara.
- `performance_acoes` grava só as `variables[]` com `status=PENDING` (extraídas de `buckets[].variables[]`), preservando `key` e `title` exatamente como vieram do ML (já redigido em pt-BR) — sem reescrever.
- `triagem()` recomputada a partir de um pseudo-payload montado das colunas **já persistidas** (`status`, `available_quantity`, `catalog_listing`) — nunca de um array vazio, o mesmo risco do T-134-26 na direção inversa do 134-04.
- Escrita nomeada com exatamente as 8 colunas de `COLUNAS_CAMADA_CARA` (`visitas_30d`, `buybox_status`, `performance_score`, `performance_level`, `performance_acoes`, `motivos`, `severidade`, `detalhe_coletado_em`) — nunca `upsert()`, nunca `save()` de model inteiro.
- Fail-open por item: qualquer exceção (visits, price_to_win ou performance) aborta a escrita do item inteiro dentro do lote — nenhuma coluna da camada cara é tocada, o item permanece "não avaliado" e volta ao topo da fila na próxima execução (auto-corretivo, sem cursor a corrigir).
- `SyncMlAcervoDetalheJob` — `ShouldQueue, ShouldBeUnique` por **lote** (`companyId` + hash md5 dos ids, não só `companyId`), `timeout=900`, `uniqueFor=1800` (> timeout + backoff máximo de 600), `backoff=[120,600]`, `failed()` não marca nada como coletado.
- `tests/Unit/Phase134/RotacaoDetalheTest.php` — 8 testes, todos verdes: cobertura completa em N execuções sem repetição prematura (D-23), priorização de nulos/mais-antigos, buy box não avaliado nunca vira motivo (D-18), falha não avança frescor nem inventa status (D-08), zero write na API do ML (D-11), preservação da camada barata + pseudo-payload não-vazio (T-134-26), performance não chamado onde não se aplica, e `performance_acoes` só com `PENDING` + `title` preservado.

## Gates provados manualmente (quebrar → falhar → reverter)

1. **Teste 1** (cobertura em N execuções): removida toda a ordenação de `selecionarFatia()` (`orderByRaw` + 2× `orderBy`) → mesmos 10 primeiros ids (por rowid) selecionados repetidamente em todas as 7 execuções, 60 itens nunca cobertos → `assertSame(0, $naoCobertos)` falhou (`60 !== 0`). Revertido, suíte volta a verde.
2. **Teste 3** (D-18, buy box não avaliado nunca vira motivo): `AnuncioSaudeService::triagem()` alterado temporariamente para `if ($buyboxStatus === null || in_array(...))` → o teste, que chama `triagem()` diretamente com `buyboxStatus=null`, caiu (`assertNotContains` falhou, `perdendo_catalogo` presente). Revertido.
3. **Teste 6** (T-134-26, não apagar a camada barata) — **duas variações testadas**:
   - (a) acrescentado `'title' => 'PROVA-TEMPORARIA-...'` ao array do `update()` → teste caiu (`title` sobrescrito). Revertido.
   - (b) `$pseudoPayload` trocado para `[]` (array vazio) antes de chamar `triagem()` → `MOTIVO_SEM_ESTOQUE` sumiu de `motivos` → teste caiu (`assertContains` falhou). Revertido.

Suíte completa (`--filter=Phase134`) verde após cada reversão: 31 testes, 253 assertions.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: MlAcervoDetalheService — seleção da fatia e coleta de visitas/buy box/performance** - `47632366` (feat)
2. **Task 2: SyncMlAcervoDetalheJob — job de lote curto e previsível** - `af3fa6d3` (feat)
3. **Task 3: Gates de cobertura, "não avaliado", fronteira de escrita e performance** - `7357fa52` (test) — inclui o fix de `gravarVisitasSerieDiaria()` descrito abaixo

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `app/Services/Mlb/Acervo/MlAcervoDetalheService.php` - rotação por fatia, coleta de visitas/buy box/performance, escrita nomeada de 8 colunas
- `app/Jobs/SyncMlAcervoDetalheJob.php` - job de lote curto da camada cara, único por lote
- `tests/Unit/Phase134/RotacaoDetalheTest.php` - 8 testes cobrindo D-23, D-18, D-08, D-11, T-134-26 e D-21

## Decisions Made

- **`COLUNAS_CAMADA_CARA` com 8 colunas (não 5)** — o ajuste do veredicto D-21, repassado pelo orchestrator depois que o `134-05-PLAN.md` foi escrito, trouxe a coleta de `performance_score`/`performance_level`/`performance_acoes` para este plano. A constante e a escrita nomeada foram dimensionadas para as 8 desde o início desta execução — não é um desvio, é a especificação efetivamente recebida.
- **`GET /item/{id}/performance` gated por `saudeMlNaoSeAplica()`** — reusa o helper do model (criado no 134-02) em vez de reimplementar a checagem `catalog_listing || status in (closed, inactive)`.
- **Falha aborta o item inteiro, não campo a campo** — se `visits` tiver sucesso mas `price_to_win` falhar, `visitas_30d` também NÃO é gravado. Escrita parcial (visitas atualizadas, buy box desatualizado) seria um estado mais difícil de diagnosticar do que simplesmente repetir o item inteiro na próxima fatia.
- **`ShouldBeUnique` por lote, não por empresa** — ao contrário do job da camada barata (134-04), a camada cara despacha vários lotes pequenos por empresa (fan-out do 134-07); a chave de unicidade precisa distinguir lotes, senão o segundo lote da mesma empresa seria bloqueado pelo primeiro.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `gravarVisitasSerieDiaria()` original (baseada no padrão do 134-04) nunca encontrava a linha do dia corrente**
- **Found during:** Task 3, ao rodar o teste `camada_cara_nao_apaga_dados_da_camada_barata` pela primeira vez.
- **Issue:** `MlAcervoMetricaDiaria.data` tem cast `'date'`. O setter do Eloquent (`fromDateTime()`) grava a coluna usando o formato COMPLETO do grammar (`Y-m-d H:i:s`, ex. `2026-08-10 00:00:00`) mesmo para cast `date` — só o getter trunca para `Y-m-d` na leitura. A implementação original usava `MlAcervoMetricaDiaria::updateOrCreate(['data' => $hoje, ...], [...])` com `$hoje` no formato `Y-m-d` (sem hora) — o `where($attributes)` interno do `firstOrNew()` faz comparação SQL crua, `'2026-08-10' = '2026-08-10 00:00:00'` nunca bate, e cada chamada tentava um INSERT novo, colidindo com o `UNIQUE (company_id, ml_item_id, data)`.
- **Fix:** `gravarVisitasSerieDiaria()` resolve "existe linha hoje?" reaproveitando o getter com cast (`$ultima->data->toDateString() === $hoje`, comparação em memória de objetos `Carbon`) em vez de repetir a igualdade crua contra a coluna, e faz `update()`/`create()` explícito em vez de `updateOrCreate()`.
- **Files modified:** `app/Services/Mlb/Acervo/MlAcervoDetalheService.php`
- **Verification:** teste 6 (`camada_cara_nao_apaga_dados_da_camada_barata`) passa e prova a série do dia ganhando `visitas` sem perder `sold_quantity`/`nota_ecf` da camada barata.
- **Committed in:** `7357fa52` (Task 3 commit)

**Achado correlato, fora do escopo desta fix (documentado, não corrigido):** o mesmo padrão (`updateOrCreate` com igualdade direta em `data`) existe em `MlAcervoService::gravarSerieDiaria()` (134-04) e sofre do MESMO bug — mascarado em silêncio pelo `try/catch` fail-open por item de `processarLote()`, que só incrementa `$falhas` e loga um `Log::warning()`. Confirmado empiricamente: 240 ocorrências do erro `UNIQUE constraint failed: ml_acervo_metricas_diarias...` acumuladas em `storage/logs/laravel.log` só de execuções passadas da suíte de testes deste projeto. O teste `serie_diaria_so_grava_quando_algo_muda` (`ColetaAcervoTest.php`) só verifica CONTAGEM de linhas, o que passa "pelo motivo errado" quando o INSERT falha silenciosamente sem alterar nada. **Impacto em produção:** a série diária da camada barata provavelmente não é atualizada numa reexecução no MESMO dia (ex.: botão "Atualizar agora" 2x no mesmo dia). Documentado em `.planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/deferred-items.md` — fora do escopo deste plano corrigir `MlAcervoService.php` (arquivo não está em `files_modified` do 134-05, bug pré-existente do 134-04, não causado por esta execução).

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug de comparação de data, isolado ao arquivo desta fase) + 1 achado correlato documentado sem correção (fora de escopo)
**Impact on plan:** O fix foi necessário para o próprio teste de preservação (T-134-26) desta fase funcionar corretamente — sem ele, a série diária da camada cara nunca teria sido gravada de fato, e o gate "obrigatório" do plano teria passado sem realmente exercitar a escrita. Nenhum scope creep: o arquivo irmão com o mesmo bug foi apenas documentado.

## Issues Encountered

- `MlToken.expires_at` semeado com `now()->addDays(6)` (mesmo padrão de `ColetaAcervoTest`) expirava no meio do teste de rotação em 7 execuções, que avança `Carbon::setTestNow()` em 1 dia por execução — o token "vencia" na 7ª rodada e `ensureValidToken()` tentava um refresh real (sem fake), lançando `RuntimeException`. Resolvido semeando `now()->addYear()` só em `RotacaoDetalheTest::criarFixture()` (teste que de fato avança o relógio); `ColetaAcervoTest` não precisa do ajuste porque não simula múltiplos dias com `Carbon::setTestNow()`.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano.

## Next Phase Readiness

- `MlAcervoDetalheService::coletarDetalhe()` e `SyncMlAcervoDetalheJob` prontos para o fan-out do 134-07 despachar lotes de `mlb_acervo.chunk_detalhe` ids por empresa, com `selecionarFatia($company, config('mlb_acervo.rotacao_n'))` decidindo o corte antes do enfileiramento (o comando artisan que lê o `--n=` e faz o corte em lotes ainda não existe — é o próprio 134-07).
- `performance_score`/`performance_level`/`performance_acoes` persistidos e prontos para a tela (134-08/134-10) exibir a saúde do ML lado a lado com a nota ECF (D-10), incluindo o caso "não se aplica" (catálogo/encerrado) via `MlAcervoItem::saudeMlNaoSeAplica()`.
- **Bug pré-existente encontrado em `MlAcervoService::gravarSerieDiaria()` (134-04)** — documentado em `deferred-items.md`, não corrigido (fora de escopo). Recomendado que quem tocar nesse método aplique o mesmo fix desta execução (resolver "linha de hoje" pelo getter com cast em vez de `where`/`updateOrCreate` com igualdade direta).
- Nenhum outro bloqueio conhecido para os próximos planos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: app/Services/Mlb/Acervo/MlAcervoDetalheService.php
- FOUND: app/Jobs/SyncMlAcervoDetalheJob.php
- FOUND: tests/Unit/Phase134/RotacaoDetalheTest.php
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/deferred-items.md
- FOUND commit: 47632366
- FOUND commit: af3fa6d3
- FOUND commit: 7357fa52
