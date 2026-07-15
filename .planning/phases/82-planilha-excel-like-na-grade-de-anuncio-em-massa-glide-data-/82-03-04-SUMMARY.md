---
phase: 82-planilha-excel-like-na-grade-de-anuncio-em-massa-glide-data-
plan: 03-04
date: 2026-07-15
status: complete
type: execute
requirements: [SHEET2-01, SHEET2-02, SHEET2-03, SHEET2-04, SHEET2-05, SHEET2-06]
one-liner: "Paste do Excel, dropdown de valores válidos, range multi-rect, fill handle bidirecional, teclado nativo e toolbar de lote"
commits:
  - 27b7d1d feat(82-03/04) paste do Excel, dropdown, range, fill handle e toolbar
---

# 82-03 + 82-04 — Capacidades de planilha — Summary

Os dois planos foram executados no mesmo commit: ambos reescrevem `GradeAnuncioGlide.jsx` e
separá-los renderia um commit vazio (o 82-04 não tem arquivo próprio).

## SHEET2-03 — copiar e colar

- `getCellsForSelection={true}` habilita o **Ctrl+C** — copy vem **desligado** por padrão na lib
  (Pitfall 4 do research). Sem isso, copiar não faria nada e pareceria bug.
- `onPaste={true}` intercepta o **Ctrl+V** e faz o split de TSV/CSV do Excel/Sheets.
- `coercePasteValue` porta o `aplicarCelula` que vivia dentro do `colarNaGrade`, **reusando as
  funções puras** do Plan 01 — nenhuma regra de coerção foi reescrita: `casarValueList` casa sem
  acento/caixa, `normalizarTipoAnuncio` traduz Clássico/Premium, e texto de Tipo não reconhecido
  **não sobrescreve** (mesmo comportamento tolerante de hoje).
- Colar um bloco mais alto que a grade cria as linhas que faltam, como o `colarNaGrade` fazia.
- `copyData` preenchido nas células custom — sem ele o Ctrl+C nelas sairia vazio.

## SHEET2-06 — dropdown de valores válidos

`Tipo` e atributos `value_type=list` viram `dropdown-cell`: **parecem texto na grade** e abrem
dropdown **só com as opções válidas** ao editar — exatamente o pedido ("aparência de planilha,
dropdown ao editar"). Substitui os 2 botões Clás/Prem e o `<select>` sempre visível.

Grava o `name` da opção (string), como hoje — `montarPayloadLinha` monta `{id, value_name}` a
partir disso. **Não** trocar para `value_id`: mudaria o payload do ML = regressão.

`useExtraCells` **não existe** neste pacote (o research provou lendo o source); o pacote exporta
`DropdownCell` direto.

## SHEET2-01/02/04/05 — interação

- `rangeSelect="multi-rect"` — vários retângulos com Ctrl.
- `fillHandle` + `allowedFillDirections="any"` — o `"any"` é o que dá o **vertical E horizontal** do
  requisito. **Sem `onFillPattern`**: o padrão já replica como o Excel, e o fill escreve pelo mesmo
  `onCellsEdited`, então o autosave por linha dispara sozinho.
- **SHEET2-04 é herdado**: Tab/Enter/setas são nativos. Nenhum `onKeyDown` custom — seria
  reimplementar o que já existe e brigaria com o nativo. Prova fica no checkpoint visual.
- `rowMarkers="clickable-number"` — o `"number"` é **só decorativo** e não seleciona nada
  (Pitfall 6). Mais `rowSelect="multi"` e `columnSelect="multi"`.
- `gridSelection` controlado, porque a toolbar precisa ler as linhas marcadas.

## Toolbar de lote

DOM/Tailwind acima do canvas (fora do canvas, então tokens `ecf-*` funcionam). Mostra a contagem e
duas ações, desabilitadas sem seleção:

- **Gerar EAN-13**: só nas linhas sem GTIN. O `Set` de GTINs é alimentado **dentro do laço** —
  senão 2 linhas do mesmo lote sairiam com o mesmo código (o `useMemo` sozinho não basta em lote).
- **Remover**: confirma (é destrutivo e agora em lote) e delega para `onRemoverLinha` por `uid`.

Publicar/validar **não** entram aqui: a `PublishBar` segue dona da publicação.

## Limpeza

`colunasColaveis`, `colarNaGrade`, `avisoColagem` e o JSX do aviso removidos (120 linhas) — **só
agora**, com o paste nativo no lugar, conforme a regra da fase. As funções puras seguem intactas em
`gradeMassaUtils.js` (gate verifica). Imports órfãos limpos.

## Decisão registrada para o checkpoint

O aviso textual "Colado: N linha(s)" / "Tipo X não reconhecido" **não volta**: não há substituto
nativo, o feedback de um paste em planilha é ver as células preenchidas, e os casos de erro seguem
visíveis (valor de tipo não reconhecido não é gravado; obrigatório vazio acende o realce vermelho).

## Verificação

- Gates (via arquivo `.cjs`): **todos verdes**, incluindo os de não-regressão (props dos planos
  anteriores preservadas; ciclo de vida da página intacto).
- `npm run build` verde. `php artisan test --filter=Phase82` **9/9**.
- Bundle: `AnunciarMassa` 19.53 → **17.57 kB**; `GradeAnuncioGlide` 301 → **390 kB** (DropdownCell).
