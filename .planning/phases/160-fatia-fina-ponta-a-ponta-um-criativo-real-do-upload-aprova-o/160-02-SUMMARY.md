---
phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o
plan: 02
subsystem: ml-publicador
tags: [laravel, inertia, react, creative-engine, gemini, queue, prompt-engineering]

requires:
  - phase: 160-01
    provides: "ml_anuncio_criativos, MlAnuncioCriativo (estados/LIMITE_MINUTOS), CreativeEngineAtivo, ReferenciaEfemeraService, PainelCriativosIa.jsx (espaço reservado)"
provides:
  - "CreativeContextBuilder — única fronteira entre o publicador (ml_anuncio_rascunhos.payload) e o Creative Engine (CTX-02)"
  - "ProductTruthBuilder — Product Truth com contagem só do cadastro (TRUTH-01/02/03) e claims proibidas nunca vazias (TRUTH-04)"
  - "CreativePromptBuilder — prompt MASTER + SLOT HERO, sanitizado, nunca lê o payload cru"
  - "GerarCriativoIaJob — geração assíncrona na fila `high`, ShouldBeUnique por rascunho (GEN-02/06), trava de 12min"
  - "Endpoints mlb.anuncios.criativo.gerar/status/imagem — 202 antes de gerar (GEN-01), whitelist de status (T-160-10)"
  - "PainelCriativosIa.jsx completo: botão Gerar, polling, lado a lado fotos×gerado (APROV-01)"
affects: ["160-03", "160-04"]

tech-stack:
  added: []
  patterns:
    - "Fronteira única de leitura de payload (CreativeContextBuilder) — nenhum outro arquivo de Creative lê ml_anuncio_rascunhos.payload"
    - "Contagem de peça só por id de atributo em lista fechada — nunca regex sobre texto livre (TRUTH-02)"
    - "Job ShouldBeUnique com onQueue('high') dentro do construtor (molde GerarAnaliseAnuncioIaJob/PublicarAnuncioMlJob)"

key-files:
  created:
    - app/Services/Creative/Dto/CreativeContext.php
    - app/Services/Creative/Dto/ProductTruth.php
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Services/Creative/ProductTruthBuilder.php
    - app/Services/Creative/CreativePromptBuilder.php
    - app/Jobs/GerarCriativoIaJob.php
    - tests/Unit/Phase160/ProductTruthBuilderTest.php
    - tests/Feature/Phase160/CriativoGeracaoTest.php
    - tests/Feature/Phase160/CriativoGeracaoEndpointsTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx

key-decisions:
  - "Contagem de peça usa lista FECHADA de ids de atributo (*_QUANTITY, QUANTITY_*, NUMBER_OF_*, PIECES_NUMBER, DRAWERS_NUMBER, DOORS_NUMBER) — nunca regex sobre título/descrição. É a prova de TRUTH-02, reforçada pelo teste do título '2 portas 3 gavetas' sem atributo de contagem."
  - "Rótulo legível de atributo (TRUTH-01) é resolvido por um pequeno dicionário pt-BR + fallback de humanização do id (snake→título), sem chamar MlCatalogoMetaService — mantém o builder síncrono e sem HTTP, e evita acoplar o Creative Engine a outra camada."
  - "Idempotência de disparo (GEN-06) usa status===RODANDO no controller + ShouldBeUnique no job (chave rascunho_id) — NÃO usa MlAnuncioCriativo::emAndamento(), que inclui 'pendente' (ver Deviations)."

requirements-completed: [CTX-01, CTX-02, CTX-03, TRUTH-01, TRUTH-02, TRUTH-03, TRUTH-04, GEN-01, GEN-02, GEN-05, GEN-06, APROV-01, APROV-06]

duration: ~90min
completed: 2026-10-02
---

# Phase 160 Plan 02: Segunda fatia — geração real com Gemini Summary

**Contexto/Product Truth/prompt com contagem de peça travada ao cadastro, `GerarCriativoIaJob` na fila `high` com unicidade e trava de 12min, e o painel mostrando a imagem gerada ao lado das fotos originais.**

## Performance

- **Duration:** ~90 min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 12 (9 criados, 3 modificados)

## Accomplishments

- `CreativeContextBuilder` é a única fronteira que lê `ml_anuncio_rascunhos.payload` no Creative Engine — confirmado por grep (`grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder` → 0).
- `ProductTruthBuilder` prova TRUTH-02 no teste mais importante do plano: título "Gabinete de cozinha 2 portas 3 gavetas" sem atributo de contagem produz `contagens=[]` — nenhum número é minerado de texto livre.
- `claims_proibidas` nunca sai vazio: 8 claims fixas + proibição derivada quando falta cor ou contagem confirmada (TRUTH-04).
- `CreativePromptBuilder` monta MASTER + SLOT HERO só a partir do `ProductTruth` (nunca do `CreativeContext` cru) — TRUTH-03 garantido pela própria assinatura do método, sanitizando quebras de linha e caracteres de controle vindos do cadastro (T-160-13).
- `GerarCriativoIaJob` roda na fila `high` (onQueue dentro do construtor), é `ShouldBeUnique` por `rascunho_id` (GEN-06), grava `contexto`/`truth`/`prompt` parcialmente a cada etapa, e `encerrarSeTravada()` impede gastar ~US$ 0,101 numa geração que ninguém mais vê.
- Três endpoints novos (`gerar`/`status`/`imagem`) devolvem 202 antes de qualquer chamada ao provedor (GEN-01), nunca expõem `prompt`/`contexto`/`truth` ao navegador (T-160-10), e o painel mostra o resultado lado a lado com as fotos originais (APROV-01).
- Bug real encontrado e corrigido em teste de aceitação: `emAndamento()` trata `pendente` (estado de repouso logo após o upload, 160-01) como "já em andamento", o que fazia o PRIMEIRO clique em "Gerar" nunca despachar o job — ver Deviations.

## Task Commits

1. **Task 1: CreativeContext, ProductTruth e os dois builders** - `a9d8373b` (feat, com teste)
2. **Task 2: CreativePromptBuilder + GerarCriativoIaJob** - `23594058` (feat, com teste)
3. **Task 3: Endpoints + painel com polling lado a lado** - `3a31f6cc` (feat, inclui fix de bug + teste)

_Tasks marcadas `tdd="true"` no plano: teste e implementação foram commitados juntos por task (não em commits RED/GREEN separados) — ver Deviations._

## Files Created/Modified

- `app/Services/Creative/Dto/CreativeContext.php` - DTO do contexto; `paraAuditoria()` nunca inclui bytes
- `app/Services/Creative/Dto/ProductTruth.php` - DTO do Product Truth; `paraPrompt()`/`paraAuditoria()`
- `app/Services/Creative/CreativeContextBuilder.php` - única fronteira que lê o payload do rascunho (CTX-02)
- `app/Services/Creative/ProductTruthBuilder.php` - fatos/contagens/claims a partir SÓ do `CreativeContext`
- `app/Services/Creative/CreativePromptBuilder.php` - prompt MASTER + SLOT HERO, sanitizado
- `app/Jobs/GerarCriativoIaJob.php` - geração assíncrona, fila `high`, `ShouldBeUnique`, trava de tempo
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoGerar`/`criativoStatus`/`criativoImagem` + `checarEscopoDoCriativo()`
- `routes/mlb_anuncios.php` - rotas `criativo.gerar` (throttle:6,1), `.status`, `.imagem`
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - botão Gerar, polling, lado a lado
- `tests/Unit/Phase160/ProductTruthBuilderTest.php` - 10 testes (TRUTH-01/02/04, CTX-03)
- `tests/Feature/Phase160/CriativoGeracaoTest.php` - 8 testes (job, prompt, fila, unicidade, trava, GEN-05)
- `tests/Feature/Phase160/CriativoGeracaoEndpointsTest.php` - 8 testes (endpoints, whitelist, 404 com chave desligada)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: lista fechada de ids para contagem (nunca regex em texto livre), rótulo legível por dicionário pt-BR sem chamada a `MlCatalogoMetaService`, e idempotência de disparo via `status===rodando` (não `emAndamento()`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Primeiro clique em "Gerar" nunca despachava o job**
- **Found during:** Task 3, escrevendo o teste de aceitação dos endpoints
- **Issue:** `MlAnuncioCriativo::STATUS_EM_ANDAMENTO = [PENDENTE, RODANDO]` (definido na 160-01). `pendente` é exatamente o status em que o criativo nasce, logo após o upload da referência — ANTES de qualquer clique em "Gerar". O controller usava `$criativo->emAndamento()` para a idempotência de GEN-06, então todo criativo recém-criado já "parecia" estar em andamento e o `GerarCriativoIaJob::dispatch()` nunca era chamado no primeiro clique — a geração simplesmente nunca começava, sem erro visível (a resposta 202 parecia sucesso).
- **Fix:** Troca da condição para `$criativo->status === MlAnuncioCriativo::STATUS_RODANDO` (só o estado "de fato processando agora" encerra antecipado). GEN-06 (clique duplo) continua garantido pelo `ShouldBeUnique` do próprio job (chave `rascunho_id`, TTL 600s) — mecanismo que já existia independente deste `if`.
- **Files modified:** `app/Http/Controllers/MlbAnuncioController.php`
- **Verification:** `CriativoGeracaoEndpointsTest::test_gerar_devolve_202_e_enfileira_na_fila_high` (reproduz o bug sem o fix) e `test_gerar_duplo_clique_nao_enfileira_duas_vezes` (prova que GEN-06 sobrevive ao fix)
- **Commit:** `3a31f6cc`

**2. [Redação] Docblocks reescritos para não conter o literal "payload"**
- **Found during:** Task 1, ao rodar o grep de verificação do próprio plano
- **Issue:** Comentários em `ProductTruthBuilder.php` e `ProductTruth.php` explicando "nunca lê o payload" continham a palavra literal "payload", fazendo `grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder` encontrar 2 ocorrências em comentário (mesmo padrão de deviation já visto no 160-01-SUMMARY.md).
- **Fix:** Reescrito para "corpo salvo do rascunho" / "corpo bruto salvo do rascunho", mantendo o mesmo sentido sem repetir o literal.
- **Files modified:** `app/Services/Creative/ProductTruthBuilder.php`, `app/Services/Creative/Dto/ProductTruth.php`
- **Verification:** grep final = 0
- **Commit:** `a9d8373b`

---

**Total deviations:** 2 (1 bug real de Rule 1, 1 ajuste de redação)
**Impact on plan:** O fix de Rule 1 é essencial — sem ele a feature inteira desta fase não funcionava na prática (o botão "Gerar" nunca gerava nada). Nenhum scope creep.

## Issues Encountered

Depuração do bug acima consumiu a maior parte do tempo da Task 3: o sintoma inicial (`Queue::assertPushed` falhando) foi investigado por eliminação — dispatch direto do job funcionou, dispatch via rota-closure sem middleware funcionou, só o endpoint real falhava — até isolar que a causa era o próprio dado de teste (`status` inicial `pendente`) expondo a leitura errada de `emAndamento()` no controller.

## User Setup Required

Nenhuma configuração nova. `GEMINI_API_KEY` já é tratada pelo spike V0.1 (260925/261001-nkx) — este plano não adiciona variável de ambiente nova.

## Next Phase Readiness

- A fatia 2 está completa ponta a ponta: upload → contexto/truth/prompt → job na fila `high` → disco → polling → painel lado a lado.
- Espaço reservado para o botão "Aprovar" (`{/* botão Aprovar entra aqui (160-03) */}`) já está no JSX — 160-03 só precisa plugar ali.
- `aprovado_em`/`aprovado_por`/`ml_picture_id`/`ml_picture_url` já existem na tabela (migration da 160-01) — 160-03 não precisa de migration nova para aprovação simples.

## Verification Results

1. `ProductTruthBuilderTest` (Unit/Phase160) — **10/10 verde**.
2. `CriativoGeracaoTest` (Feature/Phase160) — **8/8 verde**.
3. `CriativoGeracaoEndpointsTest` (Feature/Phase160) — **8/8 verde**.
4. `GeminiImageProviderTest` (spike V0.1) — **11/11 verde**, intocado.
5. `CriativoReferenciaTest` (160-01) — **9/9 verde**, sem regressão.
6. `grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder | wc -l` → **0**.
7. `npm run build` — concluído sem erro.
8. `grep -n "onQueue('high')" app/Jobs/GerarCriativoIaJob.php` casa dentro do construtor; `grep -c "public \$queue\|protected \$queue"` → **0**.
9. `php artisan route:list --name=mlb.anuncios.criativo` — **5 rotas** (referencia, referencia.ver, gerar, status, imagem).
10. Regressão adicional (fora do `<verification>` do plano, por segurança): `UploadImagemTest` + `AnuncioIaAnaliseTest` + `MeusAnunciosTest` — **44/44 verde** (controller compartilhado só recebeu adições, nenhum método existente foi alterado).

## Self-Check: PASSED

- `app/Services/Creative/Dto/CreativeContext.php` — FOUND
- `app/Services/Creative/Dto/ProductTruth.php` — FOUND
- `app/Services/Creative/CreativeContextBuilder.php` — FOUND
- `app/Services/Creative/ProductTruthBuilder.php` — FOUND
- `app/Services/Creative/CreativePromptBuilder.php` — FOUND
- `app/Jobs/GerarCriativoIaJob.php` — FOUND
- `tests/Unit/Phase160/ProductTruthBuilderTest.php` — FOUND
- `tests/Feature/Phase160/CriativoGeracaoTest.php` — FOUND
- `tests/Feature/Phase160/CriativoGeracaoEndpointsTest.php` — FOUND
- Commit `a9d8373b` — FOUND (git log)
- Commit `23594058` — FOUND (git log)
- Commit `3a31f6cc` — FOUND (git log)

---
*Phase: 160-fatia-fina-ponta-a-ponta-um-criativo-real-do-upload-aprova-o*
*Completed: 2026-10-02*
