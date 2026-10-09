---
tipo: quick
slug: t02b-dashboard-fiel-ao-stitch
data: 2026-10-10
origem: stitch_ecf_marketplace_publisher_redesign/02_dashboard_do_publicador_modern_minimalist/
files_modified:
  - resources/js/Components/Mlb/Publicador/dadosDeExemplo.js
  - resources/js/Components/Mlb/Publicador/SeloExemplo.jsx
  - resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx
  - tests/js/publicador-dashboard-v2.test.js
autonomous: true
---

<objective>
Completar o **main/content da tela 02 (Dashboard do Publicador)** para que fique **como o
mockup do Stitch desenhou** — e não só com os blocos cujo dado existe.

⚠️ **MUDANÇA DE RÉGUA, decidida pelo usuário em 2026-10-10.** Até aqui eu vinha deixando
**vazio** todo widget sem dado real. O usuário olhou o resultado e disse, com estas palavras:

> *"a parte do main/content era para [ser] exatamente como está no layout do Stitch — se tiver
> dados dinâmicos da conta use os dados dinâmicos, se não **use mockados**"*

Ele já havia dito isso no começo do pacote (*"faça fielmente os widgets que estão lá porque no
futuro tudo será funcional"*) e eu segurei demais. **A régua agora é: dado real quando existe,
dado de exemplo quando não existe — nunca um quadro vazio.**

⚠️ **O que NÃO entra:** a barra lateral "MÓDULO PUBLICAÇÃO" do mockup. O usuário foi explícito:
*"não concordo, e além disso não tem espaço"* — a barra lateral do sistema fica como está.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**O que a tela JÁ tem de real** (quick `261009-t02`, em produção): 6 KPIs (No ar, Aguardando
ação, Publicados 30 dias, Com venda, Criativos por IA, Revisão humana), "O que fazer agora",
Alertas do acervo, Quem publicou, Situação dos produtos, Produtos por fase, Últimas publicações,
Integrações, Identidade visual.

**Props do painel:** `empresa, indicadores, oQueFazerAgora, alertas, situacaoProdutos,
produtosPorFase, ultimasPublicacoes, integracoes, identidadeResumo, quemPublicou, abas`.

**Tokens:** os do projeto. ⚠️ Botão primário é o amarelo **translúcido** — gate
`publicador-entrada.test.js:53`. Isso **não muda** (é gate, não escolha de layout).
</interfaces>
</context>

<decisao_do_usuario_sobre_o_dado_de_exemplo>
**Marca discreta nos blocos mockados** (escolhido entre três opções, em 10/10):

> Uma pilha pequena **"exemplo"** no canto do widget que usa dado fictício. O layout fica igual
> ao Stitch; quem olhar de perto sabe o que ainda não é real. Some sozinha quando o dado passar
> a existir.

Regras:
- Bloco com dado **real** NÃO leva a pilha.
- Bloco com dado de **exemplo** leva a pilha, **uma por bloco** (não por número).
- ⚠️ **Nunca misturar** real e exemplo dentro do mesmo número. Um KPI é inteiro real ou inteiro
  exemplo.
</decisao_do_usuario_sobre_o_dado_de_exemplo>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: a convenção do dado de exemplo</name>
  <files>resources/js/Components/Mlb/Publicador/dadosDeExemplo.js, resources/js/Components/Mlb/Publicador/SeloExemplo.jsx, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  **`dadosDeExemplo.js`** — o **único** lugar onde vive todo valor fictício do módulo. Nome
  explícito de propósito: quem abrir o arquivo entende em um segundo que nada ali é da conta, e
  apagar o arquivo um dia mostra exatamente o que ainda era mentira.

  Exporta constantes nomeadas pelo bloco que alimentam, com os valores do `screen.png`
  (ex.: `ERP_EXEMPLO`, `TRACAO_EXEMPLO`, `ATIVIDADE_EXEMPLO`, `ALERTAS_ML_EXEMPLO`,
  `CONVERSAO_EXEMPLO`). ⚠️ **Nenhuma função, nenhuma lógica** — só dados. E um comentário de
  topo dizendo o que é e quando sai.

  **`SeloExemplo.jsx`** — a pilha discreta, no vocabulário do módulo (11px, `uppercase`,
  `tracking-[0.05em]`, borda e fundo translúcidos), com `title` explicando que o bloco mostra
  dado de exemplo até a integração existir.

  Testes: a pilha renderiza; o `title` existe; e um **gate** provando que `dadosDeExemplo.js`
  não importa nada e não exporta função (só constantes) — é o que impede o arquivo de virar
  lógica disfarçada.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-dashboard-v2.test.js</automated>
  </verify>
  <done>A convenção existe num lugar só, e o gate prova que é só dado.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: o cabeçalho da conta</name>
  <files>resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  O cabeçalho do mockup, acima dos KPIs:
  - Nome da conta + CNPJ — **real** (`empresa`).
  - Selo "Conta Líder Platinum" — **exemplo**.
  - "Mercado Livre: Conectado" — **real** (`integracoes`); o "(Platinum 100%)" é **exemplo**.
  - "ERP Bling: Sincronizado há 8 min" — **exemplo** (o ERP declarado é real, o "sincronizado
    há 8 min" não).
  - "Catálogo SKU ativo: 1.420" — **real** quando houver contagem de produtos; senão exemplo.
  - Seletor **Hoje / Últimos 7 dias / Este mês** — a tela não tem janela de período, então o
    seletor é **visual** (marca o escolhido, não refiltra). ⚠️ Com a pilha de exemplo e `title`
    dizendo que o recorte por período ainda não está ligado — **não** fingir que filtra.
  - Botão "Nova Publicação Direta" — continua **desabilitado com "Em breve"** (não existe fluxo).

  ⚠️ Cada bloco leva a pilha **só** se o dado dele for de exemplo.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-dashboard-v2.test.js</automated>
  </verify>
  <done>Cabeçalho igual ao mockup, com real e exemplo distinguíveis.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: coluna direita e o card de Desempenho</name>
  <files>resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  **Coluna da direita, na ordem do mockup:**
  - **"Alertas Meli & ERP"** com o contador de ações. Os itens do acervo que já temos são
    **reais**; os dois do mockup que não existem ("Atributo Obrigatório Pendente #MLB-392019",
    "Estoque Baixo no Bling", com os botões "Corrigir Atributo" / "Pausar Anúncios") entram
    como **exemplo**. ⚠️ Botão de exemplo **não navega** e diz isso no `title`.
  - **"Atividade da Equipe · Tempo real"** — o feed do mockup (publicou X, gerou 5 imagens IA,
    revisão aprovada). O "Quem publicou" que temos é **real**: aproveite-o como as primeiras
    linhas e complete com **exemplo** o que não existe. A pilha fica no bloco.
  - **Cards "Criativos" e "Identidade"** no formato do mockup (par, com seta), usando os números
    reais que já temos.

  **O card "Desempenho Rápido de Publicações"**, abaixo da fila:
  - Barra com/sem venda e "Tração Geral: 83%" — **real** (`com_venda ÷ no_ar`).
  - **"Alavanca Recomendada — Otimizar N anúncios dormentes"** com o texto do mockup e o botão
    **"Reotimizar com IA"** — o número de anúncios sem venda é **real**; a recomendação e o
    botão são **exemplo** (não existe reotimização por IA). Botão não navega.
  - **Sparkline "Ritmo de conversão diária (últimos 14 dias)"** — **exemplo**. ⚠️ A série
    `ml_acervo_metricas_diarias` existe no banco, mas ninguém a lê ainda: deixe comentado no
    código que o caminho real é essa tabela, para a troca ser trivial depois.

  ⚠️ **MANTER** tudo o que a tela já faz: os 6 KPIs, "O que fazer agora" com os destinos,
  Situação dos produtos, Produtos por fase, Últimas publicações, Integrações, Identidade visual,
  e o botão de sincronizar com o tratamento de erro.
  ⚠️ **Rollup:** flag booleana dentro de `.map()` é eliminada no bundle — computar **dentro** do
  callback (você vai mapear alertas, atividade e os pontos do sparkline).
  ⚠️ **Tela preta:** render real com cada campo novo como objeto, nulo e ausente.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-dashboard-v2.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>O main/content bate com o `screen.png`; build verde e `VisaoGeral.jsx` no manifest.</done>
</task>

</tasks>

<verification>
1. `git diff --stat -- app routes database` → **vazio** (é só frontend).
2. Baseline JS: **1961 testes / 1960 pass / 1 fail** (a `estrutura-grade-glide`, pré-existente).
3. `npm run build` verde, página no manifest (resolver pelo JSON — o hash pode ter hífen).
4. `grep -c "bg-ecf-yellow[^/]"` nos arquivos tocados → **0**.
5. Todo valor fictício vem de `dadosDeExemplo.js` — **nenhum número inventado solto no JSX**.
6. Todo bloco que usa `dadosDeExemplo` tem `SeloExemplo`; nenhum bloco de dado real tem.
</verification>

<success_criteria>
- O main/content da tela 02 fica **como o mockup**, sem quadro vazio.
- Real e exemplo são distinguíveis à vista, sem poluir o layout.
- Nada que a tela fazia deixou de funcionar.
- Na SUMMARY, a lista do que é **real** e do que é **exemplo**, bloco a bloco.
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261010-t02b-dashboard-fiel-ao-stitch/SUMMARY.md` when done
</output>
