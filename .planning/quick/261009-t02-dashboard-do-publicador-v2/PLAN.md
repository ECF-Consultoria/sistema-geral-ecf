---
tipo: quick
slug: t02-dashboard-do-publicador-v2
data: 2026-10-09
origem: stitch_ecf_marketplace_publisher_redesign/02_dashboard_do_publicador_modern_minimalist/
files_modified:
  - app/Services/Publicador/PainelVisaoGeralService.php
  - app/Http/Controllers/MlbPublicadorEntradaController.php
  - resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx
  - resources/js/Components/Mlb/Publicador/CartaoKpi.jsx
  - tests/Feature/Publicador/VisaoGeralTest.php
  - tests/js/publicador-dashboard-v2.test.js
autonomous: true
---

<objective>
Redesign da **tela 02 — Dashboard do Publicador** (a Visão geral da conta), a partir do mockup
`stitch_ecf_marketplace_publisher_redesign/02_dashboard_do_publicador_modern_minimalist/`.
Segunda das 4 telas do pacote.

A conferência contra o código (feita antes deste plano) mostrou que **4 dos 5 KPIs do mockup já
existem** e que os "Alertas Meli & ERP" dele são quase exatamente a triagem que já temos. O
trabalho é, em grande parte, **reorganizar no layout novo**, não inventar dado.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
**Tokens:** os do projeto (decisão do usuário). `ecf-bg`, `ecf-card`, `ecf-card-2`, `ecf-line`,
`ecf-yellow #ffe600` (idêntico ao do mockup). ⚠️ **Botão primário é o amarelo TRANSLÚCIDO**
(`border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow`), nunca o sólido — gate em
`tests/js/publicador-entrada.test.js:53`.

**O que o painel JÁ recebe** (`PainelVisaoGeral.jsx`, L158-169):
`empresa, indicadores, oQueFazerAgora, situacaoProdutos, produtosPorFase, ultimasPublicacoes,
integracoes, identidadeResumo, quemPublicou, abas`
Blocos que já renderiza: O que fazer agora · Situação dos produtos · Produtos por fase ·
Últimas publicações · Integrações · Identidade visual · Quem publicou.

**`indicadores` hoje** (`PainelVisaoGeralService::indicadores()`, ~L205-215):
`no_ar, com_venda, sem_oferta, publicados_30d, publicados_30d_pessoas, acervo_disponivel,
nunca_coletado`

**A triagem** (`AcervoTriagemService`, L70-74) — são os "Alertas" do mockup com outro nome:
`Pausado` (red) · `Sem estoque` (red) · `Ficha incompleta` (amber) · `Perdendo catálogo` (amber)
· `Foto insuficiente` (amber). Já chega ao controller como `$triagem`.
</interfaces>
</context>

<o_mapeamento_dos_5_kpis>
| KPI do mockup | Fonte |
|---|---|
| **Total Publicados** "342 · 218 Fase 1 · 124 Fase 2" | `no_ar` ✓ + divisão por fase (coluna `fase`, existe desde a 175-01) |
| **Aguardando Ação** "18 · 6 sem Fase 1 · 12 aptos Fase 2" | `sem_oferta` ✓ + "Prontos para a Fase 2" ✓, os dois já no `oQueFazerAgora` |
| **Criativos & IA** "48 packs" | `ml_anuncio_criativo_kits` tem `company_id` ✓ |
| **Tração (30D)** "284 · 83%" | é o `com_venda`; os 83% são `com_venda ÷ no_ar` ✓ |
| **Revisão Humana** "5 · Fiscal & ficha técnica" | ❌ **não existe** |
</o_mapeamento_dos_5_kpis>

<decisoes_do_usuario>
1. **Entregar com dado verdadeiro:** os 4 KPIs reais, os alertas vindos da triagem, a fila de
   ação, a barra com/sem vendas, os cards de Criativos e Identidade, e o **"Quem publicou"** no
   lugar do "Atividade da Equipe" do mockup (é o que o sistema sabe: quem publicou o quê e
   quando — não "gerou 5 imagens IA" nem "revisão aprovada").
2. **Desenhar vazio, com estado honesto**, o que não existe: **Revisão Humana**, **estoque do
   ERP** na fila de ação e **reputação "Conta Líder Platinum"**. O widget nasce no lugar certo e
   acende sozinho quando o dado existir. ⚠️ **Nunca** inventar número nem carimbo de tempo.
3. **O sparkline de conversão diária e o seletor Hoje/7 dias/Este mês ficam FORA desta tarefa.**
   A tabela `ml_acervo_metricas_diarias` **existe** (série diária por anúncio), então isso é
   factível com dado real — e é justamente por isso que merece tarefa própria, em vez de um
   gráfico de mentira agora. Registrar como pendência.
4. **"Nova Publicação Direta"** do mockup: **desabilitado com "Em breve"**, mesmo tratamento que
   o "Conectar nova empresa" da tela 01 — não há fluxo de publicação direta a partir do painel.
5. **Barra lateral "MÓDULO PUBLICAÇÃO" e busca ⌘K ficam fora** (superada pela Etapa 1 e tarefa
   transversal, respectivamente) — igual à tela 01.
</decisoes_do_usuario>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: os dados novos do painel (servidor)</name>
  <files>app/Services/Publicador/PainelVisaoGeralService.php, app/Http/Controllers/MlbPublicadorEntradaController.php, tests/Feature/Publicador/VisaoGeralTest.php</files>
  <action>
  Acrescentar, **tudo aditivo** (nenhuma chave existente muda de nome, ordem ou valor):

  - **`indicadores.no_ar_por_fase`** — `['fase1' => N, 'kits' => N]`, a partir de
    `pub_produtos.fase`/`quantidade_kit` dos produtos da conta que têm anúncio no ar. Sustenta o
    "218 Fase 1 · 124 Fase 2" do mockup. ⚠️ Rotular **"kits"**, não "Fase 2": `quantidade_kit >= 2`
    inclui o kit de 3, que é Fase 3 — foi a mesma correção da tela 01.
  - **`indicadores.criativos_packs`** — quantos `ml_anuncio_criativo_kits` da conta. ⚠️ **Não**
    inventar "reaproveitados": o acervo existe mas não conta reuso.
  - **`indicadores.tracao_pct`** — `com_venda ÷ no_ar` arredondado, **nulo** quando `no_ar` é 0 ou
    o acervo não está disponível. ⚠️ Nunca 0% quando o que houve foi "não coletado" — é a mesma
    armadilha de "dia sem linha ≠ venda zero" que já custou caro neste projeto.
  - **`alertas`** — a triagem que o controller já carrega (`$triagem`), no formato que a tela
    consome: lista de `{chave, label, cor, total}` + o total geral. **Reusar
    `AcervoTriagemService`; não reimplementar motivo nenhum** (`motivosTriagemDef()` é a fonte
    única, decisão da Etapa 2).

  Tudo **em lote**, no padrão do serviço. Teste de contagem de consultas.

  Testes (RED antes): a divisão por fase com base+kit; `criativos_packs` com e sem kit;
  `tracao_pct` nulo com `no_ar = 0` **e** nulo com acervo indisponível (não 0%); `alertas`
  espelhando a triagem; e **gate de forma** provando que as chaves antigas de `indicadores`
  continuam todas lá.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/VisaoGeralTest.php</automated>
  </verify>
  <done>As chaves novas chegam ao painel, em lote, sem quebrar nenhuma antiga.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: `CartaoKpi.jsx`</name>
  <files>resources/js/Components/Mlb/Publicador/CartaoKpi.jsx, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  O cartão de KPI do mockup: rótulo em maiúsculas pequenas, número grande, um par de
  sub-números abaixo, ícone à direita e variante de destaque (o "Prioritário" âmbar e o
  "Pendentes" vermelho do mockup).

  ⚠️ **Estado vazio honesto é requisito, não detalhe:** com número nulo o cartão diz o motivo
  ("Sem dado do acervo ainda", "Ainda não medimos") — **nunca 0**. É o que separa "não sabemos"
  de "é zero", distinção que já custou caro neste projeto.

  Teste de **render real** (esbuild + `react-dom/server`): cartão completo; número nulo; número
  como **objeto**; sub-números ausentes; props todas ausentes. Nenhum `[object Object]`.
  ⚠️ Desabilitado se prova com `/disabled=/`, nunca `/disabled/`.
  ⚠️ **Montar TODOS os bundles ANTES do primeiro `test()`** — o `node --test` dispara o `after()`
  quando os testes registrados acabam, e isso matou um arquivo inteiro na tela 01, sem nenhum
  teste falhando e só na suíte completa.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-dashboard-v2.test.js</automated>
  </verify>
  <done>Render real verde em todos os casos adversos.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: o painel</name>
  <files>resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  Reorganizar o painel no layout do mockup: faixa de **5 KPIs** no topo (o quinto, Revisão
  Humana, vazio e honesto); coluna principal com a **fila de ação** (o `oQueFazerAgora` que já
  existe, apresentado como a lista do mockup) e o **Desempenho Rápido** (barra com/sem vendas, a
  partir de `com_venda`/`no_ar`); coluna lateral com **Alertas** (a triagem), **Quem publicou** e
  os dois cards de atalho (Criativos e Identidade).

  "Nova Publicação Direta" desabilitado com "Em breve" (decisão 4).

  ⚠️ **MANTER, sob pena de regressão** — o painel está em produção desde 08/10: O que fazer
  agora (as 8 linhas e seus destinos), Situação dos produtos, Produtos por fase, Últimas
  publicações, Integrações, Identidade visual, Quem publicou, e o botão de sincronizar com o
  tratamento de erro. **Podem mudar de lugar e de forma; não de efeito.**
  ⚠️ **Armadilha do Rollup:** flag booleana dentro de `.map()` é eliminada no bundle de produção
  — computar **dentro** do callback. Você vai mapear KPIs, alertas, a fila e as publicações.
  ⚠️ **A tela preta (07/10):** render real com cada campo novo **como objeto, nulo e ausente**,
  mais `indicadores: null` e todas as props ausentes.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && node --test tests/js/publicador-dashboard-v2.test.js && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Build verde e `VisaoGeral.jsx` no manifest (resolver pelo JSON — o hash pode ter hífen).</done>
</task>

</tasks>

<verification>
1. Baseline PHP `tests/{Feature,Unit}/Publicador`: **1382** verdes; sem falha nova.
2. Baseline JS: **1813 testes / 1811 pass / 2 fail**; as 2 pré-existentes continuam 2.
3. `npm run build` verde, página no manifest.
4. `grep -c "bg-ecf-yellow[^/]"` no painel → **0**.
5. Nenhum texto afirmando estoque de ERP, reputação ou revisão humana que não existam.
6. `git diff --stat -- database` → vazio.
</verification>

<success_criteria>
- Os 4 KPIs reais com número verdadeiro; o quinto vazio e honesto.
- Os alertas saem da triagem, sem motivo reimplementado.
- Nada que o painel fazia desde 08/10 deixou de funcionar.
- Comparação com o `screen.png` item a item na SUMMARY, dizendo o que ficou diferente **e por quê**.
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261009-t02-dashboard-do-publicador-v2/SUMMARY.md` when done
</output>
