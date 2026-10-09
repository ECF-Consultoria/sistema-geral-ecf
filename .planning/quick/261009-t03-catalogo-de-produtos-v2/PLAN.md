---
tipo: quick
slug: t03-catalogo-de-produtos-v2
data: 2026-10-09
origem: stitch_ecf_marketplace_publisher_redesign/03_cat_logo_de_produtos_modern_minimalist/
files_modified:
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - resources/js/Components/Mlb/Publicador/PaginacaoDaLista.jsx
  - tests/js/publicador-produtos-layout.test.js
  - tests/js/publicador-produtos-fases.test.js
autonomous: true
---

<objective>
Fechar a **tela 03 — Catálogo de Produtos**, última das 4 do pacote do Stitch.

⚠️ **Esta é a mais barata das quatro, e de longe.** A tela foi reconstruída hoje pelo quick
`261009-prd` (layout v2) e **já tem** praticamente tudo o que o mockup pede: filtros
segmentados com contadores, dropdown "Fase: Todas", Confortável/Compacto, "Editar em grade",
faixa de sugestão de kits com Revisar/×, iniciais no lugar da miniatura, as colunas
Produto&SKU / Fase / Situação com ordenação / Anúncios / Atualizado, e seleção por checkbox.

**Faltam exatamente duas coisas:** paginação e os três cards de rodapé.

⚠️ **Só frontend:** `git diff --stat -- app routes database` tem de sair vazio.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**Medido antes deste plano:** `Produtos.jsx` **não tem paginação nenhuma** (as 3 ocorrências de
"página" no arquivo são comentários), e o servidor **manda a lista inteira** — não há `paginate`
nem `limit` em `ProgramasPublicadorService`. Logo a paginação é **do cliente**, como a ordenação
que já existe.

**Funções puras já exportadas de `Produtos.jsx`** (do layout v2, de hoje) — reaproveitar:
`montarLinhas`, `faseDaQuerystring`, `sugestaoSegura`, `destinoDoProduto`, `ordenarTopo`,
`acaoPrincipal`, `densidadeInicial`, `iniciaisDoNome`, `colunasDaLargura`, `alturaDaLinha`,
`tamanhoDaMiniatura`, `miniaturasVisiveis`.
⚠️ As **quatro primeiras** não podem mudar (gate do layout v2).

**O que o mockup mostra no rodapé da tabela:**
`Exibindo 1-7 de 22 produtos cadastrados · 19 rascunhos · 3 publicados no Meli` ·
`Linhas por página: 10` · `|< < 1 2 3 > >|`

**Os 3 cards abaixo da tabela, no mockup:** "Sincronização Contínua ERP Bling" ·
"Fase 2 Automatizada & Kits" · "Validação Pré-Publicação IA".
</interfaces>
</context>

<decisoes_do_usuario>
1. ⚠️ **O card "Sincronização Contínua ERP Bling" do mockup afirma fato FALSO.** O texto dele é
   *"Modificações de estoque físico são refletidas em tempo real em todas as ofertas vinculadas
   da conta Mercado Livre"* — **não existe integração com ERP nenhum**, e a decisão 8 do handoff
   proíbe afirmar sincronização. Mesmo tratamento das telas 01, 02 e 04: o card entra, com texto
   honesto sobre o que o sistema **de fato** faz (o Sincronizar do **Portal**, que existe), ou
   dizendo que a integração com ERP ainda não existe. **Nunca prometer tempo real.**
2. **Os outros dois cards podem ficar praticamente como estão** — "Fase 2 automatizada & kits" e
   "Validação pré-publicação" descrevem o que a Etapa 3 e a conferência realmente fazem. Rever
   só o jargão.
3. **Paginação é do cliente** (o servidor manda tudo). Padrão de 10 linhas por página, no
   seletor do mockup.
4. **Barra lateral, ⌘K e pílula de contexto continuam fora** — igual às outras três telas.
</decisoes_do_usuario>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: `PaginacaoDaLista.jsx`</name>
  <files>resources/js/Components/Mlb/Publicador/PaginacaoDaLista.jsx, tests/js/publicador-produtos-layout.test.js</files>
  <action>
  Componente novo com o rodapé de paginação do mockup: a frase de resumo à esquerda, o seletor
  "Linhas por página" no meio e os controles à direita (primeira · anterior · números · próxima
  · última).

  ⚠️ **A paginação é sobre LINHAS DE TOPO, não sobre linhas da tabela.** Os kits aparecem
  recuados **sob o seu base** (`recuado` de `montarLinhas`): paginar as linhas cruas poria um
  base na página 1 e o kit dele na página 2, quebrando a leitura. Pagine os **bases** e leve os
  kits junto — mesmo princípio que o `ordenarTopo` já usa.

  Exportar as funções puras: `paginar(linhasDeTopo, pagina, porPagina)` e
  `paginasVisiveis(pagina, total)` (a janela com reticências do mockup). ⚠️ **Estáveis e
  defensivas:** página fora do intervalo cai na válida mais próxima; `porPagina` fora da
  whitelist cai no padrão; lista vazia devolve 1 página, nunca 0.

  Render real: rodapé com 1 página (controles desabilitados), com muitas páginas (reticências),
  lista vazia, e valores adversos (`pagina` como objeto, `porPagina` string). ⚠️ `/disabled=/`,
  nunca `/disabled/`. ⚠️ **Montar TODOS os bundles ANTES do primeiro `test()`** — o `after()` do
  `node --test` já matou um arquivo inteiro assim esta semana.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produtos-layout.test.js</automated>
  </verify>
  <done>As funções puras cobertas e o render real verde nos casos adversos.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: ligar a paginação e os 3 cards</name>
  <files>resources/js/Pages/Mlb/Publicador/Produtos.jsx, tests/js/publicador-produtos-fases.test.js</files>
  <action>
  - Ligar a paginação na tela, **depois** do filtro, da busca e da ordenação — a ordem importa:
    quem filtra espera ver a página 1 do resultado filtrado.
  - ⚠️ **Mudar filtro, busca, fase ou ordenação volta para a página 1.** Ficar na página 7 de um
    resultado que agora tem 2 páginas é o beco sem saída clássico.
  - A frase de resumo usa as contagens que a tela **já tem** (`contagens`): total, rascunhos e
    publicados. ⚠️ Não recalcular nada que o servidor já mandou.
  - "Linhas por página" persistido em `localStorage`, no padrão da densidade
    (chave `publicador.produtos.linhas`), **com `try/catch`** — em janela privada o acessor lança.
  - Os **3 cards** abaixo da tabela, com o primeiro honesto (decisão 1).

  ⚠️ **MANTER, sob pena de regressão** — a tela está em produção desde hoje: filtros, busca,
  dropdown de fase, ordenação, densidade, seleção em lote, faixa de sugestões, painel lateral,
  menu ⋯, kits recuados, polling de 5s, realce após Sincronizar, os 3 estados de vazio,
  "Limpar busca e filtros", os rodapés do assistente antigo e `?filtro=`/`?fase=`.
  ⚠️ **Armadilha do Rollup:** flag booleana dentro de `.map()` é eliminada no bundle — computar
  **dentro** do callback (você vai mapear páginas e cards).
  ⚠️ **As 4 funções puras do layout v2 não mudam** (`montarLinhas`, `faseDaQuerystring`,
  `sugestaoSegura`, `destinoDoProduto`) — há gate.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-produtos-fases.test.js tests/js/publicador-produtos-layout.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Build verde e `Produtos.jsx` no manifest (resolver pelo JSON — o hash pode ter hífen).</done>
</task>

</tasks>

<verification>
1. `git diff --stat -- app routes database` → **vazio**.
2. Baseline JS: **1880 testes / 1878 pass / 2 fail**; as 2 pré-existentes continuam 2.
3. `npm run build` verde, página no manifest.
4. `grep -c "bg-ecf-yellow[^/]"` nos arquivos tocados → **0**.
5. **Nenhum texto prometendo sincronização de ERP em tempo real.**
6. Teste provando que um base e seus kits **nunca** caem em páginas diferentes.
7. Teste provando que mudar filtro/busca/ordenação volta para a página 1.
</verification>

<success_criteria>
- A tela 03 fecha o pacote: paginação e rodapé como no mockup, com o card do ERP honesto.
- Nada do layout v2 (de hoje) deixou de funcionar.
- Comparação com o `screen.png` item a item na SUMMARY, com o porquê de cada diferença.
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261009-t03-catalogo-de-produtos-v2/SUMMARY.md` when done
</output>
