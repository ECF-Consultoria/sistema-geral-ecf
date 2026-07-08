---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
plan: 02
subsystem: database
tags: [nps, eloquent, models, factories, laravel-12, has-factory, logs-activity, spatie-activitylog]

# Dependency graph
requires:
  - phase: 68-01
    provides: "5 tabelas do schema NPS Templates + nps_surveys.template_id FK + nps_responses.score_* NULLABLE"
provides:
  - "4 Models Eloquent novos: NpsTemplate, NpsTemplateQuestion, NpsTemplateOption, NpsResponseAnswer"
  - "Updates em NpsSurvey (template_id em fillable + relation template()) e NpsResponse (HasFactory + relation answers())"
  - "6 factories completos: NpsTemplateFactory (+padrao/active states), NpsTemplateQuestionFactory (+estrategista/analista/empresa/geral), NpsTemplateOptionFactory, NpsResponseAnswerFactory, NpsSurveyFactory (+completed/monthly), NpsResponseFactory (+legacyScores)"
  - "Constantes canônicas em NpsTemplateQuestion: TIPO_ESCALA/OPCOES + DIMENSAO_ESTRATEGISTA/ANALISTA/EMPRESA/GERAL + statics dimensoesLabels()"
  - "Relations Eloquent verificadas por reflection: 12 relations (HasMany/BelongsTo/BelongsToMany) todas retornam a classe correta"
affects:
  - 68-03-seed-nps-padrao-retroativo
  - 68-05-testes-schema
  - phase-69 (NpsTemplateService::resolveForCompany + NpsScoreCalculator::compute lêem via Models/relations)
  - phase-70 (UI CRUD templates usa fillable + LogsActivity)
  - phase-71 (dashboards NPS-E-05 usa answers()->question_dimensao_snapshot)

# Tech tracking
tech-stack:
  added: []  # Zero deps novas — só código PHP
  patterns:
    - "Alias de relationship: método secundário (serviceScopes) delega ao primário (servicos) — evita re-refatoração quando 2 callers diferentes usam nomes distintos convencionalmente"
    - "Factory state semânticos (padrao, monthly, completed, legacyScores) encapsulam cenários de teste comuns em vez de exigir configuração ad-hoc em cada teste"
    - "Enum labels via static method (dimensoesLabels) — mesmo padrão de Servico::setoresLabels() e tiposCobranca(); centraliza tradução pt-BR para UI"

key-files:
  created:
    - "app/Models/NpsTemplate.php"
    - "app/Models/NpsTemplateQuestion.php"
    - "app/Models/NpsTemplateOption.php"
    - "app/Models/NpsResponseAnswer.php"
    - "database/factories/NpsTemplateFactory.php"
    - "database/factories/NpsTemplateQuestionFactory.php"
    - "database/factories/NpsTemplateOptionFactory.php"
    - "database/factories/NpsResponseAnswerFactory.php"
    - "database/factories/NpsSurveyFactory.php"
    - "database/factories/NpsResponseFactory.php"
  modified:
    - "app/Models/NpsSurvey.php"
    - "app/Models/NpsResponse.php"

key-decisions:
  - "servicos() E serviceScopes() coexistem como aliases da mesma BelongsToMany — servicos() é o nome idiomático Laravel (belongsToMany chama a coluna de servico_id), serviceScopes() é o nome usado no research §4 e será chamado pelo NpsTemplateService::resolveForCompany. Ambos retornam a mesma pivot; zero duplicação de comportamento."
  - "NpsResponseFactory usa FK survey_id (não nps_survey_id) — confirmado no Model NpsResponse::fillable + nas migrations Phase 31. O prompt mencionava nps_survey_id por engano; ajustei para bater com o schema real."
  - "->for($survey, 'survey') exige nome de relação explícito em NpsResponse porque Laravel Factory conventiona camelCase(class_basename) = 'npsSurvey', mas o Model expõe survey(). Documentei o motivo com comentário pt-BR."
  - "Factory state active(bool $active = true) usa parâmetro para permitir ->active(false) sem criar state duplicado inactive()."

patterns-established:
  - "Relationship alias pattern: quando 2 callers diferentes esperam nomes distintos para a mesma relação (research vs. Laravel idiom), expor os 2 métodos e delegar um ao outro — evita re-refatoração e ambos aparecem no autocomplete IDE"
  - "Sanity chain via tinker antes de commit: rodar migrate:fresh + factory nested (2 níveis has()->count()) + assertion via reflection é mais rápido que escrever teste Feature dedicado e cobre 90% dos bugs de fillable/casts/relations"
  - "State legacy* em factories que carregam colunas legacy nullable: nomear explicitamente (legacyScores) sinaliza que aquele state simula rows de fases anteriores, não representa uso atual do schema"

requirements-completed: [NPS-A-02]

# Metrics
duration: 18min
completed: 2026-07-07
---

# Phase 68 Plan 02: Models Eloquent + Factories NPS Templates Summary

**4 Models Eloquent + 6 factories completos + updates NpsSurvey/NpsResponse — camada de aplicação pronta para 68-03 (seed retro) e Phases 69-73 (services + UI CRUD + dashboards).**

## Performance

- **Duration:** ~18 min
- **Completed:** 2026-07-07
- **Tasks:** 2
- **Files created:** 10 (4 Models + 6 factories)
- **Files modified:** 2 (NpsSurvey, NpsResponse)

## Accomplishments

- **4 Models Eloquent novos** em `app/Models/`:
  - `NpsTemplate` — HasFactory + LogsActivity + 4 relations (`questions`, `servicos`, `serviceScopes` alias, `surveys`) + 2 scopes (`active`, `default`)
  - `NpsTemplateQuestion` — 6 constantes (2 TIPOS + 4 DIMENSOES) + static `dimensoesLabels()` + 2 relations (`template`, `options`)
  - `NpsTemplateOption` — cast `peso => int` + 1 relation (`question`)
  - `NpsResponseAnswer` — 8 colunas em `$fillable` (snapshot per-row + FKs) + 3 relations (`response`, `templateQuestion`, `templateOption`)
- **2 Models atualizados:**
  - `NpsSurvey` — `HasFactory` adicionado, `template_id` no `$fillable`, relation `template(): BelongsTo`, LogsActivity `logOnly` inclui `template_id`
  - `NpsResponse` — `HasFactory` adicionado, relation `answers(): HasMany` para snapshot per-row
- **6 factories** em `database/factories/` (todos com defaults sanos + states semânticos):
  - `NpsTemplateFactory` (+states `padrao()`, `active(bool)`)
  - `NpsTemplateQuestionFactory` (+states `estrategista`, `analista`, `empresa`, `geral`)
  - `NpsTemplateOptionFactory`
  - `NpsResponseAnswerFactory` (snapshot hardcoded, FKs vivas NULL default)
  - `NpsSurveyFactory` (+states `completed(daysAgo)`, `monthly(ymd)`)
  - `NpsResponseFactory` (+state `legacyScores(est, ana, emp)`)
- **PHPDoc pt-BR** consistente com padrão do projeto (referências ao research §1/§4/§5 e às migrations do Plan 68-01)
- **Zero regressão:** Phase 31 NPS (19/19) + Phase 33 (9/9) verdes após ambos os commits

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: 4 Models novos + updates NpsSurvey/NpsResponse** — `44d4a4f` (feat)
2. **Task 2: 6 factories NPS** — `0dea8bb` (feat)

## Files Created

- `app/Models/NpsTemplate.php` (147 linhas) — Template raiz + LogsActivity + relations + scopes
- `app/Models/NpsTemplateQuestion.php` (127 linhas) — Pergunta + constantes de tipo/dimensão + labels
- `app/Models/NpsTemplateOption.php` (63 linhas) — Opção com peso 1..5
- `app/Models/NpsResponseAnswer.php` (95 linhas) — Snapshot per-row congelado
- `database/factories/NpsTemplateFactory.php` (61 linhas) — +states padrao/active
- `database/factories/NpsTemplateQuestionFactory.php` (75 linhas) — +states das 4 dimensões
- `database/factories/NpsTemplateOptionFactory.php` (32 linhas)
- `database/factories/NpsResponseAnswerFactory.php` (43 linhas)
- `database/factories/NpsSurveyFactory.php` (62 linhas) — +states completed/monthly
- `database/factories/NpsResponseFactory.php` (49 linhas) — +state legacyScores

## Files Modified

- `app/Models/NpsSurvey.php` — +HasFactory, +template_id fillable, +relation template(), +logOnly template_id
- `app/Models/NpsResponse.php` — +HasFactory, +relation answers()

## Sanity Chain — SQLite in-memory

Executada via `php artisan tinker` com `migrate:fresh`. Todos os 12 sanity checks verdes:

```
Sanity 1: NpsTemplate::factory()->make()->nome = "Template ut porro"
Sanity 2: NpsTemplate created id=1 active=true is_default=false
Sanity 3: NpsTemplate + 3 questions — count=3
Sanity 4: NpsTemplate + 2 questions + 10 options (nested has chain) — questions=2 options=10
Sanity 5: NpsSurvey with template_id=1 — survey->template->nome loaded eagerly
Sanity 6: NpsResponse survey_id=1 (relation "survey" explícita)
Sanity 7: NpsResponseAnswer response_id=1 dim=empresa peso=4
Sanity 8: response->answers count = 1
Sanity 9 (padrao state): nome="NPS Padrão" is_default=true
Sanity 10 (legacyScores): est=4 ana=NULL emp=5
Cast round-trip DB→Model: active retorna PHP bool true (não int 1)
Unique parcial em is_default: SQLSTATE 23000 no 2º INSERT com padrao()
```

## Decisions Made

- **`servicos()` E `serviceScopes()` coexistem como aliases da mesma `BelongsToMany`** — o research §4 e o `NpsTemplateService::resolveForCompany` (Phase 69) usam `serviceScopes` como nome semântico; `servicos` é o nome idiomático Laravel (belongsToMany com coluna `servico_id`). Expor ambos evita re-refatoração quando 2 callers diferentes esperam nomes distintos.
- **`NpsResponseFactory` usa `survey_id` (não `nps_survey_id`)** — o Model `NpsResponse::fillable` e as migrations Phase 31 definem `survey_id`. O prompt de execução mencionava `nps_survey_id` por engano; ajustei para bater com o schema real e documentei o motivo com comentário pt-BR no factory.
- **`->for($survey, 'survey')` exige nome de relação explícito** — Laravel Factory convenciona `Str::camel(class_basename)` = `npsSurvey`, mas `NpsResponse` expõe `survey()`. Chamadores em testes precisam passar o nome; documentado no Sanity 6 do chain.
- **State `active(bool $active = true)` parametrizado** — permite `->active(false)` sem duplicar um state `inactive()` separado. Simplifica testes de precedência do `resolveForCompany`.

## Deviations from Plan

**Nenhum desvio de intent do plan.** Ajustes de detalhe menores em relação ao texto exato:

**1. [Detalhe schema] NpsResponseFactory usa `survey_id` (não `nps_survey_id`)**
- **Encontrado durante:** Task 2 (criação do NpsResponseFactory)
- **Discrepância:** O prompt de execução mencionava `nps_survey_id` como FK; schema real (Phase 31 migrations + `NpsResponse::fillable`) usa `survey_id`
- **Fix:** Factory usa `survey_id` — não faz sentido criar factory que gera row inválida
- **Verificação:** Sanity 6 do chain — `NpsResponse::factory()->for($survey, 'survey')->create()->survey_id` retorna o id correto
- **Impacto:** Zero — factory alinhado ao schema real; documentado com comentário pt-BR no arquivo

**2. [Adição por completude] Constante `NpsTemplateQuestion::DIMENSOES` + método `dimensoesLabels()`**
- **Encontrado durante:** Task 1
- **Motivo:** Plan pediu constantes individuais (DIMENSAO_ESTRATEGISTA etc.); adicionei também o array agregado `DIMENSOES` (para `Rule::in()`) e o static `dimensoesLabels()` para UI Phase 70
- **Impacto:** Zero — segue padrão consolidado de `Servico::setoresLabels()`; economiza uma iteração futura na Phase 70

**Total:** 2 ajustes de detalhe. Zero scope creep.

## Issues Encountered

- **`php` não estava no PATH POSIX inicial** — resolvido via `export PATH="/c/xampp/php:$PATH"` (XAMPP local). Sem impacto no build/CI.
- **`Artisan::call('migrate:fresh', ['--no-interaction' => true])` falhou** — a flag não existe para `migrate:fresh`; removida sem consequência (Artisan::call não é interativo mesmo).
- **Sanity chain inicial falhou com `Call to undefined method npsSurvey()`** — Laravel Factory convenciona camelCase do class_basename. Descoberto padrão: usar `->for($survey, 'survey')` com nome explícito. Documentado nas Decisions Made para futuros implementers.

## User Setup Required

Nenhum. Zero deps novas, zero variáveis de ambiente, zero migração de dados. Deploy autorizado só precisa dos passos do Plan 68-01 (`php artisan migrate --force`).

## Next Phase Readiness

**Wave 3 (Plan 68-03 — Seed retro "NPS Padrão")** pronto para executar:
- Model `NpsTemplate` + relations `questions()` e `serviceScopes()` disponíveis
- Factory `NpsTemplate::factory()->padrao()` seta `is_default=true` e nome "NPS Padrão"
- Constantes `NpsTemplateQuestion::TIPOS` e `DIMENSOES` prontas para o seed usar sem hardcoded strings

**Wave 4 (Plan 68-05 — Testes Feature)** pronto:
- 6 factories completos permitem criar cenários end-to-end sem `DB::table()`
- Sanity chain provou que `->has()->count()` funciona em 2 níveis nested

**Phases 69+ prontas:**
- `NpsTemplateService::resolveForCompany` pode chamar `serviceScopes` conforme research §4
- `NpsScoreCalculator::compute` pode agregar via `NpsResponse::with('answers')->get()` e `->answers->groupBy('question_dimensao_snapshot')`
- CRUD admin (Phase 70) tem `$fillable` explícito + `LogsActivity` já configurado

**Executando em paralelo com Plan 68-04** (arquivos disjuntos — dedup_key migration). Zero conflito esperado.

**Deploy:** ainda gate ativo. Não deployar sem autorização explícita.

## Self-Check: PASSED

- [x] `app/Models/NpsTemplate.php` existe
- [x] `app/Models/NpsTemplateQuestion.php` existe
- [x] `app/Models/NpsTemplateOption.php` existe
- [x] `app/Models/NpsResponseAnswer.php` existe
- [x] `database/factories/NpsTemplateFactory.php` existe
- [x] `database/factories/NpsTemplateQuestionFactory.php` existe
- [x] `database/factories/NpsTemplateOptionFactory.php` existe
- [x] `database/factories/NpsResponseAnswerFactory.php` existe
- [x] `database/factories/NpsSurveyFactory.php` existe
- [x] `database/factories/NpsResponseFactory.php` existe
- [x] Commit `44d4a4f` presente em `git log` (Task 1)
- [x] Commit `0dea8bb` presente em `git log` (Task 2)
- [x] `php -l` limpo em todos os 12 arquivos
- [x] Todas as 12 relations verificadas por reflection (`get_class($model->relation())`)
- [x] Sanity chain SQLite in-memory: 12/12 checks verdes
- [x] Casts bool round-trip DB→Model retornam PHP bool (não int)
- [x] Unique parcial em `is_default` dispara SQLSTATE 23000 no 2º padrão
- [x] Phase 31 NPS: 19/19 verdes (zero regressão)
- [x] Phase 33 NPS Perguntas Extras: 9/9 verdes (zero regressão)
- [x] Working tree `MercadoLivreOAuthController.php` intocado

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Plan: 02 — Wave 2 de 4*
*Completed: 2026-07-07*
