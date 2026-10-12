---
phase: 178-modal-nova-publicacao
plan: 02
subsystem: ui
tags: [react, publicador, modal, fidelidade-de-layout, tokens-pub, busca-sem-acento, mutacao, render-real]

requires:
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "Os 7 componentes de identidade (TileDoProduto, GlyphDaFase com o tamanho `rodape`, identidade.js com normalizar()), os tokens `pub-*`/`font-pub*`/`rounded-pub-*` do tailwind.config.js, o molde GRUPO_RECENTES e o padrão de export nomeado para render real fora do portal"
  - phase: 178-modal-nova-publicacao (plano 01)
    provides: "O contrato do endpoint GET mlb.anuncios.publicador.nova-publicacao — {total, grupos[3]} com item de 6 campos escalares"
provides:
  - "novaPublicacao.js — GRUPOS (os 3 literais de tela), VAZIO, RODAPE, PLACEHOLDER, CARREGANDO, ERRO, filtrarGrupos() e normalizarPayload()"
  - "PainelNovaPublicacao.jsx — o corpo do modal (cabeçalho de 60px, lista de 420px, item de 50px, vazio e rodapé), componente PURO e export nomeado"
  - "TileDoProduto: a prop opcional `letra` (adição pura; ausente = escada intacta)"
  - "tests/js/publicador-modal-nova-publicacao.test.js — 94 casos, render real elemento por elemento, provados por 26 mutações"
affects: [178-03-casca-do-modal, 178-04, 179-visao-geral, 180-busca-global]

tech-stack:
  added: []
  patterns:
    - "Gate de classe por CONJUNTO do elemento (parte o HTML por elemento, extrai a tag, compara a lista de classes por igualdade) em vez de `html.includes('classe')`"
    - "Localizar o elemento do gate por posição estrutural ou texto exato — nunca pela medida que está sendo conferida"
    - "Detector de MUTAÇÃO RUIM: travar a contagem de ocorrências do alvo antes de aplicar (pega o alvo que também vive em comentário)"
    - "Lookup de estilo por comparação explícita em vez de indexar objeto literal com texto de fora (`'__proto__'` devolveria Object.prototype)"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/header/novaPublicacao.js
    - resources/js/Components/Mlb/Publicador/header/PainelNovaPublicacao.jsx
    - tests/js/publicador-modal-nova-publicacao.test.js
  modified:
    - resources/js/Components/Mlb/Publicador/identidade/TileDoProduto.jsx
    - tests/js/publicador-identidade-nova-render.test.js
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "O protótipo `:146` mede `font-size:11px` no tile de 34px e a escada do TileDoProduto devolve 10.5px para `lado <= 34`: O PROTÓTIPO GANHOU, e a prop opcional `letra` nasceu (adição pura, 2 linhas removidas, a escada e todos os defaults idênticos)."
  - "`normalizar` é aplicada CAMPO POR CAMPO, não sobre um template literal: `normalizar(`${nome} ${sku}`)` com campo-objeto viraria '[object Object]' e buscar \"object\" acharia o produto."
  - "`normalizar(consulta).trim()` em vez de `normalizar(String(consulta).trim())`: consulta-objeto filtraria a lista inteira por um termo que ninguém digitou."
  - "Item sem destino usável APARECE, desabilitado (`disabled` + `aria-disabled` + `title` + `opacity-40`). Esconder um produto de uma lista que se diz completa é pior que mostrá-lo dizendo por que não clica."
  - "`cursor-pointer` no item e `type=\"text\"` no input: duas medidas em que o protótipo venceu a tabela FID-01 do plano."
  - "O gate compara a lista de classes por IGUALDADE DE CONJUNTO. Isso é proposital e obriga a wave 3 a atualizar os conjuntos se acrescentar classe a estes elementos — é o preço de nenhuma mutação passar."

metrics:
  duration: ~95min
  completed: 2026-10-12
  tasks: 3
  arquivos_criados: 3
  arquivos_modificados: 3
  testes_novos: 107
  mutacoes: 26
  medidas_fid01: 20

requirements-completed: [NOVA-01, NOVA-02, NOVA-03, NOVA-04, NOVA-05, NOVA-06]
---

# Fase 178 Plano 02: o corpo do modal "Nova publicação" Summary

**Os três grupos do "próximo passo" desenhados medida por medida do protótipo — cabeçalho de 60px com
input de 16px e tecla `Esc`, lista rolável de 420px, item de 50px com tile de 34px / nome 13,5px /
SKU em Geist Mono / CTA em pill com os três tons, o vazio "Nenhum produto encontrado" e o rodapé com
o glyph de Fase 2 — mais a busca local que ignora acento e caixa sobre nome E SKU.**

## Performance

- **Duração:** ~95 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 3 criados, 3 modificados
- **Commits:** 4 (RED, GREEN, o corpo, o gate)

---

## ⚠️ O QUE A WAVE 3 CONSOME — nomes exatos, para não adivinhar

```js
// resources/js/Components/Mlb/Publicador/header/novaPublicacao.js  (módulo PURO)
export const GRUPOS;       // [{chave, rotulo, hint, cta, tom}] — 3 itens, NESTA ordem
export const VAZIO;        // 'Nenhum produto encontrado'
export const RODAPE;       // a frase de NOVA-06, byte a byte
export const PLACEHOLDER;  // 'Qual produto você quer publicar?'
export const CARREGANDO;   // 'Carregando…'
export const ERRO;         // 'Não foi possível carregar os produtos agora. Tente de novo em instantes.'
export function normalizarPayload(dados);              // → {total: number|null, grupos: [{chave, itens}]}
export function filtrarGrupos({ grupos, consulta });   // → [{chave, rotulo, hint, cta, tom, itens}]

// resources/js/Components/Mlb/Publicador/header/PainelNovaPublicacao.jsx
export function PainelNovaPublicacao({ … });   // export NOMEADO
export default PainelNovaPublicacao;           // o MESMO componente (há gate que prova)
```

**As props do painel** (todas opcionais, todas com default que não lança):

| prop | tipo | o que faz |
|---|---|---|
| `consulta` | string | o texto do input (`value`) |
| `aoConsultar` | função | o `onChange` do input |
| `grupos` | array | a saída de `filtrarGrupos()` — **já** na ordem da tela, **já** sem grupo vazio, **já** com rótulo/hint/cta/tom |
| `carregando` | boolean | mostra `CARREGANDO` e **não** desenha lista |
| `erro` | boolean | mostra `ERRO` (perde para `carregando`) |
| `aoEscolher` | função | recebe o **item inteiro**. ⚠️ Quem NAVEGA é a wave 3 — não há `router.get` aqui |
| `inputRef` | ref | para a casca focar o campo na abertura |

**O que a wave 3 ainda tem de fazer:** a casca (`Dialog` de 620px, `top: 110px`, backdrop
`rgba(0,0,0,0.65)`, raio 16px, `shadow-pub-modal`), o estado (`consulta`, `carregando`, `erro`), a
chamada ao endpoint (**uma por montagem**, nunca por tecla), os atalhos ⌘K/Ctrl+K/N/Esc, e a
navegação no `aoEscolher`.

### ⚠️ Aviso para a wave 3: o gate compara classe por IGUALDADE DE CONJUNTO

Não é `includes`. **Classe a mais reprova.** Se a casca acrescentar classe a qualquer um dos
elementos abaixo, o conjunto esperado tem de ser atualizado no mesmo commit — e **endurecido, nunca
apagado**. Os conjuntos vivem na constante `FID` de `tests/js/publicador-modal-nova-publicacao.test.js`.

**Contagens que o gate travou** (vão mudar quando a casca entrar):

| o que | quantas | como o gate acha |
|---|---|---|
| `div` | cabeçalho = `[0]`, corpo = `[1]`, rodapé = o **último** | posição estrutural |
| `div` com classe exatamente `flex flex-col` | **3** (um por grupo cheio) | igualdade de conjunto |
| `svg` | **1** | contagem |
| `input` | **1** | contagem |
| `>Esc<` | **1** | texto exato |
| títulos de grupo (`span` com `span` de hint aninhado) | **3** | regex de aninhamento |
| `button` (itens) | 1 por item | regex `<button…>…</button>` |
| rodapé (`bg-pub-sunken px-[16px] py-[12px]`) | **1** | contagem |
| retângulos do glyph (`h-[12px] w-[9px] rounded-[2px] border-[1.4px]`) | **2** | contagem — é a FORMA da Fase 2 |

⚠️ O gate também localiza o **CTA** como o **último `span`** do interior do item e o **tile** como o
**primeiro**. Se a casca inserir `span` no item, esses índices mudam.

---

## ⚠️ FID-01 — protótipo × implementado, linha por linha

Todas as medidas foram **medidas em `new_layout_publicador/PublicadorHeader.dc.html`**, não copiadas
da tabela do plano. Nas 20 linhas, 18 bateram com a tabela e **2 foram vencidas pelo protótipo**.

| # | Elemento | Protótipo (arquivo:linha) | Implementado |
|---|---|---|---|
| 1 | cabeçalho | `display:flex;align-items:center;gap:12px;padding:0 16px;height:60px;border-bottom:1px solid #1F1F1F` (**:135**) | `flex h-[60px] items-center gap-[12px] border-b border-pub-line-subtle px-[16px]` |
| 2 | ícone de busca | svg 18×18, `viewBox 0 0 16 16`, `stroke:#A1A1AA`, `stroke-width:1.6`, linecap round (**:136**) | `<Search size={18} strokeWidth={2.4} aria-hidden="true" className="flex-none text-pub-txt-2" />` — **2.4 = 1.6 × 1,5** (viewBox 16 → 24 do Lucide) |
| 3 | input | `flex:1;height:100%;border:none;background:transparent;color:#F4F4F5;font-family:inherit;font-size:16px;outline:none`, placeholder literal (**:137**) | `h-full flex-1 border-none bg-transparent text-[16px] text-pub-txt outline-none placeholder:text-pub-txt-4` + `type="text"` ⚠️ |
| 4 | tecla Esc | mono `11px`, `padding:2px 6px`, borda `1px #2E2E2E`, raio `5px`, cor `#A1A1AA` (**:138**) | `flex-none rounded-[5px] border border-pub-line-strong px-[6px] py-[2px] font-pub-mono text-[11px] text-pub-txt-2` |
| 5 | corpo | `max-height:420px;overflow-y:auto;padding:8px` (**:140**) | `max-h-[420px] overflow-y-auto p-[8px]` |
| 6 | grupo | `display:flex;flex-direction:column` (**:142**) | `flex flex-col` |
| 7 | título do grupo | `display:flex;align-items:center;justify-content:space-between;padding:10px 10px 6px;font-size:11px;font-weight:600;letter-spacing:0.08em;color:#71717A` (**:143**) | `flex items-center justify-between px-[10px] pb-[6px] pt-[10px] text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4` |
| 8 | hint do grupo | `font-weight:500;letter-spacing:0;color:#52525B` (**:143**, span interno) | `font-medium tracking-[0] text-pub-txt-off` |
| 9 | item | `white-space:nowrap;…;gap:12px;width:100%;height:50px;padding:0 10px;border:none;border-radius:10px;background:transparent;color:#F4F4F5;cursor:pointer;text-align:left`; hover `#1A1A1A` (**:145**) | `flex h-[50px] w-full items-center gap-[12px] whitespace-nowrap rounded-pub-sub border-none bg-transparent px-[10px] text-left text-pub-txt cursor-pointer hover:bg-pub-hover-3` ⚠️ |
| 10 | tile | `34×34`, raio `8px`, `display:grid;place-items:center`, **`font-size:11px`**, peso 600, `flex:none` (**:146**) | `<TileDoProduto id nome foto tamanho={34} letra="text-[11px]" />` ⚠️ |
| 11 | coluna nome/meta | `display:flex;flex-direction:column;gap:2px;flex:1;min-width:0` (**:147**) | `flex min-w-0 flex-1 flex-col gap-[2px]` |
| 12 | nome | `font-size:13.5px;font-weight:500` (**:148**) | `truncate text-[13.5px] font-medium` (o `truncate` é adição declarada) |
| 13 | meta | `font-size:11.5px;color:#8A8A90`, SKU em `'Geist Mono'` `11px`, separador literal ` · ` (**:149**) | `text-[11.5px] text-pub-txt-3` + `<span className="font-pub-mono text-[11px]">{sku}</span>` + `{' · '}` + nota |
| 14 | CTA (pill) | `font-size:12px;font-weight:600;padding:5px 10px;border-radius:7px` (**:151**) | `flex-none rounded-pub-control-sm px-[10px] py-[5px] text-[12px] font-semibold` |
| 15 | CTA — tons | `ready`: `rgba(250,204,21,0.14)` / `#FACC15` · `none`: `#1F1F1F` / `#F4F4F5` · `draft`: `#1F1F1F` / `#A1A1AA` (**:213**) | `accent`: `bg-pub-accent/[0.14] text-pub-accent` · `solido`: `bg-pub-line-subtle text-pub-txt` · `discreto`: `bg-pub-line-subtle text-pub-txt-2` |
| 16 | vazio | `padding:36px;text-align:center;font-size:13px;color:#8A8A90` (**:156**) | `p-[36px] text-center text-[13px] text-pub-txt-3` + o literal `VAZIO` |
| 17 | rodapé | `display:flex;align-items:center;gap:8px;padding:12px 16px;border-top:1px solid #1F1F1F;background:#0D0D0D;font-size:12px;color:#8A8A90` (**:158**) | `flex items-center gap-[8px] border-t border-pub-line-subtle bg-pub-sunken px-[16px] py-[12px] text-[12px] text-pub-txt-3` |
| 18 | glyph do rodapé | caixa 16×16, 2 retângulos 9×12, raio 2px, borda 1.4px `#D4D4D8`, trás `#0D0D0D`, frente `#FACC15`, offsets 0/0 e 5/3 (**:159**) | `<GlyphDaFase fase={2} tamanho="rodape" />` — **REUSADO** |
| 19 | frase | literal (**:160**) | a constante `RODAPE`, byte a byte, com o ponto final |
| 20 | tipografia | nada de 14px / escala nomeada | só `text-[11px]`, `[11.5px]`, `[12px]`, `[13px]`, `[13.5px]`, `[16px]` — provado por varredura do HTML renderizado |

### ⚠️ As 3 medidas em que o PROTÓTIPO venceu a tabela FID-01 do plano

Cada uma com `arquivo:linha`, e todas resolvidas **a favor do protótipo**.

| # | A tabela do plano dizia | O protótipo diz | Decisão |
|---|---|---|---|
| **10** | `letra="text-[11px]"` **se** `:146` medisse 11px, "se medir 10.5px, o protótipo ganha e a prop NÃO nasce" | `PublicadorHeader.dc.html:146` → **`font-size:11px`** no tile de 34px. E `:54` confirma 11px no tile de 30px do dropdown (onde o 177-06 escreveu à mão) | **Mediu 11px → a prop `letra` NASCEU.** A escada do `TileDoProduto` devolve `text-[10.5px]` para `lado <= 34` e está certa nas telas onde foi medida (`Visao Geral.dc.html:242`) — e errada nesta |
| **9** | a linha não listava `cursor` | `PublicadorHeader.dc.html:145` → **`cursor:pointer`** explícito no botão do item | Entrou `cursor-pointer`. O default do navegador para `<button>` é `cursor: default`, então sem a classe o cursor divergiria do desenho. ⚠️ O item equivalente do dropdown de empresa (177-06) **não** tem a classe — mas "o componente que já existe faz parecido" é justificativa vetada por FID-02 |
| **3** | a linha não mencionava `type` | `PublicadorHeader.dc.html:137` → `<input>` **sem `type`** (= `text`) | Entrou `type="text"`, e **não** `type="search"` como no input do dropdown (177-06): `search` acrescenta o botão nativo de limpar no Chrome, que o desenho não tem |

---

## Divergências DECLARADAS (restrição concreta, não preferência)

Todas estão escritas no topo de `PainelNovaPublicacao.jsx`, para a próxima pessoa não "consertar".

### 1. `carregando` e `erro` não existem no protótipo

A lista do protótipo é um **mock constante** (`this.PRODS`), então não há estado de carga nem de
falha. Com fetch real os dois existem, e **lista vazia em silêncio é proibida** — é a mesma
disciplina do dropdown de empresa (`SeletorDeEmpresaDoHeader.jsx`, `PainelDeEmpresas`). As duas
frases usam a **MESMA geometria do vazio** (`:156`), para não haver um segundo desenho de "nada
aqui". `carregando` vence `erro`, e nenhum dos dois desenha lista.

### 2. `truncate` no nome do produto (`:148`)

O protótipo tem `white-space:nowrap` no botão e nome longo estouraria a caixa de 620px do modal. É a
mesma adição defensiva do `flex-none` do plano 177-04. A meta (`:149`) **não** recebeu `truncate` —
ali o plano não pediu e o protótipo não resolve.

### 3. `flex-none` no ícone de busca, na tecla `Esc` e no CTA

O protótipo declara `flex:none` só no tile (`:146`). Sob `white-space:nowrap` e com a coluna do meio
em `flex-1`, sem `flex-none` os três encolhem — e o dado dos três é curto e fixo no protótipo, que
por isso não precisa declarar.

### 4. `placeholder:text-pub-txt-4` no input

O protótipo **não declara** cor de placeholder (fica a do navegador). É o mesmo token que o input do
dropdown de empresa usa (177-06), para os dois campos de busca do header não divergirem.

### 5. O que NÃO virou classe: `font-family: inherit` do input (`:137`)

O **preflight do Tailwind v3 já aplica** `font-family: inherit` a `input`/`button`/`textarea`. O
protótipo precisa declarar porque é HTML cru, sem preflight. O input do dropdown de empresa (cujo
protótipo `:46` também declara) seguiu o mesmo caminho na 177-06.

### 6. ⚠️ Divergência de CONTEÚDO herdada da wave 1 — **FICA EM ABERTO PARA O USUÁRIO**

O protótipo escreve `"Fase 1 publicada · Clássico e Premium"` na nota do grupo `PRONTOS PARA FASE 2`.
O que o sistema mostra hoje é **`"Fase 1 publicada · {n} no ar"`**.

Isto **não é escolha desta wave**: é restrição do servidor, declarada e aceita no planejamento. O
dado existe (`anuncios[].listing_type_id`), mas a tradução `gold_special → "Clássico"` /
`gold_pro → "Premium"` é literal de UI, e o contrato do item proíbe array. As duas saídas possíveis
(ambas mudam o CONTRATO, não o render):

1. o servidor manda um campo **escalar** novo (ex.: `"tipos": "gold_special,gold_pro"`) e o cliente
   traduz;
2. o servidor manda a nota já com o texto — o que contraria "nenhum rótulo de UI no payload".

A **geometria** da nota (11,5px, `pub-txt-3`, `pub-mono` no SKU, separador ` · `) está cumprida
intacta. ⚠️ **Pergunta para o usuário, não resolvida aqui.**

---

## ⚠️ A prova por mutação — 26 mutações, 26 reprovações

Rodadas em **12/10/2026** no **código real** (`PainelNovaPublicacao.jsx`, `novaPublicacao.js`,
`TileDoProduto.jsx`), com restauração por cópia pristina **conferida por `sha256`** depois de cada
uma, e para cada uma registrados: (a) que o padrão casou, (b) **QUAL linha mudou**, (c) **quais casos
caíram**.

| # | mutação | arquivo | linha | casos que caíram (amostra) |
|---|---|---|---|---|
| 1 | `h-[60px]` → `h-16` (cabeçalho) | Painel | L177 | `1 — o cabeçalho (:135) tem exatamente as classes medidas` |
| 2 | `text-[16px]` → `text-lg` (input) | Painel | L190 | `3 — o input de 16px…` + a varredura de escala nomeada + o GATE-03 |
| 3 | `max-h-[420px]` → `max-h-96` | Painel | L199 | `5 — o corpo rolável de 420px (:140)` |
| 4 | `p-[8px]` → `p-2` | Painel | L199 | `5 — o corpo rolável de 420px (:140)` |
| 5 | `h-[50px]` → `h-12` (item) | Painel | L115 | `9 — o botão do item (:145)` + `item COM capa` |
| 6 | `gap-[12px]` → `gap-3` **só no item** | Painel | L115 | `9 — o botão do item (:145)` + `item COM capa` |
| 7 | `rounded-pub-sub` → `rounded-lg` **só no item** | Painel | L115 | `9 — o botão do item (:145)` + `item COM capa` |
| 8 | `tracking-[0.08em]` → `tracking-wider` | Painel | L214 | `7/8 — os 3 títulos: rótulo e hint` |
| 9 | `text-[11.5px]` → `text-[11px]` na meta (**prova o prefixo**) | Painel | L128 | `11/12/13 — coluna, nome com truncate e meta com o SKU em mono` |
| 10 | tirar `font-pub-mono` do SKU | Painel | L129 | `11/12/13 —…` + `o separador " · " está entre o SKU e a nota` |
| 11 | `bg-pub-accent/[0.14]` → `bg-pub-accent` no CTA | Painel | L68 | `14/15 — o CTA em pill, e os 3 tons cada um NO ITEM DO SEU GRUPO` |
| 12 | trocar a **ORDEM** dos grupos | Módulo | L57-59 | **11 casos** (a ordem de `GRUPOS`, a de `filtrarGrupos`, a dos títulos renderizados) |
| 13 | deixar o grupo **VAZIO** entrar (o conserto errado de NOVA-03) | Módulo | L168-175 | **16 casos** |
| 14 | truncar a frase do rodapé | Módulo | L66 | `a frase do rodapé (NOVA-06) — string INTEIRA` (×2, módulo e HTML) |
| 15 | trocar `GlyphDaFase` por retângulos à mão | Painel | L238 | `usa o tamanho rodape` + `NÃO redesenha a geometria do glyph` |
| 16 | tirar o `tamanho="rodape"` | Painel | L238 | `18 — o glyph de Fase 2, tamanho rodape (:159)` |
| 17 | tirar o `textoSeguro` do nome | Painel | L97 | `cada um dos 6 campos como OBJETO` + o gate de reuso |
| 18 | `Number.isFinite` → `typeof === 'number'` | Módulo | L100 | `total não finito vira null — NaN é typeof number` |
| 19 | `strokeWidth={2.4}` → `{1.6}` | Painel | L182 | `2 — o ícone de busca: 18px e strokeWidth 2.4` |
| 20 | tirar o `truncate` do nome | Painel | L127 | `11/12/13 — coluna, nome com truncate…` |
| 21 | tirar o `cursor-pointer` do item | Painel | L115 | `9 — o botão do item (:145)` + `item COM capa` |
| 22 | `rounded-[5px]` → `rounded-md` na tecla Esc | Painel | L193 | `4 — a tecla Esc em mono 11px, raio 5px (:138)` |
| 23 | `p-[36px]` → `p-9` no vazio | Painel | L202,203,205 | `grupos: [] mostra o literal uma vez` + `carregando e erro usam a MESMA geometria` |
| 24 | tirar a prop `letra` da chamada do tile | Painel | L123 | `10 — o tile de 34px com a letra de 11px (:146)` |
| 25 | ignorar a prop `letra` **dentro** do `TileDoProduto` | Tile | L85 | `a prop letra troca só o corpo da letra` + `10 —…` |
| 26 | tirar o separador ` · ` entre SKU e nota | Painel | L130 | `o separador " · " está entre o SKU e a nota (:149)` |

**Restauração final conferida por sha256:**

```
abe45b4a6ce5f8e38291bea5343cba01b7dc24cc32ff38888ea125c540196438  PainelNovaPublicacao.jsx
4af690046fc64316cc7153b65ed3e728848956850fc299dbaaacc2297cc9ba65  novaPublicacao.js
d40b1ca5b1f08062469217f47a640721534d775247884f4862bb5f63d3bd544d  TileDoProduto.jsx
```

### ⚠️ Uma mutação foi RECUSADA pelo detector — e isso é o achado da campanha

A mutação **#19** foi escrita primeiro como `strokeWidth={2.4}` → `strokeWidth={1.6}`. O detector
recusou antes de aplicar: **2 ocorrências, esperava 1**. O motivo é literalmente a armadilha que
produziu 3 "furos" na Fase 177 — **o alvo também vive num COMENTÁRIO**, o que explica a conta
`1.6 × 1,5`. Com `String.replace`/`sed` a troca teria caído no comentário e a mutação "passaria",
registrando um furo que não existe.

A correção foi tornar o alvo único (`<Search size={18} strokeWidth={2.4}`), e aí a mutação reprovou
de verdade, na **linha 182**, que é a tag JSX. **Travar a contagem de ocorrências antes de aplicar é
o que impede a prova por mutação de mentir.**

---

## ⚠️ Dois BUGS REAIS que o gate pegou (Regra 1 — corrigidos)

### 1. REND-03 é literal: o 2º bundle do esbuild tinha de subir para antes do 1º `test()`

O arquivo de teste monta **dois** bundles (`novaPublicacao.js` e `PainelNovaPublicacao.jsx`). Com o
segundo `await montarModulo(...)` escrito mais abaixo no arquivo — depois das suítes do módulo puro —
o `after()` do `node --test` **disparava durante aquele `await`** (as suítes já registradas rodam
nesse intervalo), apagava o stub do Inertia, e o esbuild morria com
`Could not resolve "@inertiajs/react"`.

Sintoma: **37 testes em vez de 94**, com `fail 1` genérico no nível do arquivo. Reproduzido neste
arquivo em 12/10/2026 e corrigido pela ordem, com o motivo escrito no código.

### 2. Grupo nulo dentro de `grupos` derrubava o painel inteiro

`renderizar({ grupos: [null] })` lançava
`TypeError: Cannot read properties of null (reading 'itens')` — **render que lança derruba a página,
não só a linha**. É exatamente a família da tela preta de 07/10.

Corrigido filtrando grupo não-objeto antes do `.map()`, e com `grupo?.chave` / `grupo?.itens` nos
três pontos de leitura. ⚠️ Vale notar que `filtrarGrupos()` **nunca** produziria isso (ela monta os
grupos a partir da constante `GRUPOS`): o bug só aparece quando a prop é montada à mão — ou seja, foi
o render adverso que pegou, e gate de leitura de fonte jamais teria pego.

### 3. (menor) `TOM_DO_CTA[tom]` virou `classesDoCta(tom)`

Indexar um objeto literal com texto vindo de fora devolve **`Object.prototype`** para `'__proto__'` —
um objeto entrando no `cn()`. Trocado por comparação explícita.

---

## A busca (NOVA-04) — e duas correções de rota contra resultado-fantasma

A busca é **local**, sobre o payload já carregado (é o que o protótipo faz em `:214`, e T-178-09
aceita: nenhuma ida à rede por tecla). Ignora acento e caixa, cobre **nome e SKU**:

| digitando | acha |
|---|---|
| `espelho` / `ESPELHO` / `bisote` / `BISOTÊ` | "Espelho Redondo Bisotê 60 cm" |
| `esp-rd` | o mesmo item, pelo SKU `ESP-RD-60` |
| `plt-beny` | "Poltrona Beny Veludo", em outro grupo |
| `  espelho  ` | igual — a consulta é `trim`ada |
| `60 cm esp` | igual — a busca atravessa a fronteira nome→SKU, como `p[0] + ' ' + p[1]` do protótipo |
| `cadeira gamer` | **nada**: `[]`, e a tela mostra "Nenhum produto encontrado" — não um grupo vazio |

**Duas divergências da prescrição literal do plano, as duas para não inventar resultado:**

1. `normalizar()` é aplicada **campo por campo** (`normalizar(nome) + ' ' + normalizar(sku)`), e
   **não** sobre `` normalizar(`${item.nome} ${item.sku}`) ``. Com template literal, um campo-objeto
   viraria `"[object Object]"` e **buscar "object" acharia o produto**. É o fantasma que o próprio
   docblock de `normalizar()` descreve.
2. `normalizar(consulta).trim()` em vez de `normalizar(String(consulta ?? '').trim())`. Com
   `String()` cru, uma consulta que chegasse como objeto viraria `"[object object]"` e **filtraria a
   lista inteira** por um termo que o usuário nunca digitou (o gate pegou: devolvia 0 grupos).
   `normalizar()` devolve `''` para não-texto, e `''` é o mesmo que "sem busca".

---

## Honestidade de dado

- `nome`, `sku` e `nota` passam por **`textoSeguro`** importado de `../BarraDaConta` (há gate que
  cobra os três por nome); `url` passa por **`textoUtil`** (vazio e objeto viram ausência).
- ⚠️ **Item sem destino usável APARECE, desabilitado.** `disabled` + `aria-disabled="true"` +
  `title` explicando + `opacity-40`. A alternativa era não renderizar — e esconder um produto de uma
  lista que se diz completa é pior que mostrá-lo dizendo por que não clica (EXMP-03 proíbe o clique
  mudo, não a presença). O `0` **não** conta como ausência: `textoUtil(0)` devolve `'0'`, que é texto.
- A `key` do item é `produto_id` quando for número finito, senão o nome, senão
  `"{chave do grupo}-{índice}"` como último recurso (para item nulo/`{}` não colidir com outro).
  **Nunca** o índice puro do `.map()`.
- O clique só chama `aoEscolher(item)`. **Nenhum `router.get` neste arquivo** — a navegação é da
  wave 3, e há gate que reprova (`/\brouter\.\w/`).

---

## Verificação

| Gate | Comando | Resultado |
|---|---|---|
| 1 | `npm run test:js` | **2966 testes / 2965 pass / 1 fail** — o fail é o **pré-existente** `estrutura-grade-glide` ("Características secundárias nasce recolhido"), **não corrigido de propósito**. Baseline era 2859/2858/1 → **+107 casos**, nenhuma regressão |
| 2 | `node --test` nos 3 arquivos desta wave | **modal: 94/94** · identidade: **64/64** · vocabulário: **162/162** |
| 3 | `git diff da54a9ee -- …/TileDoProduto.jsx \| grep '^-'` | **2 linhas**: a assinatura (ganhou `letra = null`) e o `cn()` (`medida.letra` → `corpo`). A função `escala()`, a escada e **todos** os defaults **idênticos** |
| 4 | `git diff da54a9ee -- …/publicador-identidade-nova-render.test.js \| grep '^-' \| grep assert` | **vazio** — nenhuma asserção removida (só +37 linhas) |
| 5 | `git diff --stat da54a9ee -- AppLayout.jsx Editor.jsx Components/Publicador/Mesa PublicadorHeader.jsx` | **vazio** — território de outro dev e da wave 3 |
| 6 | `git diff --stat da54a9ee -- resources/js/Pages` | **vazio** — nenhuma das 9 páginas mudou |
| 7 | `git status --short -- tests/js` | só os 3 arquivos desta wave; **nenhuma fixture órfã** (`tests/js/.*.mjs` vazio) |
| 8 | `git diff --diff-filter=D --name-only da54a9ee` | **vazio** — nenhum arquivo deletado |
| T-178-SC | `git diff --stat da54a9ee -- package.json package-lock.json composer.json composer.lock` | **vazio** — **nenhum pacote instalado** |
| 9 | `npm run build` | **verde**, 34,44s |
| 10 | manifest por JSON (`node --input-type=commonjs`) | 480 entradas; `resources/js/app.jsx → assets/app-BN8PlVU6.js`; as **8** páginas do Publicador seguem no manifest. `PainelNovaPublicacao` é **Component**, entra no grafo do `app.jsx` e **não** tem entrada própria — correto |

**⚠️ Os gates 3-8 e T-178-SC foram conferidos como NÃO-vazios por construção.** O mesmo intervalo
aplicado a `resources/js tests/js` devolve exatamente os **6** arquivos desta wave e da anterior
(1495 inserções, 2 remoções) — logo o intervalo está vivo. E os cinco caminhos de território proibido
foram conferidos como **existentes no disco** antes de acreditar no diff vazio deles. Todo gate de
diff usa **intervalo** (`da54a9ee..HEAD` ou `da54a9ee`), **nunca** a árvore.

**⚠️ NENHUMA TELA FOI RENDERIZADA EM NAVEGADOR NEM CONFERIDA VISUALMENTE.** Não há navegador neste
ambiente. Toda a prova de fidelidade aqui é: (a) medida lida no `.dc.html` do protótipo e (b) classe
conferida no HTML que o `react-dom/server` produziu. **Quem tiver navegador precisa abrir o modal e
comparar com o protótipo** — principalmente o `cursor-pointer`, a cor do placeholder e o rodapé.

**Sem migration, nada a validar no VPS.** Nenhum `.php` tocado, nenhuma rota, nenhuma coluna.
**Nenhum deploy, nenhum push, nenhum comando no VPS.**

### Registro de ameaças

| Threat ID | Como ficou mitigado | Prova |
|---|---|---|
| T-178-04 (tela preta) | `textoSeguro`/`textoUtil` em todo campo + grupo não-objeto filtrado antes do `.map()` + `classesDoCta()` em vez de indexar objeto literal | render REAL com cada um dos 6 campos como objeto **e** como array, item nulo/`{}`/sem campos, `grupos` nulo/string/número/objeto/`[null]`/`[{}]`, e `doesNotMatch(/\[object Object\]\|NaN\|undefined/)` em todos. **Pegou um `TypeError` real** |
| T-178-05 (destino inventado) | `url` chega pronta do servidor; `textoUtil` rejeita `''`, espaço, objeto e array; sem destino o item não vira clique mudo | 6 formas de `url` inválida → `disabled=` + `aria-disabled="true"` + `title`; `url` usável → nenhum dos três |
| T-178-08 (vazamento) | **accept** — só nome, SKU, nota e miniatura de produto da própria conta | nada novo é exposto |
| T-178-09 (DoS no cliente) | **accept** — filtro local em memória sobre o payload já carregado, como o protótipo (`:214`) | nenhuma ida à rede nesta wave (gate: `/\baxios\b/` e `/\bfetch\(/` reprovam) |
| T-178-SC | **nenhum pacote.** `lucide-react` já era dependência | gate de diff em `package*.json`/`composer*.json` **vazio** |

---

## Task Commits

| Task | Commit | O que entrou |
|---|---|---|
| 1 (RED) | `e77d703f` | `test(publicador): literais, payload e busca do modal "Nova publicacao" (178-02, RED)` — falhou com `ERR_MODULE_NOT_FOUND` (o módulo não existia) |
| 1 (GREEN) | `71b76afb` | `feat(publicador): novaPublicacao.js — os 3 grupos do modal e a busca local (178-02)` — 36/36 |
| 2 | `64ff7f83` | `feat(publicador): PainelNovaPublicacao — o corpo do modal medida por medida (178-02)` — inclui a prop aditiva `letra` do tile e a asserção nova |
| 3 | `61703b8f` | `test(publicador): render real do corpo do modal, elemento por elemento (178-02)` — o gate, os 2 arquivos em `ARQUIVOS_NOVOS` e as 2 correções de Regra 1 |

Todos com pathspec (`git add -- <caminhos>` e `git commit -F <arquivo> -- <caminhos>`), porque a
árvore é **compartilhada** com outro dev ativo e outras sessões. Nenhum `git add -A`, `git stash`,
`git reset`, `git checkout`, `git clean` nem `--amend`.

## Arquivos criados/modificados

- `resources/js/Components/Mlb/Publicador/header/novaPublicacao.js` **(novo, 174 linhas)** — módulo
  puro: `GRUPOS`, `VAZIO`, `RODAPE`, `PLACEHOLDER`, `CARREGANDO`, `ERRO`, `normalizarPayload()`,
  `filtrarGrupos()` e o helper privado `palheiro()`
- `resources/js/Components/Mlb/Publicador/header/PainelNovaPublicacao.jsx` **(novo, 245 linhas)** — o
  corpo; `TOM_DO_CTA` + `classesDoCta()`, `TITULO_SEM_DESTINO`, o subcomponente `Item` e o
  `PainelNovaPublicacao` (export nomeado **e** default)
- `tests/js/publicador-modal-nova-publicacao.test.js` **(novo, 1016 linhas)** — 94 casos em 14
  suítes: literais, `normalizarPayload`, ordem, grupo vazio, busca, adversos do módulo, as 20 linhas
  da FID-01 por elemento, os literais no HTML, NOVA-03, NOVA-05 + carregando/erro, os adversos de
  render, o item sem destino e o reuso
- `resources/js/Components/Mlb/Publicador/identidade/TileDoProduto.jsx` — **prop `letra`, aditiva**
  (2 linhas trocadas, escada e defaults idênticos)
- `tests/js/publicador-identidade-nova-render.test.js` — **+37 linhas, 0 removidas**: uma suíte nova
  para a prop `letra`, por TOKEN de classe
- `tests/js/publicador-vocabulario-novo.test.js` — **+4 linhas**: os 2 arquivos novos em
  `ARQUIVOS_NOVOS` (a lista é constante de propósito; esquecer faria o gate passar por **omissão**)

## Desvios do plano

### 1. [Regra 3 — bloqueio] O gate do módulo puro NÃO pôde importar direto: precisou de esbuild

- **Durante:** Task 1 (GREEN)
- **Situação:** o plano diz *"módulo puro, sem JSX e sem import de React (o gate importa direto, sem
  bundle; é o molde de `identidade/identidade.js`)"*. Mas `identidade/identidade.js` **não importa
  nada**, e `novaPublicacao.js` importa `normalizar` de `'../identidade/identidade'` — **sem
  extensão**, que é a convenção do projeto (`GlyphDaFase` faz `from './identidade'`) e é resolvida
  pelo Vite. O ESM do node **não** resolve extensionless:
  `ERR_MODULE_NOT_FOUND … /identidade/identidade`.
- **O que foi feito:** o harness do teste passou a usar `esbuild` (o mesmo molde de
  `publicador-header-render.test.js`), que o arquivo ia precisar de qualquer forma para o render
  real. **O módulo segue puro** — sem JSX e sem React, e há gate que prova (`/from 'react'/`).
  Acrescentar `.js` ao import contrariaria a convenção do projeto.
- **Commit:** `71b76afb`

### 2. [Regra 1 — bug no próprio gate] REND-03 cobrado ao pé da letra

Descrito acima. A lição do plano estava certa e **mais exigente do que parecia**: não basta "montar
antes dos testes", tem de ser antes do **primeiro `test()`** — qualquer `await` posterior cede o
turno e as suítes registradas rodam até o fim, disparando o `after()`. Commit `61703b8f`.

### 3. [Regra 1 — bug de produção] grupo nulo derrubava o painel

Descrito acima. Commit `61703b8f`.

### 4. [Regra 1 — fixture que escondia o teste] a `capa` do espelho saiu da fixture compartilhada

- **Durante:** Task 3
- **Situação:** a fixture do "Espelho" nascia com `capa: 'https://http2.mlstatic.com/D_11-I.jpg'`.
  Com foto, o `TileDoProduto` renderiza **`<img>`** em vez do `<span>` de iniciais — e todos os
  índices de `span` do item mudavam, fazendo 4 asserções reprovarem **pelo motivo errado**
  (apontavam "o tile tem as classes da coluna").
- **O que foi feito:** `capa: null` na fixture compartilhada, com o motivo escrito ali, **e um teste
  dedicado novo** para o caminho da foto (`<img>` de 34px, `object-cover`, o `src` intacto com o
  sufixo `-I.jpg` que o servidor decide, e o resto do item inteiro). A cobertura **aumentou**.
- **Commit:** `61703b8f`

### 5. [informativo] 26 mutações em vez das 18 nominais do plano

As 20 da lista do plano entraram todas (a #19 depois de corrigida), mais 6 escolhidas nas medidas que
a lista não cobria: `cursor-pointer`, `rounded-[5px]` da tecla Esc, `p-[36px]` do vazio, a prop
`letra` dos dois lados (chamada e componente) e o separador ` · `.

**Total de desvios:** 5. **Impacto:** nenhum aumento de escopo; três deles são bugs que o gate
existia para pegar.

## Problemas encontrados

- **`git status --short -- tests/`** mostra `?? tests/Feature/CompanyPortfolioAccessTest.php`. **Não
  é meu** — já estava não rastreado antes desta wave. Não foi tocado, commitado nem apagado (árvore
  compartilhada com outro dev ativo).
- `public/images/*.jpg` e `public/images/favicon.ico` aparecem não rastreados. **Não são meus**;
  `public/build` é ignorado pelo git (`.gitignore:41`), então o `npm run build` não sujou a árvore.
- ⚠️ **O gate compara classe por igualdade de conjunto, e isso tem um custo que a wave 3 vai sentir:**
  qualquer classe nova em um dos 11 elementos travados reprova o gate. É **de propósito** (é o que faz
  as 26 mutações reprovarem), mas significa que a wave 3 precisa atualizar a constante `FID` no mesmo
  commit em que mexer no corpo. **Atualizar não é afrouxar** — se alguma asserção ficar frouxa,
  endurecer, não apagar.
- Nenhum item para `deferred-items.md`.

## Perguntas em aberto

1. ⚠️ **A nota de `PRONTOS PARA FASE 2` diz "{n} no ar", e o protótipo diz "Clássico e Premium".**
   Herdada da wave 1 e detalhada na seção de divergências acima. **É a única divergência de conteúdo
   desta fase e precisa de decisão do usuário** — resolvê-la muda o CONTRATO do servidor, não o
   render.
2. **`cursor-pointer` no item deste modal, mas não no item do dropdown de empresa (177-06).** O
   protótipo declara `cursor:pointer` em `:145` (item do modal) e também em `:53`? — **não foi
   conferido em `:53`**. Se o dropdown também declarar, a 177-06 ficou com uma divergência que vale
   consertar numa quick task. Esta wave não tocou naquele arquivo.
3. **A meta (`:149`) não recebeu `truncate`.** Nota longa com `white-space:nowrap` pode estourar a
   caixa de 620px. O protótipo não resolve e o plano não pediu. Se acontecer na prática, o conserto é
   `truncate` na meta — adição de uma classe, que exige atualizar `FID.meta` no gate.

## Prontidão para a wave 3 (a casca)

- Os exports, as props e as contagens travadas estão na seção "O QUE A WAVE 3 CONSOME", acima.
- O corpo é **puro** e vive **fora** de qualquer portal: é o que permite o render real continuar
  funcionando quando o `Dialog.Portal` entrar.
- O endpoint é `mlb.anuncios.publicador.nova-publicacao`, aceita só `{conta}`, e espera **uma chamada
  por montagem do modal** — a busca é local.
- `normalizarPayload()` é a fronteira: chamar sobre `resposta.data` **antes** de guardar em estado.
- `filtrarGrupos({ grupos, consulta })` devolve exatamente o que a prop `grupos` do painel espera.

---
*Fase: 178-modal-nova-publicacao · Plano 02*
*Concluído: 2026-10-12*
