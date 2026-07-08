---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-04
subsystem: nps
type: execute
wave: 2
tags: [nps, controller, generate, template-resolver, auth-admin-estrategista, phase69, tdd]
requirements: [NPS-B-01]
dependency-graph:
  requires:
    - 69-01 (NpsTemplateService::resolveForCompany já disponível — commits d733ad4/a4e9916)
    - 68-01 (coluna template_id nullable em nps_surveys)
    - 68-03 (seed "NPS Padrão" is_default=true — fallback do resolver)
  provides:
    - "NpsController::generate() que cria NpsSurvey com template_id populado"
  affects:
    - 69-03 (submitResponse — método disjunto do generate; sem conflito de arquivo)
    - 69-06 (integração completa disparo mensal + snapshot per-row)
tech-stack:
  added: []
  patterns:
    - Method injection do service (evita mexer no constructor da classe grande)
    - Company::findOrFail antes do resolver (garante model tipado no service call)
    - Auth superset "admin OR pivot company_users em qualquer role" (compat REQ-31-08)
key-files:
  created:
    - tests/Feature/Phase69/NpsGenerateFlowTest.php
  modified:
    - app/Http/Controllers/NpsController.php
decisions:
  - "Method injection (2º parâmetro do generate) ao invés de constructor injection — evita conflito de merge com Plan 69-03 que vai atacar submitResponse no mesmo controller; padrão já usado em outras controllers do repo"
  - "Auth pattern preservado (superset admin OR user com empresa em qualquer role no pivot company_users) — mantém compat REQ-31-08 com generate manual atual E cobre estrategista automaticamente (nomenclatura pós-2026-05-22); restringir a role='estrategista' isoladamente seria regressão silenciosa contra consultores/mentores historicamente autorizados"
  - "Company::findOrFail após validate (redundante com 'exists:companies,id' mas necessário pra tipagem do model no service call) — validação dupla é barata em SQLite/MySQL indexado"
  - "Comentário pt-BR NPS-B-01 explícito no create() facilita grep e code-review durante Waves 3/4"
metrics:
  tasks: 2
  files_created: 1
  files_modified: 1
  commits: 2
  tests_added: 5
  tests_passed: 5
  regression_tests_verified: 56
  completed_date: 2026-07-08
---

# Phase 69 Plan 04: NpsController::generate consome NpsTemplateService Summary

**One-liner:** POST /nps/generate (manual admin/estrategista) agora chama `NpsTemplateService::resolveForCompany` e persiste `template_id` no `NpsSurvey`, preservando 100% da compat REQ-31-08 (auto_generated=false, month_reference=null, expires_at=+7d).

## Diff Aplicado ao Método `generate()`

**Antes** (Phase 31 — 31 linhas):
```php
public function generate(Request $request)
{
    $user = $request->user();
    $data = $request->validate(['company_id' => 'required|exists:companies,id']);

    if (!$user->isAdmin()) {
        $allowed = $user->companies()->pluck('companies.id');
        if (!$allowed->contains($data['company_id'])) {
            abort(403);
        }
    }

    $survey = NpsSurvey::create([
        'token'          => Str::uuid()->toString(),
        'company_id'     => $data['company_id'],
        'generated_by'   => $user->id,
        'expires_at'     => now()->addDays(7),
        'status'         => 'pending',
        'auto_generated' => false,
    ]);

    return back()->with([
        'success'  => 'Link NPS gerado com sucesso.',
        'nps_link' => route('nps.respond', $survey->token),
    ]);
}
```

**Depois** (Phase 69 — 46 linhas, +15 delta):
- Assinatura: `generate(Request $request, NpsTemplateService $templateService)` — method injection
- Adicionado `Company::findOrFail($data['company_id'])` + `$templateService->resolveForCompany($company)`
- Adicionada chave `'template_id' => $template->id` no `NpsSurvey::create`
- Bloco de auth comentado explicitando padrão consolidado (nenhuma mudança comportamental)
- Import `use App\Services\Nps\NpsTemplateService;` no topo do controller

## Padrão de Auth Mantido (Decisão pt-BR)

O plano oferecia duas trilhas:

1. **Restringir a role='estrategista' via pivot** — mais estreito mas quebra compat com consultores e mentores historicamente autorizados via `$user->companies()`.
2. **Preservar superset atual** — admin OR qualquer role no pivot company_users → estrategista incluso automaticamente + zero regressão.

Escolhemos **(2)**: adicionar comentário pt-BR explicando o padrão (Phase 62 consolidou este superset) e manter o if-guard idêntico. O Test 5 (`test_user_sem_carteira_recebe_403`) valida a mitigação T-69-04-01 sem depender da restrição estreita — user sem pivot na empresa recebe 403 do mesmo jeito.

## Consumo do Service via Method Injection

```php
public function generate(Request $request, NpsTemplateService $templateService)
```

**Motivação:** o `NpsController` é uma classe grande (~800 LoC, ~20 métodos) e o Plan 69-03 vai atacar `submitResponse`. Constructor injection forçaria diff no header da classe, aumentando risco de merge conflict entre executores paralelos. Method injection é resolvida pelo container Laravel em runtime (padrão observado no repo em outras controllers), zero cost, sem coupling adicional.

## Cobertura de Teste

5 testes TDD Feature em `tests/Feature/Phase69/NpsGenerateFlowTest.php`, todos passando:

| # | Método | Cenário |
|---|--------|---------|
| 1 | `test_admin_gera_link_com_template_padrao` | Admin + empresa sem contratos → template_id = NPS Padrão + valida REQ-31-08 completo (auto_generated, month_reference, expires_at) |
| 2 | `test_estrategista_gera_link_com_template_correto` | User não-admin com pivot role='estrategista' → 302 + template_id populado |
| 3 | `test_template_correto_para_empresa_com_scope` | Empresa contrata Serviço A + Template T scoped em A → template_id = T (não padrão) |
| 4 | `test_template_default_fallback` | Empresa sem contratos + template arbitrário sem scope → fallback puro no is_default |
| 5 | `test_user_sem_carteira_recebe_403` | User sem admin sem pivot → 403 + zero surveys criados (T-69-04-01) |

Setup implícito via `RefreshDatabase` — migrations 100001 (schema), 100004 (seed NPS Padrão), 2026_05_27_100001 (catálogo Serviços) rodam antes de cada teste.

## Zero Regressão Confirmada

Comando executado:
```bash
php artisan test tests/Feature/Phase31NpsSubmitTest.php \
                  tests/Feature/Phase31NpsDispararMensalTest.php \
                  tests/Feature/Phase31NpsMonthlyMailTest.php \
                  tests/Feature/Phase33NpsPerguntasExtrasTest.php \
                  tests/Feature/Phase68/ \
                  tests/Feature/Phase69/NpsGenerateFlowTest.php \
                  tests/Feature/Phase69/NpsTemplateServiceTest.php
```

Resultado: **61 passed, 0 failed** (307 assertions).

- Phase 31 (NpsSubmit, DispararMensal, MonthlyMail): mantidos verdes — o Test T6 legado (`test_generate_cria_survey_com_auto_generated_false`) continua passando pois `auto_generated=false` foi preservado explicitamente.
- Phase 33 (PerguntasExtras): intacto.
- Phase 68 (Schema/SeedRetro/BackwardCompat): 11 verdes.
- Phase 69 Plan 01 (NpsTemplateService): 5 verdes preservados.
- Phase 69 Plan 04 (novos): 5 verdes.

**Obs sobre execução paralela:** o executor de Plan 69-05 (`NpsDispararMensalTemplateTest.php`) tem 5 failing tests próprios em progresso — arquivo disjunto do meu escopo (`NpsDispararMensal.php`), sem interferência bilateral confirmada nesta suíte de regressão.

## Deviations from Plan

None — plano executado exatamente como escrito. RED → GREEN sem refactor pass separado (código nasceu limpo, sem duplicação ou branching complexo pra extrair).

## Commits

| Commit | Tipo | Descrição |
|--------|------|-----------|
| `c21fa61` | test | RED — NpsGenerateFlowTest 5 cenários (4 falhando por template_id=null, 5º já verde) |
| `fb5fbbe` | feat | GREEN — NpsController::generate usa NpsTemplateService (5/5 verdes) |

## Threat Flags

Nenhum — mitigações STRIDE do plan respeitadas:
- **T-69-04-01 (Elevation of Privilege):** Guard `if (!isAdmin) { abort(403 se companyId not in user->companies()) }` preservado + testado (Test 5).
- **T-69-04-02 (Info Disclosure via flash):** Comportamento herdado inalterado — session-scoped.
- **T-69-04-03 (Tampering company_id):** `exists:companies,id` + guard de carteira antes de operar.

Nenhum surface novo introduzido — endpoint pré-existente, só o payload do survey ganhou uma coluna FK (`template_id`) já validada por `nullOnDelete` no schema.

## Referências

- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-04-PLAN.md`
- `.planning/phases/69-backend-regras-de-neg-cio-c-lculo-e-dispatch/69-01-SUMMARY.md` (service consumido)
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md` (fundação schema + seed)
- `app/Services/Nps/NpsTemplateService.php` linhas 70-112 (`resolveForCompany`)
- `app/Http/Controllers/NpsController.php` linhas 247-291 (método `generate` atualizado)
- `tests/Feature/Phase69/NpsGenerateFlowTest.php`
- REQ NPS-B-01 (implementação 69-01 + consumo 69-04)
- REQ-31-08 preservado (compat manual generate)

## Self-Check: PASSED

- `tests/Feature/Phase69/NpsGenerateFlowTest.php` — FOUND
- `app/Http/Controllers/NpsController.php` (import `NpsTemplateService` + método `generate` atualizado) — FOUND
- commit `c21fa61` (RED) — FOUND em git log
- commit `fb5fbbe` (GREEN) — FOUND em git log
- 5/5 tests novos verdes + 56 tests legados intactos = 61 verdes
