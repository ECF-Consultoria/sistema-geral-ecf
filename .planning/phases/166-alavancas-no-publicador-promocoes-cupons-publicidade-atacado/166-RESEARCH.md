# Fase 166: Alavancas no Publicador — Pesquisa

**Pesquisado em:** 2026-10-04
**Domínio:** leitura e escrita de promoções, cupons, atacado (e leitura de publicidade) na conta do Mercado Livre do cliente, dentro do Publicador interno (`/mlb/anuncios`) — Laravel 12 + Inertia + React
**Confiança:** ALTA no mapa de código (tudo lido no worktree `feat/publicador-ml-261001`) e nos corpos/erros da API (documentação oficial relida em 04/10 por `curl`, datas de atualização citadas); MÉDIA no cálculo "quanto a loja recebe" em promoção cofinanciada/boost (a doc não diz sobre qual preço a tarifa incide); BAIXA em três pontos marcados como `[ASSUMED]` e listados no Log de Suposições.

<user_constraints>
## Restrições do Usuário (do 166-CONTEXT.md — copiadas, travadas)

### Decisões travadas
- **D-01 — Barra "Publicar | Alavancas" na página da empresa.** Na tela da empresa (`mlb.anuncios.publicador.produtos`, `/mlb/anuncios/publicador/empresas/{conta}`), uma barra no topo com Publicar e Alavancas. Dentro de Publicar fica exatamente o que existe hoje, inclusive a barra `ModoAnuncioTabs` (Meus Anúncios | Individual | Em massa | Histórico). Troca a qualquer momento, sem voltar à lista de empresas. Vale para as duas âncoras (`empresa-N` e `company-N`): Alavancas usa só o token da conta, não depende de `Company` (diferente de Meus Anúncios/Em massa/Histórico, D23 da 164).
- **D-02 — Alavancas abre num panorama e depois as abas.** No topo, um resumo da conta: convites de promoção abertos com o prazo de cada um (`deadline_date`), cupons ativos com saldo (`remaining_budget`), campanhas de publicidade com o essencial e créditos de bonificação, e se a conta tem atacado liberado. Embaixo, as abas **Promoções | Cupons | Publicidade | Atacado**. O histórico de escritas (D-05) fica acessível dentro de Alavancas.
- **D-03 — Trava própria das Alavancas, fail-closed.** Lista de contas liberadas só para Alavancas, separada da trava de publicação (`publicador.contas_liberadas` / `ContasLiberadas`, D21 da 164), no mesmo formato: por âncora (`companies` e `mlb_empresas`), vazia = ninguém, começando pela #459. Liberar promoções para um cliente NÃO libera a publicação de anúncios para ele, e vice-versa. Conta fora da lista: tudo de leitura e análise funciona; os botões de escrita ficam desligados com o motivo na tela; e o SERVIDOR recusa a escrita (não basta esconder o botão). A trava vale em toda escrita (POST/PUT/DELETE no ML), como o CR-B01 da 164.
- **D-04 — Confirmação com resumo antes de cada escrita.** Uma janela mostra o que vai mudar — conta, produto, preço atual → preço na promoção, % de desconto, prazo, quanto o ML banca, quanto a loja recebe — e só escreve depois do "Confirmar". Ação em vários produtos de uma vez confirma o lote, listando os produtos.
- **D-05 — Histórico de escritas, com tela.** Tabela nova com cada escrita: quem, quando, conta (âncora), alavanca, ação, o que foi enviado e a resposta crua do ML, e o resultado. Na tela, um histórico por empresa. A resposta do ML é guardada mesmo quando dá erro (regra do usuário para o Publicador: payload e resposta ficam). Desenho da tabela POR ESCRITO no plano antes da migration (CLAUDE.md, disciplina 2) — e as armadilhas de MariaDB de `.planning/learnings/desempenho-bonificacao.md` §6.
- **D-06 — Acesso: só admins.** Como o resto de `/mlb/anuncios` (grupo `role:admin`, D17 da 164).
- **D-07 — Promoções, as quatro escritas:** (1) **Convites do ML** — inscrever, alterar preço e tirar produto das campanhas que o ML oferece (`DEAL`, `MARKETPLACE_CAMPAIGN`, `DOD`, `LIGHTNING`, `VOLUME`, `PRE_NEGOTIATED`, `SMART`, `PRICE_MATCHING`, `UNHEALTHY_STOCK`), respeitando a regra de cada tipo (DOD e LIGHTNING não se editam: tirar e inscrever de novo; aceitar o que é "usuário aceita" sem inventar preço). (2) **Desconto individual** (`PRICE_DISCOUNT`) — criar e remover; 5% a <80%, até 14 dias, preço para Mercado Pontos 3–6 opcional com a diferença mínima do ML. (3) **Campanha do vendedor** (`SELLER_CAMPAIGN`, `FLEXIBLE_PERCENTAGE`, até 14 dias) — criar, alterar, excluir e pôr/tirar produtos; também o leve X pague Y criado pelo vendedor (`VOLUME`). (4) **Bloqueio das campanhas automáticas** — lista de exclusão da conta e por produto.
- **D-08 — Cupons do vendedor** (`SELLER_COUPON_CAMPAIGN`, só MLB): criar (valor fixo ou %, com ou sem código, compra mínima, teto no %, orçamento, até 31 dias), alterar (orçamento só aumenta) e excluir; mostrar o saldo dos ativos.
- **D-09 — Publicidade só leitura.** Campanhas, anúncios/Ad Groups, métricas (investimento, vendas, ACOS) e bonificações, pelos endpoints da documentação atual (`api-version: 2`, `.../campaigns/search`, `.../ads/search`, ad groups, `/advertising/advertisers/bonifications`) — NUNCA pelos legados desligados em 2026-05-27. Criar/pausar/mover campanha fica fora até: (a) a permissão "Advertising" do app ECF ser ligada no DevCenter e (b) a escrita ser provada na #459 (não na Bymobille, que é cliente).
- **D-10 — Atacado: ver e editar.** Mostrar se a conta tem a tag `business`; se tiver, ver e editar até 5 faixas no formato **% B2B** (`POST /items/{id}/prices/price-per-quantity` com `x-version`), nunca pelo absoluto (`/prices/standard/quantity`, descontinuado para B2B em 2026-10-27). Sem `business`, a aba explica que o ML libera por convite.
- **D-11 — Números por produto em promoção:** preço atual → preço na promoção, % de desconto, quanto o ML banca (cofinanciada/boost) e **quanto a loja recebe** no preço da promoção — tarifa (`/sites/MLB/listing_prices`) + frete, o mesmo cálculo do "Quanto você recebe" do Publicador (`SimuladorVoceRecebe`). Margem com custo só quando o produto vem do Portal e a Precificação tem custo (`EstruturaPrecificacao.custo`); sem custo, não pede para digitar.
- **D-12 — Alertas simples, sem ranking.** Marcar o que pede atenção: convite que vence em poucos dias; promoção em que o recebido fica abaixo do recebido no preço normal menos X% (X em config, valor inicial a critério do planejamento); produto sem estoque suficiente para a oferta (DOD/LIGHTNING pedem estoque mínimo); reputação que bloqueia desconto/campanha/cupom (exigem reputação verde). A decisão fica com a pessoa.
- **D-13 — Padrão atual do Publicador.** Mesmo design das telas da empresa e do editor em 3 etapas: cartões escuros `ecf-card`, campos "com cara de campo", tipografia 24/15/13/11 e pesos 400/700, um amarelo sólido por tela (o próximo passo). Nada de contador de progresso ("N/8", "Completo", "Faltam N") — recusado pelo usuário em 04/10. Conferência visual com capturas antes de subir (learnings do Publicador §7).

### Critério de Claude (decidir no planejamento, com justificativa)
- Nomes de rotas e controller (seguindo `mlb.anuncios.publicador.*` e controller enxuto), onde mora o serviço das alavancas e a divisão em serviços por alavanca.
- Cache e frequência das leituras (leitura sob demanda; paginação de itens por `search_after`, que expira em 5 min); limites de chamadas ao calcular "quanto recebe" para muitos produtos.
- Como listar os produtos da conta para as ações que não partem de um convite (ex.: escolher produtos para um desconto individual ou campanha do vendedor), inclusive para `MlbEmpresa` sem `Company`.
- Valor inicial do X do alerta de recebido e o que é "poucos dias" para o convite.
- Nome da chave de config/env da trava das Alavancas.

### Ideias adiadas (FORA DE ESCOPO)
- **Resultado das promoções** (vendas e faturamento antes × durante) — outra fase.
- **Escrita em publicidade** (criar campanha, orçamento, ACOS, pausar/mover anúncio) e **"campanha de lançamento"** automática ao publicar pelo Publicador.
- **Notificações do ML** (candidatos, ofertas, `items prices`) para atualizar sozinho — nesta fase a leitura é sob demanda.
- **Ranking automático** de quais produtos mais valem entrar em cada promoção.
- **Afiliados** — sem API para o vendedor.
- **Atacado B2C** (todo comprador) — hoje só pneus, sem data no MLB.
- **Acesso para quem não é admin** (`permission:mlb.anunciar`).
- **Achado lateral, fora da fase:** `MercadoLivreAdsService::listAds` (Sugadores) usa endpoint desligado em 2026-05-27 — conferir em produção e corrigir à parte.
</user_constraints>

## Restrições do projeto (do CLAUDE.md)

- Stack fixa: Laravel 12 + Inertia + React 18; Tailwind com tokens `ecf-*`; `cn()` de `@/lib/utils`; comentários em pt-BR; logs com prefixo `[Alavancas]`; `catch (\Throwable)`.
- Fase de **risco comum**: não toca `DesempenhoScoreService`, snapshots nem `bonus_*`; a migration só CRIA tabela. Mesmo assim, disciplina 2 (schema por escrito antes) e disciplina 3 (teste no mesmo commit) valem — o desenho está em "Migration" abaixo.
- `git commit -- <caminhos>`, nunca `git add -A`; conferir `git show <sha>` antes do push (learnings: o commit por caminho arrasta trabalho de outra sessão).
- `npm run build` ao fim de qualquer alteração de frontend; componente que nenhuma página importa NÃO é compilado (learnings publicador §9) — o gate de import precisa existir.
- **Nenhum deploy sem autorização explícita.** Conta de cliente: nunca escrever fora da lista liberada; escrita real de teste só na #459, com confirmação do usuário antes de cada chamada.
- Sem SQLite-only: armadilhas de MariaDB de `desempenho-bonificacao.md` §6 (índice ≤ 64 caracteres, `nullOnDelete` só em coluna anulável, FK exige índice de apoio).

<phase_requirements>
## Requisitos (IDs AL166-xx, propostos por esta pesquisa)

Cada ID tem decisão de origem e é testável. O planejador precisa de cada um em algum plano.

| ID | Descrição | Origem | Suporte da pesquisa |
|----|-----------|--------|---------------------|
| AL166-01 | A tela da empresa ganha a barra "Publicar \| Alavancas" (componente novo, sem amarelo sólido); em Publicar o conteúdo e o `ModoAnuncioTabs` ficam idênticos; Alavancas abre para `empresa-N` e `company-N` mesmo sem `Company` (a barra só leva a rotas que dependem do token). | D-01 | §1, Padrões "Barra" |
| AL166-02 | Todas as rotas de Alavancas ficam no grupo `role:admin`; não admin recebe 403; chave inexistente/arquivada/sem programa dá 404; `company-N` ligada a `MlbEmpresa` redireciona para a chave canônica; conta sem token ativo mostra o estado "reconectar" e não chama o ML. | D-06, D-01 | §1 |
| AL166-03 | O panorama mostra: convites abertos com `deadline_date`, cupons ativos com `remaining_budget`, campanhas de publicidade (essencial) e saldo de bonificações, e atacado liberado (tag `business`). Cada fonte falha sozinha (as outras seguem) e mostra "não deu para ler agora". | D-02 | §2, §3 |
| AL166-04 | Existe trava própria das Alavancas (classe + chave de config + env), fail-closed, por âncora, separada de `ContasLiberadas`: liberar a #459 em uma não libera na outra (teste em ambos os sentidos); sem env = só a #459 (Company 459). | D-03 | §3 |
| AL166-05 | Todo POST/PUT/DELETE ao ML passa por UM ponto (`EscritorAlavancas`) que recusa fora da lista (resultado `RECUSADA`, nenhuma chamada HTTP), confere que o token é do vendedor da âncora (`/users/me` × `ml_user_id`) e nunca é contornável pelo controller; na tela, botões desligados com o motivo; leitura continua. | D-03 | §3, Padrão 1 |
| AL166-06 | Tabela `pub_alavanca_escritas` conforme o desenho abaixo: linha `PENDENTE` gravada ANTES do envio, atualizada com status HTTP, resposta crua e resultado (`OK`/`ERRO`/`INCERTO`/`RECUSADA`), inclusive em erro e timeout; token e cabeçalho `Authorization` nunca gravados. | D-05 | §4 |
| AL166-07 | Tela de histórico por empresa dentro de Alavancas: paginada, filtro por alavanca e resultado, mostra quem/quando/ação/produto e abre payload e resposta crua; só da empresa da tela. | D-05, D-02 | §4 |
| AL166-08 | Toda escrita tem prévia + confirmação: `previa` devolve o resumo (conta, produto, preço atual → promoção, %, prazo, ML banca, loja recebe) e uma assinatura; `confirmar` só escreve com a assinatura válida (mesmo payload, mesma conta, mesmo usuário, ≤ 10 min). Lote: o resumo lista todos os produtos; o lote roda por job com uma linha de histórico por item. | D-04 | Padrão 2, Padrão 5 |
| AL166-09 | Convites do ML: listar convites e candidatos (paginação `search_after` com TTL tratado) e inscrever/alterar/remover com a regra de cada tipo (matriz abaixo): sem preço onde "usuário aceita", sem edição em PRICE_DISCOUNT/DOD/LIGHTNING, DOD/LIGHTNING só removíveis enquanto programados, `offer_id` onde o tipo exige, `stock` no LIGHTNING. | D-07.1 | §Matriz |
| AL166-10 | Desconto individual (`PRICE_DISCOUNT`): criar e remover; validação local de 5% ≤ desconto < 80%, janela ≤ 14 dias (datas inteiras), `top_deal_price` ≥ 5 p.p. melhor (≥ 10 p.p. acima de 35%), item ativo/novo/não-grátis; resposta do ML (erros `buyer_discount_not_in_range` etc.) traduzida. | D-07.2 | §Matriz |
| AL166-11 | Campanha do vendedor: criar/alterar/excluir `SELLER_CAMPAIGN` (`FLEXIBLE_PERCENTAGE`, ≤ 14 dias, start ≥ hoje, start não editável com campanha `started`) e pôr/tirar/alterar produtos (preço só baixa em campanha iniciada; `remove_loyalty`); e `VOLUME` do vendedor (`BNGM`/`BNSP`/`SPONTH`, `allow_combination`). | D-07.3 | §Matriz |
| AL166-12 | Lista de exclusão das campanhas automáticas: ler/gravar a da conta (`exclusion_status`) e a por produto (`item_id` + `exclusion_status`), com histórico e confirmação como as demais. | D-07.4 | §Matriz |
| AL166-13 | Cupons: criar (`FIXED_AMOUNT`/`FIXED_PERCENTAGE`, `partial_coupon_code`, `min_purchase_amount`, `max_purchase_amount` no %, `budget`, ≤ 31 dias, ≥ 1 dia), alterar (campanha `started`: só `finish_date`, `budget` que só aumenta e `name`), excluir, **e pôr/tirar produtos no cupom** (cupom sem produtos não faz nada); lista mostra `remaining_budget` e `used_coupons`. | D-08 | §2 cupons |
| AL166-14 | Publicidade só leitura: advertiser (`Api-Version: 1`), campanhas (`campaigns/search`, `api-version: 2`, métricas ≤ 90 dias), Ad Groups (`ad_groups/search`) e bonificações; teste que falha se existir qualquer método ≠ GET para `/advertising/` e se algum caminho legado desligado aparecer na fonte. | D-09 | §7 |
| AL166-15 | Atacado: mostrar `business`; com a tag, ler as faixas atuais, buscar recomendações (`/prices-per-quantity/v1/recommendations`) antes de gravar, gravar até 5 faixas % B2B com `X-Version` (relido imediatamente antes), tratar 409 `item.version`, `remove-absolute-pxq=true` só com confirmação explícita; nunca chamar `/prices/standard/quantity`; sem a tag, texto de convite. | D-10 | §8 |
| AL166-16 | Para cada produto em promoção: preço atual → preço na promoção, % de desconto, quanto o ML banca (rebate cofinanciado / campos de boost), quanto a loja recebe (tarifa + frete reais da API, com `logistic_type`/`shipping_mode` do item) e margem só com custo da Precificação (`item → EstruturaAnuncio.codigo_mlb → oferta`); sem custo, nada de campo para digitar; frete desconhecido = calcula sem ele e diz isso. | D-11 | §6 |
| AL166-17 | Alertas (sem ranking): convite que vence em ≤ N dias, recebido na promoção < recebido normal − X% (config), estoque abaixo do mínimo de DOD/LIGHTNING, reputação que bloqueia; N e X em `config/publicador.php`; nenhum alerta bloqueia a ação. | D-12 | §9 |
| AL166-18 | Visual no padrão do Publicador: arquivos novos entram nos gates de `tests/js/` (24/15/13/11, 400/700, sem `bg-ecf-yellow` sólido, sem Select Radix, sem `uppercase`, sem contador de progresso); o único amarelo sólido vem de `PRIMARIO` em `Mesa/botoes.jsx`; capturas conferidas antes de subir. | D-13 | §10 |
| AL166-19 | Listar os produtos da conta para ações sem convite (desconto individual, campanha do vendedor, cupom, atacado), para as duas âncoras, ao vivo pelo token da conta: `items/search` + multiget de 20, busca por SKU/título, teto de paginação explícito, filtro de elegíveis (ativo, novo, não grátis). | critério de Claude (D-07) | §5 |
| AL166-20 | Robustez de chamada: erros do ML (formatos `cause[].error_code`, `{error, code, cause_id}`, 409, 423, 429) mapeados para mensagem em pt-BR sem perder a resposta crua; 423 repetido até 3× com espera curta; 429 com backoff (já existe); escrita nunca repetida em 5xx/timeout (resultado `INCERTO`, a pessoa relê o estado). | D-05, D-07 | §Armadilhas |
</phase_requirements>

## Resumo

A fase cabe quase inteira em código que já existe: a resolução `{conta}` → âncoras (`ProgramasPublicadorService::resolver`), o cliente HTTP com refresh e backoff (`ClienteMlPublicador::daConta`), a classificação de resposta (`RespostaMl`), o molde da trava (`ContasLiberadas`), o cálculo "você recebe" (`SimuladorVoceRecebe`) e o custo da Precificação (`EstruturaPrecificacaoService::pagina`). O que é novo é (a) uma segunda trava com a mesma forma, (b) um **ponto único de escrita** que aplica trava, confere vendedor, grava histórico e chama o ML, (c) serviços de leitura por alavanca, (d) a tabela de histórico e (e) a UI.

Três achados mudam o que o CONTEXT supunha e o planejador precisa saber antes de escrever tarefas. **(1)** O corpo real do PxQ % B2B não é `discount_percentage` + `min_purchase_unit` soltos: é `{type:"discount_percentage", percentage, conditions:{context_restrictions:["channel_marketplace","user_type_business"], min_purchase_unit, eligible:true}}`, a doc exige consultar as **recomendações** antes de gravar, e o ML valida o resultado contra a recomendação (erro 5599). **(2)** O endpoint `.../product_ads/ads/search` que o CONTEXT D-09 cita está **depreciado** (a seção de métricas de anúncios foi removida em 30/05/2026); a leitura atual é por **Ad Group** (`.../product_ads/ad_groups/search`). **(3)** Cupom do vendedor não tem efeito sem produtos: criar a campanha não basta, é preciso `POST /seller-promotions/items/{item}` com o `promotion_id` do cupom (sem preço). Além disso, DOD e LIGHTNING só se removem enquanto "programados", e MARKETPLACE_CAMPAIGN/VOLUME não aceitam alteração de preço (remover → mudar preço fora → reinscrever, e aumento de preço tira o item sozinho).

Nenhum código do projeto grava preço em anúncio existente no ML (verificado: não há `PUT /items` nem `PUT .../price` em `app/`; o Publicador só faz `POST /items`), então a armadilha "aumentar preço remove o desconto" hoje vem de fora (ERP, edição manual no ML). A tela de Alavancas deve avisar isso, e o plano deve registrar que uma futura escrita de preço no Publicador precisa consultar `GET /seller-promotions/items/{id}` antes.

**Recomendação principal:** uma ação = uma classe pequena (`AcaoAlavanca`: valida, resume, monta as requisições) executada só por `EscritorAlavancas` (trava → vendedor → histórico PENDENTE → ML → histórico final), com `previa`/`confirmar` assinados; lote por job na fila `high`; leituras ao vivo, sob demanda, com cache curto por conta; nenhuma migration além de `pub_alavanca_escritas`.

## Mapa de Responsabilidade Arquitetural

| Capacidade | Camada principal | Camada secundária | Justificativa |
|------------|-----------------|-------------------|---------------|
| Barra Publicar \| Alavancas, abas, modal de confirmação | Navegador (React/Inertia) | — | Só apresentação; sem regra de negócio no cliente (as validações duplicadas na tela são conveniência, a do servidor manda) |
| Trava por conta, conferência do vendedor, recusa de escrita | API / Backend (`EscritorAlavancas`, `AlavancasLiberadas`) | — | CR-B01: esconder botão não protege; a recusa é do servidor |
| Chamadas ao ML (promoções, cupons, ads, PxQ) | API / Backend (`ClienteMlPublicador` estendido) | Fila `high` (lote) | Token cifrado só no servidor; lote longo não cabe numa requisição |
| Cálculo "quanto recebe" / margem / alertas | API / Backend (`AnaliseAlavancasService`) | — | Usa tarifa/frete reais da API e custo da Precificação; o cliente só mostra |
| Histórico de escritas | Banco (`pub_alavanca_escritas`) | API (escrita só pelo `EscritorAlavancas`) | Auditoria imutável do que foi enviado e respondido |
| Lista de contas liberadas | Config (`config/publicador.php`) + env | — | Igual à trava da 164; liberar conta é decisão do desenvolvimento, sem tela |
| Cache de leituras | API (Cache do Laravel, Redis em produção) | — | TTL curto por conta; escritas invalidam |

## Stack padrão

### Núcleo (tudo já no projeto — nenhum pacote novo)
| Biblioteca | Versão | Propósito | Por que é o padrão aqui |
|-----------|--------|-----------|-------------------------|
| Laravel `Http` / `ClienteMlPublicador` | framework 12 | chamada autenticada com refresh, backoff 429/5xx, `RespostaMl` | Já faz refresh com lock por conta (refresh do ML é de uso único) |
| Inertia + React 18 + Tailwind `ecf-*` | já instalados | telas | CLAUDE.md: sem mudança de stack |
| `Cache` (Redis/array) | — | cache curto por conta | produção usa Redis; testes `array` |
| Fila `high` | — | lote de escritas | mesma fila e supervisor do Publicador (learnings §9: `queue:restart` depois do deploy) |
| PHPUnit 11 + `Http::fake` | — | testes | learnings §5 |
| `node --test` | Node 26.9 local | gates de fonte do front | `npm run test:js` |

**Instalação:** nenhuma.
**Verificação de versão:** não se aplica (sem pacote novo). `[VERIFIED: composer.json/CLAUDE.md]`.

### Alternativas consideradas
| Em vez de | Poderia usar | Compromisso |
|-----------|-------------|-------------|
| Leitura ao vivo de itens por `items/search` | `ml_acervo_items` (Fase 134) | O acervo é só de `Company` e é rotacionado (defasado); serve para `Company`, não para `MlbEmpresa` sem `Company`, e o preço/estoque precisam ser de agora numa escrita. Usar só como dica opcional, não como fonte. |
| `MercadoLivreAdsService` (Sugadores) para ler Ads | serviço novo de leitura | O serviço é tipado em `Company`, grava `MlAdvertiser(company_id)` e usa o caminho `/marketplace/advertising/MLB/...`, que a doc atual não lista. Reaproveitar só o *padrão* (descoberta do advertiser + backoff), não a classe. |
| Escrita com confirmação só no cliente | prévia assinada no servidor | A assinatura prova que o payload confirmado é o que vai ao ML; custo: ~40 linhas. |

## Auditoria de legitimidade de pacotes

Nenhum pacote externo novo (PHP ou JS) é recomendado. Seção "slopcheck" não se aplica. **Pacotes removidos por [SLOP]:** nenhum. **Pacotes [SUS]:** nenhum.

## Padrões de Arquitetura

### Diagrama de fluxo

```
Admin ─▶ GET /mlb/anuncios/publicador/empresas/{conta}            (Produtos.jsx + barra Publicar|Alavancas)
            │ clique "Alavancas"
            ▼
         GET .../{conta}/alavancas ─▶ MlbAlavancasController@index ─▶ ProgramasPublicadorService::resolver (404/redirect)
            │                                   │ props: empresa, liberada(+motivo), conta ML (nick, reputação, business)
            ▼                                   ▼
   Alavancas.jsx (panorama + abas)      LeitorContaAlavancas  ─▶ GET /users/me (token da conta)
            │ cada aba/painel pede JSON, em paralelo, sob demanda
            ├─ GET alavancas/panorama ─▶ PanoramaService ─▶ [PromocoesLeitura | CuponsLeitura | PublicidadeLeitura | AtacadoLeitura]
            ├─ GET alavancas/promocoes/{id}/itens?tipo=&search_after=   (TTL 5 min tratado)
            ├─ GET alavancas/produtos?busca=&pagina=  ─▶ ProdutosDaContaService (items/search + /items?ids= de 20)
            └─ POST alavancas/analise {itens:[{item,preco}]} ─▶ AnaliseAlavancasService (tarifa+frete+custo da Precificação+alertas)
            │
            │ ESCRITA (3 passos)
            ▼
   POST alavancas/escritas/previa {acao, ...}  ─▶ AcaoAlavanca::validar → resumo → assinatura(HMAC, 10 min)   (NÃO toca o ML)
            ▼  (usuário lê o resumo e clica "Confirmar")
   POST alavancas/escritas {acao, ..., assinatura} ─▶ confere assinatura
            │                                      ─▶ 1 item: EscritorAlavancas::executar (síncrono)
            │                                      ─▶ N itens: cria lote_uuid + linhas PENDENTE + job (fila high)
            ▼
   EscritorAlavancas::executar(AcaoAlavanca)
      1. AlavancasLiberadas::exigir(âncora)            ── fora da lista → linha RECUSADA, sem HTTP, 403 com motivo
      2. confere vendedor (/users/me.id == mlToken.ml_user_id)
      3. grava linha PENDENTE (payload, resumo, ator)
      4. ClienteMlPublicador::daConta(POST|PUT|DELETE, repetir=false, 423→espera e repete ≤3)
      5. atualiza linha (http_status, resposta crua, resultado OK|ERRO|INCERTO)
      6. invalida cache da conta
            ▼
   GET alavancas/historico (+ GET alavancas/lotes/{uuid} para o progresso do lote)
```

### Estrutura recomendada (arquivos novos em **negrito**)

```
app/
├── Http/Controllers/
│   ├── **MlbAlavancasController.php**          # página + leituras JSON (enxuto: resolve conta, delega)
│   └── **MlbAlavancasEscritaController.php**   # previa, confirmar, lote (status)
├── Http/Requests/**Alavancas/**                # um FormRequest por família de ação (validação de forma)
├── Models/**PubAlavancaEscrita.php**
├── Jobs/**ExecutarLoteAlavancaJob.php**        # fila high, fatias de ~45 s com release() (learnings §6)
├── Services/Publicador/Alavancas/
│   ├── **ContextoAlavancas.php**               # resolve {conta} → âncora com token + LeitorContaAlavancas
│   ├── **LeitorContaAlavancas.php**            # GET /users/me → id, nickname, tags, seller_reputation.level_id
│   ├── **PanoramaService.php**
│   ├── **PromocoesLeitura.php**                # users/{id}, promotions/{id}, /items (search_after), items/{item}
│   ├── **CuponsLeitura.php**                   # filtra SELLER_COUPON_CAMPAIGN + detalhe (remaining_budget)
│   ├── **PublicidadeLeitura.php**              # advertiser, campaigns/search, ad_groups/search, bonifications (só GET)
│   ├── **AtacadoLeitura.php**                  # tag business, GET /items/{id}/prices, recomendações
│   ├── **ProdutosDaContaService.php**          # items/search + multiget
│   ├── **AnaliseAlavancasService.php**         # D-11 + D-12 (tarifa, frete, margem, alertas)
│   ├── **EscritorAlavancas.php**               # O ÚNICO caminho de escrita (trava+vendedor+histórico+ML)
│   ├── **AssinaturaDaPrevia.php**              # HMAC do payload (conta+user+exp)
│   ├── **MapeadorErroAlavanca.php**            # cause[].error_code | {error,code,cause_id} → pt-BR
│   └── Acoes/**                                # InscreverItem, AlterarItem, RemoverItem, CriarPriceDiscount, RemoverPriceDiscount,
│                                              # CriarCampanha, AlterarCampanha, ExcluirCampanha, CriarCupom, AlterarCupom,
│                                              # ExcluirCupom, PorProdutoNaCampanha, ExclusaoConta, ExclusaoItem, GravarAtacado
├── Support/Publicador/**AlavancasLiberadas.php**   # trava própria (molde de ContasLiberadas)
config/publicador.php                           # + 'alavancas' => [contas_liberadas, alertas, cache, limites]
database/migrations/**2026_10_05_100000_create_pub_alavanca_escritas_table.php**
resources/js/
├── Pages/Mlb/Publicador/**Alavancas.jsx**
├── Components/Mlb/Alavancas/**                 # AreaTabs, Panorama, AbaPromocoes, AbaCupons, AbaPublicidade, AbaAtacado,
│                                              # Historico, ModalConfirmacao, SeletorDeProdutos, AvisoAlavancasTravadas, useAlavancas.js
routes/mlb_anuncios.php                         # + grupo publicador/empresas/{conta}/alavancas
tests/{Unit,Feature}/Publicador/Alavancas/**   # + tests/fixtures-ml/alavancas/** + tests/js/publicador-alavancas.test.js
```

### Padrão 1: um único caminho de escrita (o coração da trava)

**O quê:** nenhuma classe além de `EscritorAlavancas` chama `daConta` com método ≠ GET para o ML nesta fase. Um teste de guarda varre `app/Services/Publicador/Alavancas` e `app/Http` e falha se achar `'POST'|'PUT'|'DELETE'` fora desse arquivo.
**Quando:** sempre. Isso é o CR-B01 da 164 (`PublicacaoService::contaFixada`) aplicado de saída.

```php
// Forma, não implementação final. Fonte do padrão: app/Support/Publicador/ContasLiberadas.php + PublicacaoService::contaFixada
final class AlavancasLiberadas
{
    public static function libera(?ContaMercadoLivre $conta): bool
    {
        $lista = match (true) {
            $conta instanceof Company    => config('publicador.alavancas.contas_liberadas.companies', []),
            $conta instanceof MlbEmpresa => config('publicador.alavancas.contas_liberadas.mlb_empresas', []),
            default                      => null,
        };

        return $lista !== null && in_array((int) $conta->id, array_map('intval', $lista), true);
    }

    /** @throws RegraViolada */
    public static function exigir(ContaMercadoLivre $conta): void
    {
        if (! self::libera($conta)) {
            throw new RegraViolada('ALAV-LIB', 'As Alavancas ainda não foram liberadas para escrever nesta conta. Você pode ver e analisar tudo aqui; criar e alterar espera a liberação.');
        }
    }
}

// config/publicador.php — SEM fallback para PUBLICADOR_EMPRESAS_PILOTO (D-03: independente da publicação)
'alavancas' => [
    'contas_liberadas' => [
        'companies'    => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES', '459'))))),
        'mlb_empresas' => array_values(array_filter(array_map('intval', explode(',', (string) env('PUBLICADOR_ALAVANCAS_LIBERADAS_MLB_EMPRESAS', ''))))),
    ],
],
```

Observação: o default `'459'` reproduz "começando pela #459". O `.env` de produção pode sobrescrever com lista vazia (`PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES=`) — `explode` de string vazia + `array_filter` dá lista vazia = ninguém, igual à trava da 164.

```php
// EscritorAlavancas::executar — ordem importa
public function executar(AcaoAlavanca $acao, Ator $ator, ?string $lote = null): PubAlavancaEscrita
{
    $conta = $acao->conta();                                   // âncora que TEM token
    $linha = $this->abrirLinha($acao, $ator, $lote);           // PENDENTE (nasce antes de qualquer HTTP)

    try {
        AlavancasLiberadas::exigir($conta);                    // 1) trava — RegraViolada vira linha RECUSADA
        $this->conferirVendedor($conta);                       // 2) token de outro vendedor → nada sai
        foreach ($acao->requisicoes() as $req) {               // 3) normalmente 1; PxQ = GET versão + POST
            $r = $this->enviar($conta, $req);                  //    repetir=false; 423 → dormir(2) e repetir ≤ 3
            $this->anotar($linha, $req, $r);                   // payload + resposta crua + http_status
            if (! $r->ok()) break;
        }
    } catch (RegraViolada $e) { $linha->marcar('RECUSADA', $e); }
      catch (\Throwable $e)    { $linha->marcar('INCERTO', $e); Log::error("[Alavancas] ..."); }

    return $linha->fresh();
}
```

### Padrão 2: prévia assinada → confirmar

`previa` roda `AcaoAlavanca::validar()` + `resumo()` (leitura apenas, pode chamar `AnaliseAlavancasService`) e devolve `{resumo, assinatura}`. `assinatura = hash_hmac('sha256', json(payload_canônico + conta.chave + user.id + exp), config('app.key'))`. `confirmar` recomputa e compara com `hash_equals`; expirou ou mudou → 422 "Refaça a conferência". Isso cumpre D-04 sem estado em banco. Lote: o payload canônico inclui a lista ordenada de `item_id`+preço.

### Padrão 3: leitura por JSON sob demanda

`Alavancas.jsx` recebe só props leves (empresa, liberada+motivo, conta ML). Cada painel faz `axios.get` na hora de aparecer (padrão de `usePublicador.js`). Falha de uma fonte devolve `{erro: '...'}` para aquele painel — nunca 500 para a página (AL166-03). Cache: `Cache::remember("alavancas:{$conta->chaveContaMl()}:{$painel}", ttl, ...)`; TTL inicial: panorama 120 s, itens da promoção 60 s, produtos 300 s, recomendações PxQ sem cache; `?atualizar=1` ignora o cache; toda escrita bem-sucedida chama `Cache::forget` das chaves da conta. TTLs em `config('publicador.alavancas.cache')`.

### Padrão 4: barra "Publicar | Alavancas"

Componente novo `Components/Mlb/Alavancas/AreaTabs.jsx` (`area: 'publicar'|'alavancas'`, `conta`), renderizado em `Produtos.jsx` acima do bloco que hoje contém `<ModoAnuncioTabs …/>` (linha ~180) e no topo de `Alavancas.jsx`. A barra leva por `router.get(route('mlb.anuncios.publicador.produtos', {conta}))` e `route('mlb.anuncios.publicador.alavancas', {conta})` — ambas só dependem de `{conta}` (`empresa.chave`), portanto funcionam para `MlbEmpresa` sem `Company`. **Estilo do item ativo: `bg-white/[0.08] text-white` + `border-b-2 border-ecf-yellow`**, porque `Produtos.jsx` está em `ARQUIVOS` do gate `publicador-entrada.test.js` e `bg-ecf-yellow` sólido é proibido ali (o `ModoAnuncioTabs` atual usa `bg-ecf-yellow` e NÃO está no gate — não copiar o estilo dele). As telas Meus Anúncios, Em massa e Histórico continuam sem a barra (D-01 só pede na tela da empresa) — ver Questão Aberta 7.

### Padrão 5: lote por job, uma linha de histórico por item

`confirmar` com mais de 1 item cria `lote_uuid`, uma linha `PENDENTE` por item e despacha `ExecutarLoteAlavancaJob` na fila `high`. O job processa em série por até `publicador.fatia_segundos` (45 s) e usa `$this->release(15)` + `retryUntil()` (NUNCA `self::dispatch()` dentro do `handle`: no driver `sync` recursa — learnings §6). Cada item reaplica `AlavancasLiberadas::exigir` e a conferência do vendedor (a trava vale no meio do lote, como em `PublicacaoService::contaFixada`). `Cache::lock("alavancas:lote:{uuid}")` evita dois workers no mesmo lote. A tela faz polling de `GET alavancas/lotes/{uuid}` (contagem por resultado). **Alternativa se o planejador quiser cortar escopo:** limitar a 1 item por confirmação nesta fase e deixar lote para depois — mas isso viola D-04 ("ação em vários produtos confirma o lote"), então o lote por job fica no escopo.

### Anti-padrões a evitar
- **Chamar `daConta` com POST/PUT/DELETE em qualquer lugar fora de `EscritorAlavancas`** — esvazia a trava (AL166-05).
- **Reaproveitar `ContasLiberadas`/`AvisoContaTravada`** — a segunda diz "Publicação ainda não liberada" e é travada por gate de texto (`publicador-entrada.test.js:59`); criar `AvisoAlavancasTravadas` com texto próprio. Reaproveitar a lista da publicação quebraria D-03.
- **Repetir escrita em 5xx/timeout** — pode duplicar oferta; resultado `INCERTO` e a pessoa relê o estado (`repetir=false`, como o `POST /items`).
- **Gravar o limite de frete grátis (R$ 79) ou faixas de reputação em código** — RN-83: vêm da API ou de `config`.
- **Passar `$query` em POST/PUT/DELETE esperando que vá no corpo**: em `ClienteMlPublicador::enviar` o `$query` vai na URL e o `$corpo` em JSON — `app_version=v2` e `promotion_type` do DELETE são `query`; o resto é `corpo`.

## Não reinventar (Don't Hand-Roll)

| Problema | Não construir | Usar | Por quê |
|----------|---------------|------|---------|
| Token, refresh, backoff 429, classificação de resposta | cliente HTTP novo | `ClienteMlPublicador::daConta` (+ parâmetro de cabeçalhos) e `RespostaMl` | O refresh do ML é de uso único; o lock por conta já está resolvido |
| Resolver `{conta}` e redirecionar chave canônica | parser próprio | `ProgramasPublicadorService::resolver` + `PubProduto::ancoraComToken` | 404 de arquivada/sem programa, redirecionamento `company-N`→`empresa-N` |
| Trava por conta | `if (in_array(...))` espalhado | `AlavancasLiberadas` (molde de `ContasLiberadas`) | Fail-closed e separação por âncora já testados |
| Tarifa e conta de "recebe" | percentual fixo ou fórmula própria | `/sites/MLB/listing_prices` (público) + `SimuladorVoceRecebe::calcular` | RN-85: tarifa e frete vêm da API |
| Custo e imposto do produto | digitar custo na tela | `EstruturaPrecificacaoService::pagina()` (`custo.valor`, `parametros.imposto`) | Já resolve kit/combo e herança de frete |
| Assinatura da prévia | token em banco | `hash_hmac` com `app.key` | Sem estado, 10 linhas |
| Seleção/estilos de campo | CSS novo | `Mesa/comum.jsx` (`CAMPO`, `SELECT`, `Campo`, `Secao`), `Mesa/botoes.jsx` (`PRIMARIO`, `SECUNDARIO`, `BotaoAcao`) | Gate de visual |
| Datas e fuso | `strtotime` | Carbon com `America/Sao_Paulo` | O ML devolve `Z`, `-03:00` e sem fuso na mesma API |

**Insight central:** a parte difícil não é chamar o ML, é não deixar nenhuma escrita escapar da trava e guardar prova do que foi enviado. Por isso o desenho gira em torno de um único executor.

## Estado da arte (API do ML, lida em 04/10/2026)

| Antes | Agora | Desde | Impacto |
|-------|-------|-------|---------|
| PxQ absoluto `POST /items/{id}/prices/standard/quantity` | PxQ % B2B `POST /items/{id}/prices/price-per-quantity` | absoluto morre em **27/10/2026** | Só usar o % B2B `[CITED: developers.mercadolivre.com.br/pt_br/pxq-porcentagem-b2b, atualizada 01/10/2026]` |
| Métricas por anúncio `.../product_ads/ads/search` | Ad Groups `.../product_ads/ad_groups/search` | métricas de anúncios removidas em 30/05/2026 | D-09 precisa trocar o endpoint `[CITED: .../product-ads-para-catalogo-e-user-products-leitura, atualizada 06/07/2026]` |
| Listas de campanhas/itens legados de Product Ads | desligados (404) | 27/05/2026 | Não reaproveitar `ENDPOINT_ADS_ITEMS` nem `/advertising/advertisers/{id}/product_ads/campaigns` |
| `FIXED_PERCENTAGE` em campanha do vendedor | só `FLEXIBLE_PERCENTAGE` | jul/2025 | Mandar sempre `FLEXIBLE_PERCENTAGE` `[CITED: campanhas-do-vendedor]` |
| Desconto automático invisível | campos `boosted_offer`, `discount_meli_boost_amount`, `total_price_for_boosted_offer` | doc de 09/06/2026 | Mostrar "o ML banca" separado `[CITED: gerenciar-ofertas]` |
| `searchAfter` | `search_after` (o antigo ainda aceito "por um tempo") | — | Usar `search_after` |
| Custo fixo de venda só pelo preço | `fixed_fee` depende de `logistic_type`/`shipping_mode` | MLB desde 02/03/2026 | Passar `logistic_type` e `shipping_mode` do item em `listing_prices` `[CITED: comissao-por-vender, atualizada 03/09/2026]` |

## 1. Onde a barra entra (questão 1)

- **`Produtos.jsx`** (373 linhas): o bloco `<div className="mb-6"><ModoAnuncioTabs empresaId={abas.company_id} modo="individual" contaPublicador={empresa.chave} /></div>` (linha ~179-181) fica dentro de "Publicar". A barra nova entra logo acima dele, depois do cabeçalho. `empresa.chave` já está nas props (`ProgramasPublicadorService::empresaParaTela` devolve `chave`, `company_id`, `token`, `conta_nome`, `conta_ml_id`). `[VERIFIED: leitura do código]`
- **`ModoAnuncioTabs.jsx`**: `individual` já usa `contaPublicador ?? company-{empresaId}`; as outras abas desabilitam sem `Company`. A barra nova NÃO deve mexer nesse componente.
- **Controller:** `MlbPublicadorEntradaController::produtos()` ganha só `'alavancas' => ['url' => route(...), 'liberada' => AlavancasLiberadas::libera($ancora)]` se quiser mostrar o selo na barra; o resto não muda. A tela de Alavancas é do controller novo `MlbAlavancasController` (não inflar o da entrada).
- **Resolução da conta:** `ProgramasPublicadorService::resolver($conta)` → `['mlb_empresa','company','programa','chave']`; null → 404; `chave !== $conta` → `redirect()->route(<mesma rota>, ['conta' => $alvo['chave']])` (copiar o bloco de `produtos()`). A conta ML é `PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])` (MlbEmpresa antes de Company; ignora token `revoked`). Conta sem token: a página abre em estado "reconectar" (reaproveitar `empresaParaTela` → `token`, `link_reconexao`) e **nenhum** painel chama o ML.
- **Rotas propostas** (em `routes/mlb_anuncios.php`, dentro do grupo `role:admin`, depois da rota `publicador.produtos`):

```php
Route::prefix('publicador/empresas/{conta}/alavancas')
    ->where(['conta' => '(empresa|company)-[0-9]+'])->name('publicador.alavancas.')->group(function () {
        Route::get('/', [MlbAlavancasController::class, 'index'])->name('index');                                  // página (Inertia)
        Route::get('panorama', [MlbAlavancasController::class, 'panorama'])->middleware('throttle:60,1,alavancas.ler')->name('panorama');
        Route::get('promocoes', [MlbAlavancasController::class, 'promocoes'])->middleware('throttle:60,1,alavancas.ler')->name('promocoes');
        Route::get('promocoes/{promocao}/itens', [MlbAlavancasController::class, 'itensDaPromocao'])->where('promocao', '[A-Za-z0-9-]+')->middleware('throttle:120,1,alavancas.ler')->name('promocoes.itens');
        Route::get('produtos', [MlbAlavancasController::class, 'produtos'])->middleware('throttle:60,1,alavancas.ler')->name('produtos');
        Route::get('produtos/{item}/promocoes', [MlbAlavancasController::class, 'promocoesDoItem'])->where('item', 'MLB[0-9]+')->middleware('throttle:120,1,alavancas.ler')->name('produtos.promocoes');
        Route::post('analise', [MlbAlavancasController::class, 'analise'])->middleware('throttle:20,1,alavancas.analise')->name('analise');
        Route::get('cupons', [MlbAlavancasController::class, 'cupons'])->middleware('throttle:60,1,alavancas.ler')->name('cupons');
        Route::get('publicidade', [MlbAlavancasController::class, 'publicidade'])->middleware('throttle:30,1,alavancas.ler')->name('publicidade');
        Route::get('atacado', [MlbAlavancasController::class, 'atacado'])->middleware('throttle:60,1,alavancas.ler')->name('atacado');
        Route::get('atacado/{item}', [MlbAlavancasController::class, 'atacadoDoItem'])->where('item', 'MLB[0-9]+')->name('atacado.item');
        Route::post('atacado/{item}/recomendacoes', [MlbAlavancasController::class, 'recomendacoes'])->where('item', 'MLB[0-9]+')->middleware('throttle:30,1,alavancas.recomendacoes')->name('atacado.recomendacoes');
        Route::get('historico', [MlbAlavancasController::class, 'historico'])->name('historico');
        Route::get('lotes/{lote}', [MlbAlavancasEscritaController::class, 'lote'])->whereUuid('lote')->name('lotes');
        Route::post('escritas/previa', [MlbAlavancasEscritaController::class, 'previa'])->middleware('throttle:60,1,alavancas.previa')->name('escritas.previa');
        Route::post('escritas', [MlbAlavancasEscritaController::class, 'confirmar'])->middleware('throttle:30,1,alavancas.escrever')->name('escritas.confirmar');
    });
```

O `{promocao}` aceita `P-MLB…`, `C-MLB…`, `LGH-MLB…`, `DOD-MLB…` (letras, dígitos, hífen). Nomes ficam `mlb.anuncios.publicador.alavancas.*`. O Ziggy exporta todas as rotas (sem filtro) — `route('mlb.anuncios.publicador.alavancas.index', {conta})` funciona no JS. `[ASSUMED]` que o Ziggy não esteja filtrando por grupo: conferir `config/ziggy.php` no plano 01.

## 2. Chamadas autenticadas na conta (questão 2)

- **`seller_id` e reputação:** `GET /users/me` com o token da conta devolve `id`, `nickname`, `tags[]` e `seller_reputation.level_id`. **Provado pela fixture real da #459** (`tests/fixtures-ml/sondagem/conta/usuario.json`): `tags` inclui `"business"` e `"user_product_seller"`, `seller_reputation.level_id = "5_green"`, `seller_experience = "NEWBIE"`. `[VERIFIED: fixture capturada em 01/10/2026 na conta MGSTOREL]` O perfil público `/users/{id}` NÃO traz `tags` (learnings §3) — usar sempre `/users/me`.
- **O que já existe e o que não serve:** `ContaMlService::contexto()` já chama `/users/me`, mas faz também `shipping_preferences` e `stores/search` (2–3 chamadas a mais) e devolve só `tags`/modelo/envio — **não devolve reputação nem nickname**. Para Alavancas, criar `LeitorContaAlavancas` enxuto (uma chamada) que devolve `{seller_id, nickname, tags, business: bool, reputacao: level_id|null, experiencia}`; cache 5 min por conta. `ContextoConta` não precisa mudar.
- **O `seller_id` também está em `$ancora->mlToken->ml_user_id`** (sem chamada). Usar os dois: o do token para montar caminhos (`/seller-promotions/users/{id}`), e a conferência `users/me.id == ml_user_id` dentro de `EscritorAlavancas` (se divergir, é token de outro vendedor → `RECUSADA`).
- **`ClienteMlPublicador` não aceita cabeçalhos.** `daConta(conta, metodo, caminho, query, corpo, repetir)` monta só `withToken()->acceptJson()`. Ads (`api-version: 2`), PxQ (`X-Version`, `show-all-prices: true`) e o advertiser (`Api-Version: 1`) precisam de cabeçalho. **Mudança mínima e retrocompatível:** acrescentar o último parâmetro `array $cabecalhos = []` em `daConta()` e em `enviar()` (`->withHeaders($cabecalhos)`), com teste que garante que as chamadas existentes (`SimuladorVoceRecebe`, conferência, publicação) seguem idênticas. `[VERIFIED: leitura de ClienteMlPublicador.php]`
- **Classificação de erro precisa de complemento.** `RespostaMl::causas()` só entende `cause[].{code,message,type}` e `{error,message}`. O ML de promoções responde `{"message","error":"bad_request","status":400,"cause":[{"error_code":"ERROR_CREDIBILITY_DISCOUNTED_PRICE","error_message":"…"}]}` e o PxQ responde `{"error":"…","code":"item.version","status":409}` ou `{"code":"prices.validator.validation.failed","cause_id":5599,"error":"Amount above recommended"}`. Com o parser atual, o primeiro vira causa com `code=''` e o segundo cai em `UNKNOWN_FORMAT`. **Não alterar `RespostaMl`** (outras fases dependem); criar `MapeadorErroAlavanca` que lê `$resposta->corpo` cru e devolve `{codigo, mensagem_pt}`, com tabela dos códigos conhecidos (abaixo) e fallback "O Mercado Livre recusou: <mensagem original>".
- **423 `ENTITY_LOCKED`:** não está no backoff do cliente (só 429/5xx). Tratar em `EscritorAlavancas` (espera 2 s, até 3 tentativas, `dormir` injetável para o teste não dormir — mesmo padrão do construtor de `ClienteMlPublicador`).

## 3. Trava D-03 (questão 3)

Desenho em "Padrão 1". Pontos de decisão fechados:

- **Classe:** `App\Support\Publicador\AlavancasLiberadas` (irmã de `ContasLiberadas`). **Config:** `config('publicador.alavancas.contas_liberadas.{companies,mlb_empresas}')`. **Env:** `PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES` (default `459`) e `PUBLICADOR_ALAVANCAS_LIBERADAS_MLB_EMPRESAS` (default vazio). **Sem fallback** para `PUBLICADOR_EMPRESAS_PILOTO`/`PUBLICADOR_CONTAS_LIBERADAS_*` — a ausência desse fallback é a própria D-03 e vira teste (AL166-04).
- **Âncora checada = a que tem token** (`PubProduto::ancoraComToken`), como na 164. Company 5 ≠ MlbEmpresa 5.
- **Onde o servidor recusa:** `EscritorAlavancas::executar` (e o job de lote por item). Duas camadas extras baratas: `previa` já devolve `liberada:false` + motivo (a tela desliga o botão) e `confirmar` chama `exigir` antes de criar lote. Um teste de guarda (AL166-05) garante que nenhum outro arquivo faz escrita ao ML.
- **Leitura não é travada:** `GET`s funcionam em conta não liberada (D-03). A tela mostra `AvisoAlavancasTravadas` (faixa calma, sem vermelho/âmbar, como `AvisoContaTravada` em D21).
- **Conta de cliente: nunca escrever fora da lista.** Regra do usuário (memória + learnings §3): qualquer teste automatizado usa `Http::fake`; escrita real só na #459 e com confirmação do usuário antes de cada chamada.

## 4. Tabela de histórico D-05 (questão 4) — desenho por escrito

**Decisão de schema (CLAUDE.md, disciplina 2), escrita antes da migration.** Só tabela NOVA (`pub_alavanca_escritas`); nenhuma tabela existente muda. Migration própria, separada, sem `ALTER`: falha no meio não deixa "tabela criada sem índice + migration Pending" (learnings §6). Prefixo `pub_` e nomes curtos `pubale_*` (limite de 64 caracteres, erro 1059).

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint unsigned PK | |
| `lote_uuid` | `char(36)` nullable | agrupa itens de um lote; índice `pubale_lote_ix` |
| `mlb_empresa_id` | `foreignId` nullable | FK `pubale_empresa_fk` → `mlb_empresas`, **SET NULL** (coluna anulável: sem erro 1830) |
| `company_id` | `foreignId` nullable | FK `pubale_company_fk` → `companies`, **SET NULL** |
| `conta_chave` | `string(40)` | `empresa-N`/`company-N` do `chaveContaMl()` — sobrevive à exclusão da âncora (mesma razão do CR-B02: histórico não some quando a empresa é apagada) |
| `ml_seller_id` | `string(20)` | vendedor para quem foi enviado |
| `user_id` | `foreignId` nullable | FK `pubale_user_fk` → `users`, **SET NULL** |
| `ator_nome` | `string(120)` | nome na hora (sobrevive à exclusão do usuário) |
| `alavanca` | `string(16)` | `promocao` \| `cupom` \| `atacado` \| `exclusao` (sem enum: SQLite/MariaDB divergem) |
| `acao` | `string(32)` | `inscrever`, `alterar`, `remover`, `criar`, `excluir`, `gravar_faixas`, … |
| `promotion_type` | `string(40)` nullable | |
| `promotion_id` | `string(40)` nullable | |
| `item_id` | `string(20)` nullable | `MLB…`; índice `pubale_item_ix` |
| `metodo` | `string(6)` | |
| `caminho` | `string(255)` | sem query nem token |
| `payload` | `json` nullable | `{query, corpo}` enviados (NUNCA `Authorization`) |
| `resumo` | `json` nullable | o que a pessoa confirmou (preço atual → promoção, %, ML banca, recebe, margem) |
| `http_status` | `unsignedSmallInteger` nullable | |
| `resposta` | `json` nullable | corpo cru do ML, mesmo em erro; se vier texto não-JSON, `{"_texto": "..."}` |
| `resultado` | `string(10)` | `PENDENTE` (default) \| `OK` \| `ERRO` \| `INCERTO` \| `RECUSADA` |
| `erro_codigo` | `string(80)` nullable | `error_code`/`cause_id`/`code` extraído |
| `enviado_em` | `dateTime` nullable | `dateTime`, não `timestamp()` (MariaDB trata `timestamp` NOT NULL diferente) |
| `concluido_em` | `dateTime` nullable | |
| `created_at`/`updated_at` | `timestamps()` | |

**Índices:** `pubale_conta_ix` (`conta_chave`, `id`) — histórico por empresa, mais novo primeiro; `pubale_lote_ix` (`lote_uuid`); `pubale_item_ix` (`item_id`). As três FKs criam seu índice de apoio automaticamente pelo `constrained(..., nome)`. **Nenhum `unique`** (uma ação repetida é um registro novo; não há idempotência de banco aqui, a idempotência é a assinatura da prévia). Nenhum `enum`, nenhum default em `json`, nenhum `->change()`, nenhum `dropForeign` por nome no `down()` (só `dropIfExists`).

**Cuidados MariaDB que o SQLite não pega** (learnings §6 + `MigracoesDaFaseDetectamMariaDbTest`): (a) se a migration precisar saber o driver, comparar com `['mysql','mariadb']`, nunca `=== 'mysql'` — e adicionar o arquivo à lista `MIGRACOES` daquele teste; (b) `json` vira `longtext` com CHECK no MariaDB — resposta grande cabe, mas não indexar; (c) rodar no MariaDB **local** com `--path` antes de pedir deploy, NÃO nesta pesquisa (banco compartilhado); (d) nome de índice de tabela longa: já nomeados à mão.

**Retenção/LGPD:** `resposta` de promoções/cupons/PxQ não traz dado pessoal de comprador; mas nunca gravar `/users/me` inteiro aqui. `[ASSUMED]` — respostas de `seller-promotions` e `prices` não contêm PII (visto nos exemplos da doc); confirmar com as fixtures reais da #459.

## 5. Listar os produtos da conta (questão 5)

- **O acervo da Fase 134 não serve como fonte única:** `MlAcervoService` e `MlAcervoItem` são `company_id`-only (`enumerarIds(Company $company)`), e 535 de 539 `MlbEmpresa` não têm `Company` (learnings da 164). `[VERIFIED: leitura]`
- **Caminho ao vivo, igual para as duas âncoras:** `GET /users/{seller}/items/search` com `status=active`, `limit=50` e `offset`, depois multiget `GET /items?ids=MLB1,MLB2,…` em lotes de **20** (teto real confirmado por erro da própria API, `config/mlb_acervo.php`). 50 ids = 3 multigets. `attributes` do multiget: `id,title,price,original_price,base_price,status,available_quantity,sold_quantity,listing_type_id,category_id,condition,shipping,permalink,thumbnail,catalog_listing,tags,variations,seller_custom_field,attributes`. Resposta do multiget é `[{code, body}]`; item com `code != 200` é pulado com log (padrão de `MlAcervoService::buscarLotes`).
- **Paginação:** `offset` estoura em ~1000–1100 itens (erro real documentado em `MlAcervoService`); `search_type=scan` + `scroll_id` (TTL 5 min) serve para varrer tudo, mas escolher produtos numa tela não varre. **Decisão:** a tela pagina por `offset` até 1000 e, acima disso, mostra "refine a busca por SKU ou título"; a busca usa `seller_sku` (já provado: `tests/fixtures-ml/sondagem/conta/items_search_seller_sku.json`) e, para título, `q=` `[ASSUMED: o parâmetro q existe em /users/{id}/items/search — não confirmado nesta pesquisa; verificar com GET real na #459 no Wave 0 e, se não existir, cair para filtro local da página de 50 itens]`.
- **Elegibilidade (filtro local, só alerta):** `status=active`, `condition=new`, `listing_type_id != free`, sem `variations` com preço divergente (preço por variação: `GET /seller-promotions/items/{id}` é por item). Elegível de verdade é decidido pelo ML — em desconto individual o item candidato aparece em `GET /seller-promotions/items/{item}` com `status: candidate` e `min_discounted_price`/`max_discounted_price`/`suggested_discounted_price` `[CITED: gerenciar-ofertas]`; usar esses números para sugerir preço (uma chamada por produto selecionado, não por linha da lista).
- **Cache e limite:** produtos 5 min por (conta, busca, página). Teto por requisição: 50 ids → ≤ 4 chamadas ao ML. Sem pré-carregar a conta inteira.
- **Quando a âncora tem `Company`:** pode-se pré-ordenar por vendas usando `ml_acervo_items.sold_quantity` — fora do escopo (ranking adiado).

## 6. "Quanto recebe" em lote e margem (questão 6)

**O que o Publicador faz hoje** (`EditorRascunhoService::simular()`): `listing_prices` público com `logistic_type=drop_off`, `shipping_mode=me2` fixos + frete via `daConta GET /users/{seller}/shipping_options/free` com `dimensions` do rascunho. Para anúncio **já publicado** isso muda em três pontos:

1. **Tarifa:** `GET /sites/MLB/listing_prices?price=&category_id=&listing_type_id=&currency_id=BRL&logistic_type=<item.shipping.logistic_type>&shipping_mode=<item.shipping.mode>` (a doc de 03/09/2026 diz que sem `logistic_type`/`shipping_mode` o `fixed_fee` não bate com o cobrado). Resposta: `sale_fee_amount` (e `sale_fee_details.fixed_fee`, `percentage_fee`, `gross_amount`). É chamada pública (app token): `ClienteMlPublicador::publico`. **Cache por (category, listing_type, logistic, mode, preço arredondado a centavos) por 1 h** — vários itens da mesma categoria/preço dividem a chamada. `[CITED: comissao-por-vender]`
2. **Frete do vendedor:** `GET /users/{seller}/shipping_options/free` (conta) com `item_price`, `listing_type_id`, `mode`, `condition`, `logistic_type`, `dimensions` (`AxLxH,peso` em cm e g), `verbose=true` → `coverage.all_country.list_cost`. As dimensões de um anúncio publicado vêm de `item.shipping.dimensions` (pode vir `null` em UP — o exemplo da doc de PxQ mostra `"dimensions": null`) ou dos atributos `SELLER_PACKAGE_*` (g e cm, learnings §10). **Sem dimensões = frete desconhecido** → `SimuladorVoceRecebe::calcular($preco, $tarifa, null)` calcula sem frete e `frete_conhecido=false` (já é o contrato da classe); a tela diz "sem frete" em vez de inventar. Item sem frete grátis (`shipping.free_shipping=false`) = frete do vendedor 0, conhecido. `[VERIFIED: leitura de EditorRascunhoService::simular e da doc custos-de-envio]`
3. **Rate limit:** não há limite documentado nesta pesquisa; o projeto usa 60 req/min por vendedor no `ml-api` (Sugadores) e o cliente já faz backoff 429 (1, 2, 4, 8, 16 s ou `Retry-After`). Regras de custo: (a) a análise é **sob demanda** (botão "Analisar" na seleção, não ao listar); (b) no máximo **10 produtos por chamada** a `POST alavancas/analise` (cada produto = 2 tarifas [normal e promoção, dividem cache] + 2 fretes); (c) cache de frete por (seller, item, preço, listing_type) 1 h; (d) concorrência 1 (série), nada de `Http::pool`. Teto de ≈ 40 chamadas por clique no pior caso; o throttle `20,1` da rota limita a 800/min no teórico — por isso o `RateLimiter` por conta: `RateLimiter::attempt("alavancas:analise:{$chave}", 120, fn, 60)` e, estourado, devolver o que já calculou com `parcial:true`.

**Fórmulas (D-11):**
- `recebe_normal = preco_atual − tarifa(preco_atual) − frete(preco_atual)`.
- Promoção `DEAL`, `SELLER_CAMPAIGN`, `PRICE_DISCOUNT`, `DOD`, `LIGHTNING`: `recebe_promo = preco_promo − tarifa(preco_promo) − frete(preco_promo)`.
- **Cofinanciadas** (`MARKETPLACE_CAMPAIGN`, `SMART`, `PRICE_MATCHING`, `VOLUME` do ML, `PRE_NEGOTIATED`, `UNHEALTHY_STOCK`): o item traz `meli_percentage` e `seller_percentage` (sobre `original_price`). "ML banca" = `original_price × meli_percentage / 100`. O comprador paga `price`; a loja recebe `price + rebate_do_ML` antes de tarifa e frete. `[ASSUMED]` que a tarifa incide sobre `price` (o que o comprador paga) e que o rebate entra como receita — a doc não explicita; **validar na #459 com uma oferta cofinanciada real comparando com o Seller Center** (mesma técnica da H-14 do Publicador: print do Seller Center). Até validar, mostrar o rebate como linha separada ("o ML paga R$ X") e marcar o total "estimativa".
- **Boost** (`boosted_offer:true`): `discount_meli_boost_amount` é "desconto nos custos por venda compensado pelo ML" e `total_price_for_boosted_offer` é o preço que o comprador vê `[CITED: gerenciar-ofertas]`. Mostrar como linha "desconto extra do ML nos custos: R$ X" e **não** somar na fórmula até validar (Questão Aberta 3).
- **`VOLUME`/cupom:** candidato tem `price: 0` (o desconto é no carrinho). Mostrar o desconto efetivo (`item_discount_percent` do VOLUME; `fixed_amount`/`fixed_percentage` do cupom) e **não** calcular "recebe" em preço absoluto — mostrar "depende da quantidade/carrinho". Evita número inventado.

**Margem com custo — o caminho real (investigado):**

```
item MLB… ──(EstruturaAnuncio.codigo_mlb, whereIn)──▶ oferta_id ──▶ EstruturaOferta (company_id)
        ──▶ EstruturaPrecificacaoService::pagina($company, [$ofertaId])['por_oferta'][$ofertaId]['custo']['valor']
        ──▶ parâmetros da empresa (['parametros']['imposto'] em %)
```

- `estrutura_anuncios.codigo_mlb` é o vínculo oficial MLB→oferta (aba Anúncios do Portal; `AnunciosMercadoLivreService` e `EstruturaConjunto` leem por aí). Só existe para empresa **com `Company`** (oferta é por `company_id`). Para `MlbEmpresa` sem `Company`: **sem custo → sem margem** (D-11: "sem custo, não pede para digitar").
- Um segundo caminho cobre anúncio recém-publicado pelo Publicador que ainda não virou `codigo_mlb`: `pub_publicacao_itens.ml_item_id → pub_publicacoes → pub_rascunhos → pub_produtos.oferta_id`. Implementar como fallback (uma consulta com join), documentado como tal.
- `pagina()` calcula a empresa inteira em cada chamada (percorre `conjunto->ofertas()`); chamar **uma vez por requisição de análise**, não por item. Custo composto de kit/combo já vem resolvido em `custo.valor`; `null` = sem custo.
- **Margem exibida** = `recebe_promo − custo − (preco_promo × imposto/100)`; em R$ e em % do preço da promoção. O `imposto` é o da Precificação (`PrecificacaoEstrutura::preco` usa `comissao + imposto + mc + ll` como divisor — a margem de contribuição e o lucro líquido-alvo NÃO entram na conta de "margem real", só o imposto). `[VERIFIED: leitura de PrecificacaoEstrutura]`
- Teste de erro conhecido: SKU repetido gera mais de uma oferta (`EstruturaOferta` doc): `whereIn('codigo_mlb')` devolve uma linha por anúncio, então a ligação é por MLB, não por SKU — não usar SKU como chave.

## 7. Publicidade só leitura (questão 7)

**Comparação com `MercadoLivreAdsService` (leitura do código vs doc de 06/07/2026):**

| Uso hoje no serviço | Doc atual | Veredito |
|---|---|---|
| `GET /advertising/advertisers?product_id=PADS` + `Api-Version: 1` | igual `[CITED]` | **reaproveitar** a ideia |
| `GET /marketplace/advertising/MLB/advertisers/{id}/product_ads/campaigns/search` (comentário: "comprovado em produção, Fase 20") | doc: `GET /advertising/{SITE}/advertisers/{id}/product_ads/campaigns/search`, `api-version: 2` | **usar o caminho da doc**; o prefixo `/marketplace/` não aparece na documentação e não está na lista de desligados — não depender dele `[ASSUMED que ainda responde; irrelevante se usarmos o documentado]` |
| `GET /advertising/advertisers/{id}/product_ads/items` (`ENDPOINT_ADS_ITEMS`) | **desligado em 27/05/2026 (404)** | **NUNCA** |
| (CONTEXT D-09) `.../product_ads/ads/search` | métricas de anúncios **removidas em 30/05/2026**, "substituído pelas métricas de Ad Group" | **NÃO usar**; trocar por Ad Groups |

**Endpoints para a fase (todos GET, host `https://api.mercadolibre.com`):**
- Anunciante: `/advertising/advertisers?product_id=PADS` (`Api-Version: 1`) → `advertisers[].{advertiser_id, site_id, advertiser_name, account_name}`. 404 `No permissions found for user_id` = conta sem Product Ads habilitado → mostrar "Product Ads não está ativo nesta conta".
- Campanhas + métricas: `/advertising/MLB/advertisers/{advertiser_id}/product_ads/campaigns/search?limit=50&offset=0&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&metrics=clicks,prints,ctr,cost,cpc,acos,roas,units_quantity,total_amount&metrics_summary=true` (`api-version: 2`). `date_from` obrigatório se houver `metrics`; janela de até **90 dias para trás**; métricas atualizam às 10:00 GMT-3. Campos de campanha: `id, name, status, daily_budget, budget, currency_id, acos_target, strategy (PROFITABILITY|INCREASE|VISIBILITY)`.
- Ad Groups + métricas por advertiser: `/advertising/MLB/advertisers/{advertiser_id}/product_ads/ad_groups/search?date_from&date_to&limit&sort=desc&sort_by=clicks&metrics=…` (`api-version: 2`). Item → ad_group: `filters[item_ids]=MLB1,MLB2` (plural; o singular `filters[item_id]` não é mais aceito). Resposta traz `metrics_summary` e `campaign_id` (0 = fora de campanha).
- Bonificações: `GET /advertising/advertisers/bonifications` → `bonification[]` com `status, level (Campaign|Account), amount, balance, end_date, days_remaining, benefit_name, campaign_id/campaign_name/campaign_status`. 200 com array vazio = sem bonificação.
- **Cache do advertiser:** `MlAdvertiser` é `company_id`-only. Como MlbEmpresa sem Company não cabe, **não criar coluna nem tabela**: cachear `advertiser_id` no `Cache` por `chaveContaMl()` por 24 h (`Cache::remember("alavancas:ads:adv:{$chave}", 86400, …)`), só se vier `advertiser_id`.
- **Backoff:** o do `ClienteMlPublicador` (429 com `Retry-After`/exponencial, 5xx de leitura 3×) basta; o `RateLimiter` do `MercadoLivreAdsService` é de job de fila (aborta), inadequado para tela.
- **Garantia "só leitura":** `PublicidadeLeitura` só chama `daConta('GET', …)`; o teste de AL166-14 varre o arquivo.
- **Permissão:** o token já pede `read write offline_access`; a permissão funcional "Advertising" do app ECF no DevCenter é necessária só para escrita (fora da fase). Leitura de campanhas depende da permissão do app? `[ASSUMED: leitura funciona com o escopo atual — foi o que sustentou a Fase 20/41 em produção]`.

## 8. Atacado D-10 (questão 8)

Fonte: `pxq-porcentagem-b2b` (atualizada 01/10/2026) `[CITED]`. Fluxo completo e corpo exato:

1. **Habilitado?** tag `business` em `GET /users/me`. Sem ela: aba explica "o Mercado Livre libera o preço por quantidade por convite; esta conta não tem essa liberação". (A #459 **tem** `business` na fixture — a E2E de atacado é possível nela.)
2. **Ler faixas atuais:** `GET /items/{id}/prices?display_version=true` com cabeçalho `show-all-prices: true` → `{id, prices[], version, …}`; depois de configurado, a resposta traz `price_per_quantity[]` (`id, type:"discount_percentage", percentage, conditions.{context_restrictions, min_purchase_unit, eligible}`) `[CITED: resposta do POST na doc; a presença de price_per_quantity no GET com show-all-prices é [ASSUMED] — confirmar em fixture real]`. Item com PxQ tem a tag `standard_price_by_quantity` em `/items/{id}` (serve para marcar na lista).
3. **Recomendação (obrigatória antes de gravar, segundo a doc):** `POST /prices-per-quantity/v1/recommendations` com `{item_id, range_item_quantities:[2,5,10] (≤5, cada ≥1), price:{standard_amount, currency:"BRL"}}` → `recommendations[].{quantity, amount, discount.percentage, is_incoherent_quantity, profit, shipping}`. HTTP **204** = sem recomendação, "o seller pode definir o que considerar adequado". `is_incoherent_quantity:true` → o ML recusa gravar essa quantidade (erro 5598). Mostrar a recomendação ao lado do campo e pré-preencher com `discount.percentage`.
4. **Gravar:** `POST /items/{id}/prices/price-per-quantity` com cabeçalho `X-Version: <version lida no passo 2, relida imediatamente antes>` e corpo:

```json
{ "price_per_quantity": [
  { "type": "discount_percentage", "percentage": 3.63,
    "conditions": { "context_restrictions": ["channel_marketplace","user_type_business"], "min_purchase_unit": 2, "eligible": true } }
] }
```

   Regras: ≤ 5 faixas; `percentage` > 0 e < 100; `min_purchase_unit` inteiro ≥ 1 e ≤ 100 (1 = "desconto na unidade", risca o preço base); desconto **crescente** com a quantidade; `eligible` obrigatório `true`; só uma tabela por item. **Semântica de edição:** enviar o `id` de uma faixa existente = mantém; omitir = **exclui**; enviar sem `id` = cria; não há "atualizar" (alterar = excluir + criar). Corpo `{"price_per_quantity": []}` apaga todas. **A tela envia SEMPRE a lista completa, com o `id` das faixas inalteradas.**
5. **Migração do absoluto:** se o item já tem PxQ absoluto, o POST % dá 400 `Cannot add price per quantity by percentage when a standard price per quantity…`; a saída é `?remove-absolute-pxq=true` na URL — destrutiva, exigir confirmação explícita na prévia ("vai substituir as faixas em valor fixo atuais").
6. **Erros (todos `[CITED]`), para o `MapeadorErroAlavanca`:** 400 `Version must be provided` (faltou `X-Version`); **409 `item.version`** ("The version provided is not the current one") → relê a versão e mostra "alguém alterou o preço; revise e confirme de novo" (NÃO repete sozinho); 400 `Price per quantity with id N not found`; 400 `Percentage must be greater than 0 and less than 100`; 400 `Condition eligible must be true`; 400 cause 5512 `invalid coherence order`; 400 cause 5599 `Amount above recommended` (percentual menor que o recomendado); 400 cause 5598 `Quantity is incoherent`; 400 `Maximum 5 price_per_quantity entries…`; 400 cause 5531 `Category is not enabled for B2B PxQ pricing` (pneus: só B2C, fora da fase).
7. **Nunca** chamar `/items/{id}/prices/standard/quantity` (morre em 27/10/2026 para B2B; `precos-liquidos` continua nele, mas é outra funcionalidade, fora da fase). Teste de guarda por grep na fonte.
8. **Notificação** `items prices` existe mas leitura é sob demanda (adiado).

## 9. Alertas D-12 (valores iniciais, a critério do planejamento)

Sugestão, em `config('publicador.alavancas.alertas')`: `convite_vence_em_dias => 3`; `recebido_queda_percentual => 10` (X do D-12: alerta quando `recebe_promo < recebe_normal × (1 − 10%)`); `estoque_minimo_dod_lightning` vem do próprio item (`stock.min`/`stock.max` em DOD/LIGHTNING) — alerta se `available_quantity < stock.min`. **Reputação:** `level_id` válidos conhecidos nos exemplos: `5_green`; os demais níveis (`4_light_green`, `3_yellow`, `2_orange`, `1_red`, ou `null` para vendedor novo) — `[ASSUMED]` a lista exata e se `4_light_green` conta como "verde" para a promoção; por isso a regra vira `config('publicador.alavancas.reputacao_ok') = ['5_green','4_light_green']` e só **alerta** (nunca bloqueia o botão): quem decide é o ML, e a resposta crua dele fica no histórico. Os alertas saem de `AnaliseAlavancasService::alertas()`, sem ordenação por prioridade (sem ranking).

## 10. Frontend e visual (questões 10–11)

- **Gates já existentes que se aplicam** (`tests/js/publicador-entrada.test.js`, `publicador-mesa.test.js`): só 24/15/13/11 px, só pesos 400/700, nenhum `bg-ecf-yellow` sólido, nenhum `Components/ui/select` (Radix), nenhum `dangerouslySetInnerHTML`, nenhum `uppercase`, nada de "Faltam N"/"Completo"/"N/8". O único amarelo sólido é `PRIMARIO` em `Components/Publicador/Mesa/botoes.jsx` (`BotaoAcao primario`) — importar de lá no `ModalConfirmacao` ("Confirmar" é o próximo passo).
- **Criar `tests/js/publicador-alavancas.test.js`** com a lista `ARQUIVOS` dos componentes novos aplicando os mesmos gates (copiar o padrão de `publicador-entrada.test.js`), mais: a barra é componente próprio e `Produtos.jsx` a importa; nenhum arquivo de Alavancas contém `/prices/standard/quantity`, `product_ads/items` nem `ads/search`; nenhum importa o wizard antigo; `AvisoAlavancasTravadas` sem `red-`/`amber-`.
- **Componente órfão não compila:** `Alavancas.jsx` precisa importar todos os subcomponentes e a rota Inertia precisa existir (`testing.ensure_pages_exist = true`: o teste de feature falha se `Pages/Mlb/Publicador/Alavancas.jsx` não existir). Depois do build, conferir o manifest do Vite.
- **Texto:** pt-BR; nada de termos de ML em inglês sem tradução na tela (DEAL → "Campanha tradicional", MARKETPLACE_CAMPAIGN → "Cofinanciada pelo ML", LIGHTNING → "Oferta relâmpago", DOD → "Oferta do dia", PRICE_DISCOUNT → "Desconto individual", SELLER_CAMPAIGN → "Campanha do vendedor", VOLUME → "Leve mais, pague menos", PRE_NEGOTIATED → "Desconto pré-acordado", SMART → "Cofinanciada automatizada", PRICE_MATCHING → "Preço competitivo", UNHEALTHY_STOCK → "Liquidação de estoque Full"). Mapa em um só arquivo JS (`rotulos.js`), espelhado por teste contra a lista de tipos do servidor.
- **Conferência visual:** SQLite isolado + Puppeteer, roteiro do learnings §7 (`C:/tmp/ecf-publicador-melhoria-visual/analise/capturar.mjs`); semear com um PHP avulso que se recusa a rodar se `database.default` não for `sqlite`; `Http::fake` não existe fora do PHPUnit, então a captura usa dados semeados em uma rota de fixture ou um `ClienteMlPublicador` substituído por um dublê carregado do JSON — **decisão do plano de UI**, não da pesquisa.

## Matriz de regras por tipo de promoção (D-07, AL166-09)

Tudo com `?app_version=v2` na URL. `[CITED]` = página oficial lida em 04/10/2026 (data da página entre parênteses).

| Tipo (rótulo) | Inscrever (`POST /seller-promotions/items/{item}`) | Alterar | Remover (`DELETE .../items/{item}?…`) | Observações |
|---|---|---|---|---|
| `DEAL` (campanha tradicional) | `{promotion_id, promotion_type:"DEAL", deal_price, top_deal_price?}` | `PUT` mesmo corpo | `promotion_type`, `promotion_id` | `deal_price` fora do sugerido → 400 `ERROR_CREDIBILITY_DISCOUNTED_PRICE`; listar `min/max/suggested_discounted_price` do item da promoção `[CITED campanhas-tradicionais, 09/06/2026]` |
| `MARKETPLACE_CAMPAIGN` (cofinanciada) | `{promotion_id, promotion_type}` **sem preço** | **não há** (remover → mudar preço fora → reinscrever) | `promotion_type`, `promotion_id`, `offer_id` | aumento de preço do item o tira sozinho e impede reinscrição `[CITED campanha-com-co-participacao, 23/01/2025]` |
| `VOLUME` (leve X pague Y; ML ou vendedor) | `{promotion_id, promotion_type}` sem preço | **não há** (idem cofinanciada) | `promotion_type`, `promotion_id`, `offer_id` | candidato `price:0`; subtipos `BNGM`/`BNSP`/`SPONTH` `[CITED campanhas-de-desconto-por-quantidade, 23/01/2025]` |
| `DOD` (oferta do dia) | `{promotion_type:"DOD", deal_price}` (sem `promotion_id`) | **não se edita** | `promotion_type` — **só a oferta programada** | "uma vez ativada, não é possível remover"; pausar o anúncio é a saída; `stock.min/max` informativo `[CITED ofertas-do-dia, 23/01/2025]` |
| `LIGHTNING` (relâmpago) | `{promotion_type:"LIGHTNING", deal_price, stock}` | **não se edita** | `promotion_type` — **só a programada** | estoque reservado; esgotou, encerra `[CITED ofertas-relampago, 09/06/2026]` |
| `PRICE_DISCOUNT` (desconto individual) | `{deal_price, top_deal_price?, start_date, finish_date, promotion_type:"PRICE_DISCOUNT"}` | **não se edita** (excluir e criar) | `promotion_type` (remove a oferta inteira) | 5% ≤ desc < 80%; ≤ 14 dias; datas inteiras; reputação verde, item ativo/novo/não-grátis; DEAL ativo adia o início; aumentar preço remove `[CITED desconto-individua, 09/06/2026]`. Candidato traz `min/max/suggested_discounted_price` em `GET /seller-promotions/items/{item}` |
| `PRE_NEGOTIATED` / `UNHEALTHY_STOCK` | `{promotion_id, offer_id, promotion_type}` (aceitar; **sem preço**) | — | `promotion_type`, `promotion_id`, `offer_id` | preço já acordado; `offer_id` vem na lista de itens `[CITED desconto-pre-acordado-por-item, 09/06/2026]` |
| `SELLER_CAMPAIGN` (vendedor) | `{promotion_id, promotion_type, deal_price, top_deal_price?}` | `PUT` item: só os campos que mudam; iniciada: **preço só baixa**, `top_deal_price` não entra/sai; pendente: livre; `remove_loyalty:true` | `promotion_type`, `promotion_id` | campanha: `POST/PUT/DELETE /seller-promotions/promotions[/{id}]`; ≤ 14 dias; `FLEXIBLE_PERCENTAGE`; start ≥ hoje; nome único `[CITED campanhas-do-vendedor, 28/08/2025]` |
| `SMART` / `PRICE_MATCHING` | `{promotion_id, promotion_type, offer_id}` — `offer_id` é o **candidato** (`CANDIDATE-…`) | — | `promotion_type`, `promotion_id`, `offer_id` | SMART ≤ 30 dias, PRICE_MATCHING ≤ 10; candidatos mudam todo dia; `PRICE_MATCHING_MELI_ALL` é automático (não há inscrever) `[CITED campanhas-smart-price-matching, 09/06/2026]` |
| `SELLER_COUPON_CAMPAIGN` (cupom) | `{promotion_id, promotion_type}` sem preço | itens não se alteram | `promotion_type`, `promotion_id` | desconto no checkout; resposta `price:0` `[CITED cupons-do-vendedor, 28/08/2025]` |
| Lista de exclusão | `POST /seller-promotions/exclusion-list/seller` `{exclusion_status:"true"\|"false"}`; `POST .../exclusion-list/item` `{item_id, exclusion_status}` | — | — | leitura: `GET .../exclusion-list/seller`, `.../exclusion-list/seller/{item_id}` `[CITED gerenciar-ofertas]` |
| Remover de todas | `DELETE /seller-promotions/items/{item}` | — | — | resposta `{successful_ids[], errors[]}`; **não vale** para DOD/LIGHTNING. Tratar parcial (`errors`) como resultado `ERRO` com detalhe |

**Criação/alteração de campanha** (`POST /seller-promotions/promotions`):
- `SELLER_CAMPAIGN`: `{promotion_type, name, sub_type:"FLEXIBLE_PERCENTAGE", start_date, finish_date}`; `PUT` envia só o que muda + `promotion_type`; `DELETE …?promotion_type=SELLER_CAMPAIGN`.
- `VOLUME` do vendedor: `{promotion_type:"VOLUME", sub_type: BNGM|BNSP|SPONTH, buy_quantity, pay_quantity (BNGM) | discount_percentage (BNSP, SPONTH), allow_combination, name, start_date, finish_date}`; campanha ativa só muda `name`; programada exige reenviar todos os atributos do subtipo; datas não são editáveis.
- `SELLER_COUPON_CAMPAIGN`: corpo no requisito AL166-13; a data final aceita no máx. 31 dias e mín. 1 dia.

**De onde vêm as ações de cada tela:** convite = `GET /seller-promotions/users/{seller}` (`results[]` com `id,type,status,start_date,finish_date,deadline_date,name,benefits`; paginação `paging.offset/limit/total`) → itens da promoção `GET …/promotions/{id}/items?promotion_type=X[&status=candidate][&status_item=active|paused][&limit=50][&search_after=…]`; produtos de uma promoção que o usuário escolhe pela lista da conta → `GET /seller-promotions/items/{item}`.

## Migration (resumo; desenho completo em §4)

`database/migrations/2026_10_05_100000_create_pub_alavanca_escritas_table.php` — só `Schema::create` + `dropIfExists` no `down()`. Cabeçalho do arquivo com a tabela de decisão (como `2026_10_02_100000_create_pub_produtos_table.php`). Teste: `tests/Feature/Publicador/Alavancas/MigracaoHistoricoTest.php` verifica colunas, que `nullOnDelete` está em colunas anuláveis, nomes de índice ≤ 64, ausência de `enum`/`->change()`, e que apagar `MlbEmpresa`/`Company`/`User` mantém a linha (SET NULL) — o SQLite dos testes liga FK (`PRAGMA foreign_keys`)? **Conferir**: se `DB_FOREIGN_KEYS` não estiver ligado, o teste de SET NULL precisa chamar `Schema::enableForeignKeyConstraints()` (ver `ExclusaoDaEmpresaPreservaHistoricoTest.php` da 164, que já resolve isso). A prova real do MariaDB (1059/1553/1830) é rodar no banco **local** com `--path` e `SHOW CREATE TABLE` — passo de verificação manual, não desta pesquisa.

## Armadilhas comuns

### Armadilha 1: aumentar preço remove PRICE_DISCOUNT (e tira de cofinanciada/VOLUME)
**O que dá errado:** quem mexe no preço do anúncio (ERP, edição no ML, futura escrita do Publicador) derruba a promoção sem aviso.
**Por que acontece:** regra do ML `[CITED desconto-individua; campanha-com-co-participacao; campanhas-de-desconto-por-quantidade]`.
**Como evitar:** nenhum código do projeto grava preço em anúncio existente (verificado por grep em `app/`: sem `PUT /items`); manter assim e anotar no plano que uma futura escrita de preço consulte `GET /seller-promotions/items/{id}` antes. Na tela, nota fixa: "Alterar o preço do anúncio depois derruba o desconto".
**Sinal de alerta:** item some de `started` sem ação sua.

### Armadilha 2: `search_after` expira em 5 minutos e não volta
**O que dá errado:** abrir a promoção, demorar, clicar "mais" e receber erro; ou guardar o cursor para depois.
**Como evitar:** o cursor viaja só no pedido seguinte (nunca em cache); a tela guarda a lista já carregada e, ao erro de cursor, reinicia a promoção (padrão de `MlAcervoService::scroll`, que reinicia UMA vez). Cache de página (60 s) é por (promoção, cursor-recebido), sem reuso entre sessões.
**Sinal:** 400/404 no `search_after`.

### Armadilha 3: `423 ENTITY_LOCKED`
Item travado por segundos após outra escrita. Repetir até 3× com 2 s; persistindo, resultado `ERRO` com texto "item ocupado, tente de novo em instantes" e resposta crua guardada. Nunca em loop infinito.

### Armadilha 4: DOD e LIGHTNING não se editam e, ativos, não se removem
A UI desliga "alterar" para ambos e "remover" quando `status = started`. Mostrar o motivo ("oferta ativa não pode ser retirada; pause o anúncio no Mercado Livre se precisar").

### Armadilha 5: reputação verde obrigatória e "item novo, ativo, não-grátis"
Pré-requisito de desconto individual, campanha do vendedor e cupom. Alertar (D-12) mas deixar o ML decidir; guardar a recusa no histórico.

### Armadilha 6: formato de erro diferente do que `RespostaMl` entende
Ver §2. Sem `MapeadorErroAlavanca`, o usuário veria "UNKNOWN_FORMAT" em 409/PxQ. O teste usa as respostas reais copiadas da doc.

### Armadilha 7: `Http::fake` acumula; o primeiro stub que casa vence
Registrar o fake UMA vez com closures que leem propriedades do teste (learnings §5). Nesta fase isso é crítico porque a mesma URL (`/seller-promotions/items/MLB1`) responde coisas diferentes para GET, POST e DELETE: casar por método dentro da closure (`$request->method()`), nunca por chamar `Http::fake` de novo.

### Armadilha 8: o endpoint absoluto de PxQ morre em 27/10/2026
Qualquer fixture ou exemplo copiado de blog com `/prices/standard/quantity` está errado para B2B. Gate de grep.

### Armadilha 9: lote com `self::dispatch()` dentro do job
Recursa no driver `sync` (testes, máquina local). `release()` + `retryUntil()` (learnings §6).

### Armadilha 10: `X-Version` velho
A versão do preço muda a cada escrita (inclusive de outras ferramentas). Reler imediatamente antes do POST (dentro de `EscritorAlavancas`, como primeira `requisicao` da ação `GravarAtacado`) e tratar 409 sem repetir às cegas.

### Armadilha 11: `RespostaMl` classifica 403 como `PERMISSION`
Em promoções, 403 provavelmente significa "app sem a permissão funcional Promoções" (166-PESQUISA-API §6). Mensagem própria: "O aplicativo ECF não tem permissão de Promoções no DevCenter" — e tratar como pré-requisito de ambiente (Questão Aberta 5).

### Armadilha 12: fuso das datas
`start_date`/`finish_date` vão em "formato local" sem fuso (`2023-07-17T00:00:00`) e o ML considera só a data. O servidor usa `America/Sao_Paulo`; a validação de "start ≥ hoje" é pela data de São Paulo, não UTC. A leitura mistura `Z`, `-03:00` e sem fuso; normalizar com Carbon antes de comparar `deadline_date` (alerta de vencimento).

### Armadilha 13: componente órfão e página inexistente
Ver §10. `ensure_pages_exist` e manifest.

## Exemplos de código (formas, não implementação final)

### Teste de trava: liberar um não libera o outro
```php
// tests/Feature/Publicador/Alavancas/TravaAlavancasTest.php
config(['publicador.contas_liberadas.companies' => [459], 'publicador.alavancas.contas_liberadas.companies' => []]);
$this->assertTrue(ContasLiberadas::libera($company459));
$this->assertFalse(AlavancasLiberadas::libera($company459));       // publicação liberada NÃO libera alavancas

config(['publicador.contas_liberadas.companies' => [], 'publicador.alavancas.contas_liberadas.companies' => [459]]);
$this->assertFalse(ContasLiberadas::libera($company459));
$this->assertTrue(AlavancasLiberadas::libera($company459));
$this->assertFalse(AlavancasLiberadas::libera($mlbEmpresa459));    // Company 459 ≠ MlbEmpresa 459
```

### `Http::fake` com closure que lê o estado do teste (learnings §5)
```php
protected string $respostaDoPost = 'ok';   // o teste muda a PROPRIEDADE, não o fake
protected array $chamadas = [];

Http::fake(function (\Illuminate\Http\Client\Request $req) {
    $this->chamadas[] = [$req->method(), $req->url()];
    if (str_contains($req->url(), '/users/me')) return Http::response($this->usuario, 200);
    if ($req->method() === 'POST' && str_contains($req->url(), '/seller-promotions/items/')) {
        return $this->respostaDoPost === 'bloqueado'
            ? Http::response(['message' => 'Item locked', 'error' => 'entity_locked', 'status' => 423], 423)
            : Http::response(['price' => 4000, 'original_price' => 5000], 200);
    }
    return Http::response([], 404);
});
// prova da trava: conta fora da lista → nenhuma chamada com método ≠ GET
$this->assertSame([], array_filter($this->chamadas, fn ($c) => $c[0] !== 'GET'));
```
Conferir com mutação que o teste QUEBRA quando `AlavancasLiberadas::exigir` é removida (learnings §5).

### Assinatura da prévia
```php
final class AssinaturaDaPrevia
{
    public static function gerar(array $payloadCanonico, string $contaChave, int $userId, int $expiraEm): string
    {
        return hash_hmac('sha256', json_encode([$payloadCanonico, $contaChave, $userId, $expiraEm], JSON_UNESCAPED_UNICODE), (string) config('app.key')).'.'.$expiraEm;
    }
}
```

## Runtime State Inventory

Não aplicável: fase aditiva (greenfield dentro do Publicador), sem renomear nem migrar nada. Verificado: nenhuma tabela existente com dado em produção é alterada; a única migration cria `pub_alavanca_escritas`. **Nenhuma** das 5 categorias (dados armazenados, config de serviço vivo, estado do SO, segredos/env, artefatos de build) carrega string renomeada.

## Log de Suposições

| # | Afirmação | Seção | Risco se errada |
|---|-----------|-------|-----------------|
| A1 | `GET /items/{id}/prices?display_version=true` com `show-all-prices: true` devolve `price_per_quantity[]` das faixas já gravadas (a doc só mostra isso na resposta do POST) | §8 | Leitura das faixas atuais falha; cair para `GET /items/{id}` + tag e mostrar só "tem faixas" até haver fixture real |
| A2 | Parâmetro `q` (título) existe em `GET /users/{id}/items/search` | §5 | Busca por título não funciona; filtro local da página de 50 |
| A3 | Em cofinanciada, tarifa incide sobre `price` (o que o comprador paga) e o rebate do ML entra como receita; `discount_meli_boost_amount` não se soma ainda | §6 | Número de "quanto recebe" divergente do Seller Center; por isso mostrar linha separada e marcar "estimativa" até validar na #459 |
| A4 | `4_light_green` conta como "verde" para promoção; lista exata de `level_id` | §9 | Alerta falso/ausente; só alerta, não bloqueia; valor em config |
| A5 | O caminho com prefixo `/marketplace/advertising/MLB/...` do `MercadoLivreAdsService` ainda responde | §7 | Irrelevante: a fase usa o caminho documentado |
| A6 | Leitura de Product Ads funciona com o escopo atual do app (sem a permissão funcional "Advertising") | §7 | Painel de publicidade vazio com 403; mostrar "permissão do app" |
| A7 | Respostas de `seller-promotions` e `prices` não trazem dado pessoal | §4 | Histórico passa a guardar PII; sanitizar antes de gravar |
| A8 | O Ziggy exporta todas as rotas (sem filtro) para `route('mlb.anuncios.publicador.alavancas.*')` no JS | §1 | Rota não encontrada no front; incluir no filtro de `config/ziggy.php` |
| A9 | `GET /seller-promotions/users/{id}` aceita `limit`/`offset` e pagina como o resto | Matriz | Contas com > 50 convites ficam truncadas; ler `paging.total` e avisar |
| A10 | Convite/promoção criada pelo vendedor tem id `C-MLB…` e pelo ML `P-MLB…` (só nos exemplos da doc) | §Matriz | Separar "seus" de "do ML" por `type`, não por prefixo (usar `type` sempre) |

## Questões abertas (RESOLVED)

1. **Como obter o `offer_id` do candidato em SMART/PRICE_MATCHING?**
   - O que sabemos: o POST exige `offer_id` (`CANDIDATE-MLB…-NNN`), e a doc diz que o candidato nasce "sem offer_id"; o id do candidato chega pela notificação `public candidate` (`GET /seller-promotions/candidates/{id}`), que está fora da fase.
   - O que falta: se `GET …/promotions/{id}/items` (candidatos) ou `GET /seller-promotions/items/{item}` (`ref_id`) devolve esse `CANDIDATE-…`.
   - Recomendação: sondagem somente-leitura na #459 (Wave 0) antes de planejar a tarefa de SMART/PRICE_MATCHING; se não vier, entregar esses dois tipos como **somente leitura** nesta fase e dizer na tela ("aceite no Mercado Livre"), sem inventar o id.
   - **RESOLVIDA:** sem `offer_id` começando por `CANDIDATE-` na leitura do servidor, SMART e PRICE_MATCHING ficam SÓ LEITURA com o texto "Aceite este convite no Mercado Livre…"; o servidor nunca inventa o id nem aceita o do navegador. Cobertura: `TiposDePromocao::capacidades` (166-04), `InscreverNoConvite` com ALAV-CONV-03 (166-07) e a tela (166-13). A sondagem (166-05) mostra se a leitura traz o id.
2. **Quais tipos de convite existem de fato na #459?** A conta é loja real (MGSTOREL) com 65 vendas; talvez só `SMART`/`PRICE_DISCOUNT` candidatos. Escrita real de teste exige item com candidato — decidir com o usuário qual anúncio usar ("Item de teste - Não ofertar").
   - **RESOLVIDA:** o anúncio de teste da #459 é escolhido com o usuário no checkpoint da prova real (166-16, Task 4, pré-condição (d)); os tipos de convite que a conta tem aparecem no panorama e na sondagem (166-05). Nenhum código depende disso.
3. **Boost:** a doc não diz se o `discount_meli_boost_amount` aumenta o que a loja recebe. Validar com uma oferta real; até lá, linha separada.
   - **RESOLVIDA:** cofinanciada e boost aparecem em linha separada marcada "estimativa", fora da soma do "quanto recebe", até a comparação com o Seller Center na prova real (166-06; 166-16, Task 4, passo 4).
4. **Quantos itens por lote e qual teto de itens por confirmação?** Sugestão: 50 itens por lote, 1 job. Confirmar com o usuário se há necessidade de mais.
   - **RESOLVIDA:** teto de 50 produtos por confirmação em `publicador.alavancas.limites.itens_por_lote` (166-01), aplicado na prévia e no confirmar (166-11), com lote por job na fila `high`; a análise da prévia vai até os 20 primeiros (`itens_analise_previa`).
5. **Permissão "Promoções" no app ECF do DevCenter está ligada?** Sem ela, todo POST/PUT/DELETE de `seller-promotions` dá 403 mesmo com `write`. É pré-requisito de ambiente (ação do dono do app), não de código.
   - **RESOLVIDA:** pré-requisito de ambiente, não de código — conferido no checkpoint da prova real (166-16, Task 4, passo 1); o 403 vira a mensagem "O aplicativo ECF não tem a permissão de Promoções no DevCenter…" (`MapeadorErroAlavanca`, 166-02).
6. **`PUBLICADOR_ALAVANCAS_LIBERADAS_COMPANIES` em produção:** o default `459` está no código; o `.env` de produção precisa ser conferido antes do deploy para não liberar mais do que a #459 por engano (`ssh` só com autorização).
   - **RESOLVIDA:** conferir o `.env` de produção antes do deploy, fora da fase — pré-condição (b) do checkpoint do 166-16 e anotado no `166-16-SUMMARY.md`.
7. **A barra "Publicar \| Alavancas" também nas telas Meus Anúncios / Em massa / Histórico?** D-01 só pede na tela da empresa; quem estiver em "Meus Anúncios" precisa voltar à tela da empresa para ir às Alavancas. Recomendação: não mexer nelas nesta fase; reavaliar depois.
   - **RESOLVIDA:** a barra fica só na tela da empresa (`Produtos.jsx`) nesta fase (166-12); Meus Anúncios, Em massa e Histórico não mudam.
8. **Sondagem somente-leitura em produção** (`publicador:sondar-alavancas --empresa=459`, mesmo roteiro de `publicador:sondar` do learnings §3, rodado pelo USUÁRIO na VPS): capturaria `/users/me`, `/seller-promotions/users/{id}`, `/seller-promotions/items/{id}`, `/items/{id}/prices`, Ads — as fixtures reais que tirariam A1, A3, A9 e a Questão 1. Recomendado como primeiro plano da fase (leitura pura).
   - **RESOLVIDA:** o comando `publicador:sondar-alavancas` é construído e testado no 166-05 (só GET, autocontido, só contas da lista das Alavancas); a execução na VPS é do usuário, opcional, e não bloqueia nenhum plano (as fixtures da doc bastam para o código).

## Disponibilidade de Ambiente

| Dependência | Necessária para | Disponível | Versão | Alternativa |
|-------------|-----------------|-----------|--------|-------------|
| PHP | tudo | ✓ | 8.2.12 (`C:\xampp\php\php.exe`) | — |
| Composer deps (`vendor/`) | testes | ✓ (`vendor/bin/phpunit`) | PHPUnit 11 | — |
| Node | `npm run test:js` | ✓ | v26.9.0 (CLAUDE.md cita v24; o `node --test` roda) | — |
| `node_modules` no worktree | `npm run build` | ✗ (pasta vazia) | — | junção para outro worktree com `package-lock.json` idêntico, conferir com `diff` (learnings §7); lembrar: "worktree novo: `npm run build` sai 0 SEM buildar" |
| MariaDB local (compartilhado) | provar a migration | existe, mas NÃO usar nesta pesquisa | — | rodar `migrate --path` no plano de execução, por quem executa |
| Token ML de produção | leitura/escrita reais | ✗ local (cifrado com a `APP_KEY` de produção) | — | `Http::fake` + fixtures; sondagem lida pela VPS pelo usuário |
| Permissão "Promoções" do app no DevCenter | qualquer escrita real | desconhecido | — | só leitura até confirmar (Questão 5) |
| Conta de teste #459 (MGSTOREL, `business`, 5_green) | E2E de escrita | ✓ (loja real) | — | confirmação do usuário antes de CADA chamada de escrita |

**Sem alternativa e bloqueia execução:** nenhuma para o código (tudo é testável com `Http::fake`). **Bloqueia a prova com a conta real:** permissão do DevCenter e candidato real na #459.

## Arquitetura de Validação

### Framework de teste
| Propriedade | Valor |
|-------------|-------|
| Framework PHP | PHPUnit 11.5 (`phpunit.xml`: suítes `Unit` e `Feature`; SQLite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`) |
| Framework JS | `node --test` (`npm run test:js` → `tests/js/**/*.test.js`) |
| Arquivo de config | `phpunit.xml` / `package.json` |
| Comando rápido | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador/Alavancas` |
| Suíte completa | por pasta, redirecionando para arquivo (estoura 512 MB; `\| tail` engole o exit code): `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador > C:/tmp/p166-feature.txt` e depois ler o arquivo |
| JS | `npm run test:js` |
| Build | `npm run build` (com `node_modules` ligado; conferir o manifest) |

Baseline: rodar `tests/Feature/Publicador`, `tests/Unit/Publicador` e `npm run test:js` ANTES de começar e anotar as falhas pré-existentes (learnings §9: `Phase75/PublicarEmpresaNaoAtribuidaTest` é intermitente; 10 falhas antigas de Polos não são regressão — rodar isolado antes de chamar de regressão).

### Mapa Requisito → Teste
| Req | Comportamento | Tipo | Comando automatizado | Arquivo existe? |
|-----|---------------|------|----------------------|-----------------|
| AL166-01 | Produtos.jsx importa a barra; `AreaTabs` usa `route(...alavancas.index)` e `...publicador.produtos`; sem `bg-ecf-yellow` | unit JS (gate de fonte) | `node --test tests/js/publicador-alavancas.test.js` | ❌ Wave 0 |
| AL166-02 | 403 não admin; 404 chave inválida/arquivada; redirect `company-N`→`empresa-N`; sem token não chama ML (`Http::assertNothingSent`) | feature | `phpunit tests/Feature/Publicador/Alavancas/AcessoAlavancasTest.php` | ❌ Wave 0 |
| AL166-03 | panorama: 4 fontes com fixtures; uma fonte 500 → painel dela com erro e as outras ok | feature | `phpunit tests/Feature/Publicador/Alavancas/PanoramaTest.php` | ❌ Wave 0 |
| AL166-04 | liberar publicação ≠ liberar alavancas (os dois sentidos); Company 459 ≠ MlbEmpresa 459; lista vazia = ninguém; sem fallback `EMPRESAS_PILOTO` | unit | `phpunit tests/Unit/Publicador/Alavancas/AlavancasLiberadasTest.php` | ❌ Wave 0 |
| AL166-05 | conta fora da lista: `confirmar` 403/`RECUSADA`, zero chamadas não-GET; token de outro vendedor recusa; guarda de fonte: só `EscritorAlavancas` usa método de escrita; mutação (remover `exigir`) quebra o teste | feature + unit (grep) | `phpunit tests/Feature/Publicador/Alavancas/TravaEscritaTest.php` e `tests/Unit/Publicador/Alavancas/UnicoCaminhoDeEscritaTest.php` | ❌ Wave 0 |
| AL166-06 | linha `PENDENTE` antes do HTTP (verificar dentro da closure do fake); depois com `http_status`+resposta crua; erro 400, timeout (`Http::failedConnection`) → `INCERTO`; nenhum token/`Authorization` na linha | feature | `phpunit tests/Feature/Publicador/Alavancas/HistoricoEscritaTest.php` | ❌ Wave 0 |
| AL166-06 | migration: colunas, SET NULL só em anuláveis, índices ≤ 64, sem enum/change; apagar âncora/usuário mantém a linha | feature | `phpunit tests/Feature/Publicador/Alavancas/MigracaoHistoricoTest.php` (+ acrescentar o arquivo a `MigracoesDaFaseDetectamMariaDbTest::MIGRACOES`) | ❌ Wave 0 |
| AL166-07 | histórico só da empresa da tela, paginado, filtros, abre payload/resposta | feature | `phpunit tests/Feature/Publicador/Alavancas/HistoricoTelaTest.php` | ❌ Wave 0 |
| AL166-08 | `previa` sem HTTP de escrita e com `resumo`; `confirmar` sem/errada/expirada/outro-usuário → 422; lote cria N linhas e o resumo lista os N produtos | feature | `phpunit tests/Feature/Publicador/Alavancas/PreviaEConfirmarTest.php` | ❌ Wave 0 |
| AL166-08 | job de lote: fatias com `release`, trava revalidada por item, sem recursão no `sync` | feature | `phpunit tests/Feature/Publicador/Alavancas/LoteJobTest.php` | ❌ Wave 0 |
| AL166-09 | uma linha por tipo da matriz: corpo exato, método, query (`offer_id`, `stock`, sem preço onde "usuário aceita"); DOD/LIGHTNING ativos não removem; sem edição em PRICE_DISCOUNT/DOD/LIGHTNING | feature (data provider) | `phpunit tests/Feature/Publicador/Alavancas/ConvitesMatrizTest.php` | ❌ Wave 0 |
| AL166-10 | validação local (5/80, 14 dias, top_deal ≥ 5/10 p.p.); erros do ML traduzidos | unit + feature | `phpunit tests/Unit/Publicador/Alavancas/PriceDiscountRegrasTest.php` e `tests/Feature/.../PriceDiscountTest.php` | ❌ Wave 0 |
| AL166-11 | campanha do vendedor/VOLUME: corpo, start ≥ hoje (fuso SP), start não editável iniciado, preço só baixa | feature | `phpunit tests/Feature/Publicador/Alavancas/CampanhaVendedorTest.php` | ❌ Wave 0 |
| AL166-12 | exclusão conta/produto: corpo e histórico | feature | `phpunit tests/Feature/Publicador/Alavancas/ExclusaoTest.php` | ❌ Wave 0 |
| AL166-13 | cupom: criar com/sem código, FIXED_AMOUNT/PERCENTAGE, `max_purchase_amount`, `budget` só aumenta, `started` só 3 campos, pôr produto sem preço; lista com `remaining_budget` | feature | `phpunit tests/Feature/Publicador/Alavancas/CuponsTest.php` | ❌ Wave 0 |
| AL166-14 | só GET em `/advertising/`; caminhos corretos (`ad_groups/search`, `campaigns/search`, `bonifications`); cabeçalhos `api-version`; grep na fonte: nenhum legado | feature + unit (grep) | `phpunit tests/Feature/Publicador/Alavancas/PublicidadeLeituraTest.php` e `tests/Unit/Publicador/Alavancas/SemEndpointLegadoTest.php` | ❌ Wave 0 |
| AL166-15 | sem `business` não escreve; recomendação (200 e 204); POST com `X-Version` relido; corpo exato aninhado; 409 não repete; `remove-absolute-pxq` só confirmado; grep: sem `/prices/standard/quantity` | feature + unit (grep) | `phpunit tests/Feature/Publicador/Alavancas/AtacadoTest.php` | ❌ Wave 0 |
| AL166-16 | recebe_normal × recebe_promo com `SimuladorVoceRecebe`; `logistic_type`/`shipping_mode` do item na tarifa; frete desconhecido sinalizado; margem só com custo (MLB→`codigo_mlb`→oferta), `MlbEmpresa` sem Company sem margem; cache evita chamadas repetidas (contar por contador próprio) | feature | `phpunit tests/Feature/Publicador/Alavancas/AnaliseRecebeTest.php` | ❌ Wave 0 |
| AL166-17 | cada alerta liga/desliga por config; nenhum bloqueia; sem ordenação | unit | `phpunit tests/Unit/Publicador/Alavancas/AlertasTest.php` | ❌ Wave 0 |
| AL166-18 | gates de tipografia/peso/amarelo/Select/`uppercase`/contador nos arquivos novos; barra e modal usam `PRIMARIO` | unit JS | `npm run test:js` | ❌ Wave 0 |
| AL166-18 | build compila Alavancas.jsx e todos os subcomponentes; manifest contém a página | build | `npm run build` + conferir `public/build/manifest.json` | — |
| AL166-19 | `items/search` + multiget de 20 (3 chamadas por 50); item com `code != 200` pulado; `MlbEmpresa` sem Company funciona; offset > 1000 recusado com mensagem | feature | `phpunit tests/Feature/Publicador/Alavancas/ProdutosDaContaTest.php` | ❌ Wave 0 |
| AL166-20 | mapeador: `cause[].error_code`, `{error,code,cause_id}`, 409; 423 repete ≤ 3× (contador próprio, `dormir` injetado); escrita nunca repetida em 5xx/timeout | unit + feature | `phpunit tests/Unit/Publicador/Alavancas/MapeadorErroAlavancaTest.php` e `tests/Feature/.../RobustezTest.php` | ❌ Wave 0 |

**O que só dá para provar com a conta real #459** (nunca automatizado, sempre com confirmação do usuário antes de cada escrita): (a) permissão "Promoções" do app (um GET basta: `GET /seller-promotions/users/{id}` ≠ 403); (b) forma real de `price_per_quantity` no GET `prices` (A1); (c) `offer_id` do candidato SMART/PRICE_MATCHING (Questão 1); (d) conta de uma oferta cofinanciada vs. Seller Center (A3); (e) uma escrita pequena e reversível — um `PRICE_DISCOUNT` criado e removido num anúncio de teste da própria #459; (f) atacado: gravar 1 faixa e relê-la (a #459 tem `business`). Conta de cliente: **zero** escritas fora da lista liberada.

**Fixtures:** `tests/fixtures-ml/alavancas/` (pasta sem homônima; conferir `git ls-files --stage | grep -i alavancas` — learnings §2). Primeiro lote **derivado dos exemplos da doc oficial** (marcar `"origem": "doc-oficial"` no JSON; jamais passar por captura real), depois substituído pelas capturas sanitizadas da sondagem (Questão 8). Sanitizar: nenhum token, nenhum endereço/coordenada de loja (como o `sanitizar()` do `PublicadorSondar`), anonimizar `seller_id`/`nickname` de conta de cliente.

### Taxa de amostragem
- **Por commit de tarefa:** `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador/Alavancas` (+ `tests/Unit/Publicador/Alavancas`) e `npm run test:js`.
- **Por merge de onda:** `phpunit tests/Feature/Publicador` + `tests/Unit/Publicador` em arquivo, e `npm run test:js`.
- **Gate da fase:** suíte do Publicador verde (comparada ao baseline), `npm run build` com manifest conferido, capturas visuais conferidas (learnings §7), antes do `/gsd:verify-work`.

### Lacunas da Onda 0
- [ ] `tests/Feature/Publicador/Alavancas/Concerns/CenarioAlavancas.php` — trait com `Http::fake` de closure única (estado em propriedades), conta #459 (`Company` com `MlToken`) e variante `MlbEmpresa` sem `Company`, usuário admin, fixtures carregadas.
- [ ] `tests/fixtures-ml/alavancas/*.json` — derivadas da doc (usuário/me da #459 já existe em `sondagem/conta/usuario.json`).
- [ ] `tests/js/publicador-alavancas.test.js` — gates.
- [ ] Sem instalação de framework (PHPUnit/node já presentes).

## Domínio de Segurança

### Categorias ASVS aplicáveis
| Categoria ASVS | Aplica | Controle padrão |
|----------------|--------|-----------------|
| V2 Autenticação | sim (herdada) | sessão do admin; token ML cifrado, nunca em log nem em `payload` |
| V3 Gestão de sessão | herdada | `auth`+`verified` do grupo |
| V4 Controle de acesso | **sim, central** | `role:admin` no grupo; `{conta}` resolvido por `ProgramasPublicadorService` (404 fora do escopo); trava por conta; conferência token×vendedor; **IDOR:** `{lote}` e itens só da conta da rota (filtrar por `conta_chave`) |
| V5 Validação de entrada | sim | FormRequests por família + validação de regra no `AcaoAlavanca::validar()`; `{item}` por regex `MLB[0-9]+`, `{promocao}` por `[A-Za-z0-9-]+`; nunca interpolar entrada do usuário em caminho sem esse filtro (SSRF/path injection para a API do ML) |
| V6 Criptografia | sim (pontual) | `hash_hmac` + `hash_equals` na assinatura da prévia; `app.key`; nada artesanal |
| V7 Log e erros | sim | `[Alavancas]` sem token; resposta crua só no histórico (acesso admin); mensagem ao usuário em pt-BR sem stack |
| V13 API/serviços | sim | throttles por rota; backoff 429 do cliente; timeout 30 s |

### Ameaças conhecidas para este stack
| Padrão | STRIDE | Mitigação padrão |
|--------|--------|------------------|
| Escrita em conta de cliente não liberada | Elevação/Adulteração | `AlavancasLiberadas` no executor único + teste de guarda + teste de mutação |
| Replay/alteração do payload entre prévia e confirmação | Adulteração | assinatura HMAC com conta, usuário e expiração |
| Token de outro vendedor na âncora (token trocado) | Spoofing | `users/me.id == ml_user_id` antes de escrever |
| IDOR entre empresas (`lote`, histórico, item de outra loja) | Divulgação | filtrar sempre por `conta_chave` resolvida da rota; `item_id` que não é do vendedor volta 404 do ML — não confiar, e registrar |
| Vazamento de token no histórico/log | Divulgação | `payload` guarda só `{query, corpo}`; teste procura `APP_USR`/`Bearer` na linha e no log |
| Duplicação por repetição de escrita | Adulteração | `repetir=false`; `INCERTO` em 5xx/timeout; assinatura expira |
| Enumeração/abuso de leitura (esgotar rate limit do ML) | Negação de serviço | throttles + `RateLimiter` por conta na análise + cache |
| Injeção em caminho (`{promocao}`, `{item}`) | Adulteração | regex nas rotas + `rawurlencode` |

## Fontes

### Primárias (confiança ALTA)
- Documentação oficial do Mercado Livre (lida em 04/10/2026 por `curl` com User-Agent de navegador; páginas e datas de atualização): `gerenciar-ofertas` (09/06/2026), `campanhas-tradicionais` (09/06/2026), `campanha-com-co-participacao` (23/01/2025), `campanhas-de-desconto-por-quantidade` (23/01/2025), `ofertas-do-dia` (23/01/2025), `ofertas-relampago` (09/06/2026), `desconto-individua` (09/06/2026), `desconto-pre-acordado-por-item` (09/06/2026), `campanhas-smart-price-matching` (09/06/2026), `campanhas-do-vendedor` (28/08/2025), `cupons-do-vendedor` (28/08/2025), `pxq-porcentagem-b2b` (01/10/2026), `product-ads-para-catalogo-e-user-products-leitura` (06/07/2026), `bonificacoes-para-product-ads` (08/01/2026), `comissao-por-vender` (03/09/2026), `custos-de-envio` — todas em `https://developers.mercadolivre.com.br/pt_br/<página>`.
- Código do worktree: `ClienteMlPublicador.php`, `RespostaMl.php`, `ContasLiberadas.php`, `ProgramasPublicadorService.php`, `ContaMlService.php`, `EditorRascunhoService::simular`, `SimuladorVoceRecebe.php`, `DadosEfetivosService.php`, `EstruturaPrecificacaoService.php`, `PrecificacaoEstrutura.php`, `EstruturaAnuncio.php`, `PubProduto.php`, `MlAcervoService.php`, `MercadoLivreAdsService.php`, `routes/mlb_anuncios.php`, `Produtos.jsx`, `ModoAnuncioTabs.jsx`, migrations `2026_10_01_200000` e `2026_10_02_100000`, `config/publicador.php`, `config/mlb_acervo.php`, testes `tests/js/publicador-*.test.js`.
- Fixture real da #459: `tests/fixtures-ml/sondagem/conta/usuario.json` (tags `business`, `5_green`).

### Secundárias (MÉDIA)
- `166-PESQUISA-API.md` (pesquisa anterior, conferida contra a releitura desta; divergências apontadas: corpo PxQ, `ads/search`, cupom sem itens, DOD/LIGHTNING).

### Terciárias (BAIXA — marcadas `[ASSUMED]` no Log de Suposições)
- Nenhuma fonte de terceiros usada.

## Metadados

**Detalhamento de confiança:**
- Stack padrão: ALTA — tudo é código existente do projeto; nenhum pacote novo.
- Arquitetura: ALTA — espelha padrões já provados na 164 (trava no servidor, lote por job, histórico com resposta crua).
- API do ML (corpos, erros, regras por tipo): ALTA nas páginas lidas; MÉDIA onde a doc é ambígua (cofinanciada/boost, `offer_id` de candidato).
- Armadilhas: ALTA — vêm de regras escritas na doc e de learnings do projeto.

**Data da pesquisa:** 04/10/2026
**Válido até:** 7 dias para a API do ML (dois desligamentos em 2026 e o absoluto de PxQ em 27/10); 30 dias para o mapa de código.
