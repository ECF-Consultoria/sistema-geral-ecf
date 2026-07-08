---
phase: 70-ui-de-configuracao-admin
milestone: v15.0
plan: 70-05
type: execute
wave: 3
files_created:
  - resources/js/Components/Nps/Config/TemplatesList.jsx
  - resources/js/Components/Nps/Config/TemplateEditForm.jsx
  - resources/js/Components/Nps/Config/QuestionEditor.jsx
  - resources/js/Components/Nps/Config/OptionsEditor.jsx
  - resources/js/Components/Nps/Config/ServiceScopesPicker.jsx
  - resources/js/Components/Nps/Config/PreviewFormulario.jsx
  - resources/js/Pages/Nps/ConfiguracaoLegado.jsx
files_modified:
  - resources/js/Pages/Nps/Configuracao.jsx
  - app/Http/Controllers/NpsTemplateController.php
  - app/Http/Controllers/NpsController.php
  - routes/web.php
tags: [nps, frontend, react, jsx, inertia, configuracao, templates, multi-column, preview-live, ecf-tokens, dark-theme, phase70]
requirements_completed: [NPS-C-01, NPS-C-02, NPS-C-03, NPS-C-04, NPS-C-05, NPS-C-06]

metrics:
  lines_added: ~1885
  components_new: 6
  page_rewritten: 1
  routes_reorganized: 4
  bundle_size_configuracao: 38.75kB
  duration: ~13min
completed: 2026-07-08
---

# Plan 70-05 Summary — Reescrita `Configuracao.jsx` multi-template + 6 componentes filhos

Reescrita completa da página `/nps/configuracao` com layout multi-template (lista à esquerda + editor à direita + preview live sticky) orquestrando 6 componentes filhos novos em `resources/js/Components/Nps/Config/`. Legado Phase 33 preservado sob `/nps/configuracao/textos-legado` sem quebra visual nem funcional.

## Entregas

### Componentes filhos (novos em `resources/js/Components/Nps/Config/`)

| Componente | LOC | Responsabilidade |
|---|---|---|
| `TemplatesList.jsx` | 120 | Lista de templates com badges (padrão, inativo) + botão Novo + seleção controlada |
| `TemplateEditForm.jsx` | 217 | Form nome/descricao/priority + toggle-active + guard is_default disabled |
| `QuestionEditor.jsx` | 510 | CRUD inline perguntas + reorder ⬆⬇ + tipo IMUTÁVEL após criação |
| `OptionsEditor.jsx` | 388 | CRUD inline opções + peso 1..5 clamp + guard mínimo 1 opção escala |
| `ServiceScopesPicker.jsx` | 254 | Multi-select servicos + save + fetch empresas-afetadas debounced |
| `PreviewFormulario.jsx` | 160 | **Componente PURO** — reused idêntico pela Phase 71 (form público) |

### Página reescrita

`resources/js/Pages/Nps/Configuracao.jsx` — de 1076 LOC (Phase 33) para 236 LOC. Orquestra os 6 filhos via grid `320px_1fr` desktop / 1 col mobile. Preview live debounced 300ms POSTando `route('nps.configuracao.templates.preview')` — atualiza automaticamente quando template selecionado muda ou props recarregam via `router.reload({only: ['templates']})`.

### Legado preservado

`resources/js/Pages/Nps/ConfiguracaoLegado.jsx` — cópia integral do `Configuracao.jsx` pré-reescrita (1076 LOC). Sem alteração de comportamento, apenas renomeada. `NpsController::configuracao` agora renderiza `Nps/ConfiguracaoLegado`.

### Rotas reorganizadas

Estrutura final (`php artisan route:list --path=nps/configuracao` → 25 rotas):

- **Nova canônica:** `GET /nps/configuracao` → `NpsTemplateController::index` (nome `nps.configuracao.index` herdado da legada — preserva menu/breadcrumbs)
- **Legado renomeado:** `/nps/configuracao/textos-legado` (GET/PUT/POST preview) → `NpsController::configuracao/atualizarConfiguracao/previewEmail`
- **Aliases de nome preservados:** `nps.configuracao.update` e `nps.configuracao.preview` ainda funcionam apontando ao path novo `/textos-legado/*` — não quebra chamadas ziggy do `ConfiguracaoLegado.jsx`
- **Rotas de perguntas customizadas (Phase 33):** `nps.configuracao.perguntas.*` INTACTAS — a UI legada continua CRUDando perguntas extras normalmente

### Props expandidas em `NpsTemplateController::index`

Adicionados 3 props que o front consome:
- `tipos_pergunta` — `NpsTemplateQuestion::TIPOS`
- `dimensoes_labels` — `NpsTemplateQuestion::dimensoesLabels()`
- `servicos_disponiveis` — `Servico::active()->orderBy('nome')->get(['id','nome','setor'])`

Eager-load `->with('questions.options')` na query de templates — habilita preview live sem N+1.

## Contract respeitado

- **Zero dep nova** (nem `@dnd-kit`, nem `react-beautiful-dnd`) — reorder via `ArrowUp`/`ArrowDown` Lucide + `type=number`
- **Design tokens ecf-*** — `bg-ecf-bg`, `bg-ecf-card`, `border-white/[0.08]`, `bg-ecf-yellow`, `cn()` utility em todos os componentes
- **Guards UX travados:**
  - `is_default` → checkbox toggle-active `disabled` (com tooltip)
  - Tipo IMUTÁVEL → renderiza badge cinza no modo edit, nunca `<select>`
  - Peso `< 1 || > 5` → botão Salvar `disabled` + hint vermelho
  - Última opção em escala → botão excluir `disabled` com aviso "precisa ter ao menos 1"
- **PreviewFormulario é PURO** — zero `useForm`, zero `axios`, zero `router` (grep confirmado)
- **Preview live** — debounce 300ms; radio group cinza (`bg-white/[0.03]`) → amarelo (`bg-ecf-yellow`) no ativo; asterisco vermelho nas obrigatórias

## Verificações executadas

- `php -l` verde em `NpsController.php`, `NpsTemplateController.php`, `routes/web.php`
- `npm run build` verde — asset `Configuracao-BdUNMBdp.js` (38.75 kB) + `ConfiguracaoLegado-CG8V5zFg.js` no `public/build/manifest.json`
- `php artisan route:list --path=nps/configuracao` → 25 rotas, todas com middleware chain correta (auth + role:admin no bloco admin, público para /nps/{token} legado)
- Zero regressão em rotas legadas — nome `nps.configuracao.index` continua resolvendo (agora para o novo controller)

## Deviations

Nenhuma deviation de contrato. Ajuste operacional único: adicionados 2 aliases de nome de rota (`nps.configuracao.update` e `nps.configuracao.preview` apontando aos paths `/textos-legado/update` e `/textos-legado/preview-alias`) para preservar chamadas ziggy do `ConfiguracaoLegado.jsx` sem obrigar refactor da UI legada. Trade-off: 2 linhas extras em `routes/web.php` vs. reescrever `ConfiguracaoLegado.jsx` (1076 LOC de mudanças cirúrgicas de rotas). Alternativa aceita — Phase 73 cleanup pode remover os aliases.

## REQs fechados

- **NPS-C-01** — Admin cria/edita/desativa templates via `/nps/configuracao` ✓
- **NPS-C-02** — CRUD perguntas inline com reorder ⬆⬇ ✓
- **NPS-C-03** — Opções com label + peso 1..5 + ordem ✓
- **NPS-C-04** — Dimensão (select) + obrigatoriedade (checkbox) por pergunta ✓
- **NPS-C-05** — ServiceScopesPicker multi-select + feedback de empresas afetadas ✓
- **NPS-C-06** — Preview live debounced 300ms via `PreviewFormulario` puro ✓

## Próximos passos liberados

- Plan 70-06 (Feature tests Phase 70) — desbloqueado
- Phase 71 (Formulário público dinâmico) — pode importar `PreviewFormulario` IDÊNTICO em `Respond.jsx` sem mudança
