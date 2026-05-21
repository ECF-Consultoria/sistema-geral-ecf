---
phase: 5
slug: fundacao-fechamento
status: draft
shadcn_initialized: false
preset: none
created: 2026-05-19
---

# Phase 5 — UI Design Contract: Fundação Fechamento

> Contrato visual e de interação para a fase de fundação do módulo Fechamento.
> Gerado por gsd-ui-researcher. Verificado por gsd-ui-checker.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none (componentes ECF customizados sobre Radix UI) |
| Preset | not applicable |
| Component library | Radix UI (headless), shadcn-style wrappers em `@/Components/ui/` |
| Icon library | lucide-react ^1.11.0 |
| Font | Inter (sans), Manrope (display) — definidas em `tailwind.config.js` |

Fonte: `tailwind.config.js` — `theme.extend.fontFamily`. Sem shadcn `components.json` no projeto; design system é custom ECF com tokens `ecf-*`.

---

## Spacing Scale

Todos os valores são múltiplos de 4, alinhados com os padrões de espaçamento observados em `Desenvolvimento.jsx` e `Sugadores/Index.jsx`.

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Gap entre ícone e texto em linha; padding vertical de badge |
| sm | 8px | Padding interno de botão compacto; gap entre badges de tipo |
| md | 16px | Padding horizontal de linha de empresa (px-4); gap entre colunas do row |
| lg | 24px | Padding da seção accordion expandida (py-3 px-4); espaço entre seções na página |
| xl | 32px | Margem entre o header da página e a lista de empresas |
| 2xl | 48px | Não utilizado nesta fase |
| 3xl | 64px | Não utilizado nesta fase |

Exceções:
- Touch target mínimo em botões de ação (Salvar dados/Descartar alterações): altura 36px (h-9) — múltiplo de 4
- Linha de empresa: `py-3` (12px vertical) — conforme padrão Phase 1 `EmpresaRow`

---

## Typography

Fonte: todos os tamanhos em `px` como classes Tailwind `text-[Npx]`, consistente com o padrão do projeto.

| Role | Size | Weight | Line Height | Tailwind |
|------|------|--------|-------------|---------|
| Body | 13px | 400 (regular) | 1.5 | `text-[13px]` |
| Label / caption | 11px | 600 (semibold) | 1.4 | `text-[11px] font-semibold` |
| Nome de empresa | 13px | 600 (semibold) | 1.3 | `text-[13px] font-semibold` |
| Page heading | 20px | 600 (semibold) | 1.2 | `text-xl font-semibold font-display` |

Pesos declarados: regular (400) e semibold (600). Contraste hierárquico entre page heading e body provido pelo tamanho (20px vs. 13px), não pelo peso.

Fonte: padrão observado em `Desenvolvimento.jsx` — `text-[13px]`, `text-[11px]`, `text-xl font-semibold font-display`.

---

## Color

Tokens ECF definidos em `tailwind.config.js` — seção `theme.extend.colors.ecf`.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#050507` (`ecf-bg`) | Fundo da página principal (`bg-[#050507]`) |
| Secondary (30%) | `#0b0c10` / `#0f1116` (`ecf-card`) | Sidebar, card container da lista de empresas, fundo do accordion expandido (`bg-black/30`) |
| Accent (10%) | `#ffe600` (`ecf-yellow`) | Ver lista abaixo |
| Destructive | `red-500` / `red-300` | Somente: mensagem de erro no toast, campo com erro de validação |

Accent `ecf-yellow` reservado para:
1. Ícone `Banknote` no header da página
2. Ícone `ChevronDown` quando o accordion de uma empresa está expandido (`text-ecf-yellow`)
3. Ponto indicador de item de nav ativo no sidebar (`ml-auto w-1.5 h-1.5 rounded-full bg-ecf-yellow`)
4. Borda e fundo do label do sidebar quando a rota `/administrativo/financeiro` está ativa (`bg-ecf-yellow/[0.12] border-ecf-yellow/20 text-ecf-yellow`)
5. Botão "Salvar dados" no formulário inline — `bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow`

Cor adicional — badge "Sem integração":
- `bg-amber-500/10 text-amber-300 border border-amber-500/20` — distinguível do yellow puro; indica estado ausente, não ação

Cores semânticas de tipo de serviço (ServiceBadge):
- POLO: `bg-blue-500/10 text-blue-300 border-blue-500/20`
- Assessoria: `bg-purple-500/10 text-purple-300 border-purple-500/20`
- Incubadora: `bg-emerald-500/10 text-emerald-300 border-emerald-500/20`
- Sem tipo (null): `bg-white/[0.05] text-white/40 border-white/[0.08]`

---

## Copywriting Contract

| Element | Copy |
|---------|------|
| Page title (header) | `Fechamento` |
| Page heading | `Fechamento` |
| Page subtitle | `Tipo de serviço e datas de contrato por empresa ativa.` |
| Sidebar label | `Fechamento` (substituindo `Financeiro` em AppLayout.jsx linha 51) |
| Primary CTA — accordion | `Salvar dados` (botão de submit do formulário inline) |
| Ação secundária — accordion | `Descartar alterações` (fecha o accordion sem salvar) |
| Estado processing — CTA | `Salvando dados...` (botão disabled durante submissão) |
| Empty state heading | `Nenhuma empresa ativa encontrada.` |
| Empty state body | `Cadastre uma empresa com status ativo para que ela apareça aqui.` |
| Badge "Sem integração" | `Sem integração` |
| Badge tipo POLO | `POLO` |
| Badge tipo Assessoria | `Assessoria` |
| Badge tipo Incubadora | `Incubadora` |
| Badge sem tipo | `Sem tipo` |
| Label campo tipo de serviço | `Tipo de serviço` |
| Placeholder select tipo | `Selecionar tipo...` |
| Label campo início contrato | `Início do contrato` |
| Label campo término contrato | `Término do contrato` |
| Flash success — save | `Dados de fechamento salvos com sucesso.` |
| Flash error — save | `Erro ao salvar. Verifique os campos e tente novamente.` |
| Erro validação contract_end | `A data de término deve ser igual ou posterior ao início.` |
| Estado accordion — nenhum dado | `—` (traço em campo de data não preenchida, usando `fmtDate()` de `@/lib/utils`) |

Ações destrutivas nesta fase: nenhuma. Não há delete, reset ou operação irreversível no escopo de Phase 5.

---

## Component Inventory

Componentes locais a definir em `Admin/Financeiro.jsx` (sub-componentes de arquivo único, não exportados):

### `ServiceBadge({ tipo })`
- Props: `tipo: 'polo' | 'assessoria' | 'incubadora' | null`
- Renderiza span com cor semântica por tipo (ver seção Color acima)
- Se `tipo` é null: exibe "Sem tipo" com estilo muted
- Tamanho: `text-[11px] font-semibold px-2 py-0.5 rounded-full border`

### `IntegrationBadge()`
- Sem props — renderiza badge fixo "Sem integração"
- Estilo: `bg-amber-500/10 text-amber-300 border border-amber-500/20 text-[11px] font-semibold px-2 py-0.5 rounded-full`
- Ícone opcional: `WifiOff` de lucide-react, size 10, `shrink-0`

### `FechamentoRow({ empresa, expandida, onToggle })`
- Layout: `flex items-center gap-4 px-4 py-3 cursor-pointer transition-colors`
- Estado padrão: `hover:bg-white/[0.03]`
- Estado expandido: `bg-white/[0.05]`
- Colunas (da esquerda para direita):
  1. `ChevronDown` size 14 — rotaciona 180° quando expandido, `text-ecf-yellow` quando expandido
  2. Nome da empresa — `flex-1 text-white font-semibold text-[13px] truncate`
  3. `ServiceBadge` — alinhado à direita do nome, `shrink-0`
  4. `IntegrationBadge` — visível somente se `!empresa.has_adman`, `shrink-0`
  5. Datas: `"dd/mm/aaaa – dd/mm/aaaa"` ou `"—"` se não preenchidas — `text-white/40 text-[13px] font-mono shrink-0`

### `FechamentoAccordion({ empresa, onClose })`
- Container: `px-4 py-4 bg-black/30 border-t border-white/[0.04]`
- Layout interno: grid 3 colunas em desktop (`grid grid-cols-1 sm:grid-cols-3 gap-4`), stack em mobile
- Campos:
  - **Tipo de serviço** — `<select>` nativo com classe ECF (`NativeSelect` pattern de Sugadores/Index.jsx):
    `h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 w-full`
    Opções: `""` → "Selecionar tipo...", `polo` → "POLO", `assessoria` → "Assessoria", `incubadora` → "Incubadora"
  - **Início do contrato** — `<input type="date">` com mesma classe do select acima + `appearance-none`
  - **Término do contrato** — idem
- Labels acima dos campos: `text-[11px] uppercase tracking-wider text-white/40 mb-1`
- Mensagem de erro de validação: `text-[11px] text-red-400 mt-1` (abaixo do campo com erro)
- Rodapé de ações: `flex items-center gap-2 mt-4`
  - Botão "Salvar dados": `bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[13px] h-9 px-4 rounded-lg transition-colors font-semibold`
    Estado `processing`: disabled + texto "Salvando dados..." sem spinner (consistência com Phase 1)
  - Botão "Descartar alterações": `text-white/40 hover:text-white/70 text-[13px] h-9 px-3 rounded-lg transition-colors`

### `FechamentoList({ empresas })`
- Container: `divide-y divide-white/[0.04]`
- Estado vazio: centralizado, ícone `Building2` size 24 `text-white/20`, heading + body copy do contrato acima
- Gerencia `aberta: string | null` — apenas um accordion aberto por vez (D-15)
- Ao abrir empresa A quando empresa B está aberta: fecha B, abre A (via `setAberta(id)`)

---

## Page Layout Structure

```
AppLayout title="Fechamento"
  └── <main> p-6
        └── max-w-4xl mx-auto space-y-6
              ├── [Header block]
              │     ├── flex items-center gap-2 mb-2
              │     │     ├── Banknote size=20 text-ecf-yellow
              │     │     └── h1 "Fechamento" — text-xl font-semibold font-display text-white
              │     └── p subtitle — text-[13px] text-white/40
              │
              └── [Card container]
                    └── rounded-xl border border-white/[0.08] bg-white/[0.02]
                          └── FechamentoList
                                └── divide-y divide-white/[0.04]
                                      ├── FechamentoRow empresa[0]
                                      │     └── (se expandida) FechamentoAccordion empresa[0]
                                      ├── FechamentoRow empresa[1]
                                      │     └── (se expandida) FechamentoAccordion empresa[1]
                                      └── ...
```

Padrão de container externo: `rounded-xl border border-white/[0.08] bg-white/[0.02] p-0` — sem padding interno; rows têm padding próprio (px-4 py-3). Idêntico ao padrão `DevCard` de Phase 1, mas sem cabeçalho de card (ícone/título ficam no page header).

---

## Interaction Contracts

### Accordion (D-13, D-15)
- Clicar em `FechamentoRow` → chama `onToggle(empresa.id)`
- `FechamentoList` mantém `aberta` como `useState(null)`
- `toggleEmpresa(id)`: `setAberta(prev => prev === id ? null : id)` — fechar se já aberta, abrir nova (fecha a anterior implicitamente)
- Animação: sem animação CSS explícita nesta fase — apenas render condicional `{aberta === empresa.id && <FechamentoAccordion />}`
- `ChevronDown` com `transition-transform duration-200 rotate-180` quando expandido — ícone visual suficiente

### Formulário inline (D-14)
- `useForm({ service_type: empresa.service_type ?? '', contract_start: empresa.contract_start ?? '', contract_end: empresa.contract_end ?? '' })`
- `patch(route('admin.financeiro.update', empresa.id), { preserveScroll: true, onSuccess: () => onClose() })`
- `onClose()` é chamado pelo pai via `setAberta(null)` após sucesso — fecha o accordion
- Erros de validação PHP exibidos inline abaixo de cada campo via `errors.service_type`, `errors.contract_start`, `errors.contract_end`
- Flash de sucesso/erro exibido pelo `AppLayout` (toast automático via `flash` shared prop — já implementado em `AppLayout.jsx`)

### Datas no row (estado colapsado)
- Exibir: `fmtDate(empresa.contract_start) + ' – ' + fmtDate(empresa.contract_end)`
- Se ambas nulas: exibir `—`
- Se apenas start preenchida: exibir `fmtDate(start) + ' –'`
- `fmtDate()` importado de `@/lib/utils` — retorna `'-'` para null, mas nesta UI usar `'—'` (travessão) via helper local

### Flash / Toast
- Comportamento já gerenciado por `AppLayout`: lê `flash.success` e `flash.error` do `usePage().props`
- Nenhum toast custom necessário na page — herda o sistema global
- Duração: 4500ms (conforme `AppLayout` linha 96)

---

## Responsive Behavior

- Layout da lista: sem mudança entre mobile e desktop — `FechamentoRow` usa `flex` com `truncate` no nome
- `FechamentoAccordion` grid: `grid-cols-1 sm:grid-cols-3` — stack em mobile, 3 colunas em sm+
- Page container: `max-w-4xl mx-auto` — centralizado em telas largas, full width em telas menores
- Sidebar: gerenciada por `AppLayout` — comportamento existente preservado (collapse em mobile)

---

## States Reference

| Estado | Como renderizar |
|--------|----------------|
| Lista vazia (nenhuma empresa ativa) | Empty state centralizado — ícone + heading + body (ver Copywriting) |
| Empresa sem `adman_account_id` | `IntegrationBadge` visível na row; accordion ainda abrível para editar tipo/datas |
| Empresa sem `service_type` | `ServiceBadge` exibe "Sem tipo" com estilo muted |
| Empresa sem datas | Coluna de datas exibe `—` |
| Accordion fechado | `FechamentoRow` com `ChevronDown` normal (vertical) |
| Accordion aberto | `FechamentoRow` com `bg-white/[0.05]` + `ChevronDown` rotacionado + `FechamentoAccordion` visível |
| Form submitting | Botão "Salvar dados" disabled com texto "Salvando dados..." |
| Erro de validação | Texto vermelho `text-[11px] text-red-400` abaixo do campo com erro |
| Flash success | Toast verde no canto inferior direito — gerenciado por `AppLayout` |
| Flash error | Toast vermelho no canto inferior direito — gerenciado por `AppLayout` |

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | Nenhum bloco adicional — usando componentes já presentes em `@/Components/ui/` | not required |
| Terceiros | Nenhum | not applicable |

Todos os componentes utilizados nesta fase já existem no projeto:
- `@/Components/ui/button` — Button
- `@/Components/ui/badge` — Badge (usado como referência de estilo; ServiceBadge e IntegrationBadge são spans customizados)
- `lucide-react` — Banknote, ChevronDown, Building2, WifiOff
- `@inertiajs/react` — useForm, usePage, router
- `@/lib/utils` — cn(), formatDate

Nenhum pacote novo necessário. Nenhuma vetting de registry necessária.

---

## Pre-population Sources

| Campo | Fonte | Valor usado |
|-------|-------|------------|
| Design system / tokens | `tailwind.config.js` + codebase scan | ecf-yellow #ffe600, ecf-bg #050507, ecf-card #0f1116 |
| Accordion pattern | `Desenvolvimento.jsx` (Phase 1) | EmpresaRow/EmpresaAccordion como referência direta |
| NativeSelect pattern | `Sugadores/Index.jsx` | Classes de select nativo ECF |
| Badge cores | `Sugadores/Index.jsx` STATUS_BADGE | Padrão `bg-X/15 text-X-300 border-X/30` |
| Sidebar label change | CONTEXT.md D-01 | Apenas string; rota preservada |
| Accordion one-at-a-time | CONTEXT.md D-15 | `useState(null)` com toggle exclusivo |
| Sem integração badge | CONTEXT.md D-12 | amber — amber-500/10 / amber-300 / amber-500/20 |
| Formulário fields | CONTEXT.md D-13, D-14 | service_type select + contract_start/end date inputs |
| Route PATCH | CONTEXT.md D-16 | `admin.financeiro.update` |
| Empty state copy | Claude's Discretion | Building2 icon + copy padrão ECF |
| Date format display | Claude's Discretion | `fmtDate()` existente + travessão como separador |
| ServiceBadge colors | Claude's Discretion | blue/purple/emerald para polo/assessoria/incubadora |

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
