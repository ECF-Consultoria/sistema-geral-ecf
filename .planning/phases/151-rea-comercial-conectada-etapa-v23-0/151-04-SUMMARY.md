---
phase: 151-rea-comercial-conectada-etapa-v23-0
plan: 04
subsystem: api
tags: [laravel, hubspot, webhook, artisan-command, backfill, eloquent]

# Dependency graph
requires:
  - phase: 151-03
    provides: "3 colunas aditivas nullable em companies (hubspot_owner_id, hubspot_owner_nome, data_venda), HubspotApiClient::fetchOwner(), HubspotOwnerResolver::resolverNome()"
provides:
  - "HubspotWebhookController::criarEmpresa() grava as 3 colunas no update final, atravessado pelos dois ramos (criação e match forte)"
  - "HubspotDealHandoffService::parseDataHubspot() elevado a public — rotina única de conversão de data do HubSpot, reusada pelo webhook e pelo backfill"
  - "hubspot:backfill-owner-venda — retroativo manual do acervo, dry-run por padrão, duas passagens (data_venda sem custo de API, owner com custo de API)"
  - "138-BACKFILL-OWNER-CONTAGENS.md — execução dry-run + --apply contra o MariaDB local, conferida por reconsulta SQL direta"
affects: [151-05, 151-06, 151-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Chave de config com fallback literal ($propsDeal['owner_id'] ?? 'hubspot_owner_id') para tolerar testes legados que sobrescrevem services.hubspot.props.deal com array parcial — mesmo padrão já usado por email_envio_contrato"
    - "Dry-run que evita custo de API: quando uma passagem de backfill tem custo de rede por natureza (fetchDeal por empresa), o modo sem --apply usa COUNT puro em vez de repetir o fetch — só a passagem estruturalmente gratuita (leitura de snapshot já persistido) itera de verdade em dry-run"

key-files:
  created:
    - app/Console/Commands/HubspotBackfillOwnerVenda.php
    - tests/Feature/Phase151/ComercOwnerDataVendaPersistidosTest.php
    - tests/Feature/Phase151/ComercBackfillOwnerVendaTest.php
    - .planning/phases/151-rea-comercial-conectada-etapa-v23-0/138-BACKFILL-OWNER-CONTAGENS.md
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Services/Hubspot/HubspotDealHandoffService.php

key-decisions:
  - "parseDataHubspot() elevado de private para public em HubspotDealHandoffService — nenhuma rotina de conversão de data do HubSpot paralela, nem no webhook nem no comando de backfill"
  - "Passagem 2 do backfill (owner) também busca closedate junto com a property de owner, como rede de segurança de data_venda para empresas com hubspot_deal_id mas sem hubspot_snapshot (registros anteriores à Fase 111) — decisão tomada durante a execução, dentro da letra do plano ('fetchDeal($id, [owner e closedate])'), sem inventar uma terceira passagem"
  - "Dry-run da Passagem 2 não dispara nenhum fetchDeal (usa COUNT puro) — decisão para preservar a garantia de 'dry-run sem custo de rede', já que essa passagem tem custo de API por natureza, diferente da Passagem 1"

patterns-established:
  - "Retroativo em duas passagens de custo assimétrico: a passagem sem custo de API sempre itera em chunkById mesmo em dry-run (barata); a passagem com custo de API só executa de verdade sob --apply, reportando só contagem em dry-run"

requirements-completed: []

# Metrics
duration: ~50min
completed: 2026-09-02
---

# Phase 151 Plan 04: Owner e data da venda persistidos (webhook + retroativo) Summary

**Webhook grava `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` no mesmo update dos dois ramos (criação e match forte), e `hubspot:backfill-owner-venda` preenche o acervo antigo em duas passagens (data_venda sem custo de API via snapshot, owner com custo de API via fetchDeal), dry-run por padrão, conferido por reconsulta SQL direta contra o MariaDB local.**

## Performance

- **Duration:** ~50 min
- **Completed:** 2026-09-02
- **Tasks:** 2 completed
- **Files modified:** 6 (2 modificados, 4 criados)

## Accomplishments

- `HubspotWebhookController::criarEmpresa()` agora grava `hubspot_owner_id`, `hubspot_owner_nome`
  e `data_venda` no MESMO `$company->update([...])` que já grava `hubspot_notas`/
  `hubspot_observacao`/`hubspot_snapshot` — o único ponto atravessado tanto pelo ramo de criação
  quanto pelo de match forte (`enriquecerEmpresaExistente()`), garantindo que uma empresa que já
  existia também recebe as três colunas, não só a recém-criada.
- Owner ausente (chave da property fora do payload), arquivado/removido (404) ou sem escopo OAuth
  (403) resolve para `null` sem interromper o processamento — nenhum `try/catch` novo escondendo
  regressão do próprio `HubspotOwnerResolver`/`fetchOwner()` (ambos já resilientes desde o 151-03).
- `HubspotDealHandoffService::parseDataHubspot()` elevado a `public` — o webhook e o comando de
  backfill compartilham a MESMA rotina de conversão de `closedate`, nunca um `Carbon::parse` novo
  e paralelo.
- `hubspot:backfill-owner-venda` cobre o acervo pré-existente: Passagem 1 lê `closedate` já
  persistido em `hubspot_snapshot.deal` (zero custo de API); Passagem 2 busca owner via
  `fetchDeal()` real por empresa com `hubspot_deal_id`, com `data_venda` como rede de segurança
  para empresas sem snapshot. Dry-run é o padrão; em dry-run a Passagem 2 não dispara nenhuma
  chamada HTTP (só `COUNT`). Empresa sem `hubspot_deal_id` nunca é candidata a owner e é contada
  à parte em `sem_deal_id`. Falha de fetch de um deal é logada e o laço continua.
- Executado dry-run + `--apply` contra o MariaDB local (`ecf_admin`, 180 empresas) e conferido por
  reconsulta SQL direta (`SELECT COUNT(*)`), nunca pelo stdout do próprio comando — ver
  `138-BACKFILL-OWNER-CONTAGENS.md`.

## Task Commits

Each task was committed atomically:

1. **Task 1: Webhook grava owner e data da venda nas colunas próprias** - `d57398b4` (feat)
2. **Task 2: Comando de retroativo com dry-run por padrão** - `ae87a6ec` (feat)

**Plan metadata:** (este commit, a seguir)

## Files Created/Modified

- `app/Http/Controllers/Api/HubspotWebhookController.php` - `criarEmpresa()` computa
  `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` (chave de config com fallback literal) e
  acrescenta ao update final já existente; import de `HubspotOwnerResolver`
- `app/Services/Hubspot/HubspotDealHandoffService.php` - `parseDataHubspot()` de `private` para
  `public`, com docblock explicando a reutilização pelo webhook e pelo comando de backfill
- `tests/Feature/Phase151/ComercOwnerDataVendaPersistidosTest.php` - 4 testes: empresa nova,
  match forte (empresa já existia), deal sem property de owner, owner 403
- `app/Console/Commands/HubspotBackfillOwnerVenda.php` - comando `hubspot:backfill-owner-venda`,
  duas passagens (`chunkById(100)`), dry-run por padrão, nunca toca o campo de estágio da máquina
  de estados
- `tests/Feature/Phase151/ComercBackfillOwnerVendaTest.php` - 6 testes: dry-run não grava, apply
  sem HTTP na passagem 1, apply com HTTP na passagem 2, `sem_deal_id`, falha isolada não aborta o
  laço, estágio nunca alterado
- `.planning/phases/151-rea-comercial-conectada-etapa-v23-0/138-BACKFILL-OWNER-CONTAGENS.md` -
  comandos executados, stdout literal e 5 `SELECT COUNT(*)` de reconsulta contra o MariaDB local;
  registra que a execução em produção depende de autorização explícita do usuário

## Decisions Made

- `parseDataHubspot()` elevado a `public` (não `static`) — mantém a mesma instância injetada via
  `app(HubspotDealHandoffService::class)`/DI do comando, sem introduzir estado global.
- A Passagem 2 do backfill busca `closedate` junto com a property de owner no mesmo `fetchDeal()`,
  usando o valor como rede de segurança de `data_venda` apenas quando a Passagem 1 não conseguiu
  (empresa com `hubspot_deal_id` mas sem `hubspot_snapshot` — registro anterior à Fase 111). Segue
  a letra do plano ("fetchDeal($id, [owner e closedate])") sem criar uma terceira passagem.
- Dry-run da Passagem 2 não dispara `fetchDeal()` nenhum (usa `COUNT` puro): como essa passagem
  tem custo de API por natureza (diferente da Passagem 1, estruturalmente gratuita), um dry-run
  que ainda gastasse a cota de requisições deixaria de ser prévia segura.

## Deviations from Plan

None de comportamento — plano executado como escrito. Dois ajustes de implementação, ambos
dentro do escopo do Rule 3 (desbloquear a task) e documentados como decisões acima:

1. `parseDataHubspot()` precisou ter a visibilidade elevada de `private` para `public` — o plano
   exige reusar "a mesma rotina" a partir de dois chamadores externos à classe (webhook e
   comando), o que é impossível com o método `private` original.
2. A Passagem 2 do backfill ganhou o fallback de `data_venda` a partir do `closedate` buscado
   junto com o owner — não estava explícito como comportamento obrigatório no `<action>`, mas o
   argumento de `fetchDeal()` sugerido pelo próprio plano já incluía a property de `closedate`,
   e deixá-la sem uso seria uma requisição HTTP paga e descartada. Coberto por teste
   (`test_apply_preenche_hubspot_owner_para_empresa_com_deal_id` assert também `data_venda`).

## Issues Encountered

Nenhum bloqueio. O único ponto de atenção: o dataset local (`ecf_admin`, MariaDB) tem 180
empresas mas **zero** com `hubspot_deal_id` preenchido e apenas 1 com `hubspot_snapshot` (sem
`closedate` válido) — a execução real contra o MariaDB local não exercitou nenhuma escrita de
verdade. Isso é esperado (dataset de desenvolvimento, sem histórico de webhooks reais de deals
fechados) e não invalida a prova: os testes automatizados (`Http::fake()`) cobrem os cenários de
escrita real que o dataset local não tem.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano. A execução do comando de
backfill contra a VPS de produção fica registrada como pendência explícita em
`138-BACKFILL-OWNER-CONTAGENS.md`, condicionada a autorização explícita do usuário e à medição
real da property de owner (responsabilidade do plano 151-09, já registrada em
`138-HUBSPOT-MEDICOES.md`).

## Next Phase Readiness

As três colunas (`hubspot_owner_id`, `hubspot_owner_nome`, `data_venda`) agora são preenchidas
tanto no fluxo novo (webhook, os dois ramos) quanto disponibilizam um caminho de preenchimento do
acervo antigo (comando manual, ainda não executado em produção). Os planos 151-05/151-06
(listagens Contrato e Entrada) podem ler essas colunas com confiança de que owner ausente é
sempre `null` normal, nunca um erro de processamento. `COMERC-02` **não** foi marcado como
completo neste plano — ele é multi-plano e só fecha quando as listagens (151-05/151-06)
efetivamente exibirem os 8 campos do §2, incluindo estes dois.

---
*Phase: 151-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

Todos os 6 arquivos criados/modificados confirmados presentes no disco; os 2 commits de task
(`d57398b4`, `ae87a6ec`) confirmados em `git log --oneline --all`. Nenhum item faltante.
