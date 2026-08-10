---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 04
subsystem: api
tags: [laravel, php, mercadolivre, scroll-pagination, upsert, queue-job, phase134]

# Dependency graph
requires:
  - phase: 134-02
    provides: "Schema ml_acervo_itens/ml_acervo_metricas_diarias + models MlAcervoItem/MlAcervoMetricaDiaria com as constantes de domínio e helpers de 'não avaliado'"
  - phase: 134-03
    provides: "AnuncioSaudeService::avaliar()/triagem() — nota ECF base 86 e motivos/severidade, consumidos por item no multiget"
provides:
  - "MlAcervoService::enumerarIds() — varredura do acervo inteiro por scroll_id (D-20), nunca offset"
  - "MlAcervoService::coletarCamadaBarata() — multiget de 20 ids, nota/triagem, selo de origem (D-04), upsert da linha corrente e série diária"
  - "SyncMlAcervoCompanyJob — job da camada barata por empresa, ShouldBeUnique com lock > timeout+backoff"
  - "COLUNAS_CAMADA_BARATA — fronteira explícita entre as duas camadas, provada por teste de preservação"
affects: [134-05, 134-06, 134-07, 134-08, 134-09, 134-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Generator recursivo para scroll com reinício único em expiração de TTL (yield from dentro do próprio método privado)"
    - "Upsert em lote com 3º argumento literal — a lista de colunas por extenso é a própria documentação da fronteira entre camadas"
    - "Mapas (rascunho/publicação/buybox) carregados 1x por empresa antes do laço de lotes, nunca item a item"
    - "Http::fake por CLOSURE (não Http::sequence) quando o mesmo teste roda o fluxo completo mais de uma vez — sequence finita estoura com OutOfBoundsException na 2ª chamada"

key-files:
  created:
    - app/Services/Mlb/Acervo/MlAcervoService.php
    - app/Jobs/SyncMlAcervoCompanyJob.php
    - tests/Unit/Phase134/ColetaAcervoTest.php
  modified: []

key-decisions:
  - "health_ml entra em COLUNAS_CAMADA_BARATA (fora do texto original do PLAN.md, incluído pelo veredicto D-21 repassado na execução) — vem de graça no multiget, custo zero, então é desta camada; performance_score/performance_level/performance_acoes ficam de fora (camada cara, 134-05)."
  - "gravarSerieDiaria() distingue 'já existe linha hoje' (sempre atualiza, reexecução no mesmo dia) de 'última linha é de dia anterior' (só cria linha nova se sold_quantity/nota_ecf/health_ml mudou) — evita que uma 2ª execução no mesmo dia seja bloqueada pelo próprio gate de mudança."
  - "resolverOrigem() consulta Publicacao via MlbEmpresa.company_id (Publicacao não tem company_id direto) — mesmo padrão já usado em MlbAnuncioController (MlbEmpresa::where('company_id', ...)->value('id') seguido de Publicacao::where('mlb_empresa_id', ...))."
  - "Testes usam Http::fake() por CLOSURE (não Http::sequence()) para o endpoint de scroll nos casos que rodam coletarCamadaBarata() mais de uma vez — uma sequence de tamanho fixo se esgota e lança OutOfBoundsException na 2ª chamada; a closure decide a resposta olhando se a querystring já leva scroll_id."

requirements-completed: [D-01, D-03, D-04, D-07, D-09, D-11, D-12, D-17, D-18, D-19, D-20, D-23]

# Metrics
duration: ~30min
completed: 2026-08-10
---

# Phase 134 Plan 04: Coleta da camada barata — scroll + multiget + upsert Summary

**`MlAcervoService` varre o acervo inteiro por `scroll_id` (nunca offset), busca 20 itens por chamada no multiget, calcula nota/triagem e selo de origem, e faz upsert com uma lista de colunas literal que blinda os campos da camada cara contra sobrescrita diária.**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-08-10T20:00Z (aprox., logo após o 134-03)
- **Completed:** 2026-08-10T20:28Z
- **Tasks:** 3/3
- **Files modified:** 3 (todos criados)

## Accomplishments

- `MlAcervoService::enumerarIds()` — `\Generator` que varre o acervo inteiro via `GET /users/{id}/items/search?search_type=scan`, nunca `offset` (D-20); reinicia o scroll uma única vez se o `scroll_id` expirar no meio (TTL de 5min), propagando a exceção na segunda falha. Prova empírica: 3 páginas reais (`scroll-pagina-1/2/3.json`, 150 ids) devolvidas sem repetição, e uma varredura sintética de 1.250 ids (acima do penhasco real de ~1000-1100 do `offset`) enumerada por completo.
- `buscarLotes()` — multiget de até 20 ids (`config('mlb_acervo.lote_multiget')`), fail-open por item (`code != 200` é pulado com log, nunca aborta o lote).
- `coletarCamadaBarata()` termina o fluxo: para cada item, resolve `AnuncioSaudeService::avaliar()/triagem()` (categoria cacheada por `MlCatalogoMetaService`), resolve o selo de origem (D-04: rascunho ECF > `Publicacao.mlb_code` do time > legado — `Publicacao::considerado()` **não** entra, confirmado por teste com `desconsiderado=true` ainda aparecendo), e faz upsert em lote com `COLUNAS_CAMADA_BARATA` como 3º argumento **literal**, nunca omitido.
- `health_ml` (D-21, veredicto DISPONÍVEL repassado nesta execução) entra em `COLUNAS_CAMADA_BARATA` e é gravado a partir do campo `health` do multiget, sem inventar valor quando vem `null`. `performance_score`/`performance_level`/`performance_acoes` ficam de fora — são camada cara (134-05).
- Série diária (`ml_acervo_metricas_diarias`) só grava linha nova quando `sold_quantity`/`nota_ecf`/`health_ml` (ESTADO) mudam desde a última linha conhecida; `visitas` (FLUXO, campo da camada cara) nunca é escrito aqui. Comentário no método cita ESTADO/FLUXO e a conta dos ~45 milhões de linhas em regime.
- `SyncMlAcervoCompanyJob` — `ShouldQueue, ShouldBeUnique`, `timeout=1800`, `uniqueFor=3600` (> timeout + backoff máximo de 900), `backoff=[60,300,900]`, `failed()` grava `coleta_erro` nas linhas já existentes da empresa.
- `tests/Unit/Phase134/ColetaAcervoTest.php` — 7 testes, todos verdes: scroll sem offset, conta >1000 itens, variação vira 1 linha com agregado do item-pai (D-17), selo de origem nos 3 casos, série diária só cresce quando algo muda, zero write na API do ML (D-11), e o teste crítico da fase: **a camada barata preserva `buybox_status`/`visitas_30d`/`detalhe_coletado_em` ao longo de 2 execuções**, mesmo enquanto atualiza de fato `sold_quantity`/`coletado_em`.

## Gates provados manualmente (quebrar → falhar → reverter)

1. **Teste 1** (`search_type=scan`): trocado temporariamente para `'offsetlike'` → teste caiu na asserção `assertSame('scan', ...)`. Revertido, suíte verde.
2. **Teste 6** (D-11 zero write): injetado um `Http::post(...)` temporário dentro de `buscarLotes()` → teste caiu com `-'GET' +'POST'`. Revertido, suíte verde.
3. **Teste 7** (preservação da camada cara): **duas variações testadas**, ver "Achado" abaixo.

### Achado durante a prova do Teste 7

A prova pedida pelo plano tinha duas partes: "(a) omitir o 3º argumento do `upsert()`" e "(b) acrescentar `buybox_status`/`visitas_30d`/`detalhe_coletado_em` a `COLUNAS_CAMADA_BARATA`". Testei as duas separadamente:

- **(a) Omitir o 3º argumento, mantendo o array de valores como está** (sem as 3 colunas) → **o teste continuou verde**. Isso é esperado e correto: o Laravel calcula o `$update` implícito como `array_keys($linhas[0])` quando o argumento é omitido — e como as 3 colunas nunca entram no array de valores (por desenho, conforme o próprio texto do plano: "a ausência delas no array é o que garante que a camada barata não as toque"), não há nada para "todas as chaves" alcançarem. A proteção real tem **duas camadas independentes**: ausência no array de valores E lista explícita de update — a omissão isolada do argumento não basta para reproduzir o estrago quando a primeira camada segue intacta.
- **(b) Acrescentar as 3 colunas a `COLUNAS_CAMADA_BARATA` (3º argumento presente, mas agora incluindo as proibidas), sem tocar no array de valores** → **o teste caiu** (`buybox_status` virou `null`, esperado `'winning'`). Confirmado: no SQLite (driver de teste), `ON CONFLICT ... DO UPDATE SET buybox_status = excluded.buybox_status` para uma coluna ausente do INSERT zera o valor em vez de falhar — reproduz exatamente o "estrago silencioso" que o plano descreve. Revertido, suíte verde.

Registro para quem tocar neste arquivo depois: a defesa real contra o T-134-26 é a **combinação** das duas coisas — nunca colocar as 3 colunas no array de valores **e** nunca colocá-las no 3º argumento. Cada uma sozinha já ajuda; juntas é o que o teste 7 efetivamente cobre (o cenário (b), mais perigoso porque não exige esquecer o argumento inteiro, só "simplificar" a constante, é o que o teste captura na prática).

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: MlAcervoService — varredura por scroll_id e multiget de 20 ids** - `a284bb97` (feat)
2. **Task 2: Persistência — upsert da linha corrente, selo de origem e série diária** - `c026bced` (feat)
3. **Task 3: Job por empresa + gates de scroll, variações, origem, zero-write e preservação da camada cara** - `92699e0f` (test)

**Plan metadata:** commit a fazer nesta execução (docs: complete plan).

## Files Created/Modified

- `app/Services/Mlb/Acervo/MlAcervoService.php` - varredura por scroll, multiget, nota/triagem/origem, upsert da linha corrente + série diária
- `app/Jobs/SyncMlAcervoCompanyJob.php` - job da camada barata por empresa, na fila
- `tests/Unit/Phase134/ColetaAcervoTest.php` - 7 testes cobrindo D-20, D-17, D-04, D-11 e D-23/D-18

## Decisions Made

- **`health_ml` incluído em `COLUNAS_CAMADA_BARATA`** — ajuste repassado nesta execução pelo veredicto D-21 (não estava no texto original do `134-04-PLAN.md`, que foi escrito antes da sondagem do 134-01 confirmar que o campo `health` do multiget vem de graça). `performance_score`/`performance_level`/`performance_acoes` permanecem fora — são da camada cara.
- **`gravarSerieDiaria()` distingue "já existe linha hoje" de "última linha é de dia anterior"** — reexecuções no mesmo dia sempre atualizam a linha do dia (nunca ficam presas pelo próprio gate de "só grava se mudou"), e o gate de mudança só se aplica na fronteira entre dias.
- **Resolução de `Publicacao` por empresa passa por `MlbEmpresa`** — `Publicacao` não tem `company_id` direto (só `mlb_empresa_id`); segui o padrão já em produção em `MlbAnuncioController` (`MlbEmpresa::where('company_id', ...)->value('id')` → `Publicacao::where('mlb_empresa_id', ...)`).
- **Testes que rodam `coletarCamadaBarata()` mais de uma vez usam `Http::fake()` por closure para o scroll, não `Http::sequence()`** — uma sequence de tamanho fixo se esgota na 2ª chamada e lança `OutOfBoundsException`; a closure decide com base na presença de `scroll_id` na querystring, sobrevivendo a N execuções.
- **`setUp()` não registra `Http::fake()` vazio** — um fake "pega tudo" registrado primeiro seria avaliado antes de qualquer pattern mais específico declarado depois no mesmo teste (a fila de stubs do Laravel resolve por ordem de registro, não por especificidade). Cada teste monta o próprio `Http::fake([...])` completo.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Guard explícito para empresa sem MlToken em `enumerarIds()`**
- **Found during:** Task 1
- **Issue:** Acessar `$company->mlToken->ml_user_id` sem token cadastrado dispararia um erro fatal de "propriedade em null" em vez de uma exceção tratável.
- **Fix:** Guard `if (! $token) throw new \RuntimeException(...)` antes de montar a URL do scroll.
- **Files modified:** `app/Services/Mlb/Acervo/MlAcervoService.php`
- **Verification:** Não quebra nenhum teste existente; é o mesmo padrão de erro (`\RuntimeException`) já usado em `MercadoLivreService`.
- **Committed in:** `a284bb97` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 2 — funcionalidade crítica ausente)
**Impact on plan:** Adição pequena e consistente com o padrão de erro já usado no restante do módulo. Nenhum scope creep.

## Issues Encountered

- `Http::fake()` chamado sem argumentos no `setUp()` (para "garantir que nada real seja chamado") derrubava silenciosamente qualquer `Http::fake([...])` mais específico declarado depois no mesmo teste — a fila de stubs do Laravel resolve por ORDEM de registro, não por especificidade de pattern. Resolvido removendo o fake vazio do `setUp()` e deixando cada teste montar o próprio fake completo.
- Uma primeira versão de `fakeColetaPadrao()` usava `Http::sequence()` para o endpoint de scroll — funcionava para testes que chamam `coletarCamadaBarata()` uma vez, mas o Teste 7 (2 execuções) esgotava a sequence na 2ª chamada e lançava `OutOfBoundsException`. Resolvido trocando para uma closure que decide a resposta pela presença de `scroll_id` na querystring, sobrevivendo a qualquer número de execuções.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano.

## Next Phase Readiness

- `MlAcervoService::coletarCamadaBarata()` e `SyncMlAcervoCompanyJob` prontos para o comando de fan-out do plano 134-07 despachar por empresa (delay escalonado, mesmo padrão de `SyncMlData`).
- `COLUNAS_CAMADA_BARATA` documenta explicitamente a fronteira que o plano 134-05 (camada cara) precisa respeitar na volta: gravar `buybox_status`/`visitas_30d`/`performance_score`/`performance_level`/`performance_acoes`/`detalhe_coletado_em` sem tocar nas colunas desta lista.
- O mapa `buyboxPorMlItemId` já é lido (leitura estrita) dentro de `coletarCamadaBarata()`, pronto para o dia em que a camada cara começar a popular `buybox_status` — a triagem já vai refletir "perdendo catálogo" sem nenhuma mudança nesta camada.
- Nenhum bloqueio conhecido para os próximos planos da fase. O achado sobre a fragilidade do teste de "omitir só o argumento" (documentado acima) vale a pena revisitar no 134-05, que terá a mesma responsabilidade simétrica (nunca tocar nas colunas da camada barata).

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-10*

## Self-Check: PASSED

- FOUND: app/Services/Mlb/Acervo/MlAcervoService.php
- FOUND: app/Jobs/SyncMlAcervoCompanyJob.php
- FOUND: tests/Unit/Phase134/ColetaAcervoTest.php
- FOUND: .planning/phases/134-meus-anuncios-saude-analitica-do-anuncio-publicado/134-04-SUMMARY.md
- FOUND commit: a284bb97
- FOUND commit: c026bced
- FOUND commit: 92699e0f
