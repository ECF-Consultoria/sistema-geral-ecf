---
tipo: quick
slug: prd-layout-v2-da-tela-de-produtos
data: 2026-10-09
origem: design_handoff_publicador/MELHORIA-tela-produtos.md
files_modified:
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx
  - resources/js/Components/Mlb/Publicador/MenuDeAcoesDoProduto.jsx
  - resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx
  - tests/js/publicador-produtos-layout.test.js
  - tests/js/publicador-produtos-fases.test.js
  - tests/js/publicador-entrada.test.js
  - tests/js/publicador-alavancas.test.js
  - tests/js/publicador-sincronizar-resumo.test.js
autonomous: true
---

<objective>
Reconstruir o layout da aba **Produtos** do Publicador conforme
`design_handoff_publicador/MELHORIA-tela-produtos.md`, resolvendo os 4 problemas que a spec
nomeia: rolagem horizontal, cabeçalho que some ao rolar, linhas de alturas diferentes, e duas
ações idênticas em toda linha sem jeito de ver detalhe sem sair da lista.

**SÓ UI.** Nenhuma rota, controller, migration ou regra de negócio muda. Os dados são os que
`produtosParaTela` já manda — **nenhum arquivo PHP é tocado** (`git diff --stat` tem de provar).

⚠️ As quatro funções puras já exportadas — `montarLinhas`, `faseDaQuerystring`,
`sugestaoSegura`, `destinoDoProduto` — **continuam exatamente como estão**, e os testes delas
devem continuar passando **sem edição**. Se algum deles precisar mudar, você mexeu no que não
devia.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
Ler a spec inteira (`design_handoff_publicador/MELHORIA-tela-produtos.md`) e, da referência
`design_handoff_publicador/referencias/Publicador - Produtos v2.dc.html`, as linhas 273-287
(constantes `ST`/`GRUPOS`/`FILTROS`/`FASES`/`ha`/`iniciais`) e 340-400 (montagem do estado).
⚠️ **Não há navegador aqui** — a referência é lida como código, não aberta.

<interfaces>
**Constantes EXATAS da referência — usar estas, não reinventar:**

```
cols (>=1100px): '44px minmax(240px,1fr) 132px 172px 120px 92px 152px'
cols (<1100px):  '36px minmax(200px,1fr) 104px 148px 96px 140px'   // some a coluna Atualizado
rowH:  confortável 64px · compacto 52px
thumb: confortável 40px · compacto 32px
thumbsOn = mostrarMiniaturas && largura >= 1100
sticky:  position:sticky; top:0; z-index:10; background:#0f1116; border-radius:12px 12px 0 0
FILTROS: todos=Todos · rascunho=Rascunho · conferidos=Conferidos · publicados=Publicados · com_problema=Com problema
GRUPOS:  todos=null · rascunho=[rascunho,conferir,publicando] · conferidos=[pronto] · publicados=[publicado,parcial] · com_problema=[erro]
FASES:   todas=Todas · so_base=Só base · so_kits=Só kits
ORDEM default por situação: erro=-1 · conferir=0 · rascunho=1 · pronto=2 · publicando=3 · parcial=4 · publicado=5
Menu ⋯: Abrir produto · Abrir no editor · Ver no Mercado Livre · Criar Fase 2 · Vincular como kit…
Lote:   Preencher com IA · Abrir na grade · Limpar seleção
Painel: seção "Falta para conferir", texto "faltam {N}"
```

**O que CADA LINHA realmente traz** (`ProgramasPublicadorService::produtosParaTela`, ~L678) —
não existe mais nada, não invente campo:
`id, sku, nome, origem, oferta_id, rascunho_id, status{chave,rotulo,faltam}, status_rascunho,
anuncios[{ml_item_id,listing_type_id}], parcial{publicados,total}, atualizado_em, conta_nome,
conta_diferente, liberada, fase, quantidade_kit, produto_base_id, eh_kit, rotulo_fase,
url_produto, kits[], base{id,sku,nome}|null,
sugestao_kit{base_id,base_sku,base_nome,quantidade,origem,conflito_heuristica}|null`
</interfaces>

<decisoes_do_usuario>
As quatro adaptações abaixo foram levantadas por mim contra o código, levadas ao usuário e
**aprovadas por ele em 2026-10-09**. Elas SUBSTITUEM o texto da spec onde divergirem.

1. **Miniatura = INICIAIS do nome, não foto.** A linha não traz imagem nenhuma, e a própria
   referência não usa `<img>`: ela desenha `iniciais(nome)` num quadrado (duas primeiras
   palavras com mais de 2 letras, maiúsculas). Comentar no código que foto real exigiria campo
   novo no servidor, fora do escopo.
2. **Sem preço no painel.** `anuncios[]` é só `{ml_item_id, listing_type_id}`; a referência
   mostra preço porque o dado dela é falso. No painel: **tipo + MLB com link**, sem preço, com
   comentário dizendo o que faltaria.
3. **A tabela de ação principal da spec se contradiz com o código.** Não existe chave
   "sem rascunho": neste módulo **`status.chave === 'rascunho'` É "não existe rascunho"**
   (rótulo "a preencher"). Logo:
   | chave | botão | destino | estilo |
   |---|---|---|---|
   | rascunho | Começar rascunho | editor | secundário |
   | conferir | Continuar | editor | secundário |
   | pronto | Publicar | editor | **primário amarelo** |
   | publicando | Acompanhar | painel | secundário |
   | publicado | Abrir | tela do Produto | secundário |
   | parcial, erro | Ver erro | painel | vermelho suave |
4. **Atualizar asserção de teste que mudou de forma está AUTORIZADO** — afrouxar ou apagar
   teste que ainda vale, **não**. Registrar na SUMMARY o antes/depois de cada asserção alterada.
</decisoes_do_usuario>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: As funções puras novas, com teste antes</name>
  <files>resources/js/Pages/Mlb/Publicador/Produtos.jsx, tests/js/publicador-produtos-layout.test.js</files>
  <action>
  Exportar de `Produtos.jsx`, **ao lado** das quatro antigas (que não mudam):
  - `iniciaisDoNome(nome)` — duas primeiras palavras com mais de 2 letras, maiúsculas; string vazia/objeto/nulo devolve `'—'` ou `''`, nunca estoura.
  - `colunasDaLargura(largura)` — devolve o `grid-template-columns` do breakpoint (>=1100 ou <1100), exatamente as duas strings do `<interfaces>`.
  - `alturaDaLinha(densidade)` / `tamanhoDaMiniatura(densidade)` — 64/52 e 40/32.
  - `miniaturasVisiveis(mostrar, largura)` — `mostrar && largura >= 1100`.
  - `acaoPrincipal(status)` — devolve `{rotulo, destino, estilo}` pela tabela da decisão 3. Chave desconhecida ou `status` objeto/nulo cai num default seguro, nunca estoura.
  - `ordenarTopo(linhasDeTopo, coluna, direcao)` — ordenação do CLIENTE por `produto` (nome), `situacao` (a ORDEM default: erro=-1 … publicado=5) e `atualizado`. **Estável** (empate preserva a ordem de entrada). Não recebe kits: ordena só os de topo.
  - `densidadeInicial(lido)` — valida o que veio do `localStorage` (`publicador.produtos.densidade`) por whitelist `['confortavel','compacto']`, default `confortavel`. ⚠️ Whitelist por **array `includes`**, nunca `hasOwnProperty` (`__proto__` passaria).

  Testes (RED antes): cada função com entrada boa, vazia, nula, objeto e lixo; `ordenarTopo` com empate provando estabilidade; o corte de 1100px provando os DOIS conjuntos de colunas e as duas alturas; `densidadeInicial('__proto__')` caindo no default.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produtos-layout.test.js</automated>
  </verify>
  <done>Os testes das funções novas passam e os 4 arquivos de teste antigos continuam como estão (nada tocado ainda).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: LinhaDeProduto + MenuDeAcoesDoProduto</name>
  <files>resources/js/Components/Mlb/Publicador/LinhaDeProduto.jsx, resources/js/Components/Mlb/Publicador/MenuDeAcoesDoProduto.jsx, tests/js/publicador-produtos-layout.test.js</files>
  <action>
  **`LinhaDeProduto.jsx`** — uma linha da grade, altura FIXA (`alturaDaLinha`), nenhuma célula quebrando em mais de 2 linhas (`whitespace-nowrap` + `truncate` em tudo):
  - checkbox · miniatura de iniciais (`└` antes quando `recuado`) · **Produto** em 2 linhas: nome com `truncate` + `title` com o nome completo, e embaixo `SKU (mono) · ⇔ Portal` ou `✎ Publicador`
  - **Fase**: linha 1 `Fase 1` / `Fase 2 · Kit N`; linha 2 `estoque calculado` (kit) ou a pílula amarela `Kit de {base_sku}?` (sugestão) que **abre o painel**. ⚠️ Os botões Vincular / Não é kit **saem da linha**.
  - **Situação**: `SeloStatusProduto` só com o rótulo curto; com pendências, barra de progresso de 64px + `faltam N` abaixo.
  - **Anúncios**: quadradinhos `C`/`P` com `title` = MLB, mais `2 no ar` ou `1 de 2` (parcial). Sem lista de MLBs. `—` sem anúncio.
  - **Atualizado**: `haQuanto()` em texto normal (não mono), `title` com a data completa. Só no breakpoint largo.
  - **Ações**: UM botão contextual (`acaoPrincipal`) + o menu ⋯.

  **`MenuDeAcoesDoProduto.jsx`** — os 5 itens do `<interfaces>`, cada um só quando faz sentido
  (`Ver no Mercado Livre` com anúncio; `Criar Fase 2` em base publicado; `Vincular como kit…`
  com sugestão). Fecha com **clique fora** e **Esc**.

  ⚠️ **Rollup:** variável de escopo do componente usada DENTRO de `.map()` é eliminada no bundle
  de produção (já deu `ReferenceError` aqui). **Toda flag booleana é computada DENTRO do
  callback** — vale para os anúncios, os itens do menu e as células.

  Testes de **render real** (esbuild + `react-dom/server`, molde de
  `tests/js/publicador-produto-render.test.js`): cada campo exibido chegando como **objeto**,
  **nulo** e **ausente**; `anuncios` não-array; `status` objeto adverso; `sugestao_kit` lixo.
  Em todos: não estoura e **nenhum `[object Object]`** no HTML. ⚠️ Botão desabilitado se prova
  com **`/disabled=/`**, nunca `/disabled/` (as classes contêm `disabled:opacity-40` e casam
  sempre — asserção vazia).
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produtos-layout.test.js</automated>
  </verify>
  <done>Render real verde em todos os casos adversos; nenhuma flag de `.map()` fora do callback (conferir lendo o diff).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: PainelDoProdutoLateral</name>
  <files>resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx, tests/js/publicador-produtos-layout.test.js</files>
  <action>
  Painel de **440px** à direita, sobre a lista. **Recebe a linha pronta por prop e não busca
  nada.** Conteúdo, na ordem da spec: miniatura de iniciais, nome completo, SKU, origem, fase;
  sugestão de kit (se houver) com **Vincular como Fase N** / **Não é kit** reusando o
  **`DialogoVincularKit` que já existe**; situação + atualizado; **"Falta para conferir"** com
  o NÚMERO e a barra de progresso (⚠️ decisão A: **a lista de pendências não existe no dado** —
  `prontidao()` só manda `faltam`; comentar isso no código e **não criar endpoint**); anúncios
  com tipo + MLB com link (⚠️ decisão 2: **sem preço**); rodapé com "Abrir produto" + a ação
  principal.

  Fecha com **×**, **clique no fundo** e **Esc**. `role="dialog"` + `aria-modal`, foco inicial
  no painel.

  Testes de render real: painel com linha completa, com `sugestao_kit` nula, com `anuncios`
  vazio, com `status.faltam = 0` (não mostra a seção), e com **todas as props ausentes**.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produtos-layout.test.js</automated>
  </verify>
  <done>Painel renderiza em todos os casos; gate de fonte provando que não há `axios`/`route(` de busca dentro dele (ele não busca nada).</done>
</task>

<task type="auto" tdd="false">
  <name>Task 4: Montar a tela, atualizar os testes antigos e rodar os gates</name>
  <files>resources/js/Pages/Mlb/Publicador/Produtos.jsx, tests/js/publicador-produtos-fases.test.js, tests/js/publicador-entrada.test.js, tests/js/publicador-alavancas.test.js, tests/js/publicador-sincronizar-resumo.test.js</files>
  <action>
  Em `Produtos.jsx`:
  - ⚠️ **Tirar o `<div className="overflow-x-auto">` e o `<table>`** (linha ~540): é o que causa a
    rolagem horizontal **e** quebraria o sticky. Virar grade CSS com `colunasDaLargura`. **Não**
    introduzir `overflow-hidden` no card.
  - **Um único bloco `sticky top-0 z-10`** com: barra de filtros + barra de seleção + cabeçalho
    das colunas. Fundo `#0f1116`, cantos `12px 12px 0 0`. ⚠️ `top: 0` é o valor certo — o
    `AppLayout` **não** tem header fixo (o header é `h-[60px] shrink-0` que não rola; quem rola é
    o `<main>` com `overflow-y-auto`), então descontar 60px deixaria buraco.
  - Chips de situação → **grupo segmentado** (mesmos 5 filtros, mesmas contagens, mesmo `?filtro=`).
  - "Todas / Só base / Só kits" → **dropdown "Fase: Todas ▾"** (mesmo `?fase=`), fecha com Esc e clique fora.
  - **Ordenação do cliente** nos cabeçalhos Produto, Situação e Atualizado (↑/↓), via `ordenarTopo`,
    aplicada aos de topo; **kits continuam logo abaixo do base**. Default: Situação.
  - **Densidade** confortável/compacto, persistida em `localStorage` chave
    `publicador.produtos.densidade`. ⚠️ Toda leitura/escrita em `try/catch` — em janela privada
    o acessor pode lançar.
  - **Faixa de sugestões** acima do card quando algum produto tiver `sugestao_kit`:
    "{sku} parece kit de {base}. Confirme o vínculo para ele virar Fase 2. [Revisar] [×]".
    Revisar abre o painel do primeiro; × esconde até recarregar.
  - **Seleção em lote**: checkbox por linha + "selecionar todos os visíveis" no cabeçalho; com
    seleção, a barra amarela dentro do bloco fixo com "N selecionados · … · Limpar seleção".
    ⚠️ Ligar **só ao que já existe**; o que não tiver backend fica **escondido, não desabilitado**.
  - **Clique na linha abre o painel** (e Enter na linha focada); a navegação passa para os botões.

  ⚠️ **MANTER, sob pena de regressão:** polling de 5s enquanto houver "publicando", realce das
  linhas novas após Sincronizar, os 3 estados de vazio, "Limpar busca"/"Limpar filtros" (e
  "Limpar busca" zera também o filtro de fase), avisos de status/erro, o rodapé dos rascunhos do
  assistente antigo, a pílula Portal/Publicador, "Editar em grade", "+ Produto", busca, o painel
  do Sincronizar e seu acompanhamento, e `?filtro=`/`?fase=` vindos da Visão geral.

  Depois, **atualizar as asserções que mudaram de forma** nos 4 arquivos de teste antigos.
  Permitido atualizar; **proibido afrouxar ou apagar teste que ainda vale**. Registrar na SUMMARY
  o antes/depois de cada uma e por quê. Os testes das 4 funções puras intocadas têm de passar
  **sem edição**.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && npm run build 2>&1 | tail -3 && npm run test:js 2>&1 | sed 's/\x1b\[[0-9;]*m//g' | grep -E "tests [0-9]+|pass [0-9]+|fail [0-9]+" | tail -3 && git diff --stat -- app routes database | tail -2</automated>
  </verify>
  <done>`npm run build` verde e `Produtos.jsx` no `public/build/manifest.json` (⚠️ o hash do chunk pode ter hífen — resolver pelo manifest, não por grep de nome). `npm run test:js` sem falha nova: as 2 pré-existentes continuam 2. `git diff --stat -- app routes database` **vazio**.</done>
</task>

</tasks>

<verification>
1. `git diff --stat -- app routes database` → **vazio** (é só UI).
2. `git diff --stat -- resources/js/Pages/Mlb/Publicador/Produtos.jsx` mostra as 4 funções puras
   antigas **inalteradas** (conferir no diff, não só pela suíte).
3. `npm run build` verde; `Produtos.jsx` presente no manifest.
4. `npm run test:js`: 2 falhas, as mesmas de sempre.
5. `grep -n "overflow-x-auto" resources/js/Pages/Mlb/Publicador/Produtos.jsx` → vazio.
6. Nenhum `assert.match(..., /disabled/)` novo sem o `=`.
</verification>

<success_criteria>
Percorrer a seção **"Aceite"** da spec item a item na SUMMARY, dizendo COMO cada um foi
conferido. ⚠️ **Não afirmar ter visto a tela** — não há navegador aqui. Para 1280px e ~900px,
provar por teste que o conjunto de colunas, a altura de linha e `miniaturasVisiveis` mudam no
corte de 1100px, e dizer explicitamente que **a conferência visual final é do usuário**.
</success_criteria>

<output>
Create `.planning/quick/261009-prd-layout-v2-da-tela-de-produtos/SUMMARY.md` when done
</output>
