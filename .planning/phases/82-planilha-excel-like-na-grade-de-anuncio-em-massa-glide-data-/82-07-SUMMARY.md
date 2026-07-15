---
phase: 82-planilha-excel-like-na-grade-de-anuncio-em-massa-glide-data-
plan: 07
date: 2026-07-15
status: pending-checkpoint
type: execute
requirements: [SHEET2-01, SHEET2-02, SHEET2-03, SHEET2-04, SHEET2-05, SHEET2-06, SHEET2-07, SHEET2-08]
one-liner: "Varredura final, gates da fase e roteiro do checkpoint visual — a planilha está pronta, aguardando verificação humana em prod"
---

# 82-07 — Gates finais + checkpoint — Summary

## Task 1 — Varredura e gates (completa)

- **Restos da migração:** nenhum. `colunasColaveis`/`colarNaGrade`/`avisoColagem`/`onColar` não
  existem mais; `Grade`/`LinhaGrade`/`Th`/`Cell`/`CellInput`/`OrigemBadge` removidos no Plan 02.
  Imports órfãos limpos (ícones `ChevronRight`/`Store`/`Trash2` e os helpers de coerção, que agora
  são consumidos pela grade). As menções a `<table>` que restam nos comentários são **históricas de
  propósito** (explicam o que mudou e por quê).
- **Cabeçalhos atualizados:** `AnunciarMassa.jsx` agora diz que é a página dona do estado e que
  **não** desenha a grade; `GradeAnuncioGlide.jsx` explica o paradigma canvas (getCellContent lê /
  onCellsEdited escreve), a pegadinha "canvas não é DOM" (Tailwind não alcança; cor via
  `temaEcf`/`getRowThemeOverride`/`draw()`) e a dependência do `#portal`.
- **`npm run build`:** verde (31.89s).
- **`php artisan test --filter=Phase82`:** **9/9** (24 assertions).
- **Nada deployado.** Nenhum script de deploy executado, nenhum push para a VPS.

## Bundle final (fecha a incógnita A3 do research)

| Chunk | Baseline (Plan 01) | Final | Delta |
|-------|--------------------|-------|-------|
| `AnunciarMassa-*.js` | 29.90 kB | **17.57 kB** | **−12.33 kB** |
| `GradeAnuncioGlide-*.js` | — | **392.67 kB** (gzip 134.33) | +392.67 kB |
| `GradeAnuncioGlide-*.css` | — | **8.09 kB** | +8.09 kB |

A página em si **encolheu 41%** (as células JSX saíram). O custo total é ≈ **+388 kB** (~+128 kB
gzip), isolado num chunk próprio e **lazy-loaded por rota**: só paga quem abre
`/mlb/anuncios/massa/{id}`; o resto do sistema não carrega nada disso, e o chunk da lib cacheia
independente da página.

## 3 falhas em Phase75 — pré-existentes, de ambiente (medido, não presumido)

`--filter="Phase75|Phase82"` → 3 failed, 49 passed. As 3 são de `PublicarEmpresaNaoAtribuidaTest` e
`RascunhoCompanyIdImutavelTest`. **Causa real** (lida no output, não inferida):

```
cURL error 60: SSL certificate ... unable to get local issuer certificate
for https://api.mercadolibre.com/oauth/token
```

São testes que fazem **chamada real ao Mercado Livre** e esbarram em certificado SSL do PHP nesta
máquina Windows. Evidências de que não são da fase:

1. A fase **não tocou nenhum `.php` de aplicação** — `git diff --name-only 40a0a07..HEAD -- '*.php'`
   devolve só `resources/views/app.blade.php`, que é uma `<div id="portal"></div>` vazia e não pode
   mudar status HTTP de rota.
2. Os testes do Phase75 **não referenciam** `AnunciarMassa`/`GradeAnuncio`/`glide` — grep vazio.
3. A falha é de rede/TLS, não de asserção de lógica.

Baseline por worktree foi **descartado de propósito**: sem `vendor/` ele reportaria falhas
fantasmas — o mesmo desvio metodológico que o outro dev registrou no plano 80-02.

## Task 2 — Checkpoint visual: PENDENTE (bloqueante)

**Não verificável em localhost:** o banco local tem **zero** empresas com `ml_token`
(`Company::whereHas('mlToken')->count()` = 0) e não há OAuth do ML aqui — o painel vem vazio e a
grade não abre. A verificação só existe em produção.

**Nada foi deployado.** O deploy é decisão e ação do usuário (CLAUDE.md).

### Roteiro de verificação (após o deploy, em `/mlb/anuncios` → aba "Em massa")

| # | Req | O que testar | Esperado |
|---|-----|--------------|----------|
| 1 | — | A grade abre e as cores parecem do sistema | Fundo escuro `ecf-card`, seleção amarela `ecf-yellow`, sem "corpo estranho" |
| 2 | SHEET2-04 | Clicar numa célula e usar Tab, Enter e setas | Navega como Excel |
| 3 | SHEET2-01 | Arrastar sobre várias células; Ctrl+arrastar em outro bloco | Vários retângulos selecionados juntos |
| 4 | SHEET2-02 | Selecionar uma célula preenchida e arrastar a alça ↓ e → | Replica o valor nas duas direções |
| 5 | SHEET2-03 | Copiar um bloco do Excel/Sheets e Ctrl+V na grade | Preenche as células; bloco maior que a grade cria linhas |
| 6 | SHEET2-03 | Ctrl+C numa célula e Ctrl+V em outra | Copia entre células |
| 7 | SHEET2-06 | Dar duplo-clique em **Gênero** (ou outro atributo de lista) | Célula parece texto, mas abre **dropdown só com as opções válidas** |
| 8 | SHEET2-06 | Duplo-clique em **Tipo** | Dropdown com Clássico/Premium (os 2 botões antigos sumiram) |
| 9 | SHEET2-05 | Clicar no **número da linha**; Ctrl+clicar em outra | Seleciona as linhas; a toolbar mostra "N linhas selecionadas" |
| 10 | — | Com linhas selecionadas, clicar em **Gerar EAN-13** | Preenche o GTIN só das que estavam vazias, sem repetir |
| 11 | — | Com linhas selecionadas, clicar em **Remover** | Confirma e remove |
| 12 | SHEET2-07 | Apagar o Título de uma linha | Linha fica **vermelha** + `!` na coluna de status |
| 13 | SHEET2-07 | Digitar Título e esperar ~1s | Some o vermelho; status vira `✓`; recarregar a página mantém o valor (autosave) |
| 14 | SHEET2-07 | Puxar produtos do cliente e olhar as células | **Bolinha violeta** no canto; ao editar a célula, vira **âmbar** |
| 15 | SHEET2-07 | Editar o **Estoque** de um produto puxado | A bolinha muda de violeta p/ âmbar (isso **não funcionava antes** — bug corrigido) |
| 16 | — | Duplo-clique no Título | Overlay com contador `N/60`; não deixa passar do limite |
| 17 | SHEET2-07 | Digitar `10x20x30` na coluna **Altura** | Distribui em Altura/Largura/Comprimento |
| 18 | SHEET2-07 | Trocar de aba de categoria | Colunas mudam para os obrigatórios daquela categoria |
| 19 | SHEET2-07 | "Validar tudo" e depois "Publicar" | Contadores e publicação em lote iguais a antes |

### Mudanças de forma que você vai notar (deliberadas, todas registradas)

1. **A×L×C virou 3 colunas** (Altura/Largura/Comprimento) — canvas não aninha 3 inputs numa célula,
   e 3 colunas é o que torna fill handle e paste úteis nesses campos. Colar `10x20x30` continua
   funcionando (agora na digitação também).
2. **Coluna "Foto" saiu** — era placeholder decorativo; o payload sempre mandou `pictures: []`.
   Confirmado no código antes de remover.
3. **Tipo (Clás/Prem) virou dropdown** — 2 botões numa célula é o anti-padrão que SHEET2-06 pede
   para eliminar.
4. **"Gerar EAN-13" e a lixeira viraram toolbar** sobre a seleção — botão por linha não existe em
   planilha. Ganho: agem em lote.
5. **Spinners viraram glifos estáticos** (`⋯`, `↑`) — canvas não anima.
6. **O aviso "Colado: N linha(s)" sumiu** — não há substituto nativo. O feedback de um paste em
   planilha é ver as células preenchidas; erros continuam visíveis pelo realce vermelho e pela
   PublishBar.

### Pendência conhecida (fora do escopo desta fase)

A grade em massa publica anúncios **sem foto** (`montarPayloadLinha` manda `pictures: []`). É
**pré-existente** — só o wizard individual faz upload de imagem. Descoberto ao investigar a coluna
"Foto". Merece uma fase própria.

## Requisitos

| Req | Status | Onde |
|-----|--------|------|
| SHEET2-01 range multi-rect | ✅ código | `rangeSelect="multi-rect"` |
| SHEET2-02 fill handle ↓→ | ✅ código | `fillHandle` + `allowedFillDirections="any"` |
| SHEET2-03 copiar/colar Excel | ✅ código | `getCellsForSelection` + `onPaste` + `coercePasteValue` |
| SHEET2-04 teclado | ✅ código | nativo da lib (sem `onKeyDown`) |
| SHEET2-05 linha/coluna | ✅ código | `rowMarkers="clickable-number"` + `rowSelect`/`columnSelect` |
| SHEET2-06 dropdown | ✅ código | `DropdownCell` em Tipo + atributos `list` |
| SHEET2-07 zero regressão | ✅ gates | 30+ gates estruturais, build, Phase82 9/9 |
| SHEET2-08 tema ecf-* | ✅ código | `temaEcf` com os hex reais |

Todos verdes **no código e nos gates**. O que falta é a prova visual — que só existe em prod.
