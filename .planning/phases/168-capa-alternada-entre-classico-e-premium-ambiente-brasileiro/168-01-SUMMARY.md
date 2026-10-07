---
phase: 168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro
plan: 01
subsystem: publicador
tags: [publicador, payload, mercado-livre, user-products, tdd]

requires: []
provides:
  - "OrdemCapaPorAlvo: função pura de rotação de capa por índice posicional do alvo"
  - "PayloadBuilderUserProducts::montar() liga a rotação no caminho real de montagem do payload User Products"
affects: [169-quarta-etapa-imagens-editor-publicador]

tech-stack:
  added: []
  patterns:
    - "Funções puras em app/Support/Publicador/Payload/ recebem índice posicional (array_values de alvosAtivos()) em vez de ler estado — mantém determinismo"

key-files:
  created:
    - app/Support/Publicador/Payload/OrdemCapaPorAlvo.php
    - tests/Unit/Publicador/Payload/OrdemCapaPorAlvoTest.php
  modified:
    - app/Support/Publicador/Payload/PayloadBuilderUserProducts.php
    - tests/Unit/Publicador/Payload/MontadorDePlanoTest.php

key-decisions:
  - "Rotação por índice posicional do alvo dentro de alvosAtivos() (0-based): índice 0 nunca rotaciona, índice N rotaciona N posições à esquerda — generaliza para mais de 2 alvos sem regra nova"
  - "Lista com menos de 2 fotos não rotaciona: 0 ou 1 foto aprovada é limite físico documentado em teste, não tratado como bug"

patterns-established:
  - "Função pura de reordenação testada isoladamente (RED/GREEN) e depois ligada com diff mínimo no método de produção — sem reescrever lógica existente"

requirements-completed: [CAPA-01, CAPA-02, CAPA-03, CAPA-04]

duration: 25min
completed: 2026-10-07
---

# Fase 168 Plano 01: Capa alternada entre Clássico e Premium — Summary

**`OrdemCapaPorAlvo` (função pura, TDD) ligada a `PayloadBuilderUserProducts::montar()`: o Premium do mesmo produto agora usa a 2ª foto aprovada como capa, enquanto o Clássico mantém a 1ª — sem gerar imagem nova, sem duplicar o acervo, só trocando a ordem de envio por alvo.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 1 de 2 (Task 1 completa; Task 2 é checkpoint humano — ver seção "Checkpoint pendente")
- **Files modified:** 4 (2 criados, 2 modificados)

## Accomplishments

- Capa do Premium nunca repete a capa do Clássico quando há 2+ fotos aprovadas (CAPA-01)
- Rotação é só de ordem sobre a mesma lista de fotos — nunca duplica, nunca descarta (CAPA-02)
- Alvo único mantém o comportamento de hoje — comprovado sem tocar no snapshot `up_cadeira_simples_classico.json` (CAPA-03)
- Rotação determinística (mesma entrada → mesma saída, função pura sem estado) (CAPA-04)
- Diff do caminho de produção (`PayloadBuilderUserProducts::montar()`) limitado a 2 linhas — nenhuma outra lógica do método mudou

## Task Commits

Cada etapa do ciclo TDD foi commitada atomicamente:

1. **Task 1 — RED:** `f91f2f3f` (test) — teste falhando de `OrdemCapaPorAlvoTest.php` (classe ainda não existia)
2. **Task 1 — GREEN (função pura):** `cc9a0a9b` (feat) — implementação de `OrdemCapaPorAlvo::aplicar()`
3. **Task 1 — wiring + integração:** `82aa3bd6` (feat) — liga a função em `PayloadBuilderUserProducts::montar()` e adiciona os 4 testes de integração (`test_capa01_*` a `test_capa04_*`) em `MontadorDePlanoTest.php`

**Plano metadata:** (este SUMMARY — não commitado, conforme instrução do orquestrador)

## Files Created/Modified

- `app/Support/Publicador/Payload/OrdemCapaPorAlvo.php` — função pura `aplicar(array $fotos, int $indiceAlvo): array`; índice 0 nunca rotaciona, índice N rotaciona N posições à esquerda, lista < 2 elementos devolvida como veio
- `app/Support/Publicador/Payload/PayloadBuilderUserProducts.php` — `montar()` agora itera `alvosAtivos()` capturando o índice posicional e chama `OrdemCapaPorAlvo::aplicar($fotos[$v->chave] ?? [], $indiceAlvo)` no lugar da leitura direta
- `tests/Unit/Publicador/Payload/OrdemCapaPorAlvoTest.php` — 7 testes unitários da função pura (índice 0, índice 1, generalização para 3 alvos, 0/1 foto, nunca duplica/descarta, determinismo)
- `tests/Unit/Publicador/Payload/MontadorDePlanoTest.php` — 4 testes novos de integração no caminho real (`test_capa01_*` a `test_capa04_*`); nenhum teste existente foi removido ou reescrito

## Decisions Made

- Regra de rotação confirmada exatamente como desenhada no plano: índice posicional dentro de `alvosAtivos()`, 0-based, índice 0 nunca rotaciona. Nenhuma divergência entre o plano e o código real encontrada — `PayloadBuilderUserProducts::montar()` batia linha a linha com o que o `<interfaces>` descrevia.
- Nenhuma decisão nova foi necessária durante a execução — o plano já havia resolvido as 3 perguntas de design (índice 0 fixo, rotação à esquerda, 0/1 foto como limite físico documentado).

## Deviations from Plan

None - plano executado exatamente como escrito. Nenhuma linha fora do diff previsto em `<interfaces>` foi tocada em `PayloadBuilderUserProducts.php`.

## Issues Encountered

Ao verificar o baseline completo (`tests/Feature/Publicador tests/Unit/Publicador`), um primeiro `Bash` com timeout de 180s foi movido para background pelo próprio ambiente antes de terminar (suíte grande, ~3min17s de execução real). Resolvido relançando em background explícito e aguardando a conclusão — resultado real coletado abaixo, sem atalho.

## Resultado real das suítes (saída completa, não resumida)

**Verify do Task 1** (`OrdemCapaPorAlvoTest.php` + `MontadorDePlanoTest.php`):
```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.12
...........................                                       27 / 27 (100%)
Time: 00:00.637, Memory: 20.00 MB
OK (27 tests, 100 assertions)
```
(7 testes de `OrdemCapaPorAlvoTest` + 20 testes já existentes + 4 novos de `MontadorDePlanoTest` = 27; nenhum snapshot alterado.)

**Baseline completo** (`tests/Feature/Publicador tests/Unit/Publicador`):
```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.2.12
...
816 / 816 (100%)
Time: 03:17.699, Memory: 184.00 MB
OK (816 tests, 4093 assertions)
```
816 = 805 (baseline documentado no plano) + 11 novos (7 `OrdemCapaPorAlvoTest` + 4 `MontadorDePlanoTest`). Zero falhas, zero regressão.

`git diff` de `PayloadBuilderUserProducts.php` confirmado mínimo (2 linhas: `foreach` com `$indiceAlvo` e a chamada a `OrdemCapaPorAlvo::aplicar`). `tests/fixtures-ml/snapshots/up_cadeira_simples_classico.json` não foi tocado (`git status --short` vazio para esse arquivo) — prova viva de CAPA-03.

## Threat Flags

Nenhum — o próprio `<threat_model>` do plano já cobre a única superfície tocada (reordenação pura sobre ids já validados do rascunho, sem dado novo, sem estado). Nenhuma superfície de ataque nova foi introduzida pela implementação.

## Known Stubs

Nenhum.

## User Setup Required

None - nenhuma configuração externa necessária.

## Checkpoint pendente (Task 2 — NÃO resolvido por este executor)

A Task 2 do plano é `checkpoint:human-verify` com `gate="blocking"` — por instrução explícita do orquestrador, este checkpoint pertence à conversa dele com o usuário, não a este executor. Nenhuma resposta do usuário foi registrada aqui.

**O que o usuário precisa olhar** (do `<how-to-verify>` do plano):

1. **Suíte automatizada (já comprovada acima, custo zero):** os 4 testes `test_capa01_*` a `test_capa04_*` em `MontadorDePlanoTest.php` estão verdes e exercitam a MESMA função (`PayloadBuilderUserProducts::montar()`) que roda na publicação real.
2. **Opcional, só leitura, sem publicar nada — SE existir um rascunho real no VPS com os dois alvos ativos e 2+ fotos aprovadas no ML.** O script de `tinker` está no plano (`168-01-PLAN.md`, Task 2, `<how-to-verify>` item 2). Deve ser executado **pelo orquestrador da sessão, nunca por um subagente** (aprendizado já registrado no projeto: subagente é bloqueado para comandos de produção). Este executor não teve acesso ao VPS e não rodou esse script.

**Resume-signal esperado pelo plano:** o usuário digitar "aprovado" ou descrever o que não bateu com o esperado.

## Next Phase Readiness

- `OrdemCapaPorAlvo` e o wiring em `PayloadBuilderUserProducts` estão prontos e testados; nenhum bloqueio técnico para a Fase 169 (quarta etapa de Imagens) nesta frente — a Fase 169 tem seu próprio gate de coordenação com o outro dev, não relacionado a este plano.
- Falta apenas a resposta do usuário ao checkpoint da Task 2 para a Fase 168 Plano 01 ser considerada formalmente concluída pelo fluxo GSD.

## Self-Check: PASSED

Todos os 4 arquivos de `files_modified` e este SUMMARY confirmados em disco; os 3 commits de hash
(`f91f2f3f`, `cc9a0a9b`, `82aa3bd6`) confirmados em `git log --all`.

---
*Phase: 168-capa-alternada-entre-classico-e-premium-ambiente-brasileiro*
*Plan: 01*
*Completed (Task 1 only): 2026-10-07*
