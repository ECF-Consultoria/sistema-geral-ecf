---
tipo: quick
slug: t01-selecao-de-empresas-v2
data: 2026-10-09
origem: stitch_ecf_marketplace_publisher_redesign/01_sele_o_de_empresas_modern_minimalist/
files_modified:
  - app/Services/Publicador/ProgramasPublicadorService.php
  - resources/js/Pages/Mlb/AnunciosEmpresas.jsx
  - resources/js/Components/Mlb/Publicador/CartaoAcessoRapido.jsx
  - tests/Feature/Publicador/ProgramaPublicadorTest.php
  - tests/js/publicador-selecao-empresas-v2.test.js
autonomous: true
---

<objective>
Redesign da **tela 01 — Seleção de Empresas** (`Pages/Mlb/AnunciosEmpresas.jsx`), a partir do
mockup do Stitch em `stitch_ecf_marketplace_publisher_redesign/01_sele_o_de_empresas_modern_minimalist/`
(`screen.png` é a referência visual, `code.html` o detalhe de estrutura).

É a primeira das 4 telas do pacote. **O mockup é referência visual, não código para colar:**
recriar no vocabulário do projeto.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**DECISÃO DO USUÁRIO (09/10): manter os tokens do sistema.** `ecf-bg #050507`,
`ecf-card #0f1116`, `ecf-card-2 #14161d`, `ecf-line rgba(255,255,255,.08)`,
`ecf-yellow #ffe600` (idêntico ao do mockup). ⚠️ **Botão primário é o amarelo TRANSLÚCIDO do
módulo** (`border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow`), **não** o preenchido do
mockup: há gate em `tests/js/publicador-entrada.test.js:53`
(`assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/)`). **Não afrouxar o gate.**

**O que CADA LINHA da lista já traz** (`ProgramasPublicadorService::empresas()`, ~L241-256) —
não existe mais nada hoje:
`chave, tipo, id, nome, identificador (CNPJ ou "CUST n"), company_id, tem_token,
token_expirado, token ('ativo'|'expirado'|'sem_token'), link_reconexao,
portal {situacao, novas, sincronizado_em}, produtos, publicados, prontos, liberada,
publicados_mes`

**O que JÁ EXISTE na tela — reaproveitar, não reconstruir:**
`SeletorPrograma` (as abas Publicação/Polos/Incubadora do topo do mockup),
`IndicadoresDoPrograma` (os contadores), **Recentes** em `localStorage`
(`publicador.recentes.{user_id}`, até 4 — é o "ACESSO RÁPIDO (SESSÃO ATUAL)" do mockup),
**paginação** (já existe), busca, `SeloConta`, `SeloPortal`, `AvisoContaTravada`,
`LinkReconexao`, `BotaoSincronizarPortal`, `PainelComoFunciona`.
Colunas de hoje: `['Empresa', 'Conta ML', 'Portal', 'Produtos', 'Publicados']`.
</interfaces>
</context>

<decisoes_do_usuario>
1. **A coluna Portal FICA.** O mockup a substituiu por "ERP & SYNC", mas Portal é dado real e
   funcionando (situação, ofertas novas, quando sincronizou) e ERP é o que não existe. Trocar
   um pelo outro perderia informação verdadeira. **As duas convivem.**
2. **ERP com texto honesto.** ⚠️ O mockup mostra "Bling · há 14m" e "Sincronizado", mas a
   **decisão 8 do handoff** manda: *"ERP é apenas declarado. Exibir 'Bling · declarado no
   onboarding', nunca 'conectado', até existir integração real."* Um carimbo de "sincronizado
   há 14 min" quando nada nunca sincronizou afirma fato falso sobre a conta do cliente. Segue
   a decisão 8.
3. **"Conectar Nova Empresa" fica desabilitado com "Em breve"** — mesmo vocabulário que o
   próprio mockup usa na barra lateral dele. Há dois destinos possíveis no sistema
   (`comercial.empresas.novo` cadastra a empresa; `implementacao.conectar-ml` conecta o OAuth
   de uma já cadastrada) e o usuário ainda não escolheu. **Não escolher por ele.**
4. **A barra lateral "MÓDULO PUBLICAÇÃO" do mockup está SUPERADA** pela Etapa 1 (Publicador é
   módulo transversal, entra pela barra global, navegação interna são as abas da conta).
   **Não criar barra lateral nova.**
5. **A busca global ⌘K e a pílula de contexto do topo ficam FORA desta tarefa** — são
   transversais às 4 telas e viram trabalho próprio.
</decisoes_do_usuario>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: dois dados reais que faltam na linha (servidor)</name>
  <files>app/Services/Publicador/ProgramasPublicadorService.php, tests/Feature/Publicador/ProgramaPublicadorTest.php</files>
  <action>
  Acrescentar ao payload de cada linha de `empresas()`, **em lote** (nada de N+1 — o método já
  carrega tudo por `whereIn`):

  - **`erp`** — o ERP **declarado** no onboarding, de `MlbImplementacao` (é a fonte que a
    Etapa 2 §4 nomeia). Shape: `['nome' => 'Bling'|null]`. Sem valor ⇒ `nome` nulo, e a tela
    escreve "não informado". ⚠️ **Nunca** um campo de "sincronizado em": ele não existe e a
    decisão 8 proíbe fingir que existe.
  - **`fases`** — agregado por empresa a partir de `pub_produtos` (colunas que existem desde a
    175-01): `['kits' => N]`, quantos produtos da empresa têm `quantidade_kit >= 2`. É o que
    sustenta o "12 Fase 2" do mockup com dado verdadeiro.

  ⚠️ **As duas chaves são ADITIVAS** — nenhuma chave existente muda de nome, ordem ou valor.
  `ProgramaPublicadorTest` tem gate de forma; se ele reprovar por chave nova, **atualize a
  expectativa** (é aditiva), não afrouxe o teste.

  Testes (RED antes): empresa com ERP declarado traz o nome; sem declaração traz `null`;
  empresa com 2 kits traz `kits = 2`; empresa sem kit traz `0`; e **contagem de consultas**
  provando que N empresas não geram N consultas.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/ProgramaPublicadorTest.php</automated>
  </verify>
  <done>As duas chaves chegam à tela, em lote, sem quebrar nenhuma chave antiga.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: os cartões de Acesso Rápido</name>
  <files>resources/js/Components/Mlb/Publicador/CartaoAcessoRapido.jsx, tests/js/publicador-selecao-empresas-v2.test.js</files>
  <action>
  Componente novo para o bloco "ACESSO RÁPIDO (SESSÃO ATUAL)" do mockup: cartão com iniciais da
  empresa, nome, `MLB-xxxx · {ERP} ERP`, **selo de estado da conta** no canto (reusar
  `SeloConta`), a linha `{N} SKUs · {N} Anúncios` e o botão "Acessar".

  Os **Recentes já existem** em `localStorage` — este componente só desenha melhor o que já é
  lido. ⚠️ O item guardado no `localStorage` pode ser antigo e **não ter** os campos novos:
  tudo com fallback, nada pode estourar. ⚠️ Toda leitura em `try/catch` (modo privado).

  Teste de **render real** (esbuild + `react-dom/server`, molde de
  `tests/js/publicador-produtos-fases.test.js`): cartão completo; item sem SKUs; item com
  campos **como objeto**; item nulo; lista vazia. Nenhum `[object Object]` no HTML.
  ⚠️ Prova de desabilitado é `/disabled=/`, nunca `/disabled/`.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-selecao-empresas-v2.test.js</automated>
  </verify>
  <done>Render real verde em todos os casos adversos.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: a tela</name>
  <files>resources/js/Pages/Mlb/AnunciosEmpresas.jsx, tests/js/publicador-selecao-empresas-v2.test.js</files>
  <action>
  Reconstruir o corpo da tela no layout do mockup, **mantendo tudo que já funciona**:

  - **Cabeçalho:** título "Seleção de Empresas" + subtítulo do mockup; contadores à direita;
    botão "Conectar Nova Empresa" **desabilitado com "Em breve"** (decisão 3).
  - **Acesso Rápido:** o `CartaoAcessoRapido` da Task 2, alimentado pelos Recentes.
  - **Barra de filtros** no formato do mockup (busca + controles). ⚠️ Os 4 filtros de hoje
    (`todos`/`prontos`/`atencao`/`nunca`) **continuam existindo e funcionando** — podem mudar de
    forma visual, **não** de efeito. O dropdown "ERP" do mockup **não entra**: não há dado para
    filtrar. Se couber sem inventar, um controle de ordenação do cliente.
  - **Tabela**, agora com 7 colunas: Empresa (nome + 2ª linha `CNPJ · MELI ID`) · Status Meli ·
    **Portal** (fica, decisão 1) · **ERP** (declarado, decisão 2) · Catálogo · Anúncios ·
    Fases/Pendências (`kits` + `prontos`, só o que é real).
  - **Rodapé:** os 3 cards do mockup. Dois podem ser **reais** — quantas contas com token
    expirando e quantos produtos prontos sem pendência; o do ⌘K fica como texto, já que a busca
    global é outra tarefa.

  ⚠️ **Armadilha do Rollup:** flag booleana usada dentro de `.map()` é eliminada no bundle de
  produção (já deu `ReferenceError` nesta base). Computar **dentro** do callback — você vai
  mapear empresas, filtros, colunas e recentes.
  ⚠️ **A tela preta (07/10):** campo que chega como objeto e é renderizado como texto derruba a
  página. Render real com cada campo novo **como objeto, nulo e ausente**, mais `empresas: null`
  e todas as props ausentes.

  **MANTER, sob pena de regressão:** busca, os 4 filtros, paginação, Recentes, `SeletorPrograma`,
  `IndicadoresDoPrograma`, `SeloConta`, `SeloPortal`, `AvisoContaTravada`, `LinkReconexao`,
  `BotaoSincronizarPortal`, `PainelComoFunciona`, o estado vazio e o clique que abre a empresa.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-selecao-empresas-v2.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Build verde e `AnunciosEmpresas.jsx` no manifest (resolver pelo JSON — o hash pode ter hífen ou sublinhado).</done>
</task>

</tasks>

<verification>
1. `npm run test:js` sem falha nova — as 2 pré-existentes continuam 2.
2. Baseline PHP `tests/{Feature,Unit}/Publicador`: **1376** verdes; sem falha nova.
3. `npm run build` verde, página no manifest.
4. `grep -c "bg-ecf-yellow[^/]" resources/js/Pages/Mlb/AnunciosEmpresas.jsx` → **0** (o gate do amarelo).
5. Nenhum texto afirmando ERP sincronizado/conectado em lugar nenhum.
6. `git diff --stat -- database` → vazio.
</verification>

<success_criteria>
- A tela tem a estrutura do mockup com o vocabulário visual do projeto.
- Portal continua; ERP aparece como **declarado**; nada afirma sincronização que não existe.
- Nenhuma função que existia hoje sumiu.
- Comparar o resultado com `screen.png` item a item na SUMMARY, dizendo o que ficou diferente
  **e por quê** (dado que não existe, gate do amarelo, decisão do usuário).
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual final é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261009-t01-selecao-de-empresas-v2/SUMMARY.md` when done
</output>
