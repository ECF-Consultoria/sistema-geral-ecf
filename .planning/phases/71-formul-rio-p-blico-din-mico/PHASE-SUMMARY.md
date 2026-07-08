---
phase: 71-formul-rio-p-blico-din-mico
milestone: v15.0 — NPS Templates
subsystem: nps
tags: [nps, ui, publico, form-publico, template-dinamico, dual-path, react, inertia, feature-tests, laravel-12, phase71]

# Consolidação das 3 waves da Phase 71
plans-completed:
  - 71-01: NpsController::respond eager-load template.questions.options + inject template prop dual-path (null em legacy)
  - 71-02: PreviewFormulario controlled + Respond.jsx reescrito v15 dinâmico + RespondLegado.jsx preserva Phase 33
  - 71-03: Suite Feature Phase71 (10 tests) cobrindo SC1-SC5 + baseline regressão zero

requirements-completed: [NPS-D-01, NPS-D-02, NPS-D-03, NPS-D-04, NPS-D-05]

success-criteria-status:
  SC#1: PASSED  # Renderiza dinamicamente do template snapshot (nunca hardcoded)
  SC#2: PASSED  # Radio group cinza/amarelo + mobile-friendly (PreviewFormulario controlled)
  SC#3: PASSED  # Obrigatórias + botão disabled client-side + server-side 422 (Rule::in + required)
  SC#4: PASSED  # ThankYou/AlreadyCompleted/Expired preservados
  SC#5: PASSED  # Zero jargão técnico (grep confirma)

# Métricas agregadas
plans: 3
waves: 3
controllers-modified: 1   # NpsController.php (respond method)
frontend-components-created: 1  # RespondLegado.jsx
frontend-components-modified: 2 # PreviewFormulario.jsx + Respond.jsx (rewrite)
routes-modified: 0        # Rotas /nps/{token} preservadas — só handler mudou
tests-created: 10         # 5 render + 5 submit
test-assertions: 127
regression-preserved: 108  # Phase 31 + 33 + 68 + 69 + 70 continuam 108/108 verdes
grand-total-nps-tests: 118
duration-total: ~35min
completed: 2026-07-08
---

# Phase 71 Summary — Formulário público dinâmico

Backend eager-load do template no `NpsController::respond` com prop dual-path
(v15 shape + null em legacy) + frontend reescrito com `PreviewFormulario`
controlado + `Respond.jsx` como roteador + `RespondLegado.jsx` byte-a-byte da
Phase 33 preservado + suite Feature de 10 tests cobrindo SC1-SC5 com zero
regressão em Phases 31/33/68/69/70. Ready para Phase 72 (dashboards +
pendências) que já pode assumir que qualquer survey — v15 ou legacy — renderiza
corretamente.

## Waves executadas

### Wave 1 — Plan 71-01

Backend prop injection dual-path:

- **`NpsController::respond`** ganha eager-load `template.questions.options` na
  mesma query de `NpsSurvey::with([...])` (evita N+1 — 6 queries fixas
  independente de N perguntas/opções)
- Monta `$templatePayload` condicional: quando `template_id !== null` E
  `$survey->template` presente, injeta shape `{id, nome, descricao,
  perguntas: [{id, ordem, texto, tipo, dimensao, obrigatoria, options: [{id,
  ordem, label, peso}]}]}` na render prop `template`
- Quando template_id NULL → `$templatePayload = null` (não array vazio, para
  Respond.jsx testar `if (!template)`)
- Zero mudança em props legacy (`survey.textos`, `estrategista_name`,
  `analista_name`, `tem_analista`, `perguntas_extras`) — surveys Phase 31/33
  continuam funcionando end-to-end

### Wave 2 — Plan 71-02

Frontend dual-path completo:

- **`PreviewFormulario.jsx` (Phase 70-05)** ganha props opcionais `value`,
  `onChange`, `errors` — controlled pattern com fallback state interno para
  retrocompat 100% com Configuracao.jsx (Phase 70). Helpers `perguntaKey` e
  `optionKey` normalizam id (Phase 71) vs ordem/peso (Phase 70 preview cru)
- **`RespondLegado.jsx` (novo)** — cópia byte-a-byte de Respond.jsx pré-Phase
  71 (352 LOC). Renderiza quando `template === null` (survey legacy Phase 31/33)
- **`Respond.jsx` reescrito (352 → 176 LOC)** — entrypoint delegate
  (`if (!template) return <RespondLegado ... />`) + componente interno
  `RespondV15` com useForm/useMemo isolados. `podeEnviar` client-side +
  regex parser de errors `answers.<qid>` → inline por pergunta no
  PreviewFormulario. Submit via `route('nps.submit', survey.token)` com
  payload `{ respondent_name, comment, answers: { [qid]: option_id } }` que
  bate exato com submitResponseV15 (Phase 69-03)
- Layout mobile-first `max-w-md`, sem AppLayout, tokens ECF respeitados

### Wave 3 — Plan 71-03

Suite Feature Phase 71 (10 tests / 127 assertions):

- **`NpsRespondRenderTest.php`** — 5 tests do GET /nps/{token}:
  - v15 template prop shape completo (id/perguntas/options)
  - legacy sem template_id → template=null + props Phase 33
  - status=completed → AlreadyCompleted
  - expirado → Expired + status atualizado no banco
  - token inválido → 404
- **`NpsRespondSubmitFlowTest.php`** — 5 tests do POST /nps/{token}:
  - submit v15 completo → NpsResponse + N answers com snapshot congelado
  - obrigatória omitida → 302 + `answers.<qid>` session error (padrão Inertia)
  - option_id de outro template → Rule::in fail
  - dedup 23000 → AlreadyCompleted (sanity end-to-end path Phase 71)
  - sucesso → ThankYou + status=completed + completed_at populado

Regressão zero: Phase 31 + 33 + 68 + 69 + 70 seguem 108/108 verdes.

## Contract entregue

- **REQ NPS-D-01 a NPS-D-05** — 5/5 fechados (backend + frontend + testes)
- **SC1-SC5** do ROADMAP Phase 71 — 5/5 PASSED
- **Rotas:** `/nps/{token}` (GET + POST) preservadas com nomes canônicos —
  handler modificado, nome/path inalterados. Zero break de link externo já
  enviado ao cliente.
- **`PreviewFormulario.jsx` reusado idêntico** entre Configuracao (Phase 70) e
  Respond (Phase 71) — controlled props opcionais garantem retrocompat 100%
- **`RespondLegado.jsx` isolado** — Phase 73 pode deletar quando remover
  suporte legado sem impacto no fluxo v15

## Padrões travados para próximas fases

1. **Controlled component pattern com fallback interno** — `PreviewFormulario`
   detecta `typeof value === 'object' && typeof onChange === 'function'` e
   escolhe controlled OU state interno. Permite reuso em N caller sem fork.
2. **Delegate + componente interno** — Respond.jsx faz early-return para
   RespondLegado ANTES de qualquer useHook, evitando "Rendered fewer hooks
   than expected" quando o mesmo arquivo serve dois shapes de prop.
3. **Regex parser de errors** — `^answers\.(\d+)$` mapeia validation errors
   do Laravel para `{ [qid]: msg }` — pattern reusável para qualquer
   pergunta dinâmica.
4. **Prop dual-path com null explícito** — `template=null` (não array vazio,
   não undefined) é o discriminador entre paths v15 e legacy. Bate exato com
   frontend `if (!template)`.
5. **Zero jargão técnico visível ao cliente** — só `texto` da pergunta e
   `label` da opção chegam ao DOM. `dimensao`, `peso`, `snapshot` ficam nos
   metadados internos.

## Métricas finais

| Métrica | Valor |
|---|---|
| Plans | 3 |
| Waves | 3 |
| Controllers backend modificados | 1 (NpsController.php método respond) |
| Componentes React criados | 1 (RespondLegado.jsx) |
| Componentes React modificados | 2 (PreviewFormulario.jsx + Respond.jsx reescrito) |
| LOC delta (frontend) | +377 (RespondLegado +352 novo, Respond -176 líquido, PreviewFormulario +41) |
| LOC delta (backend) | +46 / -4 |
| Rotas modificadas | 0 (nomes preservados, handler modificado) |
| Tests Feature Phase 71 | 10 (127 assertions) |
| Tests NPS totais (Phases 31/33/68/69/70/71) | 118 (764 assertions) |
| Regressão | 0 |
| Duração total | ~35min |
| Completed | 2026-07-08 |

## Próximos passos liberados

- **Phase 72 (Dashboards + pendências + dia de cobrança)** — pode assumir que
  qualquer survey renderiza corretamente e produz answers com snapshot
  congelado. `NpsPendingService::forCarteira` + badge em `Portfolio/Show.jsx`
  e `Companies/Index.jsx` desbloqueados.
- **Phase 73 (Limpeza legado + testes E2E)** — pode remover `RespondLegado.jsx`
  quando todos os surveys legacy tiverem sido retro-associados a template_id
  (seed Phase 68-03 já cobre 100% do histórico existente; apenas surveys
  criadas por rotas ainda não migradas ficariam órfãs — checar auditoria antes
  do delete).
