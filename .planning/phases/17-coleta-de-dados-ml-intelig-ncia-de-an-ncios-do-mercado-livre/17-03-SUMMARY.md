---
phase: 17-coleta-de-dados-ml
plan: 03
subsystem: api
tags: [laravel, http-client, mercadolivre, oauth, client-credentials, cache, rate-limit]

requires:
  - phase: 17-01
    provides: MlKeywordMinerService (rankingKeywords/agruparPerguntas/recomendacaoHeuristica)
  - phase: 17-02
    provides: MlbColeta model + contrato executarPipeline
provides:
  - MlColetaService — pipeline HTTP da API oficial ML via app token (client_credentials)
  - getAppToken cacheado (ml_app_token_coleta, TTL expires_in-300)
  - executarPipeline(MlbColeta, MlKeywordMinerService): array com degradação graciosa
affects: [17-04-controller, 17-05-coleta-page]

tech-stack:
  added: []
  patterns:
    - "Cache de token OAuth client_credentials (análogo a MercadoLivreService::resolveAdvertiserId)"
    - "mlGet() com tratamento 429 (backoff Retry-After cap 30s) / 401-403 (best-effort)"
    - "Loop top-5 best-effort com catch \\Throwable → Log::warning → continue"

key-files:
  created:
    - app/Services/MlColetaService.php
    - tests/Unit/MlColetaServiceTest.php
  modified: []

key-decisions:
  - "App token cacheado via Cache::get/put (não Cache::remember) para usar TTL dinâmico expires_in-300"
  - "Endpoint /sites/MLB/search BANIDO (403); usa domain_discovery + products/search (D-02 probe)"
  - "Privacidade T-17-09: persiste apenas o texto da pergunta, nunca from_id"

patterns-established:
  - "questions/reviews de terceiros 401/403 não abortam — sinalizam questions_disponivel=false"
  - "App token nunca aparece em Log:: (T-17-08)"

requirements-completed: [D-01, D-02, D-03, D-04, D-05]

duration: ~12min (inline)
completed: 2026-06-02
---

# Phase 17 / Plan 03: MlColetaService Summary

**Pipeline HTTP da API oficial do Mercado Livre via app token client_credentials, com cache de token, backoff de rate limit e degradação graciosa de endpoints de terceiros.**

## Performance

- **Duration:** ~12 min (execução inline sequencial)
- **Completed:** 2026-06-02
- **Tasks:** 2 (TDD)
- **Files modified:** 2

## Accomplishments
- `getAppToken()` cacheado em `ml_app_token_coleta` (TTL `expires_in - 300`), nunca logado
- `mlGet()` com tratamento de 429 (backoff via `Retry-After`, cap 30s) e 401/403 (best-effort)
- `executarPipeline()` encadeia domain_discovery → products/search (top 10) → highlights → trends → top 5 a fundo
- Degradação graciosa: questions 401 → `questions_disponivel=false` sem abortar; 429 num item não derruba o lote
- `sold_quantity` ausente tratado como `null` (Pitfall 2); mineração heurística via `MlKeywordMinerService`
- Suíte `MlColetaServiceTest` 3/3 verde (D-01, D-02, D-03)

## Task Commits

1. **Task 1: MlColetaServiceTest (RED)** - `(test commit)` (test)
2. **Task 2: MlColetaService (GREEN)** - `(feat commit)` (feat)

## Files Created/Modified
- `app/Services/MlColetaService.php` - Pipeline HTTP + cache token + degradação graciosa (~250 linhas)
- `tests/Unit/MlColetaServiceTest.php` - Http::fake cobrindo cache token, fallback questions e 429

## Decisions Made
- `Cache::get`/`Cache::put` em vez de `Cache::remember` para aplicar TTL dinâmico baseado em `expires_in`.
- Trends normalizadas via `miner->normalizarToken` para casar com os termos do ranking (`eh_tendencia`).

## Deviations from Plan
None - plan executed exactly as written. (O endpoint `/sites/MLB/search` foi deliberadamente evitado conforme D-02.)

## Issues Encountered
None.

## User Setup Required
**Credenciais ML:** `ML_CLIENT_ID` (default no config) e `ML_CLIENT_SECRET` (env) precisam estar setados no `.env` para a coleta real funcionar. Os testes usam `Http::fake` e não dependem disso.

## Next Phase Readiness
- `executarPipeline` pronto para o `MlbColetaJob` (já implementado no 17-02) invocar em produção.
- Controller (17-04) pode disparar o Job; a página (17-05) consumirá o `resultado`.

---
*Phase: 17-coleta-de-dados-ml*
*Completed: 2026-06-02*
