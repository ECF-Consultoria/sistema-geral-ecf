---
phase: 33-perguntas-customizadas-nps
plan: 02
subsystem: nps
tags: [nps, frontend, react, inertia, ui-admin, perguntas-customizadas]
requires: [phase-33-plan-01]
provides:
  - ui_tab: "3a aba 'Perguntas extras' em /nps/configuracao"
  - subcomponent: "FormPergunta (criar/editar inline com bloco condicional de opcoes)"
  - subcomponent: "CardPergunta (visualizacao com setas up/down + badges + toggle ativa + acoes)"
  - subcomponent: "ToggleSwitch (custom — projeto nao tem @radix-ui/react-switch)"
  - subcomponent: "NativeSelect (dark-themed, padrao Sugadores/Index.jsx)"
  - constant: "TIPOS_PERGUNTA (sync manual com NpsPerguntaCustomizada::TIPOS)"
  - layout_adaptativo: "Aba extras ocupa width inteiro; outras 2 mantem split 60/40 com preview"
affects:
  - resources/js/Pages/Nps/Configuracao.jsx
tech-stack:
  added: []
  patterns:
    - "Form inline compartilhado entre criar e editar (props state/setState/onSalvar/onCancelar)"
    - "Native select customizado vs Select shadcn (evita complexidade do Radix portal)"
    - "Custom ToggleSwitch (button + aria-checked + visual) — projeto nao tem Switch shadcn instalado"
    - "Validacao client-side antes do POST (min:2 opcoes em multipla) — defesa em profundidade"
    - "Sanitizacao de opcoes vazias no payload (trim + filter) antes de enviar ao backend"
    - "Layout responsivo via cn() (grid 5-col com 2-col preview OU 1-col full-width baseado em abaAtiva)"
key-files:
  created: []
  modified:
    - resources/js/Pages/Nps/Configuracao.jsx (+739 -133 linhas)
decisions:
  - "Sem dependencia nova (@radix-ui/react-switch) — implementado ToggleSwitch local em ~30 linhas"
  - "NativeSelect local em vez de Select shadcn — segue padrao Sugadores/Index.jsx, evita portal/zIndex pra form simples"
  - "Layout adapta a aba ativa: extras = full-width (sem preview de email faz sentido); demais = split"
  - "Botoes do topo 'Salvar/Restaurar padrao' ocultos na aba Extras (CRUD por-pergunta, evita confusao)"
  - "Form de criar/editar compartilha mesmo sub-componente FormPergunta — 1 source of truth para shape e validacao"
  - "Edicao abre INLINE no lugar do card (nao expande abaixo) — UX mais limpo, evita scroll perdido"
  - "Quando admin troca tipo de pergunta para 'multipla' com state limpo, popula 2 opcoes vazias (UX nudge para min:2)"
  - "Switch ativa inline na lista (sem precisar abrir edit) chama PUT apenas com { ativa } — backend campos sometimes"
  - "Validacao client min:2 opcoes ANTES do POST + erroLocal display — evita roundtrip 422"
  - "Sanitiza opcoes vazias (trim+filter) no payload — defesa contra opcoes em branco enviadas acidentalmente"
metrics:
  duration: ~25min
  completed_date: 2026-06-12
  tasks: 1 commit atomico
  files: 1 modified
  lines_added: 739
  lines_removed: 133
  tests_passing: 27/27 (19 Phase 31 + 8 Phase 33)
  build: green (Configuracao.js 23.50 kB / 6.85 kB gzip)
---

# Phase 33 Plan 02: Aba "Perguntas extras" em /nps/configuracao Summary

UI admin completa de CRUD de perguntas customizadas NPS — 3a aba na pagina /nps/configuracao com form inline compartilhado (criar/editar), lista com setas up/down, badges e switch inline de ativa, todos os 4 endpoints do W1 conectados via router Inertia.

## O que foi entregue

### 3ª aba "Perguntas extras" em /nps/configuracao

Layout adaptativo: nas abas Email/Perguntas fixas mantém o split 60/40 com preview do email; na aba Extras ocupa width inteiro (não há preview de email para perguntas customizadas — elas só aparecem no Respond.jsx do cliente, Plan 33-03).

**Renomeada** a aba "Perguntas" para "Perguntas fixas" para distinguir das extras.

### Sub-componentes locais (definidos em Configuracao.jsx)

| Componente | Responsabilidade |
|------------|------------------|
| `FormPergunta` | Form compartilhado entre criar e editar — texto + select tipo + bloco condicional opções + 2 toggles + botões |
| `CardPergunta` | Card horizontal com setas ↑↓ verticais empilhadas, texto + badges (tipo/obrig/ativa/ordem), chips com opções (se multipla), switch ativa inline, botões Editar/Excluir |
| `ToggleSwitch` | Switch acessível (role=switch + aria-checked) — implementado local porque projeto não tem `@radix-ui/react-switch` instalado. Visual idêntico ao Switch shadcn |
| `NativeSelect` | Select nativo dark-themed seguindo o padrão de `Sugadores/Index.jsx` — evita complexidade do Radix portal para form interno simples |

### Constante TIPOS_PERGUNTA

Em sync manual com `App\Models\NpsPerguntaCustomizada::TIPOS` (padrão do projeto — `NpsResponse` Phase 31 faz isso com STATUS):

```js
const TIPOS_PERGUNTA = [
  { value: 'escala_1_5', label: 'Escala 1 a 5' },
  { value: 'texto',      label: 'Texto livre' },
  { value: 'sim_nao',    label: 'Sim / Não' },
  { value: 'multipla',   label: 'Múltipla escolha' },
];
```

### Fluxo CRUD

| Ação | Trigger | Chamada Inertia |
|------|---------|------------------|
| Criar | Click "Nova pergunta" → expande FormPergunta → "Salvar" | `router.post(route('nps.configuracao.perguntas.criar'), payload, { preserveScroll })` |
| Editar | Click "Editar" no card → CardPergunta vira FormPergunta in-place | `router.put(route('nps.configuracao.perguntas.atualizar', id), payload, { preserveScroll })` |
| Toggle ativa | Click no Switch inline da lista | `router.put(... atualizar ..., { ativa: !p.ativa }, { preserveScroll })` |
| Mover ↑↓ | Click setas verticais | `router.post(route('...mover', id), { direcao: 'up'\|'down' }, { preserveScroll })` |
| Excluir | Click ícone lixeira → confirm() | `router.delete(route('...excluir', id), { preserveScroll })` |

Setas ↑/↓ ficam disabled no primeiro/último item da lista (UX feedback).

### Validação client-side

Antes do POST/PUT:
- **Texto obrigatório:** trim().length > 0
- **Mínimo 2 opções em multipla:** após trim + filter de vazias

Sanitiza opções (trim + filter de strings vazias) no payload final — defesa contra envios acidentais de opções em branco. Erro local exibido como banner vermelho no form (`erroLocal` state).

Backend (Plan 33-01) já valida tudo isso e retorna 422 com `errors` — o client-side é apenas defesa em profundidade para UX (evita roundtrip).

## Decisões Made

- **Sem dependência nova (`@radix-ui/react-switch`)** — implementado `ToggleSwitch` local em ~30 linhas. Acessível via Space/Enter (button + role=switch + aria-checked). Visual coerente com tokens `ecf-yellow` + dark theme.
- **`NativeSelect` em vez de Select shadcn** — segue padrão de `Sugadores/Index.jsx` (linha 292). Evita complexidade do Radix portal (z-index, click outside) para form interno simples. Estilizado dark-themed.
- **Layout adaptativo via `cn()` no grid** — nas abas Email/Fixas, classe `lg:grid-cols-5` + `lg:col-span-3`/`lg:col-span-2`. Na aba Extras, `grid-cols-1` ocupando width inteiro. Lógica controlada por `abaAtiva` state.
- **Botões topo escondidos na aba Extras** — "Salvar alterações" e "Restaurar padrão" só fazem sentido nas abas de textos (form `useForm`). CRUD de extras é por-pergunta. Evita confusão de qual botão faz o quê.
- **Form compartilhado criar/editar (`FormPergunta`)** — 1 source of truth para shape, validação e renderização. Diferenciado apenas pelo `titulo` prop e callbacks `onSalvar`/`onCancelar`.
- **Edição inline NO LUGAR do card** — quando `editandoId === p.id`, o card é substituído pelo form em-line (não expande abaixo). UX mais limpo, evita layout shift.
- **Auto-popula 2 opções vazias ao trocar tipo para `multipla`** — UX nudge para o admin perceber o min:2 sem precisar clicar "Adicionar opção" 2x.
- **Switch ativa inline na lista** — toggle direto sem abrir form de edição. PUT com payload `{ ativa: !p.ativa }` (backend usa `sometimes` então só esse campo é atualizado).

## Deviations from Plan

**Auto-fixed (Rule 2 — Missing critical):**

1. **[Rule 2] `ToggleSwitch` custom em vez de `Switch` shadcn** — Plan dizia "Switch de @/Components/ui/switch (verificar existência; senão usar checkbox estilizado)". Verifiquei: não existe. Optei por implementar ToggleSwitch (visual de switch, semanticamente acessível com role=switch + aria-checked) em vez de checkbox porque o resto do form usa estética premium e checkbox quebraria o visual.

2. **[Rule 2] Renomeei aba "Perguntas" → "Perguntas fixas"** — Plan não menciona renomear, mas adicionar "Perguntas extras" como 3a aba criaria ambiguidade ("Perguntas" vs "Perguntas extras"). Mudei para "Perguntas fixas" para distinguir claramente as 3 fixas Phase 31 das customizadas.

3. **[Rule 2] Auto-popula 2 opções vazias ao trocar tipo para multipla** — Plan não especifica esse comportamento, mas sem isso o admin precisaria clicar "Adicionar opção" 2x para satisfazer min:2. UX nudge.

4. **[Rule 2] Validação client-side de min:2 opções não-vazias** — Plan menciona "validar pelo menos 2 antes de salvar — feedback visual". Implementado com `erroLocal` state + banner vermelho. Sanitiza (trim+filter) antes de enviar pro backend.

5. **[Rule 2] Setas ↑/↓ disabled no primeiro/último item** — Plan não menciona, mas backend faz no-op silencioso. UX feedback visual (opacity-30 + cursor-not-allowed) é melhor do que botão clicável sem efeito.

6. **[Rule 2] Botões topo Salvar/Restaurar padrão escondidos na aba Extras** — Plan não menciona, mas mantê-los criava confusão (eles enviam o form `useForm` dos textos, não as perguntas extras). CRUD de extras é por-pergunta. Ocultos.

7. **[Rule 2] Empty state quando `perguntas_extras.length === 0`** — Plan especifica a mensagem ("Nenhuma pergunta extra cadastrada. Crie a primeira acima."). Implementado com ícone ListChecks centralizado.

Nenhuma alteração arquitetural — todos auto-fixes foram cosméticos/UX dentro do escopo do plan.

## Commits

- `dfb33c9` — feat(33-02): adiciona 3a aba 'Perguntas extras' em /nps/configuracao

## Self-Check

- [x] `resources/js/Pages/Nps/Configuracao.jsx` modificado (+739 -133 linhas)
- [x] Commit `dfb33c9` no git log (`git log --oneline | grep dfb33c9` → match)
- [x] `npm run build` verde — `Configuracao.js` 23.50 kB / 6.85 kB gzip
- [x] `php artisan test --filter='Phase31|Phase33'` = 27/27 verdes (151 assertions)
- [x] 3 TabsTrigger renderizadas: "Email mensal", "Perguntas fixas", "Perguntas extras"
- [x] Prop `perguntas_extras = []` adicionada com fallback default
- [x] 4 endpoints chamados via `router.post|put|delete` com `preserveScroll: true`
- [x] `TIPOS_PERGUNTA` const em sync com `NpsPerguntaCustomizada::TIPOS`

## Self-Check: PASSED

## Ajustes estéticos aplicados (vs spec)

- **Card de pergunta:** badges com cores semânticas — tipo amarelo (`ecf-yellow/12`), obrigatória vermelha, ativa verde, desativada cinza. Quando `ativa=false`, todo o card fica em `opacity-60` para distinguir visualmente.
- **Form ativo (criar ou editar):** card com borda `ecf-yellow/20` + bg `ecf-yellow/[0.04]` para destacar que é uma área de input ativa (não passiva como o card de listagem).
- **Setas verticais:** Empilhadas em uma coluna fixa de 24px à esquerda do card. Cinza neutro com hover suave, opacity-30 quando disabled.
- **Botão Excluir:** ícone-only (`Trash2`) com hover vermelho destacado — evita poluição visual em lista com muitas perguntas.
- **Chips de opções (multipla):** rendered abaixo do texto da pergunta, em fonte 11px com bg sutil — não compete com o texto principal.

## Para a Wave seguinte / verificação manual

Smoke test sugerido (admin):
1. Acessar `/nps/configuracao` → clicar aba "Perguntas extras" → ver lista vazia
2. Click "Nova pergunta" → form expande → preenche texto, tipo=sim_nao, salva
3. Click ícone ↑ na 2a (criar mais uma) → ordem atualiza
4. Click "Editar" → form aparece in-place → muda texto → salva
5. Click switch da coluna direita → pergunta vira desativada (badge cinza)
6. Click ícone lixeira → confirm → some da lista (se sem respostas)
7. Criar pergunta tipo=multipla com 1 opção → erro local "mínimo 2 opções"

---

*Phase: 33-perguntas-customizadas-nps*
*Plan: 02 — UI aba "Perguntas extras"*
*Completed: 2026-06-12*
