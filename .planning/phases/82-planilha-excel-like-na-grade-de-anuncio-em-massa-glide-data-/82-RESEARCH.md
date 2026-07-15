# Phase 82: Planilha Excel-like na grade de anúncio em massa (glide-data-grid) - Research

**Pesquisado em:** 2026-07-15
**Domínio:** Canvas data grid em React (glide-data-grid v6.0.3) substituindo uma `<table>` HTML
**Confiança:** ALTA para API/instalação (fonte primária = código-fonte do repo oficial no GitHub); MÉDIA para o mapeamento de tema/cores (decisão de design, não fato verificável); MÉDIA para estimativa de bundle size (não verificado via bundlephobia).

## Project Constraints (from CLAUDE.md)

- Stack travada: Laravel 12 + Inertia.js + React — nenhuma mudança de stack. `glide-data-grid` roda inteiramente no lado React, sem exigir rota/endpoint novo.
- Design: usar tokens `ecf-*` (dark theme), `cn()` e convenções do `DevCard` já existentes — como o canvas não é alcançado por classes Tailwind, o mapeamento é feito via objeto `theme` do glide-data-grid (ver §Standard Stack e §Code Examples).
- Comentários em pt-BR (o código atual de `AnunciarMassa.jsx` já segue isso à risca — manter o padrão de blocos `// ═══...═══` e `// ─── ... ───`).
- `npm run build` é gate obrigatório após qualquer mudança de frontend.
- Deploy só com autorização explícita — não se aplica a este research (frontend puro, sem deploy).
- Convenção de sub-componentes: "Local sub-components defined in the same file as the page if used only within that page" — hoje `Grade`/`LinhaGrade`/`Cell`/`CellInput`/`Th` já vivem dentro de `AnunciarMassa.jsx`. Ver recomendação de migração em §Estratégia de Migração.

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| SHEET2-01 | Seleção de múltiplas células (range), incluindo múltiplos retângulos | `rangeSelect="multi-rect"` + `gridSelection`/`onGridSelectionChange` controlados (§Standard Stack, §Code Examples) |
| SHEET2-02 | Fill handle: arrastar a alça replica valores vertical E horizontalmente | `fillHandle={true}` + `allowedFillDirections="any"` + `onFillPattern` opcional para customizar (§Code Examples) |
| SHEET2-03 | Copiar/colar entre células e colar direto do Excel/Sheets | `getCellsForSelection` (copy) + `onPaste` + `coercePasteValue` + `onCellsEdited` (paste em lote) (§Modelo de Dados, §Code Examples) |
| SHEET2-04 | Navegação por teclado: Tab, Enter, setas | Nativo do `DataEditor` (nenhuma configuração extra) — ver §Common Pitfalls sobre o que NÃO vem de graça |
| SHEET2-05 | Seleção de linhas e colunas | `rowSelect="multi"` + `columnSelect="multi"` + `rowMarkers="clickable-number"` (§Standard Stack) |
| SHEET2-06 | Campos com valores pré-definidos mantêm aparência de planilha, dropdown só ao editar | `DropdownCell` de `@glideapps/glide-data-grid-cells` via `customRenderers` (§Code Examples — **corrige um erro de fonte não-oficial**, ver §Assumptions Log) |
| SHEET2-07 | Zero regressão nas validações e ciclo de vida atuais | Backend não muda (rotas já testadas em `tests/Feature/Phase82/GradeMassaTest.php`); `errosLocaisLinha`/`linhaPublicavel`/`montarPayloadLinha` continuam sendo lógica pura reaproveitável (§Estratégia de Migração) |
| SHEET2-08 | Tema do canvas mapeando tokens `ecf-*` | Objeto `theme` do `DataEditor` — chaves reais listadas e mapeadas em §Standard Stack |

</phase_requirements>

## Summary

`glide-data-grid@6.0.3` é uma lib MIT, canvas-based, madura (primeiro publish em 2020, última versão em jun/2026), com API 100% controlada por callbacks (`getCellContent`, `onCellsEdited`, `onPaste`, `getCellsForSelection`) — não existe um "array de linhas com inputs" como hoje: a grade lê célula-a-célula sob demanda e escreve de volta em lote. Cobre nativamente TODOS os 8 requisitos da fase (`rangeSelect: multi-rect`, `fillHandle`+`allowedFillDirections`, `getCellsForSelection`/`onPaste`/`coercePasteValue`, `rowSelect`/`columnSelect`/`rowMarkers`, `theme` por objeto JS), e o dropdown de valores pré-definidos vem de um pacote satélite oficial do mesmo monorepo, `@glideapps/glide-data-grid-cells`, via `DropdownCell`.

O maior risco confirmado (não hipotético — verificado lendo o código-fonte) é o **portal DOM obrigatório**: a lib faz `document.getElementById("portal")` toda vez que abre um editor de célula (overlay) e, se não encontrar, loga erro e a edição simplesmente não abre. Isso precisa de um `<div id="portal"></div>` como último filho do `<body>` em `resources/views/app.blade.php` — like this app é Inertia com um único layout Blade compartilhado por todas as páginas, essa é uma mudança de 1 linha, uma única vez, e não interfere em nenhuma outra página.

O segundo achado que mais muda o plano: as affordances visuais atuais que são **por célula** (bolinha de origem violeta/âmbar, contador de caracteres do título, botão "gerar" do GTIN) NÃO têm equivalente direto em `getRowThemeOverride` (que é por LINHA, não por célula). Pintar a linha inteira de vermelho quando há erro local é trivial (`getRowThemeOverride`); já o badge de origem por célula exige um **custom cell renderer** com função `draw()` própria (canvas 2D) — mais trabalho de implementação do que o resto da fase.

**Recomendação primária:** reescrever SOMENTE o bloco `Grade`/`LinhaGrade`/`Cell`/`CellInput`/`Th` (linhas ~892-1220 de `AnunciarMassa.jsx`) como um novo componente `DataEditor`-based, mantendo 100% do resto do arquivo (estado de `abas`, autosave com debounce, `PublishBar`, `puxarProduto`, `montarPayloadLinha`, `errosLocaisLinha`). O paste manual (`colunasColaveis`/`colarNaGrade`) fica obsoleto e é substituído pelo paste nativo da lib, mas `parseDimensoes`/`casarValueList`/`normalizarTipoAnuncio` continuam úteis — só mudam de lugar (viram lógica dentro de `coercePasteValue`, não mais dentro de um parser de clipboard manual).

## Architectural Responsibility Map

| Capacidade | Tier primário | Tier secundário | Motivo |
|------------|---------------|-----------------|--------|
| Renderização da grade (células, seleção, fill handle, paste) | Browser / Client (canvas via `glide-data-grid`) | — | 100% client-side; a lib não faz nenhuma chamada de rede própria |
| Autosave por linha (debounce, store/update) | Browser / Client (React state + `window.axios`) | API / Backend (`mlb.anuncios.rascunho.store/update`) | Já existe e não muda — a grade só dispara os mesmos callbacks de hoje |
| Coerção de valores colados (dim3, tipoAnuncio, list) | Browser / Client (`coercePasteValue`) | — | Puramente local; sem I/O |
| Validação bloqueante local (`errosLocaisLinha`) | Browser / Client | — | Síncrona, sem chamada de rede — determina `getRowThemeOverride` |
| Validação orientativa do ML (`/items/validate`) | API / Backend | Browser / Client (exibição) | Endpoint já existe; grade só lê o resultado (`l.valida`) |
| Publicação em lote | API / Backend (`mlb.anuncios.publicar-lote`) | Browser / Client (dispatch + polling `router.reload`) | Sem mudança — grade só fornece os `ids` publicáveis |
| Colunas dinâmicas por categoria | Browser / Client (deriva de `aba.obrigatorios`) | API / Backend (`mlb.anuncios.massa.colunas` fornece os dados) | Backend já entrega a lista; client só transforma em `GridColumn[]` |
| Dropdown de valores pré-definidos (Gênero etc.) | Browser / Client (`DropdownCell` custom renderer) | — | Todo o comportamento de overlay/editor é canvas + React portal, sem round-trip |

## Standard Stack

### Core

| Biblioteca | Versão | Propósito | Por que é padrão |
|------------|--------|-----------|-------------------|
| `@glideapps/glide-data-grid` | `6.0.3` [VERIFIED: npm registry — decisão já travada pelo usuário no ROADMAP] | Componente `DataEditor` (canvas grid) | Único pacote MIT com fill handle + range selection nativos e sem Enterprise tier; decisão já fechada, não reabrir |
| `@glideapps/glide-data-grid-cells` | `6.0.3` [VERIFIED: npm registry, mesmo monorepo/versão do core] | `DropdownCell` (SHEET2-06) e demais custom cells (`RangeCell`, `TagsCell`, `MultiSelectCell` etc.) | Pacote satélite oficial do mesmo monorepo — não é dependência de terceiro |

**Peer dependencies obrigatórias** [VERIFIED: `npm view @glideapps/glide-data-grid@6.0.3 peerDependencies` — 2026-07-15]:

```json
{
  "react": "^16.12.0 || 17.x || 18.x",
  "react-dom": "^16.12.0 || 17.x || 18.x",
  "lodash": "^4.17.19",
  "marked": "^4.0.10",
  "react-responsive-carousel": "^3.2.7"
}
```

O projeto está em `react ^18.2.0` — dentro do range suportado, sem shim/downgrade necessário.

`@glideapps/glide-data-grid-cells@6.0.3` traz peer/deps próprios [VERIFIED: `npm view @glideapps/glide-data-grid-cells dependencies`]: `react-select ^5.8.0`, `@toast-ui/editor 3.1.10`, `@toast-ui/react-editor 3.1.10`, `@linaria/react ^4.5.3` — usados por outras células do pacote (ex.: `ArticleCell` usa Toast UI para markdown). Como só vamos usar `DropdownCell`, essas deps entram na árvore de qualquer forma (é um pacote único, sem exports parciais/tree-shake de deps nativas) — impacto de bundle discutido em §Common Pitfalls.

**Instalação real:**

```bash
npm install @glideapps/glide-data-grid @glideapps/glide-data-grid-cells lodash marked react-responsive-carousel
```

**CSS obrigatório** [VERIFIED: welcome page + README oficiais]:

```js
import "@glideapps/glide-data-grid/dist/index.css";
```

Importar uma vez, no topo do novo componente de grade (não precisa ir em `app.jsx` global — só a página que usa a grade paga esse CSS).

**Portal DOM obrigatório** [VERIFIED: código-fonte, ver §Common Pitfalls #1 — este é o gotcha clássico citado no brief]. Adicionar em `resources/views/app.blade.php`, como último filho de `<body>`, UMA VEZ (é o único layout Blade compartilhado por todas as páginas Inertia deste projeto):

```blade
<body class="font-sans antialiased">
    @inertia
    <div id="portal"></div>
</body>
```

Não há incompatibilidade conhecida com Vite 7 ou ESM registrada nos issues/docs pesquisados [ASSUMED — nenhuma issue encontrada mencionando Vite; a lib é pura React/canvas sem SSR, e este projeto não faz SSR (Inertia client-side render via `createRoot`), então o padrão "dynamic import + ssr:false" citado pela doc para Next.js/Vercel não se aplica aqui].

### Supporting

| Biblioteca | Versão | Propósito | Quando usar |
|------------|--------|-----------|-------------|
| `lodash` | `^4.17.19` (peer) | Usado internamente pela lib | Não precisa ser importado no código do projeto — só precisa estar instalado |
| `marked` | `^4.0.10` (peer) | Renderização de `MarkdownCell` (não usada nesta fase) | Instalar mesmo assim — peer dependency obrigatória mesmo sem uso direto |
| `react-responsive-carousel` | `^3.2.7` (peer) | Usado por `ImageCell` (não usado nesta fase) | Idem — instalar por exigência de peer, sem uso direto no código |

### Alternatives Considered

Já descartadas e travadas pelo usuário no ROADMAP (não reabrir): `react-data-grid` (range selection é issue aberta, v7 beta exige React 19), AG Grid (fill handle/range Enterprise pago), Handsontable (licença comercial), construir do zero em DOM.

**Installation:**
```bash
npm install @glideapps/glide-data-grid @glideapps/glide-data-grid-cells lodash marked react-responsive-carousel
```

**Version verification:** `npm view @glideapps/glide-data-grid versions --json` confirmou `6.0.3` publicado e estável (existe `6.0.4-alphaXX` em pre-release — NÃO usar). `npm view @glideapps/glide-data-grid time.created/time.modified` → criado em 2020-11-10, última modificação em 2026-06-24 — pacote ativamente mantido, não abandonado.

## Package Legitimacy Audit

`slopcheck` foi instalado com sucesso (`pip install slopcheck --break-system-packages`) e rodado contra os 5 pacotes que este research recomenda instalar.

| Pacote | Registry | Idade | Repo fonte | slopcheck | Disposição |
|--------|----------|-------|------------|-----------|-------------|
| `@glideapps/glide-data-grid` | npm | ~5,5 anos (criado 2020-11-10) | `github.com/glideapps/glide-data-grid` | [OK] | Aprovado |
| `@glideapps/glide-data-grid-cells` | npm | mesmo monorepo/release | `github.com/glideapps/glide-data-grid` | [OK] | Aprovado |
| `lodash` | npm | pacote ubíquo, décadas | `github.com/lodash/lodash` | [OK] | Aprovado |
| `marked` | npm | pacote ubíquo, anos | `github.com/markedjs/marked` | [OK] | Aprovado |
| `react-responsive-carousel` | npm | pacote estabelecido | `github.com/leandrowd/react-responsive-carousel` | [OK] | Aprovado |

**Pacotes removidos por veredicto `[SLOP]`:** nenhum.
**Pacotes sinalizados como suspeitos `[SUS]`:** nenhum.

Nenhum `postinstall` script suspeito detectado nos 2 pacotes principais (verificação manual via `npm view <pkg> scripts.postinstall` não retornou nada para nenhum dos 5 pacotes).

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│ AnunciarMassa.jsx (INALTERADO — estado das abas, autosave, PublishBar)│
│                                                                       │
│  abas[] { linhas[] { uid, title, price, attrs{}, origem{} } }        │
│         │                                                            │
│         ▼                                                            │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ GradeGlide (NOVO — substitui Grade/LinhaGrade/Cell/CellInput)│    │
│  │                                                               │    │
│  │  colunas[] = 10 base + N obrigatórios(aba) ──▶ GridColumn[]  │    │
│  │                                                               │    │
│  │  getCellContent([col,row]) ◀── lê linhas[row][colunas[col]]  │    │
│  │       │                                                       │    │
│  │       ▼                                                       │    │
│  │  <DataEditor> (canvas) ──renderiza──▶ tela                   │    │
│  │       │                                                       │    │
│  │       │ usuário edita/cola/arrasta fill handle                │    │
│  │       ▼                                                       │    │
│  │  onCellsEdited([{location,value}, ...]) ── ÚNICO ponto de     │    │
│  │       │                                     entrada de edição │    │
│  │       ▼                                     (edit única, fill,│    │
│  │  agrupa por row ──▶ editarCelula() + agendarSalvar()   paste) │    │
│  │       │                                                       │    │
│  │  coercePasteValue() ── normaliza dim3/tipoAnuncio/list         │    │
│  │       (chamado ANTES do onCellsEdited, célula a célula)        │    │
│  └─────────────────────────────────────────────────────────────┘    │
│         │                                                            │
│         ▼                                                            │
│  salvarLinha() (debounce 600ms) ──▶ axios POST/PUT rascunho.store/update │
│                                          (BACKEND — sem mudança)      │
└─────────────────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
resources/js/Pages/Mlb/
├── AnunciarMassa.jsx        # Mantido — estado, autosave, PublishBar, puxarProduto
└── GradeAnuncioGlide.jsx    # NOVO — DataEditor + getCellContent + colunas + tema
```

Ver §Estratégia de Migração para a discussão entre "arquivo novo" vs. "manter no mesmo arquivo" (convenção do CLAUDE.md favorece manter no mesmo arquivo enquanto só uma página usa; a decisão final é do planner).

### Pattern 1: Colunas derivadas em duas camadas (base fixas + dinâmicas por categoria)

**O quê:** um array `GridColumn[]` construído com `useMemo` a partir de `aba.obrigatorios`, na MESMA ordem visual de hoje (10 base + N obrigatórios). Cada `GridColumn` carrega um `id` próprio que mapeia de volta ao campo da linha.

**Quando usar:** sempre que a categoria ativa mudar (`aba.category_id`/`aba.obrigatorios` mudam) — precisa recalcular `colunas` e isso força o `DataEditor` a se re-render (mudança de array `columns` é normal e esperada, ao contrário de `getCellContent` — ver §Common Pitfalls).

**Exemplo:**
```jsx
// Fonte: estrutura verificada em docs.grid.glideapps.com/welcome-to-glide-data-grid
// + dados reais de aba.obrigatorios (mlb.anuncios.massa.colunas)
const COLS_BASE = [
    { id: 'title',   title: 'Título',  width: 240 },
    { id: 'tier',    title: 'Tipo',    width: 90 },
    { id: 'price',   title: 'Preço',   width: 100 },
    { id: 'estoque', title: 'Estoque', width: 90 },
    { id: 'sku',     title: 'SKU',     width: 110 },
    { id: 'gtin',    title: 'GTIN',    width: 130 },
    { id: 'pesoG',   title: 'Peso g',  width: 90 },
    { id: 'alturaCm',       title: 'Altura cm',      width: 70 },
    { id: 'larguraCm',      title: 'Largura cm',     width: 70 },
    { id: 'comprimentoCm',  title: 'Comprimento cm', width: 70 },
];

const colunas = useMemo(() => [
    ...COLS_BASE,
    ...(aba.obrigatorios ?? []).map((o) => ({
        id: `attr:${o.id}`,
        title: o.name,
        width: 140,
        // usado por getCellContent p/ decidir se é DropdownCell ou TextCell
        _attr: o,
    })),
], [aba.obrigatorios]);
```

### Pattern 2: `getCellContent` memoizado com `useCallback` + dependência em `linhas`

**O quê:** `getCellContent` precisa ler o array `linhas` mais recente. O padrão oficial é `useCallback` com a dependência real (`aba.linhas`, `colunas`) — mudar o objeto/referência de `getCellContent` força um redraw completo da grade, então é aceitável desde que não mude a cada keystroke isolado (o próprio `onCellsEdited` já atualiza `linhas` via `setAbas`, o que naturalmente recria `getCellContent` uma vez por edição — comportamento esperado e supostamente performático o bastante para grades de algumas centenas de linhas).

**Exemplo:**
```jsx
// Fonte: docs.grid.glideapps.com/api/dataeditor/important-props (memoização)
// + shape verificado em packages/core/src/data-editor/data-editor.tsx (GridCellKind)
const getCellContent = useCallback(([col, row]) => {
    const coluna = colunas[col];
    const linha = aba.linhas[row];
    if (!linha || !coluna) {
        return { kind: GridCellKind.Loading, allowOverlay: false };
    }

    // Coluna de atributo com value_type=list → DropdownCell (SHEET2-06)
    if (coluna._attr?.value_type === 'list' && coluna._attr.values?.length) {
        const valor = linha.attrs[coluna._attr.id] ?? '';
        return {
            kind: GridCellKind.Custom,
            allowOverlay: true,
            copyData: valor,
            data: {
                kind: 'dropdown-cell',
                value: valor || undefined,
                allowedValues: coluna._attr.values.map((v) => v.name),
            },
        };
    }

    // Campos base / atributos texto → TextCell comum
    const bruto = coluna.id.startsWith('attr:')
        ? (linha.attrs[coluna._attr.id] ?? '')
        : (linha[coluna.id] ?? '');
    return {
        kind: GridCellKind.Text,
        allowOverlay: true,
        data: String(bruto),
        displayData: String(bruto),
    };
}, [colunas, aba.linhas]);
```

### Pattern 3: `onCellsEdited` como ÚNICO ponto de entrada de escrita (edição, fill, paste)

**O quê:** [VERIFIED via código-fonte, `packages/core/src/data-editor/data-editor.tsx:1207-1224`] internamente a lib SEMPRE chama `onCellsEdited` primeiro (edição por teclado de uma célula vira um array de 1 item); se `onCellsEdited` não tratar (retornar falsy), ela cai para `onCellEdited` (singular) célula-a-célula. Ou seja: implementar SÓ `onCellsEdited` cobre teclado + fill handle + paste com UM handler — resolve diretamente a dúvida do brief sobre "como não perder o autosave por linha com debounce".

**Exemplo:**
```jsx
// Fonte: assinatura verificada em packages/core/src/data-editor/data-editor.tsx:217
const onCellsEdited = useCallback((edicoes) => {
    // edicoes: readonly { location: [col,row], value: EditableGridCell }[]
    const uidsAfetados = new Set();
    setAbas((prev) => prev.map((a, i) => {
        if (i !== abaAtivaRef.current) return a;
        const linhas = [...a.linhas];
        edicoes.forEach(({ location: [col, row], value }) => {
            const coluna = colunas[col];
            const alvo = { ...linhas[row] };
            const novoValor = value.data; // já passou por coercePasteValue quando veio de paste
            if (coluna.id.startsWith('attr:')) {
                alvo.attrs = { ...alvo.attrs, [coluna._attr.id]: novoValor };
            } else {
                alvo[coluna.id] = novoValor;
            }
            alvo.salvo = false;
            linhas[row] = alvo;
            uidsAfetados.add(alvo.uid);
        });
        return { ...a, linhas };
    }));
    // Autosave com debounce por linha — MESMO mecanismo de hoje (agendarSalvar)
    uidsAfetados.forEach((uid) => agendarSalvar(abaAtivaRef.current, uid));
    return true; // true = a lib já aplicou visualmente; não precisa de onCellEdited extra
}, [colunas, agendarSalvar]);
```

### Pattern 4: `coercePasteValue` reaproveitando `parseDimensoes`/`casarValueList`/`normalizarTipoAnuncio`

**O quê:** a lib chama `coercePasteValue(valorColado, cellAtual)` célula a célula ANTES de disparar `onCellsEdited`, para cada célula do bloco colado. É o ponto exato para portar a lógica de `aplicarCelula()` que hoje vive em `colarNaGrade` (linha ~443 do arquivo atual).

**Exemplo:**
```jsx
// Fonte: assinatura verificada em API.md do repo oficial —
// coercePasteValue?: (val: string, cell: GridCell) => GridCell | undefined
const coercePasteValue = useCallback((valorColado, cellAtual) => {
    // dim3 (A×L×C) não se aplica aqui pois é 1 valor por célula — tratado via
    // 3 colunas separadas (alturaCm/larguraCm/comprimentoCm) OU via lógica
    // adicional em onCellsEdited se o valor colado tiver "x"/"×" (decisão do planner)
    if (cellAtual.kind === GridCellKind.Custom && cellAtual.data?.kind === 'dropdown-cell') {
        const casado = casarValueList(valorColado, cellAtual.data.allowedValues.map((n) => ({ name: n })));
        return { ...cellAtual, data: { ...cellAtual.data, value: casado } };
    }
    return { ...cellAtual, data: valorColado.trim(), displayData: valorColado.trim() };
}, []);
```

### Pattern 5: `getRowThemeOverride` para o realce de erro local (substitui `shadow-[inset...]`)

**O quê:** reaproveita `errosLocaisLinha(l, aba)` (lógica pura, já existe, não muda) para decidir a cor de fundo da linha inteira.

**Exemplo:**
```jsx
// Fonte: assinatura verificada em API.md — getRowThemeOverride?: (row: number) => Partial<Theme> | undefined
const getRowThemeOverride = useCallback((row) => {
    const l = aba.linhas[row];
    if (!l) return undefined;
    if (errosLocaisLinha(l, aba).length > 0) {
        return { bgCell: 'rgba(239,68,68,0.06)', borderColor: 'rgba(239,68,68,0.35)' }; // red-500 @ baixa opacidade
    }
    if (l.valida && !l.valida.valido && (l.valida.erros?.length ?? 0) > 0) {
        return { bgCell: 'rgba(251,191,36,0.05)', borderColor: 'rgba(251,191,36,0.3)' }; // amber-400
    }
    return undefined;
}, [aba]);
```

### Pattern 6: Tema mapeando os tokens `ecf-*` (SHEET2-08)

**O quê:** objeto `theme` estático (memoizado uma vez), com as chaves reais do `Theme` interface [VERIFIED via `packages/core/API.md`], mapeando os hex reais de `tailwind.config.js`.

**Exemplo:**
```jsx
// Fonte: chaves verificadas em packages/core/API.md (Theme interface) do repo oficial
// Hex reais: tailwind.config.js theme.extend.colors.ecf
const temaEcf = {
    accentColor: '#ffe600',       // ecf.yellow — seleção/foco
    accentFg: '#000000',          // texto sobre o amarelo (mesmo padrão do botão Publicar)
    accentLight: 'rgba(255,230,0,0.08)', // mesmo tom de focus:bg-ecf-yellow/[0.08] usado em toda a grade atual
    textDark: '#ffffff',
    textMedium: 'rgba(255,255,255,0.6)',
    textLight: 'rgba(255,255,255,0.4)',
    textHeader: 'rgba(255,255,255,0.4)',
    bgCell: '#0f1116',             // ecf.card
    bgCellMedium: '#14161d',       // ecf.card-2
    bgHeader: '#14161d',           // ecf.card-2
    bgHeaderHasFocus: '#191c24',
    bgHeaderHovered: 'rgba(255,255,255,0.04)',
    borderColor: 'rgba(255,255,255,0.08)',      // ecf.line
    horizontalBorderColor: 'rgba(255,255,255,0.06)',
    fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif', // mesmo fontFamily.sans do tailwind.config.js
    headerFontStyle: '600 11px',
    baseFontStyle: '13px',
    editorFontSize: '13px',
};
```

### Anti-Patterns to Avoid

- **Recalcular `getCellContent` a cada render sem `useCallback`:** força redraw completo do canvas a cada keystroke em qualquer input externo à grade (ex.: a busca de categoria). Sempre memoizar com dependências mínimas.
- **Tentar estilizar o canvas com classes Tailwind:** `className` no `<DataEditor>` só afeta o wrapper DOM, nunca o conteúdo desenhado. Toda cor visual de célula/linha passa por `theme`/`getRowThemeOverride`/custom cell `draw()`.
- **Reimplementar o parser manual de TSV (`colarNaGrade`) por cima da lib:** ao configurar `onPaste`/`coercePasteValue`/`getCellsForSelection`, o parser manual de `\t`/`\n` vira código morto e conflitante — ver §Don't Hand-Roll.
- **Esperar que `getRowThemeOverride` pinte badges por célula:** é por LINHA. Origem por célula exige custom cell renderer com `draw()` (ver §Common Pitfalls).

## Don't Hand-Roll

| Problema | Não construa | Use em vez disso | Por quê |
|----------|---------------|-------------------|---------|
| Parsing de clipboard TSV (`\t`/`\n`, mapeamento coluna a coluna) | `colarNaGrade` + `colunasColaveis` (manual, ~80 linhas hoje) | `onPaste` + `coercePasteValue` + `onCellsEdited` da lib | A lib já faz split de linha/coluna, aplica `coercePasteValue` célula a célula e entrega tudo pronto em `onCellsEdited`; reimplementar duplica lógica que a lib já testa |
| Fill handle (arrastar alça, replicar padrão vertical/horizontal) | Handler de `mousedown`/`mousemove` customizado sobre a `<table>` | `fillHandle={true}` + `allowedFillDirections="any"` (+ `onFillPattern` só se precisar de lógica de padrão customizada, ex.: sequência numérica) | Nativo, testado, acessível — reimplementar em canvas do zero é ordens de magnitude mais complexo que em DOM |
| Seleção de múltiplos retângulos (Ctrl/Cmd+arrastar) | Estado de seleção manual (`Set` de uids selecionados) | `gridSelection` controlado + `rangeSelect="multi-rect"` | Interação de mouse/teclado combinada (shift-click, ctrl-click, arrastar) é complexa de acertar em todos os browsers |
| Navegação por teclado (Tab/Enter/setas com wrap de linha) | `onKeyDown` customizado nos `<input>` | Nativo do `DataEditor` (SHEET2-04) | Vem de graça — nenhuma configuração necessária |
| Dropdown com valores restritos preservando "aparência de planilha" | `<select>` sempre visível dentro da célula (como hoje) | `DropdownCell` de `@glideapps/glide-data-grid-cells` (overlay só ao editar) | Exatamente o comportamento pedido em SHEET2-06 — célula parece texto, dropdown só no clique/Enter |

**Key insight:** o ganho real de trocar para `glide-data-grid` não é só visual — é que TODA a camada de interação (seleção, fill, paste, teclado) que hoje precisaria ser reimplementada manualmente em canvas (SHEET2-01 a 05) já vem pronta e testada pela lib. O trabalho da fase se concentra em (a) adaptar o MODELO DE DADOS para o paradigma `getCellContent`/`onCellsEdited`, e (b) portar as 3 affordances visuais que são específicas deste projeto e não têm equivalente pronto na lib: badge de origem por célula, contador de caracteres do título, botão inline "gerar EAN-13".

## Common Pitfalls

### Pitfall 1: Portal DOM ausente (`<div id="portal">`)

**O que dá errado:** clicar/Enter numa célula para editar não abre overlay nenhum; nenhum erro visível na UI, só no console.
**Por que acontece:** [VERIFIED via código-fonte] `data-grid-overlay-editor.tsx` faz `document.getElementById("portal")`; se `null`, loga `'Cannot open Data Grid overlay editor, because portal not found. Please add <div id="portal" /> as the last child of your <body>.'` e a função retorna sem renderizar nada.
**Como evitar:** adicionar `<div id="portal"></div>` como último filho de `<body>` em `resources/views/app.blade.php` (único layout Blade do projeto — mudança de 1 linha, feita uma única vez, não afeta outras páginas).
**Sinais de alerta:** grade renderiza normalmente (leitura), mas clicar numa célula/apertar Enter não abre nenhum editor, nenhum dropdown, nenhuma seleção múltipla de texto — silêncio total exceto o console.

### Pitfall 2: Badges por CÉLULA não têm equivalente em `getRowThemeOverride`

**O que dá errado:** tentar usar `getRowThemeOverride` para pintar só uma célula específica (ex.: a bolinha violeta/âmbar de origem) não funciona — o override é aplicado à linha inteira.
**Por que acontece:** [VERIFIED via `packages/core/API.md`] a assinatura é `(row: number) => Partial<Theme>` — não recebe `col`.
**Como evitar:** implementar um custom cell renderer (`kind: GridCellKind.Custom`) com função `draw(args, cell)` própria que desenha o texto normal E um pequeno círculo colorido no canto (canvas 2D via `args.ctx`), OU aceitar uma simplificação (ex.: mover o indicador de origem para um ícone na coluna "#"/linha em vez de por-célula) — **decisão do planner**, listada em §Open Questions.
**Sinais de alerta:** código tentando passar `col` para `getRowThemeOverride` ou usar `theme` global para pintar 1 célula só.

### Pitfall 3: `getCellContent` instável causa redraw completo / perda de performance

**O que dá errado:** grade "pisca" ou fica lenta ao digitar em QUALQUER lugar da página (não só na grade).
**Por que acontece:** a lib chama `getCellContent` centenas de vezes por segundo durante scroll; se a referência da função mudar a cada render (sem `useCallback` ou com dependências excessivas), o grid inteiro re-renderiza.
**Como evitar:** `useCallback` com array de dependências mínimo (`aba.linhas`, `colunas` — não o objeto `aba` inteiro, não `abas` completo).
**Sinais de alerta:** scroll ou digitação com stutter perceptível; DevTools Profiler mostrando `DataEditor` re-montando a cada keystroke fora da grade.

### Pitfall 4: Copiar (Ctrl+C) não funciona por padrão

**O que dá errado:** usuário seleciona células e aperta Ctrl+C, nada vai para o clipboard.
**Por que acontece:** [VERIFIED via `extended-quickstart-guide/copy-and-paste-support.md`] "Copy is disabled by default" — sem `getCellsForSelection`, copy simplesmente não funciona.
**Como evitar:** `getCellsForSelection={true}` (implementação genérica embutida da lib) resolve SHEET2-03 sem trabalho extra; uma implementação customizada só é necessária se houver otimização de performance a fazer depois.
**Sinais de alerta:** teste manual "selecionar range → Ctrl+C → colar no Excel" não traz nada.

### Pitfall 5: Colar de fora (Excel/Sheets) exige `onPaste` configurado — mesmo com `onCellsEdited`

**O que dá errado:** configurar só `onCellsEdited` sem `onPaste` faz o Ctrl+V não disparar nada.
**Por que acontece:** `onPaste` é o hook de ENTRADA do evento de colar (intercepta o clipboard); `onCellsEdited` é o hook de SAÍDA (recebe o resultado já processado). Sem `onPaste={true}` (ou uma função customizada), o evento de paste do browser não é nem capturado pela lib.
**Como evitar:** manter `onPaste={true}` (deixa a lib fazer o parsing de TSV/CSV do clipboard) + `coercePasteValue` para a normalização de domínio (dim3, tipoAnuncio, list) + `onCellsEdited` para o autosave em lote.
**Sinais de alerta:** paste funciona dentro de uma célula sendo editada (comportamento nativo do input do overlay) mas não funciona com a grade só selecionada (sem estar em modo de edição).

### Pitfall 6: `rowMarkers` tem 6 valores possíveis, não 4 — usar a lista errada quebra TypeScript/prop silenciosamente

**O que dá errado:** documentação de terceiros (blogs, Storybook resumido) às vezes lista só `"checkbox" | "number" | "both" | "none"`.
**Por que acontece:** [VERIFIED via código-fonte `data-editor.tsx:97`] o tipo real é `"checkbox" | "number" | "clickable-number" | "checkbox-visible" | "both" | "none"` — `"clickable-number"` é o que habilita clicar no número da linha para SELECIONAR a linha inteira (relevante para SHEET2-05).
**Como evitar:** usar `rowMarkers="clickable-number"` (não `"number"`) quando o requisito é "seleção de linha clicando no número", combinado com `rowSelect="multi"`.
**Sinais de alerta:** clicar no número da linha não seleciona nada (porque `rowMarkers="number"` é só decorativo, não interativo).

### Pitfall 7: Bundle size de `glide-data-grid-cells` traz dependências que nada tem a ver com Dropdown

**O que dá errado:** instalar `@glideapps/glide-data-grid-cells` só para usar `DropdownCell` traz `react-select`, `@toast-ui/editor` e `@toast-ui/react-editor` na árvore de deps (usados por `ArticleCell`, não por `DropdownCell`).
**Por que acontece:** [VERIFIED via `npm view @glideapps/glide-data-grid-cells dependencies`] é um pacote único sem exports parciais — instalar o pacote traz todas as deps de todas as células, mesmo usando só uma.
**Como evitar:** aceitar o custo (é um pacote oficial do mesmo monorepo, mantido, sem alternativa split) — mas MEDIR o impacto real no `npm run build` (relatório de chunk size do Vite) antes/depois, já que a página `AnunciarMassa` é lazy-loaded via Inertia (`import.meta.glob` sem `eager: true` em `app.jsx`) e portanto o custo só afeta quem visita ESSA página, não o bundle inicial do app.
**Sinais de alerta:** `npm run build` reportando um chunk `AnunciarMassa-*.js` muito maior que outras páginas do módulo MLB.

## Code Examples

Exemplos completos combinados por Pattern já cobertos em §Architecture Patterns 1-6 (todos com fonte citada inline). Um exemplo adicional cobrindo o custom cell renderer de origem (Pitfall 2):

### Custom cell com badge de origem (esboço)

```jsx
// Fonte: assinatura verificada em packages/core/src/cells/cell-types.ts
// (BaseCellRenderer.draw: DrawCallback<T> = (args: DrawArgs<T>, cell: T) => void)
const origemCellRenderer = {
    kind: GridCellKind.Custom,
    isMatch: (cell) => cell.data?.kind === 'origem-cell',
    draw: (args, cell) => {
        const { ctx, rect, theme } = args;
        const { texto, origem } = cell.data;
        // Desenha o texto normal (mesma lógica de uma TextCell simples)
        ctx.fillStyle = theme.textDark;
        ctx.font = theme.baseFontStyle;
        ctx.fillText(texto, rect.x + theme.cellHorizontalPadding, rect.y + rect.height / 2 + 4);
        // Bolinha no canto (violeta=cliente, âmbar=publicador) — equivalente ao OrigemBadge atual
        if (origem) {
            ctx.beginPath();
            ctx.arc(rect.x + rect.width - 8, rect.y + 8, 3, 0, 2 * Math.PI);
            ctx.fillStyle = origem === 'cliente' ? '#a78bfa' : '#fbbf24'; // violet-400 / amber-400
            ctx.fill();
        }
    },
    provideEditor: () => TextCellEntry, // reusa o editor de texto padrão da lib
};
```

Este é um ESBOÇO de referência (assinatura confirmada, comportamento a validar em execução) — não um exemplo copiado literalmente de docs oficiais, por isso classificado como derivado, não como cópia verbatim.

## State of the Art

| Abordagem antiga | Abordagem atual | Quando mudou | Impacto |
|-------------------|------------------|---------------|---------|
| `<table>` HTML com `<input>` por célula (código atual) | Canvas grid com callbacks (`getCellContent`/`onCellsEdited`) | Decisão desta fase (2026-07-15) | Modelo de dados vira "colunas + acesso por índice" em vez de "JSX por linha"; ganha fill handle/range/paste nativos, perde estilização via Tailwind direta |
| Paste manual via `onPaste` do React + parsing próprio de `\t`/`\n` | `onPaste`/`coercePasteValue`/`onCellsEdited` da lib | Nesta fase | `colunasColaveis`/`colarNaGrade` (linhas 94-150 e 428-500 do arquivo atual) tornam-se código morto — a lógica de coerção (`parseDimensoes`/`casarValueList`/`normalizarTipoAnuncio`) é reaproveitada dentro de `coercePasteValue` |

**Deprecated/outdated:**
- `colunasColaveis(aba)` + `colarNaGrade` (handler de `onPaste` no `<div>` wrapper): substituídos pelo mecanismo nativo da lib. Manter só as funções de coerção puras que eles chamam.
- `<CellInput>`/`<Cell>`/`<Th>` (componentes JSX de célula): sem equivalente — canvas não renderiza JSX. Toda a lógica visual migra para `getCellContent` + `theme` + custom cell renderers.

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|-------------------|
| A1 | Não há incompatibilidade conhecida entre `glide-data-grid` e Vite 7/ESM | §Standard Stack | Baixo — nenhuma issue encontrada relatando problema; se houver, apareceria como erro de build/import imediato no primeiro `npm run build`, fácil de detectar cedo |
| A2 | A busca web trouxe `useExtraCells` como o hook para registrar `customRenderers` de `@glideapps/glide-data-grid-cells` — **isso foi VERIFICADO COMO INCORRETO** ao ler `packages/cells/src/index.ts` no GitHub: o pacote exporta `DropdownCell`/`allCells` diretamente (array de renderers), sem hook `useExtraCells`. Usar `customRenderers={[DropdownCell]}` ou `customRenderers={allCells}` | §Code Examples, §Phase Requirements (SHEET2-06) | Já corrigido nesta pesquisa — mas o planner deve confirmar o import exato (`import { DropdownCell } from "@glideapps/glide-data-grid-cells"`) ao escrever o código, pois a versão instalada pode variar sutilmente |
| A3 | Estimativa de bundle size adicional (não medida via bundlephobia — ferramenta não retornou dados) | §Common Pitfalls #7 | Baixo/médio — só descoberto ao rodar `npm run build`; recomendável medir cedo (Wave 1) para não ser surpresa no fim da fase |
| A4 | O tema (`temaEcf`) proposto em §Code Examples é uma tradução de hex reais para as chaves reais da lib, mas os valores de opacidade/contraste exatos (ex.: `accentLight` em `rgba(255,230,0,0.08)`) são uma escolha de design meu, não uma regra documentada — precisa validação visual humana (checkpoint) | §Standard Stack, §Code Examples | Médio — pode exigir ajuste fino de cor durante checkpoint visual, mas não bloqueia funcionalidade |

## Open Questions

1. **Como tratar o badge de origem por célula (violeta/âmbar) dado que `getRowThemeOverride` é por linha?**
   - O que sabemos: custom cell renderer com `draw()` resolve tecnicamente (Pitfall 2), mas é o item de MAIOR esforço de implementação da fase — exige canvas 2D manual por célula que tiver origem 'cliente'/'publicador'.
   - O que não está claro: se vale o esforço para TODAS as colunas ou só para as colunas onde `puxarProduto` de fato preenche (title, sku, price, estoque, descricao, pesoG, alturaCm, larguraCm, comprimentoCm) — os atributos de ficha técnica (`obrig`) nunca vêm do cliente hoje.
   - Recomendação: escopar o custom renderer só às colunas que `puxarProduto` toca; simplificar para as demais.

2. **Botão inline "gerar EAN-13" na célula GTIN — como portar para canvas?**
   - O que sabemos: hoje é um `<button>` dentro do `<td>`, ao lado do `<input>`. Canvas não tem elementos clicáveis nativos dentro de uma célula de texto simples.
   - O que não está claro: se vale a pena um custom cell com `onClick` detectando a região do botão via `posX`/`bounds` (API confirma que `onClick` existe em `BaseCellRenderer` com `posX`/`posY`/`bounds`), ou se é mais simples mover para uma ação de TOOLBAR ("Gerar GTINs faltantes" em lote, fora da grade).
   - Recomendação: mover para ação de toolbar em lote — reduz complexidade de implementação sem perder a funcionalidade (o usuário ainda consegue gerar EAN-13, só que via botão fora da grade em vez de inline).

3. **Contador de caracteres do título (`{l.title.length}/{maxTitle}`) — onde mostrar sem custom cell?**
   - O que sabemos: hoje é um `<span>` absolutamente posicionado sobre o `<input>`.
   - O que não está claro: se o overlay editor nativo da lib (que abre ao clicar/Enter na célula) já mostra o `maxLength` de alguma forma, ou se precisa de um `provideEditor` customizado com um `<input maxLength>` + contador dentro do overlay (isso é possível — `provideEditor` aceita qualquer JSX React, renderizado dentro do portal).
   - Recomendação: usar `provideEditor` customizado para a coluna "Título" com o mesmo `<input maxLength>` + contador que já existe hoje (reaproveita o JSX quase 1:1, só troca onde ele é montado).

## Environment Availability

| Dependência | Necessária para | Disponível | Versão | Fallback |
|--------------|-------------------|------------|--------|----------|
| Node.js | `npm install` + `npm run build` | ✓ | v24.15.0 (conforme CLAUDE.md) | — |
| npm | Instalação dos pacotes | ✓ | conforme Node 24 | — |
| React 18 | Peer dependency do glide-data-grid | ✓ | `^18.2.0` (dentro do range suportado `16-19`) | — |

Nenhuma dependência de infraestrutura externa (banco, serviço, API) é introduzida por esta fase — é 100% instalação de pacotes npm + 1 linha de Blade.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|-------------|-------|
| Framework (backend) | PHPUnit 11.x — config em `phpunit.xml` |
| Framework (frontend/JS) | **Nenhum instalado** (`package.json` não tem `vitest`/`jest`/`@testing-library/react`) |
| Config file | `phpunit.xml` (backend); nenhum para JS |
| Comando rápido (backend) | `php artisan test --filter=Phase82` (roda só `tests/Feature/Phase82/GradeMassaTest.php`) |
| Comando completo (backend) | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------------|----------------|------------------------|-------------------|
| SHEET2-07 | Backend não regride (rotas `massa`/`massa.colunas`/`massa.produtos`) | Feature (PHPUnit) | `php artisan test --filter=GradeMassaTest` | ✅ já existe (`tests/Feature/Phase82/GradeMassaTest.php`, 9 testes) |
| SHEET2-01 a 06, 08 | Interação de grade (range select, fill handle, paste, dropdown, tema) | manual-only | — | ❌ nenhum framework de teste JS instalado; automação de canvas/mouse-drag é notoriamente frágil mesmo COM Playwright/Cypress |
| SHEET2-03 (coerção pura) | `parseDimensoes`/`casarValueList`/`normalizarTipoAnuncio` continuam corretas após virarem parte de `coercePasteValue` | unit (se JS test framework for adicionado) | — | ❌ Wave 0 gap — ver abaixo |

**Justificativa manual-only:** interações de canvas (arrastar fill handle, selecionar range com mouse, colar via evento de clipboard sintético) não têm um DOM tradicional para inspecionar via testing-library; testar isso exigiria Playwright com simulação real de mouse/clipboard — desproporcional ao escopo desta fase única. As funções PURAS de coerção (`parseDimensoes` etc.) SÃO testáveis unitariamente sem tocar no canvas, e vale a pena isolar isso.

### Sampling Rate
- **Por commit de task:** `php artisan test --filter=Phase82` (backend, ~9 testes, roda em segundos)
- **Por merge de wave:** `php artisan test` (suíte completa) + checkpoint visual humano (canvas não é testável automaticamente pela suíte)
- **Phase gate:** suíte completa verde + `npm run build` sem erros + checkpoint visual humano cobrindo os 8 requisitos (SHEET2-01 a 08) antes de `/gsd-verify-work`

### Wave 0 Gaps
- [ ] Nenhum framework de teste JS existe no projeto — **decisão do planner**: introduzir `vitest` só para testar as funções puras de coerção (`parseDimensoes`, `casarValueList`, `normalizarTipoAnuncio`, `errosLocaisLinha`) é opcional e de baixo custo (zero dependência de DOM/canvas), mas é uma mudança de escopo (primeira introdução de test runner JS no projeto) — sinalizar para o usuário confirmar antes de adicionar.
- [ ] Se `vitest` não for adotado: as 4 funções de coerção continuam cobertas apenas pelos mesmos testes manuais que já cobrem o comportamento de hoje (nenhuma regressão de cobertura, mas nenhum ganho tampouco).

## Security Domain

Este research não encontrou `security_enforcement: false` explícito em `.planning/config.json` (chave ausente = enforcement habilitado por padrão).

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|------------------|--------|-------------------|
| V2 Autenticação | Não (fase não mexe em auth) | — (middleware `role:admin` já existente nas rotas, sem mudança) |
| V3 Gestão de sessão | Não | — |
| V4 Controle de acesso | Não (mesmas rotas, mesmo middleware) | `EnsureUserHasRole` já aplicado, sem mudança |
| V5 Validação de entrada | **Sim** — dados colados do clipboard (Excel/Sheets) chegam como texto arbitrário do usuário | `coercePasteValue` + `errosLocaisLinha` (validação local bloqueante já existente) + validação server-side já existente em `MlbAnuncioController` (payload de rascunho passa pelas mesmas regras de hoje — o backend não muda) |
| V6 Criptografia | Não aplicável | — |

### Known Threat Patterns for este stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|--------------------|
| XSS via valor colado do clipboard renderizado sem sanitização (ex.: colar HTML/script disfarçado de texto num título de anúncio) | Tampering / Elevation of Privilege | `GridCellKind.Text` do glide-data-grid renderiza como TEXTO PURO no canvas (não interpreta HTML — é `ctx.fillText`, não `innerHTML`), então XSS clássico de DOM não se aplica ao CANVAS em si; o risco real está em onde esse valor é EXIBIDO depois (ex.: se algum lugar do app renderizar `l.title` via `dangerouslySetInnerHTML` — não encontrado no código atual, `AnunciarMassa.jsx` sempre usa JSX padrão `{l.title}` que o React já escapa) |
| Payload malformado forçando erro 500 no backend ao publicar em lote | Denial of Service | Já mitigado — `montarPayloadLinha`/validação server-side não mudam nesta fase; `errosLocaisLinha` continua sendo a barreira local antes de qualquer POST |

## Sources

### Primary (ALTA confiança — código-fonte oficial no GitHub, lido diretamente)
- `github.com/glideapps/glide-data-grid/blob/main/packages/core/src/data-editor/data-editor.tsx` — `RowMarkerOptions`, `onCellEdited`/`onCellsEdited` (assinaturas exatas e comportamento de fallback interno)
- `github.com/glideapps/glide-data-grid/blob/main/packages/core/src/cells/cell-types.ts` — `BaseCellRenderer`/`CustomRenderer`/`DrawCallback` (custom cell renderer)
- `github.com/glideapps/glide-data-grid/blob/6b0a04f9d6550378890580b4db1e1168e4268c54/packages/core/src/data-grid-overlay-editor/data-grid-overlay-editor.tsx` — confirmação exata do `document.getElementById("portal")` e mensagem de erro
- `github.com/glideapps/glide-data-grid/blob/main/packages/cells/src/index.ts` — exports reais de `@glideapps/glide-data-grid-cells` (`DropdownCell`, `allCells`)
- `github.com/glideapps/glide-data-grid/blob/main/packages/cells/src/cells/dropdown-cell.tsx` — shape de `DropdownCellProps` (`kind: "dropdown-cell"`, `value`, `allowedValues`)
- `npm view @glideapps/glide-data-grid@6.0.3` / `npm view @glideapps/glide-data-grid-cells` — peerDependencies, dependencies, versões publicadas, datas de criação/modificação
- `slopcheck install` (rodado localmente 2026-07-15) — veredicto `[OK]` para os 5 pacotes

### Secondary (MÉDIA confiança — docs oficiais via WebFetch, cross-verificado onde possível)
- `docs.grid.glideapps.com/welcome-to-glide-data-grid` — instalação, CSS obrigatório, quickstart
- `docs.grid.glideapps.com/extended-quickstart-guide/copy-and-paste-support` — comportamento de copy/paste, "copy desabilitado por padrão"
- `docs.grid.glideapps.com/api/dataeditor.md` + `packages/core/API.md` (raw GitHub) — props de `theme`, `fillHandle`, `allowedFillDirections`, `rangeSelect`, `getCellsForSelection`, `onPaste`, `coercePasteValue`

### Tertiary (BAIXA confiança — WebSearch sem verificação direta no código, marcado como corrigido/substituído onde aplicável)
- Resultado de WebSearch citando `useExtraCells` como hook de registro de custom cells — **verificado como INCORRETO** ao ler o código-fonte real (ver Assumptions Log A2); não usar essa API no plano.

## Metadata

**Confidence breakdown:**
- Standard stack (pacotes, versões, peer deps): ALTA — verificado via `npm view` + leitura de código-fonte
- Arquitetura/API (theme, fillHandle, onCellsEdited, portal): ALTA — quase tudo confirmado lendo o TypeScript-fonte real do repo, não só docs de terceiros
- Pitfalls: ALTA para os itens verificados em código-fonte (portal, rowMarkers, onCellsEdited fallback); MÉDIA para o custom cell renderer de origem (esboço não testado em runtime real deste projeto)
- Tema/cores ecf-*: MÉDIA — mapeamento é decisão de design minha, não fato documentado; precisa checkpoint visual humano

**Data da pesquisa:** 2026-07-15
**Válido até:** ~2026-08-14 (30 dias — lib estável, mas monitorar releases `6.0.4-alphaXX` em andamento; não usar pre-releases)
