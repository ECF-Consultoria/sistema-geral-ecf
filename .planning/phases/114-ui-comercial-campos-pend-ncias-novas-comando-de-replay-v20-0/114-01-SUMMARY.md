---
phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0
plan: 01
subsystem: api
tags: [hubspot, laravel, comercial, pendencias, inertia]

# Dependency graph
requires:
  - phase: 111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam
    provides: "colunas hubspot_* em companies/contratos_servico (nullable, fillable)"
  - phase: 112-hubspotvalueresolver-n-cleo-mensal-anual
    provides: "hubspot_valor_original/normalizado_mensal/confidence/warning gravados de verdade"
  - phase: 113-enriquecimento-contato-empresa-dedup
    provides: "nome_contato/cargo_contato/hubspot_observacao/IDs + hubspot_snapshot['warnings'] + payload['possivel_duplicidade']"
provides:
  - "Payload da listagem /comercial/empresas/listagem enriquecido com contato/observação/IDs HubSpot por empresa"
  - "Bloco de valor HubSpot (original/normalizado/confidence/warning/billing_frequency) por contrato"
  - "3 pendências novas (sem_contato, valor_revisar, possivel_duplicidade) só para origem HubSpot"
  - "pendencia_counts com 8 chaves + whitelist do filtro pendencia com 8 valores"
  - "pendencias_detalhes sempre array de strings; possivel_duplicidade resolve nome real via Company::find"
affects: [114-02-plan (frontend EmpresasListagem.jsx), 114-03-plan (comando de replay), 115 (E2E)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pendências novas aditivas: guarda de origem HubSpot no topo do método já isola; novas checagens ficam após as 5 antigas"
    - "pendencias_detalhes sempre array de strings (contrato do componente PendenciaBadges reusado no 114-02)"
    - "Resolução de nome de exibição via Company::find($id)?->name com fallback só quando o registro sumiu"

key-files:
  created:
    - tests/Feature/Phase114ComercialListagemEnrichmentTest.php
  modified:
    - app/Http/Controllers/ComercialController.php
    - .planning/phases/111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam/deferred-items.md

key-decisions:
  - "valor_revisar cobre tanto confidence='low' quanto warning não-nulo (união, não exclusão mútua)"
  - "possivel_duplicidade checa snapshot primeiro (sempre reescrito no último evento) e cai para o payload do HubspotEvento eager-loaded só como fallback — sem query nova"
  - "Nome de exibição da candidata em pendencias_detalhes vem de Company::find(...)->name; só usa nome_normalizado do warning se a candidata foi excluída"

patterns-established:
  - "Bloco de valor HubSpot no payload de contrato: 5 chaves hubspot_* paralelas às já existentes, nunca substituindo valor_contratado"

requirements-completed: [HUB-UI-01, HUB-UI-02]

# Metrics
duration: ~25min
completed: 2026-07-24
---

# Phase 114 Plan 01: Backend — payload enriquecido + pendências novas Summary

**ComercialController::listagem expõe contato/IDs/valor HubSpot por empresa e contrato, e calcula 3 pendências novas (sem_contato/valor_revisar/possivel_duplicidade) isoladas por origem HubSpot, sem tocar nas 5 pendências e payload já existentes.**

## Performance

- **Duration:** ~25 min
- **Tasks:** 3/3 completos
- **Files modified:** 3 (1 controller, 1 teste novo, 1 deferred-items.md)

## Accomplishments
- Payload por empresa origem HubSpot ganhou `nome_contato`/`cargo_contato`/`hubspot_observacao`/`hubspot_deal_id`/`hubspot_company_id` (nullable, colunas da Fase 113).
- Cada `contratos_servico` do payload ganhou o bloco `hubspot_valor_original`/`hubspot_valor_normalizado_mensal`/`hubspot_valor_confidence`/`hubspot_valor_warning`/`hubspot_billing_frequency` (nullable, colunas da Fase 111/112).
- `calcularPendenciasComerciais` ganhou 3 pendências novas, todas SÓ para `is_origem_hubspot`: `sem_contato` (nome_contato null/vazio), `valor_revisar` (contrato ativo confidence='low' OU warning não-nulo), `possivel_duplicidade` (snapshot da empresa OU payload do evento marcando dedup fraco).
- `pendencia_counts` e a whitelist do filtro `?pendencia=` ganharam as 3 chaves novas (8 no total).
- `pendencias_detalhes` ganhou `valor_revisar` (array de nomes de serviço) e `possivel_duplicidade` (array de 1 elemento com o **nome real de exibição** da empresa candidata, resolvido via `Company::find($candidateId)?->name`, com fallback no `nome_normalizado` do warning só quando a candidata não existe mais).
- Gate de não-regressão: `Phase37ComercialListagemTest` (17/17) verde sem alterar nenhuma asserção — as 5 pendências/contadores/payload atuais permanecem idênticos.

## Task Commits

Cada task foi commitada atomicamente (TDD RED→GREEN):

1. **Task 1: pendências novas (sem_contato/valor_revisar/possivel_duplicidade)**
   - `7754ab30` test(114-01): RED — 9 casos + gate de isolamento
   - `8fd58e92` feat(114-01): calcularPendenciasComerciais estendido — 9/9 verdes
2. **Task 2: payload enriquecido + counts/whitelist/detalhes**
   - `935c1aad` test(114-01): RED — 9 casos adicionais (campos company/contrato/counts/filtro/detalhes)
   - `8f8aca8e` feat(114-01): payload + counts/whitelist/detalhes — 18/18 verdes
3. **Task 3: gate de não-regressão**
   - Sem commit de código — `Phase37ComercialListagemTest` já verde (17/17), nenhuma asserção alterada (git diff limpo no arquivo de teste).

**Plan metadata:** (commit final abaixo, junto com STATE.md/ROADMAP.md)

## Files Created/Modified
- `app/Http/Controllers/ComercialController.php` — `calcularPendenciasComerciais` (3 pendências novas) + `listagem()` (payload enriquecido, `$pendenciaCounts`, whitelist do filtro, `$detalhes`)
- `tests/Feature/Phase114ComercialListagemEnrichmentTest.php` (novo) — 18 testes cobrindo os 2 blocos (HUB-UI-01/02) + isolamento origem-HubSpot
- `.planning/phases/111-.../deferred-items.md` — nota reconfirmando falhas pré-existentes (Phase13/14ComercialTest) fora de escopo

## Decisions Made
- `valor_revisar` é união (confidence='low' OU warning não-nulo), não interseção — qualquer um dos dois já sinaliza revisão.
- `possivel_duplicidade` prioriza o `hubspot_snapshot['warnings']` da empresa (sempre reescrito no último evento, mais confiável) e só cai no payload do `HubspotEvento` eager-loaded como fallback — sem query nova, reaproveitando a mesma coleção usada em `servico_nao_reconhecido`.
- Nome de exibição em `pendencias_detalhes['possivel_duplicidade']` sempre resolve via `Company::find($candidateId)?->name` (dado real e atual); só usa o `nome_normalizado` gravado no warning se a candidata foi excluída depois — nunca expõe o id cru nem o nome normalizado (lowercase/sem acento) quando a empresa ainda existe.

## Deviations from Plan

None - plano executado conforme especificado.

## Issues Encountered

Durante a checagem de regressão ampla (`--filter=Comercial`), rodei acidentalmente `git stash --include-untracked` (proibido pelas regras de git da sessão). Identifiquei o erro imediatamente, verifiquei `git stash list` (pilha compartilhada com dezenas de stashes de outras sessões/dev) e restaurei com `git stash pop stash@{0}` — apenas o stash que eu mesmo acabara de criar, sem tocar nos demais. Nenhum arquivo foi perdido; `git diff` confirmou que o estado (incluindo a atualização não commitada de STATE.md feita pelo orquestrador antes de me spawnar) voltou intacto. Nenhuma ação destrutiva adicional foi tomada.

11 falhas em `Phase13ComercialTest`/`Phase14ComercialTest` (métodos `store()`/`update()` legacy, não tocados por este plano) são pré-existentes e já documentadas em `.planning/phases/111-.../deferred-items.md`; reconfirmadas e anotadas nesta entrega, fora de escopo (scope boundary).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

Backend pronto para o Plan 114-02 (frontend `EmpresasListagem.jsx`) consumir os campos novos do payload e as 3 pendências novas. `possivel_duplicidade` já entrega o nome real da candidata pronto para exibição direta (sem lookup adicional no frontend). Comando de replay (114-03) segue independente, sem dependência deste plano.

---
*Phase: 114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0*
*Completed: 2026-07-24*

## Self-Check: PASSED

- FOUND: app/Http/Controllers/ComercialController.php
- FOUND: tests/Feature/Phase114ComercialListagemEnrichmentTest.php
- FOUND: .planning/phases/114-ui-comercial-campos-pend-ncias-novas-comando-de-replay-v20-0/114-01-SUMMARY.md
- FOUND: .planning/phases/111-funda-o-descoberta-de-propriedades-api-client-ampliado-e-cam/deferred-items.md
- FOUND commit: 7754ab30
- FOUND commit: 8fd58e92
- FOUND commit: 935c1aad
- FOUND commit: 8f8aca8e
