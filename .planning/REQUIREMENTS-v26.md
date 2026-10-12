# REQUIREMENTS — Milestone v26.0: Redesign do Publicador de Anúncios

**Origem:** o pacote de design `new_layout_publicador/` (README de 245 linhas + 4 protótipos `.dc.html`)
e a instrução do usuário de 2026-10-11.

**Autoridade:** `new_layout_publicador/README.md` para aparência e estrutura; a classe `Component` no
fim de cada `.dc.html` para **comportamento** (ordenação, cálculo de margem, prejuízo, ponto de
equilíbrio, filtros, atalhos). O `support.js` **não faz parte da implementação** — é runtime de
protótipo. A pasta `new_layout_publicador/` é **somente leitura**.

---

## A instrução central — e por que ela já fracassou uma vez

> *"Quero que reproduza fielmente o layout, **já tentei fazer um redesign e não saiu como eu esperava
> porque vc adaptava muito o novo layout ao sistema atual**, e como falei quero que o layout mude
> totalmente para algo novo, moderno que o novo layout que criamos. **Obedeça fontes, tamanhos de
> fontes e etc...**"* — usuário, 2026-10-11

**A causa documentada do fracasso anterior foi adaptar ao sistema atual em vez de reproduzir.** Por
isso todo requisito e todo critério de sucesso desta milestone é escrito para que *"adaptei ao que já
existia"* seja uma **reprovação explícita**, não uma interpretação possível. Nenhum requisito de
fidelidade aqui é opinável: cada um cita a **medida** ou o **token** do README.

Sobre o que ainda não existe no sistema, o usuário disse:

> *"Quero que tente deixar a maior quantidade de coisas possível funcional, como: gráficos, logs,
> informativos, botões, indicadores etc. Coisas que foram criadas no layout mas ainda não existem no
> sistema pode reproduzir como enfeite para depois resolvermos deixando funcional, mas caso consiga
> deixar funcional na hora é melhor ainda."*

**E, na mesma conversa, decidiu: "Desempenho por último — nada nasce decorativo."** Logo a régua do
enfeite é: **funcional é o padrão; enfeite é exceção justificada, item a item**; e quando o bloco
INTEIRO só poderia nascer decorativo, a fase **espera** em vez de nascer enfeite.

---

## Decisões travadas (NÃO reabrir)

- **D1 — Reproduzir, não adaptar.** O README manda comparar sistematicamente: largura, altura, grid,
  spacing, padding, gap, tipografia, tamanho, peso, raio, bordas, sombras, cores, alinhamento,
  ícones, badges, tabelas, estados, densidade e posição dos CTAs. A fidelidade declarada é
  **high-fidelity, pixel**.
- **D2 — O escopo do design novo é o MÓDULO Publicador, não o sistema.** O `AppLayout` (header de
  `h-[60px] shrink-0` + sidebar) **fica exatamente como está**. Quem rola é o `<main>`
  (`overflow-y-auto`), então `position: sticky; top: 0` dentro dele funciona — é onde o
  `PublicadorHeader` entra.
- **D3 — Os tokens novos contrariam o `CLAUDE.md` de propósito, por decisão do usuário.** O
  `CLAUDE.md` diz "tokens `ecf-*` — manter consistência"; esta milestone cria uma camada de tokens
  própria do Publicador. Não é desvio, é decisão.
- **D4 — Sem barra lateral de módulo.** Recusada pelo usuário em 10/10: *"não concordo, e além disso
  não tem espaço"*. Não reabrir.
- **D5 — Funcional é o padrão.** Enfeite só item a item, com justificativa escrita, usando a
  convenção que **já existe** no projeto: a pilha discreta `SeloExemplo.jsx` e o arquivo único
  `dadosDeExemplo.js`. **Reusar, não inventar outra.**
- **D6 — Desempenho por último, e nada nasce decorativo.** A tela Desempenho fica **registrada, não
  planejada** — ver a seção DESEMP e o bloqueio de dado medido.
- **D7 — Melhorar sem regredir.** Nada que existe hoje nas 11 páginas pode sumir.
- **D8 — Gate de aparência velha sai; gate de comportamento fica** — a distinção é caso a caso, com
  justificativa **por asserção**.
- **D9 — O header vem primeiro.** Ele é consumido por 11 páginas; é o item de maior alcance da
  milestone.

---

## Medições e restrições (feitas em 2026-10-10/11 — não refazer)

### É design system NOVO, não ajuste

| | Hoje | Novo |
|---|---|---|
| Fonte | Inter (sans) / Manrope (display) | **Geist + Geist Mono**, `tabular-nums` em toda métrica |
| Fundo | `ecf-bg` `#050507` | `#0A0A0A` |
| Card | `ecf-card` `#0f1116` | `#111111` |
| Accent | `ecf-yellow` `#ffe600` | `#FACC15`, texto sobre amarelo sempre `#0A0A0A` |

As fontes atuais são carregadas por `<link>` do Google Fonts em `resources/views/app.blade.php:24`
(Inter + Manrope); `tailwind.config.js` declara `fontFamily.sans = ['Inter', …]` e
`fontFamily.display = ['Manrope', 'Inter', …]`. Geist e Geist Mono entram **ao lado**, sem trocar o
`sans` global.

### O gate que vai brigar

`tests/js/publicador-entrada.test.js` impõe, sobre **17 arquivos** (inclui
`Pages/Mlb/AnunciosEmpresas.jsx` e `Pages/Mlb/Publicador/Produtos.jsx`):

- vocabulário de tipografia **fechado em 24/15/13/11px** e veto a `text-xs|sm|base|lg|xl|2-9xl`
  (linhas 38, 44-47);
- pesos **só 400 e 700** (linha 50-52);
- `assert.doesNotMatch(fonte, /\bbg-ecf-yellow(?!\/)/)` — **proíbe amarelo sólido** (linha 55).

O layout novo usa 28/20/17/16/15/14.5/14/13.5/13/12.5/12/11.5/11/10.5px + 30/32/34px de display,
pesos 400/500/600/700 e `#FACC15` cheio nos CTAs primários. **São incompatíveis por construção.**

`tests/js/estrutura-meus-anuncios.test.js` tem o mesmo problema em 6 asserções (sem `text-xs`, sem
`text-[10|12|13]px`, exige `text-[11px]`/`text-sm`/`text-base`, sem `font-medium`, sem `font-bold`,
sem espaçamento em meio-passo `.5`) — e, no MESMO arquivo, asserções de **comportamento** que ficam
(D-11: nenhum `router.put/delete/patch`; D-22: nota "de 86" nunca renormalizada para 100; as rotas
das abas; o literal do D23).

**Custo de aparência é BAIXO:** só 12 arquivos de `tests/js/` asseveram classe/tamanho, 1 a 4
asserções cada. Os 38 do módulo testam **comportamento**.

### Alcance do header

`BarraDaConta.jsx` e `AbasDaConta.jsx` são consumidos por **9 páginas** — medido na Fase 177 por
`grep '<BarraDaConta\|<AbasDaConta'` (quem **MONTA**), e conferido página por página:
`Mlb/AnunciarMassa`, `Mlb/AnunciosHistorico`, `Mlb/MeusAnuncios`,
`Mlb/Publicador/{Alavancas, Configuracoes, Produto, Produtos, PublicacaoEmLote, VisaoGeral}`.

> ⚠️ **CORREÇÃO (12/10/2026): este documento dizia "11 páginas" e estava ERRADO — o número era meu,
> do orquestrador, e veio de um grep mal-feito.** Eu procurei por **menções** a `BarraDaConta` em vez
> de por quem a **monta**, e duas das ocorrências eram **comentários dizendo que a página NÃO a usa**:
> `AnunciarML.jsx:2015` ("wizard NÃO adota BarraDaConta/AbasDaConta") e `Tarefas.jsx:14` ("sem
> BarraDaConta"). As duas **não montam nada** e ficaram **fora** da Fase 177, com diff vazio exigido
> por gate.
>
> E faz sentido conceitual: **`Tarefas` é tela GLOBAL**, de todas as contas — não existe conta para o
> header exibir. Pôr o header nela é decisão de produto nova, não parte desta milestone.
>
> As ocorrências de "11 páginas" que restam neste arquivo (D7, D9, HEAD-11, HEAD-15, REG-01) e no
> `ROADMAP.md` **leiam-se 9**. O texto original fica como registro do erro.

### O que JÁ existe e deve ser reaproveitado, não refeito

- **`PainelVisaoGeralService`** calcula indicadores, "O que fazer agora", alertas, situação dos
  produtos, produtos por fase, últimas publicações, integrações, identidade e a **Atividade da
  equipe** (3 fontes reais: `pub_publicacoes`, `ml_anuncio_criativo_kits`, `pub_validacoes`). Já
  aplica a disciplina **"nunca medimos" ≠ "medimos e é zero"** (`null` vs `0`, `nunca_coletado`,
  `tracao_pct` só quando há o que dividir) — essa honestidade é requisito, não detalhe.
- **`AcervoTriagemService`** — busca por **SKU, nome e MLB** e o filtro **"Em revisão"** acabaram de
  entrar (quicks `261010-nke` e `261010-rie`); `motivosDef()` é a fonte única dos 5 motivos;
  `escopo()` é a fonte única do mapeamento de status; `triagem()` é UMA query, nunca um laço.
- **`ml_acervo_itens`** tem `price`, `sold_quantity`, `status`, `sub_status`, `listing_type_id`
  (`gold_special` = Clássico, `gold_pro` = Premium), `catalog_listing`, `health_ml`,
  `performance_*`, `visitas_30d`, `buybox_status`, `nota_ecf`, `nota_sinais`, `motivos`,
  `severidade`, `origem`, `rascunho_id` e **`skus`** (coluna de 10/10).
- **A metodologia da publicação é derivável sem coluna nova:** `ml_acervo_itens.rascunho_id` →
  `pub_rascunhos.produto_id` → `pub_produtos` (`fase`, `quantidade_kit`, `produto_base_id`, `sku`,
  `nome`). `rascunho_id` nulo = **Legado** (o `ph: 0` do protótipo).
- **Custo, lucro, margem, imposto reservado e o desconto de 14 dias** acabaram de ser construídos:
  `DadosEfetivosService::custosDoProduto()` (custo + imposto do Portal),
  `App\Support\Publicador\Validacao\SimuladorVoceRecebe::calcular()` (função pura: preço, tarifa,
  frete → `voce_recebe`, `percentual`, `frete_conhecido`) e `App\Support\Publicador\PrecoDaPromocao`.
- **`AnaliseAlavancasService`** já monta `margem {valor, percentual, custo, imposto_percentual}` por
  anúncio publicado, com **tarifa cacheada** (`alavancas:tarifa:{cat}:{lt}:{logistica}:{modo}:{preco}`,
  TTL `publicador.alavancas.cache.tarifa`, padrão 3600s) e custo de envio por
  `/users/{id}/shipping_options/free`. É exposto por um endpoint **JSON lazy**
  (`MlbAlavancasController::analise`), chamado pelo cliente com a lista de itens. **É o motor de
  rentabilidade da milestone — nenhum cálculo de margem novo é escrito.**
- **`SeloExemplo.jsx`** (+ `TITULO_EXEMPLO`, `TITULO_BOTAO_EXEMPLO`) e **`dadosDeExemplo.js`**
  (`CONTA_EXEMPLO`, `ERP_EXEMPLO`, `CATALOGO_EXEMPLO`, `PERIODOS_EXEMPLO`, `ALERTAS_ML_EXEMPLO`,
  `TRACAO_EXEMPLO`, `CONVERSAO_EXEMPLO`), com gates em `publicador-dashboard-v2.test.js` (só
  constantes, nenhum import, nenhuma função).
- **`publicador.empresas-busca`** (rota com `throttle:60,1`) e `SeletorEmpresaBusca.jsx` já fazem a
  busca de empresas preservando a aba atual — o dropdown do header novo reusa, não refaz.
- **`ProgramasPublicadorService::miniaturaDaFoto`** troca o sufixo da foto do ML para a variação
  pequena (`-I.jpg`, ~4 kB, contra `-F.jpg`, ~200 kB) — toda miniatura de 34-40px usa isso (§27 dos
  learnings).
- **`textoSeguro()`** (export nomeado de `BarraDaConta.jsx`) — todo campo de texto do servidor passa
  por ela antes do JSX. `String(valor ?? '—')` **não basta**: um objeto vira `"[object Object]"`.

### A tarifa do ML não está no banco (consequência de desenho)

"Quanto você recebe" usa **a tarifa e o frete que o Mercado Livre respondeu AGORA**
(`/sites/MLB/listing_prices` + `shipping_options/free`, em `EditorRascunhoService`). Não existe
tarifa persistida por anúncio. Logo:

- a **célula Preço** da tabela não pode trazer "Margem x%" calculada no servidor junto da lista (50
  linhas = 50 cotações);
- a margem da linha e a "Rentabilidade por venda" do painel entram por **chamada assíncrona** ao
  endpoint que já existe, depois da lista renderizar; até chegar, a célula mostra **"—"**, nunca um
  número estimado.

### Dados do Desempenho — o motivo de a tela ficar para depois

- `ml_acervo_metricas_diarias`: **1.374.527 linhas** (`company_id, ml_item_id, data, visitas,
  sold_quantity, nota_ecf, health_ml`).
- ⚠️ **`sold_quantity` é ACUMULADO da vida do anúncio** (amostra real: 44 em 23/08 → 45 em 10/10).
- ⚠️ **Série ESPARSA: média de 1,3 dias por anúncio** (máximo 89).
- ⚠️ **`visitas` é NULL em 100%** das 1,37 milhão de linhas.
- ⚠️ **Não existe tabela de pedidos** (só `mlb_sync_vendas_logs`, 6 linhas).
- **Reais:** "Publicações criadas no período", "Criativos com IA (packs)", "Fase 1 × Fase 2 em
  publicações criadas".
- **Sem dado:** "vendas por dia empilhado por fase", "Visitas", "Conversão".

### Restrições de execução (não reabrir)

- **Não tocar no editor** (`Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/`) além do
  necessário pelo header — **território do outro dev, ativo agora**. Necessidade ali vira item em
  `.planning/coordenacao/`.
- **Não alterar** `App\Support\Publicador` nem `MlPublicacaoService`.
- **Tela preta de 07/10:** campo do presenter que chegou como objeto e foi renderizado como texto
  derrubou a página. Todo campo novo exibido entra em teste de **render REAL** (esbuild +
  `react-dom/server`) com o campo **objeto, nulo e ausente**.
- `assert.match(tag, /disabled/)` é **asserção vazia** aqui — usar `/disabled=/`.
- **Rollup** elimina variável de escopo usada dentro de `.map()` — computar no callback.
- **Montar TODOS os bundles ANTES do primeiro `test()`** no `node --test`.
- **Gate de diff do commit de partida** (`<inicial>..HEAD`), nunca da árvore.
- **Baselines de 11/10:** PHP `tests/{Unit,Feature}/Publicador` = **1761 / 0**; JS = **2210 / 2209
  pass / 1 fail** (a pré-existente `estrutura-grade-glide` — **não corrigir**).
- `npm run build` obrigatório em toda fase que toque `resources/`, manifest conferido **pelo JSON**
  (o hash sai com hífen).
- Migration (se houver): índice de nome curto (1059), FK `nullOnDelete` exige `->nullable()` (1830),
  sem enum (CHECK do SQLite), `up()` idempotente — e **produção é MySQL 8.0.46, o local é MariaDB
  10.4** (`.planning/learnings/banco-producao-e-mysql8-local-e-mariadb.md`): declarar no plano o que
  o teste local **não** cobre.
- `php` não está no PATH: `C:/xampp/php/php.exe`.
- **NÃO deployar, NÃO dar push.** Árvore COMPARTILHADA: pathspec no `add` e no `commit`, `-m` antes
  do `--`. **NUNCA** `git add -A`, `git stash`, `git reset`, `git checkout`, `git commit --amend`.
  **NÃO** criar worktree. **NÃO** rodar `vendor/bin/pint`.

---

## Requisitos

### FID — Fidelidade verificável (o requisito que já fracassou)

- **FID-01** — Cada tela e cada componente desta milestone tem, no plano da fase, uma **tabela de
  conferência item a item** contra o protótipo correspondente, cobrindo as 20 dimensões que o README
  exige: largura · altura · grid · spacing · padding · gap · tipografia (família, tamanho, peso,
  tracking) · raio · bordas · sombras · cores · alinhamento · ícones · badges · tabelas · estados ·
  densidade · posição dos CTAs. Cada linha cita o **valor do protótipo** e o **valor implementado**,
  lado a lado.
- **FID-02** — **"Adaptei ao que já existia" é REPROVAÇÃO.** Divergência do protótipo só é aceita
  com justificativa escrita na SUMMARY nomeando a restrição técnica concreta que a obriga (ex.: o
  `AppLayout` não é alterado — D2). **Não são justificativas aceitas:** "ficou mais parecido com o
  resto do sistema"; "mantive o token `ecf-*` por consistência"; "o componente que já existe faz
  parecido"; "o Tailwind não tem esse passo".
- **FID-03** — Nenhuma medida é arredondada para o passo do Tailwind: 13,5px é `text-[13.5px]` e não
  `text-sm`; 54px é `h-[54px]` e não `h-14`; 460px é `w-[460px]`; 176px é `w-[176px]`.
- **FID-04** — Nenhum arquivo das telas redesenhadas usa `ecf-bg`, `ecf-card`, `ecf-card-2`,
  `ecf-yellow`, `ecf-yellow-2`, `ecf-line`, `ecf-dim`, `ecf-mute`, `font-display` nem
  `border-white/[0.08]`.
- **FID-05** — Desktop-first em **1440px**: container com `min-width: 1240px`, conteúdo com
  `max-width: 1600px` centralizado e `padding: 0 36px`; abaixo de 1240px rola na horizontal
  (ferramenta interna desktop). Nenhum breakpoint `sm:`/`md:`/`lg:` novo nessas telas.
- **FID-06** — O protótipo é autoridade de **comportamento** também: ordenação, cálculo de margem,
  prejuízo, ponto de equilíbrio, filtros e atalhos seguem a classe `Component` do `.dc.html`
  correspondente, salvo onde a fonte de dados real obrigue outra coisa — e aí a divergência é escrita.

### TOK — O design system novo do módulo

- **TOK-01** — **Geist** (400/500/600/700) para UI e **Geist Mono** (400/500) para SKU, MLB, códigos
  e atalhos de teclado, carregadas ao lado de Inter/Manrope, sem trocar o `sans` global do sistema.
- **TOK-02** — `font-variant-numeric: tabular-nums` em **todas** as métricas, preços e contagens.
- **TOK-03** — Títulos com tracking negativo: H1 28px/600/`-0.025em`; H2 de seção 17px/600/`-0.01em`;
  título de card 15-16px/600. Overlines 10.5-11px/600, `letter-spacing: 0.06-0.09em`, caixa alta.
- **TOK-04** — Superfícies: page/header `#0A0A0A` · surface `#111111` · sunken `#0D0D0D` · raised
  `#151515`-`#181818` · hover `#141414`/`#161616`/`#1A1A1A` · segmented ativo `#262626` +
  `inset 0 0 0 1px #333333`.
- **TOK-05** — Bordas: default `#262626` · strong `#2E2E2E` · subtle `#1F1F1F`/`#1C1C1C` ·
  control-off `#3F3F46`. Texto: `#F4F4F5` · `#A1A1AA` · `#8A8A90` · `#71717A` · `#52525B`.
- **TOK-06** — Accent `#FACC15` · hover `#EAB308` · tint `rgba(250,204,21,0.12-0.16)` ·
  row-selected `#221D07` · row-new `#14120A`. **Texto sobre amarelo é SEMPRE `#0A0A0A`.** Amarelo é
  accent, não preenchimento geral.
- **TOK-07** — Semânticas com dot + texto + fundo: success `#22C55E`/`#4ADE80`/`rgba(34,197,94,0.12)`
  · warning `#F59E0B`/`#FBBF24`/`rgba(245,158,11,0.12)` · danger
  `#EF4444`/`#F87171`/`rgba(239,68,68,0.10-0.13)`/`#FCA5A5` em caixa de alerta · neutral-status dot
  `#71717A` e texto `#A1A1AA`. Fase 1 `#D4D4D8`/`#E4E4E7`.
- **TOK-08** — Raios: card 16px (tabela de Publicações 14px) · painéis/sub-cards 10-12px ·
  botões/inputs 7-9px · chips/badges 4-6px · pills 999px · checkbox 4px.
- **TOK-09** — Sombras, quase nenhuma: popover `0 24px 60px rgba(0,0,0,0.7)` · painel lateral
  `-24px 0 64px rgba(0,0,0,0.6)` · modal `0 30px 80px rgba(0,0,0,0.8)` · CTA primário do header
  `0 0 0 1px rgba(250,204,21,0.25), 0 6px 20px rgba(250,204,21,0.12)`.
- **TOK-10** — Escala de espaçamento 2/4/6/8/10/12/14/16/18/20/22/24/28/32/36; gap entre seções da
  página 20px; padding de card 18-24px.
- **TOK-11** — Alturas de controle: botões 32-38px (header 34px) · segmented 28-30px dentro de trilho
  com padding 3px · inputs 38px (busca principal de Publicações 54px) · linha de tabela 62px
  (confortável) / 50px (compacta) · linha de grupo 58px.
- **TOK-12** — Transições: background/border 0.15s · opacidade 0.12-0.2s · chevrons rotacionam
  0.18-0.2s · barras `width .4s` · painel lateral `0.28s cubic-bezier(.2,.8,.2,1)`. Spinner 11-13px,
  borda 1.5px `#3F3F46` com topo `#FACC15`, `spin .7s linear infinite`.
- **TOK-13** — Tile de produto placeholder: `background: oklch(0.27 0.035 H)`,
  `color: oklch(0.86 0.07 H)` com as iniciais do produto, `H` estável por produto (hash do id);
  substituído pela **foto principal** quando existir, pela miniatura pequena do CDN do ML
  (`miniaturaDaFoto`).
- **TOK-14** — Ícones: SVG da biblioteca do codebase (`lucide-react`), stroke 1.4-2px,
  `stroke-linecap: round`. Sem bitmap. Nenhum SVG do protótipo é copiado cru quando existe
  equivalente no Lucide.
- **TOK-15** — Os tokens vivem numa **camada própria do módulo**, declarados UMA vez (extensão do
  Tailwind e/ou CSS custom properties com prefixo próprio); nenhum hex literal repetido nas telas.
  Os tokens `ecf-*` **continuam intactos** para o resto do sistema.

### IDENT — Componentes de identidade (reusados pelas três telas)

- **IDENT-01** — Glyph de metodologia, **nunca dependendo só de cor**: Fase 1 = retângulo 10×13px,
  borda 1.5px, raio 2.5px · Fase 2 = dois retângulos sobrepostos (offset 5px/3px), o da frente
  preenchido `#FACC15` · Legado = retângulo com borda tracejada `#71717A`.
- **IDENT-02** — Labels literais: "Fase 1" + "Simples · 1 un." / "Fase 2" + "Combo · N un." /
  "Legado" + "Fora do Publicador".
- **IDENT-03** — Tipo: "Clássico" (400) / "Premium" (600, com losango 7px rotacionado 45° antes);
  "Catálogo" = tag outline 10.5px.
- **IDENT-04** — Status com **forma + cor + texto**: Ativa = círculo cheio · Pausada = duas barras ·
  Com erro = triângulo · Em revisão = anel aberto. Motivo do status em 11.5px abaixo.
- **IDENT-05** — Mapa de cobertura F1/F2 × C/P: células 18-20px, raio 4-5px, letra C/P; existe F1 →
  `#E4E4E7` com texto escuro; existe F2 → `#FACC15`; não existe → transparente com
  `1px dashed #3F3F46` e letra `#52525B`. Tooltip "Fase 1 · Clássico — publicada / não criada".
- **IDENT-06** — Badge NOVA: 10px/700, tracking 0.06em, `#FACC15` com texto `#0A0A0A`, raio 4px.
- **IDENT-07** — Tooltips pelo componente de tooltip do codebase, não por `title` nativo (o
  protótipo usa `title` só por limitação dele).
- **IDENT-08** — Todos estes componentes são **compartilhados**: nenhuma tela reimplementa glyph,
  status, tipo, mapa de cobertura, tile ou badge por conta própria.

### HEAD — `PublicadorHeader` global

- **HEAD-01** — Fixo no topo com `position: sticky; top: 0; z-index: 50` **dentro do `<main>` do
  `AppLayout`**; fundo `rgba(10,10,10,0.86)` + `backdrop-filter: saturate(140%) blur(14px)`; borda
  inferior `#1C1C1C`. Linha 1 = 60px; linha 2 (nav) = 48px.
- **HEAD-02** — Props: `active` (`overview|products|publications|performance|levers`), `mlConnected`,
  `erpConnected`.
- **HEAD-03** — Esquerda: marca "Publicador" (glyph de Fase 2 em amarelo, 24px + texto 14px/600) →
  separador "/" → seletor de empresa → pill de conexão.
- **HEAD-04** — Seletor de empresa: tile 28px `#1F1F1F` com iniciais em amarelo e bolinha de status
  de 10px no canto (verde/âmbar/vermelho, borda 2px `#0A0A0A`); nome 14.5px/600; código em mono 11px
  `#71717A`; ícone de abrir.
- **HEAD-05** — Dropdown de empresa de **360px**: input de busca (nome ou código) de 38px; grupos
  **RECENTES / TODAS** (ou **RESULTADOS** durante a busca); item de 46px com tile 30px, nome, status
  de conexão com dot, código em mono e **check amarelo na empresa atual**; rodapé "N empresas sob
  gestão · Ver todas →". Reusa `publicador.empresas-busca` e a navegação que **preserva a aba atual**.
- **HEAD-06** — Pill de conexão: 28px, raio 999, dois dots de 7px (ML, ERP) + label. Tudo certo →
  "Conectado" neutro `#A1A1AA` com borda `#222222`. ML off → "Mercado Livre desconectado" em
  `#F87171`, borda `rgba(239,68,68,0.45)`, fundo `rgba(239,68,68,0.10)`. ERP off → âmbar equivalente.
- **HEAD-07** — Popover de conexão de **320px**: linha Mercado Livre (tile amarelo "ML") e linha ERP,
  cada uma com meta (ex.: "Conta ECFDEV02 · token renova em 4 h") e status; rodapé "Última coleta há
  X · Atualizar agora", ligado ao `mlb.anuncios.meus.atualizar` que já existe (**enfileira**, nunca
  coleta em processo, nunca escreve no ML).
- **HEAD-08** — Direita: botão de busca de **250px** "Buscar produto ou MLB" com as teclas ⌘ e K em
  mono 10.5px (caixas de 20px, borda `#2E2E2E`, raio 5px) · ícone de Configurações 34px ghost
  (**Configurações sai das abas**) · separador de 1×20px · **CTA primário "Nova publicação"** (34px,
  `#FACC15`, texto `#0A0A0A` 13px/600) com badge da tecla "N".
- **HEAD-09** — Linha 2: abas como pills de 34px com ícone 15px + label 13.5px + contador em pill de
  18px (min-width 22px). A ativa tem fundo `#161616`, `inset 0 0 0 1px #262626`, ícone e contador em
  amarelo (`rgba(250,204,21,0.14)` de fundo no contador) e uma **barra amarela de 2px por baixo**.
  Abas, nesta ordem: **Visão geral · Produtos (N) · Publicações (N) · Alavancas** — **quatro**, sem
  "Desempenho".
  ⚠️ **DESVIO DELIBERADO DA FIDELIDADE, decidido pelo usuário em 11/10/2026.** O README especifica
  **cinco** abas, incluindo Desempenho. Palavras dele: *"não mostre o link da tela desempenho por
  enquanto"*. O motivo é a própria régua que ele fixou — **"nada nasce decorativo"**: a tela não
  existe (Fase 182 está registrada e não planejada, porque o dado de vendas por dia não existe), e
  uma aba que leva a lugar nenhum é link morto.
  **Gatilho de volta:** a aba entra junto com a Fase 182, e a 182 carrega essa anotação. Quem
  reimplementar o header a partir do README sem ler isto vai achar que faltou uma aba — **não
  faltou**.
- **HEAD-10** — A aba Publicações ganha um **dot vermelho `#EF4444` de 6px** quando houver publicação
  com erro. Todos os contadores são **reais**, vindos do servidor — nenhum número fixo no JSX.
- **HEAD-11** — O header **substitui** `BarraDaConta` + `AbasDaConta` nas 11 páginas e **nada que
  elas entregam hoje se perde**: nome e chave da conta, `SeloConta`, `SeloPortal`,
  `AvisoContaTravada`, slot de ações por página, **D23** (Publicações desabilitada sem `Company`, com
  o literal exato "Disponível só para empresas cadastradas no sistema"), contador de produtos, badge
  de alavancas pendentes e as sub-abas **No ar | Histórico** de Publicações.
- **HEAD-12** — `textoSeguro()` segue valendo para **todo** campo de texto vindo do servidor; nenhum
  campo novo é interpolado cru no JSX.
- **HEAD-13** — Teclado e descarte: ⌘K/Ctrl+K e **N** (fora de input) abrem a busca; **Esc** fecha
  modal, dropdown e popover; clique fora fecha por backdrop transparente.
- **HEAD-14** — O `AppLayout` **não muda**. O `padding` do `<main>` (`p-6`) é neutralizado **apenas**
  nas páginas do Publicador, para o header novo encostar nas bordas e cumprir FID-05.
- **HEAD-15** — Com o header novo no ar, as 11 páginas convivem com **corpo antigo e header novo**
  até as fases seguintes. Esse estado intermediário é **aceito e declarado**, não é defeito — e não
  autoriza "harmonizar" o header com o corpo antigo (FID-02).

### NOVA — Modal "Nova publicação" (a entrada da Fase 2)

- **NOVA-01** — Modal de **620px**, `top: 110px`, backdrop `rgba(0,0,0,0.65)`, raio 16px, sombra
  `0 30px 80px rgba(0,0,0,0.8)`; cabeçalho de 60px com input de 16px "Qual produto você quer
  publicar?" e a tecla **Esc** em mono 11px à direita; corpo com `max-height: 420px` e scroll.
- **NOVA-02** — Resultados agrupados por **próximo passo**, nesta ordem: **PRONTOS PARA FASE 2**
  (hint "reaproveita a Fase 1", CTA "Criar combo" com fundo `rgba(250,204,21,0.14)` e texto
  `#FACC15`) · **SEM PUBLICAÇÃO** (hint "1 unidade por oferta", CTA "Publicar Fase 1") ·
  **RASCUNHOS** (hint "retome de onde parou", CTA "Continuar"). Grupo sem item **não aparece**.
- **NOVA-03** — Item de 50px: tile 34px, nome 13.5px/500, SKU em mono 11px + nota de contexto
  11.5px `#8A8A90`, CTA 12px/600 em pill de 7px.
- **NOVA-04** — A busca **ignora acento e caixa** (NFD + remoção de diacríticos) e procura em nome e
  SKU do produto.
- **NOVA-05** — Vazio: "Nenhum produto encontrado", 13px, centralizado, padding 36px.
- **NOVA-06** — Rodapé fixo `#0D0D0D` com o glyph de Fase 2 e a frase **literal**: "A Fase 2 usa o
  mesmo Publicador e reaproveita categoria, atributos e ficha técnica da Fase 1."
- **NOVA-07** — **Todo CTA tem destino real:** "Criar combo" leva ao fluxo de Criar fase (Fase 175),
  "Publicar Fase 1" ao editor do produto, "Continuar" ao rascunho. Nenhum CTA sem destino, nenhum
  item de enfeite nesta lista.
- **NOVA-08** — Os três grupos vêm do **servidor**, das fontes que já existem (`prontosParaFase2()`,
  situação/fase dos produtos, rascunhos da conta) — nenhuma classificação feita no cliente.
- **NOVA-09** — Abre por ⌘K/Ctrl+K, por **N** fora de input e pelos **dois** botões do header (busca
  e CTA); fecha com Esc ou clique fora; o input recebe foco ao abrir.
- **NOVA-10** — Este modal é a **resposta nova para a "entrada da Fase 2"**, que o usuário adiou em
  10/10 esperando exatamente este redesenho.

### VG — Visão Geral

- **VG-01** — Ordem vertical com gap de 20px, exatamente a do README: banner de integração → Jornada
  dos produtos → grid `1fr | 392px` → faixa de Desempenho → grid `1fr | 1fr`. Conteúdo em
  `max-width: 1600px`, `padding: 24px 36px 80px`.
- **VG-02** — Banner de integração **só aparece com problema**: ícone em tile 34px, título colorido,
  descrição e CTA cheio na cor do problema. ML off (vermelho): "Mercado Livre desconectado" / CTA
  "Reconectar conta". ERP off (âmbar): "ERP Bling desconectado" / CTA "Reconectar ERP".
- **VG-03** — Jornada dos produtos: card com padding 22/24, grid `184px | 1fr`, gap 32px. A UI **diz
  na tela** que a Jornada mostra o estado atual e não depende de período. ⚠️ O seletor de período que
  a tela de hoje tem (alimentado por `PERIODOS_EXEMPLO`) **sai**: na Visão Geral não há filtro global
  (Jornada, Saúde e Ações = estado atual; Recentes e Atividade = últimos 30 dias).
- **VG-04** — Anel de **176px** (r=74, stroke 14, gaps de 3px entre segmentos), ordem Fase 1+2
  amarelo → Fase 1 `#E4E4E7` → Em andamento `#71717A` → Sem publicação `#3F3F46`. No centro: o
  percentual (34px/600), a palavra "publicados" e "N de M produtos".
- **VG-05** — Quatro estágios em grid de 4 colunas, gap 12px, com chevron entre eles: **01 Sem
  publicação** (Aguardando a Fase 1) · **02 Em andamento** (Rascunhos no Publicador) · **03 Fase 1
  publicada** (Prontos para a Fase 2) · **04 Fase 1 + Fase 2** (Metodologia completa). Cada um com
  número 32px/600, % do total, label, sub, pilha de até 4 tiles de produto + "+N" e um CTA textual.
- **VG-06** — O estágio "próximo passo" (**Prontos para Fase 2** se > 0, senão **Sem publicação**)
  leva o badge amarelo **"PRÓXIMO PASSO"** e o CTA em amarelo.
- **VG-07** — Barra segmentada de 8px proporcional aos 4 estágios; "Sem publicação" com hachura
  `repeating-linear-gradient(135deg,#3F3F46 0 3px,#262626 3px 6px)`.
- **VG-08** — Ao clicar num estágio: ele fica selecionado (fundo `#181818`, borda `#3F3F46`), os
  outros segmentos do anel e da barra caem para opacidade 0.3-0.35, e abre um painel abaixo com até 6
  produtos — cada linha com tile, nome, SKU, mapa de cobertura, nota de contexto e CTA ("Criar
  combo" / "Publicar Fase 1" / "Continuar" / "Ver publicações"). O painel tem link "Ver todos os N →"
  para Produtos **filtrada**. Clicar de novo fecha. **Estado inicial: "Prontos para Fase 2" aberto.**
- **VG-09** — "O que fazer agora": segmented com Todas / Crítico / Atenção / Oportunidade e
  contagens; ordenação **Crítico → Atenção → Oportunidade**.
- **VG-10** — Cada ação: ícone em tile 38px por severidade (Crítico = triângulo vermelho · Atenção =
  círculo âmbar com "!" · Oportunidade = seta de tendência amarela); título 14px/600 + badge de
  severidade + descrição em **uma** linha; pilha de tiles dos produtos afetados e CTA. A primeira (a
  mais severa) leva o badge **"RECOMENDADO AGORA"**, CTA amarelo cheio e fundo `#131313`; as demais,
  CTA secundário (`#161616`, borda `#2E2E2E`).
- **VG-11** — Chevron de expandir mostra a sub-lista dos itens afetados (nome, meta, motivo colorido,
  "Abrir →") e um **"Adiar até amanhã"** que esconde a ação. O adiamento é **por usuário e persiste
  até o dia seguinte** — não é só estado de tela.
- **VG-12** — As ações que o sistema sustenta hoje são **reais**: publicações com erro · N produtos
  prontos para a Fase 2 · N produtos sem publicação · rascunhos parados no Publicador · publicações
  pausadas · reconectar ML/ERP. A ação **"publicação com prejuízo"** depende da rentabilidade (fase
  do painel de inspeção) e, até ela existir, **não aparece** — nunca como enfeite.
- **VG-13** — Saúde da conta: status geral no topo ("Tudo conectado" `#4ADE80` / "Atenção" `#FBBF24`
  / "Requer ação" `#F87171`), seguido das linhas Mercado Livre, ERP Bling e Sincronização; a de
  Sincronização tem o botão "Atualizar" com spinner; linha com problema ganha fundo tint
  (`rgba(239,68,68,0.07)` / `rgba(245,158,11,0.07)`).
- **VG-14** — Publicações no Mercado Livre: total em 30px, "% ativas", barra empilhada
  Ativas/Pausadas/Com erro e legenda **clicável** que leva a Publicações já filtrada. Abaixo, o bloco
  "Acervo de imagens · N imagens · N packs gerados com IA · Abrir →".
- **VG-15** — Faixa de Desempenho: **o bloco inteiro é link** para a tela Desempenho — ícone +
  "Desempenho · Últimos 30 dias", 4 mini-KPIs (label, valor 20px, delta ↑/↓ colorido, sparkline
  64×26) e "Ver desempenho →". Os KPIs com dado real (publicações criadas no período, criativos com
  IA/packs) são reais; os sem dado (vendas, visitas, conversão) **não aparecem como número
  inventado** — ou saem da faixa, ou a faixa inteira carrega a pilha `SeloExemplo` com o motivo.
- **VG-16** — Publicações recentes: 5 linhas (tile 40px, título + badge NOVA, glyph de fase + "Fase 2
  · Combo ×2" · tipo · MLB em mono, status com forma, quando/por quem) e link "Ver todas as
  publicações →". Linha nova com fundo `#14120A`.
- **VG-17** — Atividade recente: chips **Tudo / Publicações / Criativos / Produtos / Sistema**;
  timeline agrupada por **HOJE / ONTEM / data** com ícone circular de 30px por tipo (publicação
  amarelo · criativos IA estrela · produto recebido caixa · sincronização refresh · erro vermelho ·
  fase concluída check verde). Cada evento traz **ator + verbo + alvo**, meta, link de ação opcional
  e, para criativos, miniaturas de 34px.
- **VG-18** — **"Nunca medimos" ≠ "medimos e é zero".** A honestidade que `PainelVisaoGeralService`
  já aplica (`null` vs `0`, `nunca_coletado`, `tracao_pct` só quando há o que dividir) é preservada
  em **todo** indicador da tela nova.
- **VG-19** — Os 6 cenários do protótipo (prop `scenario`) são estados suportados: **Com pendências**
  (default) · **Conta saudável** (sem críticos) · **Mercado Livre desconectado** · **ERP
  desconectado** · **Produtos sem publicação** (anel 0%, ação "N produtos aguardando a Fase 1",
  recentes vazio) · **Sem produtos** (empty state ilustrado na Jornada, com "Cadastrar produto" e
  "Enviar link do Portal ao cliente").
- **VG-20** — Nenhum bloco que **hoje é real** regride a exemplo. Em particular, a **Atividade da
  equipe** tem 3 fontes reais desde 10/10 e segue real.

### LIST — Publicações (lista, busca, filtros, tabela)

- **LIST-01** — Cabeçalho: H1 "Publicações" + "Ofertas ativas e históricas desta empresa no Mercado
  Livre". À direita, "● Sincronizado há X" e o botão "Atualizar" (spinner enquanto atualiza, depois
  "agora"), ligado ao `meus.atualizar` que **enfileira**.
- **LIST-02** — Faixa de sucesso pós-publicação (fundo `#14120A`, borda `rgba(250,204,21,0.35)`),
  visível ao voltar do Publicador: texto "N publicações criadas agora · <produto> / <fase> ·
  <tipos>", botão **"Localizar na lista"** (preenche a busca, limpa os filtros, muda para a visão Por
  produto e abre o painel da nova publicação) e "×".
- **LIST-03** — Busca central de **54px**, raio 14px, foco com borda amarela e ring
  `rgba(250,204,21,0.18)`; placeholder "Buscar por produto, título, SKU ou MLB…". Ignora acento e
  caixa e procura em **nome do produto**, título, SKU e MLB. A busca é **no servidor** e inclui o
  nome do produto — no teste real a busca não achava o anúncio pelo nome do produto.
- **LIST-04** — À direita da busca: a dica "Pressione / para buscar" ou, com busca ativa, o resumo
  "N publicações · N produtos" + "Limpar".
- **LIST-05** — Abas de status em segmented: **Todas · Ativas · Pausadas · Com erro** (com dot
  vermelho quando > 0) **· Em revisão**, com contagens calculadas sobre os **demais** filtros.
- **LIST-06** — À direita da linha de controles: "Mais recentes primeiro" e o toggle de visão **Por
  produto / Lista**.
- **LIST-07** — Chips de filtro de 32px (ativo = tint amarelo + borda `rgba(250,204,21,0.55)`):
  **Fase** [Todas | 1 · Simples | 2 · Combo] · **Tipo** [Todos | Clássico | Premium] · toggles **"Em
  catálogo"** e **"Com vendas"** · **"Responsável"** (popover com checkbox e avatar) · **"Publicada"**
  (Qualquer data / Hoje / 7 dias / 30 dias) · **"Limpar filtros"** quando houver filtro ativo.
- **LIST-08** — Tabela em card de raio 14px, grid **literal**
  `40px | minmax(300px,1fr) | 136px | 104px | 136px | 112px | 68px | 150px | 64px` = checkbox ·
  Publicação · Metodologia · Tipo · Status · Preço · Vendas · Publicada · ações. Cabeçalho de 40px,
  fundo `#0D0D0D`, borda inferior `#262626`, 12px/500 `#8A8A90`.
- **LIST-09** — **Visão Por produto (default):** linha de grupo de 58px com chevron, tile 38px, nome
  + tag "PRODUTO", "SKU · Estoque", mapa de cobertura F1/F2 × C/P e o link "Fase 2 não criada · Criar
  combo" quando não houver F2; também "N publicações" (ou "x de N" quando filtrado) e "Ver produto".
  As publicações vêm abaixo com recuo de 30px; o grupo colapsa ao clicar e a **busca ativa força os
  grupos abertos**.
- **LIST-10** — **Visão Lista:** linhas com tile e o nome do produto no meta.
- **LIST-11** — Célula Publicação: título com ellipsis; MLB em mono + botão de copiar que vira check
  verde por **1,3 s** + badge NOVA.
- **LIST-12** — Célula Preço: valor + "Margem x%" ou **"▲ Prejuízo"** em vermelho quando repasse <
  custo; **margem = lucro ÷ preço**. ⚠️ A tarifa do ML não está no banco: a margem da linha é
  carregada de forma **assíncrona** depois da lista e, até chegar, a célula mostra **"—"** — nunca um
  número estimado, nunca um zero.
- **LIST-13** — Célula Publicada: avatar com iniciais + data relativa ("Hoje, 14:32", "Ontem,
  09:10", "28 set") + responsável. Publicação sem autor conhecido (legado/importada) diz isso, não
  inventa nome.
- **LIST-14** — Ações da linha: abrir no Mercado Livre (nova aba) e chevron.
- **LIST-15** — Fundos de linha: selecionada `#221D07` · foco do teclado `#1C1C1C` · marcada
  `#18160C` · nova `#14120A` · padrão `#111111`. Altura 62px (confortável) / 50px (compacta).
- **LIST-16** — Rodapé: "N de M publicações · N produtos" e a legenda de atalhos. A **paginação de 50
  por página** que existe hoje é preservada e o rodapé a reflete.
- **LIST-17** — Vazio: "Nenhuma publicação encontrada" + explicação de **onde** a busca procura +
  "Limpar busca e filtros". Os vazios honestos de hoje **ficam**: "Ainda não coletamos os anúncios
  desta empresa" (`nunca_coletado`) e o aviso de **SKU ainda não coletado** (`skuNaoColetado`).
- **LIST-18** — Teclado: **`/`** foca a busca · **↑/↓ ou j/k** navegam · **Enter** abre o painel ·
  **Esc** fecha menu → sai da busca → fecha o painel · **↓ ou Enter dentro da busca** vai ao
  primeiro resultado.
- **LIST-19** — Filtros, busca e a publicação selecionada são refletidos na **URL** (recarregar a
  página devolve o mesmo estado).
- **LIST-20** — A **metodologia** da linha vem do dado real: `ml_acervo_itens.rascunho_id` →
  `pub_rascunhos.produto_id` → `pub_produtos` (`fase`, `quantidade_kit`, `produto_base_id`); sem
  rascunho = **Legado**. O **tipo** vem de `listing_type_id` (`gold_special` = Clássico, `gold_pro` =
  Premium) e **Catálogo** de `catalog_listing`. Nenhum desses campos é inferido do título.
- **LIST-21** — A busca por **SKU / nome / MLB** e o filtro **"Em revisão"** que acabaram de entrar
  em `AcervoTriagemService` continuam valendo e são a base das abas de status e da busca — `escopo()`
  segue a fonte única do mapeamento de status, `motivosDef()` a dos motivos.
- **LIST-22** — Os **chips de triagem** (os 5 motivos de `motivosDef()` + "Não avaliado") continuam
  na tela, no vocabulário novo, ao lado dos chips do README; o total é **sempre** `triagem.total` do
  servidor, nunca a soma dos chips (um anúncio com 2 motivos conta 1× no total e 2× nos chips).
- **LIST-23** — **Nada do que a tela de hoje entrega desaparece**, com UMA exceção autorizada
  (LIST-25): banner de defasagem (âmbar, nunca vermelho), a ordenação por gravidade (D-12), e a regra
  **D-11** — a tela **nunca escreve no ML**, com a única exceção prevista em INSP-05
  (pausar/reativar), que passa a ser a escrita autorizada.
- **LIST-25** — ⚠️ **REMOÇÃO DE FUNCIONALIDADE EXISTENTE, AUTORIZADA PELO USUÁRIO EM 11/10/2026.**
  Palavras literais dele: *"'No ar e historico' não precisa existir, ja o rascunho adicione integrado
  no mesmo grupo de botões que tem abaixo da barra de busca"*.
  - O segmentado **"No ar | Histórico"** **SAI** da tela. Não é regressão acidental: é decisão dele,
    contra a regra-mestra "melhorar sem regredir", tomada com conhecimento dela.
  - O par **"Publicados | Rascunhos"** deixa de existir como segmentado separado. **"Rascunhos" passa
    a ser uma opção DENTRO do mesmo grupo segmentado das abas de status**, abaixo da busca central,
    ao lado de Todas · Ativas · Pausadas · Com erro · Em revisão.
  - ⚠️ **GUARDA OBRIGATÓRIA ANTES DE REMOVER — o atalho sai, o DADO não pode sair.** A fase que
    implementar isto tem de **provar, por leitura do código**, que tudo o que a sub-aba "Histórico"
    alcançava continua alcançável pelo filtro de status (encerrados/inativos). Se "Histórico" for o
    **único** caminho para algum dado, **PARAR e perguntar ao usuário** — ele autorizou tirar o
    controle, não perder o conteúdo. O resultado dessa conferência vai na SUMMARY, dito com números.
  - ⚠️ Nenhuma sessão futura deve "restaurar" o "No ar | Histórico" achando que foi perdido por
    engano. **Foi removido de propósito, nesta data, por pedido escrito.**
- **LIST-24** — Os sinais de saúde ECF que hoje ocupam colunas (**Nota ECF "de 86"**, saúde do ML,
  `performance_score`, buybox, motivos) **migram para o painel de inspeção** (INSP-07) e para os
  chips (LIST-22) — não são apagados. **D-22 continua:** a nota aparece como "de 86" e nunca é
  renormalizada para 100.

### INSP — Painel de inspeção, rentabilidade e ações em lote

- **INSP-01** — Painel fixo à direita de **460px**, `z-index` acima do header, entrando com
  `transform .28s cubic-bezier(.2,.8,.2,1)`, sombra `-24px 0 64px rgba(0,0,0,0.6)`; fechado em
  `translateX(104%)`.
- **INSP-02** — Topo: "PUBLICAÇÃO · x de N", botões ↑ ↓ e ×.
- **INSP-03** — Identificação: tile 84px, chips (fase, tipo, catálogo, status), título 17px, MLB em
  mono.
- **INSP-04** — Caixa do motivo quando a publicação **não está ativa**, com **texto de ajuda por
  motivo** (ex.: "A foto principal precisa de fundo branco e sem texto. Ajuste no Publicador e
  republique."). Os motivos reais do sistema entram nesse mapa, não os do protótipo.
- **INSP-05** — Botões: **"Abrir no Mercado Livre"** (primário), **"Editar no Publicador"** e
  **"Pausar / Reativar"**, que troca o status na hora (otimista). Esta é a **única** escrita no ML
  que a tela ganha: exige a trava de conta do módulo, grava registro antes do HTTP, trata 5xx/rede
  como incerto e nunca reenvia por conta própria.
- **INSP-06** — **Rentabilidade por venda:** Preço de venda · Tarifa e frete ML (negativo) · Repasse
  estimado · Custo do produto (**× unidades na Fase 2**) · Lucro estimado + margem. A linha de lucro
  tem fundo tint verde ou vermelho. Com prejuízo, aparece a caixa "Este preço gera prejuízo" com o
  **preço mínimo para empatar** (≈ custo × preço ÷ repasse). **Reusa**
  `SimuladorVoceRecebe::calcular`, `DadosEfetivosService::custosDoProduto` e a tarifa já cacheada de
  `AnaliseAlavancasService` — **nenhum cálculo de margem novo é escrito**, e os dois lugares nunca
  divergem.
- **INSP-07** — Desempenho do painel: **Vendas · Visitas · Conversão**. ⚠️ `sold_quantity` é
  **acumulado da vida do anúncio** e a tela diz isso; `visitas` é **NULL em 100%** das 1.374.527
  linhas de `ml_acervo_metricas_diarias` e `visitas_30d` é camada cara rotativa — **sem valor, o
  painel diz "não medido"**, nunca mostra 0 nem estima. Neste bloco passam a morar também os sinais
  de saúde ECF (Nota ECF de 86, saúde do ML, performance, buybox, o checklist de sinais e a série de
  até 90 dias), reusando o endpoint **lazy** `detalheAnuncio` que já existe.
- **INSP-08** — Origem: Produto + SKU · Metodologia por extenso ("Fase 2 — Combo · 2 unidades por
  oferta") · Publicada por · Data completa.
- **INSP-09** — "Publicações deste produto": **todas as irmãs** (F1/F2 × tipo, com Catálogo quando
  houver) clicáveis, com status e preço, ordenadas fase → unidades → tipo, a atual em destaque
  (`#221D07`, peso 600).
- **INSP-10** — Ações em lote: barra flutuante embaixo (fundo `#1C1C1C`, borda `#333333`) com "N
  selecionadas · Pausar · Reativar · ×".
  ⚠️ **"Exportar CSV" FICA FORA, por decisão do usuário em 11/10/2026** (*"pode desconsiderar"*).
  Não é esquecimento nem limitação técnica: ele dispensou. O item está na tabela de fora-de-escopo.
  O checkbox do cabeçalho seleciona **todas as visíveis**. Pausar/Reativar em lote obedece à mesma trava e ao mesmo registro de INSP-05; sem o
  endpoint de escrita disponível para a conta, os botões ficam `disabled=` com explicação.
- **INSP-11** — Com o painel aberto, ↑/↓ e j/k movem a seleção e **o painel acompanha**; Esc fecha.
- **INSP-12** — **Aviso financeiro nunca depende só de cor:** sempre ícone + texto ("Prejuízo").

### REG — Melhorar sem regredir

- **REG-01** — Inventário escrito, página por página, do que as 11 páginas entregam hoje, conferido
  ao fim de **cada** fase (não só no fim da milestone).
- **REG-02** — O que o usuário acabou de ganhar **fica**: busca por SKU/nome/MLB, filtro "Em
  revisão", card "Quanto você recebe", acervo do recém-publicado, rodapé de uma ação só.
- **REG-03** — O editor (`Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/`) **não é
  tocado** além do estritamente necessário pelo header; qualquer necessidade ali vira item em
  `.planning/coordenacao/` antes de qualquer edição.
- **REG-04** — `App\Support\Publicador` e `MlPublicacaoService` **não mudam**.
- **REG-05** — Toda URL atual de `/mlb/anuncios` continua funcionando; nenhuma rota é removida nesta
  milestone.
- **REG-06** — **D23** preservado com o literal exato, e **D-05** preservado: nenhuma tela coleta em
  processo — "Atualizar agora" enfileira.

### GATE — Política de testes (aparência velha sai, comportamento fica)

- **GATE-01** — Gate que codifica a **aparência antiga** sai das telas redesenhadas, com justificativa
  **por asserção**, nomeando qual medida do README a torna impossível. Gate de **comportamento**
  fica, intacto.
- **GATE-02** — Nominalmente: de `publicador-entrada.test.js` saem o vocabulário fechado
  24/15/13/11px, o veto a `text-xs|sm|base|lg|xl`, o "só 400 e 700" e o veto a `bg-ecf-yellow`
  sólido — **apenas para os arquivos redesenhados**; os demais arquivos da lista seguem gateados.
  Ficam o veto a `@/Components/ui/select` e a `dangerouslySetInnerHTML`. De
  `estrutura-meus-anuncios.test.js` saem as 6 asserções de tipografia/peso/espaçamento; ficam **D-11**
  (nenhum `router.put/delete/patch`), **D-22** ("de 86", nunca `/100`) e as rotas das abas.
- **GATE-03** — Entra um gate **novo**, com o vocabulário **novo e fechado**, derivado do README:
  lista de tamanhos permitidos, pesos 400/500/600/700, exigência de `tabular-nums` nos arquivos de
  métrica, veto a `ecf-*`/`font-display`/`text-xs|sm|base|lg|xl` e veto a hex literal fora da camada
  de tokens (TOK-15).
- **GATE-04** — Baselines respeitadas: PHP `tests/{Unit,Feature}/Publicador` **1761 / 0**; JS
  **2210 / 2209 pass / 1 fail** (`estrutura-grade-glide`, pré-existente — **não corrigir**). Os 38
  testes de comportamento do módulo continuam verdes.
- **GATE-05** — `assert.match(tag, /disabled=/)`, nunca `/disabled/` (asserção vazia neste projeto).
- **GATE-06** — Gate de diff pelo **commit de partida** (`<inicial>..HEAD`), nunca da árvore (a
  árvore é compartilhada com outro dev).

### REND — Render real (a tela preta de 07/10)

- **REND-01** — Todo campo novo exibido tem teste de **render REAL** (esbuild + `react-dom/server`)
  com o campo **objeto, nulo e ausente**.
- **REND-02** — A saída não contém `[object Object]`, `NaN` nem `undefined`.
- **REND-03** — **Todos** os bundles são montados **antes** do primeiro `test()` no `node --test`.
- **REND-04** — Nenhuma variável de escopo do componente é usada dentro de `.map()`: flags e
  derivados são computados **no callback** (o Rollup elimina a variável no bundle de produção).
- **REND-05** — Âncoras de DOM são contrato: nenhum `id`/`data-*` usado por
  `Components/Publicador/destaque.js` ou por testes de gate é renomeado sem atualizar a lista
  correspondente.

### EXMP — Enfeite é exceção justificada

- **EXMP-01** — **Funcional é o padrão.** Enfeite só item a item, com justificativa escrita na
  SUMMARY da fase dizendo qual dado falta e o que o tornaria real.
- **EXMP-02** — Dado fictício mora **só** em `dadosDeExemplo.js` (só constantes, nenhum import,
  nenhuma função) e **todo** bloco que o consome carrega **UMA** pilha `SeloExemplo` — por bloco,
  nunca por número, e nunca misturando real e exemplo dentro do mesmo número.
- **EXMP-03** — Botão sem destino real carrega o `title` de exemplo (`TITULO_BOTAO_EXEMPLO`) — nunca
  um clique que não faz nada e não explica.
- **EXMP-04** — Bloco **inteiro** que só poderia nascer decorativo **não nasce**: a fase espera. É a
  razão de a tela Desempenho ficar registrada e não planejada.

### DESEMP — Desempenho (registrada, NÃO planejada agora)

- **DESEMP-01** — Cabeçalho: H1 "Desempenho" + "Vendas e produção de publicações no Mercado Livre ·
  {período}". À direita, segmented **Hoje / 7 dias / 30 dias**; nesta tela o período vale para a
  página toda (é a única tela com filtro global).
- **DESEMP-02** — Faixa de **4 KPIs em um único card com divisórias** (não em 4 cards separados):
  Publicações criadas · Vendas (valor em amarelo, "un.") · Publicações com venda ("de N") · Criativos
  com IA (packs). Cada um com valor 30px, delta ↑/↓ colorido + "vs período anterior" e sparkline
  96×34 (linha amarela 1.6px + área `rgba(250,204,21,0.10)`).
- **DESEMP-03** — Vendas por dia/hora: barras empilhadas de 300px, Fase 1 `#D4D4D8` embaixo e Fase 2
  `#FACC15` em cima com raio 3px no topo; eixo Y com 4 ticks e grid `#1C1C1C`; eixo X com 5 a 7
  labels; no hover a barra destaca (as outras a 0.4) e aparece tooltip com data, total, F1 e F2;
  subtítulo "N vendas · x% vieram de combos (Fase 2)".
- **DESEMP-04** — Grid `1fr | 420px`: **"Publicações que mais venderam"** (#, Publicação com tile +
  título + MLB, Metodologia, Participação com barra + %, Vendas; barras na cor da fase) e **"Fase 1 ×
  Fase 2"** (barra 100% dividida com percentuais, dois blocos comparando vendas e publicações criadas
  — o da Fase 2 com fundo `#14120A` — e o atalho "N produtos ainda sem combo · Criar combos →").
- **DESEMP-05** — ⚠️ **Bloqueio de dado, medido:** "vendas por dia empilhado por fase", "Visitas" e
  "Conversão" **não têm fonte** — `sold_quantity` é acumulado da vida do anúncio, a série de
  `ml_acervo_metricas_diarias` é esparsa (média de 1,3 dias por anúncio, máximo 89), `visitas` é NULL
  em 100% das 1.374.527 linhas e não existe tabela de pedidos (`mlb_sync_vendas_logs` tem 6 linhas).
- **DESEMP-06** — O que **já é real** e pode nascer funcional nesta tela: "Publicações criadas no
  período", "Criativos com IA (packs)" e "Fase 1 × Fase 2 em publicações criadas".
- **DESEMP-07** — A fase **não começa** sem decisão do usuário sobre coletar vendas por dia (API de
  pedidos do Mercado Livre): se coletar, qual janela, com que custo de chamada e onde persistir.
- **DESEMP-08** — Quando a fase existir, **nada nesta tela nasce decorativo** (decisão do usuário):
  nenhum gráfico de vendas com série sintética, nenhuma conversão calculada sobre visitas nulas.

---

## Fora de escopo desta milestone (não mapear em nenhuma fase)

- Refazer o **editor** do Publicador, a **Mesa**, as **Alavancas**, **Tarefas**, **Publicação em
  lote**, **Produtos**, **Produto** ou **Configurações** além do header que elas passam a consumir.
- Trocar os tokens `ecf-*` ou a fonte do **resto do sistema** (fora do módulo Publicador).
- Barra lateral de módulo (recusada em 10/10).
- Alterar `App\Support\Publicador`, `MlPublicacaoService` ou o gate de conta travada.
- Coletar vendas por dia pela API de pedidos do ML (pré-requisito da DESEMP, decisão do usuário).
- Remover o assistente antigo / o Em massa (Fase 174, já planejada e adiada).
- Corrigir a falha JS pré-existente `estrutura-grade-glide`.
- Deploy e push.

---

## Decisões do usuário — RESPONDIDAS em 11/10/2026

As quatro estavam em aberto quando este documento nasceu. O usuário respondeu as quatro no mesmo dia.
**Nenhuma segue pendente.** Os textos abaixo são o registro; os requisitos citados já foram ajustados.

1. **A aba "Desempenho" NÃO aparece por enquanto.** Palavras dele: *"não mostre o link da tela
   desempenho por enquanto"*. → **HEAD-09** lista quatro abas. É **desvio deliberado da fidelidade**
   ao README (que pede cinco), pela régua dele de que nada nasce decorativo. A aba volta com a Fase
   182. **A minha recomendação era outra** (mostrar a aba levando a uma tela honesta sobre o que não é
   medido) e **ele decidiu diferente** — fica registrado para ninguém reabrir achando que foi omissão.
2. **"No ar | Histórico" deixa de existir; "Rascunhos" entra no grupo das abas de status.** Palavras
   dele: *"'No ar e historico' não precisa existir, ja o rascunho adicione integrado no mesmo grupo
   de botões que tem abaixo da barra de busca"*. → **LIST-25** (novo), e **LIST-23** passou a abrir
   exceção para ele.
   ⚠️ É a **única remoção de funcionalidade existente** autorizada nesta milestone, contra a
   regra-mestra "melhorar sem regredir". **A guarda do LIST-25 é obrigatória:** o atalho sai, o dado
   não — se "Histórico" for o único caminho para algum dado, a fase **para e pergunta**.
3. **"Exportar CSV" fica fora.** Palavras dele: *"pode desconsiderar"*. → **INSP-10** ajustado; o item
   foi para a tabela de fora-de-escopo **por dispensa do usuário**, não por limitação técnica.
4. **"Publicação com prejuízo" só depois da Fase 181.** Ele concordou com a recomendação. →
   **VG-12** mantido: até a 181 a ação **não aparece**, e a dependência 179 → 181 está marcada no
   roadmap para a Visão Geral não ser lida como incompleta por isso.
