---
phase: 175-publicador-etapa-3-produto-fases
plan: 09
subsystem: ui
tags: [publicador, kits, estoque, multideposito, editor, react, presenter, render-test, phpunit]

# Dependency graph
requires:
  - phase: 175-01
    provides: "colunas `produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado` e o índice `pubprod_base_ix`"
  - phase: 175-02
    provides: "`CriarFaseService` (que nasce o kit com `estoque_calculado = true`) e `PubProduto::ehKit()/base()/kits()`"
  - phase: 175-04
    provides: "a rota nomeada `mlb.anuncios.publicador.produto` (a tela do Produto, destino do link 'Produto base')"
  - phase: 175-05
    provides: "`PreviaDaFaseService::estoqueDoKit()` — a FONTE ÚNICA do `floor(÷ N)` por variante e por depósito"
  - phase: 164 (Publicador)
    provides: "`EditorRascunhoService::salvarVariantes()/estado()`, `RascunhoRepository` (travar/snapshot/gravarVariacao/tocar), `BarraDoEditor.jsx`, `CartaoVariante.jsx`, `CampoEstoque`"
  - phase: 134 (Meus Anúncios)
    provides: "`ml_acervo_itens` (`available_quantity`) alimentado por job — a base do aviso de divergência com o ML"
provides:
  - "`App\\Services\\Publicador\\RecalculoEstoqueDoKitService::propagar(PubRascunho): array{kits, divergentes}` — propagação `floor(base ÷ N)` para os kits de um base"
  - "Gancho em `EditorRascunhoService::salvarVariantes()`: gravar variante no base recalcula o estoque dos kits, fora da transação do base e sem poder derrubá-la"
  - "8 chaves novas (aditivas) no sub-array `produto` do `estado()`: `fase`, `quantidade_kit`, `eh_kit`, `rotulo_fase`, `estoque_calculado`, `base{id,sku,nome,url}`, `aviso_base_apagado`, `aviso_estoque_ml`"
  - "`EditorRascunhoService::AVISO_ESTOQUE_ML` — o texto literal 'Estoque no ML difere do calculado', espelhado em `BarraDoEditor.jsx`"
  - "Barra do editor identificando a fase ('Fase N · Kit N'), o link 'Produto base' e os dois avisos âmbar"
  - "Estoque do kit somente leitura no cartão da variação, com a legenda 'calculado do produto base' — SKU e GTIN seguem editáveis"
  - "Gate de regressão do presenter: as 17 chaves de primeiro nível do `estado()` e as 7 antigas de `produto` conferidas contra literal"
affects: [175-06, 175-07, 175-08, 175-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Propagação entre rascunhos reusa o caminho normal de escrita (`salvarVariantes`) — nunca UPDATE cru: trava de linha, derivação do total e `tocar()` vêm de graça"
    - "Propagação FORA da transação da escrita original, uma transação por destino: duas travas de rascunho aninhadas é corrida garantida"
    - "Gancho em caminho quente com UM guarda de custo que também é o fim do laço: `exists()` indexado, consultado por coluna do rascunho (nunca carregando o model)"
    - "Escrita derivada só acontece quando o número MUDA — senão cada digitação do operador invalidaria a conferência do vizinho"
    - "Dependência circular entre serviços resolvida por `app()` sob demanda nos DOIS lados, documentado nos dois"
    - "Toda chave nova de presenter consumido por React entra em teste de RENDER REAL chegando certa, como OBJETO e AUSENTE"

key-files:
  created:
    - app/Services/Publicador/RecalculoEstoqueDoKitService.php
    - tests/Feature/Publicador/EstoqueDoKitTest.php
    - tests/js/publicador-barra-editor-fase.test.js
    - .planning/phases/175-publicador-etapa-3-produto-fases/deferred-items.md
  modified:
    - app/Services/Publicador/EditorRascunhoService.php
    - resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx
    - resources/js/Components/Publicador/Mesa/CartaoVariante.jsx

key-decisions:
  - "O recálculo só grava quando o número MUDA. Sem isso, cada digitação na grade do base subiria a revisão do rascunho do kit e derrubaria a conferência dele por nada — `salvarVariantes` roda a cada tecla."
  - "UM `exists()` é o guarda de custo E o fim do laço de recursão, consultado por `$rascunho->produto_id` em vez de `$rascunho->produto`: carregar o model seria uma SEGUNDA consulta em toda gravação de variante do módulo."
  - "Divergência medida do `<behavior>` do plano: o caminho sem kit custa UMA consulta, não zero — o produto não sabe se tem kit sem perguntar ao banco. O contrato de 1 consulta está travado por teste."
  - "Fusível de memória (`self::$emCurso`) além do `exists()`: cobre o CICLO em `produto_base_id` que só dados corrompidos criam (o `CriarFaseService` recusa pelo KIT-02 e o unique `pubprod_base_qtd_uq` também). `static` porque o serviço é resolvido do container a cada volta."
  - "`eh_kit` NÃO é `PubProduto::ehKit()`: é `quantidade_kit >= 2`. O `pubprod_base_fk` é SET NULL, então apagar o base deixa o kit órfão — e ele continua sendo 'Kit 2' para quem está com o editor aberto, só sem o link, com `aviso_base_apagado`."
  - "`aviso_estoque_ml` compara POR ITEM (um anúncio por tipo × variante no modelo User Products, conforme `PayloadBuilderUserProducts`), nunca total contra total."
  - "SEM coleta, não avisa: linha de acervo ausente, `available_quantity` nula, ou conta sem Company (o acervo é escopado por `company_id`; `ml_item_id` não é único globalmente) significam 'não sei', não 'difere'."
  - "`base.url` vem NULA quando a conta não tem token ativo, e a barra não renderiza link nenhum — link quebrado é pior que link ausente."
  - "`travada` do `CartaoVariante` ficou com o significado de hoje; só o estoque ganhou `estoqueTravado`. SKU, GTIN, Cor principal e atributos extras do kit SÃO editáveis."
  - "`GradeVariantes.jsx` NÃO foi editado: o `export default` (a tabela antiga) não é usado por página nenhuma, e `CampoEstoque` já aceitava `travada`. Mexer em componente morto só aumenta o diff."
  - "`textoSeguro` LOCAL no `BarraDoEditor.jsx` (o plano deixava a escolha aberta): não existe `BarraDaConta.jsx` neste repo — o helper é duplicado de propósito em cada componente do Mesa (`PainelCriativos`, `AcervoDaConta`, `IdentidadeDaConta`), e importar de um vizinho arrastaria Radix/axios para o bundle da barra."

patterns-established:
  - "Teste de custo por contagem de consultas (`DB::enableQueryLog`) em gancho de caminho quente, com o número exato no assert"
  - "Teste de não-recursão por EXECUÇÃO, não por leitura: o laço é provado inexistente rodando o caminho de volta"
  - "Gate de regressão de presenter por `array_keys()` contra literal — chave nova é aditiva, nenhuma antiga sai"
  - "Harness de render real reaproveitado para DOIS componentes num arquivo, com stub de `@inertiajs/react` e `route()` global"

requirements-completed: [FASE-12]

# Metrics
duration: 95min
completed: 2026-10-09
---

# Fase 175 Plano 09: Estoque do kit e a fase na barra do editor Summary

**Gravar o estoque de uma variante do produto base agora propaga `floor(estoque ÷ N)` para o rascunho de cada kit — por variante E por depósito, reusando o `estoqueDoKit()` do 175-05 e o caminho normal de escrita do editor — e o editor do kit passou a se identificar na barra ("Fase 2 · Kit 2" + link "Produto base"), com o estoque somente leitura e dois avisos âmbar quando o base foi apagado ou quando o anúncio no ar divergiu do calculado.**

## Performance

- **Duration:** ~95 min
- **Tasks:** 3 de 3 (todas `auto`, todas TDD)
- **Files modified:** 7 (4 criados, 3 editados)

## Accomplishments

- **`app/Services/Publicador/RecalculoEstoqueDoKitService.php` (245 linhas)** — `propagar(PubRascunho): array{kits, divergentes}`, com UM `exists()` indexado como guarda de custo e fim do laço, uma transação por kit, `try/catch` por kit, fusível de memória contra ciclo corrompido e escrita só quando o número muda.
- **Gancho em `EditorRascunhoService::salvarVariantes()`** — **depois** da transação do base, dentro de `try/catch (\Throwable)` com `Log::error`. A escrita que a pessoa pediu não cai por causa da propagação.
- **8 chaves novas no `estado()`**, todas dentro do sub-array `produto`, mais a constante `AVISO_ESTOQUE_ML`. O topo do `estado` ficou com exatamente as mesmas 17 chaves de antes, provado por literal.
- **Barra do editor e cartão da variação** — "Fase N · Kit N", link "Produto base", dois avisos âmbar; estoque `disabled` com a legenda "calculado do produto base", SKU/GTIN intactos.
- **45 testes novos** (26 PHP + 19 JS de render real), incluindo o caso multidepósito que distingue soma-dos-divididos de floor-da-soma, o custo por contagem de consultas, a prova de não-recursão por execução e cada campo novo chegando como OBJETO e AUSENTE.

## Task Commits

1. **Task 1 — `RecalculoEstoqueDoKitService` + gancho (TDD)** — `031e0fc0` (test) → `a26e0aae` (feat)
2. **Task 2 — `estado()` conta a fase (TDD)** — `cabdebcc` (feat; os testes foram escritos antes no mesmo arquivo e o commit traz test+feat juntos, ver "Issues")
3. **Task 3 — barra do editor e estoque somente leitura (TDD)** — `0d3d5643` (feat, com o teste de render)

## Testes: antes e depois

| Suíte | Antes | Depois |
|---|---|---|
| `tests/{Feature,Unit}/Publicador` (PHP) | **1021 ✓** | **1047 ✓** (+26, 5122 asserções) |
| `tests/Feature/Phase165` (toca `salvarVariantes`/editor) | 136 ✓ / 1 incompleto | **136 ✓ / 1 incompleto** (idêntico) |
| `npm run test:js` | 1425 ✓ / **2 ✗** | **1444 ✓ / 2 ✗** (+19, as mesmas 2) |

As 2 falhas JS são as **pré-existentes** do briefing — `estrutura-grade-glide.test.js`
("Características secundárias nasce recolhido") e `polosEntrantes.test.js`
("FASES_TERMINAIS"). Não viraram 3.

⚠️ O briefing dizia "1450 ✓" de baseline; o medido nesta máquina, **antes** de
qualquer mudança minha, foi **1425 ✓ / 2 ✗**. A diferença é de contagem de
baseline, não de regressão: as duas falhas são exatamente as nomeadas, e o delta
do meu trabalho é +19 verdes.

- **`npm run build`: ✅ verde** (30,03s; `Editor-zg-fAZKK.js` 159,57 kB). `public/build` é gitignored neste repo (`.gitignore:41`), então não entrou em commit.
- **`git diff --stat resources/js/Components/Publicador/usePublicador.js`: VAZIO.** Intocado, como mandado.

## Como provei que produto que não é kit passa pelo `estado()` sem mudança

Três provas independentes, nenhuma por leitura:

1. **`test_nenhuma_chave_de_primeiro_nivel_do_estado_saiu`** — `array_keys($estado)`
   comparado com o literal das 17 chaves (`produto`, `rascunho`, `alvos`,
   `atributos`, `eixos`, `variantes`, `imagens`, `atribuicoes`, `grupos_imagem`,
   `schema`, `erro_schema`, `conta`, `efetivos`, `problemas`, `conferencia`,
   `publicacao`, `ja_publicados`), **na ordem**. Esse teste já passava antes da
   implementação da Task 2 — é o gate, não a feature.
2. **`test_produto_que_nao_e_kit_passa_pelo_estado_sem_mudanca`** — as **sete
   chaves antigas** de `produto` (`id`, `sku`, `nome`, `oferta_id`, `origem`,
   `mlb_empresa_id`, `company_id`) conferidas valor a valor, e as novas provando
   que dizem "não é kit": `eh_kit=false`, `rotulo_fase=null`, `base=null`,
   `fase=1`, `quantidade_kit=1`, `estoque_calculado=false` e os dois avisos
   `false`.
3. **A suíte inteira do Publicador** — `EstadoDoProdutoTest`,
   `MlbPublicadorTest`, `TelaDoProdutoTest`, `VisaoGeralTest` e companhia: 1021 →
   1047 sem um único teste quebrado. O `estado()` é lido por dezenas deles.

No front, o mesmo pelos dois lados: `eh_kit: false` na barra não renderiza
`data-fase-do-editor` nem `data-aviso-barra` (`assert.doesNotMatch`), e o cartão
da variação com `estoque_calculado: false` deixa o input de estoque **sem** o
atributo `disabled` e **sem** a legenda.

## Como provei que o recálculo divide por depósito antes de somar

`test_divide_cada_deposito_antes_de_somar_nunca_a_soma`, com a fixture escolhida
justamente para as duas contas divergirem:

- base com depósitos `{A: 5, B: 5}`, Kit **3**;
- resultado gravado no rascunho do kit: `depositos = {A: 1, B: 1}` e `estoque = 2`;
- `floor(soma ÷ N)` daria `floor(10 ÷ 3) = 3` — um item fantasma por variante.

O assert é sobre o que voltou **do banco** (`pub_variantes.estoque` e
`estoque_depositos`), não sobre o retorno do serviço. E
`test_estoque_por_deposito_divide_cada_deposito_e_depois_soma` cobre o caso
assimétrico (`{A: 7, B: 2}`, N=2 → `{A: 3, B: 1}`, total 4).

A divisão **não** foi reimplementada: o serviço chama
`PreviaDaFaseService::estoqueDoKit()` — a fonte única do 175-05 —, que já trata
depósito, nulo e o `max(0, …)`.

## Decisões Made

Ver `key-decisions` no frontmatter. As quatro que mais importam para quem vier depois:

1. **Só grava quando muda.** `salvarVariantes` do base roda a cada digitação na
   grade. Propagar um no-op a cada tecla subiria `revisao` do kit e mandaria a
   conferência dele para o lixo repetidamente. `test_gravacao_que_nao_muda_o_estoque_do_kit_nao_sobe_a_revisao_dele`
   trava isso.
2. **UM `exists()`, por `produto_id` do rascunho.** É o guarda de custo
   (T-175-39) e o fim do laço (T-175-38) ao mesmo tempo: quando o rascunho é de
   um KIT, `produto_base_id = {id do kit}` não casa com nada e a propagação
   devolve vazio na primeira volta. Carregar `$rascunho->produto` custaria uma
   segunda consulta em **toda** gravação de variante do módulo — por isso o
   serviço lê a coluna, não a relação.
3. **`eh_kit` é `quantidade_kit >= 2`, não `ehKit()`.** O SET NULL do
   `pubprod_base_fk` tira o `produto_base_id` do kit quando o base é apagado, e
   `PubProduto::ehKit()` passa a devolver `false` para ele. Na barra isso
   apagaria a identidade do produto que a pessoa está editando. A conta local
   mantém "Kit 2" e acende `aviso_base_apagado`.
4. **Kit publicado É recalculado, e o anúncio no ML NÃO é tocado.** É a decisão
   do plano, honrada: o rascunho é a verdade local, `divergentes` registra quem
   mudou estando no ar, e o aviso "Estoque no ML difere do calculado" é o que
   impede a tela de mentir (T-175-41). Zero chamada ao Mercado Livre neste
   caminho.

## Deviations from Plan

**1. [divergência medida contra a letra do plano] o caminho sem kit custa UMA consulta, não zero**
- **Found during:** Task 1
- **Issue:** O `<behavior>` escreve *"Gravar variantes de um produto que não é base de ninguém não dispara consulta extra nenhuma (teste conta queries)"*. Isso é impossível: o produto não sabe se tem kit sem perguntar ao banco. O `<action>` do mesmo plano já dizia o certo ("tem de custar uma consulta barata e sair").
- **Fix:** o contrato REAL (1 consulta, o `exists()` indexado) está travado por teste (`test_gravar_variantes_de_produto_sem_kit_custa_uma_consulta_a_mais`), com a divergência documentada no docblock do próprio teste. De quebra, o serviço foi escrito para **não** carregar `$rascunho->produto` — seria a segunda consulta.
- **Files:** `app/Services/Publicador/RecalculoEstoqueDoKitService.php`, `tests/Feature/Publicador/EstoqueDoKitTest.php`
- **Committed in:** `a26e0aae`

**2. [Rule 2 — proteção que faltava] escrita só quando o número muda**
- **Issue:** O plano manda chamar `salvarVariantes($rascunhoDoKit, $porChave)` para cada kit. Ao pé da letra, **toda** gravação de variante do base (ou seja, cada tecla na grade) subiria `revisao` do rascunho do kit via `tocar()` e devolveria `VALIDATED → DRAFT`: a conferência do kit morreria repetidamente sem nenhum número ter mudado.
- **Fix:** comparação por valor (`iguais()`, com `null ≠ 0` e ordem de depósito irrelevante) antes de gravar; `kits` passa a devolver só os que realmente mudaram. Dois testes: a revisão SOBE quando muda, e NÃO sobe quando não muda.
- **Committed in:** `a26e0aae`

**3. [Rule 2 — proteção que faltava] fusível de memória contra ciclo em `produto_base_id`**
- **Issue:** O plano reconhece o laço (T-175-38) e aposta no `exists()`. O `exists()` resolve o caso **normal**, mas um ciclo em `produto_base_id` (base apontando para o próprio kit) criado por correção manual de banco faria a propagação ir e voltar sem parar — estouro de pilha em produção, num caminho quente.
- **Fix:** `private static array $emCurso` (ids de rascunho em propagação neste request), com `try/finally`. `static` porque o serviço é resolvido do container a cada volta. `test_ciclo_corrompido_na_familia_nao_recursa_infinitamente` prova que a propagação dá no máximo uma volta de sobra e para.
- **Committed in:** `a26e0aae`

**4. [divergência de forma] `aviso_estoque_ml` é booleano + constante PHP, não a mensagem no payload**
- **Issue:** A Task 2 pede `aviso_estoque_ml = true` *"com a mensagem"*; a Task 3 pede `aviso_estoque_ml true renderiza "Estoque no ML difere do calculado"`. Os dois juntos são ambíguos: se o payload trouxesse a string, o teste da Task 3 (que passa `true`) não renderizaria texto.
- **Fix:** `aviso_estoque_ml` é **booleano**; o texto é a constante pública `EditorRascunhoService::AVISO_ESTOQUE_ML`, espelhada literalmente em `BarraDoEditor.jsx` com o comentário "D23: mesmo texto literal; não variar" — exatamente a convenção que `FamiliaDeFasesService::SEM_COMPANY` já usa com `AbasDaConta.jsx`. O teste PHP confere a constante; o teste JS confere o texto renderizado.
- **Committed in:** `cabdebcc`, `0d3d5643`

**5. [aditivo] 3 chaves a mais do que o `<behavior>` pedia**
- O `<behavior>` da Task 2 nomeia `fase`, `quantidade_kit`, `eh_kit`, `rotulo_fase`, `estoque_calculado`, `base` e os dois avisos. Nenhuma foi retirada. O que o `base` ganhou foi forma completa (`{id, sku, nome, url}`, como o plano descreve no `<action>`), e os avisos são sempre booleanos presentes — nunca ausentes —, para o front nunca precisar de `?? false`.

**6. [não executado de propósito] `GradeVariantes.jsx` não foi editado**
- O plano manda conferir por grep se o `export default` (a tabela) é usado por alguma página e, se não for, **não** editar. Conferido: `grep -rn "GradeVariantes" resources/js/` só acha o import NOMEADO (`CampoEstoque, CampoGtin, CampoSku, atributosExtrasDaVariante`) em `CartaoVariante.jsx`. O default está morto. Não editado.

**Total deviations:** 6 (2 de corretude/proteção, 2 de contrato medido, 1 aditiva, 1 de não-ação prevista pelo próprio plano).
**Impact:** nenhuma amplia escopo; nenhuma mexe em arquivo fora do `files_modified` do plano.

## Issues Encountered

1. **Dependência circular `EditorRascunhoService` ↔ `RecalculoEstoqueDoKitService`.**
   O gancho precisa do recálculo e o recálculo precisa do editor (para reusar o
   caminho normal de escrita). Injeção no construtor nos dois lados faria o
   container recursar. Resolvido com `app()` sob demanda nos **dois** lados,
   documentado nos dois.
2. **`estado()` de um rascunho com publicação precisa do schema da categoria.**
   `PublicacaoService::problemas()` é chamado para toda publicação que não esteja
   `RUNNING`, e ele pede `CategorySchemaRepository::obter()`. No cenário de teste
   (sem categoria, sem ML), isso lança `RuntimeException` da camada de token — e
   o `catch` de lá só pega `RegraViolada`. O teste criou a publicação como
   `RUNNING` (o que importa para `ja_publicados` é o ITEM `CREATED`), com o
   motivo no comentário. **É um 500 latente em produção e está registrado em
   `deferred-items.md`** — fora do escopo desta plan (`PublicacaoService` não está
   no `files_modified`).
3. **Commits da Task 2 em um só.** A ordem RED→GREEN foi respeitada na execução
   (os 8 testes de `estado()` foram escritos e rodados falhando antes da
   implementação), mas os testes das Tasks 1 e 2 moram no MESMO arquivo que o
   plano pediu (`EstoqueDoKitTest.php`), já versionado pelo commit de RED da
   Task 1 — commitar só o trecho novo de um arquivo exigiria `git add -p`. Então
   a Task 2 tem um commit `feat` com test+código. O gate `test_nenhuma_chave_de_primeiro_nivel_do_estado_saiu`
   já passava antes da implementação, o que é a prova útil (ele é o gate, não a feature).
4. **O harness de render e o `/disabled/` cru.** A classe dos inputs do editor
   contém `disabled:cursor-not-allowed disabled:opacity-50` (variantes do
   Tailwind), então um `assert.match(html, /disabled/)` casa com a CLASSE e passa
   mesmo com o campo editável. O arquivo define `const DESABILITADO = /\sdisabled=""/`
   e extrai a tag do input antes de comparar — anotado no próprio teste, porque é
   uma armadilha que vale para qualquer render test deste módulo.
5. **Baseline JS do briefing (1450) ≠ medido (1425).** Medido nesta máquina antes
   de qualquer mudança. Sem impacto: as 2 falhas são as nomeadas e continuam 2.
6. **Estoque nulo + erro "Preencha este campo".** Num kit calculado cujo base não
   tem estoque, o campo fica `disabled` E o `useErroDoCampo` pede o valor (regra
   antiga: `vazio: ativa && ! multi && estoque === null`). A legenda "calculado do
   produto base" não aparece nesse caso, porque `Campo` troca a dica pelo erro.
   **Não mexi**: a regra de erro é pré-existente e o bloqueio está certo (não se
   publica sem estoque) — o caminho de resolução é informar o estoque no BASE.
   Fica como nota para o `175-10`, se a verificação quiser um texto melhor ali.

## Como o usuário confere isto (produção, conta #459 "Dev 02 Testes API")

O banco local não tem empresa com `ml_token`, então a conferência à mão é em
produção. **Nenhum passo abaixo altera anúncio no Mercado Livre** — o recálculo
grava só no rascunho do Publicador.

**Pré-requisito:** um produto da conta #459 com a **Fase 1 publicada** e um
**Kit 2** já criado (pelo `175-05`/`175-07`).

**Roteiro clicável — a barra e o estoque travado:**

1. Abrir `https://admin.ecfconsultoria.com.br/mlb/anuncios/publicador`, escolher
   a conta **#459 "Dev 02 Testes API"** e abrir o produto base.
2. Na tela do Produto, clicar em **"Editar"** no cartão da **Fase 2 · Kit 2**.
3. **Na barra do topo tem de aparecer** a pílula **"Fase 2 · Kit 2"** e, ao lado,
   o link **"Produto base"**. Clicar nele tem de voltar para a tela do Produto do
   base (com a Fase 2 destacada).
4. Voltar ao editor do kit e ir na etapa **Detalhes**. No cartão de cada variação:
   - o campo **Estoque** tem de estar **cinza/desabilitado**, com a frase
     **"calculado do produto base"** logo abaixo;
   - o campo **SKU** ao lado tem de continuar **editável** (clicar e digitar
     funciona — e depois desfazer, se não quiser mudar);
   - em conta multidepósito, **cada caixa de depósito** tem de estar desabilitada.

**Roteiro clicável — o estoque acompanhando o base:**

5. Voltar à tela do Produto e abrir o editor da **Fase 1** (o base).
6. Etapa **Detalhes** → mudar o **Estoque** de uma variação de, por exemplo,
   **10 para 9**. Esperar o indicador da barra dizer **"Salvo há Ns"**.
7. Voltar à tela do Produto → abrir o editor do **Kit 2** → etapa **Detalhes**.
   **O estoque da mesma variação tem de estar 4** (`floor(9 ÷ 2)`).
8. Repetir com **9 → 8**: o kit tem de virar **4** também (o número não muda, e
   nada mais deve acontecer). Com **8 → 7**: o kit tem de virar **3**.
9. **Conta multidepósito:** pôr `A = 7` e `B = 2` no base. O kit tem de ficar com
   `A = 3`, `B = 1` e **total 4** — e não `floor(9 ÷ 2) = 4` por coincidência:
   testar `A = 5`, `B = 5`, num **Kit 3**, que tem de dar `A = 1`, `B = 1`,
   **total 2** (e NÃO 3).

**Roteiro clicável — o aviso de divergência com o ML:**

10. Com o **Kit 2 já publicado**, mudar o estoque do BASE (passo 6) de modo que o
    calculado do kit fique diferente do que está no ar.
11. Abrir o editor do kit: **a barra tem de mostrar, em âmbar, "Estoque no ML
    difere do calculado"**.
12. ⚠️ **Conferir no Mercado Livre que o anúncio do kit NÃO mudou** — atualizar
    estoque de kit publicado está explicitamente fora desta etapa (§1, "Não
    entra"). O aviso existe exatamente para a tela não mentir sobre isso.
13. O aviso **só aparece quando houve coleta do acervo** (`ml_acervo_itens` é
    alimentado por job). Se o job ainda não rodou para esse MLB, a barra fica
    calada — e isso é o comportamento certo, não um bug.

**Combo vinculado (quando o `175-08`/`175-10` expuser a ação):** produto com
`estoque_calculado = false` tem de manter o estoque **próprio e editável**, e o
recálculo do base **não** pode tocá-lo.

## Next Phase Readiness

**Pronto para quem consome o `estado()`:**

```
estado.produto = {
  id, sku, nome, oferta_id, origem, mlb_empresa_id, company_id,   // as 7 de antes, intactas
  fase: int, quantidade_kit: int, eh_kit: bool,
  rotulo_fase: "Fase 2 · Kit 2" | null,
  estoque_calculado: bool,
  base: { id, sku, nome, url } | null,                            // url null = conta sem token
  aviso_base_apagado: bool,
  aviso_estoque_ml: bool,
}
```

- Quem quiser o texto do aviso no servidor: `EditorRascunhoService::AVISO_ESTOQUE_ML`.
- Quem precisar propagar estoque de novo: chamar
  `RecalculoEstoqueDoKitService::propagar($rascunhoDoBase)` — ele é idempotente
  (não grava se nada mudou) e seguro de chamar em qualquer ordem.

**Pendências que este plano NÃO resolve (de propósito):**

- **Atualizar o estoque do kit no Mercado Livre depois de publicado** — §1 "Não
  entra". Hoje o rascunho acompanha e a barra avisa; nada é enviado ao ML.
- **A ação "Usar estoque calculado"** do combo vinculado (cartão da fase, §3) é
  do `175-08`/`175-10`. O servidor já sabe distinguir os dois casos
  (`estoque_calculado`), e o recálculo já respeita o `false`.
- **Rota nova** — nenhuma foi criada. Nada deste plano exigiu rota, e
  `routes/mlb_anuncios.php`, `MlbPublicadorFaseController` e
  `app/Services/Creative/` ficaram **intocados** (são do `175-06`).
- **`REQUIREMENTS.md` da raiz continua parado na v17.0** (memória conhecida): o
  ID `FASE-12` vive no `REQUIREMENTS-v*.md` da milestone e precisa ser marcado à
  mão no fechamento da fase.
- **`deferred-items.md` item 1** — o `catch` estreito de
  `PublicacaoService::problemas()`, que é um 500 latente no editor. Vale uma
  quick task.

---
*Phase: 175-publicador-etapa-3-produto-fases · Plano 09*
*Completed: 2026-10-09*

## Self-Check: PASSED

Conferido por reconsulta, não por lembrança:

- `app/Services/Publicador/RecalculoEstoqueDoKitService.php` — existe, 245 linhas
- `app/Services/Publicador/EditorRascunhoService.php` — existe, com **2** menções a `RecalculoEstoqueDoKitService` (o `key_link` do plano: o gancho no fim de `salvarVariantes`)
- `resources/js/Components/Publicador/Mesa/BarraDoEditor.jsx` — existe, 167 linhas (era 116)
- `resources/js/Components/Publicador/Mesa/CartaoVariante.jsx` — existe, 167 linhas
- `tests/Feature/Publicador/EstoqueDoKitTest.php` — existe, 617 linhas, **26 ✓** (73 asserções)
- `tests/js/publicador-barra-editor-fase.test.js` — existe, 301 linhas, **19 ✓**
- `.planning/phases/175-publicador-etapa-3-produto-fases/deferred-items.md` — existe
- Commits `031e0fc0`, `a26e0aae`, `cabdebcc`, `0d3d5643` — todos presentes em `git log`
- `tests/{Feature,Unit}/Publicador`: **1047 ✓** (baseline 1021, +26, zero regressão)
- `tests/Feature/Phase165`: 136 ✓ / 1 incompleto — idêntico ao de antes
- `npm run test:js`: **1444 ✓ / 2 ✗** (baseline 1425 ✓ / 2 ✗ — as mesmas duas)
- `npm run build`: verde, 30,03s
- `git diff --stat resources/js/Components/Publicador/usePublicador.js`: **vazio**
- `git status` de `routes/mlb_anuncios.php`, `app/Http/Controllers/MlbPublicadorFaseController.php` e `app/Services/Creative/`: **sem modificação** (território do `175-06`)
- `ls -a tests/js | grep "^\."`: nenhum stub temporário residual
