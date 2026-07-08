---
phase: 70-ui-de-configuracao-admin
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, ui, admin, config, templates, crud, preview-live, service-scopes, feature-tests, laravel-12, react, inertia, phase70]

# Consolidação das 4 waves da Phase 70
plans-completed:
  - 70-01: NpsTemplateController CRUD + FormRequests + 4 rotas admin-only + guard is_default
  - 70-02: NpsTemplateQuestionController CRUD + auto-5-options escala + SWAP reorder + scopeBindings
  - 70-03: NpsTemplateOptionController CRUD + peso 1..5 + guard mínimo 1 opção escala + scopeBindings
  - 70-04: syncServicos atomic + empresasAfetadas simulação NpsTemplateService + preview endpoint stateless
  - 70-05: Reescrita Configuracao.jsx multi-template + 6 componentes filhos + preview live + legado preservado
  - 70-06: Suite Feature Phase70 (24 tests) cobrindo SC1-SC5 + baseline regressão zero

requirements-completed: [NPS-C-01, NPS-C-02, NPS-C-03, NPS-C-04, NPS-C-05, NPS-C-06]

success-criteria-status:
  SC#1: PASSED  # Admin cria/edita/desativa templates via /nps/configuracao
  SC#2: PASSED  # CRUD perguntas com reorder + tipo IMUTÁVEL após criação
  SC#3: PASSED  # Opções com label + peso 1..5 + reorder swap
  SC#4: PASSED  # Dimensão + obrigatoriedade + service scopes com feedback de empresas afetadas
  SC#5: PASSED  # Preview live debounced sem persistir

# Métricas agregadas
plans: 6
controllers-created: 3    # NpsTemplateController + NpsTemplateQuestionController + NpsTemplateOptionController
controllers-modified: 1   # NpsController (redireciona configuracao para ConfiguracaoLegado)
formrequests-created: 8   # Store/Update × 3 + SyncNpsTemplateScopesRequest + PreviewNpsTemplateRequest
frontend-components-created: 6  # TemplatesList, TemplateEditForm, QuestionEditor, OptionsEditor, ServiceScopesPicker, PreviewFormulario
pages-created: 1          # ConfiguracaoLegado.jsx (cópia integral Phase 33)
pages-rewritten: 1        # Configuracao.jsx (1076 → 236 LOC)
routes-added: 15          # 4 templates + 4 perguntas + 4 opcoes + 3 servicos/empresas-afetadas/preview
routes-modified: 4        # legacy movidas para /textos-legado
tests-created: 24         # 6 + 7 + 6 + 5
test-assertions: 125
regression-preserved: 84  # Phase 31 + 33 + 68 + 69 continuam 84/84 verdes
grand-total-nps-tests: 108
bugs-caught-by-tests: 1   # scopeBindings alias faltando em NpsTemplate.perguntas() / NpsTemplateQuestion.opcaos()
duration-total: ~85min
completed: 2026-07-08
---

# Phase 70 Summary — UI de Configuração admin

Backend completo (15 endpoints REST admin-only) + frontend multi-template com 6 componentes filhos + preview live debounced + legado Phase 33 preservado sob `/nps/configuracao/textos-legado` + suite Feature de 24 testes cobrindo SC1-SC5 com zero regressão em Phases 31/33/68/69. Ready para Phase 71 (formulário público dinâmico) reaproveitar o `PreviewFormulario.jsx` idêntico.

## Waves executadas

### Wave 1 (paralela sequencial — routes/web.php compartilhado) — Plans 70-01, 70-02, 70-03

Backend CRUD de templates (12 rotas nested):
- **NpsTemplateController** (index/store/update/toggleActive) com guard `is_default` triplo (schema unique parcial + rules sem is_default + abort 422 no update/toggle-active)
- **NpsTemplateQuestionController** (store/update/destroy/mover) com `DB::transaction` auto-gerando 5 options em `tipo=escala` + tipo IMUTÁVEL após criação + SWAP scoped por template_id
- **NpsTemplateOptionController** (store/update/destroy/mover) com guard duplo scope + guard invariante escala (mínimo 1 opção) + peso 1..5 clamp
- 6 FormRequests com validação `authorize()` = isAdmin (defesa em profundidade além do middleware role:admin)

### Wave 2 — Plan 70-04

Feedback visual + preview endpoint (3 rotas adicionais):
- `syncServicos` via `$template->servicos()->sync($ids)` atomic
- `empresasAfetadas` simula `NpsTemplateService::resolveForCompany` (Plan 69-01) para cada empresa ativa (LIMIT 100 perf guard), retorna JSON com `{count, empresas, sampled_from, total_ativas, truncated}`
- `preview` é PURE function — recebe payload aninhado não-persistido, normaliza ordem por índice do array, retorna JSON com estrutura idêntica ao `template_snapshot_json` do survey — zero side effects DB

### Wave 3 — Plan 70-05

Frontend multi-template completo:
- 6 componentes filhos em `resources/js/Components/Nps/Config/` (1649 LOC total): `TemplatesList`, `TemplateEditForm`, `QuestionEditor`, `OptionsEditor`, `ServiceScopesPicker`, `PreviewFormulario` (puro)
- Configuracao.jsx reescrita: 1076 → 236 LOC orquestrando os 6 filhos via grid 320px_1fr desktop / stack mobile
- Preview live debounced 300ms POSTando `route('nps.configuracao.templates.preview')` — atualiza sempre que `router.reload({only: ['templates']})` recarrega props
- **Legado Phase 33 preservado:** `Configuracao.jsx` original virou `ConfiguracaoLegado.jsx`; rotas legadas movidas para `/nps/configuracao/textos-legado` com aliases de nome (`nps.configuracao.update`, `nps.configuracao.preview`) preservando chamadas ziggy do arquivo legado — zero refactor forçado
- Design tokens `ecf-*` respeitados; `cn()` utility em 100% dos componentes; zero library nova (arrows reorder per research §3)

### Wave 4 — Plan 70-06

Suite Feature Phase 70:
- 4 arquivos em `tests/Feature/Phase70/` (24 tests / 125 assertions)
- Cobertura SC → REQ mapeada 100%
- **Bug crítico caught by tests:** rotas dos Plans 70-02/70-03 usavam `scopeBindings()` que Laravel resolve via `Str::plural` → `$template->perguntas()` (não existia; só `questions()`). Sem os aliases, qualquer PUT/DELETE/POST-mover retornava 500. Corrigido com aliases `perguntas()` no `NpsTemplate` e `opcaos()` no `NpsTemplateQuestion`. Sem os tests deste plan, o bug teria ido pra produção
- Regressão zero: Phase 31 + 33 + 68 + 69 seguem 84/84 verdes

## Contract entregue

- **REQ NPS-C-01 a NPS-C-06** — 6/6 fechados (frontend + backend + testes)
- **SC1-SC5** do ROADMAP Phase 70 — 5/5 PASSED
- **Rotas:** 15 novos endpoints `nps.configuracao.templates.*` + 3 legado renomeadas + alias `nps.configuracao.index` preservado = **25 rotas totais** em `/nps/configuracao/*`
- **PreviewFormulario.jsx portável:** componente PURO (sem useForm, sem router, sem axios) — Phase 71 importa idêntico em `Respond.jsx`

## Padrões travados para próximas fases

1. **`scopeBindings()` requer aliases de model** — Laravel usa `Str::plural(<param>)` para resolver relations. Sempre validar via HTTP test antes de mergear rotas nested.
2. **Preview endpoint como contract pattern** — payload aninhado + retorno normalizado. Phase 71 pode POST o mesmo shape se precisar de preview server-side.
3. **Componentes puros = portáveis entre fases** — `PreviewFormulario.jsx` prova o padrão. Phase 71 vai reutilizar sem 1 linha de mudança.
4. **Legado + novo coexistem via renomeação de path + preservação de nome** — `/nps/configuracao` mudou de handler mas mantém nome canônico; menu/breadcrumbs não quebram.

## Métricas finais

| Métrica | Valor |
|---|---|
| Plans | 6 |
| Waves | 4 |
| Controllers backend novos | 3 |
| FormRequests | 8 |
| Componentes React novos | 6 |
| Página reescrita | 1 (Configuracao.jsx: -840 LOC) |
| Página preservada | 1 (ConfiguracaoLegado.jsx) |
| Rotas admin adicionadas | 15 |
| Tests Feature Phase 70 | 24 (125 assertions) |
| Tests NPS totais (Phases 31/33/68/69/70) | 108 (637 assertions) |
| Regressão | 0 |
| Bug crítico caught by tests | 1 (scopeBindings alias) |
| Duração total | ~85min |

## Próximos passos liberados

- **Phase 71 (Formulário público dinâmico)** — importa `PreviewFormulario.jsx` idêntico em `Respond.jsx`, renderiza a partir do `template_snapshot_json` da survey
- **Phase 72 (Dashboards + pendências)** — `NpsPendingService::forCarteira` + badge de pendência em `Portfolio/Show.jsx` e `Companies/Index.jsx`
- **Phase 73 (Limpeza legado + testes E2E)** — remove `>=9 Promotor/>=7 Neutro/else Detrator`, remove aliases de rotas em `/textos-legado/*`, implementa `metric='nps'` em `CalculateGoalResults`
