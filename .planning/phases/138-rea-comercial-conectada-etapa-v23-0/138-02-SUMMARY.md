---
phase: 138-rea-comercial-conectada-etapa-v23-0
plan: 02
subsystem: infra
tags: [hubspot, config, checkpoint-humano, owner-comercial]

# Dependency graph
requires:
  - phase: 138-01
    provides: baseline de testes pré-migration de companies
provides:
  - "Nome interno da property de owner do deal ASSUMIDO (não medido): hubspot_owner_id, com autorização explícita do usuário"
  - "Estado do escopo OAuth crm.objects.owners.read registrado como NÃO CONFIRMADO"
  - "Duas verificações contra a conta HubSpot real carregadas como pendência obrigatória do plano 138-09 (pós-primeiro-deploy)"
affects: [138-03, 138-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Medição contra API externa que falhou por credencial ausente vira checkpoint bloqueante, nunca suposição silenciosa — resolvida só com autorização humana explícita e registrada por escrito"

key-files:
  created: []
  modified:
    - .planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-HUBSPOT-MEDICOES.md

key-decisions:
  - "hubspot_owner_id adotado como default assumido (não medido) para HUBSPOT_PROP_DEAL_OWNER_ID no plano 138-03, com autorização explícita do usuário em 2026-09-02, condicionada a medição real na VPS no deploy desta fase"
  - "escopo crm.objects.owners.read fica NÃO CONFIRMADO — usuário sem acesso ao painel HubSpot nesta sessão; verificação carregada para o plano 138-09"

patterns-established: []

requirements-completed: [COMERC-02]

# Metrics
duration: 6min (sessão de continuação — Task 1 foi executada em sessão anterior, commit e888f06b)
completed: 2026-09-02
---

# Phase 138 Plan 02: Medições HubSpot (owner do deal) Summary

**Nome interno da property de owner ASSUMIDO como `hubspot_owner_id` por autorização explícita do usuário — NÃO medido contra a conta real; escopo `crm.objects.owners.read` fica NÃO CONFIRMADO; ambas as verificações reais ficam carregadas como pendência obrigatória do plano 138-09, a rodar na VPS no deploy desta fase.**

## Performance

- **Duração desta sessão (continuação):** ~6 min
- **Iniciado (Task 1, sessão anterior):** commit `e888f06b` em 2026-09-02T15:57:52-03:00
- **Concluído (Task 2+3, esta sessão):** commit `51fc94e8` em 2026-09-02T16:03:51-03:00
- **Tasks:** 3/3 (1 auto + 2 checkpoint:human-verify bloqueantes)
- **Arquivos modificados:** 1 (`138-HUBSPOT-MEDICOES.md`)

## Accomplishments

- Tentativa real de medição contra a conta HubSpot da ECF via `hubspot:inspect-properties --objects=deals` (Task 1) — falhou com HTTP 401 por ausência de `HUBSPOT_ACCESS_TOKEN` no `.env` deste worktree; erro registrado por escrito, exit code (sempre 0 por desenho) explicado, nenhum token logado.
- Checkpoint da Task 2 resolvido: usuário autorizou explicitamente usar `hubspot_owner_id` como default assumido, com a condição adicional de que a medição real seja preparada para rodar na VPS no momento do deploy — comando literal, o que conferir na saída e a correção via `.env` (sem mudança de código) documentados em `138-HUBSPOT-MEDICOES.md`.
- Checkpoint da Task 3 resolvido: usuário respondeu "sem acesso agora" ao painel HubSpot; escopo `crm.objects.owners.read` registrado como `NÃO CONFIRMADO`, com a consequência declarada (403 → `null` → `companies.hubspot_owner_nome` sempre vazio, sem erro visível).
- Nova seção "Pendências obrigatórias para a VPS (item do plano 138-09)" criada no artefato, cobrindo as duas verificações que precisam rodar contra a conta real depois do primeiro deploy.

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Rodar hubspot:inspect-properties e capturar a property de owner** — `e888f06b` (docs) — sessão anterior
2. **Task 2 + Task 3: Confirmação humana do nome interno + do escopo OAuth** — `51fc94e8` (docs) — esta sessão. As duas tasks são checkpoints bloqueantes que tocam o mesmo arquivo único; commitadas juntas por decisão do orquestrador de continuação, com a mensagem nomeando ambas.

**Metadados do plano:** commit desta seção (SUMMARY + STATE + ROADMAP), ver abaixo.

## Files Created/Modified
- `.planning/phases/138-rea-comercial-conectada-etapa-v23-0/138-HUBSPOT-MEDICOES.md` — Task 1 registrou a tentativa de medição falha (401); esta sessão adicionou a resolução dos dois checkpoints e a seção de pendências para a VPS.

## Decisions Made

- **`property_owner_nome_interno: hubspot_owner_id` — ASSUMIDO, não medido.** O usuário autorizou explicitamente essa suposição sabendo do risco (property ausente na conta real vira `null` silencioso no payload, replicando o modo de falha do precedente `quick 260805-eqk`). A condição imposta pelo usuário é que a medição real aconteça na VPS no deploy desta fase, e isso foi registrado como pendência obrigatória do plano 138-09, com o comando exato, o que conferir e a correção via `HUBSPOT_PROP_DEAL_OWNER_ID` no `.env`.
- **`escopo_owners_read: NÃO CONFIRMADO`.** O usuário não tinha acesso ao painel HubSpot nesta sessão. Verificação carregada para o plano 138-09, a ser feita depois do primeiro deploy.
- Nenhuma linha de código, config ou migration foi tocada por este plano — confirmado por `git status --porcelain app/ config/ database/ resources/` vazio.

## Deviations from Plan

None - plano executado exatamente como escrito. As duas respostas do usuário aos checkpoints correspondem a opções já previstas no `how-to-verify` de cada task (Task 2 opção "b"; Task 3 opção "sem acesso"), com a única adição sendo a condição explícita do usuário de preparar a medição real para a VPS — registrada textualmente conforme pedido, não é uma mudança de escopo do plano.

## Issues Encountered

Nenhum problema técnico. O único "issue" é epistêmico e está deliberadamente destacado, não escondido: **o valor `hubspot_owner_id` que o plano 138-03 vai usar como default NÃO foi medido contra a conta HubSpot real da ECF** — foi assumido com autorização explícita do usuário porque a credencial não existia no `.env` local. Da mesma forma, **o escopo `crm.objects.owners.read` não está confirmado**. Ambas as lacunas ficam registradas como pendência obrigatória do plano 138-09 (checkpoint final da fase, pós-primeiro-deploy).

## User Setup Required

None - nenhuma configuração de serviço externo é necessária para ESTE plano. O plano 138-09 (futuro) vai exigir acesso ao `HUBSPOT_ACCESS_TOKEN` de produção na VPS e ao painel de Scopes do Private App HubSpot para fechar as duas pendências carregadas aqui.

## Next Phase Readiness

- O plano 138-03 está desbloqueado para fixar `HUBSPOT_PROP_DEAL_OWNER_ID` com default `hubspot_owner_id` em `config/services.php`, ciente de que é um valor assumido, não medido.
- **Bloqueio para o plano 138-09:** antes de considerar a Fase 138 encerrada, rodar `php artisan hubspot:inspect-properties --objects=deals` na VPS com o token de produção e conferir o escopo `crm.objects.owners.read` no painel HubSpot — os dois passos e as ações corretivas estão documentados em `138-HUBSPOT-MEDICOES.md`, seção "Pendências obrigatórias para a VPS".

---
*Phase: 138-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-02*
