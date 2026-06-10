---
phase: 31-nps-mensal-automatizado
plan: 03
subsystem: nps
tags: [frontend, inertia, react, nps, ui-publica]
requires:
  - 31-01 (schema nps_responses 1-5)
  - 31-02 (NpsController::respond payload com tem_analista/estrategista_name/analista_name + submitResponse validacao 1-5)
provides:
  - Form publico Nps/Respond.jsx reescrito escala 1-5 + 3 dimensoes + textarea livre + nome opcional
affects:
  - Cliente final responde a survey NPS no /nps/{token} com nova UX
  - URL publica /nps/{token} agora alinhada com schema novo do banco e validacao do controller (sem 422 silencioso)
tech_stack:
  added: []
  patterns:
    - "RatingPicker local 5 botoes 1-5 com gradiente cor por valor selecionado (vermelho->emerald)"
    - "Tokens ecf-bg/ecf-card/ecf-yellow + border-white/[0.08] do CLAUDE.md (substitui tokens shadcn genericos bg-background/bg-card)"
    - "useForm() do @inertiajs/react com chaves alinhadas ao backend (score_estrategista, score_analista, score_empresa, comment, respondent_name)"
    - "Renderizacao condicional {survey.tem_analista && ...} para omitir bloco analista em mentoria pura (D-07)"
    - "aria-label + aria-pressed por botao para acessibilidade minima"
key_files:
  created: []
  modified:
    - resources/js/Pages/Nps/Respond.jsx
decisions:
  - "Cores selecionadas seguem gradiente Pessimo->Otimo: red-500 / orange-500 / yellow-500 / lime-500 / emerald-500. Niveis 3 e 4 usam text-black por contraste com fundo amarelo/lime mais claro"
  - "Botao submit usa bg-ecf-yellow text-black (CTA primaria padrao do projeto) em vez de bg-primary generico"
  - "Validacao client-side espelha o backend: estrategista + empresa sempre obrigatorios; analista obrigatorio APENAS quando survey.tem_analista. Botao 'Enviar avaliacao' fica disabled ate validar (UX previne 422)"
  - "Labels dos pickers: prefixo 'O atendimento do' + nome (fallback 'Estrategista'/'Analista' se nome null). Empresa: 'A ECF esta atendendo suas expectativas?' (texto literal do D-07)"
  - "Textarea sem icone Lucide para preservar footprint pequeno (sem novo import)"
metrics:
  duration_minutes: 1
  tasks_completed: 1
  files_created: 0
  files_modified: 1
  commits: 1
  completed_at: "2026-06-10T21:53:17Z"
---

# Phase 31 Plan 03: UI Nps/Respond.jsx — Escala 1-5 + 3 dimensoes (Summary)

**One-liner:** Reescreve a pagina publica `Nps/Respond.jsx` substituindo o seletor 0-10 anterior por 3 RatingPickers 1-5 com gradiente vermelho->verde, renderizacao condicional do bloco Analista (mentoria pura — D-07), textarea livre de 2000 chars e campo nome opcional — alinhado com schema do Plan 31-01 e payload/validacao do Plan 31-02.

## O que foi feito

1 task, 1 arquivo modificado, 1 commit. Cliente final acessando `/nps/{token}` agora ve a nova experiencia: 3 perguntas com 5 botoes grandes coloridos (1=vermelho, 2=laranja, 3=amarelo, 4=lime, 5=emerald), labels curtas "Muito ruim"/"Excelente" embaixo, comentario livre opcional e nome opcional.

### Task 1 — Reescrita Nps/Respond.jsx

**Componente local `RatingPicker`** (substitui o antigo `ScorePicker` 0-10):
- 5 botoes `w-12 h-12 rounded-xl` (1 a 5)
- Cores por valor selecionado (gradiente Pessimo -> Otimo):
  - 1: `bg-red-500 border-red-500 text-white`
  - 2: `bg-orange-500 border-orange-500 text-white`
  - 3: `bg-yellow-500 border-yellow-500 text-black`
  - 4: `bg-lime-500 border-lime-500 text-black`
  - 5: `bg-emerald-500 border-emerald-500 text-white`
- Nao-selecionado: `border-white/[0.08] bg-white/[0.03] text-white/50 hover:border-ecf-yellow/40 hover:text-ecf-yellow`
- Labels embaixo: "Muito ruim" (esq) / "Excelente" (dir) — `text-xs text-white/40`
- Acessibilidade: `aria-label="Nota N"` + `aria-pressed={value === i}` por botao

**Componente default `NpsRespond({ survey })`:**
- `useForm({ respondent_name: '', score_estrategista: null, score_analista: null, score_empresa: null, comment: '' })`
- Submit: `post(route('nps.submit', survey.token))`
- Validacao client-side `isValid`:
  ```js
  data.score_estrategista !== null
    && data.score_empresa !== null
    && (!survey.tem_analista || data.score_analista !== null);
  ```

**Layout (dark theme ECF):**
- Wrapper `min-h-screen bg-ecf-bg flex items-center justify-center p-4`
- Card central `max-w-xl`:
  - Header: logo amarela ECF (`bg-ecf-yellow`, letra "E" preta), titulo "Avaliacao de Atendimento", `survey.company_name`, subtitulo "ECF Consultoria"
  - Container form `rounded-xl border border-white/[0.08] bg-ecf-card p-6 space-y-6`:
    - Bloco "Seu nome (opcional)" — Input `maxLength={255}` sem `required` (D-07 nullable)
    - Heading "AVALIE DE 1 A 5"
    - `RatingPicker` Estrategista — label `O atendimento do {survey.estrategista_name || 'Estrategista'}` (sempre renderiza)
    - `RatingPicker` Analista — APENAS quando `survey.tem_analista === true` (D-07 mentoria pura)
    - `RatingPicker` Empresa — label `A ECF esta atendendo suas expectativas?` (literal D-07)
    - Textarea livre — label "Opinioes, sugestoes ou outra coisa que queira compartilhar (opcional)", `rows={4}`, `maxLength={2000}`, mesmo texto de placeholder (D-08)
    - Erros `errors.score_*`/`errors.comment`/`errors.respondent_name` mostrados em `text-red-400 text-xs` abaixo de cada campo
  - Botao submit `w-full bg-ecf-yellow text-black hover:bg-ecf-yellow/90 font-semibold` — "Enviar avaliacao" / "Enviando..." (processing)
  - Footer fora do card: `text-white/40 text-xs` "ECF Consultoria · Suas respostas sao confidenciais"

**Build:** `npm run build` rodou verde em 18.86s. Bundle `Respond-3fLPnhgK.js` gerado em `public/build/assets/`.

## Arquivos afetados

### Modificados
- `resources/js/Pages/Nps/Respond.jsx` — substituicao quase total (~113 inserts, 55 deletes)

### Criados
Nenhum.

## Commits

| Hash      | Mensagem                                                                          |
| --------- | --------------------------------------------------------------------------------- |
| `101dc7a` | `feat(31-03): reescreve UI Nps/Respond.jsx com escala 1-5 + 3 dimensoes`          |

## Deviations from Plan

Nenhuma. Plan 31-03 escopo era pequeno (1 task, 1 arquivo) e o briefing do plan + decisoes locked D-06/D-07/D-08 cobriram todas as escolhas. Detalhes esteticos discricionarios (cores do gradiente, tamanho dos botoes 12x12, padroes de fallback de nomes) seguiram exatamente o que o plan ja sugeria.

## Gotchas / Proximos passos

### Validacao do backend (ja entregue no Plan 31-02)

Confirmado: `NpsController::submitResponse` valida `respondent_name: nullable|string|max:255`, `score_estrategista: required|integer|min:1|max:5`, `score_analista: nullable|integer|min:1|max:5`, `score_empresa: required|integer|min:1|max:5`, `comment: nullable|string|max:2000`. Chaves do payload sao identicas as do `useForm()` aqui.

### Inertia redirect pos-submit

Backend (Plan 31-02 Task 3c) redireciona pro `ThankYou.jsx` apos submit valido. Esse arquivo NAO foi alterado neste plan (sem mudanca de stack/texto). `AlreadyCompleted.jsx` e `Expired.jsx` tambem ficaram intactos.

### Para Plan 31-04 (UI admin NPS)

O frontend admin (`Pages/Nps/Index.jsx` linhas 82-84) ainda usa colunas legacy `score_consultant/mentor/overall` — vai 500 em prod ate o Plan 31-05 reescrever. Nao e escopo deste plan.

### Para deploy

NAO fazer deploy isolado deste plan. Conforme o SUMMARY do Plan 31-02 ja avisava: "agrupar deploy de 31-01, 31-02, 31-03, 31-04 e 31-05 juntos". Plan 31-03 sozinho em prod NAO causa erro (eh apenas frontend publico), mas a `/nps` admin continua quebrada ate 31-05.

### Smoke visual

Deferido (padrao do projeto). Verificacao manual recomendada apos deploy:
- (a) Survey ativo de empresa com analista → 3 RatingPickers
- (b) Survey ativo de empresa mentoria pura (`tem_analista=false`) → 2 RatingPickers (sem o bloco do meio)
- (c) Cores 1-5 renderizam gradiente vermelho->verde
- (d) Botao "Enviar avaliacao" disabled ate selecionar score_estrategista E score_empresa (E score_analista se tem_analista)
- (e) Submit com nome em branco passa normal
- (f) Submit redireciona pro `/nps/{token}/thank-you`

## Conhecimento ganho

- O arquivo legacy ja usava `useForm` do `@inertiajs/react`, `Button`/`Input`/`Label`/`Textarea` do `@/Components/ui/` e `cn()` de `@/lib/utils` — todos os imports foram reutilizados sem adicionar dependencia.
- Tailwind config tem tokens `ecf-bg=#050507`, `ecf-card=#0f1116`, `ecf-yellow=#ffe600` confirmados em `tailwind.config.js` linhas 65-75.
- O arquivo legacy `ScorePicker` ja usava o mesmo padrao `Array.from({ length: 11 }, ...)` + cor por valor → mantida a mesma arquitetura (componente local sub-funcao) com `[1,2,3,4,5].map(...)`.
- O backend Plan 31-02 nao exige enviar `score_analista` no payload quando `tem_analista=false` (campo eh nullable). `useForm` envia null por default (valor inicial), que passa na validacao `nullable|integer|min:1|max:5`.

## Threat Flags

Nenhuma. Frontend publico (sem auth) ja era public-by-design no `/nps/{token}` — comportamento intencional pre-existente. Token UUID + status `pending` continuam sendo o controle de acesso. Nao ha mudanca de boundary, novo endpoint, ou nova superficie de threat. Validacao backend continua sendo a fonte de verdade (Plan 31-02 ja cobriu).

## Self-Check: PASSED

- ✓ `resources/js/Pages/Nps/Respond.jsx` modificado (113 inserts, 55 deletes)
- ✓ Grep `score_consultant|score_mentor|score_overall` em Respond.jsx retorna 0 hits
- ✓ Grep `score_estrategista|score_analista|score_empresa` retorna 17 hits (incluindo errors)
- ✓ Grep `survey.tem_analista` retorna 4 hits (comentarios + render condicional + isValid)
- ✓ Grep `Array.from({ length: 11` retorna 0 hits (escala 0-10 removida)
- ✓ `npm run build` verde (18.86s, sem erros nem warnings criticos)
- ✓ Bundle `public/build/assets/Respond-3fLPnhgK.js` gerado (4.7 kB)
- ✓ Commit `101dc7a` existe em `git log` no branch main
- ✓ Sem deletion files no diff do commit
