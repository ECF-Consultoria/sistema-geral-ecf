---
id: PORTAL-01
title: Schema do módulo Mapeamento Estrutural (Portal do Cliente)
status: accepted
date: 2026-09-23
---

# ADR PORTAL-01 — Schema do Mapeamento Estrutural

## Contexto

O módulo leva para o Portal do Cliente o mecanismo da planilha
`Mapeamento_Estrutural_Sellers_Projeto_Polos_2026_1.xlsx`: o seller lista os
produtos e as variações possíveis (combo, kit, combit), registra os anúncios que
já tem no Mercado Livre, vê o que falta publicar e agenda a execução.

Escopo: **o que a planilha cobre, nada além.** Sem preço, estoque, custo ou
métricas.

### Decisões de leitura tomadas com o usuário (23/09/2026)

| Ambiguidade da planilha | Decisão |
|---|---|
| Agenda e Mapeamento não se falam (CB3 OK na agenda, "Publicar" no mapa) | **Um registro só:** concluir Clássico/Premium na agenda É cadastrar o anúncio da oferta |
| Composição: texto livre na Lista × SKU1..3 + QTD no Planejamento | **Estruturada:** a oferta aponta para as ofertas componentes, com quantidade |
| "Kit virtual" como fase, logística e tipo de anúncio | Sai da fase. **Logística** guarda a intenção; **flag do anúncio** guarda o "montado". A linha K10 do painel sai (§Kit virtual) |
| Anúncios existentes: colados da exportação do ML | Um a um dentro da oferta **e** colagem com reconciliação: SKU que não casa vai para uma área de espera (§`estrutura_anuncios_espera`) |

## Decisão

Prefixo `estrutura_`, e não `mapeamento_`: o projeto já tem
`onboarding_mapeamentos` e `MapeamentoInicial.jsx`, que são outra coisa. O
rótulo "Mapeamento Estrutural" aparece só na tela.

### `estrutura_ofertas` — uma linha da aba "Lista SKUs"

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | **é o que identifica a oferta**, não o SKU |
| `company_id` | FK `companies`, `cascadeOnDelete` | escopo; sempre do `PortalContexto`, nunca do request |
| `sku` | varchar(120) NOT NULL | texto do cliente; **sem unique** (ver abaixo) |
| `fase` | varchar(10) NOT NULL | `simples` · `combo` · `kit` · `combit` — constantes `FASE_*` no model |
| `nome` | varchar(255) NULL | "Nome do produto" |
| `logistica` | varchar(30) NULL | `mercado_envios` · `full` · `flex` · `transportadora_me1` · `kit_virtual` · `combinar` — constantes `LOGISTICA_*` |
| `observacoes` | text NULL | |
| `created_at`/`updated_at` | `timestamps()` (nullable) | |

Índice: `(company_id)`.

**Por que o SKU não é unique nem chave:**
`precificacao-onboarding-duas-telas.md` §3 registra 11 produtos colapsados em um
só em produção porque o cliente digita "Não tenho" no SKU de todos. Aqui:

- o anúncio e a agenda apontam para `oferta_id`, então SKU repetido não mistura
  registros;
- na planilha, SKU repetido conta como duas ofertas. O sistema mantém isso, mas
  a tela avisa que o SKU está repetido;
- uma oferta sem SKU não existe na planilha (a linha some), então o SKU é
  obrigatório.

**`unidades` não é coluna, é calculada:** simples = 1; as demais = soma das
quantidades dos componentes. Isso bate com a planilha: CB2 → 2, kit mesa +
cadeira → 2, combit mesa + 4 cadeiras → 5.

### `estrutura_oferta_componentes` — composição de combo/kit/combit

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `oferta_id` | FK `estrutura_ofertas`, `cascadeOnDelete` | a variação |
| `componente_id` | FK `estrutura_ofertas`, **`restrictOnDelete`** | a oferta simples que entra nela |
| `quantidade` | unsigned smallint NOT NULL | ≥ 1 |

Unique: `(oferta_id, componente_id)`, com nome curto explícito.

`restrictOnDelete` no componente: não se apaga a Cadeira 01 enquanto um combo
ou kit a usa. A tela diz quais variações a usam.

**Regra de composição, num lugar só (service), conforme a aula:**

| fase | componentes |
|---|---|
| simples | nenhum |
| combo | exatamente 1, com quantidade ≥ 2 ("mesmo produto, mais unidades") |
| kit | ≥ 2 distintos, todos com quantidade 1 ("produtos diferentes juntos") |
| combit | ≥ 2 distintos, ao menos um com quantidade ≥ 2 ("kit com mais unidades de um item") |

- Todo componente é uma oferta **simples da mesma empresa**. O FK não garante a
  empresa; o service confere.
- Não herda o teto de 3 componentes do Planejamento, que era limite de coluna da
  planilha.

### `estrutura_anuncios` — uma linha da aba "Anúncios"

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `oferta_id` | FK `estrutura_ofertas`, `cascadeOnDelete` | substitui o casamento por SKU da planilha |
| `tipo` | varchar(10) NOT NULL | `classico` · `premium` |
| `catalogo` | boolean NOT NULL default false | "Catálogo? Sim/Não" |
| `kit_virtual` | boolean NOT NULL default false | o marcador KIT VIRTUAL do Planejamento (colunas I/T/AH) — ver §Kit virtual |
| `status` | varchar(10) NOT NULL default `ativo` | `ativo` · `pausado` · `inativo` |
| `codigo_mlb` | varchar(20) NULL | normalizado em maiúsculas, `^MLB\d+$`; **único por empresa quando presente** (service); obrigatório só ao concluir pela agenda — ver §Identidade do anúncio |
| `titulo` | varchar(255) NULL | informativo, como na planilha |
| `created_at`/`updated_at` | `timestamps()` | |

- Sem `company_id`: a empresa vem pela oferta. Toda consulta parte de
  `EstruturaOferta::where('company_id', …)` e desce pela relação. Duplicar a
  coluna criaria duas verdades.
- Anúncios duplicados do mesmo tipo são permitidos, como na planilha. Eles
  aparecem, mas não somam no painel.

### Identidade do anúncio

**Revisto em 23/09, antes do código.** A primeira versão exigia MLB em todo
caminho, e isso contradizia a aula. O passo 2 diz: "Cole aqui o SKU e o tipo
(Clássico/Premium)". O mínimo documentado é **SKU + tipo**. Além disso, no ML o
SKU não é campo de primeira classe (é o atributo `SELLER_SKU`; `ml_acervo_itens`
não tem coluna de SKU), então uma exportação pode vir com MLB e sem SKU, ou o
contrário.

- **MLB obrigatório só ao concluir pela agenda.** Quem acabou de publicar tem o
  código na tela, e é aí que um registro sem nome nasceria.
- **Com MLB:** o anúncio é identificado por `(empresa, codigo_mlb)`. Único por
  empresa, olhando `estrutura_anuncios` e `estrutura_anuncios_espera` juntas,
  conferido no service. Não há unique global: ele diria ao cliente que o MLB
  existe em outra empresa.
- **Sem MLB:** a chave é `(oferta, tipo)` entre os anúncios **sem MLB** daquela
  oferta. O service mantém a invariante de **no máximo um anúncio sem MLB por
  (oferta, tipo)**. O cadastro manual de um segundo anúncio recusa e pede o MLB.
  É o que torna o upsert da colagem determinístico.
- Na espera, a mesma regra vale com `(empresa, sku_colado normalizado, tipo)`.

### Kit virtual

Na planilha ele era três coisas. Cada uma foi para um lugar:

| na planilha | no sistema |
|---|---|
| valor da FASE | **sai.** A aula lista 4 fases, e o exemplo usa kit em Fase 3 + logística Kit virtual |
| valor da LOGÍSTICA | fica: `logistica = kit_virtual` (a **intenção**) |
| coluna OK/Pendente/N/A do Planejamento | `estrutura_anuncios.kit_virtual` (**foi montado** no anúncio), como `catalogo` |

A aula trata o kit virtual como ação de publicação ("use kit virtual do ML,
várias etiquetas"). Por isso o "feito" mora no anúncio.

**A linha "Kit virtual" do painel (K10) SAI, de propósito.**
- K10 é `COUNTIF(FASE = "KIT VIRTUAL")`: uma subcontagem de "Ofertas únicas" por
  fase. Na planilha, 2+5+1+1+0 = 9.
- Sem a fase, não há o que ela conte.
- Pendurar ali "anúncios com kit virtual montado" poria um número de outro
  significado, em outra unidade (anúncio, não oferta), na mesma linha recuada
  sob "Ofertas". Quem lesse somaria as fases e não fecharia.
- O kit virtual aparece como indicador por oferta e na agenda, como o catálogo,
  sem ser cobrado: não entra em "completa" nem em "publicados".

### `estrutura_anuncios_espera` — colado sem oferta

O passo 2 da aula é COLAR, e a própria aula avisa "o SKU precisa ser igual ao da
Lista SKUs", porque divergir é o normal. A linha colada cujo SKU não casa não
pode ir para `estrutura_anuncios` (`oferta_id` é obrigatório), e não pode sumir:
o painel passaria a cobrar publicação do que já está no ar.

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `company_id` | FK `companies`, `cascadeOnDelete` | aqui é necessário: não há oferta |
| `sku_colado` | varchar(120) NULL | como veio |
| `motivo` | varchar(12) NOT NULL | `sem_oferta` (nenhuma oferta com esse SKU) · `sku_repetido` (mais de uma) · `sem_sku` (veio só com MLB) |
| `codigo_mlb`, `titulo`, `tipo`, `catalogo`, `kit_virtual`, `status` | como em `estrutura_anuncios` | |
| `created_at`/`updated_at` | `timestamps()` | |

Índice: `(company_id)`.

Tabela separada, e não `oferta_id` nulo em `estrutura_anuncios`: assim
`estrutura_anuncios` continua sempre amarrada, e **nenhuma régua do painel
precisa lembrar de filtrar o que está em espera**.

**Como uma linha sai da espera:**

1. **Vincular a uma oferta existente.** Escolha manual. A linha vira
   `estrutura_anuncios`, na mesma transação.
2. **Criar a oferta a partir dela.** Abre o formulário de oferta com SKU e nome
   (= título) preenchidos.
3. **Descartar.**
4. **Automático, igual à planilha, e só em ESCRITA.** Nenhum GET muta dado. A
   varredura roda dentro da transação de três escritas:
   - **criar oferta**;
   - **mudar o SKU de uma oferta**;
   - **excluir oferta**: os anúncios dela **voltam para a espera**
     (`sem_oferta`, com o SKU da oferta), em vez de sumirem em cascata. É o
     comportamento da planilha: apagar a linha da Lista deixa os anúncios na
     aba Anúncios. Isso também pode desfazer um `sku_repetido`.

   Ela recalcula o casamento das linhas da espera cujo SKU foi afetado. Casou
   com uma única oferta → vira anúncio, e a tela diz quantas. Senão, o motivo é
   atualizado.

O painel mostra **"N anúncios colados aguardando oferta"** como aviso. Ele não
entra nos 10 números da régua e é justamente o que torna visível a falha
"colo 200, 30 não batem".

### Colagem: regras

- **Formato:** texto com colunas separadas por tabulação (o que se copia de
  Excel/Sheets). Com linha de cabeçalho, as colunas são mapeadas pelo nome
  (SKU, MLB/Código, Título, Tipo, Catálogo, Status, Kit virtual). Sem
  cabeçalho, vale a ordem da aba Anúncios da planilha.
  - PENDENTE: calibrar os sinônimos com uma exportação real do ML.
- **Casamento do SKU:** ignora maiúsculas e minúsculas (como o `COUNTIFS`) e
  **apara espaços** (a planilha não aparava; é a falha silenciosa mais barata de
  evitar).
- **Mínimo por linha: SKU + tipo, ou MLB + tipo.** Sem nenhum dos dois →
  erro.
- **Vocabulário aceito** (o mapeamento fica num lugar só, `LeitorColagemAnuncios`,
  com teste):
  - tipo: Clássico/Classico/`gold_special` → clássico; Premium/`gold_pro` →
    premium (vocabulário documentado em
    `2026_07_13_100001_alter_ml_anuncio_rascunhos_add_empresa_tier_sku.php`);
  - status: Ativo/`active`, Pausado/`paused`/`under_review`,
    Inativo/`closed`; em branco = ativo (a planilha contava branco como não
    inativo);
  - catálogo e kit virtual: Sim/Não, `true`/`false`, 1/0; em branco = não.
- **Cabeçalho:** reconhecido pelos nomes da aba Anúncios **e** pelos de
  `ml_acervo_itens` (`ml_item_id`, `title`, `listing_type_id`, `status`,
  `catalog_listing`) e `seller_sku`. **Não calibrar pelos `Anunciar-*.xlsx`:**
  aquele é o template de PUBLICAÇÃO em massa, não exportação do que existe.
- Linha com tipo, status ou MLB irreconhecível **não grava**. Aparece como erro
  na prévia.
- **Duas etapas:** colar → **prévia** (nada gravado; quatro grupos: novos,
  atualizados, aguardando oferta e com erro) → confirmar.
- **Modo "Acrescentar/atualizar"** (padrão): upsert pela chave da
  §Identidade (MLB; sem ele, oferta + tipo).
  - Anúncio já cadastrado: atualiza tipo, status, catálogo, kit virtual e título.
  - A oferta só muda se o SKU colado casar com outra oferta, única.
  - MLB conhecido cujo SKU não casa mantém o vínculo que já tem. Não vai para a
    espera.
- **Modo "Substituir todos"**: o que não está na colagem é **removido**, tanto
  de `estrutura_anuncios` quanto da espera.
  - A prévia mostra, antes de confirmar, quantos e quais serão removidos.
  - Um anúncio cadastrado pela agenda que esteja de fato no ar vem na exportação
    com o mesmo MLB, então é atualizado, não removido. Só some o que não está na
    colagem. Isso é deliberado e fica escrito aqui.

### `estrutura_agenda` — uma linha da aba "Planejamento"

| coluna | tipo | regra |
|---|---|---|
| `id` | bigint PK | |
| `oferta_id` | FK `estrutura_ofertas`, `cascadeOnDelete` | |
| `data` | `date` NOT NULL | dia agendado |
| `acao` | varchar(12) NOT NULL | `publicacao` · `jardinagem` |
| `concluida_em` | **`dateTime`** NULL | só para Jardinagem (ver abaixo) |
| `created_at`/`updated_at` | `timestamps()` | |

Índice: `(oferta_id, data)`.

Os três blocos da planilha (unitários/combos, kits, combits) deixam de existir.
Eles existiam só para caber SKU1..3 + QTD na grade. A fase e a composição já
estão na oferta.

**Quando cada ação conta como feita:**
- **Publicação:** não guarda estado próprio. Clássico feito = a oferta tem
  anúncio Clássico não inativo; Premium, idem. Concluir na agenda cadastra o
  anúncio (MLB e título opcionais) pelo mesmo caminho do cadastro na oferta.
  Assim a agenda e o painel não conseguem discordar.
  O formulário pede **código MLB (obrigatório)** e título (opcional).
- **Catálogo e Kit virtual** na agenda: indicadores derivados (a oferta tem
  anúncio não inativo com a flag). Não são cobrados, como na planilha, e por
  isso não se guarda o "N/A".
- **Jardinagem:** é olhar métricas e ajustar, sem anúncio para derivar. É um
  "feito / não feito" (`concluida_em`), um por linha. A planilha tinha
  OK/Pendente por tipo também na Jardinagem, mas o exemplo não usa isso.

## Escala: paginação desde o primeiro registro

Medido pelo usuário: o maior seller da carteira ML da ECF tem **2.688 anúncios
ativos** (218 sellers, 46.820 no total). A aula manda "listar TODOS". A
comparação com o PPA do portal (dezenas de tarefas) erra por uma ordem de
grandeza.

**Decisão: não há limiar. A visão Ofertas pagina no servidor sempre**, com 25
blocos por página. Um bloco é um produto simples com os combos dele, ou um
kit/combit.
- Filtro de situação e busca (SKU, nome ou MLB) vão por query string ao
  servidor.
- O **painel é calculado sobre o conjunto inteiro**, nunca sobre a página.
- Um limiar criaria dois caminhos (filtro no navegador abaixo, no servidor
  acima), e o de cima só seria exercitado pelo maior cliente.
  `portal-do-cliente.md` §25 registra o que acontece quando filtro de navegador
  encontra dado paginado: contador dizendo 7 sobre lista vazia.
- Quem tem poucas ofertas vê uma página só, sem paginador.
- O cálculo carrega o conjunto da empresa em PHP: ofertas + componentes +
  anúncios, alguns milhares de linhas. A tela entrega só a página, e o DOM fica
  pequeno. `painel-polos-desempenho-da-grade.md` registra o painel que travou
  por DOM.

**Agenda:** cada seção (atrasadas, próximas, concluídas) é limitada no servidor
(100 / 100 / 20), com o total mostrado. No ritmo de 1 por dia, 100 próximas são
três meses.

## Régua do painel (service único, reproduz K5:K15 da planilha)

"Não inativo" = status `ativo` ou `pausado`. É a nota J17 da planilha: só
"Inativo" é ignorado.

| indicador | regra |
|---|---|
| ofertas | nº de ofertas da empresa (SKU repetido conta duas vezes) |
| por fase | nº de ofertas em cada `fase` |
| anúncios necessários | ofertas × 2 |
| já publicados | Σ por oferta de [tem Clássico não inativo] + [tem Premium não inativo] |
| a publicar | necessários − publicados |
| ofertas completas | ofertas com Clássico e Premium |
| % publicado | publicados ÷ necessários; 0 se não houver oferta |
| situação da oferta | OK / Falta Clássico / Falta Premium / Publicar Clássico + Premium, exatamente como a coluna H |

**Fixture de teste (gabarito da planilha):** 9 ofertas (2 simples, 5 combos,
1 kit, 1 combit) e 4 anúncios. O resultado esperado são os **10** números que
sobrevivem, `9 · 2/5/1/1 · 18 · 4 · 14 · 1 · 22,2%`, com a situação de cada
linha como na planilha, e as unidades `1,2,3,4,5,6,1,2,5`. O 11º (K10, Kit
virtual = 0) sai de propósito (§Kit virtual).

**Segundo teste:** concluir CB3 pela agenda (a linha de 23/09 do exemplo) leva o
painel a `6 publicados · 12 a publicar · 2 completas · 33,3%`. É a contradição
da planilha que deixa de existir.

## O que muda em relação à planilha, de propósito

- Sem SKU como chave, somem de uma vez: anúncio órfão, SKU numérico fora da
  contagem, linha inserida que some do mapa e `#REF!` ao excluir linha.
- Os vocabulários passam a ser travados (`Rule::in`). Na planilha, o dropdown
  aceitava qualquer texto.
- A coluna **QUANT.** do Planejamento não entra. A aula não a cita, e o próprio
  exemplo refuta a leitura "unidades" (CB3 tem QUANT. = 1).
- Os tetos de 500 SKUs, 2.000 anúncios e 300 linhas de agenda não existem.

## Armadilhas de MariaDB observadas

- Nenhum `enum`: varchar + constante no model.
- Nenhum `timestamp()` NOT NULL: `concluida_em` é `dateTime`, `data` é `date`,
  e `timestamps()` é nullable.
- Nomes de índice e FK explícitos e curtos (limite de 64 caracteres).
- A migration roda contra o MariaDB 10.4 local, não só contra o SQLite.

## Autoria

Cliente e equipe (sessão de equipe no portal) escrevem. Cada escrita grava
`origem` (`cliente` | `interno`) no activity log. O `causer_id` não distingue os
dois (`portal-do-cliente.md` §12).
