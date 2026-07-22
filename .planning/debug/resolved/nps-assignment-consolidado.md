---
slug: nps-assignment-consolidado
status: resolved
trigger: NPS de empresa com responsável CONSOLIDADO (company_users.servico_id NULL) nasce SEM nps_score_assignments → não conta no bônus (caso Prensar/Nathalia/Gustavo)
created: 2026-07-22
updated: 2026-07-22
---

# Debug: NPS assignment não criado p/ responsável consolidado (bônus)

## Symptoms

- **Expected**: ao responder um NPS, `NpsSnapshotService` cria `nps_score_assignments` (média×pessoa×role×serviço) para os responsáveis da empresa — é a fonte do BÔNUS. Toda empresa com responsável (mesmo consolidado) deve gerar atribuição.
- **Actual**: empresas cujo responsável está gravado como CONSOLIDADO (`company_users.servico_id = NULL`) nasciam SEM atribuição. O NPS respondido sumia do escopo não-admin (já corrigido) E não contava no bônus (agora corrigido nesta sessão).
- **Errors**: nenhum erro visível; `NpsSnapshotService` linha ~173-183 logava `Log::warning` de "responsável faltante" e seguia sem criar assignment.
- **Timeline**: pré-existente; exposto em 2026-07-22 pelo deploy do escopo-por-serviço da listagem /nps.
- **Reproduction**: responder NPS de empresa com responsável servico_id=NULL (ex.: Prensar #184 — Nathalia estrategista + Gustavo consultor, ambos NULL). Auditoria prod: 5/74 respondidos sem atribuição (3 jun + 2 manuais jul).

## Root Cause (confirmado)

`Company::consultorDoServico($id)`/`estrategistaDoServico($id)` (`app/Models/Company.php:201-213`) fazem `wherePivot('servico_id', $id)` — casam SÓ `servico_id` exato, nunca `NULL` (consolidado). `NpsSnapshotService::registrar()` (linha ~169-171) usava exatamente esses helpers para achar o responsável na hora de gravar `nps_score_assignments` → empresa com responsável consolidado nunca era encontrada → linha 173 "responsável faltante" → sem assignment → sem contar no bônus.

## Resolution

**root_cause:** ver acima — confirmado por leitura de código + dados de prod (Prensar #184, survey #274 respondido sem `nps_score_assignments`).

**fix:**
1. `app/Models/Company.php` — novo método `responsavelDoServicoOuConsolidado(string $role, int $servicoId): Collection`. Prioriza linha PREENCHIDA (servico_id exato); se não existir, cai para a linha `servico_id NULL` (consolidado). Mesma régua já usada por `CarteiraContextService::vinculosLegadoNull()` (CTX-05) e pelo fallback de `NpsController::filtroPorPessoa`. Os métodos antigos `consultorDoServico()`/`estrategistaDoServico()` foram mantidos intactos (consumidores service-aware puros da aba Shopee/Phase 78 continuam usando-os sem mudança de comportamento).
2. `app/Services/Nps/NpsSnapshotService.php` — passo 3 (`registrar()`) agora chama `Company::responsavelDoServicoOuConsolidado()` em vez de `consultorDoServico()`/`estrategistaDoServico()` diretos. Nova função pública `backfillAssignments(NpsResponse $response, bool $dryRun = false): array` — cria só os assignments que FALTAM numa resposta já congelada (usa `nps_response_covered_services`/`nps_response_scores` já frozen, nunca reconsulta o template ao vivo), idempotente por (response, servico, role).
3. `app/Console/Commands/NpsBackfillAssignmentsConsolidado.php` (novo comando `nps:backfill-assignments-consolidado --dry-run|--force`) — escaneia todas as respostas com template, aplica `backfillAssignments()`, exibe diff antes de gravar, e busta o cache do bônus (`DesempenhoScoreService::cacheKey()`, nunca hardcoded) dos usuários/competências afetados — mesma régua de `NpsController::bustarCacheDoBonus()` (competência = mês de `completed_at` menos 1 mês, NPSWIN-03).
4. Testes novos: `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php` (7 casos) — responsável consolidado gera assignment; regressão do caso servico_id específico; precedência específico > consolidado sem duplicar; unitários de `Company::responsavelDoServicoOuConsolidado()`; backfill idempotente (dry-run + real + segunda execução não duplica); comando artisan end-to-end.

**Regressão rodada:**
- `AtribuicaoConsolidadoNpsTest` (novo): 7/7 passed.
- `--filter=V16`: 167/167 passed.
- `--filter=Nps`: 320/324 passed — 4 falhas PRÉ-EXISTENTES confirmadas via `git stash` (baseline sem o fix já falha nos mesmos 4 casos: `Phase31NpsSubmitTest`, `NpsPhase69IntegrationTest`, 2x `NpsInvalidacaoRespostaTest`) — não relacionadas a este fix, causadas pelo WIP concorrente de outra sessão em `DesempenhoScoreService.php`/`AdmanMetricDiffService.php` (bump de cache version + regras de data). Não tocado neste debug (arquivo de outra sessão).
- `--filter=Desempenho`: 85/90 passed — 5 falhas, mesma causa (WIP concorrente: `desempenho.compute.v9` vs testes que ainda esperam `v8`, `PublicacaoDesempenhoRouteTest` 403, `breakdown_json` null) — pré-existentes, fora de escopo, arquivos não tocados.

**Pendente de aprovação (NÃO executado nesta sessão):**
- Rodar `php artisan nps:backfill-assignments-consolidado --dry-run` em PROD para confirmar as 5 respostas identificadas na auditoria original, e depois `--force` (COM aprovação explícita do usuário — escrita em dados financeiros de produção).
- Deploy do fix (código + comando) para produção — orquestrador cuida após aprovação.

@see .planning/phases/79-.../79-04-PLAN.md
@see app/Models/Company.php (consultorDoServico/estrategistaDoServico/responsavelDoServicoOuConsolidado)
@see app/Services/Nps/NpsSnapshotService.php
