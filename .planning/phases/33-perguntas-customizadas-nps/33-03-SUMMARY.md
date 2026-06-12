---
phase: 33-perguntas-customizadas-nps
plan: 03
subsystem: nps
tags: [nps, frontend, respond, perguntas-customizadas, inertia, react]
requires: [phase-33-plan-01]
provides:
  - ui_component: PerguntaExtra (render por tipo D-02)
  - ui_adaptation: NpsRespond aceita prop perguntas_extras + envia respostas_extras no submit
  - form_state: respostas_extras inicializado por tipo (null para escala_1_5, '' para demais)
  - validation_client: isValid bloqueia submit ate todas obrigatorias extras estarem preenchidas
affects:
  - resources/js/Pages/Nps/Respond.jsx
tech-stack:
  added: []
  patterns:
    - "Component-level dispatch por tipo (PerguntaExtra) — switch implicito via early returns"
    - "Form data inicializado por tipo (null para escala_1_5 combinar com RatingPicker, '' caso contrario)"
    - "useForm respostas_extras como objeto plano {pergunta_id: valor} — Inertia/Laravel parseia automaticamente como array associativo no backend"
    - "Validacao client-side espelhada (todasObrigatoriasExtrasOk) — backend Phase 33 Plan 01 ainda valida com 422 para defesa"
key-files:
  created:
    - .planning/phases/33-perguntas-customizadas-nps/33-03-SUMMARY.md
  modified:
    - resources/js/Pages/Nps/Respond.jsx (+150/-2 linhas)
decisions:
  - "Sim/Nao com cores semanticas: verde (emerald-500/25) para SIM, vermelho (rose-500/25) para NAO — mais expressivo que so amarelo destacado"
  - "Multipla escolha com radio nativo + label colorivel (border-ecf-yellow quando selecionada) — segue padrao Configuracao tab perguntas"
  - "PerguntaExtra implementado como early-return chain (sem switch) — mais legivel em JSX e evita default branch invisivel"
  - "Inicializacao do respostas_extras com null para escala_1_5 e '' para os demais — combina com null check do RatingPicker e nao quebra textarea controlado"
  - "Botao submit usa o mesmo isValid local; nao espera o backend para feedback de UX"
metrics:
  duration: ~15min
  completed_date: 2026-06-12
  tasks: 1 commit atomico
  files: 1 modified
  build_status: green (37.67s)
  tests_passing: 27/27 (zero regressao Phase 31 + 8 Phase 33 verdes)
---

# Phase 33 Plan 03: Respond.jsx renderiza perguntas customizadas por tipo

**One-liner:** UI cliente (`/nps/{token}`) renderiza dinamicamente perguntas customizadas ativas entre as 3 fixas e o comentario livre — 4 tipos suportados (escala_1_5 reutiliza RatingPicker, texto vira textarea, sim_nao vira 2 botoes coloridos, multipla vira radio group); submit envia `respostas_extras` automaticamente via useForm; validacao client-side bloqueia ate todas obrigatorias estarem preenchidas; zero regressao Phase 31.

## O que foi entregue

### Componente novo: `PerguntaExtra`

Definido no proprio `Respond.jsx`, logo abaixo do `RatingPicker` existente. Recebe `{ pergunta, valor, onChange, error }` e despacha por `pergunta.tipo`:

| Tipo         | Render                                                                            |
|--------------|-----------------------------------------------------------------------------------|
| `escala_1_5` | Reutiliza `<RatingPicker />` (mesmo componente das 3 fixas)                       |
| `texto`      | `<textarea>` dark, `rows=3`, `maxLength=2000`, placeholder "Sua resposta", borda vermelha em erro |
| `sim_nao`    | 2 botoes lado a lado (`flex-1`) — SIM verde (`emerald-500`), NAO vermelho (`rose-500`) quando selecionados |
| `multipla`   | Radio group vertical; opcao selecionada destacada com borda `ecf-yellow` e fundo `ecf-yellow/10`; valor enviado = TEXTO da opcao |

### Form state (`useForm`)

Adicionado campo:
```js
respostas_extras: Object.fromEntries(
  perguntasExtras.map(p => [p.id, p.tipo === 'escala_1_5' ? null : ''])
)
```

Inicializacao por tipo evita warning "controlled input changing to uncontrolled" no React (`null` casa com o RatingPicker que checa `value === i`; `''` casa com textarea/radio que esperam string).

### Loop JSX

Inserido entre o bloco "Empresa" (3a nota fixa) e o "Comentario livre" — conforme D-03. Envolto em `{perguntasExtras.length > 0 && (...)}` para que NADA seja renderizado quando o array vem vazio (preserva fluxo Phase 31 intacto).

Cada item renderiza:
- Label em uppercase `text-xs tracking-wide text-white/70` (mesmo padrao das fixas)
- Asterisco amarelo `*` quando `pergunta.obrigatorio === true`
- `<PerguntaExtra />` delegando o render
- Mensagem de erro abaixo se `errors[respostas_extras.{id}]` existir

### `isValid` atualizado

```js
const todasObrigatoriasExtrasOk = perguntasExtras
  .filter(p => p.obrigatorio)
  .every(p => {
    const v = data.respostas_extras[p.id];
    return v !== null && v !== '' && v !== undefined;
  });

const isValid =
  data.score_estrategista !== null &&
  data.score_empresa !== null &&
  (!survey.tem_analista || data.score_analista !== null) &&
  todasObrigatoriasExtrasOk;
```

Botao submit continua usando `disabled={processing || !isValid}` — sem mudar markup do botao.

### Submit

Nao muda. `useForm` ja envia `respostas_extras` no payload pois esta no `data`. Backend Phase 33 Plan 01 ja valida dinamicamente e persiste com snapshot.

## Decisoes Made

Listadas no frontmatter `decisions`. Destaques:

- **Cores semanticas no sim_nao** — verde para SIM, vermelho para NAO. Pesquisa visual rapida cliente associa diretamente a sentimento. Tons translucidos (`/25`) seguem a estetica dark do projeto.
- **Multipla com radio nativo dentro de label colorivel** — preserva acessibilidade do `<input type="radio">` (teclado/screen reader) e ainda aplica estilo dark consistente. Sem dependencia de Radix UI nesta tela (publica, sem AppLayout).
- **PerguntaExtra com early-returns** — mais legivel que switch em JSX (sem variavel intermediaria, sem default branch fantasma). Retorna `null` no fim como fallback defensivo se chegar um tipo desconhecido (defesa contra payload corrompido).

## Commits

- `8d27e82` — feat(33-03): renderiza perguntas customizadas em Respond.jsx por tipo

## Deviations from Plan

Nenhuma. Plan 33-03 executado exatamente como especificado:
- Prop `perguntas_extras` desestruturada
- Componente `PerguntaExtra` definido inline abaixo do `RatingPicker`
- 4 tipos cobertos (escala_1_5 / texto / sim_nao / multipla)
- Loop entre score_empresa e comment (D-03)
- isValid local atualizado com `todasObrigatoriasExtrasOk`
- Submit inalterado

Pequenas adicoes defensivas (nao consideradas deviations significativas):
- `aria-pressed` nos botoes sim/nao para acessibilidade
- `aria-required` no textarea quando `obrigatorio=true`
- `return null` fallback no PerguntaExtra para tipo desconhecido

## Self-Check

- [x] `resources/js/Pages/Nps/Respond.jsx` modificado (+150/-2 linhas)
- [x] Commit `8d27e82` presente em `git log`
- [x] `npm run build` verde (built in 37.67s, sem warnings)
- [x] `php artisan test --filter=Phase31|Phase33` = 27/27 verdes (151 assertions)
  - 19 Phase 31 verdes (zero regressao)
  - 8 Phase 33 backend verdes (Plan 33-01 continua passando)
- [x] Prop `perguntas_extras` desestruturada em `NpsRespond`
- [x] `respostas_extras` adicionado ao `useForm`
- [x] Loop posicionado entre score_empresa e comment (D-03)
- [x] `isValid` inclui `todasObrigatoriasExtrasOk`
- [x] PerguntaExtra implementa os 4 tipos (escala_1_5 / texto / sim_nao / multipla)

## Self-Check: PASSED

## Gotchas para Plan 33-04 (paralelo, modal Abrir)

Sem impacto. Plan 33-04 mexe em `Nps/Index.jsx` e adiciona modal — totalmente isolado deste plan.

## Nota para integracao com Plan 33-02 (paralelo, Configuracao.jsx)

Plan 33-02 cria/edita perguntas via 3a tab. Quando admin criar uma pergunta com `ativa=true`, ela passa automaticamente a aparecer no Respond.jsx via `perguntas_extras` do payload (NpsController::respond ja filtra `ativa=true`). Nenhum hook adicional necessario aqui.

---

*Phase: 33-perguntas-customizadas-nps*
*Plan: 03 — Respond.jsx render dinamico por tipo*
*Completed: 2026-06-12*
