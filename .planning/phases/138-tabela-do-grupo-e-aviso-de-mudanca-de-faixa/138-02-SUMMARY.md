---
phase: 138-tabela-do-grupo-e-aviso-de-mudanca-de-faixa
plan: 02
subsystem: fechamento-mensal / notificações
tags: [fechamento, notificacoes, idempotencia, faixa-faturamento]
dependency-graph:
  requires: []
  provides:
    - "notificado_em/notificado_faixa_ordem em fechamento_snapshots e fechamento_grupo_snapshots"
    - "Categoria::FAIXA_ALTERADA"
    - "App\\Notifications\\FaixaAlteradaNotification"
    - "Rótulo 'Mudança de faixa' em Notificacoes/Index.jsx"
  affects:
    - "138-05 (FechamentoFaixaNotifier — dispara usando esta infraestrutura)"
tech-stack:
  added: []
  patterns:
    - "Molde MetaAtingidaNotification copiado literal para FaixaAlteradaNotification (automática, sem autor, sem deeplink)"
    - "Duas colunas de idempotência (timestamp + valor comparável) em vez de uma só, mesmo padrão de goal_results"
key-files:
  created:
    - database/migrations/2026_09_03_120002_add_notificado_faixa_aos_fechamento_snapshots.php
    - app/Notifications/FaixaAlteradaNotification.php
    - tests/Feature/Phase138/Phase138AvisoFaixaSchemaTest.php
  modified:
    - app/Models/FechamentoSnapshot.php
    - app/Models/FechamentoGrupoSnapshot.php
    - app/Notifications/Categoria.php
    - resources/js/Pages/Notificacoes/Index.jsx
decisions:
  - "D-02: categoria própria FAIXA_ALTERADA + subclasse de Notification + rótulo na UI, entregues juntos (exigência do docblock do enum)"
  - "D-03: duas colunas de idempotência (notificado_em + notificado_faixa_ordem), não uma só — a segunda distingue 'já avisei' de 'avisei ISTO', permitindo re-aviso quando o Refazer corrige um erro real e move a empresa de faixa"
metrics:
  duration: "~40min"
  completed: 2026-09-03
---

# Phase 138 Plan 02: Colunas de idempotência + categoria/Notification/rótulo do aviso de mudança de faixa Summary

Infraestrutura (sem disparo) do aviso de mudança de faixa: duas colunas de idempotência nas tabelas
de snapshot do fechamento, a categoria `faixa_alterada` no enum, a `FaixaAlteradaNotification` e o
rótulo "Mudança de faixa" na tela de notificações.

## O que foi entregue

**Tarefa 1 — Colunas de idempotência.** Migration
`2026_09_03_120002_add_notificado_faixa_aos_fechamento_snapshots` adiciona `notificado_em`
(timestamp nullable) e `notificado_faixa_ordem` (unsignedSmallInteger nullable) em
`fechamento_snapshots` e `fechamento_grupo_snapshots`, com guard `Schema::hasColumn` (idempotente em
rerun) e `down()` simétrico. Nenhuma FK, nenhum índice novo — sem risco dos erros 1059/1830 do
MariaDB. Os dois models (`FechamentoSnapshot`, `FechamentoGrupoSnapshot`) expõem as colunas em
`$fillable`/`$casts`, com comentário explicando que só o notificador do plano 05 escreve nelas.

**Tarefa 2 — Categoria, Notification e rótulo.** `Categoria::FAIXA_ALTERADA = 'faixa_alterada'` no
enum. `FaixaAlteradaNotification` copia a forma exata de `MetaAtingidaNotification`: construtor
enxuto `(titulo, mensagem, ?meta)`, categoria fixa, `autorUserId: null`, `url: null`. Em
`Notificacoes/Index.jsx`, chave `faixa_alterada` no `CATEGORIA_META` com rótulo "Mudança de faixa",
ícone `ArrowUpDown` (lucide-react) e cor `text-amber-400` — copy sem jargão interno.

**Tarefa 3 — Teste de schema e idempotência.** `Phase138AvisoFaixaSchemaTest` (4 casos): colunas
existem/aceitam null; **prova central de D-03** — gravar `notificado_em`/`notificado_faixa_ordem`
numa linha e rodar `FechamentoSnapshotWriter::sync()` de novo para a mesma competência (com motivo,
simulando "Refazer fechamento") preserva as duas colunas, porque o payload montado pelo comando
nunca as inclui; payload de `FaixaAlteradaNotification` com as 6 chaves canônicas e
`categoria = faixa_alterada`; trava de arquivo confirmando que `Notificacoes/Index.jsx` contém o
rótulo (projeto sem test runner de JS).

## Deviations from Plan

None — plano executado exatamente como escrito. As três peças da Tarefa 2 foram entregues juntas,
como o docblock do enum exige, e a `npm run build` rodou sem erro.

## Verificação

- `Categoria::FAIXA_ALTERADA->value` = `faixa_alterada`; `FaixaAlteradaNotification` serializa com
  `categoria = faixa_alterada` (confirmado via `artisan tinker`).
- `npm run build`: sucesso (`✓ built in 19.57s`).
- `Phase138AvisoFaixaSchemaTest`: **4 testes / 15 asserções / 0 falhas**.
- Gate `Phase122|Phase136|Phase137` (isolado, sem Fase 138): **241 testes / 1220 asserções / 0
  falhas** — idêntico ao baseline medido antes deste plano, sem regressão.
- Gate combinado `Phase122|Phase136|Phase137|Phase138` (inclui os testes do plano paralelo 138-01,
  que terminou durante esta execução): **252 testes / 1304 asserções / 0 falhas**.

## Commits

- `d248b9d5` — feat(138-02): coluna de idempotencia do aviso de mudanca de faixa
- `f581e620` — feat(138-02): categoria, notification e rotulo de mudanca de faixa
- `c3e5f59f` — test(138-02): schema e idempotencia do aviso de mudanca de faixa

## Known Stubs

Nenhum. Este plano é puramente infraestrutural — não há dado renderizado para o usuário final além
do rótulo na tela de notificações, que já é funcional (aparece assim que uma
`FaixaAlteradaNotification` real for disparada pelo plano 05).

## Threat Flags

Nenhum novo — a única superfície nova (payload da notificação) já está registrada e mitigada no
`<threat_model>` do próprio plano (T-138-03, T-138-04, T-138-05), sem disposição pendente.

## Self-Check: PASSED

- `database/migrations/2026_09_03_120002_add_notificado_faixa_aos_fechamento_snapshots.php` — FOUND
- `app/Notifications/FaixaAlteradaNotification.php` — FOUND
- `tests/Feature/Phase138/Phase138AvisoFaixaSchemaTest.php` — FOUND
- Commit `d248b9d5` — FOUND
- Commit `f581e620` — FOUND
- Commit `c3e5f59f` — FOUND
