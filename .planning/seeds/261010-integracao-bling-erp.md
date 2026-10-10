# Integração com o Bling (ERP): produtos, estoque e kits

**Data:** 2026-10-10
**Origem:** pedido do usuário (dev.01), 10/10/2026: "um cliente nosso às vezes pode ter um ERP… vai saber que ele tem
vários produtos no ERP dele. Podemos puxar… mas não só isso, como atualizações de estoque… um produto que foi cadastrado
pelo nosso sistema e para o ERP dele… preciso que você, os agentes aí, façam pesquisa sobre o Bling e o que faz sentido
conectar". Ele tem uma conta Bling de teste.
**Status:** ⚠️ **PESQUISA — NÃO DESENVOLVER antes das decisões da §9 e da conta de teste.** Duas pesquisas em paralelo
(API v3 do Bling; encaixe no nosso código). Fontes no fim. O que não está confirmado vai marcado **(medir)**.
**Relação:** `261010-estoque-do-cadastro-como-fonte-unica.md`. Para quem usa Bling, o próprio Bling já faz o "estoque
único" daquele seed (§2.5).

## 1. Resumo para a decisão

O que faz sentido conectar, nesta ordem:

1. **Puxar os produtos do Bling** para Produtos do Portal (nome, código, variações, medidas, peso, custo, descrição),
   com prévia antes de gravar. A gravação usa o mesmo caminho de escrita da planilha
   (`ProdutoCadastroService::gravarLinhas`, MODO_IMPORTACAO).
2. **Trazer o estoque do Bling para o Portal**, por varredura a cada 30 min e pelo botão "Atualizar agora". Na variação
   vinculada, o estoque fica só-leitura ("vem do Bling") e o SKU fica travado.
3. **Mandar para o Bling os kits e combos que o Planejamento cria**, como produto com composição. Se o usuário quiser,
   mandar também os produtos que só existem no Portal.

Três fatos do Bling decidem o desenho:

- **O Bling já sincroniza estoque E preço com o ML** nos anúncios vinculados a ele. Se dois sistemas escrevem no mesmo
  anúncio, vence o último. A regra é **um dono por dado** (§2).
- **App público sem homologação aceita no máximo 10 contas.** A carteira passa disso, então é preciso homologar:
  teste automático mais revisão humana, sem prazo publicado.
- **Mudar os escopos do app revoga TODOS os clientes.** A lista fecha antes do primeiro cliente e aparece na tela de
  consentimento que o cliente vê. Isso toca o sigilo (§9, pergunta 5).

## 2. O Bling ↔ ML nativo, e por que ele decide o desenho

O que a integração nativa já faz (ajuda do Bling: H10–H24):

- **Estoque, do Bling para o ML:** automático a cada movimentação, nos anúncios vinculados, a partir do depósito padrão
  ou do escolhido. Há multi-origem por filial.
- **Preço, do Bling para o ML:** o preço do anúncio no Bling é o oficial. Existem regras automáticas de preço, e mexer
  no preço direto no ML desliga a regra.
- **"Gestão de Anúncios"** (substituiu o multiloja):
  - importa anúncios por SKU, MLB, link ou período;
  - vincula por SKU, GTIN ou título;
  - pode "trazer e vincular novos anúncios automaticamente", criando produto no Bling quando não acha vínculo, o que
    gera risco de duplicata.
- **Conteúdo, do ML para o Bling:** com "Trazer automaticamente", título, descrição, imagens, preço, atributos, envio
  e dimensões vêm do ML e sobrescrevem o Bling.
- **User Products:** o Bling controla o estoque no nível MLBU.
- **Full:** o Bling não gerencia esse estoque.
- **Formato:** simples ou com variação tem de ser igual nos dois lados. Se divergir, dá
  `available_quantity.not_modifiable`.

Consequências para nós:

1. **Estoque.** Hoje o sistema só manda estoque na **criação** do anúncio. Conferido em 10/10: nenhum PUT de estoque em
   `app/`; `available_quantity` e `stock_locations` só existem nos payloads de criação. Para cliente com Bling↔ML,
   continua assim: o Bling é o dono do estoque dos anúncios.
2. **Preço, um conflito real.** O anúncio que publicarmos pode ser vinculado no Bling, inclusive automaticamente pelo
   SKU, se o cliente ligou "trazer e vincular". Nesse caso, o Bling pode mandar o preço **dele**, apagar o da
   Precificação e mexer no preço por baixo da promoção automática. É preciso combinar com cada cliente: ou o Bling tem
   o mesmo preço da Precificação, ou a sincronização de preço fica desligada nesses anúncios. Uma opção mais pesada é
   publicar pela API `/anuncios` do Bling, para o vínculo já nascer lá.
3. **Anúncio novo sem vínculo no Bling fica com o estoque congelado** no número da criação. Na Fase 2, conferir o
   vínculo e abrir a tarefa "vincular no Bling" em `pub_tarefas` (novo `tipo`, learnings publicador-ml §17). A tarefa
   fecha sozinha quando o vínculo aparecer.
4. **Kit ou combo da ECF tem SKU que o Bling não conhece.** A venda chega ao Bling sem produto e não baixa estoque. Na
   Fase 3, criar esses conjuntos no Bling como composição.
5. **O "estoque único" do seed já existe no Bling.**
   - Em estoque virtual, o saldo do kit com composição é o menor número de kits montáveis. A venda baixa dos
     componentes, e o Bling atualiza todos os anúncios vinculados.
   - Com os kits e combos do Planejamento no Bling (item 4) e os anúncios vinculados, o exemplo do usuário funciona
     para quem usa Bling: "vendeu 1 kit de 2 balas, sai 2 do estoque de balas". Isso dispensa construir o consumo de
     vendas e o número de fachada.
   - Para quem não usa Bling, o seed continua valendo como está.

## 3. Quem manda em cada dado

**Regra geral.** É a do Sincronizar (`PortalParaRascunhoService`), estendida ao Bling:
- o Bling preenche o que está vazio e acompanha o que ele mesmo escreveu;
- o que o cliente ou a equipe editou no Portal nunca é sobrescrito.

**Como saber quem escreveu.** As colunas do Portal não têm `origem`. A memória fica em `bling_vinculos.escrito`, com o
último valor que o Bling gravou em cada campo. É a mesma técnica de `step_state.portal_escrito`.

**Variação que já existia antes da conexão:** valor igual ao do Bling passa a seguir o Bling; valor diferente fica.

| Dado | Dono | Regra |
|---|---|---|
| Código (SKU) | Bling | Segue sempre. Fica travado no Portal na variação vinculada, porque é por ele que o Bling casa com o anúncio (a mesma proteção da oferta ligada em `EstruturaOferta`) |
| Nome, descrição, medidas | Bling no nascimento; depois, quem editou por último | Memória |
| Custo | Bling, pela memória (ou sempre: §9, pergunta 4b) | Memória |
| Estoque | Bling, sempre, com a conexão ligada | Só-leitura na ficha. A regra fica **no serviço**, então a coluna Estoque da planilha também é ignorada na variação vinculada |
| Preço | ECF (Precificação) | O preço do Bling não entra |
| Imagens | Portal/equipe | O Bling só preenche variação sem nenhuma foto e nunca troca nem apaga |
| Categoria do ML, ficha, família, ambientes, kits | Portal/ECF | — |
| Produto apagado ou inativado no Bling | — | Nada se apaga no Portal. O vínculo vira `removido_no_bling` e sai um aviso |

O rascunho do Publicador continua com a regra dele: estoque ou SKU digitado ali pela equipe deixa de seguir o Portal.

## 4. Mapa de campos Bling → Portal

| Bling (v3) | Portal | Regra |
|---|---|---|
| `id` do produto ou da variação | `bling_vinculos.bling_produto_id` (tabela nova) | O vínculo é pelo id, não pelo SKU. O SKU não identifica (lição de `EstruturaOferta`) |
| `codigo` | `estrutura_produto_variacoes.codigo` (Ref, única por empresa) | 1º casamento por `chaveCodigo`. Código vazio ou repetido no Bling = linha recusada na prévia |
| `nome` | `estrutura_produtos.nome` | Só em produto novo. A 1ª linha redefine o produto, então o produto existente recebe o nome atual do Portal |
| `formato` S | 1 produto + 1 variação | `grupo` = `codigo` |
| `formato` V + `variacoes[]` | 1 produto + N variações | `grupo` = código do pai. Pai sem código usa a Ref da 1ª variação (learnings portal §39). O pai não tem estoque |
| `variacao.nome` (`"Cor:Azul;Tamanho:M"`) | `eixo` + `valor` | **Um atributo:** `NormalizadorDeLinha::eixo()`; fora da lista vira "outro" + aviso. **Dois ou mais:** não cabem (o Portal tem 1 eixo por variação), entram como "outro" com valor "Azul · M" + aviso |
| `formato` E + `estrutura.componentes[{produto.id, quantidade}]` | oferta composta: `estrutura_ofertas.fase` combo/kit/combit + `estrutura_oferta_componentes` | Fase 2. Só com todos os componentes vinculados. Mesmo item ×N = combo; itens diferentes = kit ou combit. O estoque do conjunto o Portal já calcula (`ComposicaoDoPortal`) |
| `dimensoes` (largura, altura, profundidade; `unidadeMedida` 0 = m, 1 = cm, 2 = mm), `pesoBruto` (reserva: `pesoLiquido`), `volumes` | `estrutura_produto_volumes` (cm, kg) | Profundidade = comprimento. `volumes` ≤ 1 → 1 volume. `volumes` > 1 → a medida **não entra** + aviso (o Bling tem 1 medida por produto). Limites do normalizador. Tratar como embalado **(medir em 2–3 produtos reais)** |
| `fornecedor.precoCusto` (leitura: `precoCusto`) | `variacao.custo` | A Precificação lê daí |
| `/estoques/saldos` | `variacao.estoque` (inteiro ≥ 0; NULL ≠ 0) | Soma dos depósitos escolhidos, piso; negativo → 0 |
| `descricaoCurta` (HTML) | `estrutura_produtos.descricao` (≤ 5.000) | Tira o HTML e corta com aviso. `descricaoComplementar` é ignorada |
| `midia.imagens.internas[]` (o link expira, `validade`) / `externas[]` | `estrutura_produto_variacao_imagens` (12 por variação) | Fase 2. Baixa na importação, só em variação sem foto |
| `gtin` | — (no Publicador é atributo de variante) | Fica em `bling_vinculos.gtin`. Na Fase 2 vai ao rascunho quando o campo estiver vazio |
| `marca` | atributo `BRAND` da ficha (texto livre desde 10/10) | Fase 2. Só com categoria confirmada, por `FichaTecnicaDoProduto::gravarParcial` (nunca apaga) |
| `categoria.id` (árvore do cliente) | — | Não casa com categoria do ML. O "Sugerir categorias" resolve, e texto nunca vira id |
| `linhaProduto` | Família? | §9 |
| `preco` | — | Não entra: o preço é calculado pela Precificação |
| `situacao`, `tipo` | filtro | Ativos (`criterio=2`), tipos PS/C/E. Serviços e inativos ficam fora |
| tributação/NCM, unidade, crossdocking, mín./máx., lotes | — | Fora. O crossdocking pode virar prazo de fabricação depois |

## 5. Estoque

**De onde vem.**
- `GET /estoques/saldos?idsProdutos[]=…` (ou `codigos[]`) devolve `saldoFisicoTotal`, `saldoVirtualTotal` e
  `depositos[{id, saldoFisico, saldoVirtual}]`.
- `GET /depositos` traz os flags `padrao` e `desconsiderarSaldo`.
- Por conexão escolhemos:
  - **depósitos que contam**, por padrão todos sem `desconsiderarSaldo`;
  - **físico × virtual**, por padrão o virtual (físico menos reservado; a reserva é configurável por situação do
    pedido).

**Sem "alterado desde".** Não há histórico de movimentação nem filtro de data nos saldos. Mudança de estoque só chega:
- pelo webhook `stock` (lançamento físico);
- pelo webhook `virtual_stock` (reservas e kits virtuais);
- ou por varredura.

**Escrita, se um dia houver.** `POST /estoques` (E entrada, S saída, B balanço) **não é idempotente**: repetir depois
de um 5xx lança em dobro. Usar balanço, ou reler o saldo antes.

**Caminho até o ML, hoje.**
1. O estoque está no Portal.
2. O Sincronizar preenche ou acompanha o rascunho. Combo e kit usam o menor piso; o kit da Fase N divide por N.
3. `available_quantity` vai na criação do anúncio, ou `stock_locations` em conta multidepósito.
4. Depois de publicado, nada atualiza, de propósito. A tela avisa "Estoque no ML difere do calculado".

**Gravar o estoque vindo do Bling.**
- Usa um método novo no `ProdutoCadastroService`, para manter o caminho único de escrita.
- Esse método **não** chama `PreparoIaAgenda::aoSalvar`. Se chamasse, cada varredura agendaria, por produto, um
  Sincronizar e a IA na fila `high` (learnings publicador-ml §22).
- Ele só agenda `SincronizarProdutoDoPortalJob`, e só para produto com rascunho ainda não publicado.
- **Fase 2:** reler o saldo no clique de publicar, para fechar a janela entre a última leitura e a publicação.

## 6. API v3: o essencial

### Autenticação
- **Fluxo.** Só existe o Authorization Code.
  - URL: `GET https://www.bling.com.br/Api/v3/oauth/authorize?response_type=code&client_id=…&state=…`.
  - `redirect_uri` e `scope` na URL são **ignorados**: valem os do cadastro.
  - Há um callback por app. Use um app de dev separado, ou leve o ambiente no `state`.
  - O `state` não pode ir vazio.
- **`code`.** Vale **1 minuto** e é de uso único. **Reusar um code ainda válido revoga o usuário.**
- **Token.** `POST https://api.bling.com.br/Api/v3/oauth/token`.
  - Header `Authorization: Basic base64(client_id:client_secret)`; as credenciais só podem ir no header.
  - Corpo form-urlencoded `grant_type=authorization_code&code=…`.
  - Header `enable-jwt: 1`, na troca do code e em toda renovação.
  - A doc escreve `/oauth/token` e `/oauth/revoke` **sem** `/Api/v3`. Essas formas dão 403 (testado em 10/10).
- **Resposta.** `access_token` (o exemplo traz 21600 s, 6 h), `refresh_token` (30 dias) e `scope` (ids numéricos).
- **Renovação.**
  - O refresh **gira**, segundo a comunidade. Grave sempre o novo e trave a renovação por conta: duas renovações
    simultâneas derrubam a autorização.
  - Conta 30 dias sem renovar = o cliente reautoriza.
  - Faça keep-alive espalhado. **Nunca** renove em rajada (ver limites).
- **JWT.**
  - Tem de 1.500 a 3.000 caracteres (coluna TEXT).
  - O token opaco está "descontinuado"; a data de corte está "em definição".
  - A doc manda o header `enable-jwt` em toda chamada. Um relato de out/2026 diz que o JWT funciona sem ele e que
    token opaco com o header dá 401. Nascer só com JWT **(medir)**.
- **Revogar.** `POST /Api/v3/oauth/revoke` com `token` e `token_type_hint`; opcionais
  `revoke_action=logout|uninstall` e `revoke_target=user|company`.
- **Empresa.** `GET /empresas/me/dados-basicos` devolve o id da empresa (= o `companyId` dos webhooks) e o CNPJ.
- **Falhas de autorização.** Inadimplência ou permissão perdida dão `UNAUTHORIZED_ERROR`. Empresa inativa não renova.
- **Cadastro do app.**
  - Caminho: Central de Extensões > Área do Integrador > Criar aplicativo, com um usuário que tenha a permissão
    "Cadastro de aplicativos" (Preferências > Sistema > Usuários).
  - Campos: logo, nome, categoria, descrições, link de redirecionamento, homepage, manual, vídeo, dados do
    desenvolvedor e escopos.
  - Aba "Informações do app": Client ID, Client Secret, "Redefinir client secret" e "Revogar usuários".
  - Excluir o app revoga todos os tokens.
- **Tipos de app.**

  | Tipo | Alcance |
  |---|---|
  | Privado | Só a própria conta |
  | Público | Outras contas, **até 10 sem homologação** |
  | Homologado | Sem limite de contas, e aparece na vitrine |

- **Homologação.**
  - **Parte automática:** 5 chamadas encadeadas em `/Api/v3/homologacao/produtos` (GET, POST, PUT, PATCH de situação,
    DELETE).
    - Cada resposta traz o header `x-bling-homologacao`, que vai na chamada seguinte.
    - O token é invalidado no meio; use o refresh.
    - Limite de 10 s no total e 2 s entre chamadas.
  - **Revisão humana:** logo, nome, descrições, categoria, tela amigável no retorno do OAuth (sucesso e erro),
    homepage pública, manual, vídeo e escopos coerentes.
  - **Sem prazo publicado.**
- **Escopos.** Os nomes exatos só aparecem na tela.
  - Para nós: Produtos, Estoques, Depósitos, Categorias de produtos, Campos customizados e Dados básicos da empresa.
  - Para o aviso "vincular no Bling" e a Fase 3: Produtos-Lojas, Canais de venda e Anúncios.
  - Sem o escopo de um recurso, o webhook dele nem aparece para configurar.
  - Escopo insuficiente dá 403 `insufficient_scope`.

### Limites
- **Cota por conta.** 3 req/s e 120.000 req/dia **por conta Bling**, somando tudo. A cota é dividida com as outras
  integrações do cliente; só o plano Elite vende segregação.
  - O 429 traz `TOO_MANY_REQUESTS` com `limit` e `period`.
  - Não há headers de limite: o ritmo é controle nosso.
- **Bloqueio do IP de origem.** Todos os clientes saem do IP da VPS, então um bloqueio derruba todos.

  | Gatilho | Duração |
  |---|---|
  | 300 erros em 10 s | 10 min |
  | 600 req em 10 s | 10 min |
  | **20 chamadas a `/oauth/token` em 60 s** | **60 min** |
  | Reincidência | Indeterminada |

  - Copiar o padrão do `ml:refresh-tokens` (renovar todas as contas de uma vez) estoura esse teto assim que houver
    mais de 20 clientes.
- **Paginação.** `pagina` e `limite` (padrão 100; máximo não documentado). Não vem total: itere até a página vazia ou
  menor. Filtro de período maior que 1 ano dá 400.
- **Sem sandbox.** Tudo roda em conta real. `/homologacao/produtos` é fictício e só serve para homologar. Não está
  confirmado se a API funciona na conta trial **(medir)**.
- **Custo, para 500 produtos / 800 SKUs.**

  | Uso | Custo |
  |---|---|
  | Importação inicial (lista de 100 em 100 + ficha de cada simples ou pai) | ~510 req, ~8,5 min a 1 req/s |
  | Estoque a cada 30 min | 8–16 chamadas por varredura (~50–100 ids por chamada **(medir)**) |
  | Cadastro alterado, 1× por dia por `dataAlteracaoInicial` | ~5 + a ficha dos alterados |

### Produtos, variações e kits
- **Busca: `GET /produtos`.**
  - Filtros: `codigos[]`, `gtins[]`, ids, `nome`, `idCategoria`, `idLoja`, datas de inclusão e de alteração.
  - `tipo`: T, P, S, E, PS, C ou V.
  - `criterio`: 1 = últimos, 2 = ativos, 3 = inativos, 4 = excluídos, 5 = todos.
  - `filtroSaldoEstoque`: o schema diz padrão 1 (positivo). **Medir** se, omitido, só traz os positivos.
  - A listagem vem resumida. A ficha completa só em `GET /produtos/{id}`.
- **Escrita.**
  - Obrigatórios: `nome` (≤ 120), `tipo`, `situacao` e `formato` (S, V ou E).
  - SKU = `codigo` (≤ 60 na interface).
  - Custo pelo objeto `fornecedor` **(medir)**.
  - Imagens **só por URL** (`imagensURL[{link}]`), sem upload, seguindo o parâmetro de armazenamento da conta.
  - O saldo **não** se grava no produto, só por `/estoques`.
- **PUT substitui o produto inteiro**: campo omitido some. Use PATCH (desde a v312).
  - PUT de produto V sem `variacoes` dá o erro 93.
  - `variacao: null` desvincula a variação do pai.
  - Mande sempre a lista completa, tirada de um GET.
- **Produto com variação.**
  - O pai é só agrupador: não tem estoque e "não existe fisicamente".
  - Até 500 variações por pai.
  - Formato: `variacao{nome: "Tamanho:G;Cor:Verde", ordem, produtoPai{cloneInfo}}`.
  - Ler por `GET /produtos/{idPai}` ou `/produtos/variacoes/{idPai}`.
  - O vínculo com o pai pode levar mais de 10 s para aparecer.
- **Kit.** `formato E` com `estrutura{tipoEstoque F|V, lancamentoEstoque A|M|P, componentes[{produto{id}, quantidade}]}`.
  - Uma variação também pode ser kit.
  - `GET /produtos?tipo=E&idComponente=X` lista os kits que usam um componente.
- **Loja.**
  - `/produtos/lojas` guarda o código na loja (= MLB), o preço e o preço promocional.
  - Contas na "Gestão de Anúncios" usam `/anuncios` (v326+, `tipoIntegracao=MercadoLivre`, `idLoja`).
  - **Medir** se `/produtos/lojas` ainda reflete o vínculo com o ML nessas contas.

### Webhooks
- **Recursos.** `product`, `stock`, `virtual_stock` (liga junto com o `stock`), `product_supplier`, `order`, `invoice`
  e `consumer_invoice`.
- **Ações.** created, updated e deleted. Excluir pela situação gera `updated`.
- **Configuração.** Na aba do app; **não há API para isso**. Vale para todas as contas autorizadas (inferência), e os
  eventos chegam depois que o cliente autoriza.
- **Envelope.** `{eventId, date, version, event, companyId, data}`, com payload enxuto:
  - produto vem sem mídia;
  - com mais de 200 vínculos, vem `vinculoComplexo: true` e é preciso reconsultar a API.
- **Assinatura.** `X-Bling-Signature-256: sha256=<hex>` = HMAC-SHA256 do **corpo bruto** com o client secret. Comparar
  com `hash_equals`.
- **Entrega.**
  - Sucesso = 2xx em até 5 s. Senão, reenvio por até 3 dias.
  - **Esgotadas as tentativas, o webhook daquele recurso é desligado até alguém religar.** Monitorar "sem eventos".
  - Sem garantia de ordem, com duplicatas: dedup por `eventId` e fila.
- **O que não tem webhook:** vínculo produto-loja, anúncios, categorias e depósitos.

## 7. Desenho técnico mínimo

### Tabelas (só criação)
Esta é a decisão de schema por escrito. Valem as regras do learnings desempenho §6: sem `enum`, sem `json`, sem
`timestamp()` solto, nomes de índice e de FK com menos de 64 caracteres, migration idempotente com `hasTable`.

**`bling_conexoes`** (uma por empresa)
- `company_id`: FK cascade, unique `blcon_company_uq`.
- `bling_empresa_id` (varchar 64, indexado; é o `companyId` do webhook), `bling_cnpj`, `bling_nome`.
- `access_token` e `refresh_token`: TEXT, com cast `encrypted` e `$hidden`.
- `expires_at`, `last_refreshed_at`, `connected_at`: `datetime` anulável.
- `status` (varchar 20): `ativa`, `revogada`, `erro` ou `divergente`.
- `last_error` (text), `last_error_at`.
- `depositos` (lista em texto; null = padrão), `saldo` (`virtual` ou `fisico`), `estoque_ativo` (bool).
- `dono_estoque_ml` (varchar 10): `bling`, `ecf` ou `nenhum`. Só a Fase 3 lê.
- `conectado_por`, `ultima_importacao_em`, `ultimo_estoque_em`, timestamps.

**`bling_vinculos`**
- `company_id`: FK cascade.
- `bling_produto_id`, `bling_pai_id` (anulável).
- `variacao_id`: FK para `estrutura_produto_variacoes` com `nullOnDelete`, unique `blvin_variacao_uq`.
- `codigo_bling`, `formato` (S/V/E), `gtin`.
- `escrito` (texto: a memória `{nome, descricao, custo, volumes, codigo}`).
- `saldo`, `saldo_lido_em`.
- `situacao` (`vinculado`, `ignorado`, `erro` ou `removido_no_bling`) e `motivo`.
- Unique `(company_id, bling_produto_id)` com o nome `blvin_company_prod_uq`; índice em `bling_pai_id`.

Por que uma tabela de vínculo, e não colunas em `estrutura_produto_variacoes`: alterar tabela com dado em produção
obriga fase GSD (CLAUDE.md), e assim o Portal continua sem saber de ERP.

**Fase 2: `bling_eventos`**
- `event_id` (unique), `bling_empresa_id`, `evento`, `recurso_id`.
- `payload` (texto até 64 KB), `assinatura_valida`.
- `status`, `erro`, `recebido_em`, `processado_em`.
- Limpeza depois de 30 dias.

### Serviços, Jobs e comandos (`app/Services/Bling/`)
- **`BlingOAuthService`.** Copia o `MercadoLivreService`: `state` em cache de uso único, troca, renovação sob
  `Cache::lock` e revogação só quando o token é recusado de verdade.
- **`BlingClient`.**
  - Base `https://api.bling.com.br/Api/v3`, `Bearer` mais o header `enable-jwt: 1`.
  - Limitador por conta (≤ 1 req/s em carga de massa) e global por IP, no Redis.
  - 429 → espera; 401 → renova uma vez.
  - Log com prefixo `[Bling]`, sem token.
- **`TradutorBlingParaPortal`.** Função pura: transforma o produto do Bling em linhas no contrato do normalizador e só
  leva as chaves que pode mexer (chave ausente = não mexer).
- **`ImportadorDoBling`.**
  - `previa()` e `aplicar()` sem estado, como o `ImportadorProdutos`.
  - Grava em lotes de até 200 linhas.
  - **Pede para não agendar o preparo por produto** e dispara um Sincronizar só no fim (§8, risco 5).
  - Textos sem citar a plataforma de venda (sigilo).
- **`EstoqueDoBlingService`.** Grava só o estoque (§5).
- **`LerProdutosDoBlingJob`.** Roda em fatias de ~45 s com `rodada`, como o `ImportarAnunciosMlEstruturaJob`. A
  próxima fatia vai por `release()` (learnings publicador-ml §6). Fila: ver §22 do learnings publicador-ml.
- **Comando `bling:estoque`.**
  - Roda a cada 30 min, em processo próprio, com `withoutOverlapping`, `onOneServer` e `runInBackground`.
  - O precedente é o `polos:warm`: a `default` vive congestionada.
- **Comando `bling:renovar-tokens`.** Keep-alive espalhado, no máximo ~10 renovações por minuto.
- **Ator da varredura.** `AtorDoPortal` só nasce de cliente ou de equipe, e o `RegistroEstrutura` exige causer. A
  varredura precisa de registro com `origem = 'bling'`, sem causer.

### Rotas
- **Portal** (grupo `portal.auth`). Cada rota precisa de throttle com prefixo próprio e de uma linha na allowlist de
  `RestringeDominioDoPortal`.
  - `GET /portal/estrutura/produtos/bling`: estado da conexão.
  - `GET …/bling/conectar`: manda ao Bling.
  - `POST …/bling/leitura` e `GET …/bling/leitura/{rodada}`: progresso e prévia.
  - `POST …/bling/importar`.
  - `POST …/bling/estoque`: "Atualizar agora".
  - `DELETE …/bling`: desconectar e revogar.
- **Callback público.** `GET /oauth/bling/callback`, amarrado ao `state` e na allowlist do domínio do cliente. Devolve
  ao Portal pela `UrlDoPortal::para()`, montada no início, nunca pelo request.
- **Webhook (Fase 2).** `POST /api/webhooks/bling`, com o CSRF já isento. Leva throttle e HMAC sobre o corpo cru, grava
  o evento, enfileira e responde em < 5 s. É o desenho do `ClicksignWebhookController`.
- **Admin** (`role:admin`). `GET /bling-oauth` (status e último erro por empresa) e
  `DELETE /companies/{company}/bling/disconnect`.
- **Onde fica o botão.** A conexão nasce no Portal (o consentimento é do cliente, logado no Bling dele); o Admin só
  acompanha e socorre.
  - A janela "Importar" ganha a aba "Do Bling".
  - A barra ganha um atalho ao lado de "Importar planilha".
  - Isso acaba com o pedido da senha do ERP no checklist do onboarding (`MlbImplementacao`, item `erp`).

### Segurança
- **Tokens.** Cast `encrypted` e `$hidden`, como `MlToken`. `BLING_CLIENT_ID`, `BLING_CLIENT_SECRET` e
  `BLING_REDIRECT_URI` ficam só no `.env`.
- **`state`.** Aleatório, de uso único, preso a empresa, ator e destino.
- **Conta divergente não grava.** No callback, comparar o CNPJ de `dados-basicos` com `companies.cnpj`. Se divergir, a
  conexão fica `divergente`, sem token ativo. É a lição do Polos no OAuth do ML.
- **Webhook.** `hash_equals` sobre o corpo cru.
- **Desconectar.** Revoga no Bling e apaga o token.
- **App de desenvolvimento separado**, com o redirect local.

## 8. Fases, esforço e riscos

**Fase 0 — pré-requisitos** (0,5–1 dia, mais a espera do Bling)
- Tomar as decisões da §9.
- Criar o app **público, já com todos os escopos decididos**.
- Montar a massa de teste e medir a §10.

**Fase 1 — MVP** (8–10 dias, 1 dev): conectar, importar simples e variações, estoque por varredura.

| Item | Dias |
|---|---|
| Migrations e models | 0,5 |
| OAuth, callback, desconectar e conferência de CNPJ | 1,5 |
| Cliente HTTP com limitador | 1 |
| Tradutor | 1,5 |
| Importador com prévia, fatias e vínculos | 1,5 |
| Estoque: comando, "Atualizar agora", gravação só do estoque, campo só-leitura, SKU travado | 1,5 |
| Aba da tela | 1 |

Os testes com `Http::fake` vão no mesmo commit. A migration é provada no MariaDB local.

**Fase 2** (6–8 dias)
- Webhooks, caixa de entrada, reconciliação diária e alerta de "sem eventos".
- Fotos, só onde a variação não tem nenhuma.
- Kits do Bling como Combo/Kit/Combit.
- GTIN e marca, só no vazio.
- Releitura do saldo ao publicar.
- Tarefa "vincular no Bling".
- Painel `/bling-oauth` no Admin.

**Fase 3** (8–12 dias, sob decisão)
- Mandar ao Bling: os kits e combos da ECF, como composição, e os produtos só do Portal (`POST /produtos`).
  - As imagens do Portal ficam em disco privado, e o Bling só aceita URL. Mandá-las exige link público temporário.
- E/ou: a ECF passa a escrever estoque no ML para quem não tem Bling↔ML. Aí entra o seed do estoque único.

**Riscos**
1. A homologação do Bling pode travar a entrada do 11º cliente, num prazo que não depende de nós.
2. Mudar escopo revoga todos os clientes.
3. Bloqueio do IP da VPS derruba todos os clientes de uma vez.
4. A cota é da conta do cliente: nossa carga em massa pode derrubar o hub ou o ERP dele.
5. **Disparo em massa na fila `high`.** Cada save agenda Sincronizar e IA por produto. Importar 500 produtos geraria
   centenas de cadeias de IA na mesma fila que publica e que manda o código de acesso do Portal. A importação por
   planilha **já tem esse problema hoje** (learnings publicador-ml §22).
6. Anúncio novo sem vínculo no Bling fica com o estoque congelado, e o kit da ECF tem SKU que o Bling não conhece.
7. Webhook desligado pelo Bling depois de 3 dias de falha deixa o estoque parado em silêncio.
8. Variação de dois eixos e produto em vários volumes entram incompletos.
9. Sem sandbox: todo teste é em conta real.
10. **Sigilo.** Nenhum texto novo do Portal cita a plataforma de venda (`assertSemOrigem` nos testes). A tela de
    consentimento do Bling lista os escopos do app: "Anúncios" e "Lojas" apareceriam ao cliente.

## 9. Decisões pendentes (perguntar ao usuário)

**Antes da Fase 0:**
1. **Quem conecta?** O cliente no Portal (recomendado), a equipe pelo Admin, ou os dois?
2. **Estoque da variação vinculada:** sempre o do Bling, travado (recomendado), ou o cliente pode digitar por cima?
3. **Os clientes com Bling já ligam o Bling ao ML?** Com estoque? Com **preço**? Dá para contar pelo item `erp` do
   checklist do onboarding. Isso define o dono de cada dado nos anúncios e o tratamento do preço (§2.2).
4. **"Mandar para o Bling" quer dizer o quê?** Os kits e combos do Planejamento (recomendado: é o que faz o estoque
   único funcionar), os produtos só do Portal, ou os dois? Quem autoriza escrever no ERP fiscal do cliente?
   - **4b.** O custo do Bling vale sempre, ou o Portal manda depois que o cliente editou?
5. **Escopos e sigilo:**
   - **opção a:** pedir só produtos, estoque, depósitos, categorias e dados da empresa. Fica sem a checagem "vincular
     no Bling" e sem `/anuncios`, e mudar depois desconecta todos;
   - **opção b:** pedir tudo já, e o cliente vê "Anúncios" e "Lojas" na tela de autorização.
6. **Homologação:** o app fica em nome de quem? Quem cuida de logo, homepage, manual e vídeo?

**Depois (não travam a Fase 0):**
- Quais depósitos contam? Físico ou virtual?
- Importar tudo, ou o cliente escolhe o que entra (só com estoque, por categoria do Bling)? E a IA prepara todos os
  importados (ver o limite diário de 60 por empresa)?
- Os kits do Bling viram ofertas do Planejamento? A "linha de produto" vira Família?
- Trazer as fotos? Ocupa disco na VPS.
- Para quem não tem Bling↔ML, a ECF passa a atualizar o estoque no ML?
- Uma conta Bling pode servir a duas empresas da ECF (matriz e filial)?

## 10. Medir na conta de teste (Fase 0)
1. Se a API funciona na conta trial, e se um app em desenvolvimento autoriza outra conta.
2. O formato real de `variacao.nome`, e o saldo que vem consultado no pai.
3. Físico × virtual; quantos `idsProdutos[]` cabem por chamada; o máximo de `limite`.
4. Se o `companyId` do webhook é o id de `dados-basicos`.
5. Se o refresh gira a cada renovação, e se o `enable-jwt` vai nas chamadas comuns.
6. Se o objeto `fornecedor` grava custo no POST e no PATCH.
7. Se `filtroSaldoEstoque` omitido traz só os positivos.
8. Se as medidas do Bling são do produto embalado (2–3 produtos reais).
9. Se `/produtos/lojas` reflete o vínculo com o ML em conta que usa a Gestão de Anúncios.

**Massa mínima:** 1 produto simples, 1 com variação de cor (e 1 com cor × tamanho), 1 kit virtual, 2 depósitos e 1
campo customizado.

**Se ligar a conta de teste ao ML,** só à #459 (MGSTOREL, loja REAL). O Bling passa a mandar estoque e preço aos
anúncios vinculados.

**Perguntas ao suporte do Bling** (+55 54 3771-7278 ou ticket):
- Data de corte do token opaco.
- O que acontece num PUT de pai sem variações.
- O máximo de `limite`.

## Fontes
- **Portal do desenvolvedor Bling:**
  - [aplicativos](https://developer.bling.com.br/aplicativos)
  - [migração JWT](https://developer.bling.com.br/migracao-jwt)
  - [limites](https://developer.bling.com.br/limites)
  - [erros comuns](https://developer.bling.com.br/erros-comuns)
  - [boas práticas](https://developer.bling.com.br/boas-praticas)
  - [perguntas frequentes](https://developer.bling.com.br/perguntas-frequentes)
  - [homologação](https://developer.bling.com.br/homologacao)
  - [webhooks](https://developer.bling.com.br/webhooks)
  - [como testar](https://developer.bling.com.br/como-testar)
  - [referência e OpenAPI](https://developer.bling.com.br/referencia)
  - [changelogs](https://developer.bling.com.br/changelogs)
  - [changelog dos webhooks](https://developer.bling.com.br/webhooks.changelogs)
- **Central de ajuda** (`https://ajuda.bling.com.br/hc/pt-br/articles/<id>`):
  - Gestão de Anúncios e vínculo: 30574574392215, 34677719147415, 34130380190743
  - Estoque e preço para o ML: 34346576685207, 34346845325591, 34347120271767, 360040356713
  - Full e User Products: 4835261790999, 38748223732375
  - Variações: 1500011966862, 360035987033
  - Kits: 360035495774
  - Reserva de estoque: 360036512833
  - Balanço de estoque: 360035628694
  - Homologação: 35472175289751, 35475121603351
- **Planos:** https://www.bling.com.br/planos-e-precos
- **Comunidade:**
  - [Koncili](https://developers.koncili.com/en/docs/direct-erp-integration/bling/technical-information/)
  - [bling-erp-api-js](https://github.com/AlexandreBellas/bling-erp-api-js), que espelha os campos da v3
  - [bling-sdk](https://github.com/GabMalta/bling-sdk), sobre o refresh que gira
- **ML:** [estoque distribuído](https://developers.mercadolivre.com.br/pt_br/estoque-distribuido)
