---
phase: 1
slug: diagn-stico-adman
status: draft
shadcn_initialized: false
preset: none
created: 2026-05-18
---

# Phase 1 — UI Design Contract: Diagnóstico Adman

> Visual and interaction contract para a seção de Diagnóstico Adman na página /dev/desenvolvimento.
> Gerado por gsd-ui-researcher. Verificado por gsd-ui-checker.

---

## Design System

| Property | Value | Source |
|----------|-------|--------|
| Tool | none (padrão ECF artesanal) | CONVENTIONS.md |
| Preset | not applicable | — |
| Component library | Radix UI (headless, via shadcn-style wrappers em `@/Components/ui/`) | STACK.md |
| Icon library | lucide-react ^1.11.0 | STACK.md |
| Font (sans) | Inter | tailwind.config.js |
| Font (display) | Manrope | tailwind.config.js |
| Class utility | `cn()` de `@/lib/utils` (clsx + tailwind-merge) — obrigatório em todo componente | CONVENTIONS.md |
| Comment language | pt-BR em todos os arquivos | CONVENTIONS.md |

Notas:
- Não há `components.json` (shadcn CLI não inicializado). Os componentes Radix já existem em `@/Components/ui/` e são usados diretamente.
- A seção nova é inline em `Desenvolvimento.jsx` — reutilizar `DevCard` e padrões visuais já presentes na página.

---

## Spacing Scale

Multiples-of-4 scale. Usa classes Tailwind nativas; sem tokens customizados além dos abaixo.

| Token | Value | Tailwind class | Usage nesta fase |
|-------|-------|----------------|-----------------|
| xs | 4px | `gap-1`, `p-1` | Gap interno de ícone/badge |
| sm | 8px | `gap-2`, `p-2` | Gap entre elementos de linha compacta |
| sm+ | 12px | `py-3` | Padding vertical do painel accordion (`px-4 py-3`) — valor herdado do padrão `LinkRow` existente |
| md | 16px | `gap-4`, `p-4` | Espaçamento padrão entre colunas da linha de empresa |
| md+ | 20px | `p-5` | Padding interno do DevCard — valor herdado do componente `DevCard` existente |
| lg | 24px | `gap-6` | Separação entre sub-seções internas |
| xl | 32px | `gap-8` | Separação entre a nova seção e a seção anterior |
| 2xl | 48px | `space-y-12` | Não necessário nesta fase |
| 3xl | 64px | — | Não necessário nesta fase |

Notas sobre valores herdados:
- `sm+` (12px) e `md+` (20px) são múltiplos de 4 válidos, incorporados à escala desta fase porque já existem nos componentes reutilizados (`DevCard` e `LinkRow`). Não alterar esses valores para não quebrar consistência visual com o restante da página.
- Touch targets do botão "Disparar sync": mínimo 36px de altura (`h-9` = 36px via Button size="sm"), aceitável para contexto desktop-only (área admin).

---

## Typography

Herda as fontes globais definidas em `tailwind.config.js`. Quatro papéis usados nesta fase:

| Role | Size | Tailwind | Weight | Line Height | Usage |
|------|------|----------|--------|-------------|-------|
| Body | 13px | `text-[13px]` | 400 (regular) | 1.5 | Descrições, labels de campo, texto corrido |
| Label | 11px | `text-[11px]` | 400 (regular) | 1.4 | Rótulos de coluna uppercase, metadados secundários |
| Heading (card) | 15px | `text-[15px]` | 600 (semibold) | 1.25 | Título do DevCard ("Sync Adman") |
| Code/mono | 12px | `text-[12px] font-mono` | 400 (regular) | 1.6 | Payload JSON bruto, timestamps absolutos |

Regras:
- Font-weights declarados nesta fase: **400** (regular) e **600** (semibold). Nenhum outro peso é permitido.
  - 400: todo texto corrido, labels, código mono.
  - 600: headings de card (`DevCard` title) E nome da empresa na linha (`EmpresaRow`) — `font-semibold` para legibilidade do item principal da lista contra fundo escuro. Nunca bold (700).
- Uppercase reservado para rótulos de coluna e labels de metadados (`uppercase tracking-wider text-[11px] text-white/40`).
- Monospace reservado exclusivamente para: payload JSON, timestamps absolutos, valores numéricos de diff.

---

## Color

Dark theme ECF. Todos os valores abaixo estão definidos em `tailwind.config.js` sob `theme.extend.colors.ecf`.

| Role | Token / Hex | Tailwind | Usage |
|------|-------------|----------|-------|
| Dominant (60%) | `#050507` / `ecf-bg` | `bg-ecf-bg` | Fundo da página (herdado do layout) |
| Secondary (30%) | `#0f1116` / `ecf-card` | `bg-ecf-card` ou `bg-white/[0.02]` | Surface do DevCard, linhas de empresa, painel accordion |
| Accent (10%) | `#ffe600` / `ecf-yellow` | `text-ecf-yellow`, `bg-ecf-yellow/10` | Ver lista abaixo |
| Destructive | `red-500` / `#ef4444` | `text-red-400`, `bg-red-500/10` | Status "Erro" no badge, mensagem de erro no accordion |
| Success | `emerald-400` / `#34d399` | `text-emerald-400` | Status "OK" no badge |
| Muted text | `#9ba0aa` / `ecf-dim` | `text-white/40`, `text-white/60` | Textos secundários, timestamps, labels |

Accent (`ecf-yellow`) reservado EXCLUSIVAMENTE para:
1. Ícone dentro do `DevCard` header (fundo `bg-ecf-yellow/[0.12]`, borda `border-ecf-yellow/20`, ícone `text-ecf-yellow`) — padrão herdado
2. Botão "Disparar sync" no estado idle: `bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow` (padrão do `LinkRow` existente)
3. Seta/chevron do accordion no estado expandido: `text-ecf-yellow`
4. NÃO usar em: bordas de card, badges de status, texto corrido, separadores

Bordas: `border-white/[0.08]` (padrão DevCard). Linhas internas de lista: `border-white/[0.04]`.
Hover de linha: `hover:bg-white/[0.03]` — sutil, sem destaque de cor.
Accordion expandido: `bg-black/30 border border-white/[0.04]` — padrão do `LinkRow`.

---

## Component Inventory

Componentes reutilizados desta fase (já existem no codebase):

| Componente | Origem | Uso nesta fase |
|-----------|--------|----------------|
| `DevCard` | `Desenvolvimento.jsx` (local) | Container da seção "Sync Adman" — mesmo ícone/título/subtitle pattern |
| `Button` | `@/Components/ui/button.jsx` | Botão "Disparar sync" (variant="ghost", size="sm") |
| `Badge` | `@/Components/ui/badge.jsx` | Status OK (variant="success") / Erro (variant="destructive") |
| `cn()` | `@/lib/utils` | Composição de classes em todo componente novo |

Componentes novos a criar (inline em `Desenvolvimento.jsx` ou em arquivo separado conforme volume):

| Componente | Responsabilidade |
|-----------|-----------------|
| `SyncAdmanSection` | Seção inteira: lista de empresas + estado de loading/empty/error |
| `EmpresaRow` | Linha de uma empresa: nome, timestamp, badge status, botão disparar |
| `EmpresaAccordion` | Painel expansível: diff (criados/atualizados/ignorados) + payload JSON |
| `DiffBadge` | Badge numérico colorido: criados (verde), atualizados (azul), ignorados (cinza) |
| `JsonViewer` | Bloco `<pre>` scrollável para exibir payload bruto formatado |

Regra: se o componente for usado apenas em `Desenvolvimento.jsx`, definí-lo no mesmo arquivo (padrão `StatusBadge` em `Sugadores/Index.jsx`). Se crescer para >120 linhas, mover para arquivo próprio.

---

## Interaction Contract

### Accordion de empresa

- **Trigger:** Clique em qualquer parte da linha `EmpresaRow` (exceto no botão "Disparar sync")
- **Comportamento:** Expande um único accordion por vez (fechar o anterior ao abrir novo — controlled state com `useState`)
- **Animação:** `animation-accordion-down` / `animation-accordion-up` (keyframes já definidos em `tailwind.config.js`, 0.2s ease-out)
- **Chevron:** Rotaciona 180° quando expandido (`transition-transform duration-200`, `rotate-180`)
- **Estado expandido:** A linha permanece com `bg-white/[0.05]` para indicar seleção ativa

### Botão "Disparar sync"

- **Estado idle:** `bg-ecf-yellow/10 hover:bg-ecf-yellow/20 text-ecf-yellow text-[12px]` com ícone `RefreshCw` (lucide)
- **Estado loading:** Ícone gira (`animate-spin`), texto "Disparando...", botão `disabled` com `opacity-50`
- **Após sucesso:** Toast de confirmação ("Sync enfileirado para [Empresa]") — usando flash message do Inertia; botão volta ao idle
- **Após erro:** Toast de erro ("Falha ao enfileirar sync — tente novamente"); botão volta ao idle
- **Escopo:** Clique não propaga para o accordion (stopPropagation)
- **Feedback:** Inertia `router.post()` com `onStart`/`onFinish` para controlar estado de loading local

### Timestamp

- **Formato:** Data/hora absoluta no formato `dd/MM HH:mm` (ex: "15/05 14:37") — mais confiável para debug que formato relativo
- **Tooltip:** Ao hover, exibir timestamp completo ISO com `title` nativo do HTML (ex: "2026-05-15T14:37:22")
- **Sem sync ainda:** exibir "Nunca sincronizado" em `text-white/30 italic`

### Nome da empresa na EmpresaRow

- **Peso:** `font-semibold` (600) para legibilidade do item principal da lista contra fundo escuro

### Payload JSON

- **Exibição padrão (no accordion):** Campos-chave resumidos em grade: `grossBilling`, `netBilling`, `TACOS`, `soldQty`, `profitMargin`, `investment`
- **JSON bruto:** Bloco `<pre>` com `overflow-x-auto max-h-64` abaixo dos campos resumidos, exibido por padrão (não requer click adicional)
- **Erro HTTP:** Exibir código + mensagem em `text-red-400` no lugar do payload

### Estados da seção inteira

- **Loading inicial:** Skeleton de 3 linhas (`animate-pulse bg-white/[0.05] rounded h-10`)
- **Empty (nenhuma empresa com adman_account_id):** Ícone `AlertTriangle` + "Nenhuma empresa com conta Adman configurada."
- **Erro de carregamento:** Ícone `AlertTriangle` + "Erro ao carregar dados de sync. Recarregue a página."

---

## Copywriting Contract

Todos os textos em pt-BR.

| Elemento | Copy |
|---------|------|
| Título do DevCard | "Sync Adman" |
| Subtitle do DevCard | "Status e controle do sync de dados por empresa" |
| Cabeçalho de coluna — empresa | "EMPRESA" |
| Cabeçalho de coluna — último sync | "ÚLTIMO SYNC" |
| Cabeçalho de coluna — status | "STATUS" |
| CTA primário (botão por empresa) | "Disparar sync" |
| CTA — estado loading | "Disparando..." |
| Badge status OK | "OK" |
| Badge status Erro | "Erro" |
| Timestamp ausente | "Nunca sincronizado" |
| Diff — criados | "criados" |
| Diff — atualizados | "atualizados" |
| Diff — ignorados | "ignorados" |
| Seção payload | "Payload bruto" |
| Seção diff | "Resultado do último sync" |
| Empty state (sem empresas) | "Nenhuma empresa com conta Adman configurada." |
| Empty state (sub) | "Adicione o campo `adman_account_id` a uma empresa para monitorar o sync." |
| Erro de carregamento | "Não foi possível carregar os dados de sync. Recarregue a página." |
| Toast sucesso disparo | "Sync enfileirado para [Nome da Empresa]." |
| Toast erro disparo | "Falha ao enfileirar o sync. Tente novamente." |

Ações destrutivas nesta fase: nenhuma. O botão "Disparar sync" é uma ação de escrita reversível (enfileira job) — não requer confirmação.

---

## Layout

A seção é inserida em `Desenvolvimento.jsx` **abaixo** do `DevCard` da extensão Chrome e **acima** do placeholder dashed (`FileText`).

```
max-w-4xl mx-auto space-y-6
  ├── Header (título Desenvolvimento)
  ├── DevCard — Extensão Chrome    [existente]
  ├── DevCard — Sync Adman         [NOVO — esta fase]
  │   └── SyncAdmanSection
  │       ├── EmpresaRow (empresa A)
  │       │   └── EmpresaAccordion (expandido)
  │       │       ├── DiffBadges (criados / atualizados / ignorados)
  │       │       └── JsonViewer (payload bruto)
  │       ├── EmpresaRow (empresa B)
  │       └── EmpresaRow (empresa C)
  └── Placeholder dashed           [existente]
```

A lista de empresas dentro do `DevCard` não tem padding extra além do `p-5` herdado. As linhas de empresa usam `divide-y divide-white/[0.04]` para separação leve sem bordas individuais.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | nenhum (CLI não inicializado) | not required |
| third-party | nenhum | not required |

Todos os componentes são escritos manualmente ou reutilizados do codebase existente.

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
