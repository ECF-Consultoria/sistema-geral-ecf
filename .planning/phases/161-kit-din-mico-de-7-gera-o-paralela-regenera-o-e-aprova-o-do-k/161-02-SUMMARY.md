---
phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k
plan: 02
subsystem: creative-engine
tags: [laravel, inertia, react, creative-engine, mercado-livre, queue, gemini]

requires:
  - phase: 161-01
    provides: "ml_anuncio_criativo_kits, MlAnuncioCriativoKit (estados/tetos/recalcularStatus), kit_id/slot_indice/slot_plano em ml_anuncio_criativos, CreativeSlotCatalog, CreativePermissao, PlanejarKitCriativosJob (fila creative), endpoints criativo.kit.planejar/.status, painel listando os 7 slots"
  - phase: 160-02
    provides: "GerarCriativoIaJob (molde de etapas salvas parcialmente), CreativeContextBuilder (fronteira única CTX-02), CreativePromptBuilder::paraSlotHero()"
provides:
  - "CreativePromptBuilder::paraSlot() — prompt por slot do kit, com o bloco de TEXTO bifurcado por CreativeSlotCatalog::aceitaTexto() (Decisão 7)"
  - "GerarCriativoIaJob adaptado ao slot — unicidade por CRIATIVO (não mais por rascunho), fila creative (não mais high), teto de custo do kit conferido antes do provedor"
  - "CreativeContextBuilder lendo as fotos do portadorDeReferencia() — os N slots de um kit compartilham a mesma referência"
  - "CreativeKitDespachante — despacho em ondas (paralelo/intervalo_s configuráveis), ignora slot pronto/rodando/aprovado, recusa a onda inteira no teto de imagens"
  - "Endpoint mlb.anuncios.criativo.kit.gerar (POST, throttle:3,1) e criativoKitStatus estendido com etapa/erro/imagem_url/modelo/latencia_ms por slot"
  - "KitCriativosGrade.jsx — grade de 7 cartões com progresso individual, lado a lado com as fotos originais"
affects: ["161-03"]

tech-stack:
  added: []
  patterns:
    - "Unicidade de job por ENTIDADE QUE SERÁ PROCESSADA, nunca pela entidade pai que agrupa várias — a chave por rascunho fazia sentido com 1 imagem por rascunho (Fase 160) e quebrou em silêncio com 7 (Fase 161)"
    - "Despacho em ondas por delay escalonado (intdiv(posição, paralelo) * intervalo) para controlar paralelismo real ao provedor, independente de quantos workers a fila tem — molde reaproveitado de MlbAnuncioController::publicarLote"
    - "Teto de custo conferido em DUAS camadas (despachante antes de enfileirar, job antes de chamar o provedor) — nenhuma das duas depende da outra para ser a última linha de defesa"

key-files:
  created:
    - app/Services/Creative/CreativeKitDespachante.php
    - resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
    - tests/Unit/Phase161/CreativePromptBuilderSlotTest.php
    - tests/Feature/Phase161/CriativoKitGeracaoTest.php
  modified:
    - app/Services/Creative/CreativePromptBuilder.php
    - app/Services/Creative/CreativeContextBuilder.php
    - app/Jobs/GerarCriativoIaJob.php
    - app/Http/Controllers/MlbAnuncioController.php
    - routes/mlb_anuncios.php
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
    - tests/Feature/Phase160/CriativoGeracaoTest.php
    - tests/Feature/Phase160/CriativoGeracaoEndpointsTest.php

key-decisions:
  - "uniqueId() do GerarCriativoIaJob passou de 'rascunho:{id}' para 'criativo:{id}' — a chave antiga aceitava o 1º dos 7 slots do mesmo rascunho e descartava os outros 6 EM SILÊNCIO (armadilha central do plano); GEN-06 continua garantido por 3 camadas que não dependem dessa chave (idempotência do controller, despachante que só pega slot pendente/erro, e o fluxo sem kit que recusa reenviar criativo rodando)."
  - "Migração de fila de 'high' para 'creative' feita na MESMA linha do construtor que já existia — decisão operacional registrada no PLAN (worker ecf-worker-creative numprocs=3 já provisionado na VPS antes desta execução); 2 testes da Fase 160 que afirmavam a fila 'high' foram atualizados para 'creative', porque a migração é intencional e documentada, não regressão."
  - "CreativePromptBuilder::paraSlot() recebe só ProductTruth + o array de slot_plano (nunca CreativeContext nem o corpo do rascunho) — mesma limitação de assinatura de paraSlotHero() que garante TRUTH-03 por construção; o claim fixo 'Não escreva texto na imagem.' é filtrado por igualdade EXATA de literal (não substring) quando o slot aceita texto."
  - "Teste de onda (paralelo=3/intervalo_s=15) assert por diferença de timestamp (now() vs job->delay) com tolerância de 2s, em vez de igualdade exata — evita flakiness de timing sem perder a prova de que as ondas existem."
  - "Dentro de um ÚNICO teste, Http::fake() chamado duas vezes NÃO substitui o primeiro stub — o matching é por ordem de registro (1º que casa, ganha) e os registros se acumulam. O teste de falha parcial usa UM fake com contador (1ª chamada = 503, demais = sucesso) para modelar a ordem real de execução sem essa armadilha."

requirements-completed: [GEN-03, GEN-04]

duration: ~31min
completed: 2026-10-02
---

# Phase 161 Plan 02: Kit dinâmico de 7 — geração paralela Summary

**Um clique em "Gerar as 7 imagens" dispara os 7 jobs na fila `creative` em ondas controladas (paralelo=3, intervalo=15s), com unicidade por criativo (não mais por rascunho) e teto de custo conferido em duas camadas antes de qualquer chamada ao provedor.**

## Performance

- **Duration:** ~31min
- **Completed:** 2026-10-02
- **Tasks:** 3/3 completas
- **Files modified:** 13 (4 criados, 9 modificados — incluindo 2 testes da Fase 160 ajustados pela migração de fila)

## Accomplishments

- `CreativePromptBuilder::paraSlot()` prova a Decisão 7 por teste: o slot `hero` continua proibindo texto/logo/selo/marca d'água mesmo quando o plano traz badges (elas são ignoradas, não escritas), e um slot `benefits` escreve EXATAMENTE as badges do plano e perde o claim fixo "Não escreva texto na imagem." — comparação por igualdade literal exata, não substring. `paraSlotHero()` foi refatorado para métodos privados compartilhados SEM alterar uma linha da sua saída (prova: `CriativoGeracaoTest` da Fase 160, que asserta o prompt do hero, ficou verde sem modificação).
- A armadilha central do plano foi corrigida e provada por um teste com nome que a nomeia: `test_os_7_slots_do_mesmo_rascunho_nao_colapsam_em_1_job`. Antes da troca de `uniqueId()`, despachar os 7 slots do mesmo rascunho enfileirava só 1 job (os outros 6 eram descartados sem erro, sem log). Com a chave por criativo, os 7 sobrevivem — confirmado por `Queue::assertPushed(GerarCriativoIaJob::class, 7)`.
- `CreativeKitDespachante` prova as ondas por inspeção do `delay` de cada job despachado (índices 1-3 sem delay, 4-6 com +15s, 7 com +30s, com `paralelo=3`/`intervalo_s=15`), ignora slot `rodando`/`pronto`/`aprovado` (segundo clique não repaga imagem pronta) e recusa a onda inteira quando `tetoDeImagensAtingido()` — sem nenhuma chamada ao provedor nesse caso (`Queue::assertNothingPushed()`).
- `GerarCriativoIaJob` migrou de `high` para `creative` (decisão operacional do plano — worker dedicado já provisionado na VPS antes desta execução) e passou a ler as fotos de referência do `portadorDeReferencia()`: os 7 slots de um kit compartilham a MESMA foto do upload original, provado por `test_todos_os_slots_leem_as_mesmas_fotos_do_portador` rodando os 7 de ponta a ponta.
- Um slot que falha (503 em todos os modelos) não trava os outros 6: o kit termina em `parcial`, provado rodando o cenário completo (`test_um_slot_com_falha_nao_impede_os_outros_e_o_kit_termina_parcial`), incluindo a armadilha descoberta no próprio teste de que `Http::fake()` chamado duas vezes no mesmo teste NÃO substitui o fake anterior (ver Deviations).
- Endpoint `criativoKitGerar` devolve 202 antes de qualquer chamada ao provedor, é idempotente quando o kit já está `gerando` (não redespacha), recusa 422 em pt-BR em planejamento inacabado e em teto de imagens atingido, e `criativoKitStatus` ganhou `etapa`/`erro`/`imagem_url`/`modelo`/`latencia_ms` por slot — nunca `prompt`/`contexto`/`truth`/`imagem_path`.
- `KitCriativosGrade.jsx` (componente novo) mostra os 7 cartões com progresso individual, fotos originais ao lado, e o fluxo de 1 imagem da Fase 160 sai de evidência quando existe kit — sem remover o código (é o caminho de rollback) e sem nenhuma linha nova em `AnunciarML.jsx` (confirmado por `git diff --stat` vazio).

## Task Commits

1. **Task 1: CreativePromptBuilder::paraSlot — o prompt de cada um dos 7 slots** - `01229fe1` (feat, com teste)
2. **Task 2: Job por slot (unicidade por criativo) e despachante em ondas com teto de custo** - `c06267ed` (feat, com teste; inclui atualização de 2 testes pré-existentes da Fase 160)
3. **Task 3: Disparo do kit, status por asset e a grade de 7 na tela** - `7119635f` (feat, com teste)

_Tasks marcadas `tdd="true"` no plano (1 e 2): teste e implementação foram escritos e verificados juntos antes de cada commit, não em commits RED/GREEN separados — mesmo padrão das Summaries anteriores desta fase._

## Files Created/Modified

- `app/Services/Creative/CreativePromptBuilder.php` - `paraSlot()` + métodos privados compartilhados com `paraSlotHero()` (MASTER/FATOS PERMITIDOS/CONTAGENS/CLAIMS PROIBIDAS/TEXTO)
- `app/Services/Creative/CreativeContextBuilder.php` - fotos de referência lidas do `portadorDeReferencia()` (único ajuste, CTX-02 preservado)
- `app/Jobs/GerarCriativoIaJob.php` - `uniqueId()` por criativo, fila `creative`, teto de custo do kit antes do provedor, caminho de imagem por slot, `recalcularStatus()` do kit em sucesso e falha
- `app/Services/Creative/CreativeKitDespachante.php` (novo) - despacho em ondas com teto de custo (GEN-03)
- `app/Http/Controllers/MlbAnuncioController.php` - `criativoKitGerar()`, `criativoKitStatus()` estendido, injeção de `CreativeKitDespachante`
- `routes/mlb_anuncios.php` - `criativo.kit.gerar` (throttle:3,1)
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` (novo) - grade de 7 cartões, progresso por slot
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` - botão "Gerar as 7 imagens", `ETAPA_LABEL` exportado, fluxo de 1 imagem some quando existe kit
- `tests/Unit/Phase161/CreativePromptBuilderSlotTest.php` (novo) - 7 testes de `paraSlot()`
- `tests/Feature/Phase161/CriativoKitGeracaoTest.php` (novo) - 18 testes (despacho, execução por slot, endpoints)
- `tests/Feature/Phase160/CriativoGeracaoTest.php` / `CriativoGeracaoEndpointsTest.php` - 2 testes atualizados de fila `high` para `creative` (migração intencional)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: chave de unicidade por criativo (não rascunho) corrige a armadilha central sem afrouxar GEN-06; migração de fila `high`→`creative` já estava decidida no plano e exigiu atualizar 2 testes pré-existentes; `paraSlot()` filtra o claim fixo de "sem texto" por igualdade literal exata; teste de ondas por diferença de timestamp com tolerância; e a descoberta de que `Http::fake()` não substitui stubs anteriores no mesmo teste (resolvida com um único fake por contador).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug de teste] `Queue::assertPushedOn()` não aceita contagem como 3º argumento**
- **Found during:** Task 2, primeira rodada de `CriativoKitGeracaoTest`
- **Issue:** Escrevi `Queue::assertPushedOn('creative', GerarCriativoIaJob::class, 7)` esperando que o 3º argumento fosse uma contagem (como em `assertPushed($job, $count)`). A assinatura real de `assertPushedOn()` é `($queue, $job, $callback = null)` — o 3º argumento é um CALLBACK de inspeção, não um inteiro. Passar `7` fazia o Laravel tentar invocar `7` como função e estourar `Value of type int is not callable`.
- **Fix:** Separado em duas chamadas: `Queue::assertPushedOn('creative', GerarCriativoIaJob::class)` (existência na fila certa) + `Queue::assertPushed(GerarCriativoIaJob::class, 7)` (contagem).
- **Files modified:** `tests/Feature/Phase161/CriativoKitGeracaoTest.php`
- **Commit:** `c06267ed`

**2. [Rule 1 - Bug de teste] `Http::fake()` chamado duas vezes no mesmo teste não substitui o fake anterior**
- **Found during:** Task 2, teste de "um slot falha, os outros continuam"
- **Issue:** O teste registrava `Http::fake(503)` para o slot que devia falhar e, depois, `Http::fake(sucesso)` para os 6 que deviam funcionar — esperando que o segundo substituísse o primeiro. Não substitui: o matching de `Http::fake()` percorre os stubs na ORDEM DE REGISTRO e usa o primeiro que casar a URL, então o stub de 503 continuava respondendo a TODAS as chamadas seguintes, inclusive para os slots que deviam ter sucesso — os 6 "outros" também falhavam com a mesma mensagem.
- **Fix:** Um ÚNICO `Http::fake()` com uma closure e um contador: a 1ª chamada (o slot que deve falhar, executado primeiro no teste) devolve 503; as chamadas seguintes devolvem sucesso. Modela a ordem real de execução sem depender de substituição de stub.
- **Files modified:** `tests/Feature/Phase161/CriativoKitGeracaoTest.php`
- **Commit:** `c06267ed`

**3. [Rule 1 - Bug, não é regressão de código] 2 testes da Fase 160 ainda esperavam a fila `high`**
- **Found during:** Task 2, ao rodar `--filter=Criativo` pela primeira vez após a migração de fila
- **Issue:** `test_job_e_despachado_na_fila_high` (`CriativoGeracaoTest`) e `test_gerar_devolve_202_e_enfileira_na_fila_high` (`CriativoGeracaoEndpointsTest`) afirmavam `Queue::assertPushedOn('high', ...)`. A migração de `high` para `creative` é uma decisão OPERACIONAL do próprio `161-02-PLAN.md` (`<migracao_de_fila_decidida_em_2026-10-02>`), não um efeito colateral — os testes antigos ficaram desatualizados por construção, não por bug de produção.
- **Fix:** Renomeados e atualizados para afirmar a fila `creative` (`test_job_e_despachado_na_fila_creative` / `test_gerar_devolve_202_e_enfileira_na_fila_creative`), com comentário explicando a migração.
- **Files modified:** `tests/Feature/Phase160/CriativoGeracaoTest.php`, `tests/Feature/Phase160/CriativoGeracaoEndpointsTest.php`
- **Verification:** `--filter=Criativo` voltou a 77/77 verde.
- **Commit:** `c06267ed`

---

**Total deviations:** 3 (2 bugs de teste próprios da sessão, 1 atualização de teste exigida pela própria decisão operacional do plano)
**Impact on plan:** Nenhum em código de produção fora do que o plano já prescrevia. Nenhum scope creep.

### Divergência de documentação (não é bug)

O `<done>` da Task 3 e a nota do `161-01-SUMMARY.md` descrevem a contagem de rotas de criativo como "8" depois do 161-01. Este plano acrescenta exatamente 1 rota nova (`criativo.kit.gerar`), então o total real e correto depois deste plano é **9** (8 + 1), não 8 como o texto da Task 3 diz literalmente. Confirmado por `php artisan route:list --name=mlb.anuncios.criativo` → 9 rotas, todas as esperadas, nenhuma faltando ou duplicada. Mesma natureza da divergência já documentada no `161-01-SUMMARY.md` (a aritmética do texto do plano fica um passo atrás da fase anterior) — não é regressão nem comportamento incorreto.

## Known Stubs

Nenhum. Os botões de regenerar/aprovar por slot ficam reservados e comentados em `KitCriativosGrade.jsx` (`{/* Regenerar/aprovar entram na 161-03. */}`) — objetivo explícito do próximo plano, não um stub escondido: nenhuma funcionalidade deste plano depende deles para funcionar.

## Threat Flags

Nenhum além do já registrado no `<threat_model>` do `161-02-PLAN.md` (T-161-09 a T-161-15, T-161-SC) — a implementação seguiu as mitigações descritas ali: `throttle:3,1` + idempotência de kit `gerando` + despachante que ignora slot pronto + teto `MAX_IMAGENS` conferido no despachante E no job antes do provedor (T-161-09); migração de fila já resolvida ANTES da execução, com worker `ecf-worker-creative` provisionado (T-161-10); decodificação estrita + bytes em disco privado (T-161-11); whitelist por slot + `imagem_url` com escopo e `no-store` (T-161-12); `paraSlot()` sanitiza de novo e o MASTER proíbe alterar o produto (T-161-13); `Log::info` com kit/slot sem segredo (T-161-14); `role:admin` + double-check de empresa + `CreativePermissao::exigir()` antes de despachar (T-161-15). Nenhum pacote novo foi instalado (T-161-SC).

## Verification Results

1. `phpunit tests/Unit/Phase161 tests/Feature/Phase161` — **54/54 verde** (240 assertions).
2. `phpunit --filter=Criativo` (Fase 160 + 161 juntas) — **77/77 verde** (357 assertions) — inclui os 2 testes atualizados pela migração de fila; o teste de duplo clique da 160-02 (`test_gerar_duplo_clique_nao_enfileira_duas_vezes`) continua verde, provando que a troca da chave de unicidade não afrouxou GEN-06.
3. `phpunit --filter=GeminiImageProviderTest` (spike) — **11/11 verde**, intocado.
4. `grep -n "uniqueId" app/Jobs/GerarCriativoIaJob.php` — chave por criativo (`'criativo:'.$this->criativoId`), com docblock explicando a troca.
5. `grep -c "onQueue('creative')" app/Jobs/GerarCriativoIaJob.php` → **1**; `grep -cE 'public \$queue|protected \$queue'` → **0**.
6. `grep -rn "payload" app/Services/Creative/ | grep -v CreativeContextBuilder | wc -l` → **0** (fronteira CTX-02 intacta).
7. `npm run build` — concluído sem erro.
8. `git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx` — **vazio**.
9. `artisan route:list --name=mlb.anuncios.criativo` → **9 rotas** (8 da Fase 160+161-01 + 1 nova — ver nota de divergência de documentação acima).
10. Regressão adicional (fora do `<verification>` do plano, por segurança, mesmo escopo das Summaries anteriores): `UploadImagemTest` + `AnuncioIaAnaliseTest` + `MeusAnunciosTest` + `RascunhosMeusAnunciosTest` — **48/48 verde** (163 assertions).
11. Conjunto completo pinado pelas restrições do executor (`tests/Feature/Phase160/`, `tests/Unit/Phase160/`, `tests/Feature/Phase161/`, `tests/Unit/Phase161/`, `tests/Unit/GeminiImageProviderTest.php`) — **120/120 verde** (501 assertions), nenhuma regressão.

## Self-Check: PASSED

- `app/Services/Creative/CreativeKitDespachante.php` — FOUND
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` — FOUND
- `tests/Unit/Phase161/CreativePromptBuilderSlotTest.php` — FOUND
- `tests/Feature/Phase161/CriativoKitGeracaoTest.php` — FOUND
- Commit `01229fe1` — FOUND (git log)
- Commit `c06267ed` — FOUND (git log)
- Commit `7119635f` — FOUND (git log)

---
*Phase: 161-kit-din-mico-de-7-gera-o-paralela-regenera-o-e-aprova-o-do-k*
*Completed: 2026-10-02*
