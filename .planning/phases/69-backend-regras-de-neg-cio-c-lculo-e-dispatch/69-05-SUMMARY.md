---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-05
subsystem: nps
type: execute
wave: 2
tags: [nps, comando-artisan, dispatch, template-resolver, batch, log-warning, di, phase69, tdd]
requirements: [NPS-B-04]
dependency-graph:
  requires:
    - phase-69-01 (NpsTemplateService::resolveForCompany)
    - phase-68 (coluna nps_surveys.template_id + seed NPS Padrão)
  provides:
    - Comando nps:disparar-mensal populando template_id + guard resiliente
  affects:
    - Phase 71 (submit + snapshot per-row) — surveys agora carregam template_id valido para o snapshot amarrar em nps_response_answers
    - Cron production (schedule às 09:00 BRT) — comando NAO crasha mais o batch inteiro por causa de uma empresa isolada
tech-stack:
  added: []
  patterns:
    - DI via constructor em Console\Command (`parent::__construct()` obrigatorio)
    - try/catch RuntimeException por-empresa com continue — batch resiliente
    - Log::warning estruturado com company_id + company_name + reason (grep-friendly)
key-files:
  created:
    - tests/Feature/Phase69/NpsDispararMensalTemplateTest.php
  modified:
    - app/Console/Commands/NpsDispararMensal.php
decisions:
  - "Guard `resolveForCompany` posicionado APOS idempotencia + estrategista + analista — evita gerar warning para empresas que ja seriam puladas por outros motivos (evita ruido em log)"
  - "Guard `template_id` ANTES do dryRun check — modo dry-run tambem valida presenca de template, exibindo o template resolvido no line output"
  - "Idempotencia mantida em (company_id, month_reference) SEM incluir template_id — como um dia = uma empresa = um template deterministico (D-01 clamp), incluir template_id nao muda comportamento observavel; preservar assinatura Phase 31 D-12"
  - "Contador puladosSemTemplate exposto tanto no CLI summary quanto no Log::info final — dev enxerga anomalias sem precisar buscar warning nos logs de producao"
metrics:
  tasks: 2
  files_created: 1
  files_modified: 1
  commits: 2
  tests_added: 5
  tests_passed: 5
  regression_tests_verified: 67
  completed_date: 2026-07-08
---

# Phase 69 Plan 05: NpsDispararMensal + NpsTemplateService Summary

**One-liner:** Comando `nps:disparar-mensal` integrado ao `NpsTemplateService::resolveForCompany` via DI construtor; surveys criados com `template_id` populado; empresa sem template (RuntimeException) pulada com `Log::warning` estruturado sem derrubar o batch.

## Diff Aplicado no Comando

### 1. Import + DI Constructor

```php
use App\Services\Nps\NpsTemplateService;
use RuntimeException;

public function __construct(private NpsTemplateService $templateService)
{
    parent::__construct();
}
```

`parent::__construct()` obrigatório — sem ele o `Console\Command` não registra `signature`/`description`. Verificado via smoke `php artisan list | grep disparar-mensal` (comando ainda listado após DI).

### 2. Novo contador `$puladosSemTemplate`

Adicionado ao bloco de contadores no topo do `handle()` e propagado por referência para o closure do `chunkById(50, ...)`.

### 3. Chamada `resolveForCompany` + guard

Posicionada logicamente **APÓS** os guards existentes (idempotência, estrategista, analista) e **ANTES** do bloco `$dryRun` check + `NpsSurvey::create`:

```php
try {
    $template = $this->templateService->resolveForCompany($empresa);
} catch (RuntimeException $e) {
    Log::warning(
        "[NPS Mensal] empresa {$empresa->id} ({$empresa->name}) sem template aplicavel — pulando disparo",
        [
            'company_id'   => $empresa->id,
            'company_name' => $empresa->name,
            'reason'       => $e->getMessage(),
        ]
    );
    $puladosSemTemplate++;
    continue;
}
```

**Rationale do posicionamento:** empresa que já seria pulada por idempotência ou por sem estrategista NÃO precisa gerar warning "sem template" (ruído). Só reporta anomalia real quando a empresa atravessaria o dispatch normal.

### 4. `template_id` populado no `NpsSurvey::create`

Adicionada chave `'template_id' => $template->id` ao payload, preservando todas as outras chaves (token, company_id, generated_by=null, expires_at, status=pending, month_reference, auto_generated=true).

### 5. Summary CLI + `Log::info` final expõem `$puladosSemTemplate`

```
✓ Concluido: 42 surveys criadas, 42 emails enviados, 45 empresas elegiveis hoje.
  ↳ 2 pulada(s) por idempotencia (já tinha survey deste mes).
  ↳ 1 pulada(s) por nao ter estrategista atribuido.
  ↳ 0 pulada(s) por nao ter template NPS aplicavel.
```

## Decisão: Idempotência Preservada em `(company_id, month_reference)`

O check atual `NpsSurvey::where('company_id', $id)->whereDate('month_reference', $mesAtual)->exists()` **NÃO** foi alterado para incluir `template_id`. Rationale:

- **Determinismo:** D-01 (clamp de aniversário) + D-12 (chave de idempotência de mês) garantem que 1 dia = 1 empresa = 1 template deterministicamente resolvido; incluir `template_id` não muda comportamento observável.
- **Backward compat:** manter chave existente preserva zero regressão em `Phase31NpsDispararMensalTest` (7 tests).
- **Defesa em camadas:** o unique parcial DB `(company_id, month_reference, template_id) WHERE status=completed` (Plan 68-04) já bloqueia 2ª COMPLETION mesmo se algum erro criar 2 pending.

Se caso EDGE futuro emergir (por exemplo, mudança de scope no meio do dia com re-run manual esperado), virá em phase futura — fora do escopo do REQ NPS-B-04.

## Cobertura de Teste

5 testes TDD Feature em `tests/Feature/Phase69/NpsDispararMensalTemplateTest.php`, todos passando:

| # | Cenário | Método |
|---|---------|--------|
| 1 | Happy path — survey criado com `template_id` do NPS Padrão | `test_comando_dispara_survey_com_template_id_populado_do_resolver` |
| 2 | Template scoped priority=10 vence padrão (priority=0) | `test_comando_prefere_template_de_maior_priority_quando_ha_scope` |
| 3 | Nenhum template (nem seed) → empresa pulada + `Log::warning` | `test_comando_pula_empresa_sem_template_e_loga_warning_estruturado` |
| 4 | 3 empresas: 1 empresa sem template NÃO derruba o batch das outras 2 | `test_comando_nao_crasha_batch_quando_uma_empresa_sem_template` |
| 5 | 2 runs mesmo dia → guard `(company_id, month_reference)` preserva 1 survey | `test_comando_e_idempotente_quando_re_run_no_mesmo_dia_com_template_id` |

Todos com `use RefreshDatabase`, `Mail::fake()` e `Log::spy()`. Setup implícito das migrations 100001 (Servicos catálogo) + 100004 (NPS Padrão seed) roda antes de cada teste no SQLite in-memory.

## Zero Regressão Confirmada

Execução: `php artisan test tests/Feature/Phase31NpsDispararMensalTest.php tests/Feature/Phase31NpsMonthlyMailTest.php tests/Feature/Phase31NpsSubmitTest.php tests/Feature/Phase33NpsPerguntasExtrasTest.php tests/Feature/Phase68/ tests/Feature/Phase69/`

Resultado: **72 tests passed, 342 assertions**, `Duration: 21.91s`.

Cobertura por fase:
- Phase 31 — `Phase31NpsDispararMensalTest` (7), `Phase31NpsMonthlyMailTest` (5), `Phase31NpsSubmitTest` (7)
- Phase 33 — `Phase33NpsPerguntasExtrasTest` (7)
- Phase 68 — `NpsSchemaTest`, `NpsSeedRetroactiveTest`, `NpsBackwardCompatTest` (23)
- Phase 69 — `NpsTemplateServiceTest` (5, Wave 1), `NpsGenerateFlowTest` (5, Wave 2 do plan paralelo 69-04), `NpsScoreCalculatorTest` (6), `NpsDispararMensalTemplateTest` (5, este plan)

## Smoke Adicional

```bash
php artisan list | grep disparar-mensal
# → nps:disparar-mensal   Cria survey NPS auto_generated + envia email customizado...
```

Comando permanece registrado após adição do constructor DI. Verificado que a DI resolve automaticamente via container Laravel.

## Deviations from Plan

Nenhuma — plan executado exatamente como escrito. Fluxo RED → GREEN sem necessidade de REFACTOR (código de guard é curto, sem duplicação).

## Commits

| Commit | Tipo | Descrição |
|--------|------|-----------|
| `2992463` | test | RED — 5 cenários TDD falhando com `template_id === null` no survey e batch crashando |
| `1852dff` | feat | GREEN — DI + `resolveForCompany` + guard resiliente + contador `puladosSemTemplate` |

## Threat Flags

Nenhum — mitigações STRIDE do plan T-69-05-01/02/03 respeitadas:
- **T-69-05-01 (DoS):** try/catch por empresa + `continue` — Test 4 valida que batch prossegue após RuntimeException.
- **T-69-05-02 (Data corruption):** `template_id` obrigatório antes de `NpsSurvey::create`; empresa sem template é pulada em vez de criar survey degradado.
- **T-69-05-03 (Repudiation):** `Log::warning` estruturado com `company_id` + `company_name` + `reason` — grep-friendly em logs de produção.

## Referências

- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-05-PLAN.md`
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01-SUMMARY.md` (Wave 1)
- `app/Services/Nps/NpsTemplateService.php` (Plan 69-01)
- `app/Models/NpsSurvey.php` (`template_id` no fillable — Phase 68)
- `app/Mail/NpsMonthlyMail.php` (preservado — sem alteração de interface)
- `tests/Feature/Phase31NpsDispararMensalTest.php` (padrão de setup base preservado)

## Self-Check: PASSED

- app/Console/Commands/NpsDispararMensal.php — MODIFIED (constructor DI + guard + contador + template_id)
- tests/Feature/Phase69/NpsDispararMensalTemplateTest.php — CREATED
- commit 2992463 (RED) — FOUND em git log
- commit 1852dff (GREEN) — FOUND em git log
- Grep `NpsTemplateService`, `resolveForCompany`, `puladosSemTemplate`, `'template_id'`, `parent::__construct` em `NpsDispararMensal.php` — TODOS PRESENTES
- Comando `nps:disparar-mensal` continua registrado no `php artisan list` — CONFIRMED
