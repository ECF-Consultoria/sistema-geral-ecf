---
phase: 178-modal-nova-publicacao
plan: 03
subsystem: ui
tags: [react, publicador, modal, radix-dialog, portal, fidelidade-de-layout, atalhos-de-teclado, mutacao, cirurgia-de-gate]

requires:
  - phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
    provides: "O PublicadorHeader global com os dois botões e o hook useAtalhosDoHeader (⌘K/N/Esc), os tokens pub-* (rounded-pub-card, shadow-pub-modal, pub-surface, pub-line-strong) e o molde Popover.Portal + font-pub do 177-06"
  - phase: 178-modal-nova-publicacao (plano 01)
    provides: "GET mlb.anuncios.publicador.nova-publicacao — {total, grupos[3]}, item de 6 campos escalares com a url de destino montada NO SERVIDOR"
  - phase: 178-modal-nova-publicacao (plano 02)
    provides: "PainelNovaPublicacao (o corpo, componente puro) e novaPublicacao.js (filtrarGrupos, normalizarPayload e os literais de tela)"
provides:
  - "ModalNovaPublicacao.jsx — a casca portada (620px a top:110px sobre rgba(0,0,0,0.65)), o fetch lazy, o foco no input e a navegação pela url do servidor"
  - "CLASSES_FUNDO e CLASSES_CAIXA — a geometria da casca em constantes EXPORTADAS, porque o render de servidor não atravessa portal"
  - "precisaBuscar() e contaParaRota() — as duas decisões que custam dinheiro, em funções puras e exercitáveis sem DOM"
  - "O header ligado: os dois botões, ⌘K/Ctrl+K e N abrem o modal; Esc e clique fora fecham"
affects: [178-04, 179-visao-geral, 180-busca-global]

tech-stack:
  added: []
  patterns:
    - "Geometria de conteúdo PORTADO conferida por constante exportada + igualdade da string inteira (o renderizador de servidor do React não atravessa portal, e o Portal do Radix devolve null no servidor de propósito)"
    - "Classe conferida por TOKEN exato de um Set, nunca por substring (`font-pub` casa dentro de `font-pub-mono`)"
    - "Regra de efeito extraída para função pura EXPORTADA quando não há DOM para observar o efeito (mesmo precedente de criarOuvinteDeAtalhos e filtrarGrupos)"
    - "Cirurgia de gate classificada: asserção REAPONTADA com veto explícito do comportamento antigo, nunca removida"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/header/ModalNovaPublicacao.jsx
  modified:
    - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
    - tests/js/publicador-modal-nova-publicacao.test.js
    - tests/js/publicador-header-render.test.js
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-configuracoes-render.test.js

key-decisions:
  - "O portal é RESTRIÇÃO, não preferência: o `backdrop-filter` do header cria containing block para `position: fixed`, então um overlay escrito dentro dele nasceria recortado na faixa de 109px do topo. Com `Dialog.Portal` o conteúdo vai para o document.body — e por isso a caixa tem de declarar `font-pub` por conta própria, senão o modal inteiro perde a Geist em silêncio."
  - "A geometria da casca vive em CLASSES_FUNDO/CLASSES_CAIXA exportadas: o renderizador de servidor do React não atravessa portal e o Portal do Radix devolve `null` no servidor (@radix-ui/react-portal:13), logo o conteúdo da casca NÃO EXISTE em renderToStaticMarkup em nenhuma combinação de props. Sem as constantes só sobraria gate de leitura de fonte — o gate fraco que deixou 4 mutações passarem na Fase 177."
  - "`precisaBuscar()` e `contaParaRota()` nasceram EXPORTADAS para o gate exercitar a decisão em vez de ler a fonte: sem jsdom e sem portal no servidor, este `useEffect` não é observável por nenhum render neste projeto."
  - "O gate pegou um furo REAL: `textoUtil(0)` devolve a string '0' (a wave 2 já tinha medido isso), então `conta: 0` montava `route('…', { conta: '0' })` — destino inventado de T-178-05. `contaParaRota()` recusa não-string antes do textoUtil. Produção não muda, porque o header já manda string."
  - "O FORMATO da chave (`(empresa|company)-[0-9]+`) NÃO é validado no cliente de propósito: a trava mora na rota (wave 1) e é a autoridade. Repetir o padrão aqui faria o modal se recusar a abrir em silêncio no dia em que o servidor aceitar um prefixo novo."
  - "Esc fecha por DOIS caminhos (o DismissableLayer do Radix e o ouvinte de window do hook). O setter é idempotente; a redundância é segura e está escrita no comentário para ninguém 'limpar' um dos dois."
  - "A asserção do destino antigo em publicador-header-render.test.js foi REAPONTADA com veto explícito — 1 linha de assert sai, 4 asserções nomeadas entram. Zero removidas sem substituta."

metrics:
  duration: ~85min
  completed: 2026-10-12
  tasks: 3
  arquivos_criados: 1
  arquivos_modificados: 5
  testes_novos: 46
  mutacoes: 29
  medidas_fid01: 12

requirements-completed: [NOVA-01, NOVA-07, NOVA-09]
---

# Fase 178 Plano 03: a casca do modal "Nova publicação" Summary

**A caixa de 620px que abre a 110px do topo sobre o preto a 65%, portada para fora do header porque
o `backdrop-filter` dele recortaria qualquer `position:fixed` escrito ali dentro — com os dois botões
do header, ⌘K/Ctrl+K e N abrindo, Esc e clique fora fechando, o foco caindo no input de busca, uma
única ida ao endpoint da wave 1 por conta, e o clique num item navegando para a `url` que o servidor
montou.**

## Performance

- **Duração:** ~85 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 1 criado, 5 modificados
- **Commits:** 3 de tarefa + os de documentação

---

## ⚠️ NENHUM EXECUTOR VIU TELA RENDERIZADA

**Não há navegador neste ambiente.** Toda a prova de fidelidade aqui é (a) medida lida no
`new_layout_publicador/PublicadorHeader.dc.html` e (b) classe conferida na string exportada ou no
HTML que o `react-dom/server` produziu. **A conferência visual é do usuário, em produção, na conta
459.** O que mais precisa de olho humano: o modal abrindo exatamente a 110px do topo (não centrado),
o texto do modal em **Geist** (se vier em Inter, o `font-pub` da caixa não chegou), e o Esc/clique
fora fechando.

---

## O diff do header, linha por linha

`PublicadorHeader.jsx`: **47 linhas acrescentadas, 15 removidas**. Nada da nav, do seletor de
empresa, da pill de conexão, das abas ou do slot de ações mudou.

| # | O que mudou | Por quê |
|---|---|---|
| 1 | `import { useState } from 'react'` (novo) | o estado de abertura do modal |
| 2 | `router` **saiu** de `import { Link, router, usePage }` | era usado SÓ em `abrirNovaPublicacao` (2 ocorrências no arquivo antigo, conferidas por grep ANTES de remover, as duas no caminho trocado). Import não usado é código morto, e o gate de vocabulário não o pegaria |
| 3 | `import ModalNovaPublicacao from './ModalNovaPublicacao'` (novo) | a casca |
| 4 | `const [novaPublicacaoAberta, setNovaPublicacaoAberta] = useState(false)` | o único estado de abertura deste arquivo — os dois popovers do 177-06 continuam cuidando dos seus |
| 5 | `abrirNovaPublicacao()`: `router.get(route('…produtos'))` → `setNovaPublicacaoAberta(true)` | **a assinatura e os chamadores não mudaram** — é o que a 177-04 prometeu por escrito. A guarda `if (!temConta) return` continua a PRIMEIRA linha |
| 6 | `fecharTudo()`: `{}` → `setNovaPublicacaoAberta(false)` | o `noop` documentado existia esperando esta fase |
| 7 | `<ModalNovaPublicacao aberto aoFechar conta />` depois da `</nav>`, dentro do `<header>` | o conteúdo é portado; o lugar do JSX só define quem é o pai React |
| 8 | 3 blocos de comentário atualizados | o slot *"o modal … → Fase 178"*, o aviso do `EXMP-03` e os comentários de `abrirNovaPublicacao`/`fecharTudo`. Comentário que descreve o comportamento antigo é pior que comentário nenhum |

**O que NÃO mudou e está provado por diff vazio no intervalo `da54a9ee..HEAD`:** `atalhosDoHeader.js`
(o contrato `aoAbrirBusca`/`aoFechar` já era o certo), `AppLayout.jsx`, `resources/js/Pages` inteiro
(as 9 páginas seguem com corpo antigo), `Editor.jsx` e `Components/Publicador/Mesa` (território de
outro dev, ativo), e os quatro arquivos de pacote.

**As duas ocorrências de `onClick={abrirNovaPublicacao}` continuam duas.** Há asserção de contagem
exata (não tocada nesta fase) e uma mutação que troca UMA delas por um `onClick` inline — reprovou.

---

## ⚠️ FID-01 — protótipo × implementado, medida por medida

Todas **medidas em `new_layout_publicador/PublicadorHeader.dc.html`**, não copiadas da tabela do
plano. Nas 12 linhas da casca, **as 12 bateram com a tabela** — nenhuma divergência de medida nesta
wave (as divergências abaixo são de **mecanismo**, declaradas).

| # | Elemento | Protótipo (arquivo:linha) | Implementado |
|---|---|---|---|
| 1 | fundo — posição | `position:fixed; inset:0` (**:133**) | `fixed inset-0` em `CLASSES_FUNDO` |
| 2 | fundo — camada | `z-index:80` (**:133**) | `z-[80]` |
| 3 | fundo — cor | `rgba(0,0,0,0.65)` (**:133**) | `bg-black/[0.65]` |
| 4 | caixa — largura | `width:620px` (**:134**) | `w-[620px]` |
| 5 | caixa — topo | `padding-top:110px` no fundo (**:133**) | `top-[110px]` na caixa (divergência 1) |
| 6 | caixa — centro | `justify-content:center` + `align-items:flex-start` (**:133**) | `left-1/2 -translate-x-1/2` (divergência 1) |
| 7 | caixa — fundo | `background:#111111` (**:134**) | `bg-pub-surface` |
| 8 | caixa — borda | `1px solid #2E2E2E` (**:134**) | `border border-pub-line-strong` |
| 9 | caixa — raio | `border-radius:16px` (**:134**) | `rounded-pub-card` (= 16px, `tailwind.config.js:164`) |
| 10 | caixa — sombra | `box-shadow:0 30px 80px rgba(0,0,0,0.8)` (**:134**) | `shadow-pub-modal` (valor idêntico, `tailwind.config.js:177`) |
| 11 | caixa — corte | `overflow:hidden` (**:134**) | `overflow-hidden` |
| 12 | caixa — fonte/cor do texto | o protótipo é HTML cru com a Geist no documento; a cor `#F4F4F5` é declarada nos filhos | `font-pub text-pub-txt` **na caixa** — obrigatório, o conteúdo portado não herda nada do header |

---

## Divergências DECLARADAS (restrição concreta, não preferência)

Todas estão escritas no topo de `ModalNovaPublicacao.jsx`, para a próxima pessoa não "consertar".

### 1. O MECANISMO de centralização (contêiner flex → `translate`)

O protótipo centraliza com um contêiner flex **no próprio backdrop**
(`display:flex; justify-content:center; align-items:flex-start; padding-top:110px`, **:133**), e a
caixa é FILHA dele. No Radix, `Dialog.Overlay` e `Dialog.Content` são **irmãos** dentro do portal —
o Content não é filho do Overlay —, então a caixa se posiciona sozinha com
`fixed left-1/2 top-[110px] -translate-x-1/2`. **Para uma caixa de largura fixa (620px) o resultado
computado é idêntico:** 110px do topo da janela, centrada na horizontal.

Efeito colateral declarado: os **dois** declaram `z-[80]` (no protótipo só o fundo precisa). Sendo
irmãos no mesmo z-index, quem vem depois na ordem do documento é pintado em cima — e há asserção de
que `Dialog.Overlay` aparece antes de `Dialog.Content` na fonte.

E o `stop` do protótipo (**:229**, um `stopPropagation` na caixa) **não tem equivalente aqui**: ele
existe só porque lá o backdrop é o PAI, e o clique na caixa subiria para o `closeModal` do fundo.
Sem esse pai, não há o que parar — e há asserção negativa provando que nenhum `stopPropagation`
entrou no arquivo.

### 2. `bg-black/[0.65]` em vez de um token novo

É exatamente `rgba(0,0,0,0.65)`. Não existe token `pub-*` para preto puro com alfa e **não se
inventa um**: `black` é cor padrão do Tailwind, não hex literal — TOK-15 segue cumprido (há asserção
de que nenhum hex entrou no arquivo).

### 3. `onOpenAutoFocus` em vez do `setTimeout(…, 30)` do protótipo (**:204**)

O protótipo precisa do timer porque lá não há gerenciador de foco nenhum. Aqui o Radix já move o
foco para dentro da `Dialog.Content` na abertura; `preventDefault()` + `inputRef.current?.focus()`
torna o **alvo determinístico** (o input de busca) em vez de depender da ordem do DOM — e sem timer,
que é o que poderia correr contra a montagem. Há asserção negativa de `setTimeout`.

### 4. Título acessível invisível

O Radix 1.1.15 escreve no console quando a `Dialog.Content` não tem `Dialog.Title`
(`node_modules/@radix-ui/react-dialog/dist/index.mjs:288`, lido antes de decidir), e o protótipo
**não tem título visível**. Entrou `<Dialog.Title className="sr-only">Nova publicação</Dialog.Title>`
— `sr-only` é classe padrão do Tailwind e **nenhum pixel** é acrescentado à tela. Mesmo motivo para
`aria-describedby={undefined}`, que é o escape documentado do próprio Radix para "este diálogo não
tem descrição" (`index.mjs:304`).

### 5. `consulta`, `carregando` e `erro` são estado desta casca

O protótipo guarda `mq` no componente e a lista dele é mock constante — sem carga e sem falha. Nada
disso muda o desenho: as três frases usam a geometria do vazio (**:156**), decidida na wave 2. Ao
fechar, a consulta é zerada: é o `mq: ''` do `open()` (**:204**).

---

## ⚠️ A cirurgia de gate — classificada

| Classificação | Quantas | Quais |
|---|---|---|
| **REAPONTADAS** | **1** | `assert.match(fonte, /router\.get\(route\('mlb\.anuncios\.publicador\.produtos'/)` em `publicador-header-render.test.js` |
| **ENDURECIDAS** | **0** | nenhuma asserção da wave 2 ficou frouxa com a casca no ar (o corpo é renderizado direto, fora do portal, e a casca não toca em nenhum dos 11 elementos travados na constante `FID`) |
| **REMOVIDAS** | **0** | — |

**A prova:** `git diff da54a9ee..HEAD -- tests/ | grep '^-' | grep assert` devolve **uma única
linha** — a reapontada acima. As substitutas, todas nomeadas:

1. `assert.doesNotMatch(fonte, /router\.get\(route\('mlb\.anuncios\.publicador\.produtos'/)` — o
   **veto explícito** do destino antigo (sem ele, o nome novo do bloco apagaria a memória de que
   aquele destino é proibido; é a lição do item 1 da cirurgia da 177-09);
2. `assert.doesNotMatch(fonte, /\brouter\b/)` — o import morto não pode voltar (conferido por grep
   que `router` realmente saiu ANTES de escrever a asserção);
3. `assert.match(fonte, /<ModalNovaPublicacao/)` + `from './ModalNovaPublicacao'`;
4. a regex da função inteira
   `function abrirNovaPublicacao() { if (!temConta) return; setNovaPublicacaoAberta(true); }` — prova
   que a abertura está **depois** da guarda de conta, não num `onClick` solto que a pularia.

Mais o bloco **novo** de render real: com o modal fechado, nenhuma das 7 medidas da casca aparece no
HTML do header, e as contagens exatas que a 177 travou (`h-[34px]`=8, `font-pub-mono`=4,
`text-[10.5px]`=4, `font-pub`=1) foram **medidas depois** da montagem do modal e seguem idênticas.
A asserção dos dois `onClick={abrirNovaPublicacao}` **não foi tocada**.

---

## ⚠️ A prova por mutação — 29 mutações, 29 reprovações

Rodadas em **12/10/2026** no código real (`ModalNovaPublicacao.jsx`, `PublicadorHeader.jsx`), com
**contagem de ocorrências travada antes de aplicar**, registro de **qual linha mudou**, e restauração
por cópia pristina **conferida por sha256** depois de cada uma.

| # | mutação | arquivo | linha | veredito |
|---|---|---|---|---|
| 1 | sem `Dialog.Portal` | Modal | 269 | REPROVOU |
| 2 | `w-[620px]` → `w-[600px]` | Modal | 101 | REPROVOU |
| 3 | `top-[110px]` → `top-[100px]` | Modal | 101 | REPROVOU (depois de corrigida, ver abaixo) |
| 4 | `z-[80]` → `z-50` na caixa | Modal | 101 | REPROVOU |
| 5 | caixa **sem** `font-pub` | Modal | 101 | REPROVOU |
| 6 | `font-pub` → `font-pub-mono` na caixa (**prova o prefixo**) | Modal | 101 | REPROVOU |
| 7 | `rounded-pub-card` → `rounded-pub-panel` | Modal | 101 | REPROVOU |
| 8 | `shadow-pub-modal` → `shadow-pub-popover` | Modal | 101 | REPROVOU |
| 9 | `bg-black/[0.65]` → `bg-black/60` | Modal | 88 | REPROVOU |
| 10 | sem `-translate-x-1/2` (a caixa descentra) | Modal | 101 | REPROVOU (depois de corrigida) |
| 11 | sem `text-pub-txt` na caixa | Modal | 101 | REPROVOU |
| 12 | `onEscapeKeyDown={(e) => e.preventDefault()}` acrescentado | Modal | 273 | REPROVOU |
| 13 | sem `onOpenAutoFocus` (o input não recebe foco) | Modal | 274 | REPROVOU |
| 14 | `router.get(destino)` → `route('…editor', …)` montado no cliente | Modal | 256 | REPROVOU |
| 15 | `contaJaPedida` removido (fetch a CADA abertura) | Modal | 225 | REPROVOU |
| 16 | `precisaBuscar` aceitando `aberto` **truthy** | Modal | 162 | REPROVOU |
| 17 | `contaParaRota` voltando a ser só `textoUtil` (**o furo do `0`**) | Modal | 131 | REPROVOU |
| 18 | `setDados(resposta.data)` cru, sem `normalizarPayload` | Modal | 235 | REPROVOU |
| 19 | falha em silêncio (sem `setErro(true)`) | Modal | 240 | REPROVOU |
| 20 | `inputRef` não desce para o painel | Modal | 287 | REPROVOU |
| 21 | sem `Dialog.Title` (o modal fica anônimo) | Modal | 279 | REPROVOU |
| 22 | o corpo reimplementado em vez de importado | Modal | 280 | REPROVOU |
| 23 | header **sem** `<ModalNovaPublicacao>` | Header | 288 | REPROVOU |
| 24 | `abrirNovaPublicacao` voltando a NAVEGAR | Header | 135 | REPROVOU |
| 25 | `fecharTudo` virando noop de novo (Esc não fecha) | Header | 146 | REPROVOU |
| 26 | a guarda `if (!temConta) return` removida | Header | 133 | REPROVOU |
| 27 | um dos dois botões deixando de compartilhar a função | Header | 205 | REPROVOU |

(as 29 contam as duas rodadas das mutações 3 e 10 — a recusada e a corrigida)

**Restauração final conferida por sha256:**

```
919503a727470cfc96d8b01c6ae201d2c458d49ebacae00f7b883d869456a3ed  ModalNovaPublicacao.jsx
263040339b97dcabe8836579f0c644e7dc731192c5b8fe3d2436d2bc60f9622f  PublicadorHeader.jsx
```

### ⚠️ Duas mutações foram RECUSADAS pelo detector — a armadilha da wave 2, de novo

As mutações **3** (`top-[110px]` → `top-[100px]`) e **10** (tirar `-translate-x-1/2`) foram recusadas
**antes de aplicar**: **2 ocorrências, esperava 1**. A segunda ocorrência das duas está no
**comentário** que declara a divergência de centralização — o que explica por que a caixa se posiciona
com `translate` em vez do flex do protótipo. Com `sed`/`String.replace` a troca cairia no comentário,
o teste passaria e o relatório registraria **dois furos que não existem** (é a origem dos 3 "furos"
da Fase 177). Refeitas com alvo único (`top-[110px] z-[80]` e
`w-[620px] -translate-x-1/2 overflow-hidden`), as duas reprovaram de verdade, na **linha 101**, que é
a constante.

---

## ⚠️ Dois BUGS REAIS que o gate pegou (Regra 1/Regra 2 — corrigidos)

### 1. `conta` como NÚMERO montava um destino inventado — nasceu `contaParaRota()`

Ao escrever o gate de "conta inútil não vai à rede", o caso `conta: 0` **passou quando não devia**:
`textoUtil(0)` devolve a **string `'0'`** (a própria wave 2 mediu e escreveu isso — "o `0` não conta
como ausência"). Com só `textoUtil`, um `conta` numérico produziria
`route('mlb.anuncios.publicador.nova-publicacao', { conta: '0' })`: exatamente o destino inventado de
T-178-05, com uma requisição desperdiçada contra um 404.

**Correção (Regra 2 — validação ausente):** `contaParaRota()` recusa não-string **antes** do
`textoUtil`, e é a única fonte de `contaUsavel` no arquivo. **Produção não muda** — o header já
normaliza a chave para string ou `null`; o que muda é o caminho adverso. Coberto por 4 casos novos
exercitados (número, vazio/espaço, nulo/booleano, objeto/array) e pela mutação 17.

### 2. `publicador-configuracoes-render.test.js` ficou VERMELHO — e não era o código

A suíte completa acusou **2 blocos** daquele arquivo com
`Error: Dynamic require of "react" is not supported`. Causa: `Configuracoes.jsx` monta o
`PublicadorHeader`, que agora arrasta o `ModalNovaPublicacao` → `@radix-ui/react-dialog` →
`react-remove-scroll`, que é **CJS**. Sem `'@radix-ui/react-dialog'` no `external` do esbuild, o
bundle quebra no `import()`.

**Correção (Regra 3 — bloqueio):** a mesma linha acrescentada ali e em
`publicador-header-render.test.js`, com o motivo escrito. ⚠️ Vale o aviso para as waves seguintes:
**qualquer harness que bundle uma página do Publicador precisa do Dialog no `external`** a partir
desta wave. Os outros dois harnesses do header (`-abas-render` e `-conta-render`) **não** precisaram
— foram medidos: eles só LEEM a fonte do header (`lerSemComentarios`), não o bundlam. E
`publicador-header-nas-paginas.test.js` já usa `@radix-ui/*`.

---

## ⚠️ Por que `precisaBuscar()` e `contaParaRota()` são EXPORTADAS

Porque **este `useEffect` não é observável por gate nenhum neste projeto**, e a alternativa seria
leitura de fonte:

- o `node --test` daqui roda **sem jsdom** (não há render de cliente, logo não há efeito);
- e o `Portal` do Radix devolve **`null`** no renderizador de servidor de propósito
  (`@radix-ui/react-portal/dist/index.mjs:13` — `mounted` só vira `true` dentro de um efeito), então
  o conteúdo da casca **não existe** em `renderToStaticMarkup` em nenhuma combinação de props.

Com a decisão fora do efeito, o gate **exercita** as três regras que custam dinheiro: fechado não vai
à rede (T-178-03: `produtosParaTela()` é a consulta mais pesada do módulo), conta inútil não vai à
rede (T-178-05), e a mesma conta não é pedida duas vezes — **mas conta DIFERENTE é**. Mesmo
precedente de `criarOuvinteDeAtalhos` (177-04) e `filtrarGrupos` (178-02).

**Desvio nominal declarado:** o plano pedia um `useRef` chamado `jaPediu` (booleano). Virou
`contaJaPedida`, que guarda a **chave da conta**. Motivo concreto: o header vive nas 9 páginas e uma
navegação do Inertia para **outra empresa** reconcilia o componente em vez de remontá-lo — com um
booleano, o modal mostraria os produtos da **empresa anterior**. Dado errado é pior que dado lento.
`null` continua significando "nunca pedi" **e** "a última tentativa falhou, pode tentar de novo".

---

## Verificação

| Gate | Comando | Resultado |
|---|---|---|
| 1 | `npm run test:js` | **3012 testes / 3011 pass / 1 fail** — o fail é o **pré-existente** `estrutura-grade-glide` ("Características secundárias nasce recolhido"), **não corrigido de propósito**. Baseline era 2966/2965/1 → **+46 casos**, nenhuma regressão |
| 2 | `node --test` nos 6 arquivos do plano | modal: **127/127** · header-render: **79/79** · header-abas: **?/?** e header-conta: **181/181 juntos** · nas-paginas + vocabulário: **199/199** · configuracoes: **15/15** |
| 3 | `git diff --stat da54a9ee..HEAD -- …/atalhosDoHeader.js` | **vazio** |
| 4 | `git diff --stat da54a9ee..HEAD -- …/AppLayout.jsx` | **vazio** |
| 5 | `git diff --stat da54a9ee..HEAD -- resources/js/Pages` | **vazio** — nenhuma das 9 páginas mudou |
| 6 | `git diff --stat da54a9ee..HEAD -- …/Editor.jsx …/Publicador/Mesa` | **vazio** — território de outro dev, ATIVO |
| 7 | `git diff --stat da54a9ee..HEAD -- package.json package-lock.json composer.json composer.lock` | **vazio nos quatro** — **nenhum pacote instalado** (T-178-SC) |
| 8 | `git diff da54a9ee..HEAD -- tests/ \| grep '^-' \| grep assert` | **1 linha**, a reapontada, com 4 substitutas nomeadas acima |
| 9 | `git diff --diff-filter=D --name-only da54a9ee..HEAD` | **vazio** — nenhum arquivo deletado |
| 10 | `npm run build` | **verde**, 38,55s |
| 11 | manifest por JSON (`node --input-type=commonjs`) | **480 entradas**; `resources/js/app.jsx → assets/app-DZqD6M2K.js`; as **8** páginas do Publicador seguem no manifest. `ModalNovaPublicacao` é **Component**, entra no grafo do `app.jsx` e **não** tem entrada própria — correto |
| 12 | `git status --short -- tests/js` | só os 4 arquivos de teste desta wave; **nenhuma fixture órfã** (os stubs `.mjs` deixados por processos mortos durante a campanha foram apagados um por um com `rm -f`, nunca `git clean`) |

**⚠️ Os gates 3-9 foram conferidos como NÃO-vazios por construção.** O mesmo intervalo aplicado a
`resources/js tests/js` devolve exatamente os **10** arquivos desta wave e das anteriores (2247
inserções, 22 remoções) — logo o intervalo está vivo. E os caminhos de território proibido foram
conferidos como **existentes no disco** antes de acreditar no diff vazio deles. Todo gate de diff usa
**intervalo** (`da54a9ee..HEAD`), **nunca** a árvore.

**Nenhum `.php` foi tocado nesta wave** — nenhuma rota, nenhum serviço, nenhuma migration. A baseline
PHP (`tests/{Unit,Feature}/Publicador` = 1848 testes) está intacta por construção e não foi
re-executada: não há caminho pelo qual uma mudança em `.jsx` a afete.

**Nenhum deploy, nenhum push, nenhum comando no VPS.**

### Registro de ameaças

| Threat ID | Como ficou mitigado | Prova |
|---|---|---|
| T-178-05 (destino inventado) | a `url` chega **pronta do servidor**; o cliente não monta rota de destino; `conta` passa por `contaParaRota` antes de qualquer `route()` | veto `doesNotMatch(/route\(['"]mlb\.anuncios\.publicador\.(editor\|produto\|produtos)['"]/)`; contagem de `route(` no arquivo = **1**; `contaParaRota` exercitada com 15 entradas inúteis; mutações 14 e 17 |
| T-178-06 (DoS de usabilidade no "N") | `atalhosDoHeader.js` **não mudou** — o `ignoraAtalho()` que já existe barra `INPUT`/`TEXTAREA`/`SELECT`/`canvas`/`[role=grid]`, e o input do modal é um `INPUT` | gate de diff vazio no arquivo; os testes do ouvinte (177-04) seguem passando sem alteração |
| T-178-03 (fetch do endpoint pesado) | **1 chamada por montagem e por conta**, só na abertura, com nova tentativa só depois de erro | `precisaBuscar` exercitada nos 3 eixos (fechado / conta inútil / já pedida); mutações 15 e 16; `.get(route(` contado = 1 |
| T-178-10 (fidelidade silenciosa: Inter no lugar da Geist) | `font-pub` declarado na `Dialog.Content` | token exato num `Set` + `/font-pub(?![-\w])/` com contagem = 1 + asserção de que `font-pub-mono` **não** está na caixa; mutações 5 e 6 |
| T-178-11 (gate que deixa de provar) | a asserção do destino antigo foi **reapontada** com veto explícito; zero removidas sem substituta; as 4 contagens exatas da 177 foram **medidas de novo** e seguem idênticas | o `grep '^-' \| grep assert` devolve 1 linha, nomeada nesta SUMMARY |
| T-178-SC (pacote novo) | **nenhum pacote instalado** — `@radix-ui/react-dialog ^1.1.15` já era dependência (`package.json:36`) e já estava em `node_modules` | gate de diff vazio nos quatro arquivos de pacote |

---

## Task Commits

| Task | Commit | O que entrou |
|---|---|---|
| 1 | `04244330` | `feat(publicador): ModalNovaPublicacao — a casca de 620px portada (178-03)` |
| 2 | `daff4649` | `feat(publicador): o header ABRE o modal — dois botoes, Cmd+K, N e Esc (178-03)` |
| 3 | `755419be` | `test(publicador): o gate da casca do modal e a cirurgia declarada (178-03)` — inclui as duas correções de Regra 1/2/3 |

Todos com pathspec (`git add -- <caminhos>` e `git commit -F <arquivo> -- <caminhos>`), porque a
árvore é **compartilhada** com outro dev ativo e outras sessões. Nenhum `git add -A`, `git stash`,
`git reset`, `git checkout`, `git clean` nem `--amend`.

## Arquivos criados/modificados

- `resources/js/Components/Mlb/Publicador/header/ModalNovaPublicacao.jsx` **(novo, 293 linhas)** — a
  casca: `CLASSES_FUNDO`, `CLASSES_CAIXA`, `ROTA_NOVA_PUBLICACAO`, `contaParaRota()`,
  `precisaBuscar()` e o componente (estado, fetch, foco, `escolher`)
- `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx` — **+47/−15**, o diff explicado
  linha por linha acima
- `tests/js/publicador-modal-nova-publicacao.test.js` — **+287 linhas, 0 removidas**: 6 suítes novas
  (33 casos) para a casca; **nenhuma asserção da wave 2 alterada**
- `tests/js/publicador-header-render.test.js` — **+114/−4**: a cirurgia declarada e o bloco de render
  real com o modal fechado
- `tests/js/publicador-vocabulario-novo.test.js` — **+7 linhas**: `ModalNovaPublicacao.jsx` em
  `ARQUIVOS_NOVOS` (a lista é constante de propósito; esquecer faria o gate passar por **omissão**)
- `tests/js/publicador-configuracoes-render.test.js` — **+8/−1**: o `external` do Dialog (Regra 3)

## Desvios do plano

### 1. [Regra 2 — validação ausente, achada pelo gate] `contaParaRota()` nasceu

Descrito acima. O plano pedia só `conta === null → não chama a rede`; medido, `textoUtil(0)` devolve
`'0'` e o número passava. Commit `755419be`.

### 2. [Regra 3 — bloqueio] `@radix-ui/react-dialog` no `external` de DOIS harnesses, não um

O plano previu a linha em `publicador-header-render.test.js` (item A.6) e ela era de fato
obrigatória. O que o plano não previu é que `publicador-configuracoes-render.test.js` bundla uma
página que monta o header e quebrava pelo mesmo motivo — só a suíte COMPLETA acusou. Commit
`755419be`.

### 3. [desvio nominal] `jaPediu` (booleano) virou `contaJaPedida` (a chave)

Descrito acima, com o motivo concreto (troca de empresa sem remontagem mostraria a lista da empresa
anterior). Estritamente mais forte; a mutação 15 prova o comportamento.

### 4. [adição] `precisaBuscar()` e `ROTA_NOVA_PUBLICACAO` exportados

O plano não previu exports além de `CLASSES_FUNDO`/`CLASSES_CAIXA`. Entraram porque o plano **exige**
que a guarda de conta nula seja provada **exercitando a função, não lendo a fonte** (item B) — e sem
jsdom e sem portal no servidor não existe outro jeito.

### 5. [informativo] 29 mutações em vez das 17 nominais

As 17 da lista do plano entraram todas (as 3 e 10 depois de corrigidas), mais as que a lista não
cobria: `-translate-x-1/2`, `text-pub-txt`, `precisaBuscar` com `aberto` truthy, `contaParaRota`,
payload cru em estado, falha em silêncio, `inputRef` que não desce, `Dialog.Title` ausente, corpo
reimplementado e um dos botões deixando de compartilhar a função.

**Total de desvios:** 5. **Impacto:** nenhum aumento de escopo; dois deles são bugs que o gate
existia para pegar.

## Problemas encontrados

- Durante a campanha de mutação, processos do `node --test` que morreram no meio deixaram **4 stubs
  órfãos** (`tests/js/.header-inertia-stub-*.mjs`, `.modal-nova-inertia-stub-*.mjs`) — o `after()` não
  dispara quando o processo cai. Apagados **um por um com `rm -f`**, nunca com `git clean` (que num
  worktree apagaria trabalho commitado de outra wave).
- `git status --short -- tests/` mostra `?? tests/Feature/CompanyPortfolioAccessTest.php`. **Não é
  meu** — já estava não rastreado antes desta wave; não foi tocado, commitado nem apagado.
  `public/images/*` idem. `public/build` é ignorado (`.gitignore:41`), então o `npm run build` não
  sujou a árvore.
- ⚠️ **Herança para as waves seguintes:** a partir daqui, **todo harness de esbuild que bundle o
  `PublicadorHeader` (ou qualquer página que o monte) precisa de `'@radix-ui/react-dialog'` no
  `external`**. O sintoma é `Dynamic require of "react" is not supported` e **não** é falha do código.
- Nenhum item para `deferred-items.md`.

## Perguntas em aberto

1. ⚠️ **Herdada e ainda aberta (wave 1 e 2): a nota de `PRONTOS PARA FASE 2` diz "{n} no ar", e o
   protótipo diz "Clássico e Premium".** É a única divergência de **conteúdo** da fase e resolvê-la
   muda o **contrato do servidor**, não o render. Esta wave não mexeu em nada disso.
2. **O modal abre a 110px do topo, medido do topo da JANELA.** O protótipo mede o mesmo (`:133`), mas
   o header real é `sticky` dentro de um `<main>` que rola: com a página rolada, o modal continua a
   110px da janela (e não "logo abaixo do header", que ali tem 109px). É o que o protótipo desenha, e
   por isso ficou assim — **mas é um ponto para o olho do usuário** na conferência visual.
3. **`conta` com formato estranho (ex.: `'0'` como string) ainda vai à rede e toma 404.** Foi decisão
   declarada: a trava `(empresa|company)-[0-9]+` mora na rota e é a autoridade; repetir o padrão no
   cliente faria o modal se recusar a abrir em silêncio quando o servidor aceitar um prefixo novo.

## Prontidão para a wave 4

- O modal está **ligado e fechado por padrão**. Abre pelos dois botões, por ⌘K/Ctrl+K e por "N" fora
  de campo de digitação; fecha com Esc (dois caminhos) e clique fora.
- `CLASSES_FUNDO`/`CLASSES_CAIXA` são o ponto de medida da geometria da casca — e **o gate compara a
  string INTEIRA**: qualquer classe nova exige atualizar a asserção no mesmo commit (atualizar não é
  afrouxar).
- `precisaBuscar()` e `contaParaRota()` são puras e exportadas: quem precisar de outra regra de
  abertura/fetch estende **ali**, não dentro do efeito.
- O corpo (`PainelNovaPublicacao`) continua puro e **fora** do portal, e a constante `FID` do gate da
  wave 2 segue intacta — nenhum dos 11 elementos travados foi tocado.

---
*Fase: 178-modal-nova-publicacao · Plano 03*
*Concluído: 2026-10-12*
