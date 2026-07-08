---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-02
subsystem: nps
tags: [nps, service, score-calculator, dimensao, snapshot, avg, laravel-12, tdd, phase69]
wave: 1
requires:
  - Phase 68 tabelas nps_response_answers com colunas snapshot
  - Phase 68 NpsTemplateQuestion::DIMENSOES enum
  - Phase 68 indice composto nps_ans_response_dim_idx
provides:
  - App\Services\Nps\NpsScoreCalculator::compute(NpsResponse, dimensao): ?float
  - Fonte unica de verdade para "nota por dimensao" em v15.0
affects:
  - Consumido por Phase 72 dashboards NPS-E-05 (nao acopla ainda)
  - Consumido por Phase 73 CalculateGoalResults / NPS-F-03 (nao acopla ainda)
tech_stack:
  patterns:
    - Stateless service em App\Services\Nps (segue padrao UnifiedMetricsService)
    - Whitelist strict via in_array($v, $enum, true) para defesa T-69-02-01
    - Leitura via Eloquent HasMany + Query Builder AVG (bate no indice composto)
key_files:
  created:
    - app/Services/Nps/NpsScoreCalculator.php
    - tests/Feature/Phase69/NpsScoreCalculatorTest.php
  modified: []
decisions:
  - "AVG uniforme entre tipos escala e opcoes — sem branch por tipo (research §5)"
  - "null semantico para zero answers (nao 0.0 — significa ausencia de pergunta na dimensao)"
  - "Sem round() no retorno — cast (float) preserva precisao do driver; display formata"
  - "Whitelist strict impede case-sensitive fuzzy (ESTRATEGISTA rejeitado)"
metrics:
  duration_min: 5
  completed_date: 2026-07-08
  tasks_total: 2
  tasks_completed: 2
  files_created: 2
  files_modified: 0
  tests_added: 6
  regression_suite_size: 51
  regression_pass_rate: "51/51"
---

# Phase 69 Plan 02: NpsScoreCalculator::compute Summary

## One-liner
Service stateless `App\Services\Nps\NpsScoreCalculator::compute` que calcula AVG de `option_peso_snapshot` por `question_dimensao_snapshot` sobre `nps_response_answers`, com whitelist strict de dimensao e `null` semantico para vazio — fonte unica de verdade de nota por dimensao na milestone v15.0 NPS Templates.

## Objetivo Alcancado
Fechar REQ NPS-B-02 com implementacao TDD (RED antes de GREEN) do calculador que substitui a leitura direta das colunas legadas `score_estrategista/analista/empresa` de `nps_responses`. A partir deste plan, dashboards e calculadores de meta NPS podem consumir `compute()` sem tocar nas colunas legacy — que na Phase 68 viraram NULLABLE justamente antecipando este passo.

## Contrato Entregue

```php
namespace App\Services\Nps;

public function compute(
    NpsResponse $response,
    string $dimensao,   // uma das constantes NpsTemplateQuestion::DIMENSOES
): ?float;              // AVG dos option_peso_snapshot | null
```

Comportamento:
1. **Defesa (T-69-02-01):** `$dimensao` fora da whitelist `NpsTemplateQuestion::DIMENSOES` -> retorna `null`. Whitelist strict (`in_array $strict=true`) impede cast `'' -> false` ou fuzzy case-insensitive.
2. **Query direta:** `$response->answers()->where('question_dimensao_snapshot', $d)->avg('option_peso_snapshot')`. Bate exatamente no indice composto `nps_ans_response_dim_idx` criado na Plan 68-01.
3. **Vazio -> null semantico:** zero answers da dimensao retorna `null` (nao `0.0`) — significa "nao ha pergunta desta dimensao neste template". Consumidores diferenciam display de nota-zero vs ausencia.
4. **AVG uniforme:** sem branch por tipo — answers de perguntas `escala` (labels 1..5) e `opcoes` (labels Sim/Nao) sao tratadas identicas. O peso 1..5 do snapshot ja normaliza a escala (research §5).

## Estrategia de Fallback null
Semantica dupla para retorno `null`:
- **Dimensao invalida** (input arbitrario do chamador) -> defesa
- **Zero answers da dimensao** (template nao configurou perguntas daquele eixo) -> ausencia

Ambos os cenarios sao operacionalmente identicos do ponto de vista do consumidor: "nao tem nota para exibir". O calculator NAO diferencia — Phase 72 dashboards vao renderizar "—" ou "N/A" para `null`, sem fallback para 0.0 (que seria confundido com "nota minima possivel").

## Indice Consumido
`nps_ans_response_dim_idx (response_id, question_dimensao_snapshot)` — criado na migration `2026_07_07_100001_create_nps_templates_v15_tables.php` (Plan 68-01). A query do `compute()` bate 100% no indice (WHERE composto exato) sem sequential scan.

## 6 Casos Cobertos

| # | Test | Cenario | Assertivo |
|---|------|---------|-----------|
| 1 | `test_multiplas_answers_dimensao_estrategista_retorna_avg` | 3 answers dim=estrategista pesos [3,4,5] | AVG == 4.0 |
| 2 | `test_uma_answer_retorna_o_peso` | 1 answer dim=empresa peso=5 | == 5.0 |
| 3 | `test_zero_answers_retorna_null` | Response com answers de outra dimensao | == null (nao 0.0) |
| 4 | `test_dimensao_invalida_retorna_null` | dim='xpto', '', 'ESTRATEGISTA' (case) | == null sem exception |
| 5 | `test_mistura_dimensoes_filtra_corretamente` | 2 dims mistas em 1 response | AVG isolado por eixo |
| 6 | `test_avg_uniforme_escala_e_opcoes` | 4 answers dim=geral labels mistos (5,3,Sim,Nao) pesos [5,3,5,1] | AVG == 3.5 |

## Zero Regressao Confirmada
Suite completa Phase 31 + 33 + 68 executada apos GREEN:

```
Tests:    51 passed (271 assertions)
Duration: 16.24s
```

Arquivos verificados:
- `Phase31NpsDispararMensalTest.php`
- `Phase31NpsMonthlyMailTest.php`
- `Phase31NpsSubmitTest.php`
- `Phase33NpsPerguntasExtrasTest.php`
- `Phase68/NpsSchemaTest.php`, `Phase68/NpsSeedRetroactiveTest.php`, `Phase68/NpsBackwardCompatTest.php`

## Deviations from Plan
Ajuste minor em relacao ao Plan 69-02 original:

**1. [Rule 2 — Simplificacao] Removido `round(...avg..., 2)` do retorno**
- **Encontrado durante:** Task 2 GREEN
- **Motivo:** Plan 69-02 (linha 96 research_reference) sugeria `round((float)$media, 2)` espelhando `NpsController::index`. Porem: (a) o consumidor (Phase 72 dashboards e Phase 73 meta) formata display proprio; (b) o calculo interno (meta NPS, ranking) deve preservar precisao maxima; (c) round no service perde precisao ANTES de calculos derivados.
- **Fix:** retorno e `(float) $media` cru. Display arredonda; calculo posterior nao perde precisao intermediaria.
- **Impacto nos testes:** todos os cenarios da mission foram desenhados com AVGs cleanly representaveis em float (4.0, 5.0, 4.5, 1.5, 3.5) — nao ha teste de round explicito na suite entregue.
- **Commit:** `91317ea` (GREEN)

## Threat Model Compliance
- **T-69-02-01 (Tampering — dimensao arbitrario):** MITIGADO via `in_array($dimensao, NpsTemplateQuestion::DIMENSOES, true)`. Test 4 prova rejeicao de `'xpto'`, `''` e `'ESTRATEGISTA'` (case).
- **T-69-02-02 (Information Disclosure — null vs 0.0):** ACEITO conforme plan — semantica de negocio, consumidor autentica antes.

## Auth Gates
Nenhum. Service puro sem I/O externo.

## Commits
- `d389465` — `test(69-02): RED — NpsScoreCalculatorTest 6 cenarios`
- `91317ea` — `feat(69-02): GREEN — NpsScoreCalculator compute AVG per dimensao`

## Self-Check

Arquivos criados:
- FOUND: `app/Services/Nps/NpsScoreCalculator.php`
- FOUND: `tests/Feature/Phase69/NpsScoreCalculatorTest.php`

Commits presentes em `git log`:
- FOUND: `d389465`
- FOUND: `91317ea`

Suite de regressao: 51/51 GREEN.

## Self-Check: PASSED
