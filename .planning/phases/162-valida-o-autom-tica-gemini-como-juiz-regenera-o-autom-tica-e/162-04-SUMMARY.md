---
phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e
plan: 04
subsystem: ui
tags: [gemini, creative-engine, laravel, inertia, react, validacao-automatica]

# Dependency graph
requires:
  - phase: 162-02
    provides: "validacao_status/validacao/validacaoMensagem() no modelo, gate confirmar_risco em criativoAprovar/criativoKitAprovar"
  - phase: 162-03
    provides: "regeneracao_automatica (não consultada diretamente aqui, mas parte do mesmo ciclo de validação)"
provides:
  - "5 campos de validação por slot (validacao_status, validacao_mensagem, validacao_problemas, pode_aprovar, exige_confirmacao_risco) e 2 no kit (prontas_sem_risco, reprovadas) na whitelist de criativo.kit.status"
  - "Trava de tempo da validação (VAL-06) agora também roda no polling do kit, não só nos dois endpoints de aprovação"
  - "Alerta de risco em pt-BR por cartão em KitCriativosGrade.jsx, com o motivo real do juiz e a lista de problemas (gravidade + explicação)"
  - "Fluxo de dois passos para aprovar imagem reprovada ('Aprovar mesmo assim' -> 'Sim, usar esta imagem'), estado local chaveado por token"
  - "aprovarSlot(token, confirmarRisco) em PainelCriativosIa.jsx envia confirmar_risco no corpo; botão 'Aprovar kit' passa a usar prontas_sem_risco"
affects: [163]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Whitelist fechada por slot: só os 5 campos novos saem do json cru de validacao — mesma disciplina T-161-05 do resto do endpoint"
    - "Flags de decisão (pode_aprovar/exige_confirmacao_risco) calculadas no SERVIDOR, nunca recalculadas na tela — mesma disciplina de regeneracoes_restantes/prontas/aprovadas já estabelecida nos Planos 02/03 da Fase 161"
    - "Confirmação de risco em dois passos é estado LOCAL do componente (useState chaveado por token), não vai ao servidor até o operador confirmar — o servidor continua sendo o gate real (confirmar_risco)"

key-files:
  created:
    - tests/Feature/Phase162/KitStatusValidacaoTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
    - resources/js/Pages/Mlb/components/PainelCriativosIa.jsx

key-decisions:
  - "prontasSemRisco()/reprovadas() já existiam no model MlAnuncioCriativoKit desde o Plano 02 — Task 1 só expôs os números na resposta HTTP, não precisou escrever lógica nova de contagem"
  - "'tipo' não entrou na lista de chaves proibidas no nível do slot (whitelist): já existe como campo legítimo e pré-existente (tipo do slot, ex. 'hero') desde a Fase 161; a chave proibida com o mesmo nome é vocabulário interno só DENTRO de cada item de validacao_problemas, testada separadamente"
  - "prontasSemRisco() conta null (dado legado nunca validado) como 'sem risco' — mesma disposição fail-open de indisponivel; o teste (a) inicialmente assumiu o contrário e foi corrigido para refletir o comportamento real do model (3, não 2, no cenário de 5 estados)"

patterns-established:
  - "Indicador curto (ícone + rótulo) sempre visível quando validacao_status existe + bloco de alerta detalhado SÓ para reprovada/indisponivel — separa 'o que está acontecendo' de 'o que fazer sobre isso', mesmo padrão que o projeto já usa para status/erro de slot"

requirements-completed: [APROV-04, VAL-03]

# Metrics
duration: ~35min
completed: 2026-10-05
---

# Phase 162 Plan 04: Alerta de risco na grade e aprovação com risco assumido Summary

**`criativo.kit.status` passa a carregar 5 campos de validação por slot (whitelist fechada) + 2 no kit; `KitCriativosGrade.jsx` mostra o veredito do juiz em pt-BR ao lado de cada imagem (nunca jargão técnico) e exige dois passos explícitos para aprovar uma imagem reprovada, enviando `confirmar_risco` ao servidor que já era o gate real desde o Plano 02.**

## Performance

- **Duration:** ~35 min (Task 1 + Task 2 + verificação)
- **Tasks:** 2/2 completas
- **Files modified:** 4 (1 criado, 3 modificados)

## Accomplishments

- `criativoKitStatus()` ganhou, por slot: `validacao_status`, `validacao_mensagem` (frase pt-BR pronta do servidor), `validacao_problemas` (lista sanitizada com só `gravidade`+`explicacao`, sem `tipo`/`fidelidade`/`motivo_curto`/`override`), `pode_aprovar` e `exige_confirmacao_risco` — as duas últimas calculadas no servidor a partir de `status`+`validacao_status`, nunca recalculadas na tela
- No nível do kit: `prontas_sem_risco` e `reprovadas` (acessores já existentes no model desde o Plano 02, só expostos agora)
- A trava de tempo da validação (`encerrarValidacaoSeTravada()`, VAL-06) passou a rodar também dentro de `criativoKitStatus()` — antes só valia nos dois endpoints de aprovação; agora o polling em si já destrava um slot `pendente` há mais de 10 minutos
- `KitCriativosGrade.jsx`: mapa `VALIDACAO_UI` com rótulo/cor/ícone por estado (`pendente`→"validando automaticamente…", `aprovada`→texto discreto "sem problema", `reprovada`/`indisponivel`→alerta); bloco de alerta detalhado (mensagem do juiz + lista de problemas com gravidade) só para `reprovada`/`indisponivel`; aviso de topo quando `kit.reprovadas > 0`
- Fluxo de dois passos para aprovar imagem reprovada: 1º clique em "Aprovar mesmo assim" abre a pergunta explícita com o risco escrito; só o 2º clique ("Sim, usar esta imagem") chama `onAprovar(token, true)`. Estado local `confirmandoRisco` chaveado por token (`useState`), mesmo padrão de `motivos`
- `pode_aprovar`/`exige_confirmacao_risco` do servidor são o guarda do botão — nunca `slot.status === 'pronto'` isolado
- `PainelCriativosIa.jsx`: `aprovarSlot(token, confirmarRisco = false)` envia `{ confirmar_risco: true }` no corpo quando confirmado; cálculo de `disponiveis` para "Aprovar kit" passa a usar `kit.prontas_sem_risco ?? kit.prontas`
- 4 testes novos em `KitStatusValidacaoTest.php`: os 5+2 campos para os 4 estados + NULL; whitelist (ausência de `fidelidade`/`override`/`motivo_curto`/`prompt`/`truth`/`contexto`/`regenerar_motivos`, e que cada item de `validacao_problemas` só tem `gravidade`+`explicacao`); pendente há 11 min vira `indisponivel` já nesta chamada (efeito persistido no banco, não só na resposta); escopo de empresa continua barrando

## Task Commits

1. **Task 1: Campos de validação na whitelist do criativo.kit.status** — `fadaa259` (feat)
2. **Task 2: Alerta de risco na grade e aprovação com risco assumido (APROV-04)** — `8abc6e9f` (feat)

**Plan metadata:** (este commit, docs — ver `<final_commit>`)

## Files Created/Modified

- `app/Http/Controllers/MlbAnuncioController.php` — `criativoKitStatus()`: trava de tempo da validação + 5 campos por slot + 2 no kit, todos na whitelist fechada
- `tests/Feature/Phase162/KitStatusValidacaoTest.php` — 4 testes (molde de `AprovacaoComValidacaoTest`/`CriativoKitGeracaoTest`)
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` — `VALIDACAO_UI`, indicador curto + bloco de alerta por cartão, fluxo de dois passos para reprovada, aviso de topo
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` — `aprovarSlot(token, confirmarRisco)`, `disponiveis` usando `prontas_sem_risco`

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: (1) a contagem de "sem risco" conta dado legado (`validacao_status` nulo) como sem risco — comportamento fail-open já decidido no model desde o Plano 02, só verificado aqui pelo teste novo; (2) `tipo` ficou de fora da lista de chaves proibidas no NÍVEL DO SLOT porque já é um campo legítimo e anterior a esta fase (tipo do slot, ex. "hero") — a chave proibida com o mesmo nome só é vocabulário interno dentro de `validacao_problemas`, testada separadamente.

## Deviations from Plan

### Notas sobre os gates informais de grep do `<done>`

O bloco `<done>` da Task 2 lista `confirmar_risco = 1` e `prontas_sem_risco = 1` no `PainelCriativosIa.jsx`. A contagem real ficou em 2 e 4 respectivamente — não é possível satisfazer literalmente "=1" sem violar o próprio requisito funcional do mesmo plano: `confirmar_risco` precisa aparecer tanto na chamada condicional (`confirmarRisco ? { confirmar_risco: true } : {}`) quanto implicitamente na assinatura/uso da função; e `prontas_sem_risco` precisa fluir do servidor (`data.prontas_sem_risco`) para o estado local (`kit.prontas_sem_risco`) e só então ser lido no cálculo de `disponiveis` (`kit.prontas_sem_risco ?? kit.prontas`) — isso é, no mínimo, dois pontos de contato textual por natureza da linguagem (origem + uso), e a linha de atribuição `prontas_sem_risco: data.prontas_sem_risco` já contém o literal duas vezes por si só. Optei por manter a implementação natural e correta (que é exatamente o que o corpo da Task 2 pede, literalmente: `kit.prontas_sem_risco ?? kit.prontas`) em vez de contorcer o código para um grep artificial. Os outros gates do `<done>` (`validacao_mensagem`, `exige_confirmacao_risco`, `pode_aprovar` ≥ 1, e `window.axios` = 0 em `KitCriativosGrade.jsx`) bateram exatamente.

O gate "`veredito`/`fidelidade`/`eliminat` em texto de UI = 0" também merece nota: um grep cru por essas palavras no arquivo encontra 3 ocorrências, mas as 3 estão dentro de um comentário JSDoc que **documenta a própria regra** ("Nunca usar aqui o vocabulário interno do juiz ('veredito', 'fidelidade', 'eliminatório')...") — nunca em string renderizada ao operador. Conferido manualmente linha a linha; nenhuma dessas palavras aparece em JSX renderizado.

Nenhum outro desvio. Plano executado como escrito nos dois pontos funcionais (whitelist no backend, alerta + confirmação de dois passos no frontend).

## Issues Encountered

Durante a escrita do teste (a) de `KitStatusValidacaoTest`, a primeira versão assumia que `prontasSemRisco()` NÃO contaria o slot com `validacao_status` nulo (dado legado) como "sem risco", esperando 2 em um cenário de 5 estados (aprovada/reprovada/pendente/indisponivel/null). O teste falhou com valor real 3 — a leitura do código-fonte do model (`MlAnuncioCriativoKit::prontasSemRisco()`, Plano 02) confirmou que `orWhereNull('validacao_status')` é proposital (mesma disposição fail-open de `indisponivel`). Corrigido o teste para refletir o comportamento real e documentado do model, não uma suposição.

## User Setup Required

Nenhum.

## Self-Check: PASSED

- `app/Http/Controllers/MlbAnuncioController.php` (contém `validacao_mensagem`, `exige_confirmacao_risco`, `prontas_sem_risco`) — FOUND
- `resources/js/Pages/Mlb/components/KitCriativosGrade.jsx` (contém `validacao_mensagem`, `exige_confirmacao_risco`, `pode_aprovar`) — FOUND
- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` (contém `confirmar_risco`, `prontas_sem_risco`) — FOUND
- `tests/Feature/Phase162/KitStatusValidacaoTest.php` — FOUND (4 testes, verde)
- Commit `fadaa259` — FOUND em `git log`
- Commit `8abc6e9f` — FOUND em `git log`
- `npm run build` — concluído sem erro (48.43s)
- `git diff --stat resources/js/Pages/Mlb/AnunciarML.jsx` → vazio — CONFIRMADO
- `git diff --stat package.json package-lock.json composer.json composer.lock` → vazio — CONFIRMADO
- `git diff --stat` nos 4 arquivos/diretórios travados pela coordenação com a Fase 165 (`CreativeContextBuilder.php`, `ProductTruthBuilder.php`, `CreativePermissao.php`, `CreativePromptBuilder.php`, `app/Services/Publicador/`, `PubProduto*`, `MlbPublicador*`, JSX do Publicador) → vazio — CONFIRMADO
- `C:/xampp/php/php.exe artisan route:list --name=mlb.anuncios.criativo` → 11 rotas, idênticas às de antes — CONFIRMADO
- Suíte pinada (`Phase162|Phase161|Phase160|Quick261003L8o|GeminiImageProviderTest`) — 239 passed (1067 assertions), 0 falhas — CONFIRMADO (baseline anterior era 235; +4 dos testes novos desta onda)
- `git diff --diff-filter=D --name-only HEAD~1 HEAD` em ambos os commits desta onda → vazio (nenhuma deleção acidental) — CONFIRMADO

## Next Phase Readiness

- APROV-04/VAL-03 encerram o escopo funcional de UI da Fase 162. A onda 5 (162-05) é checkpoint humano e NÃO foi executada por este agente — fica para verificação visual na tela de verdade (confirmar que o alerta aparece ao lado da imagem, que "validando…" substitui o botão, e que o fluxo de dois passos funciona num navegador real).
- Nenhum bloqueio conhecido. A trava de coordenação com a Fase 165 permanece válida e sem incidente ao longo de toda a onda: nenhum arquivo do território do outro dev foi tocado, confirmado por `git diff --stat` vazio em todos os caminhos proibidos.
- `MlbAnuncioController.php` (o único ponto de atrito nomeado na nota de coordenação) recebeu só uma edição ADITIVA dentro de `criativoKitStatus()` — nenhuma linha de `criativoAprovar()`/`criativoKitAprovar()` (os dois métodos que o outro dev evitou editar) foi tocada por este plano.

---
*Phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e*
*Plan: 04*
*Completed: 2026-10-05*
