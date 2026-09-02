---
phase: 138-rea-comercial-conectada-etapa-v23-0
plan: 03
subsystem: database
tags: [laravel, eloquent, migration, hubspot, cache]

# Dependency graph
requires:
  - phase: 138-02
    provides: "decisão do usuário: property de owner ASSUMIDA como hubspot_owner_id (não medida), escopo owners.read NÃO CONFIRMADO"
provides:
  - "3 colunas aditivas nullable em companies: hubspot_owner_id, hubspot_owner_nome, data_venda"
  - "chave de config services.hubspot.props.deal.owner_id, config-driven"
  - "HubspotApiClient::fetchOwner(string): ?array — GET resiliente de /crm/v3/owners/{id}"
  - "HubspotOwnerResolver::resolverNome(?string): ?string — resolução cacheada (7 dias) do nome do owner"
affects: [138-04, 138-05, 138-06, 138-09]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Sentinela cacheável de string vazia para Cache::remember quando o valor real seria null (evita re-chamada HTTP a cada leitura)"
    - "fetchOwner() segue o padrão resiliente de fetchAssociatedCompanyId() (devolve null em erro HTTP), nunca o padrão de $res->throw() de fetchCompany()/fetchDeal()"

key-files:
  created:
    - database/migrations/2026_09_02_120000_add_hubspot_owner_data_venda_to_companies_table.php
    - app/Services/Hubspot/HubspotOwnerResolver.php
    - tests/Feature/Phase138/CompaniesColunasOwnerVendaTest.php
    - tests/Unit/Phase138/HubspotOwnerResolverTest.php
  modified:
    - app/Models/Company.php
    - config/services.php
    - app/Services/HubspotApiClient.php
    - .env.example

key-decisions:
  - "hubspot_owner_id (string) e hubspot_owner_nome (string) sem cast especial; data_venda com cast 'date' (chega do HubSpot como 'Y-m-d', medido em HubspotDealHandoffService::parseDataHubspot())"
  - "Comentário do config/services.php explicita que o default 'hubspot_owner_id' foi ASSUMIDO, NÃO MEDIDO (401 por falta de HUBSPOT_ACCESS_TOKEN local), com autorização explícita do usuário em 2026-09-02 — medição real pendente do plano 138-09 contra a VPS"
  - "fetchOwner() nunca lança (nem em 403 nem em 404) — owner arquivado/removido ou escopo OAuth ausente não podem derrubar o fluxo"

patterns-established:
  - "HubspotOwnerResolver: classe pequena, um propósito só, construtor com promoted property recebendo HubspotApiClient — molde para futuros resolvers em app/Services/Hubspot/"

requirements-completed: [COMERC-02]

# Metrics
duration: ~20min
completed: 2026-09-02
---

# Phase 138 Plan 03: Colunas de owner/data da venda + resolução do responsável comercial Summary

**Três colunas aditivas nullable em `companies` (owner id/nome do HubSpot + data da venda) e a cadeia completa de resolução do responsável comercial: property config-driven → `fetchOwner()` resiliente → `HubspotOwnerResolver` com cache de 7 dias.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-09-02T16:11Z (aprox.)
- **Completed:** 2026-09-02T16:20:58-03:00
- **Tasks:** 2 completed
- **Files modified:** 8 (4 criados, 4 modificados)

## Accomplishments
- `companies` ganhou `hubspot_owner_id`, `hubspot_owner_nome` e `data_venda` — três colunas nullable, sem default, sem índice, sem backfill — confirmadas por reconsulta direta ao MariaDB (`SHOW COLUMNS`), nunca só pelo stdout do `artisan migrate`.
- A property de owner entra no fetch de deal já existente por uma única chave de config (`services.hubspot.props.deal.owner_id`), sem segundo caminho de fetch — o valor cai automaticamente em `hubspot_snapshot.deal` como as demais properties.
- `HubspotApiClient::fetchOwner()` é resiliente por desenho: qualquer erro HTTP (403 do escopo ausente, 404 de owner arquivado) devolve `null`, nunca lança.
- `HubspotOwnerResolver::resolverNome()` resolve o nome de exibição com cache de 7 dias e sentinela de string vazia (nunca `null` no cache), evitando uma chamada HTTP por empresa a cada listagem.

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration aditiva das 3 colunas e o Company model** - `f80df74a` (feat)
2. **Task 2: Property de owner no config, fetchOwner() no client e o HubspotOwnerResolver** - `54487c62` (feat)

**Plan metadata:** (este commit, a seguir)

## Files Created/Modified
- `database/migrations/2026_09_02_120000_add_hubspot_owner_data_venda_to_companies_table.php` - migration aditiva das 3 colunas, molde de `Schema::hasColumn` por coluna, `down()` reversível
- `app/Models/Company.php` - `$fillable` (3 campos novos) e `$casts` (`data_venda => date`)
- `tests/Feature/Phase138/CompaniesColunasOwnerVendaTest.php` - 5 testes: existência das colunas, nascimento null, cast de data, ausência de obrigatoriedade, atribuição por Eloquent
- `config/services.php` - chave `hubspot.props.deal.owner_id`, com comentário registrando "assumido, não medido"
- `.env.example` - `HUBSPOT_PROP_DEAL_OWNER_ID` documentado como opcional (comentado)
- `app/Services/HubspotApiClient.php` - `fetchOwner(string $ownerId): ?array`, docblock de topo atualizado
- `app/Services/Hubspot/HubspotOwnerResolver.php` - classe nova: `resolverNome(?string $ownerId): ?string` com `Cache::remember` de 7 dias
- `tests/Unit/Phase138/HubspotOwnerResolverTest.php` - 7 testes: nome composto, fallback e-mail, id nulo (2 variantes), 403, 404, cache (`assertSentCount(1)`)

## Decisions Made
- Nenhuma decisão nova além das já travadas em `138-CONTEXT.md` (D-08, D-09) e da resolução herdada de `138-02` (nome da property assumido com autorização explícita, medição real adiada para a VPS via plano 138-09).
- Comentário do `config/services.php` foi mantido deliberadamente compacto (5 linhas de diff) para respeitar o critério de aceite do plano — a explicação completa da proveniência da decisão fica em `138-HUBSPOT-MEDICOES.md`, referenciado no comentário.

## Deviations from Plan

None - plano executado exatamente como escrito. O único ajuste foi editorial: o primeiro comentário escrito em `config/services.php` (Task 2) tinha 10 linhas de diff, acima do critério de aceite explícito do plano ("no máximo 6 linhas"); foi comprimido para 5 linhas antes do commit, preservando a mesma informação essencial (assumido/não medido, autorização do usuário, ponteiro para `138-HUBSPOT-MEDICOES.md`). Não é deviation de comportamento — é correção de formatação antes do commit, dentro da mesma task, sem necessidade de nova rodada de verificação de código.

## Issues Encountered
None.

## User Setup Required

None - nenhuma configuração de serviço externo é exigida por este plano. A pendência de medição real da property `hubspot_owner_id` e do escopo `crm.objects.owners.read` contra a conta HubSpot de produção já está registrada como obrigação do plano **138-09** (ver `138-HUBSPOT-MEDICOES.md`), não deste plano.

## Next Phase Readiness

A capacidade está pronta para os planos 138-04 (persistência — gravar `hubspot_owner_id`/`hubspot_owner_nome`/`data_venda` a partir do webhook/reprocessamento) e 138-05/138-06 (exibição nas listagens Contrato e Entrada). Nenhum bloqueio conhecido. A pendência de medição real na VPS (nome da property + escopo OAuth) permanece rastreada em `138-HUBSPOT-MEDICOES.md` e é responsabilidade do plano 138-09, não impede o trabalho dos planos 138-04/05/06 porque o `env()` override cobre a correção sem mudança de código.

---
*Phase: 138-rea-comercial-conectada-etapa-v23-0*
*Completed: 2026-09-02*

## Self-Check: PASSED

Todos os 8 arquivos criados/modificados confirmados presentes no disco; os 2 commits de task
(`f80df74a`, `54487c62`) confirmados em `git log --oneline --all`. Nenhum item faltante.
