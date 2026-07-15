---
phase: 82-planilha-excel-like-na-grade-de-anuncio-em-massa-glide-data-
plan: 02
date: 2026-07-15
status: complete
type: execute
requirements: [SHEET2-07, SHEET2-08]
one-liner: "A <table> HTML virou canvas: DataEditor com tema ecf-*, colunas dinâmicas da aba ativa e autosave preservado — a virada de paradigma da fase"
files-modified:
  - resources/js/Pages/Mlb/GradeAnuncioGlide.jsx (novo, 195 linhas)
  - resources/js/Pages/Mlb/AnunciarMassa.jsx
commits:
  - 56117aa feat(82-02) grade em canvas com tema ecf-* e colunas dinamicas
  - d426b6f feat(82-02) troca a tabela HTML pelo canvas e remove as celulas mortas
---

# 82-02 — Grade em canvas — Summary

## O que foi feito

A virada de paradigma da fase: de "um `<input>` JSX por célula" para "a grade pergunta o conteúdo
célula a célula via `getCellContent` e devolve edições em lote via `onCellsEdited`". A página
segue dona do estado, do autosave e da publicação — o componente só desenha e delega.

### Task 1 — `GradeAnuncioGlide.jsx` (`56117aa`)

- **Tema (SHEET2-08):** `temaEcf` traduz os hex reais de `tailwind.config.js` (`ecf.card` #0f1116 →
  `bgCell`, `ecf.card-2` #14161d → `bgHeader`, `ecf.yellow` #ffe600 → `accentColor`, `ecf.line` →
  `borderColor`, Inter). Classes Tailwind **não alcançam o canvas** — toda cor passa por aqui.
- **Colunas:** `COLS_BASE` (10) + uma por atributo obrigatório da categoria **ativa** (SHEET-02,
  nunca a união das abas), com grupos "Campos base" / "Ficha técnica · {categoria}" e `*` nos
  obrigatórios. Título mostra o limite: `Título (60) *`.
- **`getCellContent`** memoizado com deps mínimas `[colunas, aba.linhas]` — a lib chama isso
  centenas de vezes por segundo no scroll; passar `aba` inteiro forçaria redraw completo do canvas
  a cada keystroke em qualquer input da página (Pitfall 3 do research).
- **Valores seguem `String`** (sem `GridCellKind.Number`): `montarPayloadLinha` é quem faz
  `Number(l.price)` na saída — trocar o tipo aqui mudaria o payload = regressão (SHEET2-07).
- **`tier` e atributos `value_type=list`** ficam `allowOverlay: false` (somente leitura) até
  virarem `DropdownCell` no Plan 03. Deixá-los como texto livre gravaria lixo em `listing_type_id`.

### Task 2 — Escrita, origem, A×L×C e trailing row (`56117aa`)

- **`onCellsEdited` é o único handler de escrita.** O research provou no código-fonte que a lib
  chama o plural primeiro para TUDO (digitação, fill handle, paste) e só cai no singular se este
  retornar falsy. `onCellEdited` **não** foi implementado — um handler só, sem lógica duplicada.
- Delega para `onEditarCelula` (= `editarComSalvar` da página), que já grava no estado, marca
  `salvo: false` e agenda o autosave com debounce de 600ms. Nada de `setAbas` aqui dentro.
- **A×L×C:** digitar/colar `10x20x30` em Altura passa por `parseDimensoes` e emite 3 edições.
  Ganho sobre hoje: a grade antiga só fazia isso no paste; agora vale na digitação também.
- **"+ Adicionar linha"** via `onRowAppended` + `trailingRowOptions` nativos.
- `freezeColumns={1}` mantém o Título visível no scroll horizontal (equivale ao `sticky left-0`).

### Task 3 — Swap cirúrgico (`d426b6f`)

`AnunciarMassa.jsx`: **12 inserções / 343 remoções**. Saíram `Grade`, `LinhaGrade`, `Th`, `Cell`,
`CellInput`, `OrigemBadge` — canvas não renderiza JSX, não há caminho de volta. Tudo o mais
(estado das abas, autosave, `PublishBar`, puxar produtos, cápsulas de categoria, `ModoAnuncioTabs`)
intocado, provado por gate estrutural de 14 símbolos.

## Desvio relevante — o plano estava errado sobre a origem, e segui-lo quebraria o Estoque

O plano mandava usar `chaveOrigem(campo)` "ao repassar o campo para `onEditarCelula`". Isso está
**errado**: `editarCelula` usa o mesmo argumento para gravar o valor (`{...l, [campo]: valor}`) e
para a origem. Passar `available_quantity` como campo gravaria `l.available_quantity = valor` em
vez de `l.estoque` — **o campo Estoque pararia de funcionar**.

Correção adotada: a chave vai por `opts.chaveOrigem`, e `editarCelula` ganhou
`{ attr = false, chaveOrigem } = {}` usando `chaveOrigem ?? campo` só para o mapa de origem.
Retrocompatível — sem a opt, o comportamento é byte-idêntico ao anterior. Isso tocou `editarCelula`,
que o plano listava como "não tocar"; era necessário e é a mudança mínima possível (5 linhas).

Com isso fica corrigida a **inconsistência pré-existente** que o planner identificou: hoje
`puxarProduto` grava `origem.available_quantity`/`origem.description`, mas `set('estoque', …)`
gravava `origem.estoque` — chave que ninguém lê. Na prática, editar Estoque **nunca** apagava a
marca de "veio do cliente". O badge só volta a aparecer no Plan 06, então isso não é visível ainda.

## Bundle — o custo do canvas (incógnita A3, agora medida de verdade)

| Chunk | Antes | Depois |
|-------|-------|--------|
| `AnunciarMassa-*.js` | 29.90 kB | **19.53 kB** (encolheu: as células JSX saíram) |
| `GradeAnuncioGlide-*.js` | — | **301.01 kB** (gzip 102.31 kB) |
| `GradeAnuncioGlide-*.css` | — | **8.09 kB** |

Delta ≈ **+298 kB** (~+100 kB gzip). É o preço da lib, e é informação para o checkpoint do Plan 07
— **não** motivo para reabrir a decisão travada. Mitigação estrutural já existente: a página é
lazy-loaded por rota Inertia, então o peso só afeta quem abre `/mlb/anuncios/massa/{id}`; o resto
do sistema não paga nada. O Vite ainda separou a lib em chunk próprio, então ela cacheia
independente da página.

## Estado intermediário (esperado, aceito pelo plano)

Comparado a hoje, ainda faltam: paste do Excel, dropdown dos atributos `list`, range/fill handle,
badge de origem, contador do título, realce de erro local, gerar EAN-13 e remover linha.
Entram nos Plans 03-06. Nada é deployado no meio da fase — o gate de zero regressão é medido no
checkpoint do Plan 07. Por isso `colunasColaveis`/`colarNaGrade` seguem órfãos no arquivo (com
comentário marcando), aguardando o paste nativo do Plan 03.

## Verificação

- Gates estruturais (via arquivo `.cjs` — o `<verify>` inline do plano está quebrado por escaping):
  **30/30 verdes** nas 3 tasks.
- `npm run build` verde (26.89s) — prova import/CSS/JSX corretos.
- `php artisan test --filter=Phase82` **9/9** (24 assertions) — backend intocado.
- Comportamento de canvas (render, edição, overlay do portal) **não é verificável** aqui: nenhuma
  empresa com `ml_token` no banco local, sem OAuth ML. Fica para o checkpoint visual do Plan 07.

## Success Criteria

- [x] Aba ativa renderiza via `<DataEditor>` com tema ecf-*, 10 base + obrigatórios da categoria
- [x] Editar célula → estado + autosave via o mesmo `editarComSalvar`
- [x] Origem promovida de 'cliente' para 'publicador' com as chaves corretas
- [x] "10x20x30" em Altura preenche as 3 dimensões
- [x] "+ Adicionar linha" pela trailing row nativa
- [x] Diff da página limitado a imports, render e remoção dos componentes de célula

## Próximo

Plan 03 — paste nativo do Excel (`getCellsForSelection`/`onPaste`/`coercePasteValue` reusando
`normalizarTipoAnuncio`/`casarValueList`/`parseDimensoes`) + `DropdownCell` nos atributos `list` e
no Tipo. É quando `colunasColaveis`/`colarNaGrade` morrem.
