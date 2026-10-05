---
phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e
plan: 03
subsystem: ai
tags: [gemini, creative-engine, laravel, validacao-automatica, regeneracao-automatica, queue]

# Dependency graph
requires:
  - phase: 162-02
    provides: "ValidarCriativoIaJob (fila creative, chave validacao:{id}), CreativeJuiz::julgarEGravar(), gate de validação em criativoAprovar/criativoKitAprovar"
provides:
  - "ValidarCriativoIaJob::talvezRegenerar() — VAL-05: asset reprovado regenera sozinho, uma vez, pelo MESMO orçamento da regeneração manual"
  - "Trava anti-loop VAL-06: MlAnuncioCriativo::regeneracao_automatica (booleano, uma vez por asset, para sempre)"
  - "regenerar_motivos aditivo com origem='automatica'/user_id=null, lido pelo GerarCriativoIaJob já existente como \$ajusteOperador — zero linha em CreativePromptBuilder"
  - "MlAnuncioCriativoKit::regeneracoesManuais() — regeneracoes_automaticas é subconjunto de regeneracoes, nunca soma paralela"
affects: [163]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Decisão automática consome o MESMO orçamento da decisão manual — nenhum teto paralelo (podeRegenerarAsset() é a fonte única de verdade para os dois caminhos)"
    - "Motivo do juiz entra no prompt pelo bloco de contenção já existente (AJUSTE PEDIDO PELO OPERADOR, 'preferência visual, NÃO é fato') — nunca um bloco novo, nunca edição do CreativePromptBuilder"
    - "Dispatch do job de geração sai do job de VALIDAÇÃO (lock ShouldBeUnique diferente), nunca de dentro do handle() do próprio job de geração"

key-files:
  created:
    - tests/Feature/Phase162/RegeneracaoAutomaticaTest.php
    - tests/Unit/Phase162/OrcamentoDeRegeneracaoTest.php
  modified:
    - app/Jobs/ValidarCriativoIaJob.php
    - app/Models/MlAnuncioCriativoKit.php

key-decisions:
  - "talvezRegenerar() chamado DEPOIS do primeiro \$criativo->kit?->recalcularStatus() do handle() (molde já existente), e chama de novo o recalcularStatus() internamente quando regenera — redundante mas idempotente (recalcularStatus() só grava quando o status muda), preferido a reordenar a estrutura já testada do Plano 02"
  - "Texto do motivo automático NUNCA é sanitizado de novo no job — motivoCurto já sai sanitizado e cortado em 200 do CreativeJuiz::reconciliar(); o job só compõe a frase fixa e corta em 300 (defesa em profundidade, mesma disciplina do CreativePromptBuilder)"
  - "Teste (h) precisou dar fotos de referência reais ao criativo sem kit (ReferenciaEfemeraService::guardar) — sem isso o CreativeJuiz::julgar() cai em 'indisponivel' ANTES de chamar o provedor, mascarando a prova de que REPROVADA de verdade sem kit não regenera"

patterns-established:
  - "regeneracoesManuais() = regeneracoes - regeneracoes_automaticas — acessor único para separar decisão do juiz de clique do operador na métrica de 'média de regenerações por kit' (§19, Fase 163)"

requirements-completed: [VAL-05, VAL-06]

# Metrics
duration: ~20min (do commit da Task 1 ao commit da Task 2)
completed: 2026-10-05
---

# Phase 162 Plan 03: Regeneração automática Summary

**`ValidarCriativoIaJob::talvezRegenerar()` reabre sozinho o asset reprovado pelo juiz, grava o motivo em `regenerar_motivos` (origem automática, user_id nulo) e despacha `GerarCriativoIaJob` já existente — uma vez por asset (VAL-06), dentro do MESMO orçamento `podeRegenerarAsset()` da regeneração manual (VAL-05), sem tocar uma linha no `CreativePromptBuilder`.**

## Performance

- **Duration:** ~20 min entre o commit da Task 1 (`008e2eaf`) e o commit da Task 2 (`8ee4d223`)
- **Tasks:** 2/2 completas
- **Files modified:** 4 (2 criados, 2 modificados)

## Accomplishments

- `ValidarCriativoIaJob::talvezRegenerar()` — método privado chamado depois de `julgarEGravar()`. Early-return em cascata: só reprovada (nunca `indisponivel`), chave `regenerar_automatico` ligada, `regeneracao_automatica === false` (trava VAL-06), kit/slot_indice presentes (recurso do kit), e `podeRegenerarAsset()` true (orçamento compartilhado) — qualquer falso encerra sem gastar nada
- Texto do motivo montado no servidor ("A validação automática reprovou a imagem anterior: {motivo}. Refaça seguindo exatamente as fotos de referência."), cortado em 300 caracteres, gravado como entrada ADITIVA em `regenerar_motivos` com `origem: 'automatica'` e `user_id: null` — o `GerarCriativoIaJob` já existente lê essa entrada como `$ajusteOperador` (molde da Quick 261003-l8o), sem nenhuma linha nova no `CreativePromptBuilder`
- Dentro da `DB::transaction()` (molde literal de `criativoRegenerar()`): reabre o slot (`status=pendente`), marca `regeneracao_automatica=true`, incrementa `regeneracoes` do criativo e do kit, e `regeneracoes_automaticas` do kit
- Fora da transação: `GerarCriativoIaJob::dispatch()` (job separado, lock `criativo:{id}` já liberado — comentário explícito na linha contra a armadilha da Decisão 1 do 162-02) e `$kit->recalcularStatus()`
- `MlAnuncioCriativoKit::regeneracoesManuais()` — acessor novo que documenta `regeneracoes_automaticas` como subconjunto de `regeneracoes`, nunca soma paralela
- 9 testes em `RegeneracaoAutomaticaTest` (caminho feliz, aditividade do histórico, prova de ponta a ponta de que a 2ª geração usa o bloco `AJUSTE PEDIDO PELO OPERADOR` + `VARIAÇÃO OBRIGATÓRIA` já existente, não-loop na 2ª reprovação, `indisponivel` nunca regenera, teto de imagens do kit bloqueia, chave desligada bloqueia, slot sem kit nunca regenera, gate de grep de dispatch único) + 5 testes em `OrcamentoDeRegeneracaoTest` (orçamento compartilhado, tetos do kit/imagens, subconjunto de `regeneracoes_automaticas`, independência de `max_validacoes_asset`, custo do pior caso documentado)

## Task Commits

1. **Task 1: Regeneração automática uma vez, pelo orçamento existente (VAL-05 + VAL-06)** — `008e2eaf` (feat)
2. **Task 2: Teste de orçamento compartilhado — a automação não cria cota nova** — `8ee4d223` (feat)

**Plan metadata:** (este commit, docs — ver `<final_commit>`)

## Files Created/Modified

- `app/Jobs/ValidarCriativoIaJob.php` — `talvezRegenerar()` chamado no fim de `handle()`
- `app/Models/MlAnuncioCriativoKit.php` — `regeneracoesManuais()` com docblock do subconjunto
- `tests/Feature/Phase162/RegeneracaoAutomaticaTest.php` — 9 testes (molde de `RegeneracaoComVariacaoTest` + `ValidacaoAutomaticaTest`)
- `tests/Unit/Phase162/OrcamentoDeRegeneracaoTest.php` — 5 grupos de asserção, em memória, sem HTTP

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: (1) `recalcularStatus()` chamado duas vezes em sequência quando regenera (uma do molde já existente do Plano 02, outra dentro de `talvezRegenerar()`) é redundante mas inofensivo — `recalcularStatus()` só grava quando o status computado difere do atual; (2) o motivo do juiz não é sanitizado de novo no job (já vem sanitizado/cortado em 200 de `CreativeJuiz::reconciliar()`), só a frase final é cortada em 300; (3) o teste do caso "sem kit" precisou de fotos de referência reais para o juiz não cair em `indisponivel` antes mesmo de chamar o provedor mockado.

## Deviations from Plan

None - plano executado exatamente como escrito. A trava de coordenação com a Fase 165 (`CreativeContextBuilder.php`, `ProductTruthBuilder.php`, `CreativePermissao.php`, `CreativePromptBuilder.php`, `app/Services/Publicador/`, `PubProduto*`, `MlbPublicador*`, JSX do Publicador) foi conferida por `git diff --stat` vazio ao final das duas tasks — nenhum desses arquivos foi tocado.

## Issues Encountered

Um único ajuste durante a escrita do teste (h) — "criativo sem kit nunca regenera": a primeira versão do helper não dava fotos de referência reais ao criativo, e o `CreativeJuiz::julgar()` caía em `indisponivel` ANTES de chamar o provedor mockado (porque `referenciasVivas()` do portador estava vazio), o que teria provado a coisa errada (indisponível nunca regenera, não "sem kit nunca regenera"). Corrigido adicionando `ReferenciaEfemeraService::guardar()` ao helper, replicando o molde de `ValidacaoAutomaticaTest::criativoComReferencia()` — não é desvio do plano, é correção de um teste próprio antes do commit.

## User Setup Required

Nenhum. A chave `services.creative.validacao.regenerar_automatico` já existia desde o Plano 01 (`.env.example` documentado, default `true`).

## Self-Check: PASSED

- `app/Jobs/ValidarCriativoIaJob.php` (contém `talvezRegenerar` e `regeneracao_automatica`) — FOUND
- `app/Models/MlAnuncioCriativoKit.php` (contém `regeneracoesManuais`) — FOUND
- `tests/Feature/Phase162/RegeneracaoAutomaticaTest.php` — FOUND (9 testes, verde)
- `tests/Unit/Phase162/OrcamentoDeRegeneracaoTest.php` — FOUND (5 testes, verde)
- Commit `008e2eaf` — FOUND em `git log`
- Commit `8ee4d223` — FOUND em `git log`
- `grep -vE '^\s*(//|\*|/\*)' app/Jobs/ValidarCriativoIaJob.php | grep -c "GerarCriativoIaJob::dispatch"` → `1` — CONFIRMADO
- `git diff --stat app/Services/Creative/CreativePromptBuilder.php` → vazio — CONFIRMADO
- `git diff --stat` nos 4 arquivos travados pela coordenação com a Fase 165 + `app/Services/Publicador/`/`PubProduto*`/`MlbPublicador*`/JSX do Publicador + `composer.json/lock`/`package.json/lock` — TODOS VAZIOS — CONFIRMADO
- Suíte pinada (`Phase162|Phase161|Phase160|Quick261003L8o|GeminiImageProviderTest`) — 235 passed (1027 assertions) — CONFIRMADO

## Next Phase Readiness

- VAL-05/VAL-06 encerram o escopo funcional da Fase 162 (validação automática + regeneração automática). A métrica de "média de regenerações por kit" da Fase 163 (§19) já tem `regeneracoesManuais()` disponível para separar decisão do juiz de decisão do operador.
- Nenhum bloqueio conhecido. A trava de coordenação com a Fase 165 permanece válida e sem incidente ao longo de todo o Plano 03.

---
*Phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e*
*Plan: 03*
*Completed: 2026-10-05*
