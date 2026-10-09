---
tipo: quick
slug: t04-detalhe-do-produto-v2
data: 2026-10-09
origem: stitch_ecf_marketplace_publisher_redesign/04_detalhe_do_produto_fases_ofertas_dark_theme/
files_modified:
  - resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx
  - resources/js/Components/Mlb/Publicador/CartaoDaFase.jsx
  - tests/js/publicador-produto-render.test.js
  - tests/js/publicador-detalhe-produto-v2.test.js
autonomous: true
---

<objective>
Redesign da **tela 04 — Detalhe do Produto (Fases & Ofertas)**, a partir do mockup
`stitch_ecf_marketplace_publisher_redesign/04_detalhe_do_produto_fases_ofertas_dark_theme/`.
Terceira das 4 telas do pacote.

A conferência (feita antes deste plano) mostrou que **a estrutura central já existe** — a matriz
de fases, a tabela de ofertas, a biblioteca de criativos e o mapeamento vieram com a Etapa 3,
que subiu hoje. O trabalho é **reorganizar no layout do mockup**; o que não existe é quase todo
do bloco **financeiro/comercial**.

⚠️ Esta tarefa é **só frontend**: `git diff --stat -- app routes database` tem de sair vazio.
Se algo parecer exigir servidor, **pare e registre** em vez de criar campo.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**Tokens:** os do projeto. ⚠️ Botão primário é o amarelo **translúcido**
(`border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow`) — gate `publicador-entrada.test.js:53`.

**O que `FamiliaDeFasesService::paraTela()` JÁ entrega** (Etapa 3, em produção desde 09/10):
- `base`: `id, sku, nome, origem, oferta_id, categoria, estoque_total, foto_url, editor_url, base_excluido`
- `fase_destacada`, `fases[]` (número, rótulo, estado, ofertas, `estoque_proprio`,
  `estoque_calculado_valor`, `quantidade_kit`, `produto_id`), `proxima_fase`
  (`numero, quantidade_sugerida, habilitado, motivo`)
- `ofertas[]`: `fase, produto_id, listing_type_id, tipo_rotulo, titulo, ml_item_id, preco,
  vendas, vendas_publicacao, visitas, situacao, visitas_nao_avaliadas, detalhe_disponivel,
  detalhe_motivo`
- `historico[]`, `criativos[]` (`indice` + `url` da imagem do slot), `mapeamento`
  (`vazio, medidas{comprimento,largura,altura,unidade}, peso, material, ean`)

O painel atual (`PainelDoProduto.jsx`) já renderiza os 6 blocos da §3 da Etapa 3, **inclusive o
botão que abre o painel Criar Fase N** e o botão **"Usar estoque calculado"** (quick `261009-uec`).
</interfaces>
</context>

<decisoes_do_usuario>
1. **Entregar a estrutura inteira do mockup** — cabeçalho, matriz de três fases, tabela de
   ofertas, biblioteca de criativos, rodapé de chamada para a Fase 2 — **com os dados que temos**.
2. **Blocos financeiros desenhados e VAZIOS, com o motivo dito** (mesmo tratamento das telas 01 e
   02): Custo Médio, Preço Sugerido, Margem, Saúde Cadastral, GMV Faturado, Conversão Estimada,
   Ranking de Categoria, o sparkline de performance, "Economia cliente R$ 19,90" e o preço
   sugerido do kit. ⚠️ **Nunca inventar número nem percentual.**
3. **Estoque ERP e "Bling Sincronizado" não entram como fato** — mesma regra das outras telas
   (decisão 8 do handoff). O `estoque_total` que temos é o do **rascunho**, não do ERP: rotular
   como tal, sem citar ERP.
4. ⚠️ **O mockup está DESATUALIZADO em dois pontos — seguir o código, não o desenho:**
   - **"8 Imagens Prontas" e "Custo Total IA: R$ 3,40"** são do kit de **7 imagens**, que deixou
     de existir (quick `261007-kit2`). Hoje o kit é de **2 imagens (~R$ 1,10)**. A grade mostra
     **quantas imagens o kit realmente tem** (`criativos.length`), e o custo, se exibido, sai do
     valor real — nunca o literal 3,40.
   - **"Tipo Exposição — Premium (16%) / Clássico (12%)"**: a comissão do ML **varia por
     categoria e faixa de preço**, e os learnings registram que sem `logistic_type` +
     `shipping_mode` a tarifa sai errada. **Mostrar só o tipo** (Clássico/Premium), sem
     percentual — número errado com cara de certo é pior que número nenhum.
5. **"100% Reutilizável na Fase 2" / "custo zero" PODE ficar** — é verdade: o
   `CriarFaseService` copia as fotos do base para o kit (com bytes próprios). É das poucas
   afirmações comerciais do mockup que o sistema sustenta.
6. **Fase 03 (Cross-selling & Combos)** entra como **cartão de roadmap**, sem ticket estimado nem
   requisito inventado — o estado "Liberado após ativação da Fase 2" é real (depende da família).
7. **Barra lateral, ⌘K e pílula de contexto do topo ficam fora** — igual às telas 01 e 02.
</decisoes_do_usuario>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `CartaoDaFase.jsx`</name>
  <files>resources/js/Components/Mlb/Publicador/CartaoDaFase.jsx, tests/js/publicador-detalhe-produto-v2.test.js</files>
  <action>
  O cartão da "Metodologia de Evolução de Fases" do mockup, nas três variantes: **concluída**
  (borda discreta), **oportunidade** (destaque amarelo, é a que tem a ação) e **roadmap**
  (apagada, com o motivo do bloqueio).

  Conteúdo por cartão, **só do que existe**: número e título da fase, selo de estado, as linhas
  de fato (anúncios no ar, preço unitário, criativos aplicados, vendas acumuladas) e o rodapé de
  ação. ⚠️ Linha cujo dado não existe **não vira zero**: ou não aparece, ou diz o motivo.

  ⚠️ Reaproveitar o estado de fase que o serviço já deriva (`fases[].estado`) — **não**
  reimplementar a derivação. A Etapa 3 registrou que `pub_produtos` não tem (e não vai ter)
  coluna de status.

  Render real: cartão nas 3 variantes; campos como **objeto**, nulos e ausentes; `fases: null`.
  ⚠️ `/disabled=/`, nunca `/disabled/`.
  ⚠️ **Montar TODOS os bundles ANTES do primeiro `test()`** — o `after()` do `node --test` já
  matou um arquivo inteiro assim, sem nenhum teste falhar e só na suíte completa.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-detalhe-produto-v2.test.js</automated>
  </verify>
  <done>Render real verde nas 3 variantes e em todos os casos adversos.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: o cabeçalho do produto</name>
  <files>resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx, tests/js/publicador-detalhe-produto-v2.test.js</files>
  <action>
  Cabeçalho no layout do mockup: foto (ou iniciais, quando `foto_url` é nulo — mesma solução da
  tela 01), nome, SKU, pílula de categoria, EAN e ficha curta do `mapeamento`, mais a faixa de
  números à direita.

  Na faixa: **Estoque** (o `estoque_total`, rotulado como **estoque do rascunho**, decisão 3) e
  os quadros **vazios e honestos** de Custo Médio, Preço Sugerido/Margem e Saúde Cadastral
  (decisão 2). A linha "Performance últimos 30 dias" entra com o que vier das `ofertas[]`
  (vendas e visitas somadas) e **sem** GMV, conversão, ranking nem sparkline.

  ⚠️ `mapeamento.vazio === true` ⇒ bloco âmbar "não informado", que já é o comportamento de hoje
  e não pode sumir.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-detalhe-produto-v2.test.js tests/js/publicador-produto-render.test.js</automated>
  </verify>
  <done>Cabeçalho novo sem afirmar nenhum número que não exista; o teste antigo da tela do Produto continua verde.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: ofertas, criativos e o rodapé da Fase 2</name>
  <files>resources/js/Components/Mlb/Publicador/PainelDoProduto.jsx, tests/js/publicador-produto-render.test.js, tests/js/publicador-detalhe-produto-v2.test.js</files>
  <action>
  - **Publicações & Ofertas** no formato do mockup: Oferta/Fase · Tipo · Preço · Vendas ·
    Especialista. ⚠️ **Tipo SEM percentual de comissão** (decisão 4). A coluna Especialista só
    aparece se houver o dado; não há hoje, então **não inventar avatar**.
  - **Biblioteca de criativos**: grade com as imagens que o kit realmente tem
    (`criativos.length`), não 8 fixas. O selo "Reutilizável na Fase 2" **pode** ficar — é
    verdade (o clone copia as fotos). ⚠️ **Nada de "R$ 3,40"**: é preço do kit de 7, extinto.
  - **Rodapé "Pronto para a Fase 2"**: reusa o botão que **já existe** e abre o painel Criar
    Fase N. ⚠️ **Não criar segundo caminho** para a mesma ação.

  ⚠️ **MANTER, sob pena de regressão** — a tela está em produção desde hoje: os 6 blocos da §3,
  o botão "Criar Fase N" com o motivo quando travado, o **"Usar estoque calculado"** (quick
  `261009-uec`), o histórico, a lateral de Mapeamento e o 404 cross-conta.
  ⚠️ **Rollup:** flag booleana dentro de `.map()` é eliminada no bundle — computar **dentro** do
  callback (você vai mapear fases, ofertas e criativos).
  ⚠️ **Tela preta:** render real com cada campo novo como objeto, nulo e ausente.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-detalhe-produto-v2.test.js tests/js/publicador-produto-render.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Build verde e `Produto.jsx` no manifest (resolver pelo JSON — o hash pode ter hífen).</done>
</task>

</tasks>

<verification>
1. `git diff --stat -- app routes database` → **vazio** (é só frontend).
2. Baseline JS: **1851 testes / 1849 pass / 2 fail**; as 2 pré-existentes continuam 2.
3. `npm run build` verde, `Produto.jsx` no manifest.
4. `grep -c "bg-ecf-yellow[^/]"` nos dois arquivos → **0**.
5. `grep -c "3,40\|R\$ 3,40" PainelDoProduto.jsx` → **0** (o custo do kit extinto).
6. Nenhum percentual de comissão (`16%`, `12%`) na tabela de ofertas.
</verification>

<success_criteria>
- A estrutura do mockup inteira, com os dados que existem.
- Nenhum número financeiro inventado; os quadros vazios dizem o motivo.
- A grade de criativos reflete o kit real, não 8 imagens.
- Nada que a Etapa 3 entregou hoje deixou de funcionar.
- Comparação com o `screen.png` item a item na SUMMARY, com o porquê de cada diferença.
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261009-t04-detalhe-do-produto-v2/SUMMARY.md` when done
</output>
