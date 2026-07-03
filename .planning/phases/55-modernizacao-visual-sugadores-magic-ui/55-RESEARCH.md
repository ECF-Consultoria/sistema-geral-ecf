# Phase 55: Modernização visual /sugadores + Magic UI — Research

**Researched:** 2026-07-03
**Domain:** Frontend React/Inertia (visual polish)
**Confidence:** HIGH nos pontos 1, 2, 4, 5, 6, 7; MEDIUM no ponto 3 (Magic UI — docs oficiais dizem "depende de framer-motion" mas source completo não estava acessível via WebFetch)

## Sumário

C1 (redesign tabela) é **puro reuso** — o wrapper shadcn `Components/ui/table.jsx` já existe (`resources/js/Components/ui/table.jsx:1-46`) e 9 páginas do projeto já o usam. **Zero código novo de dep, zero kb novo no bundle.** Falta apenas criar o wrapper `checkbox.jsx` (Radix já instalado, ~15 linhas). Column visibility precisa ser implementado do zero — ninguém no projeto faz isso hoje. C2 (Magic UI) exige framer-motion como dep de peso (~50kb gzipped) — recomendo **cortar Magic UI do escopo desta phase** ou usar apenas Blur Fade via CSS puro `tailwindcss-animate` (já instalado). Drag handles: **excluir** — sugadores não têm ordem manual persistida.

**Recomendação primária:** executar C1 completo + adicionar Blur Fade via keyframe CSS (0 kb extra). Cortar Number Ticker, Ripple, Shimmer Button e drag handles.

---

## User Constraints (do CONTEXT.md)

### Locked Decisions
- **C1**: substituir grid custom em `EmpresaListagem.jsx` por `<Table>` shadcn, adaptado dark theme com tokens `ecf-*`
- **C1**: checkbox quadrado shadcn (Radix) no lugar do nativo
- **C1**: DropdownMenu para "Customize Columns" (persistir em localStorage) e menu de ações "⋯" por linha
- **C2**: só componentes Magic UI que "agregam" (locked open — decisão fica no research)
- **NÃO usar**: Marquee, Bento Grid, Confetti
- **Framer-motion** só se 2+ componentes Magic UI usarem
- **Magic UI copy-paste** em `resources/js/Components/magicui/` (não instalar como pacote NPM)

### Claude's Discretion
- Criar wrapper `Components/ui/checkbox.jsx` se ausente
- Drag handles IN/OUT
- Persistência column visibility: localStorage vs URL query string
- Paleta dark exata do header

### Deferido (fora do escopo)
- Redesign Show.jsx, Index.jsx (só EmpresaListagem)
- Drag entre empresas, tabs superiores estilo shadcn Outline, "+ Add Section" literal
- Column resizing, modo compacto, confetti

---

## 1. shadcn Table já no projeto — quem usa hoje

**Wrapper existe:** `resources/js/Components/ui/table.jsx` (46 linhas, exporta `Table`, `TableHeader`, `TableBody`, `TableFooter`, `TableRow`, `TableHead`, `TableCell`, `TableCaption`).

**9 páginas já usam** (grep `TableHeader|TableBody` em `resources/js/Pages/`):
- `Comercial/EmpresasListagem.jsx:8,562-624`
- `Companies/Index.jsx`
- `Sistema/HubspotLineItems.jsx`
- `Nps/Index.jsx`, `Nps/EmailsEnviados.jsx`
- `Users/Index.jsx:154-225` ← **referência canônica limpa**
- `Servicos/Index.jsx`
- `Meetings/Index.jsx`
- `Ppa/Index.jsx`

**Padrão canônico para copiar** — `resources/js/Pages/Users/Index.jsx:152-227`:
```jsx
<Card>
    <CardContent className="p-0">
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Nome</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {items.map(u => (
                    <TableRow key={u.id}>
                        <TableCell className="font-medium">{u.name}</TableCell>
                        <TableCell className="text-right">…</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    </CardContent>
</Card>
```

**Checkbox shadcn wrapper — NÃO EXISTE.** `resources/js/Components/ui/` só tem: avatar, badge, button, card, dialog, dropdown-menu, input, label, progress, select, separator, table, tabs, textarea. Precisa criar. Dep `@radix-ui/react-checkbox ^1.3.3` já em `package.json:29`.

**Wrapper mínimo a criar** (`resources/js/Components/ui/checkbox.jsx`, ~15 linhas — padrão shadcn oficial):
```jsx
import * as React from 'react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const Checkbox = React.forwardRef(({ className, ...props }, ref) => (
    <CheckboxPrimitive.Root
        ref={ref}
        className={cn(
            'peer h-4 w-4 shrink-0 rounded-sm border border-white/20 bg-white/[0.03]',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'data-[state=checked]:bg-ecf-yellow data-[state=checked]:text-black data-[state=checked]:border-ecf-yellow',
            className
        )}
        {...props}
    >
        <CheckboxPrimitive.Indicator className="flex items-center justify-center">
            <Check className="h-3 w-3" strokeWidth={3} />
        </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
));
Checkbox.displayName = 'Checkbox';
export { Checkbox };
```

---

## 2. Column visibility — pattern existente no projeto

**Grep `columnsVisibility|columnVisibility|visibleColumns|localStorage.setItem.*column` em `resources/js/`: ZERO matches.** Ninguém implementou ainda. Precisa fazer do zero.

**Estrutura mínima recomendada** (~30 linhas, colocar no topo de `EmpresaListagem.jsx`):
```jsx
const STORAGE_KEY = 'sugadores:col-visibility:v1';
const DEFAULT_VIS = {
    campaign: false, mlb_id: false, motivos: false,
    investimento: false, vendas: false,
    // fixas (não aparecem no toggle): checkbox, produto, status, detectado_em, actions
};
const OPTIONAL_COLUMNS = [
    { key: 'campaign',    label: 'Campaign name' },
    { key: 'mlb_id',      label: 'MLB ID' },
    { key: 'motivos',     label: 'Motivos' },
    { key: 'investimento',label: 'Investimento' },
    { key: 'vendas',      label: 'Vendas' },
];

const [colVis, setColVis] = useState(() => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? { ...DEFAULT_VIS, ...JSON.parse(raw) } : DEFAULT_VIS;
    } catch { return DEFAULT_VIS; }
});
useEffect(() => {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(colVis)); } catch {}
}, [colVis]);

// Dropdown de toggle usa Components/ui/dropdown-menu.jsx (DropdownMenuCheckboxItem)
```

Versionamento da chave (`:v1`) permite invalidar preferência antiga em phases futuras sem quebrar.

---

## 3. Magic UI — quais componentes valem o custo

**Dep de peso:** todos os componentes Magic UI mencionados dependem de `framer-motion` (rebranded para `motion`) — [magicui.design/blog/framer-motion-react](https://magicui.design/blog/framer-motion-react) confirma "Magic UI is built with … Framer Motion". `framer-motion` gzipped ≈ 50-60kb. **Não estimei bytes exatos de cada componente Magic UI** — o repo `magicuidesign/magicui` no GitHub reorganizou paths de registry e os URLs raw retornaram 404 no fetch; documentação pública mostra só uso, não source.

**Levantamento (docs oficiais + WebSearch):**

| Componente | Dep framer-motion | Tamanho estimado do componente | Aplicação em /sugadores |
|---|---|---|---|
| **Number Ticker** | SIM | pequeno (~40 linhas) | contador "N pendentes" no header — nice, não crítico |
| **Blur Fade** | SIM | pequeno (~50 linhas) | fade do container ao entrar — pode ser feito com CSS puro |
| **Ripple** | NÃO confirmado (docs sugerem SIM) | pequeno | efeito em botão primário — cosmético |
| **Shimmer Button** | possivelmente CSS puro | pequeno | botão "Rodar análise" — cosmético |
| **Animated List** | SIM | médio | não se aplica (tabela, não lista sequencial) |

**Custo real da adoção:**
- Instalar `framer-motion` para 1 componente = +50kb gzipped só pra contador animado. **Ruim custo/benefício.**
- Se 2+ componentes → dilui, mas ainda +50kb.

**Recomendação:**
1. **Cortar Number Ticker** — contador estático já resolve; usuário pediu "acertividade + praticidade" no `feedback_project_priorities.md`.
2. **Substituir Blur Fade por CSS puro** com `tailwindcss-animate ^1.0.7` (já em `package.json:24`) — usar `animate-in fade-in duration-300` no container. Zero kb novos.
3. **Cortar Ripple / Shimmer Button** — cosmético, não move ponteiro.
4. **NÃO instalar framer-motion** nesta phase. Se operador quiser animações mais elaboradas depois, avaliar em phase separada com escopo próprio.

**Alternativa CSS puro para "fade agradável"** — já suportado pelo Tailwind config atual:
```jsx
<div className="animate-in fade-in slide-in-from-bottom-1 duration-300">
    {/* container EmpresaListagem */}
</div>
```

---

## 4. Ícones lucide-react para actions menu

**Grep `MoreHorizontal|MoreVertical` em `resources/js/`: ZERO matches.** Ninguém usa esse padrão no projeto ainda.

**Conjunto sugerido para dropdown de ações por linha (todos em `lucide-react ^1.11.0`, já instalado):**
- `MoreHorizontal` — trigger do menu (o botão "⋯")
- `Copy` — Copiar MLBs (já usado em `EmpresaListagem.jsx:5`)
- `ExternalLink` — Ver detalhes (abre Show.jsx)
- `PlayCircle` — Marcar em ação (já usado em `EmpresaListagem.jsx:6`)
- `CheckCircle2` — Marcar resolvido
- `EyeOff` — Ignorar
- `Trash2` — só se houver ação de exclusão (não previsto no CONTEXT)

Ícones já importados no arquivo alvo (`EmpresaListagem.jsx:4-7`) que dá pra reusar: `Copy`, `Check`, `PlayCircle`, `AlertTriangle`.

---

## 5. Dark theme — overrides sobre shadcn Table default

**Análise do `Components/ui/table.jsx`:** o wrapper usa classes light-theme padrão do shadcn (`text-muted-foreground`, `bg-muted/50`, `hover:bg-muted/50`, `border-b`). Não há branch dark explícito no arquivo — as classes `muted` vêm do CSS globals do projeto.

**Overrides mínimos por elemento** (aplicar via `className` no consumidor, ficando no `EmpresaListagem.jsx`):

| Elemento | Override sugerido | Motivo |
|---|---|---|
| `<TableHeader>` | `className="border-white/[0.06] bg-white/[0.02]"` | Header cinza suave dark |
| `<TableHead>` | `className="text-white/50 font-medium text-xs uppercase tracking-wider"` | Legibilidade + estética dashboard |
| `<TableRow>` (body) | `className="border-white/[0.05] hover:bg-white/[0.03] transition-colors"` | Hover subtle dark |
| `<TableRow data-state="selected">` | `className="data-[state=selected]:bg-ecf-yellow/[0.03]"` | Linhas selecionadas (bulk mode) |
| `<TableCell>` | `className="text-white/85"` (texto default), variantes por coluna | Contraste dark |

**Nota:** o wrapper existente **não** precisa ser alterado — todos os overrides vão no consumidor `EmpresaListagem.jsx`. Isso preserva o wrapper para outras páginas que já o usam com paleta light-adjacente (Users, Companies etc, que herdam via Card/AppLayout).

---

## 6. Drag handles — decisão OUT

**Grep `@dnd-kit|react-beautiful-dnd|framer-motion` em `package.json`: ZERO matches.** Nenhuma lib de drag instalada. Adotar `@dnd-kit/core` + `@dnd-kit/sortable` = ~30kb novos + refactor de estrutura de row.

**Domínio checa:** linhas de sugadores são ordenadas por `detectado_at DESC` (uma métrica de detecção do backend, não uma preferência do usuário). Não existe caso de uso onde o operador precisa dizer "este sugador é mais urgente que aquele" arrastando linha — a priorização já vem do algoritmo de detecção + filtros (status, motivos).

**Recomendação: EXCLUIR drag handles do escopo desta phase.** Não instalar `@dnd-kit`. Se aparecer necessidade de priorização manual persistida em UAT futura, abrir phase separada com endpoint backend próprio pra guardar a ordem.

---

## 7. Bundle size — baseline e projeção

**Baseline atual** (`du -sh public/build/assets/`):
- Total dist: **3.5 MB**
- `app-CgsmgGxu.js` (root bundle): **354 kB**
- `AppLayout-eY8H-MqR.js` (shell): **119 kB**
- `EmpresaListagem-DRDcvtZc.js` (chunk alvo): **15 kB**
- `app-Dr0tW6cV.css`: **116 kB**

**Projeção de impacto pós-Phase 55:**

| Mudança | Impacto |
|---|---|
| Refactor grid → shadcn Table | **0 kB** — wrapper já no bundle (9 páginas usam) |
| Wrapper `checkbox.jsx` | **~200 B** — Radix Checkbox já em deps (roots Radix chunks) |
| Column visibility state + DropdownMenu toggle | **~1 kB** JS extra na chunk EmpresaListagem |
| MoreHorizontal + ícones novos lucide | **~500 B** (tree-shaken, só o SVG usado) |
| Blur Fade via `tailwindcss-animate` (recomendação #3) | **0 kB** — plugin já ativo, só classes CSS |
| Magic UI copy-paste (SE aceitar #3) | **0 kB** |
| framer-motion (SE aceitar #3) | **NÃO INSTALAR** |

**Cenário recomendado (execução do research):** **+2 kB no chunk `EmpresaListagem`**, zero mudança em `app.js`. Bundle total praticamente inalterado.

**Cenário worst-case (se ignorar recomendação e instalar framer-motion + 3 componentes Magic UI):** +50-70 kB no vendor chunk. Aceitável mas evitável.

---

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|---|---|---|
| A1 | framer-motion gzipped ≈ 50-60kb | §3 | Se maior, custo pior — mas WebSearch confirma faixa; independente disso, recomendação é NÃO instalar |
| A2 | Ripple/Shimmer Button podem ser CSS puro | §3 | Se dependerem de framer-motion, cai no mesmo veto; sem impacto na recomendação |
| A3 | Estimativa de +2 kB no chunk EmpresaListagem | §7 | Só medível após `npm run build`; delta será pequeno de qualquer forma pois DropdownMenu + Radix Checkbox já bundleados |

---

## Fontes

**Primárias (HIGH):**
- Código do projeto verificado via Grep/Read: `resources/js/Components/ui/table.jsx`, `resources/js/Pages/Users/Index.jsx:154-225`, `resources/js/Pages/Sugadores/EmpresaListagem.jsx:1-50`, `package.json`
- `du -sh public/build/assets/` + `ls -lh` para baseline bundle

**Secundárias (MEDIUM):**
- [Magic UI docs — Blur Fade](https://magicui.design/docs/components/blur-fade) — confirmou dependência de motion library (uso do prop `variant` de motion)
- [Magic UI docs — Number Ticker](https://magicui.design/docs/components/number-ticker) — não expôs source diretamente
- [Magic UI blog — Framer Motion React](https://magicui.design/blog/framer-motion-react) — "Magic UI is built with … Framer Motion"

**Não confirmadas / gaps:**
- Bytes exatos de cada componente Magic UI (repo GitHub retornou 404 nos raw URLs testados) — recomendação segue válida pois o gate é framer-motion, não bytes do componente
- Não medi bundle pós-refactor (só depois de `npm run build` real)

---

*Phase 55 — Research lean concluída 2026-07-03. Próximo passo: planner consome este documento + CONTEXT.md.*
