---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-06
subsystem: nps
type: execute
wave: 4
tags: [nps, e2e, integracao, cross-plan, phase69, verification, wave-final]
requirements: [NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05]
depends_on: [69-01, 69-02, 69-03, 69-04, 69-05]
dependency-graph:
  requires:
    - 69-01 (NpsTemplateService::resolveForCompany — resolver central chamado nos 5 fluxos)
    - 69-02 (NpsScoreCalculator::compute — validado end-to-end no Fluxo 1 e no Fluxo 5)
    - 69-03 (submitResponse dinâmico v15 + guard 23000 — chain do Fluxo 1, 4, 5)
    - 69-04 (NpsController::generate — chain do Fluxo 2)
    - 69-05 (NpsDispararMensal — chain do Fluxo 1 e Fluxo 3)
  provides:
    - Suite E2E integrando os 5 SC do ROADMAP em cadeia real (não isolados)
    - Prova executável de que os artefatos das Waves 1-3 funcionam juntos
  affects:
    - Fecha Phase 69 (Wave 4 é a última) — desbloqueia Phase 70 (UI Config templates)
    - Marca REQs NPS-B-01, NPS-B-02, NPS-B-03, NPS-B-04, NPS-B-05 como Complete
tech-stack:
  added: []
  patterns:
    - Fluxo vertical end-to-end (dispatch → HTTP → snapshot → calculator)
    - Log::spy() + Mail::fake() para validação assíncrona sem I/O real
    - Carbon::setTestNow em setUp + reset em tearDown (padrão Phase 31)
    - Cenários compostos por teste (2A + 2B, 3A + 3B) — mais fluxos por caso
key-files:
  created:
    - tests/Feature/Phase69/NpsPhase69IntegrationTest.php
  modified: []
decisions:
  - "Suite E2E ISOLADA dos testes das Waves 1-3 (arquivo próprio) — deixar as suites das waves anteriores intactas facilita evolução independente. Cada wave documenta sua unidade; este arquivo documenta a CADEIA."
  - "GET /nps/{token} incluído no Fluxo 1 (assertInertia('Nps/Respond')) — a mission original marcou como obrigatório, e o custo é 1 assert extra que pega bugs de renderização (ex: NpsTextRenderer com Configuracao vazia crashar em prod). Zero overhead computacional."
  - "Fluxo 3 estruturado em 2 cenários (3A: dispatch OK 2 empresas / 3B: dispatch com órfã pós-delete) DENTRO do mesmo test method — replica o padrão sugerido pelo PLAN.md linhas 118-131 sem quebrar a resiliência do batch. Log::spy captura warnings do 2 runs sem interferência."
  - "Fluxo 4 (dedup 23000) usa 2 surveys da mesma tupla e envia POST em ambas. O 2º POST é o gatilho real do partial unique index — sem esse gatilho o guard do controller ficaria não exercitado em produção mesmo com o índice presente."
  - "Fluxo 5 combina validação dinâmica (Ato 1 sem Q1 → 302) + snapshot congelado (Ato 3 edita template pós-resposta) num único teste — os dois aspectos são a mesma promessa: o cliente responde uma versão histórica do template que não pode mudar retroativamente."
metrics:
  tasks_total: 1
  tasks_completed: 1
  files_created: 1
  files_modified: 0
  commits: 1
  tests_added: 5
  tests_passed: 5
  duration_min: 15
  completed_date: 2026-07-08
  assertions_added: 119
  phase69_suite_total: 33
  regression_suite_total: 51
  regression_pass_rate: "51/51"
---

# Phase 69 Plan 06: Suite E2E Integração 5 Fluxos Verticais Summary

**One-liner:** Suite Feature `NpsPhase69IntegrationTest` integra os 5 plans anteriores em 5 fluxos verticais end-to-end (dispatch → survey.template_id → GET/POST /nps/{token} → snapshot → NpsScoreCalculator + dedup 23000 + validação dinâmica) — encerra Phase 69 com 33/33 tests verdes acumulados e 51/51 regressão preservada.

## 5 Fluxos Entregues e SC Coberto

| # | Test | SC ROADMAP | REQs |
|---|------|------------|------|
| 1 | `test_fluxo_1_publico_completo_v15_e2e` | SC #1 + SC #2 | NPS-B-01 + NPS-B-02 |
| 2 | `test_fluxo_2_generate_manual_por_admin_estrategista` | SC #1 | NPS-B-01 |
| 3 | `test_fluxo_3_dispatch_batch_com_e_sem_template` | SC #4 | NPS-B-04 |
| 4 | `test_fluxo_4_dedup_bloqueia_duplicate_mensal` | SC #3 | NPS-B-03 |
| 5 | `test_fluxo_5_validacao_dinamica_e_snapshot_congelado` | SC #5 | NPS-B-05 |

Todos os 5 REQs de Phase 69 e os 5 SC do ROADMAP cobertos em cadeia executável (Artisan::call + HTTP POST + calculator + DB) — nenhum SC fica apenas em unit test.

## Detalhamento dos Fluxos

### Fluxo 1 — Público completo v15 E2E

Cadeia: `nps:disparar-mensal` → survey.template_id = padrão → GET /nps/{token} renderiza `Nps/Respond` → POST com 3 answers → 3 `NpsResponseAnswer` com snapshot congelado (question_texto/dimensao + option_label/peso) → `NpsScoreCalculator::compute(response, dim)` retorna 4.0/3.0/5.0/null.

Prova que:
- Dispatch mensal escreve `template_id` (SC #1 via `NpsTemplateService`)
- Página pública renderiza sem quebrar (regressão de UX)
- Submit v15 grava snapshot conforme research §1
- Calculator lê AVG por dimensão do snapshot (SC #2)
- Score_* legados ficam NULL (fonte de verdade migrou)

### Fluxo 2 — Generate manual (admin + estrategista)

2 cenários:
- **2A:** Admin autenticado + empresa sem contratos → survey com `template_id` = NPS Padrão (fallback do resolver), `auto_generated=false`, `expires_at` = hoje+7d, `generated_by` = admin.id
- **2B:** Estrategista não-admin (pivot `role='estrategista'`) + empresa contrata Servico A + template scoped priority=10 → survey com `template_id` = template scoped (vence padrão), `generated_by` = estrategista.id

Prova que:
- `NpsController::generate` consome `NpsTemplateService::resolveForCompany` corretamente (SC #1)
- REQ-31-08 preservado (auto_generated + expires_at + month_reference=null)
- Auth pattern superset (admin OR pivot em qualquer role) funciona para estrategista

### Fluxo 3 — Dispatch batch com e sem template

2 cenários dentro do mesmo teste (padrão sugerido pelo PLAN.md linhas 118-131):

- **3A:** Empresa 1 contrata Servico A (template scoped) + Empresa 2 sem serviço (fallback padrão). Run 1 → 2 surveys criadas com templates corretos, batch exit 0.
- **3B:** `NpsTemplate::query()->delete()` remove tudo. Empresa 3 nova → Run 2 exit 0 + `Log::shouldHaveReceived('warning')` com `company_id` de empresa 3 + `str_contains(mensagem, 'sem template')`. Empresa 3 fica sem survey.

Prova que:
- `NpsTemplateService::resolveForCompany` lança `RuntimeException` quando não há default
- `NpsDispararMensal` captura + `Log::warning` estruturado + `continue` no chunk
- Batch inteiro NÃO crasha por causa de uma empresa órfã (SC #4)
- Warning é grep-friendly (company_id + reason no contexto)

### Fluxo 4 — Dedup 23000 → AlreadyCompleted

Cadeia: 2 surveys pending da mesma tupla `(company_id, month_reference, template_id)` → POST /nps/{token1} → 200 ThankYou + survey1 completed → POST /nps/{token2} → dedup unique parcial (Plan 68-04) dispara SQLSTATE 23000 no UPDATE final → controller captura + renderiza `Nps/AlreadyCompleted` (200, não 500) → survey2 permanece pending (rollback da transação).

Prova que:
- Unique parcial `nps_surveys_dedup_uniq` (WHERE status='completed') está ativo
- Guard `catch (QueryException) getCode() === '23000'` em `submitResponseV15` funciona
- UX preservada: mesma tela que `respond()` GET renderiza quando survey.status já é completed
- Rollback transacional garante integridade (só 1 NpsResponse + 1 answer no banco)

### Fluxo 5 — Validação dinâmica + snapshot congelado

3 atos:
- **Ato 1:** Template com Q1(obrigatoria=true, texto="Texto ORIGINAL") + Q2(obrigatoria=false). POST sem Q1 → 302 + `assertSessionHasErrors("answers.{q1_id}")`. Nenhuma row criada.
- **Ato 2:** POST com Q1 preenchida + Q2 omitida → 200 ThankYou + 1 NpsResponseAnswer (só Q1) com snapshot congelado dos 4 campos.
- **Ato 3:** Admin edita `Q1.texto` para "TOTALMENTE DIFERENTE" e `Q1.options[0].peso` = 99 (pós-resposta). Answer snapshot NÃO muda; `NpsScoreCalculator::compute` retorna 5.0 (peso original), não 99.0.

Prova que:
- Validação dinâmica lê `obrigatoria` do template snapshot (SC #5, não de defaults hardcoded)
- Snapshot per-row sobrevive edições futuras do template (research §1)
- Calculator respeita o snapshot mesmo após edição destrutiva no template vivo

## Contagem Consolidada Phase 69

| Wave | Plan | Test File | Tests |
|------|------|-----------|-------|
| 1 | 69-01 | `NpsTemplateServiceTest.php` | 5 |
| 1 | 69-02 | `NpsScoreCalculatorTest.php` | 6 |
| 2 | 69-04 | `NpsGenerateFlowTest.php` | 5 |
| 2 | 69-05 | `NpsDispararMensalTemplateTest.php` | 5 |
| 3 | 69-03 | `NpsSubmitDynamicValidationTest.php` | 7 |
| **4** | **69-06** | **`NpsPhase69IntegrationTest.php` (novo)** | **5** |
| — | — | **TOTAL** | **33** |

Resultado real (comando executado):

```bash
php artisan test tests/Feature/Phase69/
Tests:    33 passed (241 assertions)
Duration: 17.55s
```

## Zero Regressão Confirmada

| Suite | Tests | Passed | Preservado? |
|-------|-------|--------|-------------|
| `Phase31NpsSubmitTest` | 7 | 7 | ✓ |
| `Phase31NpsDispararMensalTest` | 7 | 7 | ✓ |
| `Phase31NpsMonthlyMailTest` | 5 | 5 | ✓ |
| `Phase33NpsPerguntasExtrasTest` | 9 | 9 | ✓ |
| `Phase68/NpsSchemaTest` + `NpsSeedRetroactiveTest` + `NpsBackwardCompatTest` | 23 | 23 | ✓ |
| **TOTAL regressão** | **51** | **51** | **100%** |

Resultado real (comando executado):

```bash
php artisan test tests/Feature/Phase31NpsSubmitTest.php \
                 tests/Feature/Phase31NpsDispararMensalTest.php \
                 tests/Feature/Phase31NpsMonthlyMailTest.php \
                 tests/Feature/Phase33NpsPerguntasExtrasTest.php \
                 tests/Feature/Phase68/
Tests:    51 passed (271 assertions)
Duration: 16.59s
```

**Grand total NPS domain (Phase 69 + regressão):** 33 + 51 = **84 tests verdes**.

## Deviations from Plan

Nenhuma deviation material. Plan seguido linha-a-linha:

1. Nome de arquivo exato do PLAN.md (`NpsPhase69IntegrationTest.php`).
2. 5 fluxos com os nomes dos greps do acceptance_criteria da mission.
3. Assertion patterns copiados dos padrões estabelecidos pelas Waves 1-3 (assertInertia + assertSessionHasErrors + Log::shouldHaveReceived::withArgs).
4. `use RefreshDatabase` + `Mail::fake()` + `Log::spy()` conforme mission.
5. Comentários pt-BR em cada fluxo com o REQ/SC coberto.

## Auth Gates

Nenhum. Testes rodam offline via SQLite in-memory — sem I/O externo (Mail::fake + Log::spy substituem MTA e log driver).

## Commits

| Commit | Tipo | Descrição |
|--------|------|-----------|
| (próximo) | test | `test(69-06): NpsPhase69IntegrationTest E2E 5 fluxos verticais + PHASE-SUMMARY` |

## Threat Flags

Nenhum surface novo introduzido — este plan é puramente de verificação E2E dos artefatos entregues nos plans 69-01 a 69-05. As threat mitigações STRIDE já cobertas pelos plans-fonte:

- **T-69-03-01 (Tampering option_id):** validado end-to-end no Fluxo 5 (rules dinâmicas)
- **T-69-03-03 (Race condition dedup):** validado end-to-end no Fluxo 4 (2 POSTs sequenciais)
- **T-69-05-01 (DoS batch):** validado end-to-end no Fluxo 3 (batch resiliente com órfã)

## Referências

- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-06-PLAN.md`
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01..05-SUMMARY.md` (todas)
- `.planning/research/v15-nps-templates-schema.md` §1 + §2 + §4 + §5
- `tests/Feature/Phase69/NpsPhase69IntegrationTest.php`
- Padrão de setup replicado de `NpsDispararMensalTemplateTest.php` (helpers criarEmpresaElegivelHoje + atribuirEstrategista/Analista)

## Self-Check: PASSED

- `tests/Feature/Phase69/NpsPhase69IntegrationTest.php` — FOUND
- 5 métodos `test_fluxo_1..5_*` presentes no arquivo — FOUND
- Greps do acceptance_criteria PLAN.md (Artisan::call.*disparar-mensal, NpsScoreCalculator, AlreadyCompleted) — TODOS PRESENTES
- 5/5 tests novos verdes (119 assertions)
- Suite Phase 69 completa: 33/33 tests verdes (241 assertions)
- Suite regressão NPS (Phase 31 + 33 + 68): 51/51 tests verdes (271 assertions)
