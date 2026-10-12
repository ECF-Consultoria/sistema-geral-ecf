---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 02
subsystem: ui
tags: [publicador, componentes-de-identidade, acessibilidade, node-test, render-real, design-tokens]

# Grafo de dependências
requires:
  - "177-01: camada `pub-*` no `theme.extend` (35 cores, 8 raios, `font-pub`) — consumida por nome em todos os 7 componentes"
  - "`textoSeguro()` de `Components/Mlb/Publicador/BarraDaConta.jsx` (Fase 172) — reusado, não recriado"
provides:
  - "7 componentes de identidade compartilhados em `Components/Mlb/Publicador/identidade/`: GlyphDaFase, RotuloDaFase, SeloDoTipo, SeloDeStatusDaPublicacao, MapaDeCobertura, BadgeNova, TileDoProduto"
  - "`identidade.js`: `iniciais()`, `tomDoProduto()` (hue oklch estável por produto) e `normalizar()` — fonte única"
  - "`chaveDaFase()` (export nomeado de `GlyphDaFase.jsx`): contrato `1|'1'|2|'2'|'legado' → 'f1'|'f2'|'legado'|null`"
  - "Gate GATE-03 `tests/js/publicador-vocabulario-novo.test.js`: vocabulário novo fechado por arquivo, com lista-constante que as waves 3-6 acrescentam"
  - "Gate de render real `tests/js/publicador-identidade-nova-render.test.js`: 7 componentes × campo objeto/nulo/ausente"
affects: [177-04, 177-05, 177-06, 177-07, 177-08, 178, 179, 180, 181, 182]

# Rastreio técnico
tech-stack:
  added: []   # nenhum pacote instalado; `lucide-react` já era dependência
  patterns:
    - "Componente de identidade distingue por FORMA primeiro, cor depois, texto sempre — nunca por cor sozinha"
    - "Tooltip sem componente de tooltip: `title` + `aria-label` com o MESMO texto, mais a informação visível fora dele"
    - "Flag de célula calculada DENTRO do callback do `.map()` (o Rollup elimina var de escopo usada no callback)"
    - "Gate de render real com bundle montado por `await` no topo do módulo, antes do primeiro `test()`"
    - "Gate de vocabulário por lista CONSTANTE de arquivos, nunca por glob"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/identidade/identidade.js
    - resources/js/Components/Mlb/Publicador/identidade/GlyphDaFase.jsx
    - resources/js/Components/Mlb/Publicador/identidade/RotuloDaFase.jsx
    - resources/js/Components/Mlb/Publicador/identidade/SeloDoTipo.jsx
    - resources/js/Components/Mlb/Publicador/identidade/SeloDeStatusDaPublicacao.jsx
    - resources/js/Components/Mlb/Publicador/identidade/MapaDeCobertura.jsx
    - resources/js/Components/Mlb/Publicador/identidade/BadgeNova.jsx
    - resources/js/Components/Mlb/Publicador/identidade/TileDoProduto.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-identidade-nova-render.test.js
  modified: []   # nenhum arquivo existente foi tocado neste plano

key-decisions:
  - "Onde o valor prescrito pelo plano divergiu da medida do protótipo, o PROTÓTIPO venceu — 8 divergências declaradas abaixo, cada uma com arquivo:linha. A instrução do usuário é reproduzir o layout fielmente; escolher a medida medida não é 'adaptar ao sistema atual' (FID-02)"
  - "A borda do glyph é `pub-fase1` (#D4D4D8) na lista e no rodapé e `pub-accent` na marca do header: é o que as 7 instâncias do protótipo fazem. `pub-fase1-2` (#E4E4E7) é a cor da CÉLULA do mapa de cobertura, não da borda do glyph — o plano conflatou as duas"
  - "Fase ausente/objeto: o glyph desenha o retângulo tracejado, mas o `RotuloDaFase` escreve '—' em vez de 'Legado'. Inventar 'Legado' para um dado que não chegou é o mesmo erro que inventar 'Ativa' num status desconhecido"
  - "`motivo` em formato inesperado NÃO vira linha: um '—' solto abaixo do status se confundiria com 'não tem motivo'"
  - "`cobertura` é quatro booleanos nomeados e não a lista de publicações (o shape da lista só se decide na Fase 180) — divergência já declarada pelo plano"
  - "O raio e o corpo da letra do tile acompanham o lado (8/9/12 px e 10.5/11.5/20 px), porque é isso que o protótipo faz em 7 tamanhos diferentes; um raio único erraria em todos"

patterns-established:
  - "Gate novo é validado por mutação deliberada antes de ser aceito: 16 mutações escritas no arquivo real, 16 reprovadas, arquivos restaurados e conferidos por sha256"
  - "Entrada única de bundle para teste de render de vários componentes: fixture `.mjs` temporária em `tests/js/`, não barrel file em `resources/js/` (o app não ganha import que não usa)"

requirements-completed: [IDENT-01, IDENT-02, IDENT-03, IDENT-04, IDENT-05, IDENT-06, IDENT-07, IDENT-08, TOK-02, TOK-13, TOK-14, GATE-03, GATE-05, GATE-06, REND-01, REND-02, REND-03, REND-04, FID-01, FID-03, FID-04, FID-05]

# Métricas
duration: 40min
completed: 2026-10-12
---

# Fase 177 Plano 02: Componentes de identidade do layout novo — Summary

**O módulo Publicador ganhou uma casa única para as sete peças de identidade — glyph de metodologia, rótulo, tipo, status, mapa de cobertura, badge NOVA e tile de produto — todas distinguindo por FORMA antes de cor, todas sobrevivendo a campo objeto/nulo/ausente em render real, e todas trancadas por um gate de vocabulário que reprova `text-sm`, `ecf-*`, hex literal e breakpoint novo; onde o valor prescrito pelo plano divergiu da medida do protótipo, a medida do protótipo venceu, com arquivo e linha por escrito.**

## Performance

- **Duração:** ~40 min
- **Tarefas:** 3 de 3
- **Arquivos:** 10 criados, **0 modificados**
- **Commits:** `f490ab6c` (Task 1), `4f5ff553` (Task 2), `f39daf65` (Task 3) + o desta SUMMARY
- **Linhas:** 1413 adições, **0 remoções** no range `312c8bac..HEAD`

## Baselines — medidas por mim, hoje, ANTES de qualquer edição

| Suíte | Antes (medido agora) | Depois | Leitura |
|---|---|---|---|
| JS inteiro (`npm run test:js`) | **2216 · 2215 pass · 1 fail** | **2340 · 2339 pass · 1 fail** | +124 testes (63 do render + 61 do vocabulário); o fail é o mesmo, sozinho |

O número de partida bate exatamente com o que o 177-01 deixou (2216). **O único fail é o pré-existente**
`tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce recolhido (é o grupo que
mais infla)"*, de outro território e de outra fase — **não corrigido de propósito**.

PHP não foi tocado neste plano (nenhum arquivo em `app/`, `routes/` ou `tests/Feature`), então as
baselines de PHPUnit não foram re-executadas: nada que elas leem mudou.

## Tabela FID-01 — protótipo × implementado, linha a linha

Protótipo = `new_layout_publicador/README.md` §"Componentes de identidade" + a geometria **medida** nos
quatro `.dc.html`. A coluna "Implementado" é o valor **real** que está nos arquivos.

| # | Dimensão | Protótipo | Implementado |
|---|---|---|---|
| 1 | largura | glyph lista 15px¹ · marca 24px · rodapé 16px · célula do mapa 18-20px · tile 28/30/34/38/40/84px | `w-[18px]`¹ · `w-[24px]` · `w-[16px]` · `style={{width:tamanho}}` (default 20) · `style={{width:tamanho}}` (default 34) |
| 2 | altura | retângulos 10×13 / 15×18 / 9×12 · célula 20px | `h-[13px] w-[10px]` / `h-[18px] w-[15px]` / `h-[12px] w-[9px]` · `style={{height:tamanho}}` |
| 3 | grid | mapa F1/F2 × C/P em 4 células lado a lado | `flex flex-none gap-[3px]` — **nenhum `grid-cols-*` novo** |
| 4 | spacing | offset do 2º retângulo 5px/3px (lista e rodapé), 8px/6px (marca) | `left-[6px] top-[4px]` (a partir de `left-[1px] top-[1px]`, = 5/3) · `left-[5px] top-[3px]` · `left-[8px] top-[6px]` |
| 5 | padding | badge NOVA `2px 6px` · tag Catálogo `1px 6px` | `px-[6px] py-[2px]` · `px-[6px] py-[1px]` |
| 6 | gap | rótulo: glyph→texto 10px na lista, 9px na marca · status 7px · tipo 5px · nome/complemento 1px · status/motivo 2px · tipo/Catálogo 3px | `gap-[10px]` · `gap-[9px]` · `gap-[7px]` · `gap-[5px]` · `gap-[1px]` · `gap-[2px]` · `gap-[3px]` |
| 7 | tipografia — família | UI Geist (herdada do contêiner da página) | **nenhum componente declara família** — `font-pub` é do contêiner da tela (waves 3-6); declarar aqui obrigaria toda tela a repetir |
| 8 | tipografia — tamanho | fase 13px · complemento 11.5px · tipo 13px · Catálogo 10.5px · NOVA 10px · letra C/P 10.5px · status 13px · motivo 11.5px · iniciais 10.5/11.5/20px | `text-[13px]` · `text-[11.5px]` · `text-[13px]` · `text-[10.5px]` · `text-[10px]` · `text-[10.5px]` · `text-[13px]` · `text-[11.5px]` · `text-[10.5px]`/`text-[11.5px]`/`text-[20px]` |
| 9 | tipografia — peso | Clássico 400 · Premium 600 · NOVA 700 · nome da fase 500 · status 500 · letra C/P 600 · iniciais 600 | `font-normal` · `font-semibold` · `font-bold` · `font-medium` · `font-medium` · `font-semibold` · `font-semibold` |
| 10 | tipografia — tracking | NOVA `0.06em` | `tracking-[0.06em]` |
| 11 | raio | glyph 2.5/4/2px · célula 5px · badge 4px · losango 1px · barra de pausa 1px · tile 8/9/12px | `rounded-[2.5px]` `rounded-[4px]` `rounded-[2px]` · `rounded-[5px]` · `rounded-pub-badge` · `rounded-[1px]` · `rounded-[1px]` · `style={{borderRadius}}` 8/9/12 |
| 12 | bordas | glyph 1.5/1.6/1.4px · Legado tracejada `#71717A` · célula vazia `1px dashed #3F3F46` · célula cheia `1px solid` da própria cor · anel de revisão 1.6px | `border-[1.5px]` `border-[1.6px]` `border-[1.4px]` · `border-dashed border-pub-txt-4` · `border border-dashed border-pub-line-off` · `border border-pub-accent`/`border-pub-fase1-2` · `border-[1.6px] border-pub-neutral-dot border-r-transparent` |
| 13 | sombras | nenhuma nos componentes de identidade | **nenhuma classe de sombra** nos 8 arquivos |
| 14 | cores | borda do glyph `#D4D4D8`¹ (marca: `#FACC15`) · Fase 2 frente `#FACC15` · fundo do retângulo de trás `#111111` (lista) / `#0D0D0D` (rodapé)¹ · Legado `#71717A` · célula F1 `#E4E4E7`, F2 `#FACC15`, texto escuro `#0A0A0A`, letra vazia `#52525B`, borda vazia `#3F3F46` · status ok/warn/danger/neutral | `border-pub-fase1` / `border-pub-accent` · `bg-pub-accent` · `bg-pub-surface` / `bg-pub-sunken` · `border-pub-txt-4` · `bg-pub-fase1-2` · `bg-pub-accent` · `text-pub-page` · `text-pub-txt-off` · `border-pub-line-off` · `bg-pub-ok`+`text-pub-ok-txt`, `bg-pub-warn`+`text-pub-warn-txt`, `text-pub-danger`+`text-pub-danger-txt`, `border-pub-neutral-dot`+`text-pub-neutral-txt` |
| 15 | alinhamento | glyph e texto centrados na vertical; letra C/P centrada na célula; Fase 1/Legado centrados na caixa | `inline-flex items-center` · `grid place-items-center` · `inline-grid place-items-center` |
| 16 | ícones | SVG do codebase, stroke 1.4-2px | `lucide-react` (`AlertTriangle`, `Package`), `strokeWidth={1.6}` |
| 17 | badges | NOVA 10px/700 amarelo com texto `pub-page`, raio 4px | conforme linhas 5/8/9/10/11/14 |
| 18 | tabelas | — (identidade não desenha tabela) | n/a |
| 19 | estados | status nunca só por cor; célula do mapa existe/não existe | 4 formas distintas (círculo cheio · duas barras · triângulo · anel aberto) + letra/posição/borda no mapa + `aria-label` em cada célula |
| 20 | densidade / CTA | identidade não tem CTA | n/a |

¹ Os três itens marcados são **divergências do valor prescrito pelo plano**, explicadas abaixo com arquivo:linha.

## Divergências declaradas (FID-02 — a restrição concreta, por escrito)

**Regra que apliquei:** onde o plano prescreveu um valor e o protótipo mede outro, seguiu-se o
**protótipo**, com a linha como prova. A instrução do usuário de 11/10 é reproduzir o layout fielmente;
nenhuma destas trocas é "ficou mais parecido com o resto do sistema", "mantive o token `ecf-*`" nem "o
Tailwind não tem esse passo" — é medida medida no arquivo do protótipo. As oito estão aqui para serem
revertidas com uma linha cada, se o usuário preferir o valor do plano.

| # | Dimensão | Plano prescreveu | Implementei | Prova e por quê |
|---|---|---|---|---|
| D1 | cor da borda do glyph | `border-pub-fase1-2` (`#E4E4E7`) | `border-pub-fase1` (`#D4D4D8`) na lista e no rodapé; `border-pub-accent` na marca | As **7** instâncias de glyph do protótipo usam `#D4D4D8`: `Publicacoes v2.dc.html`:225/228/231, `Desempenho.dc.html`:137/142, `Visao Geral.dc.html`:324. `#E4E4E7` aparece **só** na célula "existe F1" do mapa (README:101 e `Publicacoes v2.dc.html`:569) — e lá eu uso `pub-fase1-2`. A marca é amarela por determinação do README §0 ("glyph de Fase 2 em amarelo") e de `PublicadorHeader.dc.html`:27-28. O plano conflatou a cor da célula com a cor da borda. |
| D2 | fundo opaco do retângulo de trás (Fase 2) | `bg-pub-page` ("como o protótipo faz no rodapé do modal") | `bg-pub-surface` na lista · **nenhum** na marca · `bg-pub-sunken` no rodapé | O rodapé do modal usa `background:#0D0D0D` (= `pub-sunken`), não `#0A0A0A` (`PublicadorHeader.dc.html`:159); a tabela usa `#111111` (= `pub-surface`, `Publicacoes v2.dc.html`:228); a marca **não tem fundo** no retângulo de trás (:27). O fundo existe para o traço de trás não vazar, então tem de ser a cor da superfície em que o glyph se apoia. Para a tela que apoiar o glyph em outra superfície (linha selecionada, p.ex.), existe a prop `fundo`. |
| D3 | caixa do glyph `lista` | `15×16px` | `18×18px`, com o 1º retângulo em `left-[1px] top-[1px]` | `Publicacoes v2.dc.html`:225/228/231. O **offset relativo** do 2º retângulo continua os 5px/3px do README. A caixa de 18px é o que mantém Fase 1, Fase 2 e Legado com a mesma largura na coluna Metodologia — com 15×16 as três também ficariam iguais, mas 2px mais estreitas que o protótipo. |
| D4 | padding do badge NOVA | `px-[5px] py-[1px]` | `px-[6px] py-[2px]` | `padding:2px 6px` em `Publicacoes v2.dc.html`:218 e `Visao Geral.dc.html`:322 (idênticos). O README não especifica padding. |
| D5 | padding da tag Catálogo | `px-[5px]` | `px-[6px] py-[1px]` | `padding:1px 6px` em `Publicacoes v2.dc.html`:243. |
| D6 | célula do mapa de cobertura | `h-[18px] w-[18px]`, `tamanho = 18` | `style={{width,height}}` com `tamanho = 20`, mais `border` sólida no estado "existe" | `Publicacoes v2.dc.html`:185 (20×20, raio 5px) é a **única** instância renderizada; o README declara a faixa "18–20px". Como `tamanho` é prop, não pode ser classe estática. A borda sólida no estado "existe" é do protótipo (`cell()`, :569) e é o que impede a célula cheia de ficar 2px menor que a tracejada. |
| D7 | raio e letra do tile | `rounded-pub-sub` (10px), um só | escada medida: raio 8px (≤34), 9px (≤40), 12px (acima); letra 10.5 / 11.5 / 20px | O protótipo usa raio diferente por lado: 28→8 (`PublicadorHeader`:35), 30→8 (`Desempenho`:148), 32→8 (`Visao Geral`:242), 34→8 (`PublicadorHeader`:146), 38→9 (`Publicacoes v2`:175), 40→9 (`Visao Geral`:320), 84→12 (`Publicacoes v2`:326). A própria linha 11 do FID-01 do plano diz "tile 8px" e mapeia para `rounded-pub-sub`, que vale **10px** — os dois não podem ser o mesmo valor. Para o único tile de 38px que o protótipo arredonda em 10px (`Visao Geral`:182) existe a prop `raio`. |
| D8 | gap glyph→texto do rótulo | `gap-[9px]` (header) / `gap-2` (lista) | `gap-[10px]` na lista, `gap-[9px]` na marca e no rodapé | `Publicacoes v2.dc.html`:223 (`gap:10px`) e `PublicadorHeader.dc.html`:25 (`gap:9px`). |

### As duas divergências que o PRÓPRIO plano já declarava — mantidas como estavam

| Item | Protótipo | Implementado | Restrição |
|---|---|---|---|
| IDENT-07 tooltip | "usar o componente de tooltip do codebase" | `title` nativo **mais** `aria-label` com o mesmo texto, em cada uma das 4 células | **Não existe componente de tooltip em `Components/ui/`** e esta fase **não instala pacote**. A guarda real é não deixar informação EXCLUSIVA no `title`: a célula já diz por letra (C/P), posição (F1 à esquerda), borda (sólida × tracejada) e cor; o `aria-label` entrega o texto completo a quem usa leitor de tela. Troca por tooltip rico é item da Fase 179. |
| `cobertura` como 4 booleanos | o protótipo deriva de uma lista de publicações | `{ f1_classico, f1_premium, f2_classico, f2_premium }` | O shape da lista de publicações só se decide na Fase 180. `1` também conta como `true` (PHP manda bool, mas um `tinyint` cru chegaria 1). Nenhuma medida visual muda. |

### Observações que NÃO virei decisão (ficam para quem precisar)

1. **O triângulo do "Com erro"** — segui o plano (`lucide-react` `AlertTriangle`, `strokeWidth={1.6}`,
   11×11px). O protótipo (`Publicacoes v2.dc.html`:249) desenha um triângulo **preenchido** de vermelho
   com um "!" escuro dentro, em SVG à mão. A forma (triângulo) e o tamanho são os mesmos; o
   preenchimento não. **Não troquei** porque a linha 16 do FID-01 manda usar `lucide-react` e trocar
   exigiria SVG à mão num componente compartilhado. Se o usuário quiser o preenchido, é uma linha.
2. **O anel de "Em revisão" usa `#5F5F68` no protótipo**, que **não tem token** na camada `pub-*`
   (`pub-neutral-dot` é `#71717A`, o valor que a tabela de cores do README declara para
   neutral-status). Usei o token, como TOK-15 exige. É o mesmo caso do `#18160C` que o 177-01 deixou
   anotado: hex que aparece numa tela e não está na tabela de cores do README.
3. **Nenhum componente declara `font-pub`.** A família é do contêiner da página (waves 3-6). Declarar
   em cada peça obrigaria toda tela a repetir e tornaria impossível reusar a peça em contexto que não
   seja Geist. Se a wave 3 esquecer o `font-pub` no contêiner, os componentes herdam Inter — anotado
   para quem montar o header.
4. **Fase ausente escreve "—", não "Legado".** O plano prescreve os três literais mas não diz o que
   fazer quando `fase` não chega. Decidi pelo "—" porque escrever "Legado" seria inventar informação —
   o mesmo motivo pelo qual o selo de status se recusa a inventar "Ativa".

## Realizações

1. **`identidade.js` (107 linhas)** — `iniciais()` (a `ini()` do protótipo: palavras com mais de 2
   letras, as 2 primeiras, maiúsculas; entrada inválida devolve string **vazia**, não "—"),
   `tomDoProduto()` (hash determinístico do `String(id)` em 0-359 e os dois literais `oklch` da
   `tone()`) e `normalizar()` (NFD + sem diacrítico + minúscula, a base da busca de HEAD-05).
2. **`GlyphDaFase.jsx` (119 linhas)** — a forma distingue: 1 retângulo · 2 sobrepostos · 1 tracejado.
   Três tamanhos (`lista`, `marca`, `rodape`) com a geometria de cada instância do protótipo, inclusive
   cor de borda e fundo de occlusão. `aria-hidden` sempre: quem nomeia é o rótulo. Export nomeado
   `chaveDaFase()` para o rótulo não reimplementar a normalização.
3. **`RotuloDaFase.jsx` (81 linhas)** — os literais byte a byte, com o ponto medial e o "un." com
   ponto. Sem quantidade válida (0, nulo, objeto, `'duas'`, `NaN`, ausente) o complemento é **"Combo"**
   sozinho.
4. **`SeloDoTipo.jsx` (56 linhas)** — 400 contra 600, losango de 7px rotacionado **antes** do texto só
   no Premium, tag "Catálogo" em coluna abaixo (como o protótipo). A tradução `gold_special`/`gold_pro`
   fica documentada como responsabilidade do servidor.
5. **`SeloDeStatusDaPublicacao.jsx` (96 linhas)** — as quatro formas distintas, provadas distintas por
   teste. Status desconhecido → anel aberto + o valor como chegou. `motivo` só vira linha quando é
   texto de verdade.
6. **`MapaDeCobertura.jsx` (79 linhas)** — 4 células com letra, posição, borda e cor; `title` e
   `aria-label` iguais; **toda flag calculada dentro do callback do `.map()`**, com o comentário
   explicando que o Rollup já quebrou isso neste projeto.
7. **`BadgeNova.jsx` (27 linhas)** — um badge só, sem prop de cor nem de texto.
8. **`TileDoProduto.jsx` (79 linhas)** — foto só quando é string não vazia; senão iniciais sobre o tom
   `oklch`; sem iniciais, o ícone `Package`. Documenta que o sufixo `-I.jpg` do CDN é decisão do
   servidor.

## Os dois gates — provados por MUTAÇÃO, 16 de 16 reprovaram

Teste que para de provar algo é pior que teste ausente. Cada mutação foi escrita **no arquivo real**, o
gate rodado, o arquivo restaurado, e a restauração conferida por **sha256** no fim (todos idênticos;
`git diff` dos 8 arquivos voltou vazio).

### `publicador-vocabulario-novo.test.js` — 61 testes · 12 de 12 mutações reprovadas

| Mutação | Resultado |
|---|---|
| M1 `text-[13px]` arredondado para `text-sm` (FID-03) | reprovou |
| M2 tamanho fora da lista do README (`text-[10.4px]`) | reprovou |
| M3 peso inventado (`font-black`) | reprovou |
| M4 token `ecf-*` de volta (`bg-ecf-yellow`, FID-04) | reprovou |
| M5 hex literal em vez do token (`bg-[#FACC15]`, TOK-15) | reprovou |
| M6 breakpoint novo (`md:gap-[4px]`, FID-05) | reprovou |
| M7 `oklch` recalculado fora de `identidade.js` (IDENT-08) | reprovou |
| M8 glyph reimplementado à mão em vez de importado (IDENT-08) | reprovou |
| M9 literal de IDENT-02 parafraseado ("Simples (1 unidade)") | reprovou |
| M10 `aria-label` removido, informação só no `title` (IDENT-07) | reprovou |
| M11 `textoSeguro` trocado por `String()` (a tela preta de 07/10) | reprovou |
| M12 `dangerouslySetInnerHTML` introduzido | reprovou |

### `publicador-identidade-nova-render.test.js` — 63 testes · 4 de 4 mutações reprovadas

| Mutação | Resultado |
|---|---|
| R1 quantidade interpolada sem guarda → "Combo · undefined un." | reprovou |
| R2 `foto` objeto virando `src` → `[object Object]` | reprovou |
| R3 status desconhecido inventando "Ativa" | reprovou |
| R4 guarda de tipo removida de `iniciais()` → nome objeto derruba o render | reprovou |

**Decisões de arquitetura dos gates, pelas armadilhas deste projeto:**

- **Lista CONSTANTE, não glob** — glob pegaria arquivo que ainda não nasceu e o gate passaria por
  omissão. As waves 3-6 **acrescentam** os arquivos do header ao `ARQUIVOS_NOVOS`.
- **`lerSemComentarios` em toda leitura** — a prosa em pt-BR deste projeto cita os próprios hex do
  protótipo ("o da frente preenchido `#FACC15`"); um gate cru reprovaria pelo motivo errado. A `M5`
  prova que o gate pega o hex **no código** mesmo com hex nos comentários.
- **GATE-05 respeitado** — a asserção de atributo usa `/aria-hidden=/`, com o `=`. Sem ele a palavra
  casaria dentro de um comentário e a asserção seria vazia.
- **REND-03** — o bundle é montado por `await` **no topo do módulo**, antes do primeiro `test()`. O
  `after()` deste projeto já apagou o arquivo temporário embaixo dos testes seguintes sem nenhum
  teste falhar.
- **Entrada de bundle é fixture de teste**, criada e apagada em `tests/js/`. Não criei barrel file em
  `resources/js/`: o projeto não usa e o app passaria a importar o que não precisa.
- **`#FACC15` conferido nos dois lados da fronteira**: tem de estar no `tailwind.config.js` e **não**
  pode estar em nenhum componente. Não mutei o `tailwind.config.js` para provar o primeiro lado — é
  arquivo do 177-01, alimenta o build de todo o sistema e a árvore é compartilhada com outro dev ativo.

## Verificação

| Item | Resultado |
|---|---|
| `node --test tests/js/publicador-identidade-nova-render.test.js` | **63 pass · 0 fail** |
| `node --test tests/js/publicador-vocabulario-novo.test.js` | **61 pass · 0 fail** |
| `npm run test:js` | **2340 · 2339 pass · 1 fail** — o mesmo fail pré-existente da baseline, sozinho |
| `git diff --stat b36037f9..HEAD -- .../identidade tests/js` | 11 arquivos, **só adição** (os 10 meus + o gate de tokens do 177-01) |
| `git diff --stat b36037f9..HEAD -- database app/Support/Publicador` | **vazio** |
| `git diff --name-only b36037f9..HEAD -- Components/Publicador/Mesa Pages/Mlb/Publicador/Editor.jsx` | **vazio** (REG-03 — território do outro dev) |
| `git diff --numstat 312c8bac..HEAD` | 1413 adições, **0 remoções**; nenhum arquivo existente modificado |
| `package.json` / `package-lock.json` / `composer.json` / `composer.lock` no range | **intactos** — nenhum pacote instalado (T-177-SC) |
| `tailwind.config.js` / `app.blade.php` / `app/` / `routes/` / `tests/Feature` nos meus commits | **não aparecem** — nada do 177-01 nem do 177-03 |
| Temporários do gate de render (`tests/js/.identidade-*`) | **nenhum sobrando**; `git status` sem untracked meu |
| `npm run build` | **não executado** — é do plano 177-09, e rodar aqui escreveria em `public/build` |

## Desvios do plano

### 1. [Rule 1 — correção de valor] Oito medidas prescritas pelo plano divergiam do protótipo

Documentadas uma a uma na tabela de divergências acima (D1-D8), cada uma com arquivo e linha do
protótipo. Resumo: cor da borda do glyph, fundo de occlusão da Fase 2, caixa do glyph `lista`, padding
do badge NOVA, padding da tag Catálogo, tamanho/borda da célula do mapa, raio e letra do tile, gap do
rótulo. **Nenhuma é adaptação ao sistema atual** — todas vão na direção do protótipo, e cada uma é
reversível em uma linha se o usuário preferir o valor do plano.

### 2. Commits por tarefa em vez do commit único do `<output>`

O `<output>` descreve um commit único. Commitei por tarefa (`f490ab6c`, `4f5ff553`, `f39daf65` e este),
conforme o protocolo de commit atômico, e com a mensagem do plano preservada no sentido. Pathspec no
`add` **e** no `commit`, mensagem por `-F`, nenhum `git add -A`/`stash`/`reset`/`checkout`/`--amend` —
a árvore é compartilhada com outro dev ativo.

### 3. Dois acréscimos de API não pedidos pelo plano

- **`fundo` em `GlyphDaFase`** (default por tamanho): sem ela, uma tela que apoie o glyph de Fase 2
  numa superfície diferente (linha selecionada `pub-row-sel`, p.ex.) teria de reimplementar o glyph —
  o oposto de IDENT-08. Duas linhas.
- **`raio` em `TileDoProduto`** (default pela escada medida): existe exatamente um tile no protótipo
  que foge da escada (38px com raio 10px, `Visao Geral.dc.html`:182).

## Pendências e observações para as waves seguintes

1. **`ARQUIVOS_NOVOS` precisa crescer.** Os planos 177-04/05/06 têm de **acrescentar** `PublicadorHeader.jsx`
   e os componentes do header à constante no topo de `publicador-vocabulario-novo.test.js`. Se não
   acrescentarem, o gate passa por omissão nesses arquivos — e isso não é detectável pelo próprio gate.
2. **O contêiner da tela tem de aplicar `font-pub`.** Nenhum componente de identidade declara família
   (item 3 das observações). Sem o `font-pub` no contêiner, as peças herdam Inter.
3. **`#5F5F68` (anel de "Em revisão") e `#18160C` (linha marcada, herdado do 177-01) não têm token.**
   Os dois são hex que aparecem numa tela do protótipo e não constam da tabela de cores do README. Quem
   redesenhar a tabela de Publicações precisa decidir: acrescentar os tokens à camada (adição, o gate
   atual não impede) ou aceitar o valor vizinho que já tem token.
4. **A tradução dos dados é do servidor, em três lugares**, e está documentada no docblock de cada
   componente: `gold_special`/`gold_pro` → "Clássico"/"Premium" (`SeloDoTipo`); `active|paused|under_review|closed`
   → `ativa|pausada|em_revisao|com_erro` (`SeloDeStatusDaPublicacao`, Fase 180); sufixo `-I.jpg` da foto
   (`TileDoProduto`, `ProgramasPublicadorService::miniaturaDaFoto`).
5. **Nada visual foi conferido.** Esta fase não produziu tela: produziu peças e dois gates. A prova que
   tenho é o HTML gerado pelo `react-dom/server` nos 63 testes de render. **Não vi nenhuma tela
   renderizada — a conferência visual é do usuário, em produção, na conta 459**, e só faz sentido a
   partir da wave que montar o header.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano.

- **T-177-04 (`mitigate`) coberto:** `tipo` e `motivo` passam por `textoSeguro()`; `quantidade` por
  guarda de tipo própria; `foto` só é usada quando `typeof foto === 'string'`. O veto a
  `dangerouslySetInnerHTML` é conferido pelo gate (M12 reprovou).
- **T-177-05 (`mitigate`) coberto:** render real dos 7 componentes com campo objeto, nulo e ausente, e
  os três vetos de saída (`[object Object]`, `undefined`, `NaN`) em **toda** asserção de render — o
  helper `renderizar()` aplica os três sempre, então nenhum caso novo pode esquecê-los.
- **T-177-06 (`accept`) inalterado:** a URL do CDN do ML continua pública e já exibida hoje; `alt=""`
  mantido.
- **T-177-SC vazio por construção:** nenhum pacote instalado, `package.json`/`package-lock.json`
  intactos no range. `lucide-react` já era dependência.

## Known Stubs

Nenhum. Os 7 componentes são peças completas e nenhum deles depende de dado que não existe: todos
recebem por prop o que a tela decidir passar, e todos têm comportamento definido para a prop ausente.
Nenhuma tela foi ligada a eles neste plano — por desenho (o plano diz "o que NÃO entra aqui é tela").

## Self-Check: PASSED

- Os 8 arquivos de componente, os 2 de teste e esta SUMMARY existem em disco.
- Os commits `f490ab6c`, `4f5ff553` e `f39daf65` existem no `git log`.
- `git diff` dos 8 componentes depois das 16 mutações: **vazio** (restauração conferida por sha256).
- Nenhum arquivo existente foi modificado: `git diff --numstat 312c8bac..HEAD` traz 10 arquivos, todos
  `N/0`.
