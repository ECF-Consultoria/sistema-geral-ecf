---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 04
subsystem: renovacao
tags: [renovacao, orquestrador, job, agendador, hubspot, idempotencia]
requires: ["202-02", "202-03"]
provides:
  - "RenovacaoOrquestrador::sincronizar(ciclo, origem, userId, simulacao, liberarCriacao, justificativa): RenovacaoDecisao"
  - "SincronizarRenovacaoJob (fila high, afterCommit, lock por ciclo)"
  - "renovacao:verificar (agendado 07:35 America/Sao_Paulo)"
  - "renovacao:chave status|ligar|desligar|simulacao-ligar|simulacao-desligar"
key-files:
  created:
    - app/Services/Renovacao/RenovacaoOrquestrador.php
    - app/Jobs/SincronizarRenovacaoJob.php
    - app/Console/Commands/VerificarRenovacoes.php
    - app/Console/Commands/ChaveRenovacao.php
    - tests/Feature/Phase202/RenovacaoOrquestradorTest.php
    - tests/Feature/Phase202/RenovacaoComandosTest.php
  modified:
    - routes/console.php
requirements-completed: [REN-04, REN-07, REN-08]
completed: 2026-11-06
---

# Fase 202 Plano 04: Orquestração da renovação Summary

Orquestrador idempotente (política decide, orquestrador age), job por ciclo na fila `high` com lock, comando diário `renovacao:verificar` e comando de chaves `renovacao:chave`; a chave nasce desligada e a simulação é o padrão.

## Commits
- b7fecd7a8 — orquestrador + testes (13)
- ee05d91d6 — job, comandos, agendamento + testes (11)

## Decisões
- A tentativa remota (`criacao_iniciada_em`, status `criando`, tentativas+1) é gravada e commitada ANTES do POST; 429 devolve o estado anterior (incluindo `tentativas`), 400 permanente devolve `criacao_iniciada_em` e vai para `erro`, timeout/5xx/409 vão para `resultado_incerto` mantendo a marca.
- Em 429 o status restaurado é o anterior quando era `resultado_incerto`/`revisao_manual` (liberação manual que bate no limite não perde a trava); caso contrário `pendente_sincronizacao`.
- Falha de leitura preserva também `revisao_manual` (além de criando/incerto/tentativa remota) para não apagar a informação da revisão.
- Em simulação não há escrita remota nem mudança de status/extensão; só `ultima_decisao*`, `ultima_sincronizacao_em`, evento `simulacao` e correção local do término. A liberação manual (zerar tentativas + evento) só acontece fora de simulação.
- VINCULAR_POR_CORRELACAO reaplica a política uma vez com o negócio vinculado (usa o pipeline/etapa já lidos na busca).
- Conciliação de negócio aberto só completa a associação com a empresa (GET + PUT v4 idempotente); nunca PATCH de etapa/valor/dono.
- `renovacao:verificar` em modo real confere a property de correlação antes de tudo (ausente → `Log::error` + exit 1 apontando `renovacao:hubspot-preparar`).

## Desvios
- Critério de aceite `grep -c "\[Renovacao\]" RenovacaoOrquestrador.php >= 3` dá 1: o prefixo está centralizado no helper `log()` (todas as mensagens passam por ele), em vez de repetido literalmente. Comportamento coberto por teste com `Log::shouldReceive` (`[Renovacao]` em `error`). Sem desvio de regra 1-4.
- `Http::recorded` não registra requisição que estoura por timeout no fake; os testes contam os POSTs de criação por contador no próprio fake.

## Testes
`C:\xampp\php\php.exe artisan test tests/Feature/Phase202`: 97 passed (340 assertions). Novos: Orquestrador 13, Comandos 11.

## Known Stubs
Nenhum.

## Self-Check: PASSED
