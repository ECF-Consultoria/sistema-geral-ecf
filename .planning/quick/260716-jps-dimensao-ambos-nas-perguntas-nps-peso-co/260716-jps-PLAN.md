---
quick_id: 260716-jps
slug: dimensao-ambos-nas-perguntas-nps-peso-co
date: 2026-07-16
status: in-progress
---

# Quick Task 260716-jps: Dimensão "Ambos" nas perguntas do modelo NPS

## Objetivo

Adicionar uma nova dimensão **"Ambos"** às perguntas do modelo NPS
(`/nps/configuracao`). Quando a dimensão de uma pergunta for "Ambos", o peso da
resposta deve contar **tanto para a nota do Analista quanto para a do
Estrategista** — o cliente responde uma única vez e o peso alimenta as duas
notas de pessoa.

## Contexto da lógica atual

- A `dimensao` da pergunta (`estrategista`/`analista`/`empresa`/`geral`) é a
  chave única de roteamento da nota.
- `NpsScoreCalculator::compute()` faz `SOMA(pesos WHERE dimensao=X) / Nº
  perguntas com peso da dimensão X`.
- `NpsSnapshotService::registrar()` cria uma linha de score por dimensão
  (estrategista/analista/empresa) e depois amarra o score à pessoa responsável
  via `nps_score_assignments` (mapa dimensão→role).

## Abordagem

"Ambos" NÃO é uma dimensão de score própria — é uma dimensão de **pergunta**
que alimenta as notas de `estrategista` e `analista`. Basta trocar o filtro de
igualdade (`= dimensao`) por pertencimento ao conjunto de dimensões-fonte
(`IN (dimensao, ambos)`) nos 3 pontos que fazem o cálculo cru. Nenhuma
migration (é só string), nenhuma linha de score "ambos", nenhum consumidor a
jusante muda (leem por dimensão de score ou por role).

## Tarefas

### T1 — Modelo `NpsTemplateQuestion`
- `files`: app/Models/NpsTemplateQuestion.php
- `action`: adicionar `const DIMENSAO_AMBOS = 'ambos'`; incluir em `DIMENSOES`
  e em `dimensoesLabels()` (`'ambos' => 'Ambos'`); adicionar helper
  `dimensoesFonte(string $scoreDimensao): array` que mapeia
  estrategista→[estrategista,ambos], analista→[analista,ambos], demais→[self].
- `verify`: `NpsTemplateQuestion::dimensoesFonte('estrategista')` retorna as 2.
- `done`: opção "Ambos" válida no CRUD e visível no select da UI.

### T2 — `NpsScoreCalculator`
- `files`: app/Services/Nps/NpsScoreCalculator.php
- `action`: `compute()` (SUM) e `contarPerguntasComPeso()` (divisor) usam
  `whereIn(..., NpsTemplateQuestion::dimensoesFonte($dimensao))`.
- `verify`: pergunta ambos entra no numerador e no divisor de estrategista E
  analista; invariante `score_sum/question_count == average_score` preservada.
- `done`: nota das duas pessoas inclui as perguntas "ambos".

### T3 — `NpsSnapshotService`
- `files`: app/Services/Nps/NpsSnapshotService.php
- `action`: `score_sum` da dimensão usa `whereIn(dimensoesFonte)` para bater com
  o `average_score` do calculator. `DIMENSOES` do snapshot permanece
  estrategista/analista/empresa (ambos NÃO vira linha de score).
- `verify`: `nps_response_scores` de estrategista/analista inclui pesos das
  perguntas ambos; empresa e assignments inalterados na estrutura.
- `done`: snapshot congelado correto; assignments de ambas as pessoas recebem a
  média que já contém "ambos".

### T4 — Mensagens dos FormRequests
- `files`: StoreNpsTemplateQuestionRequest.php, UpdateNpsTemplateQuestionRequest.php
- `action`: atualizar a mensagem `dimensao.in` para incluir "ambos".
- `done`: mensagem de erro coerente com a whitelist.

### T5 — Build
- `action`: `npm run build` (convenção do projeto).

## Fora de escopo
- Deploy (só com autorização explícita).
- Command histórico `NpsBackfillDivisorTextoLivre` (backfill de bug antigo; não
  faz parte do fluxo vivo e não havia "ambos" no histórico).
