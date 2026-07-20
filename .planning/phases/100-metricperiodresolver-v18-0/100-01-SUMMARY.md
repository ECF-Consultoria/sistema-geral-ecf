---
phase: 100-metricperiodresolver-v18-0
plan: 01
subsystem: metrics
tags: [carbon, period-resolution, tdd, php, laravel]

# Dependency graph
requires: []
provides:
  - "App\\Services\\Metrics\\MetricPeriodResolver — resolvedor único e determinístico de período (operacional/oficial-bônus/mês-fechado/custom)"
  - "Contrato de 14 chaves (mode, period_key, current_start, current_end, baseline_start, baseline_end, days_count, comparison_mode, timezone, data_fresh_until, bonus_payment_month, bonus_competence_month, is_current_month, is_closed)"
  - "Suite unitária cobrindo os 4 casos obrigatórios do plano canônico (§1200-1203) + edge cases (clamp de dia inexistente, bissexto, virada de ano, clamp de data_fresh_until nos 2 ramos)"
affects: ["102-desempenhoscoreservice-consumo-resolver", "103-carteira-consumo-resolver", "104-dashboard-consumo-resolver"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Service puro (só Carbon, sem Model/DB/Http/cache) determinístico sob Carbon::setTestNow()"
    - "match(true) para dispatch por period_key com guarda default lançando InvalidArgumentException pt-BR"
    - "Helper privado compartilhado (baselineJanelaMesmoTamanho) reusado por 3 dos 4 modos"
    - "buildResult() com array_replace(array_flip(RESULT_KEYS), ...) garantindo ordem/presença exata das 14 chaves do contrato"

key-files:
  created:
    - app/Services/Metrics/MetricPeriodResolver.php
    - tests/Unit/MetricPeriodResolverTest.php
  modified: []

key-decisions:
  - "current_end do modo operacional = data_fresh_until (INPUT clampado em [current_start, hoje]) — resolver nunca consulta Adman/banco, só recebe e valida o que o consumidor informar"
  - "Clamp de dia inexistente no baseline usa min(dia-do-mês-atual, daysInMonth-do-mês-anterior) — cobre bissexto e meses de 30/31 dias sem lógica especial por mês"
  - "baselineJanelaMesmoTamanho() extraído como helper único usado por last_closed_month, YYYY-MM e custom — evita 3 implementações divergentes da mesma regra de N-dias-anteriores"
  - "bonus_competence_month/bonus_payment_month só preenchidos no modo official_bonus (last_closed_month); todos os outros modos retornam null explicitamente"

requirements-completed: [PER-01, PER-02, PER-03, PER-04, PER-05, PER-06]

duration: ~15min
completed: 2026-07-20
---

# Fase 100 Plano 01: MetricPeriodResolver Summary

**Resolvedor único de período (`App\Services\Metrics\MetricPeriodResolver`) com 4 modos determinísticos (operacional/oficial-bônus/mês-fechado/custom), 14 chaves de contrato fixas e 20 testes unitários cobrindo os 4 casos obrigatórios do plano canônico + edge cases de clamp/bissexto/virada de ano.**

## Performance

- **Duration:** ~15 min
- **Tasks:** 3/3 completas (ciclo TDD RED→GREEN em cada uma)
- **Files modified:** 2 (ambos novos)
- **Commits:** 6 (3 pares test→feat)

## Accomplishments

- `MetricPeriodResolver::resolve(array $filtros): array` implementado com os 4 modos do plano: `current_month` (operacional), `last_closed_month` (oficial/bônus), `YYYY-MM` (mês fechado específico) e `custom` (período personalizado fechado).
- Os 4 casos obrigatórios do plano canônico (§1200-1203) passam exatos, com bloco âncora dedicado (`test_casos_obrigatorios_ancora_de_regressao`) para regressão futura.
- Gate de contrato PER-06 (`test_gate_de_contrato_para_todos_os_modos`) valida as 14 chaves, timezone, formato de data e inclusividade nos 4 modos num único teste parametrizado.
- Clamp de `data_fresh_until` testado nos DOIS ramos exigidos pelo plan-checker (futuro → clampa para hoje; anterior ao início → clampa para `current_start`).
- Clamp de dia inexistente (31/03 → fevereiro) e ano bissexto (2024-02-29) cobertos e verdes.
- Guardas de input inválido (`custom` sem `start`/`end`, `period_key` desconhecido) lançam `InvalidArgumentException` com mensagem pt-BR.

## Task Commits

Cada task seguiu o ciclo RED→GREEN com 2 commits atômicos:

1. **Task 1: Esqueleto + modo operacional**
   - `90dbe77` test(100-01): RED — contrato de shape + modo operacional
   - `a9bb4c2` feat(100-01): GREEN — esqueleto do resolver + modo operacional
2. **Task 2: Modo oficial/bônus + mês específico**
   - `c2b882d` test(100-01): RED — modo oficial-bônus + mês específico
   - `9c52626` feat(100-01): GREEN — modo oficial-bônus + mês específico
3. **Task 3: Modo custom + âncora + gate de contrato**
   - `52c38ee` test(100-01): RED — modo custom + âncora + gate de contrato
   - `2cd9f76` feat(100-01): GREEN — modo custom + guarda de input inválido

**Plan metadata:** não commitado (SUMMARY.md fica solto por instrução explícita da task — sessão paralela ativa nas Fases 94-96+).

## TDD Gate Compliance

Todos os 3 pares RED→GREEN confirmados via execução real de teste (não apenas inspeção de código):

- Task 1: RED com 9 testes falhando (`Class "App\Services\Metrics\MetricPeriodResolver" not found`) → GREEN com 9/9 verdes.
- Task 2: RED com 5 testes novos falhando (`Call to undefined method resolveLastClosedMonth/resolveSpecificMonth`), 9 anteriores mantidos verdes → GREEN com 14/14 verdes.
- Task 3: RED com 5 testes novos falhando (`Call to undefined method resolveCustom`), 15 anteriores mantidos verdes → GREEN com 20/20 verdes.

Sem REFACTOR — implementação ficou limpa desde a primeira passada em cada task (único ajuste foi cast `(int)` em `diffInDays()` por causa de `assertSame` estrito contra float, corrigido antes do commit GREEN da Task 1).

## Files Created/Modified

- `app/Services/Metrics/MetricPeriodResolver.php` (363 linhas) — resolvedor puro com 4 modos + helper compartilhado `baselineJanelaMesmoTamanho()` + `buildResult()` garantindo o shape de 14 chaves.
- `tests/Unit/MetricPeriodResolverTest.php` (371 linhas) — 20 testes: contrato de shape, 4 casos obrigatórios, bloco âncora, gate de contrato, clamps, bissexto, virada de ano, guardas de input inválido.

## Decisions Made

- **Clamp de dia inexistente unificado:** em vez de tratar "31→28/29" como caso especial de fevereiro, usei `min($currentEnd->day, $prevMonthAnchor->daysInMonth)` — a mesma linha cobre bissexto, meses de 30 dias e qualquer combinação futura sem `if` extra.
- **Helper de baseline único:** `baselineJanelaMesmoTamanho(Carbon $currentStart, int $daysCount)` é chamado por `last_closed_month`, `YYYY-MM` e `custom` — os 3 modos "fechados" compartilham exatamente a mesma regra de N-dias-anteriores, evitando divergência futura entre eles.
- **`data_fresh_until` tratado estritamente como INPUT:** nunca há leitura de banco/Adman dentro do resolver (mitigação T-100-02 do threat model) — o clamp em `[current_start, hoje]` é a única defesa contra "ler dia não consolidado".

## Deviations from Plan

None - plano executado exatamente como escrito. Único ajuste técnico foi um cast `(int)` em `diffInDays()` (retorna `float` em Carbon moderno) para satisfazer `assertSame` estrito — não é deviation de regra de negócio, só tipagem, corrigido dentro da própria Task 1 antes do commit GREEN.

## Issues Encountered

None além do ajuste de tipagem acima.

## Known Stubs

Nenhum. Service completo e sem dependências pendentes — os ramos `last_closed_month`, `YYYY-MM` e `custom` estavam ausentes apenas entre o commit RED e o commit GREEN de cada task (estado transitório esperado do ciclo TDD, não um stub deixado no código final).

## Threat Flags

Nenhum. As duas mitigações do threat model da fase (T-100-01 validação de `period_key`/guardas de input; T-100-02 `data_fresh_until` como INPUT com clamp) foram implementadas e testadas dentro do escopo já previsto — nenhuma superfície nova não coberta pelo `<threat_model>` do plano.

## User Setup Required

None - nenhuma configuração externa necessária. Service puro sem dependências de infraestrutura.

## Next Phase Readiness

- Contrato de 14 chaves pronto e testado para consumo pelas Fases 102-104 (migração de `DesempenhoScoreService` e controllers para usar `MetricPeriodResolver::resolve()` em vez de montar período na mão).
- **Nenhum consumidor foi reapontado nesta fase** — por desenho explícito do plano (PER-06 aqui é gate de contrato + documentação; a migração é escopo das Fases 102-104).
- Regressão da suite `tests/Unit/` completa: 12 falhas pré-existentes detectadas, TODAS em arquivos fora do escopo desta fase (ver seção abaixo) — nenhuma regressão causada por este plano.

### Regressão — falhas pré-existentes fora do escopo (não corrigidas, conforme constraint)

Rodando `php artisan test --testsuite=Unit` após a conclusão: **131 passed, 12 failed** (nenhum dos 12 é `MetricPeriodResolverTest`, que está 20/20 verde). As 12 falhas são anteriores a este plano e não relacionadas a período/Carbon:

- `Tests\Unit\CalcularFaixaTest` (8 testes) — `ArgumentCountError` ao invocar `AdminController::calcularFaixa()` via reflection; assinatura do método parece ter mudado em outra branch/sessão paralela, sem relação com Metrics.
- `Tests\Unit\CompanyServiceTypeTest` (1 teste) — `service type aceita polo`: `assertDatabaseHas` falha, indício de migration/enum de `service_type` dessincronizado do teste (relacionado à memória do projeto sobre enum+SQLite CHECK).
- `Tests\Unit\Phase39\MercadoLivreSugadoresProviderTest` (2 testes) — normalização de payload ML retornando string vazia em vez de `AD-ML-1`/chaves `AD-1`/`AD-2` ausentes; não relacionado a `MetricPeriodResolver`.

Nenhum destes arquivos foi tocado por esta execução (fronteira respeitada: só `MetricPeriodResolver.php` + `MetricPeriodResolverTest.php`). Reportado sem correção, conforme instrução explícita da task.

---
*Phase: 100-metricperiodresolver-v18-0*
*Completed: 2026-07-20*
