# Trilha de produtos — o que não é dedutível do código

Escrito em 30/10/2026, na 1ª entrega (só leitura); a revisão de 31/10
acrescentou o §14 ao §19 e itens no §5, §9, §12 e §13. Leia antes de mexer em
`app/Services/Trilha/`, `routes/trilha.php`, `resources/js/Pages/Trilha/` ou
`resources/js/Components/Trilha/`.

A trilha é o desenho do Erlon (gestor, "arquiteto da obra") para juntar o
cadastro do cliente, o Planejamento, o Publicador e a Jardinagem numa tela só,
em 6 etapas com dono: Lista de produtos (cliente) → Mapeamento estrutural →
Planejamento (ondas) → Preparação automática (agente de IA) → Revisão e
publicação → Protocolo e Jardinagem. Ela vai SUBSTITUIR o cadastro de produtos
do Portal para o cliente; a lógica já existia, o trabalho é adaptar.

---

## 1. "Mapeado" não existe no banco — é derivado

Nada marca um produto como "mapeamento concluído". Nesta entrega, o produto
completo fica na etapa 2 enquanto existe sugestão PENDENTE (não descartada) do
Planejamento que envolve alguma cor dele (`SugestoesService::gerar`). Aceitar
vira oferta e some da lista; descartar grava em
`estrutura_sugestoes_descartadas`. Produto sem nenhuma sugestão passa direto.

Consequência conhecida: não dá para "concluir só com a simples" sem descartar
as sugestões uma a uma. O botão "Concluir mapeamento" (com tabela própria) é da
próxima entrega.

## 2. Uma "oferta da trilha" não é uma linha de `estrutura_ofertas`

O Portal cria UMA oferta simples por COR (`estrutura_ofertas.variacao_id`). O
Publicador junta as cores de um produto num pub_produto só
(`pub_produtos.estrutura_produto_id`), com as cores como variantes. E o Combo N
de cada cor vira uma cor do Kit N, a "fase" (`pub_produtos.produto_base_id` +
`quantidade_kit`), que só nasce depois que a simples foi publicada.

A trilha agrupa como o Publicador, senão um produto de 3 cores apareceria 3
vezes e o combo 3×N vezes:

| Item da trilha | Chave | Do que é feito |
|---|---|---|
| Simples do produto | `s-{produtoId}` | todas as simples das cores do produto |
| Combo N do produto | `c-{produtoId}-{N}` | as ofertas `combo` de 1 componente × N de todas as cores |
| Kit / Combit / oferta sem produto | `o-{ofertaId}` | a própria linha de `estrutura_ofertas` |

## 3. Não há relação do Portal para o Publicador

`EstruturaOferta` e `EstruturaProduto` não conhecem `PubProduto`. Todo caminho
vai de `pub_produtos` para o Portal (`estrutura_produto_id`, `oferta_id`,
`produto_base_id`). E os anúncios vêm de DUAS fontes, que precisam ser unidas
sem repetir MLB: `estrutura_anuncios` (régua do Portal: colado, importado,
digitado e o espelho do Publicador) e `pub_publicacao_itens` com `CREATED`. A
fase (Kit N) do Publicador nunca é espelhada em `estrutura_anuncios`.

## 4. Ler o estado do Publicador sem gravar

O "Pronto"/"Faltam N itens" do Publicador vem de
`pub_rascunhos.step_state.resumo`, que só vale se estiver EM DIA (mesma
`revisao` do rascunho, chave `validado`, `versao` igual à
`EditorRascunhoService::VERSAO_DO_RESUMO`). Recalcular com
`EditorRascunhoService::estado()` custa 40–80 ms por rascunho e GRAVA — a trilha
nunca chama. Resumo vencido vira "a conferir".

O que o agente está fazendo vem de `IaTrabalhando::no()` e de
`step_state.ia_preparo.etapas` (título, modelo, descrição).

## 5. Duas portas, um código só

- Equipe: `admin…/trilha` e `/trilha/{empresa}`, atrás de
  `modulo:trilha.produtos` — módulo que nasce OCULTO (só o Dev entra; os outros
  recebem 404, como se a rota não existisse). Para mostrar ao Erlon sem ele ser
  Dev: liberar o módulo no Controle Dev (continua sem item de menu). Antes,
  depois do deploy, `php artisan modules:sync` na VPS: o deploy não o roda, e
  sem ele a linha `trilha.produtos` não existe na tabela `modules` — o Controle
  Dev não tem o que liberar, e a rota fica fechada para todos fora o Dev
  (fail-closed). O `modules:sync` cria a linha já oculta.
- Cliente: `cliente…/portal/{empresa}/trilha`, dentro do mesmo grupo
  `portal.auth` do Portal. Fica FORA do catálogo `ModulosPortal` de propósito
  (sem item de menu enquanto está escondida) e precisa da linha
  `'portal/trilha'` na allowlist do `RestringeDominioDoPortal`.
- As duas só abrem para empresa na chave `trilha.empresas` (env
  `TRILHA_EMPRESAS`, padrão `459` — a loja de teste do usuário).
- A porta do cliente recebe os dados SEM o que é da equipe: link do Publicador
  (rotas `role:admin`), detalhes do agente, código MLB, lista de sugestões. O
  corte é feito no servidor, não na tela.

## 6. Tema próprio, de propósito

A trilha nasce clara (como o protótipo) e tem o escuro como opção, com cores em
variáveis (`resources/css/trilha.css`, grupo `trilha` do Tailwind) e chave
própria no localStorage (`ecf-trilha-tema`). O modo claro do resto do sistema é
um remendo (`light.css` reescreve `text-white`/`bg-white/…`) com telas tortas:
usar a chave `ecf-theme` aqui jogaria o sistema inteiro nele. Por isso o código
da trilha não pode usar `text-white`, `bg-white/…`, `bg-ecf-*` nem `pub-*`.

O Tailwind só varre `.jsx`: classe escrita em `resources/js/lib/*.js` não é
gerada. Em `.js`, cor vai por `style` com `rgb(var(--t-…))`. No SVG (Mapa da
loja) também: `var()` em atributo de apresentação (`fill="rgb(var(--t-…))"`)
não é garantido em todo navegador; em `style`, é.

A barra de rolagem: o `app.css` pinta o polegar de branco translúcido (feito
para o fundo escuro do sistema) e no claro da trilha ele some. A barra da
PÁGINA não obedece a `html::-webkit-scrollbar-*`: o Chrome a desenha com o
estilo do `<body>` quando o body tem barra própria — e tem, porque o
`::-webkit-scrollbar` global casa com ele. Por isso o `TrilhaLayout` marca o
`<html>` (`data-trilha-tema`) e o `trilha.css` cobre `.trilha ::-webkit-…` e
`html[data-trilha-tema] body::-webkit-…`. O `scrollbar-color` (Firefox) fica
dentro de `@supports not selector(::-webkit-scrollbar)`: no Chrome 121+ ele
desliga as regras `::-webkit-scrollbar` e traz a barra larga do sistema.

## 7. "Pronto" exige `validado`, não só zero bloqueio

O editor do Publicador grava `step_state.resumo` com `bloqueios = 0` e
`validado = false` quando o rascunho NÃO tem categoria: sem schema não há o que
validar. Zero bloqueio, ali, não quer dizer pronto — a vitrine diz "Falta
categoria". A trilha só escreve "Pré-pronto · esperando a revisão" com
`validado === true`; sem isso o item cai em "Preparando".

## 8. O agente não escreve o texto do kit da fase N

`PreparoIaDoRascunhoService::avaliarIa` pula o kit da fase N (o Combo N no
Publicador: `pub_produtos.produto_base_id` preenchido) com o motivo
`kit_da_fase` — o texto dele tem fluxo próprio. Na trilha, para esse item, o
"texto do agente" conta como pronto e as pílulas de título/modelo/descrição
saem vazias (não "na fila"). Sem isso o Combo N ficaria para sempre em
"Preparando", esperando um texto que nunca vem.

## 9. Regras do Publicador COPIADAS em lote — mudou lá, muda aqui

`IaTrabalhando::no()` e `CriativosAutomaticosService::situacaoDasImagens()`
fazem uma consulta (e uma leitura de cache) POR rascunho; chamar os dois por
item quebraria o número fixo de consultas. `App\Services\Trilha\SituacaoDasOfertas`
reproduz as mesmas regras em lote, com cópias das constantes PRIVADAS de lá:
10 min (escrevendo), 30 min (gerando imagens), `EM_ANDAMENTO_VALE_MIN` = 180
(etapa `rodando`/`adiada` mais velha que isso é Job que morreu → "O agente
parou") e a lista `KIT_EM_ANDAMENTO`. Nenhum teste pega a divergência: quem
mexer numa dessas constantes no Publicador precisa mexer na cópia (a
referência está no comentário de cada uma).

Desde a revisão de 31/10 entra na lista a régua das imagens automáticas (§14):
`SituacaoDasOfertas::estadosDasImagens()` copia o laço de
`CriativosAutomaticosService::situacaoDasImagens()` — a marca `adiado` do teto
do dia; grupo com kit e sem `aplicado_em`; kit em erro → nova tentativa ou
falhou de vez. `podeTentarDeNovo()` e `TENTATIVAS` são públicos e lidos de lá;
o laço, não.

## 10. `replaceState` com o `history.state` do Inertia: a URL guardada envelhece

O padrão `history.replaceState(window.history.state, '', url)` — em 30/10, em
8 arquivos: Agenda, Editor e Alavancas do Publicador, AbaPortal, Planejador
FULL, Acessos do Portal no Onboarding, Mapeamento e Sugestões do Portal —
troca a barra de endereço mas deixa `history.state.page.url` com a URL velha.
Quando o "voltar" do navegador recarrega o documento (sem bfcache), o
`InitialVisit.handleBackForward` do Inertia 2.3 compara as duas, faz
`pushState`: duplica a entrada, apaga a da frente e reabre a tela no estado
antigo. A trilha usa `estadoComUrl()` (`resources/js/Components/Trilha/estadoDaTela.js`),
que grava a URL nova também em `state.page.url` (só quando `page` é objeto:
com histórico criptografado, devolve como veio). Provado com o Inertia real
pelo agente da tela em 30/10; as outras telas continuam com o padrão antigo.

## 11. `sr-only` dentro de contêiner que rola alarga a página

A planilha da Lista rola dentro do próprio contêiner (`overflow-x-auto`) no
celular. As células têm `<span class="sr-only">` (posição absoluta): sem
ancestral posicionado, o bloco de contenção delas é a página, elas escapam do
contêiner rolável e a PÁGINA ganhava rolagem lateral (+639 px a 390 px de
largura). O contêiner é `relative` por isso. Vale para qualquer tabela com
`sr-only` dentro de `overflow-x-auto`.

## 12. Para conferir na tela, sem confundir ambiente com regressão

- Worktree novo sem `public/build`: cerca de 31 testes do Portal (domínio,
  multi-abas, link aberto, aba Portal em /companies…) falham com "Vite manifest
  not found". É ambiente: depois do `npm run build` os 229 testes dos 19
  arquivos que citam o domínio do Portal passam.
- `$this->mock()` sem `andReturn` devolve `[]` num método `: array`: um 404
  esquecido no controller aparece como 200 no teste, não como 500.
- A régua do "frete ME1" depende da modalidade de envio em cache
  (`ModalidadeDeEnvio::emCache`, 7 dias, gravada pela cotação real). Sem ela
  valem os limites dos Correios (30 kg, soma 200 cm, maior lado 100 cm): móvel
  grande vira ME1 e o produto fica na Lista com a falta "frete ME1" — tanto na
  trilha quanto na tela de Produtos do Portal. Numa demo, grave a modalidade
  (`ModalidadeDeEnvio::guardar`) antes de concluir que a trilha errou.
- Ligar ou desligar empresa é `TRILHA_EMPRESAS` no `.env` + `php artisan
  config:cache` (em produção a config fica em cache).
- Teste que abre tela da EQUIPE monta as props do menu interno, e o badge de
  alertas pergunta ao ECF Drive: o `phpunit.xml` não troca o `ECF_API_BASE` do
  `.env`. Sem dublar `EcfDriveService::listSignals` (e sem
  `Http::preventStrayRequests()`), o teste bate no ECF Drive de verdade — o
  `AcessoATrilhaTest` caiu de 13,1 s para 7,3 s com o dublê.
- O build grava o `ASSET_URL` do `.env` como base dos pedaços das páginas. O
  MESMO build servido noutra porta importa os pedaços da porta do `.env` (outra
  origem): o import falha e o `app.jsx` recarrega a página — depois do login,
  cai em /login → /dashboard, sem erro aparente. Para conferir a tela com outro
  banco (estados que a demo não tem), suba a porta do `.env` com `DB_DATABASE`
  no ambiente e `artisan serve --no-reload` (sem ele o `serve` filtra as
  variáveis do `$_ENV` que passa ao processo filho).

## 13. Em aberto (decisão do usuário/Erlon)

- Sigilo do ML na ficha (decisão de 07/10): a trilha do cliente fala em
  anúncio e publicação. Decidir antes de ligar a trilha para cliente real.
- Siglas de SKU: o protótipo usa `SKU1+SKU2-KIT`/`-CBT4`; o sistema gera
  `KT-…`/`CT{n}-…` desde 09–10/10. A trilha mostra o que o sistema gera.
- Aprovação do cliente e combo antes da Fase 1 no ar.
- A porta da equipe (`/trilha`) não confere carteira: liberado o módulo no
  Controle Dev, qualquer usuário interno vê a trilha de qualquer empresa da
  chave. Hoje só o Dev entra. Se precisar, a régua é a do
  `PortalEquipeService::podeEntrar`.
- Na porta do cliente, os status do robô falam do agente ("O agente está
  escrevendo…", "O agente parou: descrição") e a revisão mostra "A publicação
  falhou". O contrato corta o `preparo` (detalhe do agente), mas não esses
  textos. Decidir antes de ligar empresa de cliente real.
- A falta "medidas" (rótulo de `PendenciasDoProduto`) é a da EMBALAGEM. A
  trilha mostra também as medidas do produto montado, e o rótulo pode confundir
  ("falta medidas" ao lado de "210 × 92 × 85 cm").
- Os contadores "no ar" — o "N no ar" do Ver tudo, o "N de M ofertas no ar" do
  índice e o "No ar · N ofertas" da faixa do Mapa — contam a ETAPA 6 inteira,
  inclusive "Fora do ar · …", "Anúncios encerrados" e "Publicado"; a cor e a
  legenda da etapa 6 no Mapa também dizem "No ar". A revisão de 31/10 manteve a
  contagem por etapa (o índice só recebe do servidor a `contagem` por etapa).
  Com um anúncio em revisão no ML, o resumo diz "3 no ar" ao lado da linha
  "Fora do ar · Clássico e Premium". Para contar só o que está à venda, o
  servidor precisa mandar essa conta, e as três telas mudam juntas.
- "O agente parou: imagens" (§14) é mais um status que cita o agente na porta
  do cliente.
- Todo anúncio novo nasce EM REVISÃO no ML (§16). Até a releitura vê-lo ativo
  (`RelerEstadoDoPublicadoJob`: 90 s e 5 min depois de publicar; depois só a
  rodada diária das 09:20 do `publicador:reler-publicados`), a trilha diz
  "Fora do ar · …" com alerta — também na porta do cliente, logo depois da
  publicação. A lista do Publicador diz "Em revisão no Mercado Livre" no mesmo
  momento. Um texto mais calmo para esse caso ("Em revisão · …") é decisão de
  produto.

## 14. "Pronto" também exige as imagens automáticas

O resumo do editor (`step_state.resumo`) não cobre as imagens automáticas: com
as fotos do cliente ele diz 0 bloqueio. A vitrine do Publicador lê
`CriativosAutomaticosService::situacaoDasImagens()` ANTES do resumo e não diz
"Pronto" quando elas não vieram ("Imagens amanhã" / "Imagens não geradas").
A trilha segue a mesma régua (revisão de 31/10):

- imagens na fila (teto do dia ou nova tentativa amanhã cedo) → o item fica no
  robô em "Preparando" — nenhuma linha da revisão vale, nem "Faltam N itens";
- as duas tentativas falharam → "O agente parou: imagens", com alerta;
- kit só "fazendo" não segura: andando há mais de 30 min, o Publicador também
  já diz "Pronto".

A decisão olha o CONJUNTO dos estados dos grupos, não a pílula: com duas cores,
um kit andando numa escondia a outra que ficou para amanhã. Divergência
consciente: um grupo falhado de vez junto de outro na fila é "Imagens amanhã"
no Publicador e "O agente parou: imagens" na trilha — o falhado precisa de
alguém de qualquer jeito.

## 15. A URL que a tela grava tem de estar na forma do Laravel

Na carga NOVA (F5, link colado, aba nova) o Inertia compara o `page.url` do
servidor com a barra e, se diferem, EMPILHA uma entrada: o 1º "voltar" não saía
da trilha. O servidor escreve a busca pelo `Request::fullUrl()` — o
`normalizeQueryString` do Symfony: chaves em ordem alfabética e RFC 3986 (`:`
vira `%3A`, espaço `%20`). Por isso `buscaComEstado()` grava
`?abrir=p%3A12&etapa=lista&vista=mapa`, e não a forma do contrato
(`?etapa=lista&vista=mapa&abrir=p:12`); a tela lê as duas. Complementa o §10,
que é o "voltar" que recarrega o documento.

- Link escrito à mão na forma antiga ainda ganha uma entrada na 1ª carga (é o
  Inertia); a barra da trilha não gera mais essa forma.
- Chave repetida ou com colchetes (`a[]=1`) ainda sairia diferente; a trilha
  não usa nenhuma das duas.
- As outras telas que gravam a busca com `replaceState` têm o mesmo efeito
  quando a busca sai fora de ordem ou com `:` cru. Não conferidas.

## 16. "No ar" é ativo ou pausado — o resto parado é "Fora do ar"

`SituacaoDasOfertas::CONTA_NO_AR` é lista PERMITIDA (`null` e `Pausado`): em
revisão — o ML põe o anúncio nela logo depois de criar —, aguardando pagamento,
ainda não ativado e qualquer status novo do ML ("Fora do ar") NÃO contam.
Pausado conta: é a decisão do contrato e a régua do Portal. A oferta publicada
fica na etapa 6 de qualquer jeito, com um destes status:

- "No ar · …", com alerta quando outro anúncio dela está parado;
- "Fora do ar · {tipos parados}", com alerta (o porquê de cada anúncio a linha
  e o painel já mostram: "Clássico: Em revisão");
- "Anúncios encerrados", com alerta.

A pílula da linha (`estaNoAr()` em `lib/trilha.js`) lê o PREFIXO "No ar" do
status: na etapa 6 a equipe lê "No ar" ou "Conferir". Mudar o texto no
servidor muda a pílula. No Mapa, cinza só `Encerrado`/`Inativo` (a lista
`ENCERRADOS` do servidor); o resto parado é âmbar.

## 17. Porta do cliente: a lista de campos vale em todo nível, e as compartilhadas também vão

- `ApresentacaoDaTrilha` passa os componentes por lista (`COMPONENTE`) nas duas
  portas e manda `ondas: []` ao cliente até a entrega das ondas decidir quais
  campos dela ele vê. O "Ver como: Cliente" corta igual no navegador
  (`paraVisaoCliente`).
- As props COMPARTILHADAS do `HandleInertiaRequests` vão no mesmo data-page.
  `alertas_criticos_count` (a carteira inteira no ECF Drive) era calculada sem
  olhar quem pedia e ia ao Portal do cliente; agora só sai para quem tem usuário
  `web`. A varredura do `AcessoATrilhaTest` exige que toda compartilhada chegue
  VAZIA ao cliente (fora `asset_url`, `csrf_token`, `errors` e `flash`): prop
  compartilhada nova com dado interno precisa olhar o guard `web`, ou o teste
  cai. O conserto do `HandleInertiaRequests` foi num commit próprio, com teste
  que não depende da trilha (`PortalCliente/AlertasCriticosSoNaEquipeTest`):
  o vazamento era do Portal inteiro, e pode subir sozinho. Já a varredura da
  trilha depende dele.

## 18. O "Pronto quando" da Lista está amarrado à régua do servidor

O texto (`ETAPAS[0].pronto` em `lib/trilha.js`) cita tudo o que segura o
produto na Lista, porque o cliente segue a regra escrita. O teste de render lê
`PendenciasDoProduto::ROTULOS` e os `$faltas[] = '…'` do `CompletudeDoProduto`
direto das fontes PHP: pendência nova no servidor que o texto não cite derruba
o teste. Atualize o texto junto.

## 19. Achados da revisão de 31/10 deixados para depois

Reais e de gravidade baixa, cada um confirmado por um cético. Nenhum estraga
dado. Os seis primeiros pedem decisão (usuário ou Erlon) antes do código.

Decisão antes do código:
- **F2** — o "Onde está" da OFERTA (`PainelLateral`, `OndeEsta` de `Pecas.jsx`)
  marca Lista e Mapeamento como concluídos enquanto o produto ainda está nelas
  (a Simples pode andar antes do produto). O painel do produto e o da oferta se
  desmentem. Proposta: com componente em `lista`/`mapa` (ou `sem_produto`), não
  marcar as etapas 1 e 2 e avisar "o produto X ainda está na Lista".
- **F5** — o "Ver como: Cliente" (`paraVisaoCliente`) não mostra os botões que o
  cliente tem ("Abrir o cadastro de produtos", "Completar no cadastro",
  "Decidir no Planejamento"). Proposta: desenhá-los desabilitados, com a dica
  "no Portal do cliente".
- **F7** — texto de bastidor na porta do cliente: "As ondas chegam na próxima
  entrega" (para o vendedor, "entrega" é a do pedido), "chegam por outro
  módulo", e na ficha "os campos desta categoria não estão guardados no
  sistema" (soa como erro dele). Proposta: textos por visão — junto com os
  status do agente (§13).
- **F8** — para o cliente, o produto no Mapeamento diz "Acompanhar" na linha, e
  o painel pede "Decidir no Planejamento". Quem decide as sugestões? Se for o
  cliente, `acaoDoItem('mapa', 'cliente')` vira "Decidir"; se não, o botão vira
  o secundário "Ver no Planejamento".
- **D3** — Kit N do Publicador sem Combo no Portal (fase criada no painel entre
  08 e 20/10, ou por "Vincular") não aparece na trilha, nem no ar
  (`OfertasDaTrilha`: o item Combo só nasce de `estrutura_ofertas` fase
  `combo`). E o Planejamento nunca sugere esse Combo
  (`RetratoDoCatalogo::fasesDoPublicador` o dá por existente). Proposta, se
  aprovada: emitir `c-{p}-N` a partir do Publicador para o kit que nenhum combo
  consumiu (só bases com grupo; a legada já aparece com o Combo aceito).
- **T2** — o selo do tipo (S/CB/KIT/CBT) nas caixas do Mapa fica abaixo do
  contraste AA no tema claro (Revisão 2,99:1). O desenho é o do protótipo.
  Proposta: o texto do selo em `--t-fg2`, mantendo o fundo na cor da etapa.

Só trabalho:
- **F4** — no Mapa, o anúncio parado só se distingue pela cor (a caixa diz
  "{tipo} · {MLB}"). Proposta: "{tipo} · {situação}" quando não for ativo, e a
  situação na lista "Anúncios" do detalhe.
- **P2** — id com mais de 18 dígitos em `/trilha/{company}` dá 500 (TypeError
  na coerção para `int`) em vez de 404. Uma linha em `routes/trilha.php`:
  `->where('company', '[0-9]{1,18}')` no lugar do `whereNumber` (mantém
  `/trilha/0459`).
- **P3 (o resto)** — o `@routes` do `app.blade.php` põe no HTML de TODA página
  do Portal as rotas nomeadas do sistema interno (863 em 31/10), entre elas
  `trilha.empresa` e o editor do Publicador. São só endereços, nenhum dado, e é
  do Portal inteiro. Filtrar o Ziggy por grupo é decisão de quem cuida do
  Portal.
- **D4** — falta teste de isolamento entre duas empresas (a consulta de
  `pub_produtos` em `SituacaoDasOfertas` não filtra `company_id`: o isolamento
  vem das subconsultas) e um que compare as constantes copiadas (§9) com as
  originais, por `ReflectionClassConstant`.
- **T3** — ao fechar o painel aberto pela URL, quando o item não tem linha na
  vista, o foco cai no `<body>` (`PainelLateral`, limpeza do efeito). Proposta:
  focar a aba selecionada, ou `#trilha-vista` com `tabIndex={-1}`.
- **T4** — nome em MAIÚSCULAS passa da borda das caixas do Mapa: o corte usa
  7 px por letra (`LETRA` em `trilhaMapa.js`). Proposta: medir com
  `measureText` (cache por texto) ou recortar a caixa com `clipPath`.
- **T5** — a interação (efeito da URL, foco, Esc, Tab, tema) não tem teste
  versionado; só os roteiros Playwright do scratchpad da sessão a conferiram.
  Proposta: extrair para funções puras e testar.
