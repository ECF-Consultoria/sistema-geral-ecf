---
phase: 69-backend-regras-de-neg-cio-c-lculo-e-dispatch
milestone: v15.0
plan: 69-01
subsystem: nps
type: execute
wave: 1
tags: [nps, service, resolve-template, priority, is-default, precedencia, laravel-12, phase69, tdd]
requirements: [NPS-B-01]
dependency-graph:
  requires:
    - phase-68-schema (5 tabelas nps_* + seed NPS Padrão + relação serviceScopes)
  provides:
    - app/Services/Nps/NpsTemplateService::resolveForCompany(Company): NpsTemplate
  affects:
    - Plan 69-04 (endpoint manual generate) — usará este resolver
    - Plan 69-05 (disparo mensal automatizado) — usará este resolver
tech-stack:
  added: []
  patterns:
    - Stateless service (sem propriedades, DI-friendly)
    - 2-query strategy (scope-aware + fallback default) — mais legível que union
    - Ordenação determinística priority DESC + id ASC
key-files:
  created:
    - app/Services/Nps/NpsTemplateService.php
    - tests/Feature/Phase69/NpsTemplateServiceTest.php
  modified: []
decisions:
  - "Fallback via 2 queries (scope + default) ao invés de UNION/CASE — legibilidade > micro-otimização; ambos os passos cobertos por índices existentes (nps_templates_active_priority_idx + unique parcial is_default)"
  - "Mensagem de RuntimeException menciona 'is_default' e 'migration 2026_07_07_100004' explicitamente para grep-friendly troubleshooting em logs de CI/prod"
  - "orderBy('id') como tiebreak secundário garante idempotência entre re-runs (MySQL/SQLite podem devolver ordem diferente sem isso)"
metrics:
  tasks: 2
  files_created: 2
  files_modified: 0
  commits: 2
  tests_added: 5
  tests_passed: 5
  regression_tests_verified: 51
  completed_date: 2026-07-08
---

# Phase 69 Plan 01: NpsTemplateService::resolveForCompany Summary

**One-liner:** Service stateless de resolução de template NPS por empresa usando priority DESC + is_default fallback via pivot `nps_template_service_scopes` (research §4).

## Signature do Método Público

```php
namespace App\Services\Nps;

final class NpsTemplateService
{
    public function resolveForCompany(\App\Models\Company $company): \App\Models\NpsTemplate;
}
```

**Contrato:**
- Retorna `NpsTemplate` sempre que houver template `is_default=true` OU um template `active=true` com scope aplicável ao serviço da empresa.
- Lança `RuntimeException` (com mensagem em pt-BR mencionando `is_default` e a migration culpada) somente no estado anômalo em que o seed 100004 não rodou.

## Precedência Implementada (Research §4)

1. **Regra primária:** templates com `active=true` E scope em qualquer `servico_id` de contrato ativo da empresa → maior `priority` vence.
2. **Tiebreak:** empate de priority → menor `id` (determinístico).
3. **Fallback:** nenhum aplicável → template com `is_default=true` (seed NPS Padrão).
4. **Exceção:** sem default → `RuntimeException` com contexto acionável.

## Estratégia de Fallback

Optamos por **2 queries separadas** (`->first()` + null coalescing) ao invés de uma query única com `UNION`/`CASE`:

- **Legibilidade:** o intent "primeiro tento scope; se falhar caio no default" fica óbvio no código.
- **Performance equivalente:** ambos os passos são cobertos por índices dedicados — `nps_templates_active_priority_idx` na query principal e o unique parcial em `is_default` no fallback.
- **Custo real:** query única com `UNION` custaria 2 sub-queries também; `CASE`/subquery custaria mais que 2 lookups indexados.

## Cobertura de Teste

5 testes TDD Feature em `tests/Feature/Phase69/NpsTemplateServiceTest.php`, todos passando:

| # | Cenário | Método |
|---|---------|--------|
| 1 | Múltiplos templates aplicáveis → maior priority vence | `test_resolveForCompany_retorna_template_de_maior_priority_quando_multiplos_aplicaveis` |
| 2 | Empate de priority → menor id vence | `test_resolveForCompany_desempata_por_menor_id_quando_priority_igual` |
| 3 | Nenhum scope match → fallback no NPS Padrão | `test_resolveForCompany_cai_no_default_quando_nenhum_template_aplicavel` |
| 4 | Templates `active=false` são ignorados (regressão) | `test_resolveForCompany_ignora_templates_active_false` |
| 5 | Sem default disponível → `RuntimeException` | `test_resolveForCompany_dispara_runtime_exception_quando_sem_default` |

Setup implícito via `RefreshDatabase` — todas as migrations (incluindo seeds 100001 servicos + 100004 NPS Padrão) rodam antes de cada teste.

## Zero Regressão Confirmada

- Phase 31: `Phase31NpsDispararMensalTest`, `Phase31NpsMonthlyMailTest`, `Phase31NpsSubmitTest` — 33 tests passando.
- Phase 33: `Phase33NpsPerguntasExtrasTest` — 7 tests passando.
- Phase 68: `Phase68/NpsSchemaTest`, `NpsSeedRetroactiveTest`, `NpsBackwardCompatTest` — 11 tests passando.

Total pós-implementação: **51 testes legados verdes** + **5 testes novos verdes** = 56/56.

## Deviations from Plan

None — plan executado exatamente como escrito. RED → GREEN sem necessidade de refactor pass separado (código nasceu limpo, sem duplicação nem magic numbers a extrair).

## Commits

| Commit | Tipo | Descrição |
|--------|------|-----------|
| `d733ad4` | test | RED — 5 cenários TDD falhando com "Class NpsTemplateService does not exist" |
| `a4e9916` | feat | GREEN — Service implementado, 5/5 verdes |

## Threat Flags

Nenhum — mitigações STRIDE do plan T-69-01-01/02/03 respeitadas:
- Sem SQL raw / interpolação — query builder puro.
- Volume esperado ≤ 20 templates ativos (v15 é admin single-tenant); índice cobre.
- `RuntimeException` só surge em estado pré-deploy; sem info sensível vazada.

## Referências

- `.planning/research/v15-nps-templates-schema.md` §4 (regra determinística)
- `.planning/phases/68-schema-modelos-e-seed-retroativo-nps-padr-o/PHASE-SUMMARY.md`
- `app/Models/NpsTemplate.php` (scopes `active()`/`default()` + relação `serviceScopes()`)
- `app/Models/Company.php` linha 236 (relação `contratosServico()`)
- `app/Models/ContratoServico.php` linha 74 (scope `active` — coluna `ativo`)
- Padrão de service DI: `app/Services/Metrics/MetricsProviderFactory.php`

## Self-Check: PASSED

- app/Services/Nps/NpsTemplateService.php — FOUND
- tests/Feature/Phase69/NpsTemplateServiceTest.php — FOUND
- commit d733ad4 (RED) — FOUND em git log
- commit a4e9916 (GREEN) — FOUND em git log
