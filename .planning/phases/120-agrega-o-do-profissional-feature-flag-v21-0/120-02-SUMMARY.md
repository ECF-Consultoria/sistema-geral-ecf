---
phase: 120-agrega-o-do-profissional-feature-flag-v21-0
plan: 02
subsystem: desempenho
tags: [feature-flag, cache, shadow-testing, laravel, phpunit]

# Dependency graph
requires:
  - phase: 120-01
    provides: feature flag `metrics.performance_company_first_score` (desligada), parâmetro `$incluirEmpresasScore` em `compute()`/`computeCached()`, `cacheKey()` em v14
provides:
  - shadow ligado com garantia em `desempenho:consolidar-mes` (chama compute() direto — sem risco de Cache::remember pular)
  - guard do Cache::remember em `desempenho:warm-cache` — recomputa só quando falta empresas_score no payload cacheado (C-02)
  - suíte `tests/Feature/Phase120/ShadowRoteamentoTest.php` — gate nº 2 do VALIDATION fechado nos 3 itens
affects: [121-comparador-antigo-x-novo, 122-persistencia-empresas-score]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dublê contador por herança (extends + override + parent::) para instrumentar chamadas a um serviço sem mockar sua lógica interna"
    - "Guard de recompute condicional por chave ausente no payload cacheado, em vez de Cache::forget() incondicional"

key-files:
  created:
    - tests/Feature/Phase120/ShadowRoteamentoTest.php
  modified:
    - app/Console/Commands/ConsolidarMesDesempenho.php
    - app/Console/Commands/WarmDesempenhoCache.php

key-decisions:
  - "warm-cache passa a ser GARANTIDO, não best-effort: o guard custa exatamente 1 leitura de cache por user por ciclo (8min) quando o payload já tem empresas_score (custo zero de recompute); só paga o recompute completo (~70s/user) na primeira vez que uma chave pré-Fase-120 é encontrada quente."
  - "Fixture só-Shopee escolhida para ShadowRoteamentoTest — margem_amostra.n_elegivel=0 nesse caso, então o gate FIXMARG-03 do consolidar-mes nunca pode bloquear a persistência, isolando o teste do roteamento do shadow de qualquer complexidade da régua de margem."
  - "A flag `metrics.performance_company_first_score` continua false — este plano liga o shadow (dois comandos), não a flag (C-01, sinais independentes)."

requirements-completed: [AGRE-02]

# Metrics
duration: ~45min
completed: 2026-07-30
---

# Phase 120 Plan 02: Roteamento do shadow Summary

**Shadow ligado com garantia em `desempenho:consolidar-mes`/`desempenho:warm-cache` (guard do `Cache::remember` na C-02), com prova de contagem zero em leitura interativa e isolamento byte-a-byte dos valores legados — 6 testes novos, flag ainda `false`.**

## Performance

- **Duration:** ~45min
- **Tasks:** 3/3 (Task 2 e 3 compartilham o mesmo arquivo de teste — ver Deviations)
- **Files modified:** 3 (2 comandos + 1 suíte de teste nova)

## Accomplishments

- `ConsolidarMesDesempenho::handle()` chama `compute($user, $mes, null, incluirEmpresasScore: true)` — como este comando chama `compute()` direto (sem `Cache::remember`), o shadow roda com garantia em toda execução; é o registro canônico mensal, e `empresas_score` passa a ser persistido no `breakdown_json` de graça.
- `WarmDesempenhoCache::handle()` ganha o guard da C-02: chama `computeCached(..., incluirEmpresasScore: true)`, e se o resultado NÃO tiver a chave `empresas_score` (payload cacheado pré-Fase-120 ou populado por leitura interativa), faz `Cache::forget()` seguido de um segundo `computeCached()` — sem isso, o `Cache::remember` interno retornaria o valor quente e o closure (logo o shadow) NUNCA rodaria naquele ciclo, silenciosamente.
- `tests/Feature/Phase120/ShadowRoteamentoTest.php` — 6 testes fecham o gate nº 2 do VALIDATION:
  1. `consolidar-mes` dispara o shadow com garantia (contador > 0) e persiste `empresas_score` no snapshot.
  2. `warm-cache` recomputa (contador > 0) quando o payload cacheado não tem `empresas_score`.
  3. `warm-cache` NÃO recomputa (contador = 0) quando o payload cacheado já tem `empresas_score` — prova o outro lado do guard, o motivo de `Cache::forget()` incondicional ter sido rejeitado.
  4. Leitura interativa (`computeCached($user, $mes)` com exatamente 2 argumentos — a forma real como os 3 controllers chamam hoje) tem contagem zero de `CompanyScoreService` com a flag desligada (D-04 em runtime).
  5. Gate estático: nenhum dos 3 arquivos de controller (`PerformanceController`, `PortfolioController`, `DashboardController`) contém a string `incluirEmpresasScore` — protege contra ativação futura sem perceber.
  6. Prova de isolamento (Pitfall 2 do RESEARCH): `compute()` com shadow ligado e desligado produz EXATAMENTE os mesmos valores em `componentes.*`, `pontos_componentes.*`, `nota_final`, `score_status`, `faixa_bonus` e `margem_amostra` — a única divergência permitida é `empresas_score` (vazio × populado).

## Task Commits

1. **Task 1: Shadow garantido no consolidar-mes e guard do Cache::remember no warm-cache (C-02)** - `587667cc` (feat)
2. **Task 2+3: Suíte ShadowRoteamentoTest — gate nº 2 do VALIDATION (roteamento + isolamento)** - `a0dafd94` (test)

## Files Created/Modified

- `app/Console/Commands/ConsolidarMesDesempenho.php` — 1 linha (`compute()` com `incluirEmpresasScore: true` + comentário explicando o sinal independente da feature flag)
- `app/Console/Commands/WarmDesempenhoCache.php` — import da facade `Cache` + guard condicional de recompute em torno de `computeCached()`
- `tests/Feature/Phase120/ShadowRoteamentoTest.php` — criado; 6 testes + dublê `ContadorCompanyScoreService` (extends `CompanyScoreService`, conta chamadas via `parent::`) + stub `ShadowRoteamentoTestProviderStub` de `MetricsProviderFactory`

## Decisions Made

- **Fixture só-Shopee para os testes 1-3.** Empresa com fonte financeira 100% Shopee garante `margem_amostra.n_elegivel=0` (Shopee é filtrada do denominador de margem em `computeVarMargem()`), o que força `cobertura=1.0` por definição no `compute()` (`$nElegivelAdman > 0 ? ... : 1.0`). Isso isola o teste do roteamento do shadow de qualquer instabilidade da régua/gate de margem (FIXMARG-03), que não é o alvo desta suíte.
- **Dublê por herança, não mock de framework.** `ContadorCompanyScoreService extends CompanyScoreService` sobrescreve só `computeEmpresasScore()`, incrementa um contador estático e delega a `parent::computeEmpresasScore(...)` — mantém o payload realista (necessário para o teste 6, que precisa comparar valores reais entre shadow ligado/desligado) sem reimplementar a lógica do serviço real.
- **Guard mede só a AUSÊNCIA da chave, não se o array está vazio.** `empresas_score` está SEMPRE presente no payload retornado pelo código atual (`$empresasScore?->values()->all() ?? []` — vazio quando o shadow não roda, populado quando roda). O guard do warm-cache detecta payloads verdadeiramente pré-Fase-120 (a chave nem existe), não payloads com shadow desligado computados pelo código já deployado.

## Deviations from Plan

**1. [Ajuste de execução, sem impacto de escopo] Task 2 e Task 3 commitadas juntas**

O plano descreve Task 2 (testes 1-3, gate item 2/3) e Task 3 (testes 4-6, gate item 1 + isolamento) como tasks separadas, ambas editando o MESMO arquivo `tests/Feature/Phase120/ShadowRoteamentoTest.php` (Task 3 "acrescenta" à suíte criada na Task 2). Escrevi as 6 asserções em uma única passada do arquivo — o dublê contador e o stub de `MetricsProviderFactory` são infraestrutura compartilhada entre as duas tasks, e revisar/verificar os 6 testes juntos (incluindo a interação entre o teste 4 e o teste 1, que usam o MESMO dublê estático) foi mais coerente do que duas edições incrementais do mesmo arquivo. Resultado idêntico ao especificado — 6 testes, mesmo conteúdo funcional — só o agrupamento do commit mudou (1 commit em vez de 2). Nenhuma tarefa foi pulada; a verificação de cada task (`--filter=ShadowRoteamentoTest`, depois com `PayloadBaselineFlagOffTest`, depois `--filter=Desempenho`) rodou como especificado.

---

**Total deviations:** 1 (ajuste de agrupamento de commit, zero impacto de escopo/comportamento)
**Impact on plan:** Nenhum — todos os 6 testes especificados existem, passam, e cobrem exatamente os itens do VALIDATION.

## Issues Encountered

Durante a verificação da Task 1, `--filter="WarmDesempenhoCacheTest|ConsolidarMesDesempenho|PayloadBaselineFlagOffTest"` reportou 4 falhas em `ConsolidarMesDesempenhoCommandTest` (fixture própria daquela suíte, não desta). Investiguei revertendo temporariamente os 2 arquivos de comando (via patch reverso) e rodando a mesma suíte sem nenhuma mudança do Plano 02 — as MESMAS 4 falhas ocorreram, confirmando que são pré-existentes (parte da baseline de 14 falhas em `--filter=Desempenho`, não enumeradas nominalmente no 120-01-SUMMARY.md mas já presentes antes desta execução). Reapliquei o patch (task 1 preservada) e prossegui. Nenhuma correção foi necessária — está fora de escopo (RULE de scope boundary: falha pré-existente em arquivo não tocado por este plano).

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- AGRE-02 fechado: o shadow existe, roda com garantia nos comandos (`desempenho:consolidar-mes`/`desempenho:warm-cache`), é auditável (`empresas_score` no `breakdown_json`), e comprovadamente não vaza para leitura interativa.
- A flag `metrics.performance_company_first_score` continua `false` — nada nesta wave a liga. Ligar em produção segue dependendo do GATE MPP-04 (hoje `reprovado`) e do delta aceito na Fase 121.
- `--filter=Desempenho` — 14 failed / 94 passed, idêntico à baseline documentada; zero regressão nova.
- Fase 121 (comparador antigo × novo) pode agora ler `empresas_score` persistido nos snapshots gerados por `desempenho:consolidar-mes` a partir desta execução.

## Self-Check

- `app/Console/Commands/ConsolidarMesDesempenho.php` — FOUND
- `app/Console/Commands/WarmDesempenhoCache.php` — FOUND
- `tests/Feature/Phase120/ShadowRoteamentoTest.php` — FOUND
- Commit `587667cc` (Task 1) — FOUND em `git log --oneline`
- Commit `a0dafd94` (Task 2+3) — FOUND em `git log --oneline`

## Self-Check: PASSED

---
*Phase: 120-agrega-o-do-profissional-feature-flag-v21-0*
*Completed: 2026-07-30*
