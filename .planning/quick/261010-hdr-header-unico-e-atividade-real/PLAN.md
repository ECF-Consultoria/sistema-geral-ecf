---
tipo: quick
slug: hdr-header-unico-e-atividade-real
data: 2026-10-10
files_modified:
  - app/Services/Publicador/PainelVisaoGeralService.php
  - app/Http/Controllers/MlbPublicadorEntradaController.php
  - resources/js/Components/Mlb/Publicador/BarraDaConta.jsx
  - resources/js/Components/Mlb/Publicador/AbasDaConta.jsx
  - resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx
  - tests/Feature/Publicador/VisaoGeralTest.php
  - tests/js/publicador-dashboard-v2.test.js
  - tests/js/publicador-entrada.test.js
autonomous: false
---

<objective>
Três correções que o usuário pediu depois de ver a tela 02 em produção (10/10):

1. **O cabeçalho ficou duplicado.** A `BarraDaConta` (nome, chave, selos, trocar empresa) e o
   cabeçalho novo da tela 02 dizem a mesma coisa, um em cima do outro. Palavras dele:
   *"Esse header antigo não precisa ter, é redundante, tem o header novo embaixo."*
   ⚠️ **Mas a navegação tem de sobreviver:** *"nesse header existem os itens de navegação, esses
   itens quero que entrem no header novo, mas de uma forma moderna que fique bonita no layout,
   me surpreenda."*
2. **As fontes estão pequenas** — *"principalmente as escritas principais, como nome da loja."*
3. ⚠️ **A "Atividade da equipe" está mockada e NÃO devia estar.** Palavras dele: *"você colocou
   dados mockados sendo que já existem dados dinâmicos para esse widget."* **Ele está certo** —
   conferido: `MlAnuncioCriativoKit` tem `user_id` e data (quem gerou criativo),
   `PubValidacao` tem o resultado da conferência e `PubPublicacao` tem `ator` + `iniciada_em`.
   Eu mockei o que já dava para buscar.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
<interfaces>
⚠️ **`BarraDaConta` e `AbasDaConta` são usadas por NOVE páginas**, não só pela Visão geral:
`Mlb/AnunciarMassa`, `Mlb/AnunciarML`, `Mlb/AnunciosHistorico`, `Mlb/MeusAnuncios`,
`Mlb/Publicador/{Alavancas, Configuracoes, Produto, Produtos, VisaoGeral}`.
**Qualquer mudança nelas atinge as nove.** É o que torna esta tarefa maior do que parece — e
também o que faz valer a pena: o cabeçalho fica consistente no módulo inteiro.

**Hoje:** `BarraDaConta` tem trilha (13px), nome (`font-display` 24px bold), chave (mono 11px),
selos e "Trocar empresa"; `AbasDaConta` tem as 5 abas (`h-11`, 15px) e o segmentado de
Publicações. O cabeçalho da tela 02 (quick `261010-t02b`) repete nome, chave e estado da conta,
e acrescenta ERP, catálogo ativo e o seletor de período.

**Dado REAL disponível para a Atividade da equipe** (conferido):
- `PubPublicacao`: `ator` (JSON `{equipe,id,nome}`), `iniciada_em`/`concluida_em` — **já usado**
- `MlAnuncioCriativoKit`: `user_id`, `created_at`, `pub_rascunho_id` → "gerou N imagens para X"
- `PubValidacao`: `resultado`, `revisao`, `rascunho_id` → "conferência aprovada"
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: a Atividade da equipe com dado real</name>
  <files>app/Services/Publicador/PainelVisaoGeralService.php, app/Http/Controllers/MlbPublicadorEntradaController.php, tests/Feature/Publicador/VisaoGeralTest.php</files>
  <action>
  Método novo `atividadeDaEquipe(array $alvo, int $limite = 8): array`, juntando **três fontes
  reais** numa linha do tempo única, ordenada por data desc:

  - **Publicou** — de `pub_publicacoes` (`ator`, `concluida_em`), com o produto e a fase. Já
    existe em `publicadosRecentes()`: **reaproveitar, não reimplementar**.
  - **Gerou criativos** — de `ml_anuncio_criativo_kits` (`user_id`, `created_at`), com quantas
    imagens e para qual produto.
  - **Conferiu** — de `pub_validacoes` (`resultado`, `rascunho_id`), a conferência que passou.

  Shape por item: `{tipo, quem, quando_iso, titulo, detalhe}` — **escalares**, nunca objeto
  aninhado que a tela renderize cru (a tela preta de 07/10 nasceu disso).

  ⚠️ **Em lote**, no padrão do serviço: um `whereIn` por fonte, nada de N+1. Teste contando
  consultas.
  ⚠️ **`ator.id` nem sempre é usuário do sistema** (quando `equipe` é falso, é cliente pelo
  portal) e as linhas migradas do assistente antigo não têm `id` nenhum — tratar os dois sem
  inventar nome. É a mesma armadilha que a Etapa 2 já documentou.
  ⚠️ Sem Company ou sem nenhuma das três fontes ⇒ lista vazia e `disponivel: false` — a tela
  decide o que mostrar.

  Testes (RED antes): os três tipos aparecem; a ordem é por data desc; ator sem `id` não vira
  "undefined"; limite respeitado; e o gate de contagem de consultas.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && C:/xampp/php/php.exe artisan test tests/Feature/Publicador/VisaoGeralTest.php</automated>
  </verify>
  <done>A Atividade da equipe passa a ter dado real das três fontes, em lote.</done>
</task>

<task type="checkpoint:decision" gate="blocking">
  <name>Task 2: desenho do cabeçalho único — mostrar ao usuário antes de implementar</name>
  <action>
  ⚠️ **PARE e mostre ao usuário duas ou três propostas de layout do cabeçalho único**, em ASCII
  ou descrição curta, antes de escrever JSX. Ele pediu *"de uma forma moderna que fique bonita
  no layout, me surpreenda"* — e isso é escolha visual dele, não minha.

  O cabeçalho único precisa, obrigatoriamente, carregar: identidade da conta (iniciais, nome,
  chave), estado do Mercado Livre, ERP, catálogo ativo, "Trocar empresa", **as 5 abas**
  (Visão geral · Produtos · Publicações · Alavancas · Configurações), o slot de ações por tela
  e o seletor de período (só na Visão geral).

  ⚠️ Lembrar ao usuário na hora de escolher: o cabeçalho vale para **9 páginas**, inclusive as
  do assistente antigo, e algumas **não têm** Company (abas desabilitadas com explicação, D23).
  </action>
  <done>O usuário escolheu o desenho.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: implementar o cabeçalho único e subir as fontes</name>
  <files>resources/js/Components/Mlb/Publicador/BarraDaConta.jsx, resources/js/Components/Mlb/Publicador/AbasDaConta.jsx, resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx, tests/js/publicador-entrada.test.js, tests/js/publicador-dashboard-v2.test.js</files>
  <action>
  Implementar o desenho escolhido na Task 2, fundindo o cabeçalho da tela 02 com a
  `BarraDaConta`/`AbasDaConta` — **uma identidade da conta só na tela**.

  **Tipografia (pedido 2):** subir as fontes principais. Nome da conta de **24px para 30px**;
  a trilha e os rótulos de apoio um degrau acima do que estão; as abas de 15px para 16px.
  ⚠️ Manter a escala coerente com o resto do módulo — subir tudo proporcionalmente, não só um
  número solto. O gate de design (`publicador-entrada.test.js`) confere tamanhos: **atualize-o**
  para a escala nova, não o afrouxe.

  ⚠️ **MANTER, sob pena de regressão nas 9 páginas:** trilha, "Trocar empresa", `SeloConta`,
  `SeloPortal`, `AvisoContaTravada`, o slot de ações, as 5 abas com os destinos corretos, o
  segmentado No ar/Histórico de Publicações, a contagem de produtos na aba, e **as abas
  desabilitadas com explicação quando não há Company** (D23).
  ⚠️ **Rollup:** flag booleana dentro de `.map()` é eliminada no bundle — computar dentro do
  callback (você vai mapear abas e selos).
  ⚠️ Render real com props nulas e campos como objeto.
  </action>
  <verify>
    <automated>cd /c/xampp/htdocs/ecf_admin/ecf_admin && npm run test:js 2>&1 | tail -5 && npm run build 2>&1 | tail -3</automated>
  </verify>
  <done>Um cabeçalho só, fontes maiores, nada quebrado nas 9 páginas.</done>
</task>

</tasks>

<verification>
1. Nenhuma das 9 páginas perdeu trilha, selos, "Trocar empresa", abas ou slot de ações.
2. A Visão geral mostra a identidade da conta **uma vez só**.
3. "Atividade da equipe" **sem pilha de exemplo** — é dado real agora.
4. Baseline JS: **1989 testes / 1988 pass / 1 fail**; a falha continua sendo só a `estrutura-grade-glide`.
5. Baseline PHP `tests/{Feature,Unit}/Publicador`: sem falha nova.
6. `npm run build` verde; as páginas no manifest.
7. `grep -c "bg-ecf-yellow[^/]"` nos arquivos tocados → **0**.
</verification>

<success_criteria>
- Um cabeçalho só, com a navegação dentro dele, no desenho que o usuário escolher.
- Fontes principais maiores, com a escala coerente.
- A Atividade da equipe com dado real das três fontes — e a pilha "exemplo" **sai** dela.
  ⚠️ **Não afirmar ter visto a tela renderizada** — a conferência visual é do usuário.
</success_criteria>

<output>
Create `.planning/quick/261010-hdr-header-unico-e-atividade-real/SUMMARY.md` when done
</output>
