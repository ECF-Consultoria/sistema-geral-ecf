# Migração Sugadores Adman → ML (referência canônica v11.0)

Plano técnico importado via `/gsd-import plano-migracao-sugadores-ml-direto.md` em 2026-06-25.

## Decisões tomadas na importação

- **Slicing:** Nova **Milestone v11.0** com 6 phases (38-43), substituindo Phase 30 W2/W3/W4 (plans 30-02/30-03/30-04). Plan 30-01 (throttled queue Adman) **permanece em prod**.
- **Arquitetura:** **Provider pattern** (`SugadoresAdsProvider` contract + `AdmanSugadoresProvider` + `MercadoLivreSugadoresProvider`), NÃO mirror service.

## Mapeamento Fase do plano → Phase GSD

| Plano | Phase GSD | Resumo |
|-------|-----------|--------|
| Fase 0 — Smoke técnico | **Phase 38** | `sugadores:ml-smoke --company={id} --days=30` com Bymobile |
| Fase 1 — Provider ML sem gravar | **Phase 39** | `SugadoresAdsProvider` + ML provider + `AdgroupMlbMapRepository` + `--dry-run` |
| Fase 2 — Shadow mode | **Phase 40** | `sugador_provider_runs`/`_items` + comandos shadow/compare + paridade ≥95% |
| Fase 3 — Onboarding empresas | **Phase 41** | Tela admin token ML + checklist + rate limiter `ml-api:{seller_id}` |
| Fase 4 — Cut-over por empresa | **Phase 42** | Envs `SUGADORES_*` + modo `ml_primary` + rollback automático |
| Fase 5 — Remoção Adman | **Phase 43** | Só depois de 100% empresas com mlToken + 7d estável; rename tabela legada |

## Arquivos

- [`PLANO-ORIGINAL.md`](PLANO-ORIGINAL.md) — cópia textual do plano importado (raiz: `plano-migracao-sugadores-ml-direto.md`)
- Próximo: `/gsd-plan-phase 38` cria `.planning/phases/38-.../38-01-PLAN.md` do smoke piloto Bymobile

## Premissas críticas do plano (preservar em todas as 6 phases)

- UI, FSM de status e schema de `sugadores`/`sugador_configs`/`sugador_acoes` **idênticos**
- `evaluateMetrics()` e `buildRow()` recebem o **mesmo contrato normalizado** de métricas
- Módulo continua **apenas lendo** dados do Mercado Livre; ações no painel ML permanecem manuais
- Adman **não removida** no primeiro corte — fallback até todas empresas relevantes terem `mlToken` válido
- Hoje só **`ByMobille - Teste`** tem conexão direta ML (piloto Fase 0); precisa de 1+ empresa Adman+ML para validar paridade na Fase 2

## Referências cruzadas

- `.planning/phases/30-.../CONTEXT.md` — nota da supersedure
- `.planning/ROADMAP.md` — Milestone v11.0 (linhas após v9.5)
- Memory: [feedback_sugadores_provider_pattern](../../../../../Users/User/.claude/projects/c--xampp-htdocs-ecf-admin-ecf-admin/memory/feedback_sugadores_provider_pattern.md)
- Memory: [project_v11_sugadores_ml_migration](../../../../../Users/User/.claude/projects/c--xampp-htdocs-ecf-admin-ecf-admin/memory/project_v11_sugadores_ml_migration.md)
