---
phase: 69
verified_at: 2026-07-08
status: passed
requirements_covered: [NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05]
success_criteria_covered: [1, 2, 3, 4, 5]
tests_green: 33
test_assertions: 241
regression_tests_green: 51
regression_assertions: 271
regression_delta: 0
debt_markers_found: 0
score: 5/5
---

# Phase 69 — Backend regras de negócio, cálculo e dispatch — Verificação

**Milestone:** v15.0 — NPS Templates
**Phase Goal:** Regras de negócio implementadas em services + validação server-side + dedup mensal garantido no DB + dispatch mensal usando template correto por empresa.
**Verified:** 2026-07-08
**Status:** ✅ PASSED

Verificação goal-backward completa: os 5 Success Criteria do ROADMAP, os 5 Requirements NPS-B-01..05 e as 5 decisões travadas do research foram observadas diretamente no código, complementadas por execução da suite de tests da própria phase e das suites de regressão.

---

## 1. Success Criteria (ROADMAP §Phase 69)

### SC #1 — `NpsTemplateService::resolveForCompany` (priority DESC + is_default fallback)

**Status:** ✅ VERIFIED

Evidência direta no `app/Services/Nps/NpsTemplateService.php` (114 linhas):

- Linha 84-89: query `NpsTemplate::query()->where('active', true)->whereHas('serviceScopes', ...)->orderByDesc('priority')->orderBy('id')->first()` — precedência primária + tiebreak determinístico
- Linha 75-77: leitura dos `servico_id` via `contratosServico()->active()` — usa scope real da relação
- Linha 98: fallback `NpsTemplate::where('is_default', true)->first()`
- Linha 107-111: `RuntimeException` com mensagem acionável em pt-BR referenciando a migration `2026_07_07_100004`

**Comando executado:**
```bash
/c/xampp/php/php.exe artisan test tests/Feature/Phase69/NpsTemplateServiceTest.php
```
**Resultado:** 5/5 tests verdes (retorna maior priority, desempata por id, cai no default, ignora active=false, dispara RuntimeException).

Integração E2E adicional: `NpsPhase69IntegrationTest::fluxo_1_publico_completo_v15_e2e` e `fluxo_2_generate_manual_por_admin_estrategista` PASS.

### SC #2 — `NpsScoreCalculator::compute` (AVG por dimensão; null quando vazio)

**Status:** ✅ VERIFIED

Evidência em `app/Services/Nps/NpsScoreCalculator.php` (93 linhas):

- Linha 70-72: whitelist strict `in_array($dimensao, NpsTemplateQuestion::DIMENSOES, true)` — bloqueia dimensão arbitrária retornando `null`
- Linha 78-80: `$response->answers()->where('question_dimensao_snapshot', $dimensao)->avg('option_peso_snapshot')` — lê SEMPRE do snapshot, nunca de FK viva
- Linha 82-86: null semântico preservado (não coerção para 0.0)
- Linha 90: cast `(float)` unifica MySQL string decimal vs SQLite float nativo

**Comando executado:**
```bash
/c/xampp/php/php.exe artisan test tests/Feature/Phase69/NpsScoreCalculatorTest.php
```
**Resultado:** 6/6 tests verdes (AVG múltiplas, 1 answer, zero answers → null, dimensão inválida → null, filtro por dimensão, uniforme escala/opções).

Integração E2E: `fluxo_1_publico_completo_v15_e2e` valida AVG 4.0/3.0/5.0/null; `fluxo_5_validacao_dinamica_e_snapshot_congelado` valida que calculator respeita snapshot pós-edit do template.

### SC #3 — Guard `QueryException 23000` + tela "Já respondida"

**Status:** ✅ VERIFIED

Evidência em `app/Http/Controllers/NpsController.php`:

- Linha 16: `use Illuminate\Database\QueryException;`
- Linha 469-514: transação `DB::transaction(...)` engloba `NpsResponse::create` + `NpsResponseAnswer::create` (com snapshot per-row nas 4 colunas linhas 500-503) + `$survey->update(['status' => 'completed', ...])`
- Linha 515-524: `catch (QueryException $e)` com `if ((string) $e->getCode() === '23000')` → `Inertia::render('Nps/AlreadyCompleted')`; re-throw para outros códigos
- Migration `database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php` cria `nps_surveys_dedup_uniq` (partial SQLite / virtual column MySQL) — confirmado por grep
- `resources/js/Pages/Nps/AlreadyCompleted.jsx` existe — página renderizável

**Comando executado:**
```bash
/c/xampp/php/php.exe artisan test tests/Feature/Phase69/NpsSubmitDynamicValidationTest.php
```
**Resultado:** 7/7 tests verdes; especificamente `submit v15 captura query exception 23000 e renderiza already completed` PASS. `NpsPhase69IntegrationTest::fluxo_4_dedup_bloqueia_duplicate_mensal` também PASS (2 POSTs sequenciais reais na mesma tupla).

### SC #4 — `nps:disparar-mensal` batch resiliente

**Status:** ✅ VERIFIED

Evidência em `app/Console/Commands/NpsDispararMensal.php` (291 linhas):

- Linha 63-66: DI construtor `public function __construct(private NpsTemplateService $templateService)` com `parent::__construct()` obrigatório
- Linha 88-92: contador `$puladosSemTemplate` inicializado
- Linha 145-158: `try { $template = $this->templateService->resolveForCompany($empresa); } catch (RuntimeException $e) { Log::warning(...); $puladosSemTemplate++; continue; }` — guard resiliente per-empresa
- Linhas 149-155: `Log::warning` estruturado com contexto `{company_id, company_name, reason}`
- Linha 177: `template_id => $template->id` populado no `NpsSurvey::create`
- Linhas 263-267: contador e `Log::info` final consolidados no summary CLI
- Linha 247-251: outer try/catch geral `\Throwable` com `continue` — batch não crasha mesmo para outros erros

**Comando executado:**
```bash
/c/xampp/php/php.exe artisan test tests/Feature/Phase69/NpsDispararMensalTemplateTest.php
```
**Resultado:** 5/5 tests verdes (happy path, precedência por scope, skip warning, batch resiliente 3 empresas, idempotência `(company_id, month_reference)`). `NpsPhase69IntegrationTest::fluxo_3_dispatch_batch_com_e_sem_template` PASS.

### SC #5 — Validação server-side dinâmica derivada do template snapshot

**Status:** ✅ VERIFIED

Evidência em `app/Http/Controllers/NpsController.php::submitResponseV15` (linhas 426-527):

- Linha 430: `$survey->load('template.questions.options')` — eager load do template snapshot
- Linha 445-449: rules base declarativas
- Linha 454-462: loop por pergunta gera `$rules["answers.{$q->id}"] = [$req, 'integer', Rule::in($optionIds)]` onde `$req` deriva de `$q->obrigatoria` e `$optionIds` só contém as opções DESTA pergunta (mitiga threat T-69-03-01, tampering de option_id entre perguntas)
- Linha 464: `$request->validate($rules)` — Laravel dispara 422 automaticamente
- Linha 483-506: gravação de 1 `NpsResponseAnswer` por pergunta respondida com snapshot congelado dos 4 campos (`question_texto_snapshot`, `question_dimensao_snapshot`, `option_label_snapshot`, `option_peso_snapshot`) — fiel ao research §1
- Linha 476-478: `score_estrategista/analista/empresa` gravados NULL no fluxo v15 (Phase 68 tornou nullable justamente para isso)

**Tests correlatos:** `submit v15 valida obrigatoria via template snapshot`, `submit v15 valida option id pertence ao template`, `submit v15 permite pergunta nao obrigatoria omitida`, `submit v15 scores legados score estrategista etc permanecem null` — todos PASS. `NpsPhase69IntegrationTest::fluxo_5_validacao_dinamica_e_snapshot_congelado` também PASS.

---

## 2. Requirements Coverage (REQUIREMENTS.md)

| REQ | Descrição resumida | Status | Evidência |
|-----|---------------------|--------|-----------|
| **NPS-B-01** | `NpsTemplateService::resolveForCompany` respeita priority DESC + is_default fallback via `nps_template_service_scopes` | ✅ SATISFIED | Service inteiro (`app/Services/Nps/NpsTemplateService.php`) + 5 unit tests + 2 fluxos E2E |
| **NPS-B-02** | `NpsScoreCalculator::compute` retorna AVG por dimensão ou `null` quando vazio | ✅ SATISFIED | Service (`app/Services/Nps/NpsScoreCalculator.php`) + 6 unit tests + 2 fluxos E2E |
| **NPS-B-03** | Sistema bloqueia dedup mensal (unique parcial + guard 23000 + tela "Já respondida") | ✅ SATISFIED | Guard `QueryException::getCode() === '23000'` (linhas 515-522 do controller) + migration 100005 + `Nps/AlreadyCompleted.jsx` + 1 unit test + 1 fluxo E2E |
| **NPS-B-04** | `nps:disparar-mensal` usa resolver + skip-log guard | ✅ SATISFIED | `NpsDispararMensal` com DI + try/catch RuntimeException + Log::warning estruturado + contador + template_id em create + 5 unit tests + 1 fluxo E2E |
| **NPS-B-05** | Validação server-side dinâmica derivada do snapshot | ✅ SATISFIED | `submitResponseV15` monta rules dinâmicas com `Rule::in` por pergunta + `obrigatoria` do snapshot (linhas 454-462) + 3 unit tests + 1 fluxo E2E |

**Orphans:** Nenhum. Todos os REQs do ROADMAP para Phase 69 foram declarados no `PHASE-SUMMARY.md` e implementados.

**Note:** REQUIREMENTS.md linhas 99-100 ainda marcam NPS-B-01 e NPS-B-02 como `Pending` mas o código está entregue — provável descuido de atualização de status na tabela. Não bloqueador (o `PHASE-SUMMARY.md` frontmatter lista corretamente todos como `requirements-completed`).

---

## 3. Spot-checks executáveis (grep + wc + php artisan test)

| # | Check | Comando | Resultado |
|---|-------|---------|-----------|
| 1 | Service Template existe e expõe método | `test -f app/Services/Nps/NpsTemplateService.php && grep -q "public function resolveForCompany"` | ✅ PASS (linha 70) |
| 2 | Service Calculator existe e expõe método | `test -f app/Services/Nps/NpsScoreCalculator.php && grep -q "public function compute"` | ✅ PASS (linha 65) |
| 3 | Precedência priority DESC | `grep "orderByDesc.*priority" app/Services/Nps/NpsTemplateService.php` | ✅ PASS (linha 87) |
| 4 | Fallback `is_default=true` | `grep "is_default.*true" app/Services/Nps/NpsTemplateService.php` | ✅ PASS (linha 98) |
| 5 | Calculator lê snapshot | `grep "option_peso_snapshot" app/Services/Nps/NpsScoreCalculator.php` | ✅ PASS (linha 80) |
| 6 | Controller injeta service | `grep "NpsTemplateService" app/Http/Controllers/NpsController.php` | ✅ PASS (linhas 13, 250) |
| 7 | Command injeta service | `grep "NpsTemplateService" app/Console/Commands/NpsDispararMensal.php` | ✅ PASS (linhas 9, 63) |
| 8 | Guard `QueryException` | `grep "QueryException" app/Http/Controllers/NpsController.php` | ✅ PASS (linhas 16, 515) |
| 9 | Guard código 23000 | `grep "23000" app/Http/Controllers/NpsController.php` | ✅ PASS (linha 516) |
| 10 | Split v15/legacy | `grep "submitResponseV15\|submitResponseLegacy" app/Http/Controllers/NpsController.php` | ✅ PASS (linhas 410, 413, 426, 541) |
| 11 | Log::warning no batch | `grep "Log::warning" app/Console/Commands/NpsDispararMensal.php` | ✅ PASS (linhas 133, 148) |
| 12 | Debt markers zero | `grep "TBD\|FIXME\|XXX" app/Services/Nps/*.php app/Http/Controllers/NpsController.php app/Console/Commands/NpsDispararMensal.php tests/Feature/Phase69/*.php` | ✅ PASS (0 matches) |
| 13 | Página AlreadyCompleted existe | `ls resources/js/Pages/Nps/AlreadyCompleted.jsx` | ✅ PASS |
| 14 | Migration dedup existe | `ls database/migrations/2026_07_07_100005_add_dedup_key_to_nps_surveys.php` | ✅ PASS |
| 15 | Rota POST pública sem middleware auth | `grep -B2 "nps.submit" routes/web.php` | ✅ PASS (linha 128-130 fora do grupo auth) |

---

## 4. Behavioral Spot-Checks (php artisan test)

### 4.1 Suite Phase 69 (deve dar 33 verdes / 241 assertions)

**Comando:**
```bash
/c/xampp/php/php.exe artisan test tests/Feature/Phase69/
```

**Output real:**
```
PASS  Tests\Feature\Phase69\NpsDispararMensalTemplateTest       (5/5)
PASS  Tests\Feature\Phase69\NpsGenerateFlowTest                 (5/5)
PASS  Tests\Feature\Phase69\NpsPhase69IntegrationTest           (5/5)
PASS  Tests\Feature\Phase69\NpsScoreCalculatorTest              (6/6)
PASS  Tests\Feature\Phase69\NpsSubmitDynamicValidationTest      (7/7)
PASS  Tests\Feature\Phase69\NpsTemplateServiceTest              (5/5)

Tests:    33 passed (241 assertions)
Duration: 20.97s
```

**Status:** ✅ PASS — bate exatamente com `PHASE-SUMMARY.md` (33 tests, 241 assertions).

### 4.2 Suite de regressão Phase 31 + 33 + 68 (deve dar 51 verdes)

**Comando:**
```bash
/c/xampp/php/php.exe artisan test \
  tests/Feature/Phase31NpsSubmitTest.php \
  tests/Feature/Phase31NpsDispararMensalTest.php \
  tests/Feature/Phase31NpsMonthlyMailTest.php \
  tests/Feature/Phase33NpsPerguntasExtrasTest.php \
  tests/Feature/Phase68/
```

**Output real:**
```
Tests:    51 passed (271 assertions)
Duration: 16.86s
```

**Status:** ✅ PASS — zero regressão. Legacy (`submitResponseLegacy`) preservou 100% do comportamento Phase 31/33 (rules hardcoded 1..5 + NpsPerguntaCustomizada + NpsRespostaCustomizada intactos, ver linhas 541-620 do controller).

---

## 5. Aderência ao research (`.planning/research/v15-nps-templates-schema.md`)

| § | Decisão travada | Onde no código |
|---|-----------------|-----------------|
| §1 | Snapshot per-row: 4 colunas congeladas na answer | Linhas 500-503 do controller populam `question_texto_snapshot`, `question_dimensao_snapshot`, `option_label_snapshot`, `option_peso_snapshot` |
| §2 | Unique parcial + guard 23000 | Migration 100005 + controller linha 516 `(string) $e->getCode() === '23000'` |
| §4 | Precedência priority DESC + is_default | Service `orderByDesc('priority')->orderBy('id')` (linhas 87-88) + fallback linha 98 |
| §5 | AVG uniforme escala/opcoes (sem branch por tipo) | Calculator faz `avg('option_peso_snapshot')` direto — snapshot já normaliza peso 1..5 |
| — | Whitelist strict de dimensões | Calculator linha 70 `in_array(..., DIMENSOES, true)` |

Todas as decisões travadas do research foram observadas no código.

---

## 6. Preservação de compatibilidade (contrato legado)

- **`submitResponseLegacy`** (linhas 541-620) mantém rules hardcoded `score_estrategista|analista|empresa 1..5`, `NpsPerguntaCustomizada` ativas via `Rule::in`, e persistência em `NpsRespostaCustomizada` com snapshot pergunta/tipo. Nenhuma mudança comportamental — 51/51 tests legados preservados.
- Discriminação em `submitResponse` (linhas 409-413): `if ($survey->template_id !== null)` → V15; else → Legacy. Rows criadas antes da seed retro do Plan 68-03 continuam funcionando.
- Auth pattern superset preservado em `generate` (linhas 262-267): admin OU user com empresa em qualquer role no pivot `company_users` — compat com consultores/mentores/estrategistas historicamente autorizados.
- Rota `POST /nps/{token}` (routes/web.php linha 130) permanece pública, sem middleware auth — fluxo público token-based intacto.

---

## 7. Anti-Patterns Scan

| Categoria | Padrão | Files escaneados | Ocorrências |
|-----------|--------|-------------------|-------------|
| Debt markers | `TBD\|FIXME\|XXX` | Services + Controller + Command + Tests | **0** |
| Warning cleanup | `TODO\|HACK\|PLACEHOLDER` | idem | 0 |
| Stubs UI | `placeholder\|coming soon\|will be here` | idem | 0 (uso legítimo de `placeholder` em textos config) |
| Return null vazio | `return null` em services | Calculator | 2 (semânticos, documentados: dimensão inválida + zero answers → contrato `?float`) |

Nenhum anti-pattern bloqueador.

---

## 8. Ordem de execução das waves

- **Wave 1 (paralela)** — 69-01 (`NpsTemplateService`) + 69-02 (`NpsScoreCalculator`): serviços stateless independentes, sem overlap de arquivo. ✅ Consistente.
- **Wave 2 (paralela)** — 69-04 (`NpsController::generate`) + 69-05 (`NpsDispararMensal`): consomem serviços da Wave 1. Overlap de arquivo apenas em `NpsController.php` mas em métodos distintos (`generate` vs `submitResponse`). ✅ Consistente.
- **Wave 3** — 69-03 (`NpsController::submitResponse` dinâmico): reescreve `submitResponse` sem afetar `generate` da Wave 2. Ambos os métodos coexistem no diff final. ✅ Consistente.
- **Wave 4** — 69-06 (suite E2E integração 5 fluxos verticais): consome resultado das 3 waves anteriores. ✅ Consistente.

Nenhum conflito de merge, nenhum arquivo deixado em estado intermediário.

---

## 9. Deferred / Not Yet Met

Nenhum item deferido para Phase 70+. `PHASE-SUMMARY.md` seção "Deferred (nenhum)" bate com a realidade — todos os SC do ROADMAP e todos os REQ NPS-B-01..05 estão observáveis no código e cobertos por tests.

Débitos técnicos declarados no summary (todos deliberados e fora de escopo da Phase 69):
- `nps_response_answers.template_question_id` int (não uuid) — schema Phase 68 fechado
- Round no calculator — display arredonda, service retorna `(float)` cru
- Idempotência de dispatch mantida em `(company_id, month_reference)` sem incluir template_id — comportamento D-12 preservado

---

## Gaps Summary

**Nenhum gap identificado.**

- 5/5 Success Criteria do ROADMAP verificados no código com evidência direta
- 5/5 Requirements NPS-B-01..05 satisfeitos
- 33/33 tests da Phase 69 verdes (241 assertions) — bate 1:1 com o SUMMARY
- 51/51 tests de regressão verdes (271 assertions) — zero regressão Phase 31/33/68
- 15/15 spot-checks (grep + existence + wiring) passaram
- Zero debt markers (TBD/FIXME/XXX) nos arquivos modificados
- Todas as 5 decisões travadas do research verificadas no código
- Compatibilidade legacy preservada (`submitResponseLegacy` intacto + auth superset preservado + rota pública preservada)
- Backend NPS Templates observavelmente pronto para desbloquear Phase 70 (UI Config) e Phase 71 (Formulário público)

## Veredito

## VERIFICATION PASSED

Phase 69 entrega literalmente o que o ROADMAP prometeu. Nenhum gap. Segue para Phase 70.

---

_Verified: 2026-07-08_
_Verifier: Claude (gsd-verifier) — goal-backward + adversarial stance_
