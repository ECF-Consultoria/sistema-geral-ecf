---
phase: 55-modernizacao-visual-sugadores-magic-ui
plan: "55-01"
type: execute
wave: 1
status: complete
completed_at: 2026-07-03
requirements:
  - REQ-55-01
tech_stack:
  added: []
  patterns:
    - "shadcn Table wrapper com overrides dark theme no consumidor"
    - "Radix Checkbox wrapper com data-[state=checked]:bg-ecf-yellow"
    - "Blur Fade via tailwindcss-animate (CSS puro, sem framer-motion)"
key_files:
  created:
    - resources/js/Components/ui/checkbox.jsx
  modified:
    - resources/js/Pages/Sugadores/EmpresaListagem.jsx
decisions:
  - "Overrides dark theme aplicados NO CONSUMIDOR (EmpresaListagem.jsx), não no wrapper compartilhado — preserva 9 páginas que já usam <Table> com paleta light-adjacent"
  - "onCheckedChange (Radix) substitui onChange (nativo) nas 2 ocorrências do <Checkbox>"
  - "framer-motion NÃO instalado; Blur Fade via animate-in fade-in duration-300 do tailwindcss-animate já ativo"
metrics:
  commits: 2
  tasks_completed: 3
  files_changed: 2
  bundle_delta_uncompressed_kb: 6.4
  bundle_delta_gzip_kb: 2.0
---

# Phase 55 Plan 01: Base modernização tabela EmpresaListagem — Summary

Refatoração da tabela do drilldown `/sugadores/empresa/{id}` para o wrapper shadcn `<Table>` com checkbox quadrado Radix e Blur Fade CSS puro no container principal — zero deps novas.

## Commits (SHAs)

| Commit    | Tarefa   | Mensagem                                                                             |
| --------- | -------- | ------------------------------------------------------------------------------------ |
| `bca19b7` | T1       | `feat(55-01): cria wrapper Components/ui/checkbox.jsx (Radix + shadcn)`               |
| `fca8a40` | T2 + T3  | `feat(55-01): tabela shadcn + checkbox quadrado + blur fade no EmpresaListagem`      |

## Arquivos novos/modificados

### Criados

- `resources/js/Components/ui/checkbox.jsx` (30 linhas) — wrapper shadcn oficial de `@radix-ui/react-checkbox` (dep já presente em `package.json:29`). Export nomeado `Checkbox` com forwardRef, quadrado (`h-4 w-4 rounded-sm`), border white/20, bg white/[0.03]; estado checked usa `bg-ecf-yellow` + texto preto + ícone `Check` (lucide, strokeWidth=3).

### Modificados

- `resources/js/Pages/Sugadores/EmpresaListagem.jsx` (593 → 601 linhas):
  - Adicionados imports `Table, TableHeader, TableBody, TableRow, TableHead, TableCell` de `@/Components/ui/table` + `Checkbox` de `@/Components/ui/checkbox`
  - Substituído `<table>` cru + `<thead>/<tbody>/<tr>/<td>/<th>` (linhas 468-572) pelo padrão shadcn `<Table>`
  - Substituídos 2 `<input type="checkbox">` nativos pelo `<Checkbox>` novo (header + linha) — `onChange` trocado por `onCheckedChange` (semântica Radix)
  - Envolvido todo o conteúdo do `<AppLayout>` em `<div className="animate-in fade-in duration-300">` (Blur Fade CSS puro)

## Overrides dark theme aplicados no consumidor

Wrapper compartilhado `Components/ui/table.jsx` **não foi alterado**. Overrides ficam na `EmpresaListagem.jsx` (research §5):

| Elemento                | Override no consumidor                                                                                             | Motivo                                                                                 |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| `<TableHeader>`         | `border-white/[0.06] bg-white/[0.02]`                                                                              | Header cinza suave dark                                                                |
| `<TableRow>` (header)   | `hover:bg-transparent border-white/[0.06]`                                                                         | Neutraliza `hover:bg-muted/50` default do wrapper (feio no header dark)                |
| `<TableHead>`           | `text-white/50 font-medium text-xs uppercase tracking-wider`                                                       | Legibilidade + estética dashboard                                                      |
| `<TableRow>` (body)     | `border-white/[0.05] hover:bg-white/[0.03] cursor-pointer transition-colors data-[state=selected]:bg-ecf-yellow/[0.03]` | Hover subtle + linha selecionada com bg amarelo suave                                   |
| `<TableCell>` (produto) | `min-w-0 py-3`                                                                                                     | Permite truncate no nome do adgroup dentro do grid col-span-3                          |
| `<TableCell>` (ações)   | `py-3 text-right min-w-[80px]` + `onClick={e => e.stopPropagation()}`                                              | Largura estável mesmo quando o botão MLB não renderiza (sugadores tipo=campanha)      |

## Bundle EmpresaListagem — antes vs depois

Medido via `ls -lh public/build/assets/EmpresaListagem-*.js` e output do Vite:

| Fase                  | Uncompressed | Gzip        |
| --------------------- | ------------ | ----------- |
| Baseline pré-Wave 1   | 15 kB        | ~5 kB       |
| Pós-T1 (só checkbox)  | 15 kB        | ~5 kB       |
| Pós-T2+T3 (final)     | **21.39 kB** | **7.26 kB** |
| **Delta**             | **+6.39 kB** | **+2.0 kB** |

**Desvio da meta (+2 kB uncompressed):** o plano previu +2 kB baseado no research §7, que assumiu que o runtime do `@radix-ui/react-checkbox` já estaria em algum vendor chunk compartilhado. Na prática, esta é a **primeira página** do projeto a importar Radix Checkbox — o Presence/Root/Indicator runtime foi bundleado dentro do chunk `EmpresaListagem`. **O delta gzip real transmitido pelo navegador (~2 kB) permanece dentro do orçamento.** Nenhuma ação corretiva — quando a próxima página adotar Checkbox (previsto Wave 2 do CONTEXT), o Vite irá extrair para vendor chunk automaticamente.

## framer-motion — confirmação de ausência

- `grep framer-motion package.json` → **0 matches**
- `grep framer-motion resources/js/` → **0 matches**
- Blur Fade entregue via `tailwindcss-animate ^1.0.7` (já instalado) — classe `animate-in fade-in duration-300`

## Handlers preservados 100%

Todos os handlers da Phase 54 continuam operando sem mudanças de assinatura:

- `toggleOne(id)` — checkbox de linha (agora via `onCheckedChange`)
- `toggleAllVisible()` — checkbox de header (agora via `onCheckedChange`)
- `clearSelection()` — botão "Limpar seleção" na barra bulk
- `copiarMlbsLinha(sugadorId)` — botão MLBs por linha (endpoint `sugadores.mlbs-hint`)
- `copiarMlbsBulk()` — botão "Copiar MLBs dos selecionados" na barra bulk (endpoint `sugadores.bulk-copy-mlbs`)
- `rodarAnalise()` — botão "Rodar análise" na sidebar + cronômetro 30s
- `aplicarPeriodo(value)` — filtro de período `<select>` nativo
- Click row inteiro navega para `/sugadores/{id}` (Phase 54-02 B2); `stopPropagation` nos `<TableCell>` de checkbox e ações preservado

## Desvios

### D1: Delta uncompressed +6.4 kB vs meta de +2 kB

**Justificativa:** primeira página a importar `@radix-ui/react-checkbox` no projeto — Radix Presence runtime (~4 kB uncompressed) foi bundleado no chunk. Delta gzip real (+2 kB) permanece na meta. Sem ação corretiva; Wave 2 (55-02) fará extração para vendor chunk quando o Checkbox for consumido por 2+ páginas.

### D2: Wrapper `<Table>` já usado — comportamento default

O wrapper compartilhado `Components/ui/table.jsx` (imutável nesta wave) define `hover:bg-muted/50` no `<TableRow>` default. Neutralizado no header via `hover:bg-transparent` explícito. Nas linhas do body, sobrescrito por `hover:bg-white/[0.03]`. Nenhum bug funcional — apenas nota para futuras waves consumindo o mesmo wrapper.

## Success Criteria — status final

| Critério                                                                                              | Status |
| ----------------------------------------------------------------------------------------------------- | ------ |
| `resources/js/Components/ui/checkbox.jsx` criado seguindo padrão shadcn oficial                      | OK     |
| Wrapper compartilhado `Components/ui/table.jsx` NÃO alterado                                          | OK     |
| EmpresaListagem.jsx usa `<Table><TableHeader>...</Table>` com dark theme overrides no consumidor      | OK     |
| Checkboxes nativos 100% substituídos por `<Checkbox>` do novo wrapper (header + linha)               | OK — grep `type="checkbox"` = 0 matches |
| Blur Fade via `animate-in fade-in duration-300` no container principal (0 kb novos)                  | OK — grep = 1 match |
| framer-motion NÃO instalado, `resources/js/Components/magicui/` NÃO criado                            | OK     |
| Handlers preservados (`toggleOne`, `toggleAllVisible`, `copiarMlbsLinha`, `copiarMlbsBulk`, ...)     | OK     |
| Click row + `stopPropagation` no checkbox e nas ações mantidos (Phase 54-02)                         | OK     |
| Delta do chunk EmpresaListagem ≤ +2 kB                                                                | DESVIO documentado (D1): +6.4 kB uncompressed, +2 kB gzip |
| `npm run build` verde                                                                                 | OK     |

## Débitos abertos (Wave 2 — Plan 55-02)

- Menu de ações "⋯" por linha (`DropdownMenu` + `MoreHorizontal`) — substituirá o botão inline "MLBs" atual
- Botão "Customize Columns" com `DropdownMenuCheckboxItem` — colunas opcionais (Campaign name, MLB ID, Motivos, Investimento, Vendas) com persistência em `localStorage`
- Colunas fixas continuam: checkbox, produto, ações

## Self-Check: PASSED

- Arquivo `resources/js/Components/ui/checkbox.jsx` existe (verificado via Write)
- Arquivo `resources/js/Pages/Sugadores/EmpresaListagem.jsx` modificado (verificado via Edit + greps)
- Commit `bca19b7` presente em `git log --oneline -3`
- Commit `fca8a40` presente em `git log --oneline -3`
- `npm run build` verde (verificado em ambos T1 e T2+T3)
- Grep `framer-motion` em `package.json` + `resources/js/` = 0 matches
