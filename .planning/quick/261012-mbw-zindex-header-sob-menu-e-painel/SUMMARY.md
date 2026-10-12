---
tipo: quick
slug: mbw-zindex-header-sob-menu-e-painel
data: 2026-10-12
status: complete
commits:
  - f8fea7dc docs(publicador) — o plano
  - bc4fa01b fix(publicador) — z-50 -> z-30 no header + os 6 arquivos de teste reapontados
  - 2519595a test(publicador) — gate de ordem lendo os tres z do codigo
files_modified:
  - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
  - tests/js/publicador-header-render.test.js
  - tests/js/publicador-header-nas-paginas.test.js
  - tests/js/publicador-lote.test.js
  - tests/js/publicador-paginas-anuncios-render.test.js
  - tests/js/publicador-produtos-fases.test.js
  - tests/js/publicador-produtos-layout.test.js
files_created:
  - tests/js/publicador-header-zindex-ordem.test.js
supersede:
  - HEAD-01 (.planning/REQUIREMENTS-v26.md:307) — o `z-index: 50` foi superado
---

# O que foi feito

O `PublicadorHeader` passou de **`z-50` para `z-30`** — **uma linha de produção**. Com isso
ele deixa de cobrir o menu lateral do sistema e o painel lateral do produto, e continua
cobrindo o corpo da página ao rolar.

O custo real não foi o conserto: foi reapontar os **6 arquivos de teste** que travavam o valor
antigo (9 linhas, 4 delas comentário) e **acrescentar o gate de ordem que faltava** — o que
teria pegado este bug antes do usuário.

# O relato (12/10/2026, conferido em produção)

> *"Ao passar o mouse sobre menu lateral do sistema ele se expande mas o header do publicador
> continua em cima sobrepondo o menu lateral do sistema"*
>
> *"Ao clicar em algum produto na tabela aparece um sidebar do lado direito, o header está por
> cima desse sidebar, isso está errado, o sidebar deveria ficar por cima"*

# Eram dois sintomas de UM bug

| Elemento | Onde | z-index antes | depois |
|---|---|---|---|
| `PublicadorHeader` | `header/PublicadorHeader.jsx` | **`z-50`** | **`z-30`** |
| Menu lateral do sistema (expande no hover) | `AppLayout.jsx:978` | `z-40` | `z-40` (intocado) |
| Painel lateral do produto | `PainelDoProdutoLateral.jsx:155` | `z-40` | `z-40` (intocado) |

E os três disputam no **MESMO contexto de empilhamento**. O `<main>` do `AppLayout` (`:1048`) é
`flex-1 overflow-y-auto p-6` — **sem `z-index`, sem `transform`** — e o pai dele é
`flex-1 flex flex-col min-w-0 overflow-hidden`; **`overflow-hidden` NÃO cria contexto de
empilhamento**. Logo o `z-50` do header comparava direto, na raiz, com os dois `z-40` e ganhava
dos dois. Não era um bug do menu nem do painel: era o header.

## Por que `z-30` e não outro valor (medido, não escolhido por gosto)

- O conteúdo das páginas do Publicador tem no máximo **`z-20`** (`Produtos.jsx:404`, dropdown;
  `Editor.jsx:364`, rodapé sticky) e **`z-10`** (`Produtos.jsx:888` e `PublicacaoEmLote.jsx:247`,
  o bloco de filtros `sticky top-[108px]`). `z-30` fica **acima** de todos — o header continua
  cobrindo o corpo ao rolar, que é a função dele.
- `z-30` fica **abaixo** dos dois `z-40` — o menu expandido e o painel passam a cobrir o header.

O conserto foi **no header**, de propósito: mexer no `z-40` do `AppLayout` ou do painel afetaria
o sistema inteiro, muito além do Publicador.

## O que NÃO foi afetado (conferido antes de mexer)

Os dois `Popover.Content` do header são `z-[61]` (`SeletorDeEmpresaDoHeader.jsx:403`,
`ConexaoDoHeader.jsx:417`) e o modal "Nova publicação" é `z-[80]` (`ModalNovaPublicacao.jsx:88`
e `:101`). Os três **portam para o `document.body`** via `Dialog.Portal`/`Popover.Portal`: vivem
na raiz e **não dependem do z do header**. Intocados.

## Efeito colateral ESPERADO E DESEJADO — não "consertar"

O painel do produto é `fixed inset-0 z-40` **com overlay `bg-black/50`**
(`PainelDoProdutoLateral.jsx:162`). Com o header em `z-30`, esse overlay **escurece o header
também**. É o comportamento correto que o usuário pediu ("o sidebar deveria ficar por cima").
Quem achar isso estranho numa sessão futura: **não suba o z de volta** — isso desfaz o conserto.
O porquê está escrito em comentário no próprio `PublicadorHeader.jsx`, acima da tag.

# HEAD-01 ficou SUPERADO (e o REQUIREMENTS-v26 NÃO foi editado)

`.planning/REQUIREMENTS-v26.md:307` diz literalmente:

> **HEAD-01** — Fixo no topo com `position: sticky; top: 0; z-index: 50` **dentro do `<main>` do
> `AppLayout`** [...]

O **`50` foi superado por conflito medido com o layout do sistema**, e o registro fica aqui de
propósito: o arquivo de requisitos não é editado por quick task. O `sticky top-0` e o "dentro do
`<main>` do `AppLayout`" continuam valendo integralmente — **só o número do `z-index` mudou**,
de `50` para `30`, pelo motivo acima.

**Para a próxima sessão:** se você leu `z-index: 50` no HEAD-01 e veio "consertar de volta",
pare. Rode `node --test tests/js/publicador-header-zindex-ordem.test.js` e leia o comentário no
topo daquele arquivo.

# Os gates: onde esteve o trabalho

## Os 6 arquivos reapontados, nenhum relaxado

| Arquivo | O que era | O que ficou |
|---|---|---|
| `publicador-header-render.test.js:188` | `z-50` na lista por **igualdade de conjunto** | `z-30` na mesma lista, **ainda igualdade de conjunto** |
| `publicador-header-nas-paginas.test.js:261` | regex ancorada em `^<div><header class="...z-50` | mesma âncora `^`, valor `z-30` |
| `publicador-lote.test.js:516` | asserção `z-50` (+ comentário em `:513`) | `z-30` + comentário corrigido |
| `publicador-paginas-anuncios-render.test.js:244` | `z-50` na lista de classes da tag | `z-30` |
| `publicador-produtos-fases.test.js:401` | **só comentário** citando `z-50` | comentário corrigido |
| `publicador-produtos-layout.test.js:1306` | asserção `z-50` (+ comentários `:1299`/`:1301`) | `z-30` + comentários corrigidos |

**Atualizar não é afrouxar:** a igualdade de conjunto continua igualdade de conjunto (classe a
mais reprova), as regexes continuam ancoradas. O que mudou foi o valor, não o rigor.

Os **4 comentários** foram corrigidos porque passariam a mentir. Isso não é zelo: a tabela FID da
Fase 177 divergiu do protótipo em 21 medidas justamente por documento que envelheceu. Onde o
`z-50` sobrou, sobrou **só como registro histórico explícito** ("era `z-50` até 12/10/2026") —
nenhuma asserção carrega o valor antigo (conferido por grep separando comentário de asserção).

## O gate novo: prova a ORDEM, não o valor

`tests/js/publicador-header-zindex-ordem.test.js` — 7 testes. Lê **do código** os quatro pontos
que disputam na raiz e prova a desigualdade:

```
filtros da grade (z-10)  <  header (z-30)  <  menu lateral = painel do produto (z-40)
```

**Por que não bastava conferir `z-30`:** um gate que só confere o valor **volta a passar** se
alguém baixar o menu lateral para `z-20` amanhã — e o bug volta sem ninguém ver. O que precisa
ser verdade não é um valor, é uma **desigualdade entre arquivos**.

O que precisaria estar errado no código para este gate falhar (respondido antes de escrevê-lo):

- o header subir para `z-40`+ → volta a cobrir menu e painel (o bug relatado)
- o menu lateral do `AppLayout` cair para `z-30` ou menos → mesmo sintoma
- o `PainelDoProdutoLateral` cair para `z-30` ou menos → mesmo sintoma
- o header cair para `z-10` ou menos → ficaria **atrás** do corpo ao rolar (sintoma oposto,
  igualmente errado)
- qualquer uma das 4 âncoras deixar de casar → a extração **reprova por contagem**, em vez de
  devolver `null` em silêncio e passar pelo motivo errado

Decisões de construção do gate:

- **Só lê a fonte** (via `lerSemComentarios` de `tests/js/_fonte.js`) — sem isso leria a própria
  prosa em pt-BR que cita os valores. **Não bundla nada**, logo **não precisa** do
  `@radix-ui/react-dialog` no `external` do esbuild (herança da Fase 178 que vale só para os
  harness que bundlam o header).
- **Lookahead de espaço/aspa em toda extração.** `z-3` não casa dentro de `z-30` nem `z-30`
  dentro de `z-300` — e isso é **provado contra strings sintéticas** num teste próprio.
  Substring casando com prefixo já mordeu este projeto **quatro vezes**.
- **Duas iscas:** âncora inventada (`zz-NN`) tem de dar 0 ocorrências, e os comentários têm de
  sair da fonte lida. Sem isca, uma sonda quebrada diz "presente" para tudo.

## Prova por mutação — 5 cenários, no arquivo REAL

Contagem de ocorrências travada em 1 **antes** de aplicar, linha alterada conferida, restauração
por **sha256**:

| # | Mutação | Linha | Resultado |
|---|---|---|---|
| A | header `z-30` -> `z-40` (empata com os dois) | `PublicadorHeader.jsx:171` | 3 testes reprovam |
| B | menu lateral `z-40` -> `z-20` | `AppLayout.jsx:978` | 2 testes reprovam |
| C | painel do produto `z-40` -> `z-20` | `PainelDoProdutoLateral.jsx:155` | 2 testes reprovam |
| D | header `z-30` -> `z-5` (sintoma oposto) | `PublicadorHeader.jsx:171` | 2 testes reprovam |
| E | âncora podre (`zIndex30`) | `PublicadorHeader.jsx:171` | 6 testes reprovam |

E a mutação dos 6 gates antigos: com `z-50` de volta no arquivo real, **23 testes reprovam em 5
dos 6 arquivos**. O `publicador-produtos-fases.test.js` **não** reprova — e isso está correto e
registrado: ali o `z-50` era **só comentário**, nunca houve asserção. Era exatamente a armadilha
da Fase 178 (três mutações recusadas porque o alvo aparecia também em comentário); aqui a
contagem travada e a conferência de **qual** linha mudou impediram registrar furo inexistente.

`AppLayout.jsx` e `PainelDoProdutoLateral.jsx` voltaram **byte a byte** (sha256 idêntico) e não
aparecem em commit nenhum.

# Build, manifest e CSS construído

- `npm run build` verde (31,45s).
- **Manifest pelo JSON**, nunca por grep (o hash sai com hífen — o chunk desta rodada é
  `AnunciarMassa-hl-o7-1-.js`, prova viva disso). As **9 páginas consumidoras** do
  `PublicadorHeader` estão no manifest (6 em `Pages/Mlb/Publicador/` + `AnunciarMassa`,
  `AnunciosHistorico`, `MeusAnuncios`). Isca: chave inventada deu **ausente**.
- **`z-30` provado no CSS construído:** `.z-30{z-index:30}` presente em `assets/app-Cg8qkwwN.css`
  (caminho lido do manifest). `z-40`, `z-20`, `z-10` e `z-50` também presentes com o valor certo
  (`z-50` segue existindo — outros componentes usam).
  **3 iscas, todas ausentes:** `.z-37` (valor que o Tailwind não gera), `.z-300` (a armadilha do
  prefixo) e `.z-31` (vizinho imediato).
- **O header compilado usa a classe nova:** `1` chunk contém
  `sticky top-0 z-30 -mx-6 -mt-6 min-w-[1240px]` (`PublicadorHeader-pLHBbxrI.js`) e **`0` chunks**
  contêm a string antiga com `z-50`.

A 1a versão da sonda de CSS **reprovou 5 de 5 por erro da própria sonda** — o duplo escape do
shell mastigou o regex dentro de `node -e`. Foi reescrita **em arquivo**. É a mesma classe de
falha do fechamento da Fase 178 (14 de 30 na 1a rodada). A isca é o que distingue "a classe não
existe" de "minha sonda está quebrada".

# Baselines (medidos nesta árvore, antes e depois)

| Suíte | Antes | Depois |
|---|---|---|
| PHP `tests/{Unit,Feature}/Publicador` | 1848 testes / 11.421 asserções / **0 falhando** | **idêntico** — 1848 / 11.421 / 0 |
| JS (`npm run test:js`) | 3012 testes / 3011 pass / **1 fail** | 3019 / 3018 pass / **1 fail** |

Os 7 testes a mais no JS são o gate de ordem novo. A falha é a **pré-existente**
`estrutura-grade-glide:123` ("Características secundárias nasce recolhido") — **não corrigida, de
propósito**, está fora do escopo desta task. As **47 `PHPUnit Deprecations`** do Publicador também
são pré-existentes e idênticas antes/depois.

# Gate de escopo

`git diff --stat 978c20d1..HEAD -- app/ database/ resources/js/Layouts/ resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx resources/js/Pages/`
→ **vazio**, **com range**.

E a prova de que o gate **não é vazio por construção** (sem ela, o gate não provaria nada):

1. os 5 caminhos proibidos **existem no disco** (conferido um por um);
2. o **mesmo range**, apontado para onde eu espero arquivos
   (`header/`, `tests/js/`, `.planning/quick/`), devolve **exatamente os 9 arquivos** que eu toquei.

O range todo tem **9 arquivos** e **0 deleções**.

# O que ficou de fora, de propósito

- `AppLayout.jsx` e `PainelDoProdutoLateral.jsx` — o conserto é no header; mexer no `z-40` deles
  afetaria o sistema inteiro.
- Os popovers `z-[61]` e o modal `z-[80]` — portam para o body, não dependem do header.
- O editor (`Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/`) — território de
  outro dev, ativo agora. Foi **lido** (para medir o `z-20` do rodapé sticky), não tocado.
- A falha pré-existente do `estrutura-grade-glide` e as 47 deprecations do PHPUnit.
- **Nenhuma** migration, **nenhum** pacote, **nenhum** deploy, **nenhum** push.

# Conferência pendente — é do usuário

**Não houve tela renderizada aqui** (não existe navegador neste ambiente): a verificação é de
render estático, CSS construído e bundle. O que confirma o conserto é o usuário, em produção, na
conta 459:

1. passar o mouse no menu lateral do sistema → ele expande **por cima** do header do Publicador;
2. clicar num produto da grade → o painel lateral abre **por cima** do header, e o overlay
   escurece o header também (**é o esperado**);
3. rolar a lista de produtos → o header continua **cobrindo** o conteúdo e o bloco de filtros
   continua colando logo abaixo dele (`top-[108px]`);
4. abrir o seletor de empresa, a pill de conexão e "Nova publicação" → os três continuam **por
   cima de tudo** (portam para o body).

# Self-Check: PASSOU

- 4 arquivos declarados conferidos no disco: `PLAN.md`, `SUMMARY.md`,
  `tests/js/publicador-header-zindex-ordem.test.js`, `PublicadorHeader.jsx` — todos presentes.
- 3 commits declarados conferidos no `git log`: `f8fea7dc`, `bc4fa01b`, `2519595a` — todos presentes.
