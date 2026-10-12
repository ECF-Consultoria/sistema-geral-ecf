---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 07
subsystem: ui
tags: [publicador, header-global, sticky, colisao-de-sticky, cirurgia-de-gates, render-real, node-test, head-15]

# Grafo de dependências
requires:
  - "177-03: a prop `cabecalho` (`CabecalhoPublicadorService::para()`) chegando nas 6 páginas — pode ser NULA"
  - "177-04: a casca do `PublicadorHeader` (contrato `aba`/`cabecalho`/`acoes`/`liberada`, `-mx-6 -mt-6`, `font-pub` na tag do `<header>`)"
  - "177-05: `AbasDoHeader` — as 4 abas-pílula com contador do servidor e o D23"
  - "177-06: `SeletorDeEmpresaDoHeader` e `ConexaoDoHeader` — a identidade da conta na linha 1"
  - "Fase 172: `textoSeguro()` de `BarraDaConta.jsx` — segue importado como NAMED pelas páginas que já o usavam"
provides:
  - "As 6 páginas por conta (`VisaoGeral`, `Produtos`, `Produto`, `Alavancas`, `Configuracoes`, `PublicacaoEmLote`) com o `PublicadorHeader` global no topo, sticky, encostado nas bordas"
  - "Os dois blocos sticky das listas reposicionados para `top-[108px]` — regressão funcional evitada"
  - "`tests/js/publicador-header-nas-paginas.test.js`: 37 testes — render REAL das 6 páginas com `cabecalho` objeto, nulo, ausente e com cada campo interno adverso"
  - "`publicador-vocabulario-novo.test.js`: GATE-01 (o header antigo não volta) e HEAD-14 (o header existe, na ordem certa, com a aba certa) — 44 asserções novas"
affects: [177-09, 178, 179, 180, 181, 182]

# Rastreio técnico
tech-stack:
  added: []   # T-177-SC: nenhum pacote instalado
  patterns:
    - "Header global `sticky top-0` no scrollport do `<main>` obriga TODO bloco `sticky top-0` do corpo a descontar a altura dele (`top-[108px]`) — senão o bloco rola para trás do header"
    - "Asserção de `disabled` extraída POR ELEMENTO: `.*?` com flag `/s` no HTML inteiro atravessa elementos e vira falso negativo quando um botão novo nasce desabilitado"
    - "Gate de estrutura por GLOB quando o risco é página NOVA escapar; por CONSTANTE quando o risco é arquivo novo entrar sem revisão — e as duas listas conferidas uma contra a outra"
    - "Prop do servidor entra no teste de render em TRÊS estados (objeto, nulo, ausente), porque o default de parâmetro só cobre `undefined`"

key-files:
  created:
    - tests/js/publicador-header-nas-paginas.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx
    - resources/js/Pages/Mlb/Publicador/Produto.jsx
    - resources/js/Pages/Mlb/Publicador/Alavancas.jsx
    - resources/js/Pages/Mlb/Publicador/Configuracoes.jsx
    - resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx
    - tests/js/publicador-entrada.test.js
    - tests/js/publicador-alavancas.test.js
    - tests/js/publicador-produtos-layout.test.js
    - tests/js/publicador-lote.test.js
    - tests/js/publicador-produtos-fases.test.js      # não estava no plano — mesma prescrição de reaponte
    - tests/js/publicador-tarefas.test.js             # não estava no plano — mesma prescrição de reaponte
    - tests/js/publicador-configuracoes-render.test.js
    - tests/js/publicador-vocabulario-novo.test.js

key-decisions:
  - "NENHUMA das três asserções de aparência antiga precisou sair (GATE-02). Resultado MEDIDO, não suposto: as classes novas moram nos componentes do header e `Produtos.jsx` segue com corpo antigo (HEAD-15)"
  - "`font-pub` NÃO foi aplicado no contêiner das 6 páginas — o orquestrador repassou instrução desatualizada da wave 2 e eu recusei antes da correção chegar. A família é declarada UMA vez, na tag do `<header>` (177-04)"
  - "Os 108px foram CONFERIDOS lendo o header, não aceitos do plano: `h-[60px]` (linha 1) + `h-[48px]` (nav). A `border-b` de 1px fica por cima do bloco, e o `z-50` do header sobre o `z-10` do bloco garante que nada legível se esconda"
  - "Dois furos de gate que o plano não previa foram encontrados e fechados: a regex de `disabled` do lote (falso negativo) e o stub do Inertia sem `usePage` (o esbuild nem compilava)"
  - "Três das seis páginas (`Produto`, `VisaoGeral`, `Alavancas`) não tinham NENHUM teste de render: apagar o header delas não reprovava nada. O arquivo novo existe por causa dessa medida"

patterns-established:
  - "Prova por mutação antes de aceitar o gate: 52 mutações nos arquivos reais, 1 furo encontrado e fechado, restauração conferida por sha256"
  - "Toda alteração de gate classificada (a)/(b)/(c) com justificativa escrita NO PRÓPRIO TESTE, não só na SUMMARY"

requirements-completed: [HEAD-11, HEAD-14, HEAD-15, GATE-01, GATE-02, GATE-04, GATE-05, GATE-06, REG-01, REG-02, REG-03, REND-05, FID-01, FID-02, FID-05]

# Métricas
duration: ~115min
completed: 2026-10-12
---

# Fase 177 Plano 07: o header novo nas 6 páginas do Publicador — Summary

**As 6 telas por conta trocaram `BarraDaConta` + `AbasDaConta` pelo `PublicadorHeader` global, que nasce como primeiro filho do `AppLayout` e encosta nas bordas sem tocar no `AppLayout.jsx`; os filtros das duas listas desceram para `top-[108px]` porque com `top-0` rolariam para trás do header — regressão funcional, não estética; a única remoção de elemento é a trilha, e NENHUMA asserção de comportamento foi apagada (as 12 falhas medidas foram 12 reapontes, zero remoções); e três das seis páginas ganharam a primeira rede de render que já tiveram, depois de a mutação provar que apagar o header delas não reprovava nada.**

⚠️ **O corpo das 6 continua ANTIGO, de propósito (HEAD-15).** Antes de "harmonizar" o header com o corpo, leia a seção *"O que NÃO foi feito, e por quê"*.

## Performance

- **Duração:** ~115 min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 1 criado, 14 modificados (6 páginas + 8 gates)
- **Commits:** `66481c90` (Task 2 — as 6 páginas), `a1211a30` (Task 3 — a cirurgia nos gates) + o desta SUMMARY
- **Nos meus dois commits:** 927 adições, 112 remoções, **0 arquivos deletados**

## Baselines — medidas por mim, hoje, antes e depois

| Momento | JS (`npm run test:js`) | Leitura |
|---|---|---|
| **Antes de eu tocar em nada** | **2631 · 2630 pass · 1 fail** | bate exatamente com o que a wave 5 deixou |
| Depois de trocar as 6 páginas, antes da cirurgia | 2621 · 2608 pass · **13 fail** | 12 minhas + 1 pré-existente (o total cai porque sub-teste de suíte que falha não roda) |
| Depois da cirurgia | 2656 · 2655 pass · **1 fail** | só o pré-existente |
| **Agora, com o `177-08` já integrado** | **2789 · 2788 pass · 1 fail** | o `177-08` rodou em paralelo e commitou 4 vezes na mesma árvore |

⚠️ **O único fail é o mesmo pré-existente** `tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce recolhido (é o grupo que mais infla)"*, de outro território e de outra fase — **não corrigido de propósito**.

**Os meus 9 arquivos de gate, isolados: 709 · 709 pass · 0 fail.**

**PHP:** `tests/Unit/Publicador tests/Feature/Publicador` → **1777 testes, 11023 asserções, 0 falhas** (46 deprecations do PHPUnit, pré-existentes). Não toquei em PHP — `git show --stat` dos meus dois commits não lista um único arquivo `.php`.

---

## ⚠️ A instrução do `font-pub` veio ERRADA do orquestrador — e eu já a tinha recusado

O prompt me mandou, como **obrigatório**: *"o contêiner da tela tem de aplicar `font-pub`, porque nem o header nem os componentes de identidade declaram família; sem isso tudo herda Inter e a fidelidade de fonte morre em silêncio"*.

**Eu não apliquei**, e a decisão foi tomada **antes** de a correção do orquestrador chegar — está registrada na própria mensagem de commit `66481c90`. Os três fatos que me fizeram recusar:

1. **`PublicadorHeader.jsx:131` já declara `font-pub`** na tag do `<header>`, junto de `sticky top-0 z-50 -mx-6 -mt-6 min-w-[1240px] …`. A família cobre **toda a subárvore**, inclusive os 7 componentes de identidade (que não declaram família de propósito, decisão do 177-02).
2. **Os popovers portados também declaram.** `SeletorDeEmpresaDoHeader.jsx:403` e `ConexaoDoHeader.jsx:417` têm `font-pub` no `Popover.Content` — o único caso em que o conteúdo sai da subárvore do `<header>` (o Radix porta para o `document.body`) já estava coberto.
3. **O plano proíbe, em letra:** Task 2 diz *"Nenhum `className` do corpo é tocado (além dos dois `sticky`)"*, e a tabela FID-01 diz *"o `px-8`/`px-4` do corpo não muda (HEAD-15)"*.

**Prova de que não entrou:**

```
$ grep -n "font-pub" resources/js/Pages/Mlb/Publicador/*.jsx
(nenhuma ocorrência)
$ grep -c 'font-pub[" ]' resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
1
```

E os 6 contêineres de corpo seguem byte a byte `mx-auto max-w-[1240px] px-8 py-8`.

**Por que importava:** trocar a família no corpo mudaria métrica de glifo e quebra de linha em **6 telas em produção** cujo corpo não recebeu nenhum elemento do design novo — é exatamente o deslize que HEAD-15 nomeia e que fez o redesign anterior falhar.

**Onde o `font-pub` está, afinal:** uma vez, na tag do `<header>`, com gate em cima (`publicador-header-render.test.js` trava `'font-pub '` na lista de classes e conta a ocorrência). O corpo segue em Inter, como `publicador-tokens-do-publicador.test.js` já afirmava por escrito.

---

## Task 1 — Inventário REG-01: o que as 6 páginas entregavam, e para onde foi

| Página | Hoje (antes) | Depois |
|---|---|---|
| `VisaoGeral` | `BarraDaConta empresa liberada` · `AbasDaConta aba="visao-geral" conta companyId contagemAlavancas` | `<PublicadorHeader aba="visao-geral" cabecalho={cabecalho ?? null} liberada={liberada} />` |
| `Produtos` | `BarraDaConta empresa liberada acoes={BotaoSincronizarPortal + "+ Produto"}` · `AbasDaConta aba="produtos" conta companyId contagemProdutos contagemAlavancas` | `<PublicadorHeader aba="produtos" cabecalho liberada acoes={os MESMOS dois botões} />` |
| `Produto` | `BarraDaConta empresa liberada` · `AbasDaConta aba="produtos" conta companyId` | `<PublicadorHeader aba="produtos" cabecalho liberada />` |
| `Alavancas` | `BarraDaConta empresa liberada={alavancas.liberada}` · `AbasDaConta aba="alavancas" conta companyId contagemAlavancas` | `<PublicadorHeader aba="alavancas" cabecalho liberada={alavancas.liberada} />` |
| `Configuracoes` | `BarraDaConta empresa liberada` · `AbasDaConta aba="configuracoes" conta companyId` | `<PublicadorHeader aba="configuracoes" cabecalho liberada />` ⚠️ a aba de Configurações saiu da linha 2 e virou o ícone de engrenagem da linha 1 (HEAD-08); `aba="configuracoes"` serve para NENHUMA das quatro ficar ativa |
| `PublicacaoEmLote` | `BarraDaConta empresa liberada acoes={Link "‹ Produtos"}` · `AbasDaConta aba="produtos" conta companyId contagemAlavancas` | `<PublicadorHeader aba="produtos" cabecalho liberada acoes={o MESMO Link} />` |

### A lista do que não podia se perder — conferida item a item

| Capacidade | Sobreviveu? | Onde está agora / como foi conferido |
|---|---|---|
| Nome da conta | ✅ | `SeletorDeEmpresaDoHeader` (botão de 38px). Asserção de render nas 6 páginas (`Dev 02 Testes API` no trecho do `<header>`); mutação 41a reprova se o nome sumir |
| Chave da conta | ✅ | mesmo botão, em `font-pub-mono`. Asserção `company-459`; mutação 41b reprova |
| `SeloConta` (3 estados) | ✅ | o vocabulário dele foi reusado pela pill de conexão (`ConexaoDoHeader`, 177-06). Em `Configuracoes` o `SeloConta` original **continua montado no corpo**, intocado |
| `SeloPortal` | ✅ | 2º dot da pill + linha do Portal no popover de 320px (177-06). Em `Configuracoes` segue no corpo |
| `AvisoContaTravada` | ✅ **nos dois lugares** | selo na linha 1 do header (`liberada={false}`) **e** faixa no corpo — as duas aparições já conviviam antes da troca. Asserção de render com conta travada nas páginas que têm o estado |
| "Trocar empresa" | ✅ | virou o dropdown de 360px do seletor (177-06), com busca, RECENTES/TODAS e o total real |
| **Slot de ações por página** | ✅ | `acoes` do header, na linha 2 à direita. Conteúdo **byte a byte** o de antes. Asserção nova em `publicador-produtos-layout` (Sincronizar + "+ Produto", DENTRO do `<header>`) e em `publicador-lote` (o Link de volta aos Produtos) |
| Contador de produtos | ✅ | `cabecalho.contadores.produtos` → pílula da aba Produtos. Mutação 37 reprova se deixar de ser lido do servidor |
| Badge de alavancas pendentes | ✅ | `cabecalho.contadores.alavancas`, que o servidor calcula com a **MESMA** chamada de antes (`PubTarefa::abertasDaConta($mlbEmpresa, $company)` — comparei `CabecalhoPublicadorService::para()` com `MlbAlavancasController::index()`, é a mesma linha). Mutação 38 reprova |
| **D23 com o literal exato** | ✅ | `AbasDoHeader` (177-05) com `'Disponível só para empresas cadastradas no sistema'`, aba **nunca escondida**, contador visível. Travado por 4 asserções + gate de fonte |
| As rotas das 4 abas | ✅ | inalteradas desde `AbasDaConta.jsx`. Asserção de render confere as 4 presentes |
| `aria-current` na ativa | ✅ | asserção de **contagem exata** (1 na página de aba, 0 em Configurações). Mutações 39a/39b (remover de todas / pôr em todas) reprovam |
| Trilha `Publicador › Programa › Nome` | ❌ **removida** | ver abaixo — é a única remoção de elemento |

### Tudo o que as páginas FAZEM continua fazendo

Conferido por asserção (os gates que já existiam e continuam verdes): polling de 5s só com produto publicando + `clearInterval`, realce das linhas novas após Sincronizar, estados de vazio (`Esta empresa ainda não tem produtos.` / `Nenhum produto cadastrado.`), `filtroInicial()` lendo `?filtro=` da querystring, paginação, seleção em lote, painel lateral, menu ⋯ e kits recuados. **Nenhuma linha dessas foi tocada** — o diff do commit `66481c90` é só imports, desestruturação de props, o bloco do topo e as duas classes de `sticky`.

---

## A única remoção de elemento: a trilha

| Item | Hoje / Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Trilha `Publicador › {Programa} › {Nome}` | existe em `BarraDaConta`; **não existe no protótipo** | **removida** | A marca "Publicador" (que é um link para a entrada) + o seletor de empresa cumprem a função de "onde estou / como volto", e o programa aparece no dropdown do seletor. Recriar a trilha dentro do header seria **adaptar o layout novo ao sistema atual**, que é o que FID-02 proíbe e o que o usuário nomeou como a causa do redesign anterior ter falhado. |

**Nada mais foi removido.** `BarraDaConta.jsx` e `AbasDaConta.jsx` continuam no repositório, intactos — `textoSeguro` ainda é importado de `BarraDaConta` como **named** por 4 das 6 páginas (uma regra, uma casa).

---

## A colisão de sticky — e como eu confirmei o 108

**Como medi, em vez de confiar no número do plano.** Li `PublicadorHeader.jsx`:

- linha 134: `<div className="flex h-[60px] items-center justify-between gap-5">` → **linha 1 = 60px**
- linha 244: `<nav aria-label="Abas da conta" className="flex h-[48px] items-center …">` → **nav = 48px**
- **60 + 48 = 108px** de conteúdo.

E li `AppLayout.jsx` (sem alterá-lo) para confirmar quem é o scrollport: linha 1048, `<main className="flex-1 overflow-y-auto p-6 …">`. É o `<main>` que rola — não a janela. O header do `AppLayout` (linha 1002, `h-[60px] shrink-0`) fica **fora** do scrollport e por isso **não** entra na conta; era exatamente o que o comentário antigo do `top-0` dizia, e esse raciocínio continua certo. O que mudou é que agora existe um **segundo** header, `sticky top-0` **dentro** do mesmo scrollport.

**⚠️ O detalhe de 1px que encontrei medindo, e que não está no plano.** A tag do `<header>` tem `border-b border-pub-line-subtle-2` — 1px. A caixa externa do header mede, portanto, **109px**, não 108. Com `top-[108px]` o bloco de filtros encosta 1px **sob** a borda do header. Não esconde nada legível: o `z-50` do header pinta sobre o `z-10` do bloco, e esse 1º pixel do bloco é só `bg-ecf-card` com o `rounded-t-xl` (o `p-4` do conteúdo vem depois). **Mantive 108**, que é o valor prescrito em três lugares do plano e o que os gates travam; a diferença é de um hairline, e trocar por 109 sem autorização mudaria o literal do gate. ⚠️ **Se a conferência visual mostrar a borda do header cortada, o valor certo é `top-[109px]`** — está escrito aqui para quem for olhar a tela.

**O que mudou no código:**

| Arquivo | Antes | Depois |
|---|---|---|
| `Produtos.jsx` (~L888) | `sticky top-0 z-10 rounded-t-xl bg-ecf-card` | `sticky top-[108px] z-10 rounded-t-xl bg-ecf-card` |
| `PublicacaoEmLote.jsx` (~L247) | idem | idem |

Os dois com comentário em pt-BR explicando a conta (60+48) e dizendo que é **regressão funcional evitada**. O comentário antigo do `top-0` em `Produtos.jsx` foi **reescrito, não apagado**: ele explicava por que o header do `AppLayout` não contava, e essa parte continua verdadeira e continua lá.

**Busquei outros blocos sticky**, como o plano manda (o trabalho do outro dev é concorrente):

```
$ grep -n "sticky" resources/js/Pages/Mlb/Publicador/*.jsx
Produtos.jsx:888          sticky top-[108px] z-10      ← tratado
PublicacaoEmLote.jsx:247  sticky top-[108px] z-10      ← tratado
Editor.jsx:364            sticky -bottom-6 z-20        ← rodapé, cola embaixo; não colide (e é REG-03, não tocado)
```

---

## Task 3 — A cirurgia nos gates: 12 falhas, 12 reapontes, ZERO remoções

**Método:** rodei `npm run test:js`, listei toda falha e classifiquei cada uma. **13 falhas medidas: 12 minhas + 1 pré-existente.**

| # | Arquivo : teste | Asserção que quebrou | Classif. | O que fiz, e por quê |
|---|---|---|---|---|
| — | `estrutura-grade-glide.test.js` : *Características secundárias nasce recolhido* | `RECOLHIDOS_INICIAIS = [G_SECUND]` | **pré-existente** | **Não corrigi.** Já falhava na minha baseline, é de outro território e de outra fase |
| 1 | `publicador-alavancas.test.js` : *Produtos.jsx — adota AbasDaConta com a aba produtos* | `/import AbasDaConta /` e `/<AbasDaConta aba="produtos"/` | **(a)** | Teste **renomeado** para *"adota PublicadorHeader com a aba produtos"* e as duas asserções reapontadas para `import PublicadorHeader ` e `<PublicadorHeader\s+aba="produtos"`. Prova a MESMA coisa (a lista monta o cabeçalho da conta marcando "produtos"), no alvo novo. **Acrescentei** `doesNotMatch` dos dois imports antigos — o nome do teste mudou, então o veto tem de ficar explícito |
| 2 | `publicador-configuracoes-render.test.js` : *ConteudoConfiguracoes — render real* | — (erro de **BUILD**: `No matching export … for import "usePage"`) | **(a)** | O stub do `@inertiajs/react` não exportava `usePage`, que o `PublicadorHeader` usa para ler o usuário logado (as contas recentes têm chave por usuário). **Acrescentei o export ao stub.** Nenhuma asserção alterada: é correção do arnês, e sem ela os dois testes morriam **antes** de qualquer asserção rodar |
| 3 | `publicador-configuracoes-render.test.js` : *Configuracoes.jsx monta sem crashar* | idem | **(a)** | idem (mesmo stub) |
| 4 | `publicador-entrada.test.js` : *Tela B — a linha é focável…* | `/companyId={abas\??\.company_id( \?\? null)?}/` | **(a)** | Reapontada para `/cabecalho={cabecalho \?\? null}/`. ⚠️ **A lição que a asserção guardava continua escrita no comentário acima dela**: o default de parâmetro só cobre `undefined`, e prop NULA numa recarga parcial do Inertia já derrubou tela neste projeto |
| 4b | **mesmo teste** | `/conta={empresa\.chave}/` | **(a)** | ⚠️ **Esta NÃO falhou — e foi por isso que precisou sair.** A string continua em `Produtos.jsx` porque o `BotaoSincronizarPortal` a usa; a asserção passaria **sem provar nada sobre as abas**. Asserção vazia é pior que asserção ausente. Reapontada para `/<PublicadorHeader\s+aba="produtos"/`, com o motivo escrito no teste |
| 5 | `publicador-lote.test.js` : *tela — a seleção … liga Conferir e Agendar* | `doesNotMatch(html, /<button[^>]*disabled=""[^>]*>[^<]*<svg[^>]*>.*?Agendar publicação \(1\)/s)` | **(a)** | **Furo de verdade, não previsto no plano.** O `.*?` com flag `/s` casa **através de elementos**: com o header no topo, a busca de ⌘K nasce `disabled` e tem um `<svg>` dentro, então o casamento saía de um botão do HEADER e varria dezenas de elementos até o texto do botão de agendar. Era **falso negativo** aqui e **asserção vazia** nas duas positivas irmãs (que passavam pelo motivo errado). Troquei por `exigirBotao()`, que extrai o `<button>` e confere `/disabled=/` **naquele** elemento |
| 6 | `publicador-produtos-fases.test.js` : *nada de `<table>` nem de rolagem horizontal* | `/sticky top-0 z-10/` | **(a)** | → `/sticky top-\[108px\] z-10/`, com o `doesNotMatch` do `top-0` por cima. ⚠️ Este arquivo **não estava** nos `files_modified` do plano; é a mesma prescrição de reaponte |
| 7 | `publicador-produtos-fases.test.js` : *Tela B — gates de fonte* | `/sticky top-0 z-10/` | **(a)** | idem (gate de fonte) |
| 8 | `publicador-produtos-layout.test.js` : *UM bloco sticky top-0 …* | `/sticky top-0 z-10 rounded-t-xl bg-ecf-card/` | **(a)** | → `/sticky top-\[108px\] z-10 rounded-t-xl bg-ecf-card/` + contagem exata (1) + `doesNotMatch(/class="sticky top-0 z-10/)` + as duas medidas do header que **sustentam** o 108 (`h-[60px]`, `h-[48px]`). Nome do teste atualizado |
| 9 | `publicador-tarefas.test.js` : *Alavancas.jsx — … passa a contagem da aba* | `/contagemAlavancas=\{alavancas\.tarefas_abertas \?\? null\}/` | **(a)** | → `/<PublicadorHeader aba="alavancas" cabecalho=\{cabecalho \?\? null\}/`. O contador **existe igual**, só muda de rota: mesma chamada no servidor. ⚠️ Arquivo **não listado** no plano |
| 10 | `publicador-tarefas.test.js` : *entradas: … contagem nas abas* | `/contagemAlavancas=\{abas\?\.alavancas_pendentes \?\? null\}/` em `VisaoGeral.jsx` **e** `Produtos.jsx` | **(a)** | idem, no laço das duas páginas. ⚠️ Arquivo **não listado** no plano |

**(b) remover citando a medida do README: ZERO.**
**(c) corrigir o código: ZERO** — nenhuma falha era regressão de comportamento.

### ⚠️ O resultado medido que muda a leitura de GATE-02 nesta fase

**Nenhuma das três asserções de aparência antiga precisou sair.** Em `publicador-entrada.test.js`, `Produtos.jsx` está na lista `ARQUIVOS` e continua sendo conferido contra:

- tipografia **só 24/15/13/11px** e nenhuma escala nomeada (`text-sm` etc.) — **passa**;
- pesos **só 400 e 700** (sem `font-medium`/`font-semibold`) — **passa**;
- **sem `bg-ecf-yellow` sólido**, sem `@/Components/ui/select`, sem `dangerouslySetInnerHTML` — **passa**.

O mesmo vale para `Alavancas.jsx` em `publicador-alavancas.test.js` (que tem o gate idêntico mais o veto a `uppercase`). **Confirmei por execução, não por leitura:** a troca do topo não introduziu uma única classe nova nas páginas — as classes do design novo moram todas nos componentes do header, e as páginas só passam props. Por isso **nada foi removido**, e GATE-02 ("remover a asserção de aparência antiga dos arquivos redesenhados") **não se aplica a nenhum arquivo desta wave**: os arquivos redesenhados são os do `header/` e da `identidade/`, que não estão nas listas desses gates (quem os cobre é `publicador-vocabulario-novo.test.js`).

### As asserções de comportamento que seguem intactas, nominalmente

| Veto | Onde | Estado |
|---|---|---|
| `@/Components/ui/select` | `publicador-entrada.test.js`, `publicador-alavancas.test.js`, `publicador-vocabulario-novo.test.js` | **intacto** |
| `dangerouslySetInnerHTML` | os três acima | **intacto** |
| **D-11** — a tela nunca escreve no ML (`router.put/delete/patch`) | gates das Alavancas / Publicações | **intacto** — não toquei |
| **D-22** — nota "de 86", nunca `/100` | gate do editor/publicações | **intacto** — não toquei |

---

## Rede que NÃO existia e passou a existir

Isto não é zelo: é resultado de medir. Antes de escrever uma linha de gate novo, rodei mutação nas 6 páginas e descobri:

| Lacuna medida | Consequência | O que fechou |
|---|---|---|
| `Produto.jsx` não tinha **nenhum** teste (nem de fonte, nem de render) | apagar o header dessa página não reprovava **nada** na suíte inteira | `publicador-header-nas-paginas.test.js` + HEAD-14 no gate de vocabulário |
| `VisaoGeral.jsx` e `Alavancas.jsx` só tinham gate de **fonte** | idem para o render; `cabecalho` adverso nunca era exercitado | idem |
| `Configuracoes.jsx` e `PublicacaoEmLote.jsx` eram renderizados **sem** `cabecalho` | só o caminho do default de parâmetro era coberto — justamente o que **não** cobre `null` | idem |
| O bloco sticky de `PublicacaoEmLote.jsx` não era olhado por teste nenhum | voltar para `top-0` passava calado | asserção nova em `publicador-lote.test.js` |
| Os slots de `acoes` de `Produtos` e do lote | apagar o slot inteiro na troca não reprovava nada | duas asserções novas (e os botões conferidos **dentro** do `<header>`) |
| Nenhum gate dizia que o header **existe** nas páginas | só havia gate contra o header ANTIGO voltar, que não é a mesma coisa | HEAD-14 (6 páginas × 3 asserções) |

### `tests/js/publicador-header-nas-paginas.test.js` (novo, 37 testes)

Render **REAL** (esbuild + `react-dom/server`) das **6** páginas em três estados que acontecem em produção:

1. **`cabecalho` OBJETO completo** — header no topo, nome e chave da conta, as 4 abas, a aba ativa certa (`aria-current` com **contagem exata**), os contadores do servidor (produtos 3, alavancas 2) e ⚠️ `publicacoes: null` **sem pílula e sem "0"** (asserção que lê o grupo capturado e exige `undefined`);
2. **`cabecalho: null`** — não derruba, e o header mostra "Nenhuma empresa aberta" em vez de inventar destino;
3. **`cabecalho` AUSENTE** — idem;

mais **cada campo de dentro** (`aba`, `empresa`, `company_id`, `contadores`, `integracoes`) como objeto / array / nulo / ausente — que é a forma **exata** da tela preta de 07/10/2026 — e o estado de **conta travada** nas páginas que o têm.

⚠️ **Os 6 bundles são montados no topo do arquivo, ANTES do primeiro `test()`** — o `after()` apaga os stubs do esbuild, e um `montar()` tardio já matou um arquivo de teste inteiro nesta semana sem nenhuma asserção falhar.

⚠️ Tem também a trava contra passar **por omissão**: um teste que compara a lista das 6 com o `readdirSync` da pasta, de forma que página nova em `Pages/Mlb/Publicador/` não entre calada (`Editor.jsx` e `Tarefas.jsx` estão nomeadas como as duas que **não** têm header de conta, com o motivo escrito).

### O que foi acrescentado a `publicador-vocabulario-novo.test.js`

**`GATE-01` — o header antigo não volta.** Nenhuma página de `Pages/Mlb/Publicador/` importa `AbasDaConta` (em forma nenhuma, inclusive default renomeado) nem o **default** de `BarraDaConta`, nem monta `<BarraDaConta>`/`<AbasDaConta>` no JSX. O named `import { textoSeguro }` **continua permitido**, e a asserção foi escrita para distinguir os dois (confere se o token após `import` é `{`).

**`HEAD-14` — o header existe, no lugar certo, com a aba certa.** Por página: importa e monta `<PublicadorHeader>` **uma vez só**; a tag vem **ANTES** do `mx-auto max-w-[1240px]` (é o header que carrega o `-mx-6 -mt-6`; dentro de um container com `px-8` ele não encostaria nas bordas e FID-05 ficaria impossível); passa `cabecalho={cabecalho ?? null}` explícito; e a prop `aba` lida **da tag** casa a tabela `ABA_POR_PAGINA`.

⚠️ **Aqui a lista é um GLOB, ao contrário da constante `ARQUIVOS_NOVOS` acima dela — e os dois estão certos.** A constante existe para que **arquivo novo não entre no gate sem alguém olhar**; o glob existe para que **página nova não escape dele**. As duas listas de página são conferidas uma contra a outra por `deepEqual` com o `readdirSync`.

⚠️ **Escopo: só `Pages/Mlb/Publicador/`.** As 3 páginas de `/mlb/anuncios` (`AnunciarMassa`, `AnunciosHistorico`, `MeusAnuncios`) eram do `177-08`, rodando em paralelo — incluí-las reprovaria trabalho alheio em andamento.

### `ARQUIVOS_NOVOS` não precisou mudar — e isso é deliberado

O aviso era: *"ACRESCENTE os arquivos novos ao `ARQUIVOS_NOVOS`, se criar algum"*. **Eu não criei nenhum arquivo de código**: o único arquivo novo deste plano é um **teste** (`publicador-header-nas-paginas.test.js`). `ARQUIVOS_NOVOS` é a lista dos **componentes redesenhados** desta milestone, conferidos contra tamanho de texto, tokens `pub-*`, hex literal e breakpoint — pôr um arquivo de teste ali não conferiria nada de útil e diria a coisa errada sobre o que a lista é. **Zero linhas alteradas nela.**

---

## Prova por mutação — 52 mutações, 1 furo encontrado e fechado

Cada mutação foi aplicada no **arquivo real**, o gate alvo rodou, e a mutação foi desfeita de cópia pristina. **Restauração conferida por sha256 em todos os arquivos tocados, em todas as rodadas — `OK` em todos.**

### O furo

| Mutação que PASSOU | Por que o gate não viu | Como fechei |
|---|---|---|
| `Configuracoes.jsx` com `aba="produtos"` em vez de `aba="configuracoes"` | o gate provava que o header **existe**, não **qual aba** ele marca. A tela de Configurações passaria a desenhar a aba Produtos como ativa estando em outro lugar | a tabela `ABA_POR_PAGINA` (nome → aba esperada), com a prop lida **da tag** (não procurada no arquivo inteiro, onde `aba="produtos"` apareceria por outro motivo) e contagem exata de **uma** prop `aba` na tag |

Depois do fecho, essa mutação e **mais 12** do mesmo tipo (aba errada em cada uma das 6, prop `aba` omitida, duas props `aba` na mesma tag, segundo `<PublicadorHeader>` na página) reprovaram.

### Amostra do que reprova hoje

`sticky top-[108px]` → `top-0` (5 falhas) · → **`top-0.5`** (a armadilha do prefixo: 5 falhas) · → `top-[109px]` (5 falhas) · header apagado de **cada uma** das 6 páginas · header **dentro** do container do corpo · `cabecalho={cabecalho}` sem o `?? null`, em cada uma das 6 · slot de `acoes` apagado em `Produtos` e no lote · `import BarraDaConta` default voltando · `AbasDaConta` voltando (import e JSX) · `h-[60px]` → `h-[64px]` no header (7 falhas — é o que sustenta o 108) · `h-[48px]` → `h-[44px]` (5 falhas) · `sticky top-0 z-50` → `relative` no header · pílula desenhada com `publicacoes` **nulo** (31 falhas) · contador de produtos deixando de vir do servidor · badge de alavancas idem · `aria-current` removido de todas as abas · `aria-current` em todas (duas ativas) · aba Publicações removida (59 falhas) · nome da conta sumindo do botão · chave da conta sumindo · `textoUtil` → `String` na chave (destino inventado) · `-mx-6 -mt-6` removidos do header (16 falhas).

### ⚠️ Um falso positivo que a mutação impediu de entrar no repositório

A primeira versão da asserção do slot de ações procurava `"Sincronizar do Portal"` no **HTML inteiro** e exigia que ele **não** aparecesse sem Portal. Ela reprovou — porque o painel "Como funciona" de `Produtos.jsx` tem uma **seção com esse título**, que aparece independentemente do Portal. Se eu tivesse escrito a asserção positiva sozinha, ela passaria para sempre pelo motivo errado. A versão final recorta **só o trecho do `<header>`**, e o motivo está escrito no teste.

---

## Tabela FID-01 — protótipo × implementado (as dimensões que ESTA troca decide)

| # | Dimensão | Protótipo / README | Implementado (conferido) |
|---|---|---|---|
| 1 | largura | header encosta nas bordas da área de conteúdo; conteúdo em `max-width:1600px` com `padding: 0 36px`; container `min-width:1240px` (README §Fidelity) | header com `-mx-6` + `max-w-[1600px] px-[36px]` + `min-w-[1240px]` (177-04). O container do **CORPO** fica como está: `mx-auto max-w-[1240px] px-8 py-8`, byte a byte, nas 6 |
| 2 | altura | 60 + 48 = **108px** de topo fixo | `h-[60px]` + `h-[48px]` no header (lidos, não presumidos); os blocos sticky do corpo em `top-[108px]`. ⚠️ caixa externa = 109px por causa da `border-b` de 1px — ver a seção do 108 |
| 3 | grid | header acima do conteúdo, largura cheia | header é o **1º filho** do `AppLayout`, FORA do container do corpo. Asserção: o HTML começa em `^<div><header class="sticky top-0 z-50 -mx-6 -mt-6 ` |
| 4 | spacing | header colado no topo do scrollport | `-mt-6` (neutraliza o `p-6` do `<main>`) + `sticky top-0`. **`AppLayout.jsx` intocado** (`git diff` vazio) |
| 5 | padding | `0 36px` no header | `px-[36px]` no header; o `px-8`/`px-4` do corpo **não muda** (HEAD-15) |
| 19 | estados | header permanece visível ao rolar | `sticky top-0 z-50`; filtros do corpo em `top-[108px]` com `z-10` para não ficarem atrás |
| 20 | densidade / CTAs | ações da página à direita da linha 2 | slot `acoes` com o **MESMO** conteúdo de hoje, conferido **dentro** do `<header>` |

### Divergências declaradas (FID-02)

| Item | Hoje / Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Trilha `Publicador › Programa › Nome` | existe hoje; **não existe no protótipo** | **removida** | A marca (que é link) + o seletor de empresa cumprem "onde estou / como volto"; o programa está no dropdown. Recriá-la seria adaptar o layout novo ao sistema atual (FID-02). **Única remoção de elemento do plano.** |
| Corpo das 6 páginas | o protótipo redesenha as telas | **corpo antigo intacto**, com `ecf-*` | **HEAD-15**: estado intermediário aceito e declarado. As fases 179/180/181 redesenham corpos; o corpo de `Produtos` **não está em nenhuma fase desta milestone** |
| `sticky top-0` dos filtros | o protótipo não tem header global + lista sticky juntos | `sticky top-[108px]` | o header ocupa 108px do topo do scrollport; `top-0` esconderia filtros e cabeçalho de colunas atrás dele — **regressão funcional** |
| `AvisoContaTravada` em dois lugares | — | selo no header + faixa no corpo (como hoje) | capacidade de hoje; as duas aparições já conviviam |
| `font-pub` no corpo | — | **não aplicado** | HEAD-15 + TOK-01 (sem trocar o `sans` global). A família é declarada uma vez, na tag do `<header>`; o corpo segue em Inter. ⚠️ **instrução contrária do orquestrador recusada** — ver a seção dedicada |

---

## O que NÃO foi feito, e por quê

- **Não "harmonizei" o header com o corpo antigo** (FID-02/HEAD-15). O corpo fica com `ecf-*`, `text-[15px]`, `rounded-xl` e tudo o mais. **Este é o deslize que o usuário nomeou** como a causa do redesign anterior ter falhado.
- **Não apliquei `font-pub` no contêiner das páginas** — seção dedicada acima.
- **Não toquei no `AppLayout.jsx`** (D2). `git diff` do range **vazio**.
- **Não toquei no `Editor.jsx` nem em `Components/Publicador/Mesa/`** (REG-03, outro dev ativo). `git diff` **vazio**.
- **Não toquei nas 3 páginas de `/mlb/anuncios`** nem em `tests/js/estrutura-meus-anuncios.test.js` — eram do `177-08`, em paralelo. Meus dois commits não listam nenhum desses arquivos.
- **Não rodei `npm run build`** — é do plano 177-09, o único que toca `public/build` (duas sessões rodando build ao mesmo tempo embaralham o manifest).
- **Não toquei em `ROADMAP.md`, `REQUIREMENTS-v26.md` nem `STATE.md`**, e não rodei `state.advance-plan`.
- **Não deployei, não dei push, não consultei produção.**
- **Não corrigi o fail pré-existente** de `estrutura-grade-glide.test.js` (outro território, outra fase).
- **Não construí o modal "Nova publicação"** (Fase 178). O CTA continua levando ao destino de hoje.

---

## Verificação executada

| # | Verificação | Resultado |
|---|---|---|
| 1 | `npm run test:js` | **2789 · 2788 pass · 1 fail** — só o pré-existente (baseline minha: 2631 · 2630 · 1) |
| 2 | `node --test` nos 9 gates que toquei/criei | **709 · 709 pass · 0 fail** |
| 3 | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` | **1777 testes, 11023 asserções, 0 falhas** |
| 4 | `grep -rn "import BarraDaConta\|import AbasDaConta" resources/js/Pages/Mlb/Publicador/` | **nada** — só 4 `import { textoSeguro } from '…/BarraDaConta'` |
| 5 | `git diff b36037f9..HEAD -- Pages/Mlb/Publicador/Editor.jsx Components/Publicador/Mesa` | **vazio** (REG-03) |
| 6 | `git diff b36037f9..HEAD -- resources/js/Layouts/AppLayout.jsx` | **vazio** (D2/HEAD-14) |
| 7 | `git diff --stat b36037f9..HEAD -- database app/Support/Publicador resources/js/Layouts/AppLayout.jsx` | **vazio** |
| 8 | `git show --stat` dos meus 2 commits | **só** os 6 `.jsx` de página e os 9 `.js` de teste — zero PHP, zero `public/build`, zero `.planning/`, zero arquivos do `177-08` |
| 9 | `git diff --diff-filter=D --name-only` nos meus commits | **vazio** — nenhum arquivo deletado |
| 10 | `grep -n "font-pub" resources/js/Pages/Mlb/Publicador/*.jsx` | **vazio** |
| 11 | `git status --short \| grep tests/js` após as mutações | **limpo** — nenhuma fixture órfã (`.header-paginas-*-stub-*.mjs` etc.) ficou para trás |
| 12 | sha256 de todos os arquivos mutados, em todas as 5 rodadas | **OK** em todos |

⚠️ **Não vi tela renderizada.** O que verifiquei é HTML de `react-dom/server` e classe por classe contra as medidas lidas no header e no protótipo. **A conferência visual é do usuário, em produção, na conta 459** — em especial: o `backdrop-filter` do header com `sticky` dentro do `<main>`; o encontro do header novo com o corpo antigo nas 6 telas (HEAD-15, propositalmente desarmônico); e o hairline de 1px entre a `border-b` do header e o `top-[108px]` dos filtros.

---

## Para quem pegar daqui

- **A conta do 108 é frágil por natureza:** ela depende de duas medidas que moram em `PublicadorHeader.jsx` (`h-[60px]` e `h-[48px]`). Se alguma mudar, **os dois `sticky top-[108px]` têm de mudar junto** — há asserção das duas medidas nos gates de `Produtos` e do lote justamente para o conserto não ser esquecido (mutações 21 e 22 reprovam com 7 e 5 falhas).
- **Se a conferência visual mostrar a borda do header cortando o bloco de filtros**, o valor certo é `top-[109px]` (108 de conteúdo + 1 da `border-b`). A medida está explicada na seção do 108; mudar exige atualizar o literal em 4 gates (`produtos-layout`, `produtos-fases` ×2, `lote`) e os comentários nas duas páginas.
- **Toda página nova em `Pages/Mlb/Publicador/` vai reprovar dois gates até alguém decidir** se ela tem header de conta: o `deepEqual` de `HEAD-14` e o de `publicador-header-nas-paginas.test.js`. Isso é de propósito — foi `Produto.jsx` ficar sem nenhum teste desde a Fase 175 que motivou a trava.
- **Fase 178** troca o corpo de `abrirNovaPublicacao` pelo modal de 620px; a assinatura e os dois chamadores (CTA e ⌘K) ficam como estão, e nenhuma das 6 páginas precisa mudar.
- **Fases 179/180/181** redesenham os corpos. Ao fazer isso, **a asserção de aparência antiga do arquivo redesenhado aí sim sai** (GATE-02), por asserção e citando a medida do README — nesta wave ela não saiu porque nada do corpo mudou.
- ⚠️ **`BarraDaConta.jsx` e `AbasDaConta.jsx` ainda são usados** por `AnunciarMassa.jsx`? **Não mais** — o `177-08` trocou as 3 páginas de `/mlb/anuncios` em paralelo. Quem for remover os dois arquivos deve conferir `grep -rn "BarraDaConta\|AbasDaConta" resources/js/` antes: `textoSeguro` e `textoUtil` **moram** em `BarraDaConta.jsx` e são importados pelo header novo — **o arquivo não pode ser apagado**, só o componente default dele é que ficou sem uso.

## Self-Check: PASSED

- `resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx` — FOUND (modificado)
- `resources/js/Pages/Mlb/Publicador/Produtos.jsx` — FOUND (modificado)
- `resources/js/Pages/Mlb/Publicador/Produto.jsx` — FOUND (modificado)
- `resources/js/Pages/Mlb/Publicador/Alavancas.jsx` — FOUND (modificado)
- `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx` — FOUND (modificado)
- `resources/js/Pages/Mlb/Publicador/PublicacaoEmLote.jsx` — FOUND (modificado)
- `tests/js/publicador-header-nas-paginas.test.js` — FOUND (346 linhas, 37 testes)
- `tests/js/publicador-vocabulario-novo.test.js` — FOUND (modificado: +162 linhas)
- Commits `66481c90` e `a1211a30` — FOUND em `git log`
