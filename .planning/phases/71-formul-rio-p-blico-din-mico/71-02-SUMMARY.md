---
phase: 71-formul-rio-p-blico-din-mico
milestone: v15.0
plan: 71-02
subsystem: nps-frontend-publico
tags: [nps, frontend, react, jsx, inertia, respond, publico, template-dinamico, portabilidade, mobile-first, phase71, controlled-component, dual-path]
requirements: [NPS-D-01, NPS-D-02, NPS-D-03, NPS-D-04, NPS-D-05]

dependency_graph:
  requires:
    - 68-01 (schema nps_templates + questions + options)
    - 69-03 (submitResponseV15 aceita payload {answers: {qid: option_id}})
    - 70-05 (PreviewFormulario.jsx componente puro portátil)
    - 71-01 (NpsController::respond injeta prop `template` no Inertia render)
  provides:
    - "Formulário público NPS renderizando 100% do template snapshot (perguntas dinâmicas + labels)"
    - "PreviewFormulario com interface controlled opcional — reutilizável em preview (Phase 70) e público (Phase 71) sem fork"
    - "RespondLegado.jsx — cópia byte-a-byte de Phase 33 preservada para surveys legacy sem template_id"
    - "Contrato submit v15 fechado no front (respondent_name/comment/answers) — bate exato com Rule::in do backend Phase 69-03"
  affects:
    - "Rotas /nps/{token} — surveys v15 renderizam form dinâmico; surveys legacy continuam com form Phase 33"
    - "Bundle Vite: 3 assets (Respond, RespondLegado, PreviewFormulario) — code-split preserva lazy load"

tech-stack:
  added: []
  patterns:
    - "React 18 controlled component pattern (`value` + `onChange` opcionais; fallback state interno para retrocompat)"
    - "Delegação por early-return — Respond.jsx roteia por `!template` para RespondLegado antes de qualquer useHook, preservando ordem de hooks"
    - "Componente interno RespondV15 — separa hooks do fluxo v15 do delegate legacy sem violar Rules of Hooks"
    - "Regex parser de errors — `answers.<qid>` map do Laravel → `{ [qid]: msg }` para renderizar inline no PreviewFormulario"
    - "useMemo para guard client-side (`podeEnviar`) — recalcula quando template ou data.answers mudam"

key-files:
  created:
    - resources/js/Pages/Nps/RespondLegado.jsx
  modified:
    - resources/js/Components/Nps/Config/PreviewFormulario.jsx
    - resources/js/Pages/Nps/Respond.jsx

decisions:
  - "PreviewFormulario ganha props opcionais (`value/onChange/errors`) em vez de fork/wrapper — retrocompat garantida via detecção `isControlled`; Configuracao.jsx (Phase 70) continua chamando sem props e funciona idêntico"
  - "Helpers `perguntaKey(q) = q.id ?? q.ordem` e `optionKey(o) = o.id ?? o.peso` — Phase 70 (preview cru sem save) usa ordem/peso, Phase 71 (público) usa id real que bate com Rule::in do backend"
  - "Respond.jsx delega via `if (!template) return <RespondLegado .../>` antes de qualquer useHook — evita 'Rendered fewer hooks than expected' quando o mesmo componente serve dois shapes de prop"
  - "Componente interno `RespondV15` separado — mantém useForm/useMemo em bloco isolado, ordem estável"
  - "Botão submit disabled até obrigatórias preenchidas (`podeEnviar` useMemo) — redundância defensiva com Rule::in do server; hint pt-BR embaixo do botão explica visualmente por que está desabilitado"
  - "Rodapé em `mode='live'` do PreviewFormulario NÃO renderiza botão — Respond.jsx posiciona seu próprio submit fora do componente; preserva pureza do componente"
  - "Zero jargão técnico: JSX comentário reescrito para evitar termos como 'dimensao/snapshot/peso' até no código (garantia extra além do texto visível ao cliente)"
  - "Layout mobile-first max-w-md, sem AppLayout — cliente respondendo NPS não é usuário logado; página standalone com branding ECF no footer"

metrics:
  duration_minutes: 12
  completed_date: 2026-07-08
  tasks_completed: 4
  files_created: 2  # RespondLegado.jsx + SUMMARY.md
  files_modified: 2  # PreviewFormulario.jsx + Respond.jsx
  loc_delta: "+377 (RespondLegado +352 novo, Respond -176 líquido pós-reescrita, PreviewFormulario +41)"
---

# Phase 71 Plan 02: Formulário Público Dinâmico Frontend v15.0 — Summary

## One-liner

Reescrita completa do `Nps/Respond.jsx` com roteamento dual-path (v15 dinâmico via `PreviewFormulario` controlado + `RespondLegado` byte-a-byte da Phase 33), fechando REQs NPS-D-01 a D-05 no frontend público.

## Objetivo entregue

Cobre o REQ NPS-D-01 no front (renderiza 100% do template snapshot dinamicamente) + NPS-D-02 (radio group mobile) + NPS-D-03 (obrigatórias + guard submit) + NPS-D-04 (ThankYou/AlreadyCompleted/Expired preservados — não tocados) + NPS-D-05 (zero jargão técnico visível).

Sem este plan, mesmo com o backend Phase 71-01 injetando `template`, o `Nps/Respond.jsx` continuaria renderizando as 3 perguntas fixas de Phase 33. Este plan é a entrega visível do formulário público v15.0.

## Tarefas executadas

### T1 — PreviewFormulario refatorado (controlled props)

**Arquivo:** `resources/js/Components/Nps/Config/PreviewFormulario.jsx` (160 → 201 LOC, +41).

Mudanças aplicadas:

1. Assinatura estendida com 3 props opcionais: `value` (map `{ [pergunta_id]: option_id }`), `onChange` (`(pid, oid) => void`), `errors` (map `{ [pergunta_id]: msg }`).
2. Flag `isControlled` — detecta `typeof value === 'object' && value !== null && typeof onChange === 'function'`. Quando true, respostas vem do pai; quando false, cai no `useState` interno original (Phase 70).
3. Helpers `perguntaKey(q) = q.id ?? q.ordem` e `optionKey(o) = o.id ?? o.peso` — normaliza identificador em qualquer shape.
4. Handler `selecionar(pergunta, opcao)` chama `onChange(pk, ok)` em controlled ou muta state interno caso contrário.
5. Renderiza `errors[pk]` inline abaixo do grid de opções (`text-red-400 text-xs mt-1`) — só aparece quando o pai passa erro para aquela pergunta.
6. Rodapé em `mode='live'` **não renderiza nada** — Respond.jsx (Phase 71) posiciona os campos nome/comentário/submit fora do componente.

**Retrocompat verificada:** Configuracao.jsx (Phase 70) chama `<PreviewFormulario template={x} mode="preview" />` sem value/onChange/errors — build inclui `_PreviewFormulario-BBhi3HOp.js` nos imports de `Configuracao-brtxB6jx.js`, comprovando que o mesmo chunk é compartilhado sem regressão.

### T2 — RespondLegado.jsx criado (Phase 33 preservado)

**Arquivo:** `resources/js/Pages/Nps/RespondLegado.jsx` (novo, 352 LOC).

Cópia integral do `Respond.jsx` antes da reescrita, com **única mudança:** a linha `export default function NpsRespond(...)` foi trocada para `export default function RespondLegado(...)` (nome bate com o arquivo).

Zero mudança comportamental — todos os componentes internos (`RatingPicker`, `PerguntaExtra`), useForm com chaves fixas (`respondent_name`, `score_estrategista`, `score_analista`, `score_empresa`, `comment`, `respostas_extras`), validação client-side (`isValid`), textos dinâmicos com fallback, e submit em `route('nps.submit', survey.token)` estão preservados byte-a-byte.

Grep de conformidade:
- `wc -l`: 352 LOC (idêntico ao original).
- `grep "PreviewFormulario\|Components/Nps/Config"`: 0 matches — zero contaminação com Phase 70.

### T3 — Respond.jsx reescrito (roteamento + v15 dinâmico)

**Arquivo:** `resources/js/Pages/Nps/Respond.jsx` (352 → 176 LOC, líquido -176).

Nova estrutura:

- **Componente `Respond` (entrypoint):** early-return `if (!template) return <RespondLegado ... />` — delega Phase 33 sem chamar nenhum hook, preservando Rules of Hooks quando o mesmo componente serve dois shapes.
- **Componente interno `RespondV15`:** hooks + JSX v15.
  - `useForm({ respondent_name: '', comment: '', answers: {} })` — casca exata do payload esperado por `NpsController::submitResponseV15`.
  - `setAnswer(qid, oid)` merged em `data.answers`.
  - `podeEnviar` via `useMemo` — recalcula quando `template` ou `data.answers` mudam; true só quando todas as `q.obrigatoria` têm valor não nulo.
  - `errorsByQuestion` via `useMemo` — regex `^answers\.(\d+)$` mapeia validation errors do Laravel para `{ [qid]: msg }`, repassado ao PreviewFormulario.
  - Submit via `post(route('nps.submit', survey.token), { preserveScroll: true })`.
  - Layout mobile-first `max-w-md`, sem AppLayout, tokens `bg-ecf-bg` / `bg-ecf-card` / `bg-ecf-yellow` / `border-white/[0.08]` / `hover:bg-ecf-yellow/90` (opta por `/90` em vez de `-hover` porque `ecf-yellow-hover` não está definido no tailwind.config.js — consistente com o legacy line 338).
  - Hint pt-BR embaixo do botão: "Responda todas as perguntas marcadas com * para enviar."

Grep de conformidade:
- 16 matches para `PreviewFormulario|RespondLegado|!template|route('nps.submit'|!podeEnviar|answers\.|useForm` — todas as âncoras do contrato presentes.
- 0 matches para `dimensao|snapshot|peso.*interno|option_id.*visivel` (case-insensitive) — comentário JSX reescrito para "metadados internos" para evitar até o grep pegar palavras que nunca chegam ao DOM.

### T4 — Build + verificação

- `npm run build` → verde em 25.26s.
- Manifest inclui:
  - `assets/Respond-CA2wlGMm.js` (novo v15 entry)
  - `assets/RespondLegado-Bhe_osjX.js` (Phase 33 preservado, split-chunk)
  - `assets/PreviewFormulario-BBhi3HOp.js` (shared entre Configuracao e Respond)
- `Configuracao-brtxB6jx.js` continua listando `_PreviewFormulario-BBhi3HOp.js` nos imports → Phase 70 retrocompat 100%.
- Zero warning ou error no output de build.

## Deviations from Plan

**Nenhuma — plano executado exatamente como escrito, com um único ajuste cosmético de token:**

**[Rule 3 - Blocker mínimo] Substituição `hover:bg-ecf-yellow-hover` → `hover:bg-ecf-yellow/90`**
- **Found during:** T3 (reescrita Respond.jsx)
- **Issue:** O prompt inicial pediu `hover:bg-ecf-yellow-hover`, mas o token `ecf-yellow-hover` não está definido em `tailwind.config.js` (apenas `ecf.yellow`, `ecf.yellow-2`, `ecf.bg`, `ecf.card`, `ecf.card-2`, `ecf.line`, `ecf.dim`, `ecf.mute`).
- **Fix:** Usei `hover:bg-ecf-yellow/90` (opacity syntax do Tailwind), padrão já em uso no `RespondLegado.jsx` linha 338 e em outros componentes do projeto. Zero visual regression.
- **Files modified:** `resources/js/Pages/Nps/Respond.jsx` (uma linha).
- **Commit:** incluído no commit atômico do plano.

## Contrato validado

- Backend Phase 71-01 injeta `template` (shape `{id, nome, descricao, perguntas: [{id, ordem, texto, tipo, dimensao, obrigatoria, options: [{id, ordem, label, peso}]}]}`) → Respond.jsx consome via `template.perguntas`.
- Payload submit: `{ respondent_name, comment, answers: { [question_id]: option_id } }` → bate exato com `NpsController::submitResponseV15` (Rule::in dos option_ids, Phase 69-03).
- Validação server-side devolve errors com chaves `answers.<qid>` → regex `^answers\.(\d+)$` do `errorsByQuestion` mapeia para inline render por pergunta no PreviewFormulario.

## Success Criteria per Roadmap Phase 71

- [x] SC #1 (renderiza dinamicamente do template snapshot — nunca hardcoded) — PreviewFormulario controlled + `template.perguntas.map(...)`.
- [x] SC #2 (radio group cinza→amarelo + mobile-friendly) — PreviewFormulario já implementava; agora habilitado em `mode='live'`.
- [x] SC #3 (obrigatórias marcadas + submit disabled até tudo preenchido + server-side 422) — asterisco vermelho + `podeEnviar` client-side + Rule::in Phase 69-03.
- [x] SC #4 (ThankYou/AlreadyCompleted/Expired preservados) — arquivos não tocados; submit v15 continua redirecionando via `Inertia::render('Nps/ThankYou')`.
- [x] SC #5 (zero jargão técnico) — só `texto` da pergunta e `label` das opções visíveis; comentário JSX reescrito para não conter os termos.

## Files Reference

| Arquivo | Status | LOC | Papel |
|---------|--------|-----|-------|
| `resources/js/Components/Nps/Config/PreviewFormulario.jsx` | Modified | 201 | Componente puro reutilizado por Configuracao (preview) + Respond (live controlled) |
| `resources/js/Pages/Nps/RespondLegado.jsx` | Created | 352 | Cópia byte-a-byte de Phase 33 Respond.jsx; renderizado quando `template === null` |
| `resources/js/Pages/Nps/Respond.jsx` | Modified (rewrite) | 176 | Roteador + fluxo v15.0 dinâmico via PreviewFormulario controlled |

## Known Stubs

Nenhum stub introduzido. Todos os campos consomem dados reais do template snapshot ou do form state.

## Auth Gates

Nenhum gate de autenticação — cliente que responde NPS não é usuário logado (rota pública via token).

## Self-Check: PASSED

Arquivos verificados no filesystem:
- FOUND: `resources/js/Components/Nps/Config/PreviewFormulario.jsx` (201 LOC)
- FOUND: `resources/js/Pages/Nps/RespondLegado.jsx` (352 LOC)
- FOUND: `resources/js/Pages/Nps/Respond.jsx` (176 LOC)

Build verificado:
- FOUND: `public/build/manifest.json` inclui `Respond-CA2wlGMm.js`, `RespondLegado-Bhe_osjX.js`, `PreviewFormulario-BBhi3HOp.js`
- FOUND: `Configuracao-brtxB6jx.js` ainda importa `_PreviewFormulario-BBhi3HOp.js` (retrocompat Phase 70)
