---
phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi
plan: 01
subsystem: api
tags: [nps, laravel, eloquent, replicate, db-transaction, cascade, role-admin]

# Dependency graph
requires:
  - phase: 70-ui-de-configuracao-admin
    provides: NpsTemplateController (store/update/toggleActive/setPrincipal/syncServicos) e rotas nps.configuracao.templates.*
  - phase: 79-nps-multi-modelo
    provides: snapshot imutável (nps_response_scores/covered_services) e seed NPS Shopee com scopes
provides:
  - "NpsTemplateController@duplicate — clonagem atômica de modelo (config+perguntas+opções+scopes) com is_default=false"
  - "NpsTemplateController@destroy — exclusão com guardas is_default e histórico (respostas)"
  - "Rotas POST duplicar + DELETE destroy no grupo role:admin"
affects: [81-03-frontend-config-botoes, 81-02-empresas-elegiveis, deploy-nps-v16]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Clonagem atômica de árvore Eloquent via Model::replicate() + DB::transaction"
    - "Guardas de negócio no controller antes do delete (FK é só rede de segurança)"

key-files:
  created:
    - tests/Feature/V16/DuplicarModeloTest.php
    - tests/Feature/V16/ExcluirModeloTest.php
  modified:
    - app/Http/Controllers/NpsTemplateController.php
    - routes/web.php

key-decisions:
  - "Clone força is_default=false (invariante do unique parcial; Pitfall 2)"
  - "destroy() bloqueia is_default E modelos com respostas (Pitfall 1: nullOnDelete zeraria notas no dashboard); arquivar via toggleActive é a alternativa"

patterns-established:
  - "Duplicar árvore: replicate() do pai → replicate() dos filhos com FK reapontada, tudo em DB::transaction"
  - "Exclusão segura: guardas abort(422) espelhando update/toggleActive antes de delegar limpeza ao cascade"

requirements-completed: [DEC-81-1, DEC-81-2]

# Metrics
duration: 9min
completed: 2026-07-15
---

# Phase 81 Plan 01: Duplicar e Excluir modelo NPS Summary

**Backend `duplicate()` (clone atômico de modelo completo com is_default=false) e `destroy()` (com guardas de modelo principal e de histórico) no NpsTemplateController, com 2 rotas role:admin e cobertura Feature em tests/Feature/V16.**

## Performance

- **Duration:** ~9 min
- **Started:** 2026-07-15T01:09:14Z
- **Completed:** 2026-07-15T01:18:35Z
- **Tasks:** 2 (ambas TDD)
- **Files modified:** 4 (2 criados, 2 editados)

## Accomplishments
- `duplicate()`: clona template + perguntas + opções + service scopes numa `DB::transaction`, forçando `is_default=false` (não colide no unique parcial mesmo duplicando o principal); nome do clone `"{nome} (cópia)"`; original intocado.
- `destroy()`: guarda 1 bloqueia `is_default` (422); guarda 2 bloqueia modelos com survey respondido (422, Pitfall 1); caso limpo delega a limpeza de perguntas/opções/scopes ao cascade das FKs.
- 2 rotas novas no grupo `role:admin`: `POST .../templates/{template}/duplicar` e `DELETE .../templates/{template}`.
- Regressão NPS intacta: 168 testes `--filter=Nps` verdes; suíte V16 completa (60) verde.

## Task Commits

Cada tarefa foi commitada atomicamente (TDD RED → GREEN):

1. **Task 1 (RED): teste da duplicação** - `28a5b74` (test)
2. **Task 1 (GREEN): duplicate()** - `67135e6` (feat)
3. **Task 2 (RED): teste da exclusão** - `3260f56` (test)
4. **Task 2 (GREEN): destroy()** - `5633053` (feat)

_Nota: rota `destroy` foi registrada junto com a `duplicate` no commit `67135e6` (mesmo bloco de rotas), então o GREEN da Task 2 tocou só o controller._

## Files Created/Modified
- `app/Http/Controllers/NpsTemplateController.php` - novos métodos `duplicate()` e `destroy()`
- `routes/web.php` - rotas `nps.configuracao.templates.duplicate` (POST) e `.destroy` (DELETE) no grupo role:admin
- `tests/Feature/V16/DuplicarModeloTest.php` - 3 cenários DEC-81-1
- `tests/Feature/V16/ExcluirModeloTest.php` - 3 cenários DEC-81-2

## Decisions Made
- Segui o plano conforme especificado. A única escolha de execução foi tornar os testes robustos aos modelos seedados por migration (NPS Padrão `is_default=true` + NPS Shopee) — ver Deviations.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Testes assumiam banco sem modelos NPS seedados**
- **Found during:** Task 1 (duplicate)
- **Issue:** O primeiro rascunho do DuplicarModeloTest assertava contagens absolutas (`NpsTemplate::count() === 2`) e criava um segundo `is_default=true`. As migrations `2026_07_07_100004` (NPS Padrão) e `2026_07_14_200002` (NPS Shopee) já semeiam templates no `RefreshDatabase`, e o seed principal já ocupa o unique parcial — o setup do teste estourava `UNIQUE constraint failed: nps_templates.is_default` antes mesmo de exercitar o código.
- **Fix:** Testes passaram a usar baseline relativo (`$baseline = NpsTemplate::count()`) e a reutilizar o modelo principal seedado (`NpsTemplate::where('is_default', true)->firstOrFail()`) em vez de criar outro. Localização do clone por nome `"(cópia)"`.
- **Files modified:** tests/Feature/V16/DuplicarModeloTest.php
- **Verification:** `php artisan test --filter=DuplicarModeloTest` → 3 passed
- **Committed in:** `67135e6` (parte do commit GREEN da Task 1)

**2. [Rule 3 - Blocking] Evitado tipo `texto_livre` no fixture (gotcha SQLite CHECK)**
- **Found during:** Task 1 (duplicate)
- **Issue:** Usar `TIPO_TEXTO_LIVRE` numa pergunta do fixture estourava `CHECK constraint failed: tipo` no SQLite. A migration `2026_07_13_101151` que estende o enum pula SQLite (`getDriverName() === 'sqlite'`), então o CHECK herdado da migration original só aceita `escala|opcoes` (gotcha enum+SQLite do MEMORY.md — pré-existente, fora do escopo deste plano).
- **Fix:** A pergunta "sem opções" do fixture usa `TIPO_OPCOES` (aceito pelo CHECK) — cobre igualmente o caso de clonar pergunta sem `NpsTemplateOption`.
- **Files modified:** tests/Feature/V16/DuplicarModeloTest.php
- **Verification:** teste verde; caso de pergunta sem opções preservado.
- **Committed in:** `67135e6`

---

**Total deviations:** 2 auto-fixed (1 bug de teste, 1 blocking de fixture)
**Impact on plan:** Ambos ajustes são de robustez dos testes ao estado real do banco (seeds + gotcha SQLite pré-existente). Nenhuma mudança de comportamento no código de produção. Sem scope creep.

## Issues Encountered
- Gotcha enum+SQLite (`texto_livre`) e modelos NPS seedados por migration — ambos contornados nos fixtures (ver Deviations). Nenhum bloqueio.

## User Setup Required
None - nenhuma configuração de serviço externo necessária. Backend-only; sem `npm run build`/deploy neste plano.

## Next Phase Readiness
- Backend de duplicar/excluir pronto para o frontend (81-03: botões Duplicar/Excluir na config).
- Rotas `nps.configuracao.templates.duplicate`/`.destroy` disponíveis via Ziggy `route()`.
- Endpoint de empresas elegíveis (81-02) e modal gerar-link (81-04) seguem independentes.

## TDD Gate Compliance
Ambas as tarefas seguiram RED→GREEN com commits `test(...)` antes de `feat(...)` (ver Task Commits). Sem fase REFACTOR necessária.

## Self-Check: PASSED
- Arquivos criados verificados: DuplicarModeloTest.php, ExcluirModeloTest.php, 81-01-SUMMARY.md.
- Commits verificados: 28a5b74, 67135e6, 3260f56, 5633053.
- Métodos verificados: `duplicate()`, `destroy()` presentes no controller.

---
*Phase: 81-nps-config-ux-duplicar-excluir-modelo-modal-gerar-link-multi*
*Completed: 2026-07-15*
