---
quick_id: 260716-jps
slug: dimensao-ambos-nas-perguntas-nps-peso-co
date: 2026-07-16
status: complete
commit: 4e4a34ad
---

# Quick Task 260716-jps — Dimensão "Ambos" nas perguntas do modelo NPS

## O que foi feito

Adicionada a dimensão **"Ambos"** às perguntas do modelo NPS. Quando a dimensão
de uma pergunta é "Ambos", o peso da resposta passa a contar **tanto para a nota
do Analista quanto para a do Estrategista** — o cliente responde uma única vez.

## Decisão de arquitetura

"Ambos" **não** é uma dimensão de score própria. `NpsSnapshotService::DIMENSOES`
(as dimensões que geram linha em `nps_response_scores` e `role` em
`nps_score_assignments`) continua sendo `estrategista`/`analista`/`empresa`.
"Ambos" é uma **dimensão-fonte**: a pergunta salva `dimensao = 'ambos'`, mas o
peso é injetado nas notas de estrategista e analista via o novo helper
`NpsTemplateQuestion::dimensoesFonte()`.

Isso mantém a mudança mínima e sem migration: bastou trocar o filtro cru
`= $dimensao` por `whereIn(dimensoesFonte($dimensao))` nos 3 pontos que fazem o
cálculo bruto. A dimensão `empresa` (meta da empresa via `CalculateGoalResults`)
**não** absorve "ambos" — de propósito (meritocracia da empresa intacta).

## Arquivos alterados (commit 4e4a34ad)

| Arquivo | Mudança |
|---|---|
| `app/Models/NpsTemplateQuestion.php` | `const DIMENSAO_AMBOS`; incluída em `DIMENSOES` + `dimensoesLabels()` (`'Ambos (Analista e Estrategista)'`); novo helper `dimensoesFonte()` |
| `app/Services/Nps/NpsScoreCalculator.php` | `compute()` (SUM) e `contarPerguntasComPeso()` (divisor) usam `whereIn(dimensoesFonte)` |
| `app/Services/Nps/NpsSnapshotService.php` | `score_sum` congelado usa `whereIn(dimensoesFonte)` — bate com o `average_score` |
| `StoreNpsTemplateQuestionRequest.php` / `UpdateNpsTemplateQuestionRequest.php` / `PreviewNpsTemplateRequest.php` | mensagem `dimensao.in` inclui "ambos" |

## Consumidores que NÃO mudaram (verificados)

- `DesempenhoScoreService`, `PerformanceController` → leem `nps_score_assignments`
  por `role` / usam `compute($response, $dim)` com `$dim` = estrategista|analista
  (herdam "ambos" automaticamente).
- `DashboardController::avgNotaDimensao` → chama `compute()` por dimensão de score.
- `CalculateGoalResults` → usa `empresa` (não absorve "ambos", correto).
- UI `QuestionEditor.jsx` → select gerado de `Object.entries(dimensoesLabels)`
  (opção "Ambos" aparece sozinha após o backend adicionar o label).

## Verificação

- `NpsTemplateQuestion::dimensoesFonte()` via tinker: estrategista→[estrategista,ambos],
  analista→[analista,ambos], empresa→[empresa]. ✓
- `php -l` nos 6 arquivos: sem erros. ✓
- `npm run build`: verde (20.9s). ✓
- Invariante `score_sum/question_count == average_score` preservada (mesma fonte
  `dimensoesFonte` nos 3 pontos). ✓

## Pendências / não feito

- **Deploy:** não executado (aguarda autorização explícita do usuário).
- **Checkpoint visual em prod:** o select "Ambos" só rende com dados reais de NPS
  (banco local com MariaDB corrompido / sem token). Validar em prod após deploy.
- Command histórico `NpsBackfillDivisorTextoLivre` deixado como está (backfill de
  bug antigo; não faz parte do fluxo vivo, e não havia "ambos" no histórico).
