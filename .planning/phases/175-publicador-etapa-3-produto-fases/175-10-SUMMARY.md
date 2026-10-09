---
phase: 175-publicador-etapa-3-produto-fases
plan: 10
subsystem: ui
tags: [publicador, kits, fases, react, inertia, produtos, visao-geral, vinculo, render-real, esbuild]

# Dependency graph
requires:
  - phase: 175-08
    provides: "as 9 chaves novas por produto (`fase`, `quantidade_kit`, `produto_base_id`, `eh_kit`, `rotulo_fase`, `url_produto`, `kits`, `base`, `sugestao_kit`), `contagens.por_fase`, os 3 endpoints de vínculo e a prop `produtosPorFase`"
  - phase: 175-04
    provides: "a rota `mlb.anuncios.publicador.produto` (destino de `url_produto`) e o `PainelDoProduto`"
  - phase: 175-07
    provides: "`PainelCriarFase.jsx` — o molde de overlay/Escape/erro de campo e o padrão de funções puras exportadas para teste"
  - phase: 173 (Etapa 2)
    provides: "`PainelVisaoGeral.jsx` com os 7 blocos, `textoSeguro()`/`numeroSeguro()` e o padrão `filtroInicial()` de leitura da querystring"
  - phase: 164 (Etapa 1)
    provides: "`Produtos.jsx` (tela B) com chips, busca, polling de 5 s, modal e rodapés — tudo preservado"
provides:
  - "Coluna **Fases** na lista de Produtos, entre Origem e Situação"
  - "Kits como linhas recuadas logo abaixo do base, em ordem de fase, com `rotulo_fase` ('Kit N')"
  - "Filtro de fase 'Todas / Só base / Só kits' convivendo com os chips de situação (os dois se combinam)"
  - "`?fase=todas|so_base|so_kits` lido da querystring por whitelist — é o que faz o link 'Prontos para a Fase 2' funcionar"
  - "`montarLinhas()`, `faseDaQuerystring()`, `destinoDoProduto()`, `sugestaoSegura()` — funções puras exportadas de `Produtos.jsx`"
  - "'Abrir produto' (tela do Produto) + 'Continuar' (editor); sem rascunho segue 'Começar rascunho' num clique"
  - "`DialogoVincularKit.jsx` — confirmação do vínculo de combo (§6) e o 'Não é kit' irreversível, nos dois modos do mesmo diálogo"
  - "Bloco 'Produtos por fase' na Visão geral, ABAIXO de 'Situação dos produtos' (os dois convivem)"
  - "`destinoDaFase()`/`itensPorFase()` em `PainelVisaoGeral.jsx` — bucket → par (filtro, fase)"
  - "Coluna Fase nas Últimas publicações"
affects: [176, 174]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Página inteira sob teste de render real: `AppLayout` entra como STUB no `alias` do esbuild em vez de a tela ser partida em dois para o teste caber"
    - "Agrupamento/ordenação de lista mora em FUNÇÃO PURA exportada, nunca em closure lida dentro do `.map()` do JSX (armadilha do Rollup deste projeto)"
    - "Querystring que vem de outra tela tem whitelist por array e prova de ponta a ponta: o teste renderiza a página COM a querystring do link e confere que o chip nasce marcado e a lista já vem filtrada"
    - "Confirmação destrutiva é um SEGUNDO MODO do próprio diálogo da tela, nunca `window.confirm` (caixa nativa não combina com tela dark e não explica o que é irreversível)"
    - "Prop nova de servidor é normalizada por uma função que aceita as DUAS formas quando plano e implementação divergiram — a tela não fica vazia por causa de um contrato ambíguo"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx
    - tests/js/publicador-produtos-fases.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx
    - resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx
    - tests/js/publicador-visao-geral-render.test.js

key-decisions:
  - "`Produtos.jsx` NÃO foi partida: o `AppLayout` entrou como stub no alias do esbuild (precedente da 173-07 em `Configuracoes.jsx`), então o teste exercita a página de produção de verdade — tabela, chips, rodapés e tudo. Extrair a tabela para um componente irmão (a alternativa que o PLAN autorizava) mexeria de lugar a tela mais usada do módulo só por causa do teste."
  - "'Não é kit' é o segundo modo do `DialogoVincularKit`, não um `window.confirm`: a caixa nativa aparece em branco, com fonte do sistema e o domínio em cima, e não dá para explicar nela que a sugestão não volta."
  - "`proximaFase` e `modo` foram acrescentados às props que o PLAN listava. A fase que vai nascer é calculada pela LISTA (que tem a família em mão) e passada ao diálogo; o diálogo não recalcula nada — quem decide a fase é o servidor."
  - "`eh_kit` do servidor é a ÚNICA fonte do filtro 'só base'/'só kits'; a tela nunca deduz kit por SKU. Consequência coerente com o 175-08: kit órfão (base apagado) tem `eh_kit=false` e conta como base nos filtros, do mesmo jeito que já é linha de topo."
  - "`destinoDaFase('sem_oferta')` abre a lista INTEIRA. 'Sem oferta' aqui é 'sem anúncio no ar', que não é nenhum dos chips de situação da lista — inventar um filtro inexistente (ou deixar o número sem destino) seria pior do que levar à lista completa."
  - "`itensPorFase()` aceita o MAPA por bucket (o que o servidor manda) e a LISTA de `{chave, rotulo, numero}` (o que o PLAN descrevia). Os dois contratos divergiram entre 175-08 e 175-10 e a tela não pode ficar vazia por isso."
  - "Sem a prop `produtosPorFase` o bloco novo não aparece e a Visão geral fica byte a byte como era — degrau de compatibilidade, no mesmo espírito do 'Prontos para a Fase 2' do 175-08."

patterns-established:
  - "Teste de render real de PÁGINA: stub de `AppLayout` + stub de `@inertiajs/react` + `window` mínimo cujo `location.search` cada caso troca antes de renderizar"
  - "Prova de filtro vindo por URL: renderizar com a querystring EXATA que a outra tela manda e conferir o `aria-pressed` do chip E as linhas que sobraram"
  - "Gate de fachada correto é `/disabled=/`, nunca `/disabled/` (as classes do módulo contêm `disabled:opacity-40` e casam sempre)"

requirements-completed: [FASE-09, FASE-10, FASE-11]

# Metrics
duration: 95min
completed: 2026-10-09
---

# Fase 175 Plano 10: Impacto nas Etapas 1 e 2 (lado tela) — Summary

**A lista de Produtos passou a mostrar a família — coluna Fases, kits recuados sob o base e filtro de fase que entende o link da Visão geral — e o combo que já existia virou fase de outro produto por um diálogo que exige quantidade ≥ 2 e diz, em letras, o que NÃO muda.**

## Performance

- **Duration:** ~95 min
- **Started:** 2026-10-09 ~09:40 -03
- **Completed:** 2026-10-09 11:15 -03
- **Tasks:** 3 de 3
- **Files modified:** 5 (2 criados, 3 modificados)

## Accomplishments

- **A tela mais usada do módulo ganhou 9 campos novos e entrou num teste de render REAL — a página inteira, não um pedaço dela.** `tests/js/publicador-produtos-fases.test.js` compila `Produtos.jsx` com esbuild e renderiza com `react-dom/server`, com `AppLayout` em stub. São 46 testes, e cada campo novo (`rotulo_fase`, `base`, `kits`, `sugestao_kit`, `eh_kit`, `url_produto`, `fase`, `quantidade_kit`, `produto_base_id`) entra chegando como **objeto**, **nulo** e **ausente**. Nenhum `[object Object]` escapa.
- **O filtro `?fase=` tem prova de ponta a ponta, não só de unidade.** O caso renderiza a página com `?filtro=publicados&fase=so_base` — a querystring literal que o destino "Prontos para a Fase 2" do 175-08 manda — e confere que o chip "Só base" nasce com `aria-pressed="true"`, que o chip "Publicados" também, e que as duas linhas de kit **não estão** no HTML. Sem isso o parâmetro seria ignorado em silêncio.
- **O vínculo de combo (§6) com a confirmação que a §9 pede.** `DialogoVincularKit` abre com o base fixo da sugestão, exige quantidade inteira ≥ 2 (campo **vazio** quando a sugestão não traz o N), mostra no botão a fase que vai nascer, avisa em âmbar quando `conflito_heuristica` e reparte o 422 entre erro de campo (VINC-03/04) e erro do produto inteiro (VINC-01/02/05/06), mantendo o diálogo aberto.
- **Nada saiu das duas telas.** Os gates por literal de `publicador-entrada.test.js` e `publicador-alavancas.test.js` passaram **227/227 sem nenhuma edição**, antes e depois; e o teste novo tem um bloco inteiro de "nada regrediu" (busca, "Editar em grade", os dois rodapés do assistente antigo, a faixa de conta travada, os 3 estados de vazio, pílula de origem e selos). Na Visão geral, os 23 casos antigos continuam verdes sem uma linha alterada.

## Task Commits

Cada task em RED → GREEN (TDD):

1. **Task 1: coluna Fases, kits recuados, filtro de fase e ações** — `61c17216` (test, RED) + `1acae243` (feat, GREEN)
2. **Task 2: DialogoVincularKit + "Não é kit"** — `d2cadc49` (test, RED) + `eec8ad32` (feat, GREEN)
3. **Task 3: "Produtos por fase" e coluna Fase na Visão geral** — `a3b50803` (test, RED) + `83baa8a3` (feat, GREEN)

_O commit `8eb134b9` (`fix(175-11)`) é da sessão paralela, em arquivos de `app/`, e aparece intercalado: todo commit deste plano foi por caminho (`git commit -m … -- <arquivos>`), nenhum `git add -A`, e nenhum arquivo de `app/` ou `routes/` foi tocado._

## Files Created/Modified

- `resources/js/Components/Mlb/Publicador/DialogoVincularKit.jsx` **(novo, 332 linhas)** — o diálogo do vínculo (§6) e o "Não é kit", nos dois modos. Exporta as puras `erroLocalDaQuantidade()`, `erroDeRecusa()`, `proximaFaseDaFamilia()` e `quantidadeInicial()`.
- `resources/js/Pages/Mlb/Publicador/Produtos.jsx` — coluna Fases, recuo dos kits, grupo de filtro de fase, `abrir()`/`abrirEditor()`, montagem do diálogo. Exporta `faseDaQuerystring()`, `montarLinhas()`, `destinoDoProduto()` e `sugestaoSegura()`.
- `resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx` — bloco "Produtos por fase" (abaixo do antigo), coluna Fase nas Últimas publicações, `destinoDaFase()` e `itensPorFase()`.
- `tests/js/publicador-produtos-fases.test.js` **(novo, 46 testes)** — funções puras + render real da página + gates de fonte.
- `tests/js/publicador-visao-geral-render.test.js` — **+10 casos**, nenhum caso antigo alterado.

## Como foi provado que nada de `Produtos.jsx` regrediu

Quatro provas independentes, porque esta tela está em produção desde 08/10:

1. **Os gates por literal rodaram sem edição.** `publicador-entrada.test.js` (bloco `PAGINA_B`: `mlb.anuncios.publicador.editor`, `tabIndex={0}`, `'Enter'`, `conta={empresa.chave}`, `companyId={abas.company_id}`, ausência de `mlb.anuncios.wizard`, `produto.oferta_id`, o `title` do Portal apagado, `5000`, `clearInterval`, `only: ['produtos', 'contagens']`, `variante="faixa"`, `Abrir no assistente antigo`, as 3 mensagens de vazio, `Não foi possível abrir o produto.`, `criativos_ia = { url: null }`, `{criativos_ia?.url && (`, `href={criativos_ia.url}`, `Gerar criativos no assistente antigo`) + `publicador-alavancas.test.js` (`import AbasDaConta`, `<AbasDaConta aba="produtos"`) = **227/227 antes e depois**, byte igual.
2. **Bloco "nada regrediu" no teste novo**, por render: busca, "Editar em grade", rodapé dos criativos, rodapé dos rascunhos antigos, faixa D21/D26, "Esta empresa ainda não tem produtos.", "Nenhum produto cadastrado.", "Nenhum produto neste filtro." + "Limpar busca", pílula Portal/Publicador e os selos de situação.
3. **O diff conta a mesma história.** As únicas remoções são linhas substituídas por versão equivalente ou mais rica: o `visiveis` virou `montarLinhas()` (mesmo filtro de situação, mesma busca, agora com fase e família), a classe inline do chip virou `classeDoChip()` **com as mesmas classes**, e o botão único de ação virou dois. Nenhum chip, contagem, rodapé, aviso, polling, modal ou mensagem foi removido.
4. **`usePublicador.js` saiu intocado** (`git diff --stat` dele: vazio), como o plano exige, e nenhum arquivo de `app/`, `routes/`, `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `KitCriativosGrade.jsx`, `AnunciarMassa.jsx` ou `GradeAnuncioGlide.jsx` foi aberto para escrita.

**A única mudança de comportamento, e ela é a pedida pela §7:** hoje, com rascunho, o botão "Abrir produto" ia para o **editor**; agora vai para a **tela do Produto**, e o editor continua a um clique pelo botão "Continuar" ao lado. Produto sem rascunho segue com "Começar rascunho" abrindo o editor direto, igual a antes.

## Decisions Made

Ver `key-decisions` no frontmatter. As três que mais importam para quem vem depois:

1. **A página não foi partida para o teste caber.** `AppLayout` em stub no alias do esbuild; o que roda no teste é a página de produção.
2. **A confirmação destrutiva é modo do próprio diálogo**, não `window.confirm`.
3. **`itensPorFase()` aceita as duas formas da prop** (mapa do servidor e lista do PLAN), porque os dois contratos divergiram.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] O contrato de `produtosPorFase` divergiu entre o PLAN e o 175-08**
- **Found during:** Task 3
- **Issue:** O `<interfaces>` do PLAN diz `produtosPorFase` = "lista de `{chave, rotulo, numero}`". O que `PainelVisaoGeralService::produtosPorFase()` entrega (175-08, já em produção) é um **mapa por bucket**: `{sem_oferta: {numero, rotulo}, …}` — a mesma forma de `situacaoProdutos`. Implementar o que o PLAN descrevia renderizaria o bloco **vazio** com o dado real.
- **Fix:** `itensPorFase()` normaliza as duas formas e devolve sempre `[{chave, numero, rotulo}]` na ordem do contrato (`ORDEM_POR_FASE`); bucket desconhecido que o servidor passe a mandar entra no fim em vez de desaparecer.
- **Files modified:** `resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx`
- **Verification:** `destinoDaFase/itensPorFase` com o mapa real, com a lista do PLAN, com `undefined` e com `42`; os 33 testes da tela.
- **Committed in:** `83baa8a3`

**2. [Rule 2 - Missing critical] "Limpar busca" não limpava o filtro novo**
- **Found during:** Task 1
- **Issue:** O estado vazio "Nenhum produto neste filtro." oferece "Limpar busca", que zerava `filtro` e `busca`. Com o filtro de fase ativo (por exemplo vindo de `?fase=so_kits` numa conta sem kit), o botão "limpava" e a lista continuava vazia — um beco sem saída na tela.
- **Fix:** o botão passou a zerar os três (`setFiltro('todos'); setFase('todas'); setBusca('')`).
- **Files modified:** `resources/js/Pages/Mlb/Publicador/Produtos.jsx`
- **Verification:** o caso de render do estado vazio (`?filtro=com_problema`) + inspeção do literal no gate de fonte.
- **Committed in:** `1acae243`

**3. [Rule 2 - Missing critical] Produto em formato inesperado na lista derrubava o `.filter()`**
- **Found during:** Task 1
- **Issue:** O filtro de hoje fazia `p.status?.chave` e `p.sku ?? ''` direto. Um item `null` na lista, ou `sku` chegando como objeto, passava para o JSX — e é exatamente a classe de falha que derrubou a tela em 07/10 (`[object Object]` como filho de React).
- **Fix:** `montarLinhas()` descarta item que não é objeto e passa SKU/nome por `textoSeguro()` antes de comparar; a coluna Fases e o SKU recuado também usam `textoSeguro()`.
- **Files modified:** `resources/js/Pages/Mlb/Publicador/Produtos.jsx`
- **Verification:** `montarLinhas` com `null`, `'nao-e-produto'`, `base` string, `kits` objeto e `fase` string; render com produto do **contrato antigo** (sem nenhum campo de fase) e com todos os campos adversos.
- **Committed in:** `1acae243`

### Divergências entre a spec/PLAN e o que ficou na tela

- **"Usar estoque calculado" NÃO foi implementada — exige servidor.** A §6 pede, no cartão da fase, "estoque próprio" + o valor calculado ao lado **e a ação explícita 'Usar estoque calculado'**. As duas primeiras partes já existem desde o 175-04/06 (`PainelDoProduto.jsx` mostra `estoque próprio · calculado do base: N`). **A ação não existe e não dá para nascer neste plano:** ela escreve `estoque_calculado = true` e dispara o recálculo — não há endpoint para isso (`routes/mlb_anuncios.php` tem `vinculo.salvar/remover/recusar`, `fases.*` e nada de estoque), e este plano é frontend puro, proibido de tocar `app/` e `routes/`. Além disso o botão viveria em `PainelDoProduto.jsx`, que esta sessão não pode editar (dono: 175-07). **Fica registrado como pendência para a Fase 176**, que precisa de: endpoint `PUT .../estoque-calculado`, chamada ao `RecalculoEstoqueDoKitService` (175-09) e o botão no cartão da fase.
- **"Situação dos produtos" não foi substituída** — divergência da §7 já decidida pelo 175-08 e reafirmada aqui: o bloco novo vai **abaixo**. Se o usuário preferir substituir de fato, é remover a renderização do bloco antigo em `PainelVisaoGeral.jsx`; o servidor continua entregando os dois.
- **`destinoDaFase('sem_oferta')` abre a lista inteira.** O PLAN pedia "cada um clicável para a lista com `?fase=`/`?filtro=` equivalente", mas "sem nenhum anúncio no ar" não é nenhum dos 5 chips de situação da lista. A alternativa (chip novo "Sem anúncio no ar") é filtro novo em tela de produção, fora do escopo deste plano.
- **Props novas no `DialogoVincularKit`:** `proximaFase` e `modo`, além das 6 que o PLAN listava.

---

**Total deviations:** 3 auto-corrigidas (1× Rule 3, 2× Rule 2) + 4 divergências registradas.
**Impact on plan:** nenhuma ampliação de escopo. A Rule 3 era bloqueio real (o bloco sairia vazio com o dado de produção) e as duas Rule 2 fecham um beco sem saída de interface e a classe de falha que derrubou a tela em 07/10.

## Issues Encountered

- **`Produtos.jsx` é página e arrasta `AppLayout`.** O PLAN autorizava extrair a tabela para um componente irmão "se o bundle ficar inviável". Não ficou: o `alias` do esbuild aponta `@/Layouts/AppLayout` para um stub de 3 linhas (precedente da 173-07 com `Configuracoes.jsx`) e a página de verdade renderiza. **Decisão de não partir a tela**, porque mover a tabela mais usada do módulo só para o teste caber é risco sem retorno.
- **Sem DOM nos testes deste projeto** (não há jsdom): clique e `useEffect` não rodam em `renderToStaticMarkup`. Por isso toda regra que depende de interação está em **função pura exportada** (filtro, família, validação da quantidade, repartição do 422, destino do bucket) e o resto é gate de fonte — mesmo arranjo do 175-07.
- **`window` mínimo no teste.** A leitura de `?filtro=`/`?fase=` acontece na montagem, então o arquivo define um `global.window` com `location.search` que cada caso troca. Nenhum componente do bundle usa `window` fora disso (conferido).
- **Pint não foi rodado**, de propósito: ele reprova este módulo no baseline e reformataria o módulo inteiro, colidindo com a sessão paralela do 175-11. Nenhum arquivo PHP foi tocado de qualquer forma.
- **Sessão paralela:** o 175-11 commitou `8eb134b9` entre o RED e o GREEN da Task 1, em `app/Http/Controllers/MlbPublicadorFaseController.php` e `tests/Feature/Publicador/CapaDoKitTest.php` — arquivos disjuntos dos deste plano.
- **1 falha de PHP na suíte inteira, que NÃO é deste plano.** `tests/Feature/Publicador/CriarFaseCloneTest.php:150` quebra no `FilesystemAdapter` quando a suíte roda junta, e **passa 6/6 (66 asserções) quando roda sozinha** — é estado de disco falso compartilhado entre testes, provavelmente com o `CapaDoKitTest` que a sessão paralela acabou de acrescentar (ele também grava imagem). **Este plano não tocou uma linha de PHP** (`git show --stat` dos 6 commits não lista nenhum arquivo de `app/`, `routes/` ou `tests/Feature`), então a falha não pode vir daqui. Fica avisado para o dono do 175-11.

## Testes

| momento | comando | resultado |
|---|---|---|
| antes | `npm run test:js` | **1519 tests · 1517 pass · 2 fail** |
| depois | `npm run test:js` | **1575 tests · 1573 pass · 2 fail** |
| gates por literal (antes e depois) | `node --test publicador-entrada.test.js publicador-alavancas.test.js` | **227 pass · 0 fail**, sem nenhuma edição nos dois arquivos |
| tela B | `node --test publicador-produtos-fases.test.js` | **46 pass · 0 fail** |
| Visão geral | `node --test publicador-visao-geral-render.test.js` | **33 pass · 0 fail** (23 antigos + 10 novos) |
| bundle | `npm run build` | **✓ built in 1m 14s**; `Produtos.jsx → assets/Produtos-BU5WNfHJ.js` e `VisaoGeral.jsx → assets/VisaoGeral-CVxN543p.js` no `manifest.json` |
| PHP (nenhuma linha de PHP foi tocada) | `artisan test tests/Feature/Publicador tests/Unit/Publicador` | **1140 passed · 1 failed** — `CriarFaseCloneTest.php:150` (`FilesystemAdapter`), que **passa 6/6 sozinho**: é colisão de disco falso na suíte inteira, não regressão deste plano (ver abaixo) |

**56 testes JS novos.** As **2 falhas são as pré-existentes do baseline** e não foram tocadas: `estrutura-grade-glide.test.js:123` ("Características secundárias nasce recolhido") e `polosEntrantes.test.js:188` ("FASES_TERMINAIS"). Contagem lida pelos contadores do `node --test` (`ℹ pass` / `ℹ fail`), nunca pelas marcas `✔`.

Nenhum teste chamou API real (sem axios de verdade: o render estático não dispara efeito, e `@inertiajs/react` entra como stub). Nenhum stub temporário ficou em `tests/js/` (conferido depois da rodada). O gate de fachada usa `/disabled=/`, não `/disabled/` — a correção que o 175-07 fez não foi reintroduzida.

## Roteiro clicável de validação em produção — conta #459 "Dev 02 Testes API"

⚠️ Nada aqui pede comando no servidor: é tudo clique. **`npm run build` já foi rodado**; o deploy depende de autorização e não foi feito.

1. Abrir `/mlb/anuncios` → **Dev 02 Testes API** → aba **Produtos**.
2. **A coluna Fases** tem de aparecer **entre Origem e Situação**, com **"1 unidade"** na maioria das linhas. Se a conta já tiver um kit criado (pelo painel "Criar Fase N" do 175-07), ele aparece **recuado logo abaixo do base**, com um `└` e **"Kit 2"**.
3. **Os chips de situação continuam lá, com os mesmos números** (Todos / Rascunho / Conferidos / Publicados / Com problema) e, **ao lado deles**, o grupo novo **Todas / Só base / Só kits**. Clicar em "Só kits": só as linhas de kit ficam. Clicar em "Só base": os kits saem e o base fica. Clicar em "Todas": tudo volta.
4. **Combinar os dois:** "Publicados" + "Só base" tem de mostrar só base publicado. Os dois filtros valem juntos, não um substituindo o outro.
5. **Botões da linha:** produto **com** rascunho mostra **"Abrir produto"** (abre a tela do Produto, a do 175-04) **e "Continuar"** (abre o editor, como antes). Produto **sem** rascunho mostra só **"Começar rascunho"**, que abre o editor num clique.
6. **Clicar na linha** (fora dos botões) leva à tela do Produto.
7. **Sugestão de kit:** se existir na conta um produto com SKU terminado em `-CB2` (ou nome "Combo 2 …"), a coluna Fases dele mostra **"Kit de …?"** com **"Vincular"** e **"Não é kit"**.
   - **"Vincular"** → o diálogo abre com o base já escolhido, a quantidade pré-preenchida (ou **vazia**, se o sistema não soube o N) e o botão **"Vincular como Fase 2"**. Tentar com **1** ou vazio: o botão fica **travado com a explicação embaixo do campo**.
   - Confirmar com **2** e depois **abrir a tela do Produto do combo**: o **rascunho, o estoque e o MLB do combo têm de estar exatamente como estavam** — o vínculo só registra o parentesco.
   - **"Não é kit"** → pede confirmação dizendo que **a sugestão não volta a aparecer**. Confirmar, recarregar a lista e conferir que a sugestão sumiu daquele produto (e **só** daquele).
8. **Busca:** digitar o SKU do base tem de trazer o base e os kits dele; digitar "Kit 2" tem de achar o kit.
9. **Visão geral** (primeira aba): **"Situação dos produtos" continua lá, com os 4 números de sempre**, e **logo abaixo** aparece o bloco novo **"Produtos por fase"** com 5 números: Sem oferta · Fase 1 publicada · Fase 2 em preparação · Fase 2 publicada · Fase 3+. **A soma dos 5 tem de ser igual ao "Todos" da lista.**
10. **Clicar em "Fase 1 publicada"** leva à lista já com **"Publicados" + "Só base"** marcados. É o mesmo caminho da linha **"Prontos para a Fase 2"** em "O que fazer agora" — clicar nela tem de cair na mesma lista filtrada (e **não** na lista inteira: se cair na lista inteira, o `?fase=` não chegou).
11. **"Últimas publicações"** agora mostra a **fase** de cada publicação ("1 unidade" / "Kit 2"); publicação antiga, sem fase, mostra **"—"**. Nenhuma coluna antiga pode ter saído.
12. **O que NÃO pode ter mudado:** os dois rodapés ("Gerar criativos no assistente antigo" e "Abrir no assistente antigo"), o botão "Editar em grade", o "+ Produto", o "Sincronizar do Portal", a faixa de conta travada e as abas da conta.

## Next Phase Readiness

**O §7 está fechado do lado tela**, menos um item, que fica explícito aqui para a Fase 176:

1. **"Usar estoque calculado" (§6) continua faltando** e é trabalho de servidor + `PainelDoProduto.jsx`: endpoint novo que grave `estoque_calculado = true` e chame o `RecalculoEstoqueDoKitService` (175-09), mais o botão no cartão da fase. A tela já mostra "estoque próprio · calculado do base: N" — falta a ação.
2. **A Fase 174** (tirar a ponte dos criativos do assistente antigo) continua dona do rodapé "Gerar criativos no assistente antigo": ele foi **preservado de propósito** e há gate por literal protegendo-o.
3. **Se o usuário decidir substituir "Situação dos produtos" de fato**, é uma linha em `PainelVisaoGeral.jsx` (remover a renderização do bloco antigo) — o servidor já manda as duas props.

Nada foi deployado, nada foi enviado ao VPS, nenhum push foi feito.

---
*Phase: 175-publicador-etapa-3-produto-fases*
*Plan: 10*
*Completed: 2026-10-09*

## Self-Check: PASSED

- Arquivos conferidos no disco: `DialogoVincularKit.jsx`, `publicador-produtos-fases.test.js`, `Produtos.jsx`, `PainelVisaoGeral.jsx`, `publicador-visao-geral-render.test.js` e este SUMMARY.
- Commits conferidos no histórico: `61c17216`, `1acae243`, `d2cadc49`, `eec8ad32`, `a3b50803`, `83baa8a3`.
- `git diff --stat` de `resources/js/Components/Publicador/usePublicador.js`: **vazio** (intocado, como o plano exige).
- Nenhum arquivo de `app/` ou `routes/` nos commits deste plano (conferido por `git show --stat`).
- `AnunciarML.jsx`, `PainelCriativosIa.jsx`, `PainelDoProduto.jsx`, `PainelCriarFase.jsx` e `useSugestaoKitIa.js`: diff vazio.
- `tests/js/` sem stub temporário sobrando; `npm run test:js` em 1573 pass / 2 fail (as 2 do baseline).
