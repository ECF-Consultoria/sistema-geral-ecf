---
phase: 96-nps-anti-burlamento-endurecimento-e-gest-o
plan: 03
subsystem: nps
tags: [laravel, inertia, react, phpunit, tdd, activitylog, cache, audit-trail]

# Dependency graph
requires:
  - phase: 94-nps-anti-burlamento-auditoria-t-cnica-servi-o-de-suspeita-ba
    provides: "nps_responses.suspicion_reasons/is_suspicious (base do critério que motiva a invalidação manual)"
  - phase: 79-nps-multi-modelo-disparo-por-servi-os-cobertos-snapshot-de-a
    provides: "nps_response_scores/nps_score_assignments (snapshot congelado, base do bônus — preservado intacto pela invalidação)"
  - phase: 96-nps-anti-burlamento-endurecimento-e-gest-o (plan 01/02)
    provides: "Nada consumido diretamente — os 3 planos do phase são independentes entre si"
provides:
  - "NpsResponse::scopeValida() (whereNull invalidated_at) — contrato reutilizável pelos 8 call-sites externos do Plano 96-04"
  - "NpsController::invalidarResposta()/revalidarResposta() admin-only, com trilha activity() explícita e cache-busting do bônus (Cache::forget desempenho.compute.v4.{user_id}.{Y-m})"
  - "Rotas PATCH nps.responses.invalidar/revalidar registradas no grupo role:admin, antes de /nps/{token}"
  - "NpsController::index() filtra invalidated_at nos cards/série 12m (mantém a listagem paginada intacta para o admin gerir)"
  - "Payload admin-only item['invalidada'] consumido pela UI de /nps para alternar Invalidar/Revalidar"
affects: [96-04-agregacoes-externas-invalidacao]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Flag de invalidação (nunca deleção/recálculo) + scope Eloquent reutilizável — preserva o congelamento da Fase 79/DEC-79-C e garante reversibilidade trivial (invalidated_at = null)"
    - "activity() explícito (não LogsActivity no model) para ações pontuais raras dentro de um model de alto volume — evita poluir activity_log com o 'created' de toda resposta legítima"
    - "Cache-busting explícito em ação administrativa pontual, complementar ao TTL — usado quando a mutação precisa refletir IMEDIATAMENTE (não pode esperar o TTL de 7 dias do mês fechado)"
    - "Payload admin-only por chave ausente (não `false`) — 3ª vez que o módulo NPS aplica esse padrão (Fase 95 confianca/auditoria, agora invalidada)"

key-files:
  created:
    - database/migrations/2026_07_17_090002_add_invalidation_to_nps_responses_table.php
    - tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php
  modified:
    - app/Models/NpsResponse.php
    - app/Http/Controllers/NpsController.php
    - routes/web.php
    - resources/js/Pages/Nps/Index.jsx

key-decisions:
  - "Cache-busting roda incondicionalmente para qualquer completed_at com atribuição (não só 'mês fechado') — mais simples e inofensivo (TTL do mês corrente já é curto), evita a complexidade de detectar a fronteira mês-fechado/mês-corrente no controller"
  - "Resposta LEGADA (sem NpsScoreAssignment) não busta cache nenhum — comportamento CORRETO documentado no RESEARCH (Pitfall 5), coberto por teste dedicado para não ser 'corrigido' por engano no futuro"
  - "invalidarResposta()/revalidarResposta() NUNCA tocam em NpsSurvey.status/completed_at — evita reabrir o hasOne ambíguo (Pitfall 2 do RESEARCH), diferente de excluirResposta() que reverte para pending"
  - "item['invalidada'] adicionado ao payload do index() (Rule 2 — funcionalidade crítica ausente do plano original): sem essa chave a UI não teria como decidir entre mostrar 'Invalidar' ou 'Revalidar', nem indicar visualmente o estado — extensão mínima seguindo exatamente o padrão admin-only já estabelecido por confianca/auditoria (Fase 95)"

requirements-completed: [AB-96-3]

# Metrics
duration: ~70min
completed: 2026-07-17
---

# Phase 96 Plan 03: Invalidação Manual de Resposta NPS (AB-96-3) Summary

**Flag reversível `invalidated_at`/`invalidated_by` em `nps_responses` + scope `scopeValida()`, ações admin-only `invalidarResposta()`/`revalidarResposta()` com trilha `activity()` explícita e `Cache::forget()` do bônus, filtro nos cards/série de `/nps` (listagem paginada preservada) e UI de invalidar/revalidar no modal — fundação que o Plano 96-04 estende aos 8 call-sites externos de agregação.**

## Performance

- **Duration:** ~70 min
- **Started:** 2026-07-17 (commit RED `607965e`)
- **Completed:** 2026-07-17 (commit UI `2991366`)
- **Tasks:** 3 completed
- **Files modified:** 7 (2 criados, 5 modificados)

## Accomplishments
- Migration idempotente adiciona `invalidated_at` (timestamp nullable) + `invalidated_by` (FK `users` `nullOnDelete`, `nullable()` antes do `nullOnDelete()` — evita erro 1830 MariaDB) em `nps_responses`; `down()` reversível
- `NpsResponse::scopeValida()` (`whereNull('invalidated_at')`) — contrato reutilizável que o Plano 96-04 aplica nos 8 call-sites externos (`DesempenhoScoreService`, `PerformanceController`, `DashboardController`, `PortfolioController`, `CalculateGoalResults`, `CompanyController`)
- `NpsController::invalidarResposta()`/`revalidarResposta()` — admin-only (`abort_unless`), motivo textual livre opcional (`nullable|string|max:500`), trilha `activity()->causedBy()->performedOn()->log()` explícita (NÃO `LogsActivity` no model, para não poluir a auditoria com o `created` de toda resposta legítima), NUNCA tocam em `NpsSurvey.status`/`completed_at`
- `bustarCacheDoBonus()` privado: `Cache::forget('desempenho.compute.v4.{user_id}.{Y-m}')` para cada `user_id` de `NpsScoreAssignment` da resposta — sem isso o bônus de mês fechado ficaria errado no `/performance` por até 7 dias (TTL documentado em `DesempenhoScoreService::computeCached()`); resposta legada sem atribuição não quebra e simplesmente não busta nada (comportamento correto, não bug — Pitfall 5 do RESEARCH)
- Rotas `PATCH /nps/{survey}/response/invalidar` (`nps.responses.invalidar`) e `.../revalidar` (`nps.responses.revalidar`) no grupo `role:admin`, antes de `/nps/{token}`
- `index()`: `$responsesMes` e o loop da série 12m ganham `->whereNull('invalidated_at')` — cards/série param de contar a resposta invalidada; a listagem paginada (`$surveys`) NÃO filtra, o admin continua vendo a resposta para gerenciá-la/revalidar
- Payload admin-only `item['invalidada']` (mesma blindagem de `confianca`/`auditoria` da Fase 95) + UI em `Nps/Index.jsx`: tag "Invalidada" discreta na tabela e no modal, botão "Invalidar resposta" (abre textarea opcional de motivo) que vira "Revalidar resposta" quando já invalidada
- `npm run build` (`npx vite build`) executado com sucesso (exit 0, ~4min34s)
- Suíte `Phase96/NpsInvalidacaoRespostaTest`: 10/10 verde (flag+scope, activity+motivo, cache-busting com/sem atribuição, status intacto, cards/série filtrados vs listagem preservada, revalidar restaura+busta cache, 403 não-admin, payload `invalidada` admin-only)
- Baseline completo `--filter=Nps`: **284/284 passando** (274 anterior + 10 novos), 0 falhas

## Task Commits

Cada task seguiu o ciclo RED → GREEN (TDD):

1. **Task 1: Migration invalidação + scopeValida()/fillable no NpsResponse + teste RED**
   - `607965e` — `test(96-03)`: flag persistida via update() + scopeValida() — RED
   - `d19a4e0` — `feat(96-03)`: migration + fillable/cast/scopeValida() — GREEN
2. **Task 2: invalidarResposta/revalidarResposta + cache-busting + activitylog + rotas + filtro em index()**
   - `b3437ab` — `test(96-03)`: 7 cenários (flag+activity, cache-busting com/sem atribuição, status intacto, cards/série vs listagem, revalidar, 403) — RED
   - `d706e32` — `feat(96-03)`: controller + rotas + filtro — GREEN
3. **Task 3: UI de invalidar/revalidar no modal de Index.jsx**
   - `2991366` — `feat(96-03)`: `item['invalidada']` no controller (Rule 2) + UI completa

**Plan metadata:** (este commit, a seguir)

## Files Created/Modified
- `database/migrations/2026_07_17_090002_add_invalidation_to_nps_responses_table.php` - colunas `invalidated_at`/`invalidated_by` (novo)
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` - suíte completa AB-96-3, 10 cenários (novo)
- `app/Models/NpsResponse.php` - fillable + cast `invalidated_at` + `scopeValida()`
- `app/Http/Controllers/NpsController.php` - `invalidarResposta()`/`revalidarResposta()`/`bustarCacheDoBonus()`, filtro em `index()` (cards/série), `item['invalidada']` admin-only
- `routes/web.php` - rotas `nps.responses.invalidar`/`nps.responses.revalidar`
- `resources/js/Pages/Nps/Index.jsx` - tag "Invalidada" (tabela + modal) + botões Invalidar/Revalidar com textarea de motivo opcional

## Decisions Made
- Cache-busting roda para qualquer resposta com `completed_at` + atribuição, sem checar explicitamente se o mês está "fechado" — mais simples e inofensivo (o TTL do mês corrente já é curto, 10min); evita duplicar a lógica de fronteira mês-fechado/mês-corrente que já vive em `computeCached()`
- Resposta legada (sem `NpsScoreAssignment`) não busta cache nenhum — comportamento correto (Pitfall 5 do RESEARCH), coberto por teste dedicado para não ser "corrigido" por engano numa revisão futura
- `invalidarResposta()`/`revalidarResposta()` nunca tocam em `NpsSurvey.status`/`completed_at` — evita reabrir o `hasOne` ambíguo (Pitfall 2), ao contrário de `excluirResposta()` (ação diferente, já existente) que reverte para `pending`
- `item['invalidada']` adicionado ao payload do `index()` (deviation Rule 2 — funcionalidade crítica ausente do plano original): sem essa chave a UI não conseguiria decidir entre "Invalidar"/"Revalidar" nem indicar visualmente o estado; extensão mínima seguindo o padrão admin-only já estabelecido por `confianca`/`auditoria` (Fase 95)
- Estado do formulário de motivo (`invalidarAberto`/`motivoInvalidar`) resetado sempre que um survey diferente é aberto no modal ou o modal fecha — evita motivo "vazando" de uma resposta para outra

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Payload `item['invalidada']` ausente do plano original**
- **Found during:** Task 3 (UI de invalidar/revalidar)
- **Issue:** O plano especificava a UI alternando entre "Invalidar resposta"/"Revalidar resposta" e mostrando um indicador visual de invalidação, mas não previa nenhuma chave no payload do `index()` para a UI saber, por item, se aquela resposta já está invalidada — sem essa informação a UI não tem como decidir qual botão mostrar nem renderizar a tag.
- **Fix:** Adicionado `item['invalidada'] = (bool) $s->response?->invalidated_at` dentro do bloco `if ($user->isAdmin())` já existente (mesma blindagem de `confianca`/`auditoria` da Fase 95 — chave simplesmente ausente para não-admin).
- **Files modified:** `app/Http/Controllers/NpsController.php`
- **Verification:** teste `test_item_da_listagem_traz_invalidada_admin_only_e_reflete_o_estado` (presença + estado true/false + blindagem não-admin)
- **Committed in:** `2991366` (commit da Task 3)

---

**Total deviations:** 1 auto-fixado (Rule 2 — funcionalidade crítica ausente)
**Impact on plan:** Extensão mínima e necessária para a UI funcionar conforme o próprio objetivo do plano; nenhum scope creep — segue exatamente o padrão de payload admin-only já estabelecido pela Fase 95.

## Issues Encountered
Nenhum bloqueio. `npx vite build` (~4min34s) e as 2 rodadas completas de `php artisan test --filter=Nps` (283 e 284) foram executadas em background pelo tempo de duração — sem impacto no resultado, só no tempo de parede da sessão.

## User Setup Required
None — nenhuma configuração de serviço externo necessária. Migration roda automaticamente no próximo `php artisan migrate` (dev/staging/produção).

## Next Phase Readiness
AB-96-3 (fundação) completo e testado. Pronto para:
- **Plano 96-04**: aplicar `NpsResponse::scopeValida()` nos 8 call-sites externos mapeados no 96-RESEARCH (`DesempenhoScoreService::notasPorAtribuicao()`/`notasLegado()`, `PerformanceController::notasNpsDoUsuarioPorResposta()` ramos A/B, `DashboardController::home()`/`userDashboard()`/`buildRanking()`, `PortfolioController` "Histórico NPS mensal", `CalculateGoalResults::computeNps()`, `CompanyController::show()`) — o contrato (`scopeValida()` + migration) já está pronto e testado, cada call-site precisa só da troca `->with('response')` → `->with(['response' => fn ($q) => $q->valida()])` (ou `->whereNull('invalidated_at')` direto quando é JOIN/query solta)
- O `Cache::forget()` desta plan já cobre a invalidação/revalidação em si — o Plano 96-04 não precisa mexer em cache, só nas queries de leitura

Nenhum bloqueio identificado.

## Self-Check: PASSED

Arquivos criados confirmados em disco:
- `database/migrations/2026_07_17_090002_add_invalidation_to_nps_responses_table.php` — FOUND
- `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — FOUND

Commits confirmados via `git log --oneline`:
- `607965e` (test, Task 1 RED) — FOUND
- `d19a4e0` (feat, Task 1 GREEN) — FOUND
- `b3437ab` (test, Task 2 RED) — FOUND
- `d706e32` (feat, Task 2 GREEN) — FOUND
- `2991366` (feat, Task 3) — FOUND

---
*Phase: 96-nps-anti-burlamento-endurecimento-e-gest-o*
*Completed: 2026-07-17*
