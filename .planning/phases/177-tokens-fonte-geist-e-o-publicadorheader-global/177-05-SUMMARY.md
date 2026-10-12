---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 05
subsystem: ui
tags: [publicador, header-global, abas, navegacao, contadores, d23, fidelidade-de-pixel, render-real, node-test]

# Grafo de dependências
requires:
  - "177-01: camada `pub-*` no `theme.extend` (cores, raio `pub-control`, `shadow-pub-aba-ativa`) — consumida só por nome"
  - "177-03: a prop `cabecalho.contadores` (`CabecalhoPublicadorService::para()`), que pode chegar NULA — e cujos campos `publicacoes`/`publicacoes_pedem_acao` podem ser nulos sem ser zero"
  - "177-04: a casca do `PublicadorHeader`, a `<nav>` de 48px e o ponto de montagem com `gap-[2px]`"
  - "Fase 172: `textoSeguro()` de `Components/Mlb/Publicador/BarraDaConta.jsx` — importado, não recriado"
  - "Fase 164: as 4 rotas das abas, inalteradas desde `AbasDaConta.jsx`"
provides:
  - "`Components/Mlb/Publicador/header/AbasDoHeader.jsx`: as 4 abas-pílula de 34px (`default` + `ABAS`), com contador do servidor, dot de alerta, D23, barra da ativa e desabilitação por ausência de conta"
  - "`tests/js/publicador-header-abas-render.test.js`: 72 testes de render real + FID-01 travado elemento por elemento"
  - "A linha 2 do header COMPLETA: o header global já tem as duas linhas inteiras, menos o seletor de empresa e a pill de conexão (177-06)"
affects: [177-06, 177-07, 177-08, 177-09, 178, 180, 182]

# Rastreio técnico
tech-stack:
  added: []   # T-177-SC: nenhum pacote instalado; os 4 ícones já existiam no `lucide-react`
  patterns:
    - "Contador do servidor lido por `Number.isFinite`, nunca por `typeof === 'number'`: `NaN` é number e escreveria \"NaN\" na tela"
    - "`null` ≠ `0` na interface: contador não medido não desenha pílula nenhuma; contador medido e vazio desenha `0`"
    - "Controle sem destino usável vira `<span role=\"link\" aria-disabled=\"true\">` com `title` que explica — nunca um `href` para `undefined`, nunca aba escondida"
    - "Gate de fidelidade por ELEMENTO: o HTML é partido nas 4 pílulas e cada asserção roda DENTRO da pílula certa, com contagens exatas por cima"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx
    - tests/js/publicador-header-abas-render.test.js
  modified:
    - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx   # montagem na <nav> (+27/-11)
    - tests/js/publicador-header-render.test.js                            # contagens 4→8 ATUALIZADAS + as abas dentro da <nav>
    - tests/js/publicador-vocabulario-novo.test.js                         # ARQUIVOS_NOVOS + IDENT-08 endurecido

key-decisions:
  - "HEAD-09 — são QUATRO abas e isso está escrito em três lugares (o topo do componente, o gate de fonte e esta SUMMARY), porque é desvio DELIBERADO da fidelidade e a Fase 182 precisa achar o registro"
  - "Onde o plano divergiu do protótipo, o PROTÓTIPO venceu em 4 medidas: `white-space:nowrap`, `display:flex` (o plano pedia `inline-flex`), a caixa `grid place-items-center` do ícone e o `font-variant-numeric:tabular-nums` explícito no contador"
  - "`publicacoes = 0` DESENHA a pílula com `0`; `publicacoes = null` não desenha nada. São informações diferentes (medido e vazio × nunca medido) e a tela não pode confundi-las"
  - "Dot de alerta no singular quando N = 1 (\"1 publicação pede ação\"), igual à convenção de plural que `AbasDaConta` já usa no badge de alavancas"
  - "`IDENT-08` do gate de vocabulário foi ENDURECIDO (par de medidas em vez de classe solta) porque `h-[18px]` solto não é o glyph — é também a altura do contador, medida no protótipo"

patterns-established:
  - "Prova por mutação antes de aceitar o gate: 47 mutações nos arquivos reais, 47 reprovadas, restauração conferida por sha256"
  - "HTML partido por pílula (`pilulas(html)`) para que contador, dot e barra sejam assertados na aba certa, e não \"em algum lugar do HTML\""

requirements-completed: [HEAD-09, HEAD-10, HEAD-11, HEAD-12, FID-01, FID-03, REG-01, REG-05, REG-06, REND-01, REND-02, REND-03, REND-04, GATE-03, GATE-05]

# Métricas
duration: ~70min
completed: 2026-10-12
---

# Fase 177 Plano 05: as 4 abas-pílula do header — Summary

**A linha 2 do header global ficou pronta: quatro abas-pílula de 34px com a geometria medida no protótipo (ícone Lucide de 15px/traço 1,6 numa caixa `grid`, label de 13,5px, contador em pílula de 18px/mín. 22px com `tabular-nums`, dot vermelho de 6px e a barra amarela de 2px a −7px da ativa), com TODO contador vindo do servidor, `null` que nunca vira `0`, D23 preservado com o literal exato e desabilitação honesta quando não há conta — e a fidelidade travada pílula por pílula num gate que só foi aceito depois de 47 mutações reprovarem.**

⚠️ **São QUATRO abas de propósito. Leia a seção "HEAD-09" abaixo antes de "consertar" para cinco.**

## Performance

- **Duração:** ~70 min
- **Concluído:** 2026-10-12
- **Tarefas:** 2 de 2
- **Arquivos:** 2 criados, 3 modificados
- **Commits:** `1bd0b5b5` (Task 1), `19f12cce` (Task 2) + o desta SUMMARY
- **Linhas no meu range (`b3c9ba0c..HEAD`):** 982 adições, 17 remoções, **0 arquivos deletados**

## Baselines — medidas por mim, hoje, antes e depois

| Suíte | Antes (medido agora) | Depois | Leitura |
|---|---|---|---|
| JS inteiro (`npm run test:js`) | **2424 · 2423 pass · 1 fail** | **2502 · 2501 pass · 1 fail** | +78 testes (72 do render das abas + 6 do vocabulário sobre o arquivo novo) |
| Os três gates deste plano | — | **223 · 223 pass · 0 fail** | — |

O número de partida bate exatamente com o que o 177-04 deixou (2424). **O único fail é o mesmo
pré-existente** `tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce
recolhido (é o grupo que mais infla)"*, de outro território e de outra fase — **não corrigido de
propósito**.

PHP não foi tocado neste plano (`git diff --name-only b3c9ba0c..HEAD -- app routes tests/Feature` é
vazio), então as baselines do PHPUnit não foram re-executadas: nada que elas leem mudou. A última
medida registrada segue valendo (**Publicador 1777 · 0 fail**, plano 177-01).

## ⚠️ HEAD-09 — por que são QUATRO abas, e não cinco

**Para quem chegar aqui pela Fase 182, ou reimplementando o header a partir do protótipo:**

O protótipo tem **cinco** abas. Está no array `T` de `PublicadorHeader.dc.html:215`:

```
['overview','Visão geral',''], ['products','Produtos','32'], ['publications','Publicações','58'],
['performance','Desempenho',''], ['levers','Alavancas','']
```

E o README §0 repete: *"Abas: Visão geral · Produtos (32) · Publicações (58, + dot vermelho se
houver erro) · **Desempenho** · Alavancas."*

A quinta **não entra**. Decisão do usuário em 11/10/2026 — palavras dele: *"não mostre o link da
tela desempenho por enquanto"*. O motivo é a régua dele: **nada nasce decorativo**. A tela de
Desempenho não existe (Fase 182 registrada e não planejada, porque o dado de vendas por dia não
existe) e aba que leva a lugar nenhum é link morto (EXMP-04).

**É desvio DELIBERADO da fidelidade — NÃO "corrigir" para cinco.** O registro está em três lugares,
de propósito:

1. o bloco no topo de `AbasDoHeader.jsx`, com a frase do usuário;
2. `tests/js/publicador-header-abas-render.test.js` → *"a aba Desempenho NÃO existe (desvio
   deliberado)"*, que reprova no HTML (`doesNotMatch(html, /Desempenho/)`) **e na FONTE**
   (`doesNotMatch(fonte, /'desempenho'/)`);
3. esta SUMMARY.

Quando a Fase 182 entregar a tela, é esse teste que muda **junto com** a aba — não antes.

E **Configurações também não está aqui** (HEAD-08): saiu das abas e virou o ícone de 34px da
linha 1, entregue no plano 177-04. O gate reprova a volta dela também.

## Tabela FID-01 — protótipo × implementado, item a item

Protótipo = `new_layout_publicador/PublicadorHeader.dc.html` (linha citada em cada item) + README §0.
A coluna "Implementado" é o valor **real** que está no arquivo.

| # | Dimensão | Protótipo (`PublicadorHeader.dc.html`) | Implementado |
|---|---|---|---|
| 1 | largura | pílula por conteúdo (:111) · contador `min-width:22px` (:120) · dot `width:6px` (:121) · barra `left:12px;right:12px` (:122) | sem largura fixa · `min-w-[22px]` · `w-[6px]` · `left-[12px] right-[12px]` |
| 2 | altura | nav `48px` (:109) · pílula `34px` (:111) · contador `18px` (:120) · dot `6px` (:121) · barra `2px` (:122) | `h-[48px]` (já era do 177-04) · `h-[34px]` (4×, contagem travada) · `h-[18px]` · `h-[6px]` · `h-[2px]` |
| 3 | grid | nav `display:flex;align-items:center` (:109) · pílula `display:flex;align-items:center` (:111) · ícone `display:grid;place-items:center` (:112) · contador `display:grid;place-items:center` (:120) | `flex items-center` · `flex items-center` · `grid place-items-center` · `grid place-items-center` |
| 4 | spacing | barra a `bottom:-7px` da pílula (:122) | `bottom-[-7px]` |
| 5 | padding | pílula `0 12px` (:111) · contador `0 6px` (:120) | `px-[12px]` · `px-[6px]` |
| 6 | gap | entre abas `2px` (:109) · dentro da aba `8px` (:111) | `gap-[2px]` (no grupo) · `gap-2` (4×) |
| 7 | tipografia — família | Geist, herdada do header (:21) · contador com `font-variant-numeric:tabular-nums` (:120) | **nenhuma classe de família aqui** (vem do `font-pub` do `<header>`, 177-04 — e há asserção de que as abas não a redeclaram) · `tabular-nums` (3×) |
| 8 | tipografia — tamanho | label `13.5px` (:111) · contador `11px` (:120) | `text-[13.5px]` (4×) · `text-[11px]` (3×) ⚠️ 13,5 é `text-[13.5px]`, **nunca** `text-sm` (FID-03) |
| 9 | tipografia — peso | ativa `600`, inativa `500` (:111 + :233 `weight: on ? 600 : 500`) · contador `600` (:120) | `font-semibold` (1×, só a ativa) · `font-medium` (3×, as inativas) · `font-semibold` nos 3 contadores |
| 10 | tracking | não declarado na nav | nenhum |
| 11 | raio | pílula `9px` (:111) · contador `999px` (:120) · dot `50%` (:121) · barra `2px` (:122) | `rounded-pub-control` (4×) · `rounded-full` · `rounded-full` · `rounded-[2px]` |
| 12 | bordas | ativa com anel **INTERNO** `inset 0 0 0 1px #262626` (:233 `ring`) — não é borda | `shadow-pub-aba-ativa` (o token é byte a byte esse valor) e **gate que proíbe a classe `border` na pílula ativa**: borda empurraria o conteúdo 1px e a pílula mediria 36px |
| 13 | sombras | só o anel interno da ativa (:233) | idem; `inativa` sem sombra nenhuma |
| 14 | cores | ativa: fundo `#161616`, texto `#F4F4F5`, ícone `#FACC15`, contador `#FACC15` sobre `rgba(250,204,21,0.14)` (:233-234) · inativa: texto `#8A8A90`, ícone `#71717A`, contador `#8A8A90` sobre `#1A1A1A` (:233-234) · hover `#151515`/`#F4F4F5` (:111) · dot `#EF4444` (:121) · barra `#FACC15` (:122) | `bg-pub-hover-2` `text-pub-txt` `text-pub-accent` `bg-pub-accent/[0.14]` · `text-pub-txt-3` `text-pub-txt-4` `bg-pub-hover-3 text-pub-txt-3` · `hover:bg-pub-raised hover:text-pub-txt` · `bg-pub-danger` · `bg-pub-accent` — **todos exatos, por nome de token** |
| 15 | alinhamento | ícone, label, contador e dot centrados na vertical (:111) · ícone e contador centrados na própria caixa (:112/:120) | `items-center` · `grid place-items-center` nos dois |
| 16 | ícones | 15px, `stroke-width:1.6`, `linecap/linejoin: round` (:113-117): 4 retângulos · caixa 3D · retângulo com linhas · raio | Lucide `LayoutDashboard` `Package` `LayoutList` `Zap`, `size={15} strokeWidth={1.6}` (TOK-14) — cada um conferido pela classe que o Lucide emite (`lucide-layout-dashboard`, `lucide-package`, `lucide-layout-list`, `lucide-zap`), uma única ocorrência cada ⚠️ ver a divergência nº 4 abaixo |
| 17 | badges | contador em pílula (:120) · dot vermelho de alerta (:121) | linhas 2/8/9/11/14 desta tabela |
| 18 | tabelas | — | n/a |
| 19 | estados | ativa / inativa / hover (:111, :233) | os 3 do protótipo + **dois estados desabilitados que o protótipo não tem**: D23 (sem Company) e sem conta — ambos `aria-disabled="true"` com `title`, nunca escondidos |
| 20 | densidade / CTAs | **5 abas** (:215), Configurações fora das abas (:99) | **4 abas** (HEAD-09, decisão do usuário) + Configurações como ícone na linha 1 (plano 177-04) |

### Divergências declaradas (FID-02 — restrição concreta, por escrito)

As que o plano previu:

| Item | Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Quantidade de abas | 5, com "Desempenho" (:215) | **4** | Decisão do usuário em 11/10/2026: *"não mostre o link da tela desempenho por enquanto"*. A tela não existe (Fase 182 registrada, sem dado de vendas por dia) e aba sem destino é link morto (EXMP-04). **Desvio deliberado — não corrigir.** |
| Rótulo do dot vermelho | `title="Publicações com erro"` (**:121**) | `"N publicações pedem ação"` | `MlAcervoItem.status` não tem valor de erro (`active\|paused\|under_review\|closed` — `AcervoTriagemService::escopo()`). O número real é "publicação com ≥1 motivo de triagem", que é o que a tela de Publicações já conta. **Ausência de coluna, não preferência de estilo.** O gate reprova a volta de "com erro". |
| Estado desabilitado | não existe no protótipo | `aria-disabled="true"` + `title` com o literal exato, `cursor-not-allowed opacity-40` | D23 é regra do sistema: sem `Company` não há Publicações. Capacidade de hoje que não pode se perder (HEAD-11/REG-01). O protótipo é mock e nunca fica sem empresa. |
| Badge de alavancas | contador neutro (:120) | contador padrão + `title` "N publicados aguardando alavancas" | Capacidade de hoje (`AbasDaConta.jsx:67`). O visual passa a ser o do contador novo; a informação é a mesma, e o literal do `title` está byte a byte. |
| Sub-abas "No ar \| Histórico" | não existem | **não entram no header** — vão para as páginas (plano 177-08) | Até a Fase 180 continuam funcionando em `AbasDaConta` (HEAD-11); a remoção só é autorizada lá (LIST-25). Pôr no header seria desenhar algo que o protótipo não tem e que vai sair. |
| Hover na aba ATIVA | `style-hover` vale para TODAS as abas, inclusive a ativa (:111) | hover só nas **inativas** | O `style-hover` do formato DC é uma regra única, sem ramo por estado. Aplicá-lo à ativa a ESCURECERIA no hover (fundo `#161616` → `#151515`), que é o inverso do feedback esperado. A ativa já tem fundo e anel próprios. |

E as **quatro que eu encontrei medindo** — nas quatro, **o protótipo venceu o plano**:

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| 1 | nada sobre `white-space` no bloco de classes da pílula | `white-space:nowrap` na pílula (**:111**) | `whitespace-nowrap` nas 4 pílulas (contagem travada em 4) |
| 2 | `relative inline-flex h-[34px] …` | `display:flex` (**:111**) | `flex`. Como item de um contêiner flex os dois computam para `flex`, mas o valor do protótipo é `flex` e é o que está escrito |
| 3 | só "ícone: `text-pub-accent` na ativa, `text-pub-txt-4` na inativa" — sem dizer onde a cor mora | o ícone vive dentro de `<span style="display:grid;place-items:center;color:…">` (**:112**) | a cor está numa caixa `<span className="grid flex-none place-items-center …">`, como no protótipo — e não jogada no `<svg>` |
| 4 | "contador … `tabular-nums`" | `font-variant-numeric:tabular-nums` **declarado no próprio contador** (**:120**) | `tabular-nums` no contador, com contagem exata de 3 (TOK-02) |

Além delas, **uma adição defensiva** (mesmo precedente do 177-04): `flex-none` na caixa do ícone, no
contador e no dot. O protótipo não tem — nenhuma diferença visual a 1440px, mas impede que um nome
longo de empresa (plano 177-06) esprema o ícone ou o contador. **Adição defensiva, não adaptação
de estilo.**

### ⚠️ Observação medida que NÃO decidi sozinho — para a conferência visual do usuário

Os SVG do protótipo usam `viewBox="0 0 16 16"`; os do Lucide usam `0 0 24 24`. Com o mesmo valor de
`stroke-width`, o traço RENDERIZADO não é o mesmo:

- protótipo: `1.6 × 15/16` = **1,5 px** de traço
- Lucide a 15px: `1.6 × 15/24` = **1,0 px** de traço

Ou seja: o ícone sai **mais fino** que no protótipo. Para igualar seria preciso `strokeWidth ≈ 2.4`.

**Não mudei, e o motivo é concreto:** isto não é específico desta wave. Os três ícones da linha 1
(`Search` 1.6, `Settings` 1.4, `Plus` 2), já entregues e já com gate no plano 177-04, têm exatamente
a mesma conta. Mexer só nestes quatro deixaria o header internamente inconsistente (abas com traço
mais grosso que a busca ao lado), e mexer nos sete exige alterar o gate já commitado do 177-04 —
que não é escopo desta wave. É consequência direta da troca TOK-14 (SVG do protótipo → Lucide), em
que as formas também são aproximações.

⚠️ **Fica como pergunta aberta ao usuário, para a conferência visual:** os ícones do header ficaram
finos demais? Se sim, é um ajuste de **uma linha em cada um dos 7 ícones** (`strokeWidth × 1,5`) mais
a atualização das duas asserções de gate, e vale fazer de uma vez, não por wave.

## Contador `null` × `0` — o que cada caso desenha

`contadores.publicacoes` e `contadores.publicacoes_pedem_acao` **podem ser nulos** quando o acervo
nunca foi coletado (contrato do plano 177-03). A regra implementada:

| Valor do servidor | O que aparece | Por quê |
|---|---|---|
| `58` | pílula com `58` | medição existe |
| `0` | pílula com **`0`** | medido e vazio: "esta conta não tem publicação" é uma afirmação VERDADEIRA aqui |
| `null` | **nada** — nenhuma pílula | nunca medido. Escrever `0` afirmaria "não há", quando o certo é "não sabemos" (T-177-18) |
| ausente / objeto / texto / `NaN` / lista / booleano | **nada** | leitura por `Number.isFinite`, nunca por `typeof === 'number'` — `NaN` é `number` e escreveria "NaN" na tela |

`alavancas` é diferente de propósito: **só com `> 0`**, porque é badge opcional e é o comportamento
de hoje (`AbasDaConta.jsx:65`). `visao-geral` nunca tem contador, nem no protótipo.

O **dot de alerta** é independente do contador: pode haver triagem pendente num acervo cuja contagem
total ainda não foi apurada — há teste para exatamente esse par (`publicacoes: null` +
`publicacoes_pedem_acao: 4` → dot sim, pílula não).

## D23 e a ausência de conta — honestidade em vez de aba escondida

| Situação | Abas afetadas | O que a tela faz |
|---|---|---|
| `companyId === null` (MlbEmpresa solta) | só **Publicações** | `<span role="link" aria-disabled="true">` com `title` = `'Disponível só para empresas cadastradas no sistema'` (**literal exato**, o mesmo de `AbasDaConta.jsx` e `ModoAnuncioTabs.jsx` — há gate de fonte em `publicador-entrada.test.js` procurando essa string) e `aria-label` = `"Publicações — <o mesmo texto>"`. **Nunca escondida**, e **o contador continua visível**: esconder o número seria esconder o motivo. |
| `conta` nula, objeto ou vazia | **Visão geral, Produtos, Alavancas** | mesmo tratamento, com `title` começando em *"Abra uma empresa do Publicador…"*. Nunca um `href` para `undefined` (EXMP-03). |
| `companyId` objeto, `NaN`, `''`, booleano, lista | só **Publicações** | cai no D23 em vez de montar `/mlb/anuncios/meus/NaN`. `NaN` tem de ser barrado à mão: `typeof NaN === 'number'`. |

⚠️ **Visão geral e Alavancas NUNCA passam por D23** — tratam "sem Company" por dentro das próprias
telas, exatamente como hoje. Elas só ficam desabilitadas por falta de **conta**, que é outra coisa.

E a aba **desabilitada E ativa** (acontece em `/mlb/anuncios/meus` sem Company) continua declarando
`aria-current="page"` e desenhando a barra amarela: é a página atual, ainda que o link não sirva.

## O que `AbasDaConta` entrega e que NÃO se perdeu (REG-01)

| Capacidade de hoje | Onde foi | Conferida por |
|---|---|---|
| as 4 abas, com as MESMAS rotas e os MESMOS parâmetros | `ABAS` (export) | teste de `ABAS.map(a => a.rota)` + os 4 `href` no HTML |
| ⚠️ Publicações parametrizada por `company`, as outras por `conta` | `pilula()` | `href="/mlb.anuncios.meus{"company":459}"` travado |
| D23 com o literal exato, nunca escondida | `TITULO_SEM_COMPANY` | 4 asserções + gate de fonte |
| contador de produtos com `tabular-nums` | pílula de contador | contagem exata |
| badge de alavancas só com `> 0`, com o `title` de hoje | `tituloContador` | `"2 publicados aguardando alavancas"` e `"1 publicado aguardando alavancas"` travados |
| `aria-current="page"` na ativa | `pilula()` | teste chave por chave, 1 ocorrência |
| sub-abas "No ar \| Histórico" | **ficaram em `AbasDaConta`**, intactas | `git diff b36037f9..HEAD -- AbasDaConta.jsx` é **vazio** |

⚠️ **`AbasDaConta.jsx` não foi tocado** e continua no ar com tudo funcionando: a troca nas páginas é
dos planos 177-07/08. `git diff --name-only b3c9ba0c..HEAD -- resources/js/Pages` é **vazio**, de
propósito.

## O que foi acrescentado ao gate de vocabulário

Em `tests/js/publicador-vocabulario-novo.test.js`, a constante `ARQUIVOS_NOVOS` ganhou:

```
resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx
```

(o `DIR_HEADER` já existia, do plano 177-04). **A lista é constante de propósito: se eu tivesse
esquecido, o gate passaria POR OMISSÃO, sem acusar nada** — e com ela o arquivo novo passou a ser
conferido contra `text-sm`, `ecf-*`, `font-display`, `border-white/[0.08]`, hex literal, breakpoint
novo, peso fora dos quatro do README e `dangerouslySetInnerHTML` (**+6 testes**).

### E uma asserção desse mesmo arquivo foi ENDURECIDA (não relaxada)

O sub-teste IDENT-08 *"a geometria do retângulo do glyph aparece SÓ em `GlyphDaFase.jsx`"* conferia
`w-[15px]` e `h-[18px]` **cada um por conta própria** — e reprovou o arquivo novo, porque a pílula de
contador tem `h-[18px]` por medida do protótipo (`:120`) e não redesenha glyph nenhum.

A asserção passou a ser sobre a **geometria inteira** —
`'h-[18px] w-[15px] rounded-[4px] border-[1.6px]'` — **mais** a proibição de combinar as duas
medidas em qualquer ordem. É mais forte que antes: a versão velha passaria se alguém escrevesse o
retângulo com o raio errado; a nova não. É a mesma lição dos 4 furos do gate do 177-04: **substring
compartilhada não prova nada; o que prova é o conjunto, no elemento certo.**

## Como as contagens do gate da wave 3 foram ATUALIZADAS (não apagadas)

Em `tests/js/publicador-header-render.test.js`, o sub-teste *"contagens: a medida certa no lugar
certo"*:

| Classe | Antes (wave 3) | Agora | Por quê |
|---|---|---|---|
| `rounded-pub-control` | 4 | **8** | 4 controles da linha 1 + as 4 pílulas |
| `h-[34px]` | 4 | **8** | idem |
| `gap-[2px]` | 2 | **2** (inalterado) | a `<nav>` e o grupo das abas — o ponto de montagem vazio virou o `<div>` raiz do `AbasDoHeader` com as mesmas classes |
| `font-pub-mono` | 3 | **3** (inalterado) | as pílulas não usam mono |
| `text-[10.5px]` | 3 | **3** (inalterado) | idem |
| `font-pub[\s"]` | 1 | **1** (inalterado) | ⚠️ as abas **não** redeclaram família: ela vem do `<header>`. Há asserção explícita (`doesNotMatch(HTML_CHEIO, /font-pub/)`) no gate novo para que a troca de fonte continue tendo UMA casa. |

Continuam sendo **igualdades exatas**, não `>=`. E o mesmo sub-teste da `<nav>` ganhou duas
asserções novas: os 4 rótulos **dentro** da `<nav>`, e a ausência de "Desempenho" lá.

## Prova por mutação — 47 mutações, 47 reprovadas, 0 furos

Diferente da wave 3 (onde 4 de 22 passaram na primeira rodada), aqui o gate nasceu já no formato
"por elemento": o HTML é partido nas quatro pílulas por `pilulas(html)` e cada asserção roda **dentro
da pílula certa**, com contagem exata por cima. Resultado: **as 47 reprovaram na primeira rodada.**

A razão de ter nascido assim é que o risco era ainda maior que no 177-04: ali quatro controles
compartilhavam algumas classes; aqui as quatro pílulas compartilham a **base inteira** —
`html.includes('h-[34px]')` continuaria verdadeiro com três pílulas quebradas.

Amostra do que reprova hoje (as 47 completas rodaram contra os arquivos reais):

`h-[34px]`→`h-9` · `text-[13.5px]`→`text-sm` · `rounded-pub-control`→`rounded-lg` ·
`px-[12px]`→`px-3` · pílula sem `whitespace-nowrap` · `gap-2`→`gap-1` · contador `h-[18px]`→`h-5` ·
`min-w-[22px]`→`min-w-[20px]` · `text-[11px]`→`text-[12px]` · contador `rounded-full`→`rounded-pub-badge` ·
contador sem `tabular-nums` · contador sem peso 600 · tinta `0.14`→`0.2` · dot 6→8px ·
dot `bg-pub-danger`→`bg-pub-warn` · dot voltando a dizer "com erro" · barra `-7px`→`-6px` ·
barra 2→3px · barra `left-[12px]`→`left-0` · anel interno virando `border` · ativa
`bg-pub-hover-2`→`bg-pub-hover-3` · inativa `font-medium`→`font-normal` · inativa sem hover ·
ícone da ativa sem amarelo · ícone 15→16px · traço 1,6→2 · `Zap`→`LayoutList` em Alavancas ·
`gap-[2px]`→`gap-1` · grupo sem `min-w-0` · `ABAS` fora de ordem · **volta da 5ª aba "Desempenho"** ·
`publicacoes` nulo virando `0` · `alavancas > 0`→`>= 0` · `Number.isFinite`→`!== undefined` ·
dot aparecendo com `pedem_acao === 0` · literal de D23 parafraseado · **D23 escondendo a aba** ·
D23 sem `aria-disabled` · desabilitada sem `title` · link sem `aria-current` ·
`textoSeguro`→`String` na chave · `title` de alavancas parafraseado · Publicações parametrizada por
`conta` · flag saindo de dentro do `.map()` (REND-04) · abas sumindo do header ·
`cabecalho?.company_id ?? null`→`cabecalho.company_id` · `cabecalho?.contadores ?? null`→`cabecalho.contadores`.

Os dois arquivos foram restaurados de cópia pristina e a restauração **conferida por sha256**
(`OK` nos dois), com os três gates verdes depois. ⚠️ Uma fixture temporária
(`tests/js/.header-abas-inertia-stub-31788.mjs`) ficou para trás durante a rodada de mutação — é o
comportamento já anotado no 177-04 (o `after()` do `node --test` não roda quando o módulo falha
antes de carregar). **Apaguei; a árvore está limpa nos meus caminhos.**

## Decisões técnicas

1. **`Number.isFinite` em todo contador, não `typeof === 'number'`.** `typeof NaN === 'number'` — e
   `NaN` escreveria "NaN" dentro da pílula. Vale para os quatro campos de `contadores`.
2. **`idUtil()` para o `company_id`.** Aceita número finito ou texto não vazio (é o que o servidor
   manda, e `AbasDaConta` passava adiante sem conferir — REG-01 manda não endurecer o que já
   funciona) e devolve `null` para objeto, booleano, lista, vazio e `NaN`. Sem isso, um `company_id`
   estragado montaria `/mlb/anuncios/meus/NaN` em vez de cair honestamente no D23.
3. **`textoSeguro(conta, null)` em vez de `String(conta)`.** A chave vira parâmetro de URL. Com
   `String()`, uma chave objeto montaria um destino inventado; com `null`, a aba assume "não há
   conta" e desabilita com explicação. Mesma decisão do 177-04, pelo mesmo motivo.
4. **`<Link href>` em vez de `router.get` no `onClick`.** O protótipo usa `<a href>` (`:111`) e link
   de verdade abre em nova aba, aparece na barra de status e é navegável por teclado — o `<button>`
   de `AbasDaConta` não fazia nada disso.
5. **Nenhum pacote instalado** (T-177-SC). `LayoutDashboard`, `Package`, `LayoutList` e `Zap` saem do
   `lucide-react` que já era dependência. Sem componente de tooltip: `title` + `aria-label` com o
   **mesmo** texto, e nenhuma informação mora só no tooltip.
6. **Dot no singular quando N = 1** (`"1 publicação pede ação"`). O plano escreve o template no
   plural (`"N publicações pedem ação"`); renderizar "1 publicações pedem ação" seria visivelmente
   errado, e `AbasDaConta` já faz singular/plural no `title` das alavancas. Mesma informação, forma
   correta — e as duas formas estão travadas no gate.

## Verificação executada

| # | Verificação | Resultado |
|---|---|---|
| 1 | `node --test publicador-header-abas-render + publicador-header-render + publicador-vocabulario-novo` | **223 pass · 0 fail** |
| 2 | `npm run test:js` | **2502 · 2501 pass · 1 fail** (o mesmo fail pré-existente da baseline) |
| 3 | `git diff --stat b36037f9..HEAD -- database app/Support/Publicador resources/js/Pages resources/js/Layouts/AppLayout.jsx` | **vazio** |
| 4 | `git diff --stat b36037f9..HEAD -- resources/js/Components/Mlb/Publicador/AbasDaConta.jsx` | **vazio** (o componente antigo segue intacto) |
| 5 | `git diff --stat b36037f9..HEAD -- resources/js/Pages/Mlb/Publicador/Editor.jsx resources/js/Components/Publicador/Mesa` | **vazio** (REG-03, território do outro dev) |
| 6 | `git diff --name-only b3c9ba0c..HEAD -- app routes tests/Feature tailwind.config.js resources/views/app.blade.php resources/js/Pages resources/js/Layouts .planning identidade new_layout_publicador` | **vazio** — os meus 2 commits só tocam os 5 arquivos |
| 7 | `git diff --diff-filter=D --name-only b3c9ba0c..HEAD` | **vazio** — nenhum arquivo deletado |
| 8 | `sha256sum -c` das cópias pristinas após a mutação | **OK** nos dois arquivos |
| 9 | `git status --short -- resources/js tests/js` após a limpeza | **vazio** — nenhuma fixture órfã |

`npm run build` **não** foi rodado: é do plano 177-09, e nenhuma página consome o header ainda.

⚠️ **Não vi tela renderizada.** O que foi verificado é HTML de `react-dom/server` e classe por
classe contra as medidas do protótipo. **A conferência visual é do usuário, em produção, na conta
459** — inclusive a pergunta aberta do traço dos ícones (seção acima) e o encaixe da barra de 2px
com a borda inferior do header, que só se julga olhando.

## Para o próximo plano (177-06 — seletor de empresa e pill de conexão)

- O ponto de montagem na esquerda da linha 1 continua marcado por comentário em
  `PublicadorHeader.jsx`; o `flex-none` já está nos controles da direita e nas pílulas, então um nome
  longo de empresa **não** vai espremer nem o lado direito nem as abas.
- `fecharTudo()` segue um `noop` documentado: é o 177-06 que liga os dois menus no Esc.
- O grupo das abas tem `min-w-0`: se o seletor crescer muito, quem encolhe é ele, não as abas.
- ⚠️ Ao acrescentar os arquivos novos ao `ARQUIVOS_NOVOS`, lembre que a lista é constante **de
  propósito** — esquecer faz o gate passar por omissão.
- As contagens do gate do 177-04 estão em **8** (`h-[34px]`, `rounded-pub-control`). O seletor de
  empresa é outro controle de 34px: **atualize para 9, não apague a asserção.**

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx` — FOUND (248 linhas)
- `tests/js/publicador-header-abas-render.test.js` — FOUND (673 linhas, 72 testes)
- `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx` — FOUND (modificado: +27/−11)
- `tests/js/publicador-header-render.test.js` — FOUND (modificado: contagens 4→8)
- `tests/js/publicador-vocabulario-novo.test.js` — FOUND (modificado: `ARQUIVOS_NOVOS` + IDENT-08)
- Commits `1bd0b5b5`, `19f12cce` — FOUND em `git log`
