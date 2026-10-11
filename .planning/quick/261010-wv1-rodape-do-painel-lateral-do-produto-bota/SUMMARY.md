---
quick_id: 261010-wv1
titulo: Rodapé do painel lateral do produto — uma única ação principal
status: concluido
ponto_de_partida: 6dc843d4
commits:
  - 794846b4  # fix(publicador): rodapé do painel do produto com UMA ação principal
arquivos_modificados:
  - resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx  # só comentário
  - tests/js/publicador-produtos-layout.test.js
---

# 261010-wv1 — Rodapé do painel lateral do produto: uma única ação principal

O rodapé do drawer renderizava **sempre dois botões** — "Abrir produto" + a ação de
`acaoPrincipal(status)`. Isso não era um defeito: eram **três**, e um deles é pior que a
redundância que o usuário viu.

## Os três defeitos, e como cada um ficou

### 1. Dois botões para o MESMO destino (`destino === 'produto'`)

Afetava `status.chave = 'publicado'`, cuja ação na tabela é `{rotulo: 'Abrir', destino: 'produto'}`.
"Abrir produto" chama `abrir(produto)`; o botão "Abrir" chamava `irPara('produto', produto)`, que
cai no mesmo `abrir(produto)`. Mesma tela, dois botões, rótulos quase iguais — foi o que o usuário
descreveu no item 10.

**Como ficou:** um botão só, "Abrir produto", com o mesmo comportamento que o par tinha. Nada
deixou de ser alcançável.

### 2. ⚠️ Botão NO-OP (`destino === 'painel'`)

Afetava `publicando` ("Acompanhar") e `parcial`/`erro` ("Ver erro"). O `onAcao` da página tem
`if (destino !== 'painel')` — porque o painel **já é** o destino `'painel'`. Resultado: clicar
naqueles botões não fazia **nada**. Não era só redundância: era um botão que mente, contra o §29
do briefing ("um funcionário novo deve conseguir entender sozinho qual é a próxima ação"). Pior,
o "Ver erro" era **vermelho**, então prometia com ênfase um detalhe que nunca abriria.

**Como ficou:** o botão não é renderizado. Antes de esconder, conferi o corpo do drawer (conforme
o pedido) — a informação que ele prometia está lá, e nunca esteve no botão:

- **Situação**: o `SeloStatusProduto` mostra "Publicando" (com spinner e `aria-live`), "Parte
  publicada" (âmbar) ou "Não publicado" (vermelho) — o vermelho do erro não se perdeu.
- **Parcial**: a seção Anúncios mostra o `"N de M"` (`resumoDosAnuncios` dá precedência ao
  `parcial`) **e** a lista dos MLBs que subiram, com link para o Mercado Livre. É literalmente a
  informação de "o que foi e o que não foi".
- **Andamento**: o "atualizado há…" ao lado do selo.
- E, decisivo: `ProgramasPublicadorService::produtosParaTela()` devolve
  `status = {chave, rotulo, faltam}` — **não existe mensagem de erro de publicação no dado desta
  tela** (não há `erro_mensagem` em `PubPublicacaoItem` nesta carga). O botão no-op não tinha
  detalhe algum para abrir; portanto removê-lo não podia esconder dado nenhum. Quem quiser mais
  continua com "Abrir produto", que segue no rodapé.

### 3. Dois botões com o rótulo IDÊNTICO (chave desconhecida)

`ACAO_PADRAO` é `{rotulo: 'Abrir produto', destino: 'produto'}`. Com uma `status.chave` que a
tabela não conhece, o rodapé mostrava "Abrir produto" **duas vezes**.

**Como ficou:** um botão só (cai na mesma regra do caso 1). E o fallback do rótulo da ação deixou
de ser `'Abrir produto'` — passou a ser `'Abrir no editor'`, que é honesto para o único destino
que ainda renderiza o segundo botão. Teste cobre `acao` como objeto lixo, array, nulo e ausente.

## O caso `editor` CONTINUA com os dois botões — e isso não é inconsistência

`rascunho` ("Começar rascunho"), `conferir` ("Continuar") e `pronto` ("Publicar") têm
`destino: 'editor'`. Aí os dois botões levam a **lugares diferentes**: "Abrir produto" vai para a
tela do Produto (`url_produto`) e o outro abre o editor do rascunho. A regra implementada não é
"um botão sempre" — é **"o segundo botão só existe quando leva a outro lugar"**. É a mesma regra
nos três casos; o que muda é o dado. Este é o caminho principal da Fase 1 e está protegido por
teste que falha se o segundo botão sair.

A whitelist é **positiva** (`destino === 'editor'`), não uma blacklist de `'painel'`/`'produto'`:
destino em formato inesperado cai no botão único, nunca em dois botões iguais.

## A segunda pergunta do §17: o drawer SE SUSTENTA — não foi removido

O drawer hospeda coisas que **não estão na linha da lista**:

- **Sugestão de kit** — os dois botões "Vincular como Fase N" e "Não é kit" saíram da linha de
  propósito e moram só aqui;
- **"Falta para conferir"** — a barra de progresso + "faltam N itens";
- **Os links dos MLBs** — a linha mostra só os quadradinhos C/P; a lista com link para cada
  anúncio no ar é do painel;
- mais a miniatura/identificação completa e o "atualizado há…" (que o breakpoint estreito tira da
  linha de propósito).

Acrescentei um teste-gate explícito (`o drawer se sustenta: o rodapé encolheu, o CORPO não (§17)`)
justamente para que "tirar a redundância do rodapé" nunca vire "esvaziar o drawer".

## O que NÃO foi tocado

- A tabela `ACOES`, o `ACAO_PADRAO` e `acaoPrincipal()` em `layoutDaListaDeProdutos.js` —
  intactos. A linha da lista lê a **mesma** função e o botão dela está correto (na linha, "Ver
  erro"/"Acompanhar" com destino `painel` **abrem** o painel; só dentro do painel é que eram
  no-op). Mudar a tabela regrediria a linha.
- `Produtos.jsx`: só o comentário do `onAcao`. A guarda `if (destino !== 'painel')` fica como
  defesa, não como comportamento.
- Editor, entrada da Fase 2, painel "Criar Fase N" e tela do Produto: nada.
- Nenhum arquivo PHP, nenhuma rota, nenhum serviço, nenhuma migration.

## Baselines

| Suíte | Antes | Depois |
|---|---|---|
| JS (`npm run test:js`) | 2205 testes / 2204 pass / **1 fail** | 2210 testes / 2209 pass / **1 fail** |
| PHP (`tests/Unit/Publicador tests/Feature/Publicador`) | 1761 testes / 0 falhando | 1761 / 0 (nenhum arquivo PHP tocado) |

O único `fail` nas duas pontas é o **pré-existente** `estrutura-grade-glide` —
"Características secundárias nasce recolhido" —, que o usuário mandou não corrigir. Os +5 testes
são os novos subtestes do rodapé.

**RED provado antes do conserto:** rodando os testes novos contra o código antigo, 4 subtestes
novos + a asserção corrigida do rodapé falharam (5 subtestes, 6 falhas com o pai). Cada asserção
quebra se o segundo botão voltar; e a do caso `editor` quebra se alguém remover o segundo botão
por completo — pega o conserto desfeito nos dois sentidos.

## Gates

- `npm run build` rodado (toca `resources/`). Manifest lido **pelo JSON**:
  `resources/js/Pages/Mlb/Publicador/Produtos.jsx → assets/Produtos-vdqb3thQ.js`, presente no disco.
- **Rollup:** a flag `temAcaoPropria` é de escopo do componente mas **não** é lida dentro de
  `.map()` nenhum — o único `.map()` do painel (os anúncios) continua usando só o próprio item. No
  bundle minificado a guarda sobrevive: `M=X(k.destino,"")==="editor"` e `M&&e.jsx("button",…)`.
- `git diff --stat 6dc843d4..HEAD -- app/ database/` → **vazio**.
- Nenhum deploy, push, acesso ao VPS, worktree ou `pint`.

## ⚠️ Conferência visual é do usuário

Não vi tela renderizada: as provas acima são render estático via `react-dom/server`, bundle
minificado e manifest. A conferência visual do rodapé (nos status `publicado`, `erro`/`parcial`,
`publicando` e nos três do editor) fica com o usuário.
