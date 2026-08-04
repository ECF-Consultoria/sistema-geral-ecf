---
phase: 123-telas-e-relat-rios-v21-0
plan: 07
subsystem: auth
tags: [authorization, rbac, laravel, performance-endpoint, gap-closure]

# Dependency graph
requires:
  - phase: 122-persist-ncia-por-empresa-e-comandos-v21-0
    provides: "desempenho_company_score_snapshots + CompanyScoreSnapshotReader (leitura pura)"
provides:
  - "PerformanceController::show()/::evolucao() gateados por autorizadoParaVerDesempenhoDe() (admin || dono || líder-da-equipe-do-alvo)"
  - "resolveContextoPeriodo() com regex de mês que nunca produz overflow silencioso do Carbon"
  - "CompanyScoreSnapshotReader::mapear() com shape de 14 chaves (sem faturamento absoluto nem contagem interna)"
affects: [123-verification, deploy-fase-123]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Checagem de autorização por usuário-alvo extraída em método privado reusado por dois endpoints (show/evolucao), corpo idêntico ao já aprovado em PortfolioController::transparencia()"

key-files:
  created:
    - tests/Feature/Phase123/PerformanceAutorizacaoTest.php
  modified:
    - app/Http/Controllers/PerformanceController.php
    - app/Services/Desempenho/CompanyScoreSnapshotReader.php
    - tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php

key-decisions:
  - "Regra de autorização replicada literalmente de PortfolioController::transparencia() (admin || dono || líder de setor do qual o alvo é membro via user_setores) — não inventada, para não quebrar o líder do setor Performance nem o botão Desempenho de Portfolio/Transparencia.jsx"
  - "faturamento_atual/faturamento_anterior/componentes_presentes saem só do reader (leitura), não do model/writer/tabela — continuam gravados no fechamento mensal"

requirements-completed: [UIEM-02, UIEM-03]

# Metrics
duration: 10min
completed: 2026-08-04
---

# Fase 123 Plano 07: Autorização por usuário-alvo em /performance + payload enxuto Summary

**`PerformanceController::show()`/`::evolucao()` passam a exigir admin, dono ou líder-da-equipe-do-alvo (mesma regra de `PortfolioController::transparencia()`); `?mes=` inválido nunca mais produz 500; `CompanyScoreSnapshotReader` para de expor faturamento absoluto e contagem interna.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-08-04T18:20:42Z
- **Completed:** 2026-08-04T18:30:19Z
- **Tasks:** 2/2 completos
- **Files modified:** 4 (2 de código, 2 de teste — 1 teste novo)

## Accomplishments

- **CR-01 fechado:** qualquer não-admin com `core.performance` (líder do setor Performance via `AUTO_LIDERANCA_PERFORMANCE`, ou setor inteiro via `setor_permissoes`) não consegue mais trocar o `{user}` na URL de `/performance/{user}` nem de `/api/performance/{user}/evolucao` para ler nota, faixa de bônus, nome de empresa cliente, faturamento em R$, margem e nota por empresa de outro profissional. O líder do setor Performance continua vendo quem está na própria equipe — comportamento preservado e provado por teste com `AUTO_LIDERANCA_PERFORMANCE` real (não permission manual).
- **WR-01 fechado:** a regex de `?mes=` em `resolveContextoPeriodo()` (fonte única de período de `index()` e `show()`) passou de `\d{4}-\d{2}` para `\d{4}-(0[1-9]|1[0-2])`, mesmo padrão já usado em `indexPolos()`. `?mes=2026-13` e `?mes=9999-99` caem no mês corrente por fallback em vez de estourar o `MetricPeriodResolver::resolve()` com 500.
- **WR-03 fechado:** `CompanyScoreSnapshotReader::mapear()` passou de 17 para 14 chaves — `faturamento_atual`, `faturamento_anterior` e `componentes_presentes` (confirmado por grep: zero consumidores em `resources/js`) não trafegam mais para o browser. A tabela, o writer e o model continuam intocados; só o reader (a única fonte de leitura dos 3 controllers) ficou mais enxuto.

## Task Commits

Each task was committed atomically:

1. **Task 1: Autorização por usuário-alvo em show()/evolucao() (CR-01) + regex de mês correta (WR-01)** - `85f59fc0` (fix, tdd)
2. **Task 2: Payload enxuto do CompanyScoreSnapshotReader (WR-03)** - `e06ddd01` (fix, tdd)

_Note: cada task seguiu o ciclo TDD dentro de um único commit (teste + implementação escritos e verificados juntos antes do commit, conforme o padrão já usado nas demais suítes Phase123 desta árvore)._

## Files Created/Modified

- `app/Http/Controllers/PerformanceController.php` — novo método privado `autorizadoParaVerDesempenhoDe()` (corpo idêntico a `PortfolioController::transparencia()`); `abort_unless()` como primeira linha de `show()` e `evolucao()`; regex de `resolveContextoPeriodo()` corrigida.
- `tests/Feature/Phase123/PerformanceAutorizacaoTest.php` (novo) — 8 testes: dono vê o próprio (show + evolucao), não-admin não vê outro (show + evolucao), líder vê a equipe, líder não vê fora da equipe, `?mes=` inválido no ranking admin e no show nunca produzem 500.
- `app/Services/Desempenho/CompanyScoreSnapshotReader.php` — `mapear()` reduzido a 14 chaves; docblock atualizado.
- `tests/Feature/Phase123/CompanyScoreSnapshotReaderTest.php` — teste de shape renomeado e atualizado para 14 chaves, com as 3 removidas somando-se ao `assertArrayNotHasKey`.

## Decisions Made

- **Regra de autorização replicada literalmente, não reinventada.** `PortfolioController::transparencia()` (linhas 214-226) já implementa em produção, para o mesmo tipo de dado (financeiro/compensação de terceiro), exatamente `admin || dono || líder de um setor do qual o alvo é membro`. Um `abort_unless(admin || dono)` cru teria quebrado o líder do setor Performance (que ganha `core.performance` via `Permissions::AUTO_LIDERANCA_PERFORMANCE` — `app/Models/User.php:232-240` — precisamente para "visão consolidada da equipe") e o botão "Desempenho" de `Portfolio/Transparencia.jsx:51`.
- **Setor de teste com slug EXATO `'performance'`**, separado do `$this->setorId` (`'performance-123'`) de `Phase123TestCase` — necessário porque `AUTO_LIDERANCA_PERFORMANCE` checa o slug literal, e o teste precisa exercitar o pacote automático de verdade (com sanity check `hasPermission('core.performance')` antes de exercitar a rota), não uma permission concedida manualmente.
- **Os 3 campos removidos do reader continuam existindo na tabela.** Não foi tocado `DesempenhoCompanyScoreSnapshot`, a migration, nem `CompanyScoreSnapshotWriter` — a decisão foi estritamente sobre o que trafega para o browser através deste reader, mantendo a superfície de dado gravado intacta para uso futuro se necessário.

## Deviations from Plan

None - plano executado exatamente como escrito. As duas tasks TDD seguiram literalmente as instruções de `<action>`, incluindo a reconfirmação por grep (zero consumidores JSX das 3 chaves removidas) exigida antes da Task 2.

## Issues Encountered

None.

## User Setup Required

None - nenhuma configuração de serviço externo necessária.

## Next Phase Readiness

- CR-01 e WR-01 (achados Critical/Warning bloqueadores do `123-VERIFICATION.md`, truth #6) estão fechados e testados nos dois lados (dono/outro, líder-vê-equipe/líder-não-vê-fora).
- WR-03 fechado, reduzindo a superfície de dado financeiro absoluto que trafegava sem consumidor.
- **Ainda pendente para a fase 123 fechar com segurança:** CR-02 (truth #7 do `123-VERIFICATION.md` — `BonusAuditoriaController::index()` mistura `nota_final` recomputada ao vivo com `nota_empresa` congelada, sem rótulo de safra) — fora do escopo deste plano (07), tratado por outro plano de gap closure da mesma fase.
- Suítes de regressão reexecutadas nesta sessão, sem regressão nova: `--filter=Phase123` 49/49 passed (41 anteriores + 8 novos), `--filter=Phase120` 18/18 passed, `--filter=Desempenho` 14 failed/102 passed (mesma baseline conhecida — pré-existente, as 14 falhas são as mesmas classes já documentadas, nenhuma em `PerformanceController` ou `CompanyScoreSnapshotReader`), `npm run test:js` 124 pass/1 fail (mesma falha pré-existente e não relacionada, `estrutura-grade-glide.test.js`).

---
*Phase: 123-telas-e-relat-rios-v21-0*
*Completed: 2026-08-04*
