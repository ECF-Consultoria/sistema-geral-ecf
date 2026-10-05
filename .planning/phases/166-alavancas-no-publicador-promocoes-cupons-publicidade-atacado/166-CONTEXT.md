# Fase 166: Alavancas no Publicador — promoções, cupons, publicidade e atacado — Contexto

**Coletado em:** 2026-10-04
**Status:** pronto para o planejamento

<domain>
## Limite da fase

No Publicador interno (`/mlb/anuncios`), depois de escolher a empresa, a equipe passa a ter duas
áreas: **Publicar** (tudo o que existe hoje) e **Alavancas**, onde vê, analisa e — onde a API do
Mercado Livre permite e a conta está liberada — cria e altera as alavancas de venda da conta do
cliente: Central de promoções, cupons do vendedor, publicidade (só leitura nesta fase) e atacado
(preço por quantidade). Afiliados está fora (não há API para o vendedor).

A pesquisa de API que sustenta esta fase está em `166-PESQUISA-API.md` (documentação oficial lida
em 2026-10-04).

</domain>

<decisions>
## Decisões de implementação

### Entrada
- **D-01 — Barra "Publicar | Alavancas" na página da empresa.** Na tela da empresa
  (`mlb.anuncios.publicador.produtos`, `/mlb/anuncios/publicador/empresas/{conta}`), uma barra no topo
  com Publicar e Alavancas. Dentro de Publicar fica exatamente o que existe hoje, inclusive a barra
  `ModoAnuncioTabs` (Meus Anúncios | Individual | Em massa | Histórico). Troca a qualquer momento, sem
  voltar à lista de empresas. Vale para as duas âncoras (`empresa-N` e `company-N`): Alavancas usa só
  o token da conta, não depende de `Company` (diferente de Meus Anúncios/Em massa/Histórico, D23 da 164).
- **D-02 — Alavancas abre num panorama e depois as abas.** No topo, um resumo da conta: convites de
  promoção abertos com o prazo de cada um (`deadline_date`), cupons ativos com saldo
  (`remaining_budget`), campanhas de publicidade com o essencial e créditos de bonificação, e se a
  conta tem atacado liberado. Embaixo, as abas **Promoções | Cupons | Publicidade | Atacado**. O
  histórico de escritas (D-05) fica acessível dentro de Alavancas.

### Escrita na conta do cliente
- **D-03 — Trava própria das Alavancas, fail-closed.** Lista de contas liberadas só para Alavancas,
  separada da trava de publicação (`publicador.contas_liberadas` / `ContasLiberadas`, D21 da 164), no
  mesmo formato: por âncora (`companies` e `mlb_empresas`), vazia = ninguém, começando pela #459.
  Liberar promoções para um cliente NÃO libera a publicação de anúncios para ele, e vice-versa.
  Conta fora da lista: tudo de leitura e análise funciona; os botões de escrita ficam desligados com o
  motivo na tela; e o SERVIDOR recusa a escrita (não basta esconder o botão). A trava vale em toda
  escrita (POST/PUT/DELETE no ML), como o CR-B01 da 164.
- **D-04 — Confirmação com resumo antes de cada escrita.** Uma janela mostra o que vai mudar — conta,
  produto, preço atual → preço na promoção, % de desconto, prazo, quanto o ML banca, quanto a loja
  recebe — e só escreve depois do "Confirmar". Ação em vários produtos de uma vez confirma o lote,
  listando os produtos.
- **D-05 — Histórico de escritas, com tela.** Tabela nova com cada escrita: quem, quando, conta
  (âncora), alavanca, ação, o que foi enviado e a resposta crua do ML, e o resultado. Na tela, um
  histórico por empresa. A resposta do ML é guardada mesmo quando dá erro (regra do usuário para o
  Publicador: payload e resposta ficam). Desenho da tabela POR ESCRITO no plano antes da migration
  (CLAUDE.md, disciplina 2) — e as armadilhas de MariaDB de `.planning/learnings/desempenho-bonificacao.md` §6.
- **D-06 — Acesso: só admins.** Como o resto de `/mlb/anuncios` (grupo `role:admin`, D17 da 164).

### O que escreve nesta fase
- **D-07 — Promoções, as quatro escritas:**
  1. **Convites do ML** — inscrever, alterar preço e tirar produto das campanhas que o ML oferece
     (`DEAL`, `MARKETPLACE_CAMPAIGN`, `DOD`, `LIGHTNING`, `VOLUME`, `PRE_NEGOTIATED`, `SMART`,
     `PRICE_MATCHING`, `UNHEALTHY_STOCK`), respeitando a regra de cada tipo (DOD e LIGHTNING não se
     editam: tirar e inscrever de novo; aceitar o que é "usuário aceita" sem inventar preço).
  2. **Desconto individual** (`PRICE_DISCOUNT`) — criar e remover; 5% a <80%, até 14 dias, preço para
     Mercado Pontos 3–6 opcional com a diferença mínima do ML.
  3. **Campanha do vendedor** (`SELLER_CAMPAIGN`, `FLEXIBLE_PERCENTAGE`, até 14 dias) — criar, alterar,
     excluir e pôr/tirar produtos; também o leve X pague Y criado pelo vendedor (`VOLUME`).
  4. **Bloqueio das campanhas automáticas** — lista de exclusão da conta e por produto.
- **D-08 — Cupons do vendedor** (`SELLER_COUPON_CAMPAIGN`, só MLB): criar (valor fixo ou %, com ou
  sem código, compra mínima, teto no %, orçamento, até 31 dias), alterar (orçamento só aumenta) e
  excluir; mostrar o saldo dos ativos.
- **D-09 — Publicidade só leitura.** Campanhas, anúncios/Ad Groups, métricas (investimento, vendas,
  ACOS) e bonificações, pelos endpoints da documentação atual (`api-version: 2`, `.../campaigns/search`,
  `.../ads/search`, ad groups, `/advertising/advertisers/bonifications`) — NUNCA pelos legados
  desligados em 2026-05-27. Criar/pausar/mover campanha fica fora até: (a) a permissão "Advertising" do
  app ECF ser ligada no DevCenter e (b) a escrita ser provada na #459 (não na Bymobille, que é cliente).
  - *Atualização da pesquisa (2026-10-04):* a leitura por anúncio citada acima (`.../ads/search`) foi
    removida pelo Mercado Livre em 30/05/2026 ("substituída pelas métricas de Ad Group"); a fase lê campanhas,
    Ad Groups (`.../product_ads/ad_groups/search`, `api-version: 2`) e bonificações (`166-RESEARCH.md` §7). A decisão
    não muda: publicidade continua SÓ LEITURA nesta fase.
- **D-10 — Atacado: ver e editar.** Mostrar se a conta tem a tag `business`; se tiver, ver e editar até
  5 faixas no formato **% B2B** (`POST /items/{id}/prices/price-per-quantity` com `x-version`), nunca
  pelo absoluto (`/prices/standard/quantity`, descontinuado para B2B em 2026-10-27). Sem `business`, a
  aba explica que o ML libera por convite.

### O que é "analisar"
- **D-11 — Números por produto em promoção:** preço atual → preço na promoção, % de desconto, quanto o
  ML banca (cofinanciada/boost) e **quanto a loja recebe** no preço da promoção — tarifa
  (`/sites/MLB/listing_prices`) + frete, o mesmo cálculo do "Quanto você recebe" do Publicador
  (`SimuladorVoceRecebe`). Margem com custo só quando o produto vem do Portal e a Precificação tem
  custo (`EstruturaPrecificacao.custo`); sem custo, não pede para digitar.
- **D-12 — Alertas simples, sem ranking.** Marcar o que pede atenção: convite que vence em poucos dias;
  promoção em que o recebido fica abaixo do recebido no preço normal menos X% (X em config, valor
  inicial a critério do planejamento); produto sem estoque suficiente para a oferta (DOD/LIGHTNING
  pedem estoque mínimo); reputação que bloqueia desconto/campanha/cupom (exigem reputação verde). A
  decisão fica com a pessoa.

### Visual
- **D-13 — Padrão atual do Publicador.** Mesmo design das telas da empresa e do editor em 3 etapas:
  cartões escuros `ecf-card`, campos "com cara de campo", tipografia 24/15/13/11 e pesos 400/700, um
  amarelo sólido por tela (o próximo passo). Nada de contador de progresso ("N/8", "Completo", "Faltam
  N") — recusado pelo usuário em 04/10. Conferência visual com capturas antes de subir (learnings do
  Publicador §7).

### A critério do planejamento (Claude's Discretion)
- Nomes de rotas e controller (seguindo `mlb.anuncios.publicador.*` e controller enxuto), onde mora o
  serviço das alavancas e a divisão em serviços por alavanca.
- Cache e frequência das leituras (leitura sob demanda; paginação de itens por `search_after`, que
  expira em 5 min); limites de chamadas ao calcular "quanto recebe" para muitos produtos.
- Como listar os produtos da conta para as ações que não partem de um convite (ex.: escolher produtos
  para um desconto individual ou campanha do vendedor), inclusive para `MlbEmpresa` sem `Company`.
- Valor inicial do X do alerta de recebido e o que é "poucos dias" para o convite.
- Nome da chave de config/env da trava das Alavancas.

</decisions>

<canonical_refs>
## Referências canônicas

**Os agentes seguintes DEVEM ler estas antes de planejar ou implementar.**

### API do Mercado Livre
- `.planning/phases/166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado/166-PESQUISA-API.md` —
  endpoints, regras e prazos de cada alavanca (lida da documentação oficial em 2026-10-04). A
  documentação dá 403 ao WebFetch; para reler, `curl -A "<User-Agent de navegador>"` em
  `https://developers.mercadolivre.com.br/pt_br/<página>` (lista de páginas no fim do arquivo).

### Publicador (onde a fase entra)
- `.planning/phases/164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu/164-CONTEXT.md` —
  D12–D27: entrada por programa, só admins, trava por conta (D21), conferência local em conta não
  liberada (D26), âncoras `Company`/`MlbEmpresa`.
- `.planning/learnings/publicador-ml.md` — §3 (conta de cliente: nunca escrever sem liberação; a #459 é
  loja real), §9 (conta e trava; `contaOuNula()` × `conta()`), §11 (análise de 04/10).
- `.planning/learnings/desempenho-bonificacao.md` §6 — armadilhas de MariaDB que o SQLite dos testes não pega.

### Código
- `routes/mlb_anuncios.php` — grupo `role:admin`, rotas `publicador.*` e throttles.
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — `produtos()` (tela da empresa, onde entra a barra D-01).
- `app/Services/Publicador/ProgramasPublicadorService.php` — resolve `{conta}` (`empresa-N`/`company-N`).
- `resources/js/Pages/Mlb/Publicador/Produtos.jsx` e `resources/js/Pages/Mlb/ModoAnuncioTabs.jsx` — tela da empresa e a barra de modos atual.
- `app/Support/Publicador/ContasLiberadas.php` e `config/publicador.php` (`contas_liberadas`) — modelo da trava D-03.
- `app/Services/Publicador/ClienteMlPublicador.php` — `daConta()` (chamada autenticada com refresh) e `publico()`.
- `app/Support/Publicador/Validacao/SimuladorVoceRecebe.php` e `EditorRascunhoService::simular()` — cálculo do "quanto recebe" (D-11).
- `app/Models/EstruturaPrecificacao.php` — `custo` da Precificação do Portal (margem em D-11).
- `app/Services/Sugadores/MercadoLivreAdsService.php` — leitura de Ads já existente (anunciante, campanhas,
  ad groups). ⚠️ `ENDPOINT_ADS_ITEMS` (`/advertising/advertisers/{id}/product_ads/items`) está na lista de
  endpoints desligados em 2026-05-27 — não reaproveitar esse caminho.
- `.planning/todos/pending/270626-resume-44-01-smoke-bymobille.md` — o que falta para provar a escrita de Ads (fora desta fase, D-09).

</canonical_refs>

<code_context>
## O que já existe no código

### Reaproveitável
- `ClienteMlPublicador::daConta(ContaMercadoLivre, ...)`: chamadas autenticadas na conta (as duas âncoras), com refresh de token.
- `ProgramasPublicadorService`: resolve a empresa (`empresa-N`/`company-N`), programa e conta ML; 404 para arquivada/sem programa.
- `ContasLiberadas`: padrão de trava por âncora, fail-closed — modelo para a trava das Alavancas.
- `SimuladorVoceRecebe` + `listing_prices` + frete: o "quanto você recebe" do editor.
- `MercadoLivreAdsService` (Sugadores): `discoverAdvertiser()` com cache em `MlAdvertiser`, rate limit por vendedor e backoff por status — reaproveitável para a leitura de Ads, menos o endpoint desligado.
- Componentes visuais do Publicador: `Mesa/comum.jsx` (`Campo`, `Secao`, `CAMPO`/`SELECT`), `Mesa/botoes.jsx` (o único amarelo), `SeloConta`, `AvisoContaTravada`.

### Padrões estabelecidos
- Trava no servidor em toda escrita, nunca só na tela (CR-B01 da 164).
- Guardar payload e resposta crua do ML (`pub_publicacoes`, `pub_validacoes`).
- Testes do ML com `Http::fake` de closures que leem estado do teste (learnings §5); fixtures em `tests/fixtures-ml/` (pasta sem homônima, learnings §2).
- Conferência visual em SQLite isolado com Puppeteer (learnings §7; roteiro em `C:/tmp/ecf-publicador-melhoria-visual/analise/capturar.mjs`).

### Pontos de integração
- Página da empresa (`Produtos.jsx`) ganha a barra Publicar | Alavancas; Alavancas é página(s) Inertia nova(s) sob `mlb.anuncios.publicador.*`.
- `config/publicador.php` ganha a lista de contas liberadas das Alavancas.
- Migration nova só CRIA tabela (histórico D-05); nenhuma tabela existente com dado muda.

</code_context>

<specifics>
## Ideias específicas do usuário

- "um módulo em Publicador onde podemos ver, analisar e os possíveis criar/alterar"
- "selecionamos a empresa, vamos ter opção de publicar e opção de Alavancas"
- Alavancas citadas pelo usuário: Publicidade (campanha de lançamento), Central de promoções, Atacado
  (preço por quantidade), Afiliados, cupom.

</specifics>

<deferred>
## Ideias adiadas

- **Resultado das promoções** (vendas e faturamento antes × durante) — outra fase; exige cruzar pedidos por período e produto.
- **Escrita em publicidade** (criar campanha, orçamento, ACOS, pausar/mover anúncio) e **"campanha de
  lançamento"** automática ao publicar pelo Publicador — depois da permissão "Advertising" e da prova na #459.
- **Notificações do ML** (candidatos, ofertas, `items prices`) para atualizar sozinho — nesta fase a leitura é sob demanda.
- **Ranking automático** de quais produtos mais valem entrar em cada promoção — precisa de regra de negócio.
- **Afiliados** — sem API para o vendedor.
- **Atacado B2C** (todo comprador) — hoje só pneus, sem data no MLB.
- **Acesso para quem não é admin** (`permission:mlb.anunciar`) — segue a decisão D17 da 164.
- **Achado lateral, fora da fase:** a listagem de anúncios dos Sugadores (`MercadoLivreAdsService::listAds`)
  usa um endpoint desligado em 2026-05-27 e pode estar falhando desde então — conferir em produção e
  corrigir à parte.

</deferred>

---

*Fase: 166-alavancas-no-publicador-promocoes-cupons-publicidade-atacado*
*Contexto coletado: 2026-10-04*
