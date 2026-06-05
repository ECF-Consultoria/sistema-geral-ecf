---
phase: 22-wrapper-expandido-ecfdriveservice-todos-endpoints-auth-files
plan: 01
subsystem: api
tags: [ecf-drive, http-client, cache, laravel, php, wrapper, sellers, carteira, signals, relatorios]

# Dependency graph
requires:
  - phase: 20-integracao-ecf-drive
    provides: "EcfDriveService original com listGrants, cliente, ping, grantsExpirandoEm"

provides:
  - "EcfDriveService expandido com 22 métodos públicos (4 Phase 20 + 18 novos)"
  - "Helpers privados get() e cacheKey() centralizando HTTP e cache"
  - "Constante MET_VALIDAS para validação de ranking"
  - "5 suítes Feature cobrindo todos os domínios da API ECF Drive"
  - "Cache estratégico por endpoint (5min/1h/6h/24h/1min) via Cache::remember"

affects:
  - phase-23 (alertas — consumers de listSignals + ackSignal)
  - phase-24 (painel executivo — carteiraResumo, ranking)
  - phase-25 (ficha 360 seller — seller, sellerMetricasMensal, sellerMedalhas)
  - phase-26 (webhooks HMAC)
  - phase-27 (concentração + forecast)
  - phase-28 (relatório mensal — relatorioMensal)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Helper privado get(path, params) centralizando Http::withHeaders + retry + timeout + RuntimeException pt-BR"
    - "Helper privado cacheKey(path, params) gerando chave determinística ecf.{path-sanitizado}.{md5(query)}"
    - "Cache estratégico por tipo de endpoint: proxy on-demand (sem cache), refresh on view (5min), médio (1h), estável (6h/24h)"
    - "Validações defensivas com InvalidArgumentException para erros de uso do caller"
    - "Testes Feature com Http::fake — sem chamar API real"

key-files:
  modified:
    - "app/Services/EcfDriveService.php — expandido de 127 para ~360 linhas com 22 métodos públicos"
  created:
    - "tests/Feature/Phase22/EcfDriveServiceClientesTest.php — 4 testes domínio /clientes/*"
    - "tests/Feature/Phase22/EcfDriveServiceSellersTest.php — 10 testes domínio /sellers/*"
    - "tests/Feature/Phase22/EcfDriveServiceCarteiraTest.php — 6 testes domínio /carteira/*"
    - "tests/Feature/Phase22/EcfDriveServiceSignalsTest.php — 4 testes domínio /signals/*"
    - "tests/Feature/Phase22/EcfDriveServiceRelatoriosTest.php — 3 testes domínio /relatorios/*"

key-decisions:
  - "D-02: ping() NÃO usa o helper get() — precisa retornar false sem lançar (não RuntimeException)"
  - "D-03: cache key grantsExpirandoEm preserva formato Phase 20 (ecf_drive:expirando:{dias}) para evitar invalidação em prod"
  - "D-04: ackSignal invalida apenas chave-padrão da inbox (acked=false,limit=50,page=1) — database driver não suporta tags"
  - "D-07: validações defensivas lançam InvalidArgumentException (uso do caller) vs RuntimeException (API remota)"
  - "Sellers entregados com 10 testes (vs 9 planejados) — sellerSignals recebeu teste adicional de cache"

patterns-established:
  - "Novo método HTTP: private function get(path, params): array — todos os GETs passam por aqui"
  - "Chave de cache: ecf.{path-com-pontos}.{md5-query-params} — formato determinístico"
  - "TTL conforme tabela D-02 do CONTEXT.md — 5min para listas, 1h para rankings, 6h para medalhas, 24h para histórico/relatorio"

requirements-completed:
  - ECF-CORE-01
  - ECF-CLIENTES-02
  - ECF-SELLERS-03
  - ECF-CARTEIRA-04
  - ECF-SIGNALS-05
  - ECF-RELATORIOS-06
  - ECF-CACHE-07
  - ECF-VALIDA-08

# Metrics
duration: ~45min
completed: 2026-06-05
---

# Phase 22 Plan 01: Wrapper expandido EcfDriveService Summary

**EcfDriveService expandido de 4 para 22 métodos públicos cobrindo /clientes, /sellers, /carteira, /signals e /relatorios — helpers get()/cacheKey() centralizando HTTP, cache estratégico por endpoint e 27 testes Feature verdes via Http::fake**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-06-05
- **Completed:** 2026-06-05
- **Tasks:** 8 executadas (W1-T1 a W3-T3 + setup de testes) — W4 aguarda checkpoint humano
- **Files modified:** 1 arquivo de produção + 5 arquivos de teste novos

## Accomplishments

- Helper privado `get(path, params)` centraliza Http + retry(2,500) + timeout(15) + RuntimeException pt-BR em todos os métodos GET
- Helper privado `cacheKey(path, params)` gera chave determinística `ecf.{path}.{md5(query)}` para cache consistente
- 18 métodos públicos novos cobrindo `/clientes/*` (3), `/sellers/*` (7), `/carteira/*` (5), `/signals/*` (2), `/relatorios/*` (1)
- Cache estratégico por endpoint: 5min (listas), 1h (rankings/breakdowns), 6h (medalhas), 24h (histórico/relatório), 1min (signals)
- Validações defensivas: `ranking()` valida métrica contra MET_VALIDAS, `compararSellers()` valida 1-20 ids, `carteiraSegmentacao()` valida dimensoes não-vazio
- Phase 20 sem regressão: 20/20 testes Phase 20 verdes durante todo o ciclo

## Task Commits

| Wave | Task | Commit | Tipo |
|------|------|--------|------|
| W1 | T1: helpers + refactor Phase 20 | `7af9e02` | refactor |
| W1 | T2: testes /clientes/* | `78dbd50` | feat |
| W2 | T1+T2: testes /sellers/* (10 testes) | `434be9c` | feat |
| W3 | T1: testes /carteira/* | `229b2d1` | feat |
| W3 | T2: testes /signals/* | `b497bbb` | feat |
| W3 | T3: testes /relatorios/* + validação final | `7c5c9e9` | feat |

## Contagem de Testes

| Suite | Testes | Status |
|-------|--------|--------|
| Phase 22 — Clientes | 4 | verdes |
| Phase 22 — Sellers | 10 | verdes |
| Phase 22 — Carteira | 6 | verdes |
| Phase 22 — Signals | 4 | verdes |
| Phase 22 — Relatórios | 3 | verdes |
| **Phase 22 Total** | **27** | **verdes** |
| Phase 20 — EcfDriveServiceTest | 6 | verdes (regressão) |
| Phase 20 — SyncGrantsFromEcfDriveTest | 10 | verdes (regressão) |
| Phase 20 — outros | 4 | verdes (regressão) |
| **Phase 20 Total** | **20** | **verdes** |
| **Grand Total** | **47** | **verdes** |

## Files Created/Modified

- `app/Services/EcfDriveService.php` — expandido de 127 linhas (4 métodos) para ~360 linhas (22 métodos + helpers)
- `tests/Feature/Phase22/EcfDriveServiceClientesTest.php` — criado (4 testes)
- `tests/Feature/Phase22/EcfDriveServiceSellersTest.php` — criado (10 testes)
- `tests/Feature/Phase22/EcfDriveServiceCarteiraTest.php` — criado (6 testes)
- `tests/Feature/Phase22/EcfDriveServiceSignalsTest.php` — criado (4 testes)
- `tests/Feature/Phase22/EcfDriveServiceRelatoriosTest.php` — criado (3 testes)

## Decisions Made

- **D-02**: `ping()` preservado com try/catch local — `get()` lança RuntimeException que quebraria o contrato bool-sem-lançar
- **D-03**: `grantsExpirandoEm()` preserva chave `"ecf_drive:expirando:{dias}"` da Phase 20 — evita invalidar caches ativos em produção
- **D-04**: `ackSignal()` invalida apenas a chave-padrão da inbox (`acked=false,limit=50,page=1`) — driver `database` não suporta cache tags; outras chaves expiram em 1min naturalmente
- **Sellers com 10 testes** (plano previa 9): `test_sellerSignals_cacheia_5min` adicionado para cobrir o TTL do método (não afeta contagem total — plano previa ≥26)

## Deviations from Plan

Nenhum — plano executado exatamente como especificado. Os 4 métodos Phase 20 mantêm assinatura e comportamento idênticos. Nenhum arquivo fora do escopo foi tocado.

## Issues Encountered

Nenhum. Todos os testes passaram na primeira execução.

## Arquivos Não Tocados (confirmado)

- `config/services.php` — sem mudança
- `app/Providers/AppServiceProvider.php` — sem mudança
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` — sem mudança
- `app/Http/Controllers/GrantController.php` — sem mudança
- `routes/console.php` — sem mudança
- `resources/js/Pages/Grants/Index.jsx` — sem mudança

## Smoke W4 (PENDENTE — checkpoint humano)

**Status:** Aguardando execução humana via tinker em produção.

Chamadas necessárias (após deploy do código Phase 22):
1. `$s->ping()` — deve retornar `bool(true)`
2. `$s->carteiraResumo()` — deve retornar array com `mesAtual`, `gmv`, `vendas`
3. `$s->ranking('tgmv_lc', 1)` — deve retornar `metrica=tgmv_lc` + `count(data)===1`
4. `$s->listSignals(['acked' => false, 'limit' => 1])` — deve retornar array com chave `data`
5. `$s->relatorioMensal()` — deve retornar array com `periodo` no formato `YYYYMM`

## Known Stubs

Nenhum — sem UI, sem migration. Apenas wrapper de serviço.

## Threat Flags

Nenhum — nenhum endpoint novo exposto. O service é consumidor HTTP externo, não expõe rota na aplicação.

## Next Phase Readiness

- `app(EcfDriveService::class)->carteiraResumo()` disponível para Phase 24 (painel executivo)
- `app(EcfDriveService::class)->listSignals()/ackSignal()` disponíveis para Phase 23 (alertas)
- `app(EcfDriveService::class)->seller()/sellerMetricasMensal()/sellerMedalhas()` disponíveis para Phase 25 (ficha 360)
- `app(EcfDriveService::class)->relatorioMensal()` disponível para Phase 28

Pré-requisito antes de usar em produção: **smoke W4 aprovado** (ping real + 4 chamadas por domínio). Phases 23-28 devem aguardar aprovação do smoke.

---
*Phase: 22-wrapper-expandido-ecfdriveservice-todos-endpoints-auth-files*
*Completed parcial: 2026-06-05 (W1+W2+W3 completos — W4 checkpoint humano pendente)*
