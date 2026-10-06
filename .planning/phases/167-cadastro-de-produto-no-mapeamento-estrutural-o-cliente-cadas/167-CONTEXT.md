# Phase 167: Cadastro de Produto no Mapeamento Estrutural - Context

**Gathered:** 2026-10-05
**Status:** Ready for planning

<domain>
## Phase Boundary

A aba **Produtos** da planilha `3Planejamento_Estrutural_ECF.xlsx` (a que o Emerson apresentou na reunião da
Incubadora de 05/10/2026) vira sistema: um submódulo novo **Produtos** no Mapeamento Estrutural do Portal do
Cliente, onde o cliente — e a equipe, pela entrada de equipe no portal — cadastra o catálogo dele uma vez.

Esta fase entrega:
- o produto com suas variações (código, valor da variação, volumes, peso, custo), família, ambiente(s) e
  categoria real do Mercado Livre;
- a tela de cadastro rápido (tabela editável) e a importação por planilha-modelo;
- a criação automática da **oferta simples** na Lista SKUs, uma por variação, ligada ao produto;
- o custo passando a morar no produto (a Precificação lê de lá);
- por variação: logística provável (Full elegível / ME2 / ME1), peso cubado e frete ME2.

Fica FORA (fases seguintes): gerar combo/kit/combit, logística de kits, grade de margem e tarifa por categoria
na Precificação, cronograma e checklist de alavancas, e fotos/vídeo/ficha técnica/dados fiscais do produto.

A ideia que orienta tudo, na palavra da reunião: "eu tenho que cadastrar o produto uma vez, e tenho que cadastrar
um monte de vezes anúncio". Na planilha, a aba Produtos é onde o cliente cadastra; a aba Planejamento é a
"identificação da oferta" — o que o usuário chamou de base do Publicador.

</domain>

<decisions>
## Implementation Decisions

### Quem cadastra e onde
- **D-01:** Produtos é submódulo do Mapeamento Estrutural e é ancorado em **Company**, como todo o `estrutura_*`
  (empresa sempre do `PortalContexto`, nunca do request). Empresa polo sem Company (Polos, e hoje a Incubadora)
  fica de fora: o cliente da Incubadora entra "como cliente novo" e ganha Company + Portal no onboarding. Ter
  Company e acesso ao Portal para as empresas da Incubadora é pré-condição operacional, não código desta fase.
- **D-02:** Cliente (pelo Portal) **e** equipe (pela entrada de equipe `/companies/{company}/portal`, permissão
  `core.onboarding` + carteira em `PortalEquipeService::podeEntrar()`) cadastram e editam. O activity log grava a
  origem `cliente` | `interno`, como no resto do Mapeamento (o `causer_id` não distingue — learnings
  `portal-do-cliente.md` §12).

### Modelo do produto
- **D-03:** O **produto** corresponde ao "Grupo (anúncio)" da planilha e guarda: nome, família, ambiente(s) e
  categoria do ML. Cada **variação** corresponde à "Ref" da planilha (ex.: `1014-1`, `1014-2`) e guarda: código
  (SKU), o valor da variação (eixo + valor, ex.: Cor = Natural), os **volumes** (lista de caixas, cada uma com
  C×L×A em cm e peso em kg), o peso total (soma dos volumes) e o custo.
- **D-04:** Medidas, peso e custo são **por variação** (na planilha há mesa cujas cores pesam diferente). Ao criar
  uma variação nova, os dados da primeira são copiados para a pessoa só ajustar o que muda.
- **D-05:** Família e ambiente são **listas da própria empresa**: criadas uma vez e depois só escolhidas (acaba o
  "Sala estar" × "Sala Estar" da planilha). Ambiente admite vários por produto ("Sala Jantar / Sala Estar"). Serve
  a qualquer segmento (móveis, peças, pet) porque as combinações só acontecem dentro do catálogo da mesma empresa.
- **D-06:** Categoria = **categoria real do ML** (grava o `category_id`), sugerida pelo nome do produto com o
  caminho inteiro da árvore, como o Publicador já faz. Motivo: na fase seguinte a tarifa de cada categoria vem da
  API (`listing_prices`) — na planilha só 1 das 24 categorias tinha tarifa confirmada.
- **D-07:** **Nome "família" — atenção.** Na planilha, Família = linha de design (Farmhouse, Palhinha Slim). No
  código existente, "família" (Precificação do Onboarding, `agruparFamilias` em `resources/js/lib/precificacaoProdutos.js`)
  quer dizer "o mesmo produto em várias cores" — que na planilha é Grupo + Variação. O campo novo segue a planilha.
  Também não colidir com `pub_produtos` (o "produto" do Publicador é, na prática, uma oferta a publicar).

### Produto ↔ oferta
- **D-08:** Cadastrar um produto **cria sozinho a oferta simples** (fase `simples`) na Lista SKUs, **uma por
  variação** — como a planilha faz (Cristaleira 1014 com 2 cores → ofertas A002-V1 e A002-V2) — ligada à
  variação. Combo, kit e combit não nascem nesta fase.
- **D-09:** As ofertas que já existem (criadas à mão ou importadas do ML, ex.: 500 na #131) **ficam como estão**:
  sem casamento por SKU, sem criar produto para elas. Produto novo com o SKU de uma oferta antiga gera outra
  oferta — SKU repetido, que a Lista SKUs já aceita e avisa (ADR PORTAL-01).
- **D-10:** O **custo mora na variação do produto**. A oferta simples ligada usa esse custo (a Precificação mostra
  o custo vindo do produto); combo/kit/combit continuam somando os componentes (ADR PORTAL-02, "custo pelos
  componentes"). Oferta sem produto segue com o custo digitado na Precificação, como hoje.
- **D-11:** Ligar oferta → variação altera `estrutura_ofertas`, tabela com dado em produção — por isso esta fase é
  GSD completa (CLAUDE.md): baseline de testes antes de mexer, desenho da migration por escrito antes de existir,
  armadilhas de MariaDB (learnings `desempenho-bonificacao.md` §6), VERIFICATION no fim.

### Como os produtos entram
- **D-12:** A **tela é o caminho principal** ("acho muito mais eficiente fazer os cadastros na tela, no sistema
  mesmo" — o usuário). Formato: **tabela editável** no jeito da aba Produtos — uma linha por variação, Tab/Enter
  para andar, colar várias linhas copiadas do Excel, família/ambiente/categoria como escolha na célula, e a
  logística e o frete aparecendo na própria linha. Reaproveitar o `SpreadsheetGrid`. Tem de ser rápida para
  cadastrar ~70 produtos de uma vez.
- **D-13:** **Planilha-modelo**: o cliente baixa o modelo (colunas da aba Produtos), preenche fora e importa no
  Portal; o sistema mostra a prévia antes de gravar.
- **D-14:** Reimportar = **acrescentar e atualizar**, casando pelo código da variação; nada é apagado. A prévia
  separa novos / atualizados / sem mudança (mesmo padrão da colagem de anúncios do Mapeamento).

### Envio pelas dimensões
- **D-15:** Assim que medidas e peso estão preenchidos, cada variação mostra **logística provável**, **peso cubado**
  e **frete ME2**. Regras da planilha (aba Parâmetros), aplicadas ao pacote:
  - peso cubado = C×L×A ÷ 6000; só vale acima de 5 kg cubados (peso faturado = maior entre real e cubado);
  - **ME2**: peso real ≤ 30 kg, soma dos lados ≤ 200 cm, maior lado ≤ 100 cm;
  - **Full elegível**: cabe no ME2 **e** peso real ≤ 20 kg **e** maior lado ≤ 80 cm;
  - fora do ME2 → **ME1** (frete pela tabela da transportadora do cliente; não calculado nesta fase);
  - sem medidas → "Pendente: completar cadastro".
- **D-16:** Frete ME2 vem da **API do ML** com a conta do cliente (`GET /users/{seller_id}/shipping_options/free`,
  o mesmo cálculo do Publicador, valor de `coverage.all_country.list_cost` — reflete a reputação dele). Sem conta
  do ML conectada, usa a **tabela da ECF** (aba "Frete ML Verde" da planilha) e mostra que é estimativa.
- **D-17:** Produto em **vários volumes** é **empilhado num pacote** para o cálculo: maior comprimento, maior
  largura, alturas somadas, pesos somados (Cristaleira 1015: 186×43×12 + 97×42×12 → 186×43×24, 39,9 kg → ME1).
- **D-18:** Logística e frete de **kits e combos** (pacote juntando produtos diferentes) ficam para a fase de
  geração das ofertas.

### Decisões depois da pesquisa (05/10, perguntas abertas do 167-RESEARCH)
- **D-19:** O frete ME2 calculado no produto é **só exibido** nesta fase: não preenche o `frete_*` da Precificação
  e não muda o preço efetivo que o Publicador herda. A fase de preço decide como usar.
- **D-20:** O **eixo** da variação é **lista fechada** — Cor, Tamanho, Voltagem, Material, Sabor, Outro (a mesma
  `VARIACAO_TIPOS` do Onboarding), para virar o atributo de variação do ML ao publicar. O **valor** (Natural,
  Preto) é livre. A importação aceita também a coluna "Variação" ordinal da planilha real (`1`, `2`, `única`).
- **D-21:** **Entrada do Mapeamento:** empresa sem ofertas antigas, ou que já tem produtos, entra em **Produtos**;
  empresa que já trabalha pela Lista SKUs (ofertas sem produto, ex.: as 500 importadas da #131) continua entrando
  na **Lista SKUs**.
- **D-22:** **Excluir variação cuja oferta tem anúncios:** igual à Lista SKUs — confirmação mostrando quantos
  anúncios; os anúncios voltam para a área de espera e o item do Publicador fica solto (D27 da Fase 164). Só
  bloqueia quando a oferta é componente de combo/kit/combit.

### Revisão no checkpoint do 167-17 (06/10) — substitui o FORMATO do D-12
- **D-23:** **Nada de planilha dentro do sistema para o cadastro de produto.** O usuário reprovou a tabela tipo
  planilha (grade com colar do Excel, Tab entre células, menu "Planilha") na conferência visual: "eu disse que não
  queria uma planilha dentro do sistema pra esse caso". "Na tela, no sistema mesmo" (D-12) quer dizer **formulário**,
  não grade. No computador vale o mesmo desenho do celular (167-16): **lista de produtos em cartões + ficha do
  produto** (painel lateral) com as variações e "Salvar produto". A grade sai da tela de Produtos.
- **D-24:** A **importação por arquivo continua** (D-13/D-14): baixar o modelo, preencher FORA do sistema e
  importar com prévia. Dentro do sistema só aparece a prévia do que vai entrar.

### Claude's Discretion
- **Preço usado para cotar o frete.** O frete do ML depende da faixa de preço, e o preço sai do custo + frete.
  Estimar o preço pelo custo da variação com os parâmetros da Precificação da empresa
  (`estrutura_precificacao_parametros`) e avisar quando ele cai perto/abaixo do limite de frete grátis
  obrigatório — esse limite NUNCA fica fixo no código (hoje R$ 79; regra do Publicador, learnings `publicador-ml.md`).
  A planilha supôs a faixa "a partir de R$ 200" e alertava abaixo disso.
- **Onde ficam as regras Full/ME2/cubagem e a tabela de frete reserva:** são regras do ML, iguais para todos —
  configuração global (não por empresa). Formato e atualização a critério do planejamento.
- **Posição do submódulo no menu:** proposta na conversa de 05/10 (sem objeção) como o primeiro, antes da Lista
  SKUs: Produtos → Lista SKUs → Precificação → Anúncios → Planejamento → Mapeamento (`ModulosPortal`).
- **Produto/variação alterado ou excluído:** o que acontece com a oferta simples ligada (sincronizar nome/SKU;
  bloquear exclusão quando a oferta tem anúncios ou entra em combo — `restrict` do PORTAL-01 — ou soltar o
  vínculo, como o D27 da Fase 164). Seguir os precedentes.
- **"Produto completo":** mostrar o que falta por linha (sem medidas, sem custo, sem categoria, ME1 sem frete),
  no espírito da coluna "Alertas" da planilha.
- **Formato do modelo para download** (.xlsx com PhpSpreadsheet, já no projeto) e como os volumes viram colunas.
- **Cache/lote das consultas de frete** (limite por minuto da conta — padrão `ClienteMlPublicador`).
- **Nomes de tabelas e colunas** (prefixo `estrutura_`, sem `enum`, índices com nome curto).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Mapeamento Estrutural (onde a fase mora)
- `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md` — schema `estrutura_*`: oferta identificada pelo `id`
  (SKU não é chave, pode repetir), fases `simples/combo/kit/combit`, componentes com quantidade e `restrict`,
  vocabulários travados sem `enum`, autoria cliente/equipe.
- `.planning/adrs/PORTAL-02-precificacao-do-mapeamento.md` — custo por oferta, "custo pelos componentes", preço
  calculado (nunca gravado), parâmetros por empresa. A D-10 desta fase muda a origem do custo da oferta simples.
- `.planning/adrs/PORTAL-03-anunciar-do-mapeamento.md` — contexto de como o Mapeamento alimentava a publicação
  (hoje o Anunciar saiu do Portal; a publicação é do Publicador interno).
- `.planning/learnings/portal-do-cliente.md` §12 (autoria cliente × equipe), §27–30 (o módulo, os submódulos, a
  Precificação e o frete em branco).
- `.planning/learnings/precificacao-onboarding-duas-telas.md` §3 — SKU repetido ("Não tenho") colapsando produtos.

### Publicador (consome o Mapeamento)
- `.planning/phases/164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu/164-CONTEXT.md` — D15 (empresa
  polo sem Company; 535 de 539 do Polos), D16 ("Sincronizar do Portal" herda título e preço da oferta), D17 (só
  admin), D27 (oferta apagada → item do Publicador fica solto).
- `.planning/learnings/publicador-ml.md` — frete ME2 por `shipping_options/free`, frete grátis obrigatório
  (limite nunca no código), `listing_prices`.

### Banco e ambiente
- `.planning/learnings/desempenho-bonificacao.md` §6 — armadilhas de MariaDB que o SQLite dos testes não pega.
- `.planning/learnings/autoloader-compartilhado-entre-worktrees.md` — testes em worktree rodando contra a árvore errada.
- `.planning/learnings/verificacao-visual-local.md` — conferência visual local.
- `.planning/learnings/gates-do-gsd-em-projeto-pt-br.md` — gates do GSD neste projeto.

### Fonte da fase (FORA do repositório — não commitar)
- `C:/xampp/htdocs/ecf_admin/3Planejamento_Estrutural_ECF.xlsx` — a planilha do Emerson. **Tem catálogo e custos
  reais de cliente: ler localmente, nunca copiar para o repo nem citar custos em documento commitado.** Abas:
  Produtos, Planejamento, Cronograma, Parâmetros, Frete ML Verde, Resumo.
- `C:/xampp/htdocs/ecf_admin/REUNIAO_INCUBADORA.docx` — transcrição da reunião de 05/10/2026.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/js/Components/SpreadsheetGrid.jsx` (1.084 linhas): tabela editável já usada na Planilha de Produtos
  do Onboarding (`resources/js/Pages/Mlb/ImplementacaoPublica.jsx`, `PRODUTOS_COLS`) — grava no Enter/Tab/blur,
  célula de escolha, colunas calculadas, popup de texto longo que salva ao fechar.
- `app/Services/Publicador/CategoriaBuscaService.php` e `app/Services/Incubadora/Publicador/CategoriaSugestaoService.php`
  — do nome do produto às categorias do ML com o caminho inteiro (app token; cache `ml_meta_categoria_{id}`
  compartilhado com `MlCatalogoMetaService`).
- `app/Services/Publicador/EditorRascunhoService.php` (`dimensoes()`, consultas a `shipping_options/free`) e
  `app/Services/Publicador/ClienteMlPublicador.php` (`daConta`, `publico`) — frete ME2 e tarifa reais pela API.
  `app/Services/Mlb/Publicacao/MlFreteService.php` é a versão antiga do wizard.
- `app/Services/Portal/Estrutura/PrecificacaoEstrutura.php` + `EstruturaPrecificacaoService.php` — custo efetivo,
  parâmetros da empresa, fretes.
- `app/Services/Portal/Estrutura/EstruturaOfertaService.php` — criação de oferta e regras de composição.
- `app/Services/Portal/Estrutura/ColagemAnunciosService.php` + `LeitorColagemAnuncios.php` — prévia em grupos e
  modo "acrescentar/atualizar" (modelo para a importação de D-13/D-14).
- PhpSpreadsheet (`phpoffice/phpspreadsheet`) — já usado em `app/Console/Commands/SyncGrantsFromSftp.php`.

### Established Patterns
- Empresa sempre do `app/Support/Portal/PortalContexto.php`; rotas do portal em `routes/web.php`
  (`portal.auth.estrutura.*`) com `throttle` por rota; rota nova do portal precisa entrar na allowlist de
  `app/Http/Middleware/RestringeDominioDoPortal.php`; submódulos em `app/Support/Portal/ModulosPortal.php`.
- Vocabulário travado com `Rule::in` + constante no model; `varchar` em vez de `enum`; `timestamps()` nullable;
  nome de índice/FK curto e explícito; migration rodada também no MariaDB 10.4 local com `--path`.
- Testes do módulo em `tests/Feature/PortalCliente/Estrutura/`; JS em `tests/js/`.

### Integration Points
- `app/Models/EstruturaOferta.php` — ganha o vínculo com a variação (D-08/D-11).
- `app/Models/EstruturaPrecificacao.php` / Precificação — custo da oferta simples ligada vem do produto (D-10).
- `app/Services/Publicador/PublicadorSincronizaPortalService.php` e `DadosEfetivosService.php` — o Publicador lê as
  ofertas e o preço efetivo; a mudança de origem do custo chega lá.
- `app/Services/Portal/Estrutura/ReguaEstrutura.php` / `EstruturaVisaoService.php` — painel e visão de ofertas.
- Telas `resources/js/Pages/Portal/Estrutura*.jsx` e `resources/js/Components/Portal/Estrutura/`.

</code_context>

<specifics>
## Specific Ideas

- **Colunas da aba Produtos (a referência da tela e do modelo):** Ref · Grupo (anúncio) · Variação · Produto ·
  Família · Ambiente · Categoria ML · Nº volumes · Volumes (C×L×A cm · kg, várias caixas separadas por "|") ·
  Peso total (kg) · Custo (R$). Exemplo de volume múltiplo: `186×43×12 · 27.8 | 97×42×12 · 12.1`.
- **A regra de combinação que justifica família e ambiente como dado estruturado** (medida na planilha: 70
  produtos/56 grupos → 199 ofertas): das 83 ofertas com 2+ produtos, **0 misturam família**; 82 dividem ao menos
  um ambiente; dentro da mesma família, produtos sem ambiente em comum formam 45 pares e só 1 foi combinado.
  Família + ambiente dão 105 pares possíveis e 43 foram usados — o 3º filtro ("faz sentido": mesa + cadeira sim,
  cômoda + cama não) é da fase de geração. As 4 fases da planilha (Simples, Combo, Kit, Combit) são exatamente
  as `FASES` de `EstruturaOferta`.
- Falas do usuário (05/10): "Produto onde o cliente cadastra seus produtos e Planejamento é onde planejamos
  IDENTIFICAÇÃO DA OFERTA"; "o produto que é do ambiente Sala de jantar não se conecta com o ambiente quarto";
  "temos que ter essa planilha no nosso sistema, obviamente que não em formato de planilha, mas sim de sistema, e
  mais inteligente".
- Diferenças planilha × reunião, para as fases seguintes: overprice 35% na planilha × 30% dito na reunião;
  capacidade 15/12/10/8 por dia × 10/8/6/4 no exemplo da reunião.

</specifics>

<deferred>
## Deferred Ideas

- **Geração automática das ofertas** combo/kit/combit a partir de família + ambiente + par de categorias que
  combina (aba Planejamento) — próxima fase.
- Logística e frete de **kits e combos** (pacote juntando produtos).
- **Precificação da planilha**: grade de margem 30/20/10/0, overprice, desconto da Central de Promoções, tarifa real
  por categoria pela API.
- **Cronograma** por capacidade por fase, checklist de 13 alavancas e status Atrasado/Hoje (aba Cronograma).
- **Fotos (mais de 7), vídeo, ficha técnica, descrição e dados fiscais** no produto — pedidos na reunião.
- Criar produtos a partir dos **anúncios que o cliente já tem no ML** ("relançar o que já vende").
- Trazer a **Planilha de Produtos do Onboarding** (`/implementacao/{token}`).
- Produtos para **empresa polo sem Company** (Polos).
- Frete **ME1** pela tabela da transportadora do cliente.
- Renomear a lista "Produtos" do Publicador (que são ofertas a publicar) para não confundir com o catálogo.

### Reviewed Todos (not folded)
- `todo.match-phase` trouxe só coincidências de palavras (prova das Alavancas na #459, junção do Danilo, cobrança,
  heurística de recomendação de 06/2026) — nenhum trata de cadastro de produto; nenhum dobrado.

</deferred>

---

*Phase: 167-cadastro-de-produto-no-mapeamento-estrutural*
*Context gathered: 2026-10-05*
