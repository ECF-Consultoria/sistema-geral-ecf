---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 05
subsystem: creative-engine
tags: [laravel, creative-engine, publicador, http, throttle, testes]

requires:
  - phase: 165-03
    provides: "PublicadorCriativoAprovacaoService::aprovarKit()/aprovarSlot()"
  - phase: 165-04
    provides: "MlbPublicadorCriativoController (atual/status/planejar/gerar/referencia/imagem/aprovar) + PublicadorCriativoKitPresenter + rotas mlb.anuncios.publicador.criativos.*"
provides:
  - "MlbPublicadorCriativoController::regenerar() — regenera UM slot do kit com o mesmo teto/relógio/transação do fluxo antigo, passando $regeneracao/$ajusteOperador ao prompt"
  - "MlbPublicadorCriativoController::aprovarKit() — aprova o kit inteiro via PublicadorCriativoAprovacaoService, com a retenção da referência efêmera só na aprovação do kit"
  - "Rotas slot.regenerar (throttle:creative-regenerar) e kit.aprovar (throttle:30,1,publicador.criativos.aprovar) no bloco criativos.* do Publicador"
  - "Prova de D-13 do lado da 165: nenhum token de 32 caracteres nas 9 rotas novas (sucesso e recusa), fluxo antigo isolado, guarda das rotas antigas visível como pendente"
affects: [165-06, 165-07, 165-08]

tech-stack:
  added: []
  patterns:
    - "Regenerar reaproveita a variável do parâmetro de rota (int $kit) para guardar o model MlAnuncioCriativoKit depois do escopo — único lugar do controller que faz isso (os demais métodos usam $k para não sombrear)"
    - "Teste que precisa do JSON 'limpo' de um abort_if (sem trace) desliga app.debug no setUp — .env local tem APP_DEBUG=true, e o handler de exceção do Laravel acrescenta trace/file a QUALQUER JSON de erro nesse modo, inclusive 404"

key-files:
  created:
    - tests/Feature/Phase165/RegenerarEAprovarKitTest.php
    - tests/Feature/Phase165/LimitesDoPublicadorTest.php
    - tests/Feature/Phase165/NenhumTokenNoNavegadorTest.php
    - tests/Feature/Phase165/RotasAntigasComKitDoPublicadorTest.php
  modified:
    - app/Http/Controllers/MlbPublicadorCriativoController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "aprovarKit() repassa $res['ok'] do serviço sem reinterpretar — PublicadorCriativoAprovacaoService::aprovarKit() (165-03, fora do files_modified deste plano) devolve ok:true mesmo com falha parcial ('ok' ali significa 'a chamada rodou', não 'tudo certo'); quem decide se o kit fechou é kit_aprovado/falharam. O behavior do 165-05-PLAN.md descrevia 'ok:false' para esse caso — divergência de prosa, não de comportamento real do serviço já implementado; o teste foi ajustado para refletir o serviço, não o texto do plano (código manda)."
  - "Teste do caso (2) da guarda das rotas antigas desliga app.debug e usa markTestIncomplete() quando a rota antiga devolve != 404 — nunca falha, nunca enshrina 200 como certo; liga-se sozinho (passa a exigir 404 nas 3 rotas restantes) no dia em que o outro dev acrescentar o abort_if de 1 linha"

requirements-completed: [CE165-06, CE165-09, CE165-11, CE165-12]

duration: ~95min
completed: 2026-10-05
---

# Fase 165 Plano 05: Regenerar, aprovar o kit e provar D-13 no Publicador Summary

**`regenerar()`/`aprovarKit()` fecham o Creative Engine do Publicador — regenerar passa `$regeneracao`/`$ajusteOperador` ao `CreativePromptBuilder` (sem isso a correção do Quick 261003-l8o não valeria para este caminho e devolveria sempre a mesma imagem), aprovar o kit inteiro delega ao serviço da 165-03, e 26 testes novos provam os limitadores pt-BR e que nenhum token de 32 caracteres chega ao navegador pelas 9 rotas novas.**

## Performance

- **Duração:** ~95 min
- **Tasks:** 2/2
- **Arquivos:** 4 criados, 2 modificados

## Accomplishments

- `MlbPublicadorCriativoController::regenerar()` — molde de `criativoRegenerar()` antigo: valida `motivo` (≤300 chars), recusa kit fechado/sem referência viva ANTES de subir qualquer contador, recusa slot aprovado/em andamento, respeita os tetos por asset e por kit, grava `regenerar_motivos` (aditivo, com `user_id`/`em`), reinicia o relógio da tentativa (achado (b) do 165-01) e despacha `GerarCriativoIaJob` SEM chamar `recalcularStatus()` (achado (a)). Provado por teste que `$regeneracao`/`$ajusteOperador` chegam ao `CreativePromptBuilder::paraSlot()` — o defeito que motivou o Quick 261003-l8o (regenerar sempre devolver a mesma imagem) não volta por este caminho.
- `MlbPublicadorCriativoController::aprovarKit()` — delega a `PublicadorCriativoAprovacaoService::aprovarKit()` (165-03): cada slot `pronto` sobe para `pub_imagens` na ordem de `slot_indice`, o kit só fecha quando nada falhou e o mínimo foi atingido, e a referência efêmera do portador só é apagada nesta aprovação (nunca na de um slot isolado).
- Rotas `slot.regenerar` (`throttle:creative-regenerar`) e `kit.aprovar` (`throttle:30,1,publicador.criativos.aprovar`) no bloco `criativos.` existente — nenhuma linha removida de `routes/mlb_anuncios.php` desde `bb1b61f8`.
- `LimitesDoPublicadorTest` — as 3 rotas que gastam cota usam os limitadores NOMEADOS do Creative Engine (não um throttle novo), o 429 sai em pt-BR com `Retry-After`, o balde é o MESMO da rota antiga (12 chamadas antigas + 1 nova → 429) e é por usuário, não por IP.
- `NenhumTokenNoNavegadorTest` — percorre as 9 rotas `mlb.anuncios.publicador.criativos.*` (sucesso E as recusas 404/422/409/429) e prova que nenhum corpo JSON contém um token de 32 caracteres conhecido (kit/slot/portador), nenhuma sequência de 32 alfanuméricos por regex, e nenhuma chave `token`/`kit_token` em nenhum nível — e que as URLs de imagem/referência usam o `kit_id` numérico.
- `RotasAntigasComKitDoPublicadorTest` — caso (1): a rota antiga `criativo.kit.planejar` com um criativo do assistente antigo nunca devolve o kit do Publicador (o `where('rascunho_id', $criativo->rascunho_id)` do controller antigo nunca casa com `rascunho_id IS NULL`); o kit do Publicador fica intocado (`status`/`updated_at` iguais). Caso (2): a guarda das rotas antigas ainda não existe — `markTestIncomplete()` documenta o risco aceito (T-165-20) sem afirmar 200 como certo; o teste se liga sozinho (passa a exigir 404 nas outras 3 rotas) no dia em que o `abort_if` de 1 linha proposto no checkpoint do 165-01 chegar.

## Task Commits

1. **Task 1: Regenerar uma imagem e aprovar o kit inteiro** — `ed9cf1bb` (feat)
2. **Task 2: Limitadores em pt-BR, nenhum token no navegador e as rotas antigas** — `b076458b` (test)

## Files Created/Modified

- `app/Http/Controllers/MlbPublicadorCriativoController.php` — `regenerar()` e `aprovarKit()`
- `routes/mlb_anuncios.php` — rotas `slot.regenerar` e `kit.aprovar`
- `tests/Feature/Phase165/RegenerarEAprovarKitTest.php` — 19 testes (regenerar: sucesso/contadores/motivos/tetos/recusas/relógio/escopo; aprovarKit: sucesso/mínimo/já aprovado/falha parcial/escopo)
- `tests/Feature/Phase165/LimitesDoPublicadorTest.php` — 4 testes (limitadores nomeados, 429 em pt-BR, balde compartilhado, por usuário)
- `tests/Feature/Phase165/NenhumTokenNoNavegadorTest.php` — 1 teste ponta a ponta pelas 9 rotas (sucesso + 404/422/409/429)
- `tests/Feature/Phase165/RotasAntigasComKitDoPublicadorTest.php` — 2 testes (não regressão + guarda que se liga sozinha)

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo:

1. **`aprovarKit()` repassa `ok` do serviço sem reinterpretar.** O `165-05-PLAN.md` descrevia, na prosa do `<behavior>`, que uma falha parcial devolveria `ok: false`. Ao ler `PublicadorCriativoAprovacaoService::aprovarKit()` (165-03, já implementado, fora do `files_modified` deste plano), confirmei que o serviço devolve `ok: true` mesmo com `falharam` não vazio — ali `ok` significa "a chamada processou", não "tudo subiu"; `kit_aprovado`/`falharam` é quem carrega a informação de sucesso parcial. A própria ação do plano (`GREEN`) já instruía repassar `{ok, aprovadas, falharam, kit_aprovado, mensagem}` literalmente do retorno do serviço — ou seja, o próprio plano se contradiz entre a prosa do `<behavior>` e o pseudocódigo do `<action>`. Segui o `<action>` (repassar sem reinterpretar) e ajustei o teste para o comportamento real do serviço já em produção, documentando aqui em vez de alterar `PublicadorCriativoAprovacaoService` (que pertenceria a outra onda).
2. **`app.debug` desligado em `NenhumTokenNoNavegadorTest`.** O `.env` local tem `APP_DEBUG=true`; com debug ligado, o handler de exceção do Laravel acrescenta `trace`/`file` ao JSON de QUALQUER abort (inclusive o 404 de "kit não encontrado"), e os caminhos de arquivo do `vendor/` batem na regex de 32 caracteres por acidente — um falso-positivo que não tem relação com token nenhum. Desligar `app.debug` no `setUp()` faz o teste refletir o JSON de produção (`APP_DEBUG=false`), que é limpo.

## Deviations from Plan

### Auto-fixed Issues

Nenhum desvio das Regras 1-4 (nenhum bug, funcionalidade crítica ausente, bloqueio ou mudança arquitetural). Os dois ajustes abaixo são de **infraestrutura de teste**, não de comportamento do controller:

1. O dublê de `ImageGenerationProvider` (da trait `CenarioCriativoDoPublicador`) devolve `jpeg(1200+n)` com `n` incrementado por chamada, começando em 0 — regenerar o slot 1 de `kitProntoDoPublicador()` coincidiria com o MESMO lado (`1201`) que o cenário já escreveu em disco para esse slot (fakes de imagem com o mesmo lado dão bytes idênticos). Ajustei o teste de "imagem muda" para o slot 2.
2. `services.creative.validacao.ativa` desligada no `setUp()` de `NenhumTokenNoNavegadorTest` — mesmo motivo de infraestrutura já registrado no `165-04-SUMMARY.md` (o `ImageJudgementProvider` da Fase 162 não tem dublê registrado pela trait; sem desligar, `ValidarCriativoIaJob` quebraria em `Http::preventStrayRequests()`).

---

**Total deviations:** 0 das Regras 1-4. 2 ajustes de infraestrutura de teste (documentados acima) + 1 divergência de prosa do plano (`<behavior>` vs `<action>`, resolvida a favor do `<action>`/código real — ver Decisions Made).
**Impact on plan:** Nenhum scope creep. Nenhum arquivo fora do `files_modified` foi alterado.

## Issues Encountered

Nenhum bloqueio. Ver "Deviations from Plan" para os dois ajustes de teste.

## Known Stubs

Nenhum. Este plano é só backend (2 métodos de controller + 2 rotas); não há componente de tela — a tela entra no 165-06/07 (em execução paralela).

## Threat Flags

Nenhuma superfície nova fora do `threat_model` do próprio plano. Cobertura:

- **T-165-25** (motivo no prompt) — validação `max:300` na rota; sanitização/bloco de contenção já vivem em `CreativePromptBuilder` (não tocado); log nunca grava o texto (`Log::info` sem `motivo`).
- **T-165-26** (DoS financeiro via regenerar) — `throttle:creative-regenerar`; `podeRegenerarAsset`/`motivoDoTetoAsset`; recusa de slot em geração; recusa de kit fechado/sem referência viva ANTES de subir o contador (provado por teste com `Queue::fake()` + `assertNothingPushed()`).
- **T-165-20** (rotas antigas com token do Publicador) — provado negativo pelas 9 rotas novas (`NenhumTokenNoNavegadorTest`); caso (1) de não regressão provado; guarda pendente registrada e visível (`RotasAntigasComKitDoPublicadorTest`, "incomplete").
- **T-165-28** (aprovar kit duas vezes) — `throttle:30,1,publicador.criativos.aprovar`; o serviço recusa kit já `aprovado` (422 testado).
- **T-165-29** (repúdio) — `regenerar_motivos` aditivo com `user_id`/`em` (testado); `aprovado_por` no kit (já coberto pelo serviço 165-03).
- **T-165-30** (referência depois do kit fechado) — regenerar recusa quando `referenciasVivas() === []` (testado nos dois sentidos: kit fechado E kit aberto com referência já apagada pela varredura de 48h).

## User Setup Required

Nenhum.

## Resultado dos testes (medido nesta execução)

| Suíte | Comando | Resultado |
|---|---|---|
| `tests/Feature/Phase165/RegenerarEAprovarKitTest.php` | `phpunit` | **19 testes / 82 asserções / 0 falhas** |
| `tests/Feature/Phase165/{LimitesDoPublicadorTest,NenhumTokenNoNavegadorTest,RotasAntigasComKitDoPublicadorTest}.php` | `phpunit` | **7 testes / 290 asserções / 0 falhas / 1 incomplete** (o caso (2) da guarda — esperado, ver abaixo) |
| `tests/Feature/Phase165` + `tests/Unit/Phase165` (fase inteira, 165-01 a 165-05) | `phpunit tests/Feature/Phase165 tests/Unit/Phase165` | **148 testes / 838 asserções / 0 falhas / 1 incomplete** |
| Creative Engine antigo (`Unit/Phase160`, `Unit/Phase161`, `Unit/Quick261003L8o`, `Feature/Phase160`, `Feature/Phase161`, `Feature/Quick261003L8o`) | `phpunit` | **164 testes / 758 asserções / 0 falhas / 1 deprecation do PHPUnit** (pré-existente, mesma do 165-04-SUMMARY) |
| Validador da Fase 162 (`Feature/Phase162`) | `phpunit` | **32 testes / 170 asserções / 0 falhas** |
| `tests/Feature/Publicador` + `tests/Unit/Publicador` (suíte inteira do Publicador) | `phpunit` | **803 testes / 4039 asserções / 0 falhas** — idêntico ao baseline do 165-04-SUMMARY.md |
| `node --test tests/js/estrutura-anunciar-ml.test.js` | `node --test` | **6/6 pass** |

**O caso (2) da guarda das rotas antigas saiu "incomplete" (sem guarda) nesta execução** — a rota antiga `criativo.kit.status` devolveu algo diferente de 404 para o token de um kit do Publicador, confirmando o risco residual T-165-20 já aceito e registrado no `165-01-SUMMARY.md`. O teste está pronto para exigir 404 nas 4 rotas antigas automaticamente no dia em que o outro dev acrescentar o `abort_if` de 1 linha proposto no checkpoint — nenhuma edição deste arquivo será necessária.

`git status --short` / `git log --oneline bb1b61f8..HEAD -- app/Http/Controllers/MlbAnuncioController.php` confirmados: nenhuma mudança no assistente antigo nesta execução (só os 3 commits pré-existentes da Fase 162).

## Next Phase Readiness

O contrato HTTP do Creative Engine dentro do Publicador está completo (7 leituras/escritas do 165-04 + regenerar/aprovar-kit deste plano) — os planos 165-06/07 (tela nativa da mesa, `PainelCriativos.jsx`/`useCriativosDoPublicador`, em execução em paralelo nesta mesma janela) podem consumir `slot.regenerar` e `kit.aprovar` sem precisar redescobrir tetos, relógio de tentativa ou o gate de validação da Fase 162. A guarda das rotas antigas (D-13) continua como item pendente do outro dev, sem bloquear nada da 165.

## Self-Check: PASSED

Arquivos citados neste SUMMARY confirmados em disco (`app/Http/Controllers/MlbPublicadorCriativoController.php`, `routes/mlb_anuncios.php`, os 4 arquivos de teste). Commits `ed9cf1bb` e `b076458b` confirmados em `git log --oneline --all`. Todas as suítes da tabela acima foram executadas nesta sessão com os números reportados.

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
