# Phase 55: Modernização visual /sugadores + Magic UI — Context

**Gathered:** 2026-07-03
**Status:** Ready for research
**Source:** briefing 2026-07-02 bloco C do TODO `270702-refinamentos-sugadores-uat-e-magic-ui.md` + print de referência (shadcn/ui data table)

<domain>
## Phase Boundary

Após 4 phases anteriores no módulo Sugadores (52, 53, 54), a estrutura e comportamento estão maduros — falta o polimento visual. Operador mandou print de referência (tabela shadcn/ui estilo dashboard) e sugeriu Magic UI. Esta phase entrega **redesign da tabela** e **animações seletivas**, mantendo dark theme + tokens `ecf-*`.

**Estado atual pós-Phase 54:**
- `EmpresaListagem.jsx` (593 linhas): grid CSS custom com checkboxes redondos, sem drag, coluna empresa já removida (A4)
- `Show.jsx` (866 linhas): view individual sem ConfigResumoCard nem botão rodar análise (A2)
- `Index.jsx` (567 linhas): cards + busca de empresa + filtro analista

**Componentes shadcn já disponíveis no projeto (dependencies OK):**
- `Components/ui/table.jsx` — **NÃO USADO** em Sugadores hoje (grid custom vive na EmpresaListagem)
- `Components/ui/dropdown-menu.jsx` — usar para "Customize Columns" e menu de ações "⋯"
- `Components/ui/dialog.jsx` — modais existentes
- `Components/ui/tabs.jsx` — já usado em outros lugares
- `Components/ui/checkbox.jsx` (Radix) — precisa criar shadcn wrapper se não existir

**Não instalado:**
- `framer-motion` — Magic UI depende para várias animações. Custo: ~50kb bundle. Vale se usarmos 2+ componentes Magic UI.
- Lib de drag-and-drop (`@dnd-kit/*` ou `react-beautiful-dnd`) — necessária se implementar drag handles para reordenar linhas.

</domain>

<decisions>
## Implementation Decisions

### Escopo LOCKED

**C1 — Redesign tabela EmpresaListagem estilo shadcn/ui (print de referência):**
- Substituir o grid CSS custom por `<Table>` do shadcn (`Components/ui/table.jsx`)
- Header cinza suave — dark theme adaptado (não copiar cinza claro do print literal)
- **Checkbox quadrado shadcn** (Radix Checkbox) — substitui checkbox nativo
- **Row hover subtle** — bg mais escuro em `hover:bg-white/[0.03]`
- **Menu de ações "⋯"** por linha (DropdownMenu) — substitui os botões inline (Copiar MLBs, Ver detalhes)
- **Botão "Customize Columns"** com DropdownMenu para mostrar/ocultar colunas — persiste seleção em localStorage
- **Botão "+ Add Section"** — OUT LOCKED. Não se aplica ao domínio sugadores (sem hierarquia de seções nem workflow de criação manual). Deixar para futura phase se necessário.
- **Drag handles: OUT LOCKED (research 2026-07-03).** Sugadores são ordenados por `detected_at` — sem caso de uso de priorização manual. Não instala `@dnd-kit`.

**C2 — Magic UI seletivo (só o que agrega):**

**REDUZIDO PÓS-RESEARCH 2026-07-03:** Magic UI depende de framer-motion (~50-60kb gzipped). Custo alto para valor marginal em animações decorativas. Decisão LOCKED:

- **CORTAR** Number Ticker, Ripple, Shimmer Button, Animated List — todos dependem de framer-motion
- **PRESERVAR** o efeito Blur Fade via `tailwindcss-animate` (já instalado — 0kb novos): classes `animate-in fade-in duration-300` no container principal do EmpresaListagem
- **framer-motion NÃO instala** — mantém bundle atual
- Se no futuro operador quiser mais animações, revisitar (nada bloqueia)

### FORA da Phase 55 (locked)

- Redesign do Show.jsx individual — mantém como está (view leve, não é foco de dashboard)
- Redesign do Index.jsx (cards de empresas) — mantém cards; refinamento visual só se pontual
- Novos filtros/features — Phase 54 já entregou os necessários
- Drag & drop entre empresas — não faz sentido (não há hierarquia)
- Tabs superiores no estilo "Outline · Past Performance 3 · Key Personnel 2" — não se aplica ao domínio (sugadores não tem seções agrupadas)

### Abordagem técnica

- **shadcn Table wrap**: refatorar EmpresaListagem trocando o `<div className="grid grid-cols-...">` por `<Table><TableHeader><TableRow><TableHead>...` — preserva 100% da lógica (props sugadores, filtros, checkboxes controlled, click row com stopPropagation, bulk actions)
- **Column visibility**: state `columnsVisibility = {mlbs: true, expires: true, ...}` com persistência via `useEffect + localStorage`. DropdownMenu com CheckboxItem por coluna. Colunas fixas (nome do produto, checkbox, actions) não aparecem no toggle.
- **Actions dropdown**: substituir os 2 botões inline (MLBs, ver detalhes) por 1 botão `<MoreHorizontal>` que abre menu com as ações. Preserva `stopPropagation` (menu não navega). Preserva "Copiar MLBs" (Wave 3 Phase 52) e "Ver detalhes" (redundante com click row mas mantém para acessibilidade).
- **Magic UI copy-paste**: baixar código dos componentes (magicui.design/docs) e colar em `resources/js/Components/magicui/`. NÃO adicionar como dep NPM. Framer-motion adicionado só se 2+ componentes usarem.
- **Bundle audit**: após implementação, rodar `npm run build` e comparar size do bundle vs baseline. Se aumentar >100kb, revisar quais Magic UI componentes valem.

### Claude's Discretion

- Componente `Checkbox` shadcn — verificar se já existe em `Components/ui/checkbox.jsx`; se não, criar (Radix wrapper de 15 linhas)
- Drag handles — decisão do research (não bloqueia)
- Cores exatas do header — dark theme requer paleta própria (`bg-white/[0.02] border-white/[0.06]`), NÃO cinza claro literal do print
- Persistência column visibility — localStorage vs URL query string. LocalStorage é mais discreto (não polui URL).
- Testes: não é feature backend; UI puro; `npm run build` verde é critério hard. Sem PHPUnit obrigatório.

</decisions>

<specifics>
## Specific Ideas

### Tabela redesign — visual esperado (adaptado do print)

```
┌──────────────────────────────────────────────────────────────────────────┐
│ [⚙ Customize Columns ▾]  [+ Novo sugador manual]      [Buscar... 🔍]     │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐ Produto (adgroup)                Status    Detectado    Ações           │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐ Caixa Direção Hidráulica Chery   • Pend.   01/07/2026   [⋯]            │
│ ☐ Meia 7/8 Sigvaris                • Pend.   01/07/2026   [⋯]            │
│ ☐ Bota Pvc Cano Extra Curto        • Pend.   30/06/2026   [⋯]            │
└──────────────────────────────────────────────────────────────────────────┘
```

**Colunas visíveis por padrão:** Produto, Status, Detectado em, Ações
**Colunas opcionais (toggle):** Campaign name, MLB ID, Motivos, Investimento, Vendas
**Colunas fixas (sem toggle):** Checkbox, Ações

### Header animações (Magic UI)

- Contador "N pendentes" com Number Ticker (conta de 0 até N ao carregar)
- Blur Fade no container ao entrar na página (300ms)

### Actions menu por linha (DropdownMenu)

- Copiar MLBs
- Ver detalhes
- Marcar em ação (bulk-move existente — chamada singular)
- Marcar resolvido
- Ignorar

Reusa handlers existentes; só muda a UI dos triggers.

### Rejeitados do briefing

- "+ Add Section" — sugadores não têm hierarquia de seções; ignorar. Substituir por "+ Novo sugador manual" (link para form de criação — se ainda não existe, deferir).
- Tabs superiores estilo print — não se aplica.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning ou implementing.**

### Briefing

- `.planning/todos/pending/270702-refinamentos-sugadores-uat-e-magic-ui.md` — bloco C (Phase 55)
- Print de referência mencionado no briefing (tabela shadcn com Outline/Past Performance/Key Personnel/Focus Documents)

### Patterns existentes (a investigar/reusar)

- `resources/js/Components/ui/table.jsx` — shadcn Table wrapper (usar!)
- `resources/js/Components/ui/dropdown-menu.jsx` — para Customize Columns + actions menu
- `resources/js/Components/ui/dialog.jsx` — modais existentes
- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (593 linhas pós Phase 54) — alvo do redesign
- `resources/js/Pages/Sugadores/Show.jsx` (866 linhas) — não mexer no visual (view leve)
- Alguma página do projeto usa shadcn Table já (`grep -rln "TableHeader" resources/js` — reusa o pattern)

### Magic UI

- Site: https://magicui.design
- Componentes candidatos: Number Ticker, Blur Fade, Ripple (leves), Animated List (média), Shimmer Button (leve)
- Modo de uso: copy-paste do código em `resources/js/Components/magicui/`
- Dependência: framer-motion (~50kb gzipped) — instalar só se 2+ componentes usarem
- Baixar da doc oficial cada componente escolhido; NÃO instalar como pacote NPM único

### Memory cross-refs

- `feedback_project_priorities` — acertividade + praticidade
- `feedback_gsd_language_pt_br` — pt-BR
- `feedback_lean_planning` — pular discuss/plan-check overhead — APLICADO

</canonical_refs>

<deferred>
## Deferred Ideas

- Drag & drop de linhas (se research decidir NÃO incluir) — Phase futura se operador quiser priorização manual persistida
- Redesign do Show.jsx individual — visualização leve não precisa mudar
- Redesign do Index.jsx (cards de empresa) — refinamento pontual só se necessário no UAT
- Column resizing (arrastar largura) — nem foi mencionado no briefing
- Tabs superiores estilo shadcn Outline/Past Performance — não se aplica ao domínio
- "+ Add Section" literal do print — sugadores não têm seções
- Modo compacto (row density) — nice-to-have, não escopo
- Confetti ao resolver todos sugadores — não pediram

</deferred>

---

*Phase: 55-modernizacao-visual-sugadores-magic-ui*
*Context gerado: 2026-07-03 (síntese lean — bloco C do briefing UAT Phase 52)*
