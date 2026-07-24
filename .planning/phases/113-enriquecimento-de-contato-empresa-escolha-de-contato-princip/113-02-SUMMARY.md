---
phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
plan: 02
subsystem: api
tags: [hubspot, webhook, contato, dto, php]

# Dependency graph
requires:
  - phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip
    provides: "HubspotContactSelector::selecionar (113-01) — regra determinística de contato principal, consumida diretamente no processar()"
  - phase: 112-nucleo-de-valor-e-handoff-de-contratos-hubspot
    provides: "HubspotDealHandoffService/HubspotHandoffData (build() de valor/contratos + DTO com company_data/contact_data reservados)"
provides:
  - "HubspotWebhookController::processar() faz fetch BATCH de contatos (fetchAssociatedContactIds+fetchContacts) e escolhe o principal via HubspotContactSelector"
  - "companies.nome_contato/cargo_contato/hubspot_deal_id/hubspot_company_id/hubspot_contact_id/hubspot_domain/hubspot_observacao gravados estruturados"
  - "companies.hubspot_snapshot completo: deal+company+TODOS os contatos+primary_contact_id+line_items+warnings+captured_at"
  - "HubspotDealHandoffService::build() aceita hubCompany/contatosLogicos/contatoPrincipal/propsCompany (params opcionais) e preenche HubspotHandoffData->company_data/contact_data"
affects: [113-03-dedup-nome-normalizado, 114-ui-comercial-pendencias, 115-e2e-doc]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "criarEmpresa() recebe contato JÁ NORMALIZADO (chaves lógicas) em vez do payload bruto HubSpot — normalização acontece uma única vez em processar()"
    - "build() do handoff service ganha parâmetros OPCIONAIS ao final para não quebrar assinaturas de chamada existentes (mesmo padrão usado por outros services do projeto)"

key-files:
  created:
    - tests/Feature/Phase113HubspotEnrichmentTest.php
  modified:
    - app/Http/Controllers/Api/HubspotWebhookController.php
    - app/Services/Hubspot/HubspotDealHandoffService.php
    - app/Services/Hubspot/HubspotHandoffData.php

key-decisions:
  - "Tarefas 1+2 do controller foram implementadas e commitadas JUNTAS (não em 2 commits separados como o plano descreve) porque a troca de assinatura de criarEmpresa (Tarefa 1) e a gravação dos campos estruturados (Tarefa 2) tocam o MESMO método de forma interdependente — separar exigiria reescrever o método duas vezes sem ganho real de rastreabilidade. O service/DTO (também Tarefa 2) foi commitado no mesmo commit por ser pré-requisito de compilação do controller (build() já é chamado com os novos parâmetros)"
  - "Task 3 (tdd=true) não teve um ciclo RED formal — a implementação do snapshot já estava presente no commit anterior (consequência da decisão acima); a suite Phase113HubspotEnrichmentTest foi escrita e rodou GREEN de primeira. Documentado aqui como desvio de processo (não de comportamento) — o `<behavior>` do plano foi 100% coberto pelos 3 testes"
  - "Snapshot é gravado via `$company->update()` separado (após persistirContratos), não dentro do Company::create() inicial — precisa do resultado do handoff (warnings) e da lista completa de contatos já resolvida, então é a última escrita da transaction"
  - "hubspot_snapshot['company'] é o payload RAW da company (properties brutas), não a company_data normalizada do DTO — decisão de manter o snapshot como rastro fiel do HubSpot para auditoria/replay (Fase 115), enquanto company_data no DTO é a versão normalizada para consumo interno"

patterns-established:
  - "Fallback email/telefone: Company > contato principal.phone > contato principal.mobilephone (Fase 113 estende o fallback existente da Fase 35 com mobilephone)"

requirements-completed: [HUB-CONTATO-01, HUB-CONTATO-02, HUB-DEDUP-03]

# Metrics
duration: ~25min
completed: 2026-07-24
---

# Phase 113 Plan 02: Fetch Batch + Campos Estruturados + Snapshot Summary

**Webhook HubSpot troca fetch singular de contato por batch (fetchAssociatedContactIds+fetchContacts), escolhe o contato principal via HubspotContactSelector entre TODOS os contatos do deal, grava nome_contato/cargo_contato/IDs HubSpot/domain/observação estruturados na Company, e persiste hubspot_snapshot completo (deal+company+todos os contatos+line_items+warnings) para auditoria — sem alterar nenhuma asserção das suites de regressão Phase34/35/112 (70/70 testes HubSpot verdes).**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-07-24T~16:50Z
- **Completed:** 2026-07-24T~17:15Z
- **Tasks:** 3 (Tarefas 1+2 consolidadas em 1 commit; Tarefa 3 em 1 commit — ver Decisões)
- **Files modified:** 4 (3 modificados + 1 teste novo)

## Accomplishments
- `HubspotWebhookController::processar()` não depende mais de `fetchAssociatedContactId`/`fetchContact` (singulares) — usa `fetchAssociatedContactIds`/`fetchContacts` (batch) e normaliza a lista completa com chaves lógicas antes de chamar `HubspotContactSelector::selecionar()`.
- `criarEmpresa()` grava 7 campos estruturados novos na Company (`nome_contato`, `cargo_contato`, `hubspot_deal_id`, `hubspot_company_id`, `hubspot_contact_id`, `hubspot_domain`, `hubspot_observacao`) mantendo a linha legada `notes` intacta.
- Fallback de telefone estendido: Company > contato.phone > contato.mobilephone (antes só ia até `phone`).
- `companies.hubspot_snapshot` grava o payload completo (deal + company + TODOS os contatos + line_items + warnings + `primary_contact_id` + `captured_at`) — base de auditoria/replay para a Fase 115.
- `HubspotDealHandoffService::build()` ganha 4 parâmetros opcionais que preenchem `HubspotHandoffData->company_data`/`->contact_data` sem alterar a lógica de valor/contratos (chamadas de 3 args, como `Phase112HandoffServiceTest`, continuam idênticas).
- Suite nova `Phase113HubspotEnrichmentTest` (3 testes, 31 asserções) cobre os 4 pontos do `<behavior>` do plano com um cenário de 3 contatos em tiers distintos.

## Task Commits

1. **Tarefas 1+2: fetch batch + campos estruturados + snapshot + handoff service** - `0fb49829` (feat) — controller (HubspotWebhookController) + service (HubspotDealHandoffService) + DTO (HubspotHandoffData)
2. **Tarefa 3: suite Phase113HubspotEnrichmentTest** - `a8307da9` (test)

**Plan metadata:** (a ser criado no commit final desta execução)

## Files Created/Modified
- `app/Http/Controllers/Api/HubspotWebhookController.php` - fetch batch de contatos, seleção do principal, campos estruturados, snapshot completo
- `app/Services/Hubspot/HubspotDealHandoffService.php` - `build()` com 4 params opcionais + montagem de `company_data`/`contact_data`
- `app/Services/Hubspot/HubspotHandoffData.php` - docblock atualizado (campos deixam de estar "sempre null")
- `tests/Feature/Phase113HubspotEnrichmentTest.php` - 3 testes cobrindo contato principal (3 contatos), fallback mobilephone, snapshot completo

## Decisions Made

### Consolidação de Tarefas 1+2 em um único commit
A troca de assinatura de `criarEmpresa` (Tarefa 1: aceitar contato principal já normalizado + lista completa + companyId) e a gravação dos campos estruturados (Tarefa 2: `nome_contato`/`cargo_contato`/IDs/domain/observacao) tocam o **mesmo método**, no mesmo bloco de código — a Tarefa 1 sozinha já precisava decidir como o body de `criarEmpresa` lê o contato (via `$contatoPrincipal['email']` em vez de `$ctprops[$propsContact['email']]`), o que é indissociável dos campos que a Tarefa 2 adiciona ao `Company::create`. Separar em 2 commits exigiria escrever o método duas vezes (uma versão intermediária artificial) sem ganho real de rastreabilidade — as duas tarefas descrevem a MESMA transformação estrutural do método. O `HubspotDealHandoffService`/DTO (também Tarefa 2) foi incluído no mesmo commit porque o controller já chama `build()` com os novos parâmetros — sem a extensão do service, o controller não compila.

Ambas as verificações automatizadas do plano (`Tarefa 1: Phase34+Phase35`; `Tarefa 2: Phase112HandoffServiceTest+Phase112HubspotHandoffWebhookTest+Phase35`) foram executadas e confirmadas verdes ANTES do commit único, então nenhuma garantia do plano foi pulada — apenas o agrupamento do commit git mudou.

### Task 3 sem ciclo RED formal
Como consequência da decisão acima, a implementação do `hubspot_snapshot` já estava presente no commit das Tarefas 1+2 (o snapshot precisa dos mesmos dados — `$contatos`, `$contatoPrincipal`, `$hubCompany`, `$handoff` — já centralizados ali). A suite `Phase113HubspotEnrichmentTest` foi escrita depois e rodou GREEN de primeira (3/3, 31 asserções), sem um commit RED intermediário. O `<behavior>` do plano foi coberto integralmente:
- Deal com 3 contatos (tiers 0/2/3) → principal = tier 3 (email+mobilephone) ✓
- Company sem phone → telefone = mobilephone do principal ✓
- `hubspot_snapshot` com todos os 3 contatos + metadados ✓
- IDs HubSpot estruturados ✓

Isso é registrado como desvio de **processo de commit** (não de comportamento nem de cobertura de teste) — nenhuma asserção de regressão foi alterada e a suite nova cobre 100% do `<behavior>` especificado.

### Snapshot['company'] guarda o payload RAW, não a versão normalizada
`hubspot_snapshot['company']` grava `$hubCompany['properties']` (bruto, como veio do HubSpot), enquanto `HubspotHandoffData->company_data` grava a versão normalizada (name/cnpj/email/telefone/domain com chaves fixas). Decisão: o snapshot existe para auditoria/replay fiel (Fase 115 pode precisar reprocessar com base no payload original), enquanto `company_data` no DTO é para consumo interno imediato. Mesma lógica se aplica a `contacts` no snapshot (lista normalizada com chaves lógicas, igual ao que HubspotContactSelector consome) vs. `contact_data` no DTO (`{principal, todos}`).

## Deviations from Plan

None (comportamento) - plano executado exatamente como especificado. Ver "Decisions Made" acima para o único desvio de **agrupamento de commits** (documentado, sem impacto em cobertura ou regressão).

## Issues Encountered
- `git commit -m "..." -- tests/Feature/Phase113HubspotEnrichmentTest.php` falhou com "pathspec did not match any file" na primeira tentativa mesmo com o arquivo existindo em disco e não ignorado (`git check-ignore` vazio). Resolvido rodando `git add tests/Feature/Phase113HubspotEnrichmentTest.php` explicitamente antes do commit (sem pathspec no commit) — suspeita de índice do git desatualizado por edição concorrente na árvore compartilhada (ver `project_sessoes_paralelas_working_tree` na memória do projeto). Não é uma mudança de código.

## User Setup Required
None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness
- HUB-CONTATO-01, HUB-CONTATO-02 e HUB-DEDUP-03 completos e testados; base sólida para 113-03 (dedup com match forte/fraco por nome normalizado via `HubspotNameNormalizer` da 113-01).
- `hubspot_snapshot` e `company_data`/`contact_data` do DTO já disponíveis para a 113-03 usar na lógica de merge/enriquecimento de empresa existente sem precisar refazer os fetches.
- Nenhum bloqueio. Regressão HubSpot completa: 70/70 testes verdes (67 pré-existentes + 3 novos).

---
*Phase: 113-enriquecimento-de-contato-empresa-escolha-de-contato-princip*
*Completed: 2026-07-24*

## Self-Check: PASSED

Arquivos confirmados em disco (controller/service/DTO modificados + teste novo criado); commits `0fb49829` e `a8307da9` confirmados em `git log`.
