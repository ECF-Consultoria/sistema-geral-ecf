---
tipo: quick
slug: prd-layout-v2-da-tela-de-produtos
data: 2026-10-09
status: complete
origem: design_handoff_publicador/MELHORIA-tela-produtos.md
---

# Layout v2 da aba Produtos do Publicador

Reconstrução de `resources/js/Pages/Mlb/Publicador/Produtos.jsx` conforme
`design_handoff_publicador/MELHORIA-tela-produtos.md`. **Só UI** — nenhum arquivo PHP tocado.

## Commits

| hash | o que |
|---|---|
| `e58e5514` | funções puras do layout v2 (+32 testes) |
| `635499d8` | `LinhaDeProduto` + `MenuDeAcoesDoProduto` |
| `34aa2e7f` | `PainelDoProdutoLateral` de 440px |
| `610a8b34` | monta a tela e atualiza os gates |
| `dddcb6a8` | prova do aceite 3 (altura igual de linha) |

**Criado:** `Components/Mlb/Publicador/layoutDaListaDeProdutos.js`, `LinhaDeProduto.jsx`,
`MenuDeAcoesDoProduto.jsx`, `PainelDoProdutoLateral.jsx`, `tests/js/publicador-produtos-layout.test.js` (97 testes).
**Modificado:** `Produtos.jsx`, `SeloStatusProduto.jsx` (prop `curto`, default `false`),
`tests/js/publicador-produtos-fases.test.js`, `tests/js/publicador-entrada.test.js`.

## Gates — medidos por mim (orquestrador), não só relatados

| gate | resultado |
|---|---|
| `npm run test:js` | **1763 testes · 1761 pass · 2 fail** (as 2 pré-existentes: `estrutura-grade-glide`, `polosEntrantes`) |
| `npm run build` | verde |
| manifest | `resources/js/Pages/Mlb/Publicador/Produtos.jsx` → `assets/Produtos-C__0FKbE.js`, arquivo existe |
| `git diff --stat -- app routes database` | **vazio** |
| `grep -c overflow-x-auto Produtos.jsx` | **0** |
| 4 funções puras antigas | **byte a byte IDÊNTICAS** (md5 do corpo, antes × depois) |

⚠️ O hash do chunk saiu `C__0FKbE` — com dois sublinhados. Resolver o manifest **pelo JSON**, nunca
por grep de nome de arquivo: um padrão estreito dá falso negativo (aconteceu nesta sessão).

## A seção "Aceite", item a item

⚠️ **Sem navegador nesta sessão. A tela não foi vista.** A conferência visual final é do usuário.

1. **1280px sem rolagem, todas as colunas** — ⚠️ **MEIO ATENDIDO, e a spec se contradiz.** Ver
   "O conflito de geometria" abaixo. Sem rolagem: ✅ provado. Todas as 7 colunas a 1280px com a
   barra lateral expandida: ❌ **impossível com os números da spec.**
2. **Cabeçalho fixo ao rolar** — ✅ `sticky top-0 z-10 rounded-t-xl bg-ecf-card` no HTML montado;
   gate prova que o card não ganhou `overflow-hidden`. `top: 0` é o valor certo (o header do
   `AppLayout` é `h-[60px] shrink-0` e não rola; quem rola é o `<main>` com `overflow-y-auto`).
3. **Todas as linhas com a mesma altura** — ✅ 7 casos (SKU de 53 caracteres, nome de 99, sugestão
   de kit, 9 pendências, kit recuado, tudo junto): **uma única** `height:64px` por linha, 52px no
   compacto. O "uma única" faz parte da asserção.
4. **Clique abre o painel, Esc fecha, Enter abre** — ✅ estrutura provada (`tabindex="0"`,
   `onKeyDown` com `ev.target === ev.currentTarget`, `role="dialog"`, `aria-modal`, listener de
   `Escape`, fundo clicável, ×). ⚠️ Que o `keydown` real feche **não** é provável sem DOM.
5. **Botão principal muda com a situação** — ✅ `deepEqual` nas 7 chaves; `data-acao-principal`
   exatamente 1× por linha; `primario` só em `pronto`, `erro` só em `parcial`/`erro`.
6. **Vincular / Não é kit pelo painel e pela faixa** — ✅ os dois botões no painel disparam o
   `DialogoVincularKit` que já existia; a faixa leva ao painel pelo "Revisar". Nenhum endpoint novo.
   ⚠️ O efeito do clique (PUT/POST) é o mesmo código de antes; não há teste de clique neste projeto.
7. **`?filtro=` e `?fase=` da Visão geral** — ✅ os testes de `faseDaQuerystring` passam **sem
   edição**; render segue provando `?filtro=publicados&fase=so_base`, `?fase=so_kits` e o lixo
   caindo em "todas".
8. **Build e test:js verdes** — ✅ ver os gates.

## ⚠️ O conflito de geometria (decisão do usuário pendente)

A referência decide o breakpoint por `window.innerWidth`. Aqui isso **traria de volta a rolagem
horizontal**, porque o conteúdo é muito mais estreito que a janela. Medido no código:

```
janela                                   1280 px
− barra lateral expandida (w-64)        − 256
− padding do <main> (p-6)               −  48
= largura do container                   976
− padding do container (px-8)           −  64
= LARGURA ÚTIL DO CARD                   912 px

colunas do conjunto largo:
44 + 240 + 132 + 172 + 120 + 92 + 152  =  952 px  (+ gaps)
```

**952 > 912: as 7 colunas não cabem.** Então a tela passou a medir a largura **do conteúdo**
(`clientWidth` + listener de `resize`), que é o que a spec literalmente pede ("largura do
conteúdo"), e a 1280px com a barra expandida ela cai no **conjunto estreito** (6 colunas,
"Atualizado" só no painel). Sem rolagem — que é o que o aceite protege. Com a barra **recolhida**
(`w-16`) sobram ~1104px e as 7 colunas aparecem.

Para ter as 7 colunas a 1280px **com** a barra expandida, é preciso ceder ~40px em algum lugar:
reduzir o `px-8` do container, ou baixar o mínimo da coluna Produto de 240 para 200. **Decisão do
usuário** — é trade-off visual, não técnico.

## As 4 adaptações aprovadas pelo usuário (2026-10-09)

1. **Miniatura = INICIAIS do nome**, não foto: `produtosParaTela` não manda imagem nenhuma, e a
   referência também desenha `iniciais()`. Comentado nos três arquivos que foto real exigiria
   campo novo no servidor.
2. **Sem preço no painel**: `anuncios[]` é só `{ml_item_id, listing_type_id}`. Tipo + MLB com link.
   Teste trava o total de ocorrências de "preço" em 1 — o `title` pré-existente da pílula do Portal.
3. **`status.chave === 'rascunho'` é "NÃO existe rascunho"** (`prontidao()` L728, quando `$r` é
   nulo). Logo `rascunho → "Começar rascunho"`, `conferir → "Continuar"`. Default seguro para
   chave desconhecida/nula/objeto.
4. **Asserções atualizadas, nunca afrouxadas** — 10 em 2 arquivos; em 6 delas a cobertura
   **aumentou**. Resumo abaixo.

## As 10 asserções alteradas

**`publicador-entrada.test.js` (5):** `tabIndex`/`Enter` migraram de `Produtos.jsx` para
`LinhaDeProduto.jsx` (+ gate de `aoAbrirPainel`); `companyId` virou `abas?.company_id ?? null`;
a pílula D27 e seu literal migraram para a linha; o gate de altura deixou de ler a classe `h-14`
e passou a ler `CLASSE_DA_LINHA` + `alturaDaLinha(densidade)` (a altura virou `style`); a lista
`ARQUIVOS` dos gates de design ganhou os 3 componentes novos (+9 testes).

**`publicador-produtos-fases.test.js` (5):** os cabeçalhos passaram de 7 para 5 (SKU e Origem
saíram como coluna) **com asserção nova de que o dado dos dois continua na linha**; o filtro de
fase virou dropdown e ganhou sub-teste que o abre para não perder a cobertura das 3 opções;
`?fase=` passou a conferir o rótulo do gatilho; os sub-testes de ação viraram "UM botão por
linha" + um teste novo cobrindo as 5 chaves restantes; o gate de `stopPropagation`/`<tbody>`
virou gate de ausência de tabela + sticky + colunas.

⚠️ **Achado no caminho:** a fixture antiga usava `status.chave = 'sem_rascunho'`, **chave que
`prontidao()` nunca emite**. O teste passava porque o rótulo era decidido por `rascunho_id`.
Agora é decidido por `acaoPrincipal(status)` e a fixture reflete o servidor.

## Desvios

- **[Bug REAL corrigido] `contagens`/`abas` nulos derrubavam a tela.** O default de
  desestruturação só cobre `undefined`; uma recarga parcial pode mandar `null` e
  `contagens.todos` lançava `TypeError`. Corrigido para `?.` + `?? null`.
- **`layoutDaListaDeProdutos.js` (módulo folha, fora da lista do plano).** O plano pedia as
  funções exportadas de `Produtos.jsx`, mas os componentes novos precisam delas e são importados
  **pela** página — isso fecharia **ciclo de import**, e ciclo + Rollup é a mesma família do bug
  que já apagou variável de escopo no bundle de produção daqui. A página **reexporta** as 8, com
  teste provando.
- **`SeloStatusProduto` ganhou `curto` (default `false`).** Era o texto "Faltam N itens" dentro do
  selo que quebrava a altura da linha. Nenhum rótulo mudou; editor e `PainelDoProduto` seguem
  chamando sem a prop.
- **Seleção em lote traz só "N selecionados" + "Limpar seleção".** "Preencher com IA" e "Abrir na
  grade" ficaram **escondidos** (regra do plano) porque **não há endpoint que receba uma seleção
  de produtos do Publicador**: `MlbAnuncioController::massa()` recebe só `{company}` e reconstrói
  a grade a partir de `ml_anuncio_rascunhos` (assistente **antigo**). ⚠️ **A seleção hoje é só um
  contador.**
- **"Limpar busca" → "Limpar busca e filtros"**: o botão já zerava os três; o rótulo mentia.
- **"Criar Fase 2" do menu ⋯** leva à tela do Produto, onde o painel já mora (Fase 175).
- **ARIA de tabela ficou de fora**: o bloco sticky único (filtros + seleção + cabeçalho) é
  incompatível com `role="table"`, que exige filhos `rowgroup`/`row`. Em troca: linha focável,
  `title` com o valor completo em cada célula, e cabeçalhos ordenáveis como `<button>` com
  `aria-label` anunciando coluna e sentido. Para ter o ARIA, o preço é dividir o sticky em dois
  com `top` calculado em runtime.

## Pendências

1. **Comentário desatualizado em `montarLinhas`** (`Produtos.jsx` ~L198): ainda fala de `<tbody>`,
   que não existe mais. Deixado intocado de propósito (a função estava sob verificação byte a
   byte). Vale uma linha em commit separado.
2. **Duas asserções vazias PRÉ-EXISTENTES** em `tests/js/publicador-produto-render.test.js`
   linhas **208** e **250**: `assert.match(html, /disabled/)` casa sempre (as classes contêm
   `disabled:opacity-40`). Vêm de `0f5e537c` (Fase 175-04), fora do escopo desta tarefa.
   Deveriam virar `/disabled=/`.
3. **A lista de pendências do painel não existe no dado** (decisão A): `prontidao()` só manda
   `faltam`. O painel mostra o número + barra + "O editor mostra quais são, etapa por etapa.", e
   **nenhum endpoint foi criado** — há gate provando que o painel não busca nada.
4. **A geometria do breakpoint** (acima) espera decisão do usuário.
