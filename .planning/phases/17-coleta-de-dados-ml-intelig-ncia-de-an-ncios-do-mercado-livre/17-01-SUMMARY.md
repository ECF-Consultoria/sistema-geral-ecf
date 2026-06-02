---
phase: 17-coleta-de-dados-ml
plan: 01
subsystem: api
tags: [php, keyword-mining, ngrams, ptbr, heuristic, no-ai]

requires: []
provides:
  - MlKeywordMinerService — mineração estatística pt-BR em PHP puro (sem intl/Normalizer, sem DB, sem IA)
  - rankingKeywords (unigramas/bigramas/trigramas por frequência + flag eh_tendencia)
  - agruparPerguntas (temas das dúvidas) e recomendacaoHeuristica (título sugerido por regras)
affects: [17-03-coleta-service, 17-05-coleta-page]

tech-stack:
  added: []
  patterns:
    - "Service stateless PHP puro (sem DI Laravel), análogo a CobrancaCalculator"
    - "Normalização pt-BR via strtr(ACCENT_MAP) + mb_strtolower (sem ext-intl)"

key-files:
  created:
    - app/Services/MlKeywordMinerService.php
    - tests/Unit/MlKeywordMinerTest.php
  modified: []

key-decisions:
  - "Recomendação 100% heurística (regras) com aviso explícito de Fase 1 — IA diferida para Fase 2 (D-05)"
  - "Acentuação resolvida por mapa estático (ACCENT_MAP) para não depender de ext-intl no XAMPP"

patterns-established:
  - "TDD RED→GREEN: teste unitário sem RefreshDatabase instanciando o service no setUp"
  - "Stopwords pt-BR + domínio e-commerce em const privada STOPWORDS_PT"

requirements-completed: [D-04, D-05]

duration: ~5min (recuperado inline após stall de subagente)
completed: 2026-06-01
---

# Phase 17 / Plan 01: MlKeywordMinerService Summary

**Mineração estatística pt-BR em PHP puro — tokeniza títulos ML, remove stopwords, ranqueia n-gramas e produz recomendação heurística (sem IA, D-04/D-05).**

## Performance

- **Duration:** ~5 min (finalização inline após o subagente paralelo sofrer stream-idle timeout)
- **Completed:** 2026-06-01
- **Tasks:** 2 (TDD)
- **Files modified:** 2

## Accomplishments
- `MlKeywordMinerService` com `normalizarToken`, `tokenizar`, `ngrams`, `rankingKeywords`, `agruparPerguntas`, `recomendacaoHeuristica`
- Ranking determinístico de unigramas/bigramas/trigramas ordenado por frequência, com `eh_tendencia` cruzando `/trends`
- Recomendação heurística (título sugerido + palavras-top) sem nenhuma chamada de IA, com aviso de Fase 1
- Suíte `MlKeywordMinerTest` 4/4 verde (D-04-a/b/c)

## Task Commits

1. **Task 1: MlKeywordMinerTest (RED)** - `c33abc8` (test)
2. **Task 2: MlKeywordMinerService (GREEN)** - `56ee116` (feat)

## Files Created/Modified
- `app/Services/MlKeywordMinerService.php` - Minerador estatístico + recomendação heurística (~13 KB)
- `tests/Unit/MlKeywordMinerTest.php` - Cobertura unitária D-04-a/b/c (4 testes)

## Decisions Made
- None além das travadas no plano — execução conforme especificado.

## Deviations from Plan
None - plan executed exactly as written. (O código foi produzido pelo subagente; o orquestrador apenas verificou os testes GREEN e gerou este SUMMARY após o stall do subagente.)

## Issues Encountered
- O subagente paralelo (worktree) sofreu *stream idle timeout* logo antes de escrever o SUMMARY. As duas commits de implementação já estavam em `main`; o orquestrador validou `php artisan test --filter MlKeywordMinerTest` (4/4 verde) e finalizou o SUMMARY inline.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- `rankingKeywords`/`agruparPerguntas`/`recomendacaoHeuristica` prontos para o `MlColetaService` (Plan 17-03) consumir.

---
*Phase: 17-coleta-de-dados-ml*
*Completed: 2026-06-01*
