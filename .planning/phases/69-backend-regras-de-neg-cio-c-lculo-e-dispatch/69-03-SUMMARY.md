---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-03
subsystem: nps
type: execute
wave: 3
tags: [nps, controller, submit-response, validacao-dinamica, snapshot, dedup-guard, query-exception-23000, phase69, tdd]
requirements: [NPS-B-03, NPS-B-05]
depends_on: [69-02, 69-04, 68-04]
dependency-graph:
  requires:
    - 69-02 (NpsScoreCalculator disponivel; nao consumido aqui, mas AVG lera as answers gravadas)
    - 69-04 (generate consome NpsTemplateService — surveys nascem com template_id populado que este plan valida)
    - 68-01 (nps_response_answers snapshot columns + nps_responses.score_* nullable)
    - 68-04 (nps_surveys dedup unique parcial — dispara QueryException 23000 aqui)
  provides:
    - "NpsController::submitResponse dinamico com branch v15/legacy discriminado por template_id"
    - "Guard QueryException 23000 -> render Nps/AlreadyCompleted (UX de duplicata mensal)"
    - "Snapshot congelado per-row em nps_response_answers (fonte de verdade para Phase 72 dashboards)"
  affects:
    - 71 (form publico Nps/Respond.jsx — payload muda de score_* para answers.<qid>=option_id)
    - 72 (dashboards NPS-E-05 leem via NpsScoreCalculator das answers gravadas por este plan)
    - 73 (CalculateGoalResults NPS-F-03 le AVG do calculator alimentado por este plan)
tech-stack:
  added: []
  patterns:
    - Discriminacao de fluxo via propriedade FK nullable (template_id)
    - Metodos privados auxiliares (submitResponseV15 + submitResponseLegacy) sem quebrar constructor
    - Rules dinamicas via foreach + Rule::in por chave answers.<qid>
    - try/catch QueryException com filtro por getCode() '23000'
    - Snapshot per-row congelado dentro da DB::transaction (atomicidade)
key-files:
  created:
    - tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php
  modified:
    - app/Http/Controllers/NpsController.php
decisions:
  - "Discriminacao por template_id !== null em vez de flag booleana explicita — a existencia da FK ja e o discriminador natural, sem coluna extra. Nulo -> row legacy Phase 31/33; populado -> row v15.0 pos-Phase 69."
  - "Metodos auxiliares privados submitResponseV15/Legacy dentro do mesmo controller — evita conflito de merge com Plan 69-04 que reescreveu generate() no MESMO arquivo. Constructor injection do NpsTemplateService fica no generate, o submit nao precisa de service dependency."
  - "Rule::in por pergunta (option_ids daquela pergunta especificamente) em vez de flatMap do template inteiro — mais preciso: option_id valido para pergunta X mas nao para pergunta Y ainda e barrado. Elimina T-69-03-01 (tampering entre perguntas)."
  - "score_estrategista/analista/empresa gravados NULL no fluxo v15.0 — fonte de verdade migra pra nps_response_answers. NpsResponseFactory ja default null (Phase 68 Plan 05); rows Phase 31 legadas continuam populando. Phase 68 Plan 01 preparou o terreno com colunas nullable."
  - "Guard 23000 renderiza mesmo componente Nps/AlreadyCompleted usado pelo respond() em survey.status=completed — UX semanticamente identica ('esta pesquisa ja foi respondida'). Sem prop extra necessaria; a tela existente ja e clara sem contexto adicional."
  - "Pre-existente Nps/AlreadyCompleted.jsx foi reusado sem modificacao — nao introduz novo componente frontend, evita necessidade de vite build extra."
metrics:
  tasks_total: 2
  tasks_completed: 2
  files_created: 1
  files_modified: 1
  commits: 2
  tests_added: 7
  tests_passed: 7
  regression_tests_verified: 79
  duration_min: 7
  completed_date: 2026-07-08
---

# Phase 69 Plan 03: submitResponse Dinamico v15.0 + Guard 23000 Summary

**One-liner:** `NpsController::submitResponse` agora discrimina por `template_id`: fluxo v15.0 monta rules dinamicas do template snapshot com `Rule::in` por pergunta, grava 1 `NpsResponseAnswer` congelado por pergunta e captura `QueryException 23000` do dedup unique parcial para renderizar `Nps/AlreadyCompleted` — fluxo legacy Phase 31/33 preservado 100% em surveys com `template_id=NULL`.

## Padrao de Discriminacao template_id

O metodo publico `submitResponse` faz apenas 3 coisas antes do dispatch:

```php
public function submitResponse(Request $request, string $token)
{
    $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

    if ($survey->isExpired()) {
        return response()->json(['error' => 'Pesquisa expirada.'], 422);
    }

    if ($survey->template_id !== null) {
        return $this->submitResponseV15($request, $survey);
    }
    return $this->submitResponseLegacy($request, $survey);
}
```

O discriminador e a **propria FK nullable** — sem coluna extra, sem flag booleana redundante:

- `template_id !== null` -> row nasceu pos-Phase 69 (via `generate()` reescrito no Plan 69-04 ou `nps:disparar-mensal` reescrito no Plan 69-05) OU foi retro-associada pelo seed Phase 68 Plan 03. **Fluxo v15.0 dinamico.**
- `template_id === null` -> row nasceu antes da Phase 69 E o seed retro nao a associou (edge case). **Fluxo legacy Phase 31/33 preservado.**

## 2 Metodos Auxiliares Privados

### submitResponseV15 (novo, 74 linhas)

```php
private function submitResponseV15(Request $request, NpsSurvey $survey)
{
    $survey->load('template.questions.options');

    // Guard defesa: template_id != null mas template null (edge case teorico)
    if (!$survey->template) {
        return response()->json(['error' => 'Template do NPS nao encontrado.'], 422);
    }

    // Rules dinamicas do template snapshot
    $rules = [
        'respondent_name' => 'nullable|string|max:255',
        'comment'         => 'nullable|string|max:2000',
        'answers'         => 'nullable|array',
    ];
    $questionsById = [];
    $optionsByQuestion = [];
    foreach ($survey->template->questions as $q) {
        $questionsById[$q->id] = $q;
        $optionsByQuestion[$q->id] = $q->options->keyBy('id');
        $req = $q->obrigatoria ? 'required' : 'nullable';
        $rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($q->options->pluck('id')->all())];
    }
    $validated = $request->validate($rules);

    try {
        DB::transaction(function () use (...) {
            $response = NpsResponse::create([
                'survey_id'          => $survey->id,
                'respondent_name'    => $validated['respondent_name'] ?? null,
                'score_estrategista' => null,   // fonte migra pra answers
                'score_analista'     => null,
                'score_empresa'      => null,
                'comment'            => $validated['comment'] ?? null,
            ]);

            foreach (($validated['answers'] ?? []) as $qid => $optionId) {
                if ($optionId === null || $optionId === '') continue;
                $question = $questionsById[$qid] ?? null;
                $option   = $optionsByQuestion[$qid][$optionId] ?? null;
                if (!$question || !$option) continue;

                NpsResponseAnswer::create([
                    'response_id'                => $response->id,
                    'template_question_id'       => $question->id,
                    'template_option_id'         => $option->id,
                    'question_texto_snapshot'    => $question->texto,
                    'question_dimensao_snapshot' => $question->dimensao,
                    'option_label_snapshot'      => $option->label,
                    'option_peso_snapshot'       => $option->peso,
                    'comentario'                 => null,
                ]);
            }

            $survey->update(['status' => 'completed', 'completed_at' => now()]);
        });
    } catch (QueryException $e) {
        if ((string) $e->getCode() === '23000') {
            return Inertia::render('Nps/AlreadyCompleted');
        }
        throw $e;
    }

    return Inertia::render('Nps/ThankYou');
}
```

### submitResponseLegacy (preservado, 65 linhas)

Corpo idêntico ao `submitResponse` pré-Phase 69 — apenas movido para método privado auxiliar. Zero mudanca de comportamento: rules `score_estrategista|analista|empresa` hardcoded 1..5 + validação dinâmica das `NpsPerguntaCustomizada` Phase 33 (`respostas_extras.<pid>`) + persistência de `NpsResponse` com scores legados + `NpsRespostaCustomizada` por pergunta extra.

## Estrutura da Validacao Dinamica (Rule::in por Pergunta)

Cada pergunta do template gera 1 regra em `answers.<qid>`:

```php
$rules["answers.{$q->id}"] = [
    $q->obrigatoria ? 'required' : 'nullable',
    'integer',
    Rule::in($q->options->pluck('id')->all())  // IDs SO daquela question
];
```

**Por que `Rule::in` **por pergunta** em vez de flatMap do template inteiro?**

Se agrupássemos todos os `option_ids` do template num único set e usássemos como whitelist, um atacante poderia enviar `answers.42 = <option_id da pergunta 43>` — semanticamente inválido (option de outra pergunta) mas passaria pela regra. Precisão por pergunta elimina esse vetor (mitigação T-69-03-01 do threat model).

Cobertura de teste no `Test 3` (`test_submit_v15_valida_option_id_pertence_ao_template`): cria 2 templates, envia `option_id` do template B para uma pergunta do template A → 422 confirmado.

## Como o Guard 23000 Renderiza AlreadyCompleted

O `try/catch` envelopa a transação inteira. O último statement dentro da transação é o `$survey->update(['status' => 'completed', 'completed_at' => now()])` — este UPDATE é o gatilho do `partial unique index` do Plan 68-04:

```sql
CREATE UNIQUE INDEX nps_surveys_dedup_uniq
    ON nps_surveys(company_id, month_reference, template_id)
    WHERE status = 'completed' AND completed_at IS NOT NULL
```

Se já existe outra survey `(company_id, month_reference, template_id)` `completed` com `completed_at IS NOT NULL`, o novo UPDATE viola o índice → SQLite/MySQL dispara SQLSTATE 23000 → Laravel envelope em `Illuminate\Database\QueryException` → o `catch` filtra por `getCode() === '23000'` → **renderiza a mesma tela** `Nps/AlreadyCompleted.jsx` que o `respond()` GET já usa quando `status=completed`. UX semanticamente idêntica.

Rollback automático da transação garante que nenhuma linha "orfã" foi criada — o `NpsResponse` e seus `NpsResponseAnswer` inseridos antes do UPDATE são desfeitos junto.

Cobertura no `Test 5` (`test_submit_v15_captura_query_exception_23000_e_renderiza_already_completed`): cria 2 surveys da mesma tupla, marca 1 como completed, POST no pending → assertOk() + `assertInertia(component=Nps/AlreadyCompleted)` + verifica que pending permaneceu `status=pending` (rollback confirmado).

## Preservacao do Fluxo Phase 33 Legacy (respostas_extras + NpsPerguntaCustomizada)

O metodo `submitResponseLegacy()` é uma cópia byte-a-byte do corpo original de `submitResponse` (linhas 384-472 do pre-Phase 69). Não há mudança de comportamento:

- Perguntas customizadas `NpsPerguntaCustomizada::where('ativa', true)` continuam sendo carregadas por request
- Rules dinâmicas por tipo (`escala_1_5`, `texto`, `sim_nao`, `multipla`) permanecem
- `NpsRespostaCustomizada` continua sendo criada com `pergunta_texto_snapshot` + `tipo_snapshot`

Isso garante que:
- `Phase31NpsSubmitTest` (7 tests) permanecem verdes
- `Phase33NpsPerguntasExtrasTest` (9 tests) permanecem verdes
- Rows Phase 31/33 já em produção continuam podendo ser respondidas se o `template_id=null` (não haverá edge case de "survey antigo já em campo que agora fica quebrado")

## 7 Casos Cobertos + Regressao Zero

Suite Feature `tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php`:

| # | Test | Cenario | Assertivo chave |
|---|------|---------|-----------------|
| 1 | `test_submit_v15_grava_1_response_answer_por_pergunta_respondida_com_snapshot` | 3 perguntas 3 dims 5 opts, POST responde as 3 | 3 answers criados com snapshot congelado; edit posterior do texto NAO altera snapshot |
| 2 | `test_submit_v15_valida_obrigatoria_via_template_snapshot` | pergunta obrigatoria=true, POST sem answer | 302 + `assertSessionHasErrors('answers.{qid}')` + 0 rows criadas |
| 3 | `test_submit_v15_valida_option_id_pertence_ao_template` | option_id do template B para pergunta do template A | 302 + erro validation; 0 rows criadas |
| 4 | `test_submit_v15_permite_pergunta_nao_obrigatoria_omitida` | 1 pergunta obrigatoria + 1 opcional, POST so a obrigatoria | 200 + 1 answer (nao 2) |
| 5 | `test_submit_v15_captura_query_exception_23000_e_renderiza_already_completed` | 2 surveys mesma tupla, 1 completed + 1 pending, POST no pending | 200 + `assertInertia(Nps/AlreadyCompleted)`; pending permanece pending (rollback) |
| 6 | `test_submit_v13_legacy_survey_sem_template_id_preserva_fluxo_phase31` | survey template_id=null + payload Phase 31 hardcoded | 200 + NpsResponse com score_* populados + 0 NpsResponseAnswer |
| 7 | `test_submit_v15_scores_legados_score_estrategista_etc_permanecem_null` | POST v15.0 valido em template com 2 perguntas | NpsResponse.score_estrategista/analista/empresa = null + 2 answers criadas |

**Zero regressao confirmada** — comando executado:

```bash
php artisan test \
  tests/Feature/Phase31NpsSubmitTest.php \
  tests/Feature/Phase31NpsDispararMensalTest.php \
  tests/Feature/Phase31NpsMonthlyMailTest.php \
  tests/Feature/Phase33NpsPerguntasExtrasTest.php \
  tests/Feature/Phase68/ \
  tests/Feature/Phase69/
```

Resultado consolidado:

| Suite | Tests | Passed | Regressao? |
|-------|-------|--------|------------|
| Phase31NpsSubmit | 7 | 7 | preservado |
| Phase31NpsDispararMensal | 7 | 7 | preservado |
| Phase31NpsMonthlyMail | 5 | 5 | preservado |
| Phase33NpsPerguntasExtras | 9 | 9 | preservado |
| Phase68/NpsSchema | 11 | 11 | preservado |
| Phase68/NpsSeedRetroactive | 7 | 7 | preservado |
| Phase68/NpsBackwardCompat | 5 | 5 | preservado |
| Phase69/NpsDispararMensalTemplate | 5 | 5 | preservado (Plan 69-05) |
| Phase69/NpsGenerateFlow | 5 | 5 | preservado (Plan 69-04) |
| Phase69/NpsScoreCalculator | 6 | 6 | preservado (Plan 69-02) |
| Phase69/NpsTemplateService | 5 | 5 | preservado (Plan 69-01) |
| **Phase69/NpsSubmitDynamicValidation (novo)** | **7** | **7** | **entregue** |

**Total: 79 tests verdes** (7 novos + 72 preservados). Zero regressao em cadeia.

## Deviations from Plan

**Nenhuma deviation material.** Plan seguido linha-a-linha:

1. Arquivo de teste com nome exato do PLAN.md (`NpsSubmitDynamicValidationTest.php`) — não o alias mais curto sugerido pela mission (`NpsSubmitFlowTest.php`) — preserva os greps do bloco `acceptance_criteria` do 69-03-PLAN.md.
2. Estrutura de 7 tests com nomes exatos dos greps do acceptance_criteria (`submit_v15_grava_1_response_answer_por_pergunta_respondida`, `submit_v15_captura_query_exception_23000_e_renderiza_already_completed`, `submit_v13_legacy_survey_sem_template_id_preserva_fluxo_phase31`).
3. Nenhum novo componente frontend criado — `Nps/AlreadyCompleted.jsx` já existia e foi reutilizado sem modificação.

## Auth Gates

Nenhum. Endpoint `POST /nps/{token}` é publico (auth via token opaco, sem middleware auth) — Phase 31 D-06 estabeleceu essa fronteira e Phase 69 preserva.

## Commits

| Commit | Tipo | Descrição |
|--------|------|-----------|
| `5f5f6da` | test | RED — NpsSubmitDynamicValidationTest 7 cenarios (v15 + legacy) |
| `eaccd3d` | feat | GREEN — submitResponse v15.0 dinamico + guard 23000 + preserva legacy |

## Threat Model Compliance

Todas as mitigacoes STRIDE do PLAN respeitadas:

- **T-69-03-01 (Tampering — option_id de outro template):** MITIGADO via `Rule::in` **por pergunta** (não flatMap do template inteiro). Test 3 prova.
- **T-69-03-02 (Tampering — question_id fora do template):** MITIGADO via iteração sobre `$survey->template->questions` (fonte trusted); chaves extras em `answers.*` que não batem caem em `$questionsById[$qid] ?? null = null` e são puladas silenciosamente.
- **T-69-03-03 (Race condition — 2 POSTs completando):** MITIGADO via `QueryException 23000` catch + `Nps/AlreadyCompleted` render. Test 5 prova + rollback da transação garante integridade (verificado explicitamente pelo assert `$surveyPending->status === 'pending'` após catch).
- **T-69-03-04 (DoS — templates gigantes):** ACEITO conforme plan (volume esperado ≤ 30 perguntas × ≤ 5 opts, single request, eager load 1x).
- **T-69-03-05 (Info Disclosure via 422):** ACEITO conforme plan (`Rule::in` retorna mensagem genérica de validação, não expõe lista de options).

Nenhum surface novo introduzido — endpoint pré-existente, só a lógica interna mudou.

## Threat Flags

Nenhum surface novo detectado — o endpoint público POST `/nps/{token}` já era o superfície-alvo do Phase 31 (D-06) e continua com mesmo guard (token opaco). Nova coluna FK ou tabela não foi introduzida.

## Referencias

- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-03-PLAN.md`
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-02-SUMMARY.md` (NpsScoreCalculator — consumidor downstream das answers)
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-04-SUMMARY.md` (generate — populador upstream do template_id)
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md` (fundação schema + seed retro + dedup unique parcial)
- `.planning/research/v15-nps-templates-schema.md` §1 (snapshot per-row) + §2 (dedup 23000) + §5 (escala uniforme com opcoes)
- `app/Http/Controllers/NpsController.php` linhas 384-618 (submitResponse + submitResponseV15 + submitResponseLegacy)
- `tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php`
- REQ NPS-B-03 fechado (guard 23000 → AlreadyCompleted)
- REQ NPS-B-05 fechado (validação dinâmica derivada do template snapshot)

## Self-Check: PASSED

- `tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php` — FOUND
- `app/Http/Controllers/NpsController.php` com `submitResponseV15` + `submitResponseLegacy` + `QueryException` + `23000` + `Nps/AlreadyCompleted` — FOUND (todos os 12 greps do acceptance passam)
- commit `5f5f6da` (RED) — FOUND em `git log`
- commit `eaccd3d` (GREEN) — FOUND em `git log`
- 7/7 tests novos verdes + 72 tests legados intactos = 79 verdes totais
