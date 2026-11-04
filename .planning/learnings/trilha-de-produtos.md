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
  textos. Decidir antes de ligar empresa de cliente real. Os status NOVOS da
  régua da onda (2ª entrega) já nasceram neutros: "Na fila · começa/começou em
  dd/mm", "Na fila · com pendência na Lista", "Ainda não começou (desde dd/mm)"
  — este último é sinal de falha interna e também vai ao cliente.
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
  portas. Desde a 2ª entrega (02/11/2026) as ondas vão ao cliente só com id,
  nome, data e se começou (`ONDA_CLIENTE`): o NOME da onda, que a equipe digita
  livre, é visível a ele; quem criou, não. Nada de `pode_editar`/`escrita` na
  porta dele. O "Ver como: Cliente" corta igual no navegador
  (`paraVisaoCliente`) e a página não passa os endereços de escrita aos painéis
  na simulação.
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
- **F8** (resolvido na 2ª entrega, 02/11/2026: com a trilha de trabalho o cliente
  acompanha e o link do Planejamento do Portal sai da porta dele) — para o cliente, o produto no Mapeamento diz "Acompanhar" na linha, e
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
- **P2** (resolvido na 2ª entrega: `[0-9]{1,18}` em todas as rotas da equipe) — id com mais de 18 dígitos em `/trilha/{company}` dá 500 (TypeError
  na coerção para `int`) em vez de 404. Uma linha em `routes/trilha.php`:
  `->where('company', '[0-9]{1,18}')` no lugar do `whereNumber` (mantém
  `/trilha/0459`).
- **P3 (o resto)** — o `@routes` do `app.blade.php` põe no HTML de TODA página
  do Portal as rotas nomeadas do sistema interno (863 em 31/10), entre elas
  `trilha.empresa` e o editor do Publicador. São só endereços, nenhum dado, e é
  do Portal inteiro. Filtrar o Ziggy por grupo é decisão de quem cuida do
  Portal.
- **D4** (em parte na 2ª entrega: onda, produto e item de outra loja dão 404/422 em
  `OndasDaTrilhaTest`/`MapeamentoNaTrilhaTest`) — falta teste de isolamento entre duas empresas (a consulta de
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

## 20. 2ª entrega (02/11/2026): a trilha virou LUGAR DE TRABALHO — chave própria, nasce vazia

Mapeamento e Planejamento (ondas com data) passaram a ser feitos DENTRO da
trilha, e o robô (o agente de IA do Publicador) só prepara uma oferta no dia da
onda dela. Tudo isso vale só para a chave `trilha.trabalho.empresas` (env
`TRILHA_TRABALHO_EMPRESAS`, padrão VAZIO), cruzada com `trilha.empresas`
(`App\Support\Trilha\TrilhaDeTrabalho`). Fora dela, nenhum gancho roda: cada um
pergunta `TrilhaDeTrabalho::ligada()` primeiro, e a pergunta só lê a config.

**Ligar exige três passos, nesta ordem**: `.env` + `php artisan config:cache` +
`sudo -u www-data php artisan queue:restart`. A trava roda DENTRO dos workers
(`ecf-worker-ia`, `ecf-worker-high`), que carregam a config ao subir — sem o
`queue:restart` eles seguem preparando na hora. O `deploy.sh` só reinicia
`ecf-worker:*`; depois de deploy que mexe na trava, `queue:restart` também.
Nunca `supervisorctl restart` para isso: ele mata geração paga no meio.

Na 459 (decisão do usuário de 02/11/2026), TUDO o que nasce depois de ligar
espera a onda — inclusive o que vier do Portal. Quem testar o Publicador lá
precisa pôr a oferta numa onda com a data de hoje (a soltura roda na hora).

## 21. A trava do robô mora num ponto só: `avaliarIa`, por pub_produto

`PreparoIaDoRascunhoService::avaliarIa()` é o ÚNICO lugar onde a IA de texto
nasce (3 chamadores: `preparar()`, `EntradaDoPreparo::seguir()` e
`PreparadorDaAprovacao::recuperarTexto()`; as imagens automáticas só saem depois
dela passar, ou de `em_dia`/`limite` em `criativosSemCadeia`). A trava
(`App\Services\Trilha\Ondas\PortaoDaOnda::segura`) fica entre `intocavel` e
`incompleto`: antes da cota, da memória e de qualquer Job — o item segurado não
gasta nada e nada é gravado. Devolve `aguardando_onda`.

Por pub_produto, e não no `aoSalvar` (que é por produto): o `preparar()` de um
produto prepara o grupo E os compostos dele, e a Simples e o kit do mesmo
produto podem estar em ondas diferentes. A régua inversa pub → item é
`Ondas\ItemDaTrilha::doPub()` (teste `ItemDaTrilhaTest` confere com o
agrupamento). Mais três ganchos: o Kit N (`CombosDoPlanejamentoService`
pergunta `seguraOCombo` por N), a política do agente da Aprovação (regra
`TRILHA-ONDA`, antes da conta de capacidade) e `seguir`/`recuperarTexto`
tratando `aguardando_onda` como `limite` (encerra BLOQUEADA — sem isso a
execução ficaria PREPARANDO esperando um texto que não vem). A varredura do
agente pula a empresa da chave. Em 02/11/2026 o agente estava DESLIGADO em todas
as contas (0 habilitadas); os ganchos são para o dia em que alguém o ligar.

Erro ao ler segura (e loga `[Trilha] Trava da onda`): é o lado barato.

## 22. "Andou" é UMA régua: a tela e a trava perguntam a mesma

`SituacaoDasOfertas::andou()`: tem anúncio, o rascunho saiu do DRAFT, o robô já
mexeu (etapa do texto registrada OU memória `criativos_auto` — ela existe sem o
texto quando a cota acabou) ou o item é o Kit N. A trava lê o "andou" do ITEM
pela mesma régua (`TrilhaDaEmpresa::itens()`), nunca só o do pub: o item `s-p`
pode ter outro pub (legado por cor) já publicado, e uma trava que olhasse só o
pub seguraria para sempre uma oferta que a tela mostra "no ar" (e que ninguém
consegue pôr numa onda). O que andou antes de a chave ligar não é segurado e não
entra em onda.

## 23. A data da onda é TEXTO `Y-m-d`, comparada em PHP

`trilha_ondas.data` sem cast no model; o serviço grava `Y-m-d`. Com cast `date`
o Eloquent gravaria `Y-m-d 00:00:00`, e no SQLite dos testes (coluna texto)
`'2026-11-06 00:00:00' <= '2026-11-06'` é falso — no MySQL é verdadeiro. "Hoje"
é sempre São Paulo (`TrilhaOnda::hoje()`). A data é o dia em que o robô COMEÇA,
não "publicar até": nenhum texto da trilha diz "prazo".

## 24. O combo na trilha nasce da Simples PRONTA, não da publicada

Decisão do usuário de 31/10/2026 ("o combo não espera a simples vender; se o
Publicador precisar, o agente prepara a simples antes"). Fora da trilha, a
regra de sempre (KIT-05: Fase 1 publicada). Pronta = `Ondas\SimplesPronta`:
rascunho DRAFT/VALIDATED, `situacaoDoTexto() === 'completo'` e imagens
resolvidas (nada no teto do dia, nada esperando nova tentativa, nenhum kit
andando) — o Kit N é CÓPIA do rascunho da Simples no instante em que nasce, e
copiar no meio da geração levaria foto pela metade. Custo: o que a publicadora
corrigir depois na Simples não chega ao Kit N. O KIT-05 só existe no controller
do botão manual; `CriarFaseService::criar` aceita Simples não publicada.

A soltura (`trilha:soltar-ondas`, minutos 7/22/37/52) chama
`CombosDoPlanejamentoService::agendar` para o combo de onda que começou e ainda
não andou, NO MÁXIMO 1×/hora por produto (`Cache::add`) e sem janela de dias: o
Kit N nasce quando a Simples fica pronta, não só na hora do primeiro pedido (a
Simples pode ficar pronta muito depois; o Job não chama IA). Não "conserte" o
limite de 1×/h: sem ele, um Kit N que nunca nasce vira um Job a cada 15 min. Combo sem a Simples planejada leva a Simples junto
para a mesma onda; combo não começa antes da Simples dele.

## 25. Mapeamento na trilha: o servidor expande por COR

O artefato compõe por produto; o sistema, por cor (§2). `MapeamentoNaTrilha`:
Combo N → um combo por cor (um item `c-p-N`); parceiro com 1× → um Kit por par
de cores em PARALELO (`VariacoesEmParalelo::casar`), com 2+ → Combit; "Juntar
todos" → uma oferta por cor da âncora (`casarComAncora`, a régua do Kit de 3 do
gerador, conferida por `CasarComAncoraTest`). Cada composição passa por
`MontagemManualDeOferta::analisar()` (público desde 02/11, com o retrato já
lido): fase, nome, SKU do sistema, "já existe" pela COMPOSIÇÃO (inclusive o Kit
N do Publicador) e avisos. O SKU sai na ordem do TIPO, como o "Montar kit"
(`KT-BB-1-VA-1` quando Banqueta tem tipo e Vaso não) — não na ordem do clique.
O "Concluir" grava tudo ou nada sob a trava da empresa, descarta as sugestões
que sobraram do produto (reversível no Portal, decisão de 02/11/2026) e marca
`trilha_mapeamentos`. Com a chave, o produto completo fica em "falta mapear" até
essa marca — ou até a Simples andar.

## 26. Rodar as suítes do Publicador neste worktree

O `.env` do worktree tem `PUBLICADOR_PREPARO_IA_ATIVO=false`: o `aoSalvar` sai
cedo e os testes do preparo falham (ou passam sem a IA nunca rodar). Os testes
da trilha ligam a config no `setUp`. Para as suítes do Publicador aqui:
`PUBLICADOR_PREPARO_IA_ATIVO=true php vendor/bin/phpunit --colors=never <arquivo>`
— e `--colors=never` porque `grep "^OK"` não casa com a saída colorida. O
`FotosDoPainelServiceTest` falha neste worktree por um arquivo de design não
versionado (`design_handoff_publicador/Aprovacao.dc.html`), não por regressão.

## 27. A soltura e a IA paga: o que a revisão de 02/11/2026 mudou

Achados confirmados por teste antes do deploy (`RepescagemSemLacoPagoTest`,
`SolturaDaOndaTest`, `OndasDaTrilhaTest`):

- **A IA da soltura roda só para os itens pedidos.** `aoSalvar(p)` prepara
  TODOS os pub_produtos de `p`, e `avaliarIa` refaz as etapas que deram `erro`:
  pedir um kit (ou combo, ou repescar) refazia, paga e de carona, a etapa que
  falhou na Simples de uma peça (e vice-versa). Agora a soltura passa
  `soItensPorProduto` → `PrepararProdutoNoPublicadorJob->soItens` →
  `preparar(..., $soItens)`: o Sincronizar e o preencher seguem para todos, mas
  `avaliarIa` só roda para os pubs cujo item (`ItemDaTrilha::doPub`) foi pedido.
  O save do Portal não manda `soItens` (null = como sempre). O Kit N nasce pelo
  `agendar` (1×/h).
- **Repescagem é UMA vez** (`repescado_em`), de outro dia, dentro de 7 dias
  contados da ENTRADA do item na onda (`na_onda_desde` → `comecoEfetivo()`),
  e só para quem tem `sem_texto` — não `!andou`: com as imagens automáticas
  ligadas (a 459 tem), o item que bateu no teto do TEXTO ganha a memória das
  imagens e "anda" sem texto nenhum.
- **A Simples pronta espera as imagens**: o Job delas é o último elo da cadeia
  da descrição; sem memória, espera 30 min do fim do texto (os desfechos sem
  kit — sem foto, sem permissão — não gravam memória, por isso o prazo).
- **A regra combo × simples vale também ao editar a data e ao apagar a onda**,
  e só confere os pares que o pedido toca (antes, um combo quebrado de outro
  produto travava qualquer planejamento).
- **Combo apagado no Portal**: a linha `c-` órfã sai em QUALQUER onda (soltura,
  planejar e Concluir do mapeamento) — senão o combo aceito de novo herdaria a
  onda antiga.
- A pendência na Lista é AVISO ("Na fila · com pendência na Lista"): o robô
  prepara com o que houver; só a ficha incompleta o barra. A tela não pode
  prometer espera.
- A soltura no pedido roda depois do commit protegida (`try/catch`): falhar
  ali não vira 500 (a nova tentativa da pessoa duplicaria a onda).
- **Item sem texto avisa.** Com a onda começada, o item `sem_texto` (mesmo com
  a memória das imagens) segue a régua da onda: "Ainda não começou (desde
  dd/mm)" com alerta depois de 24 h — antes ficava "Preparando" para sempre.
  Com pendência na Lista, o alarme leva " · pendência na Lista".
- **Composto só de ofertas sem produto** (Lista SKUs, importação) é tratado
  como sem produto: fora das ondas, sem trava (o robô nem teria ficha).
- **Tirar da onda** vale para quem já andou (preparado ou publicado à mão antes
  da data); mover, não. **Editar a data** reinicia `na_onda_desde`.
- **Combo apagado e criado de novo** antes da passada: a linha `c-` mais velha
  que TODAS as ofertas do combo é órfã (`SoltarOndas::comboRecriado`).
- **A descrição automática do editor** (D-11, dispara ao abrir) não roda para
  oferta segurada pela onda: abrir a tela não é pedir. O botão manual vale.

## 28. Deploy da 2ª entrega (02/11/2026): o Job com campo novo e o que a conferência achou depois

- **Campo novo em Job enfileirado: NUNCA promovido no construtor.** O
  `656ffd126` pôs `public ?array $soItens = null` como parâmetro promovido do
  `PrepararProdutoNoPublicadorJob`. A propriedade promovida NÃO tem valor
  padrão de propriedade: o Job que entrou na fila antes do deploy volta sem o
  campo (o construtor não roda na volta; o `SerializesModels` pula a chave
  ausente) e o `handle` lança "must not be accessed before initialization" —
  em TODA loja, não só na trilha. Também o preparo ADIADO que um worker ainda
  com o código velho na memória enfileira depois do deploy. A regra: declarar
  fora do construtor com padrão (`public ?array $x = null;`) e ler com `?? null`
  (`ddf33185d`; teste `PreparoJobDeAntesDaTrilhaTest` monta o payload antigo).
  Em produção não chegou a quebrar nada: fila `publicador-ia` vazia na hora.
- **O `deploy.sh` só reinicia `ecf-worker:*`.** Os workers `ecf-worker-ia`,
  `-high` e `-creative` seguem com o código velho na memória até
  `php artisan queue:restart` (como www-data). Ordem segura para ligar a chave:
  deploy → `queue:restart` (código novo, chave desligada) → backup do `.env` →
  linha no `.env` → `config:cache` → `queue:restart`.
- **Produção grava log só de `error` para cima** (`LOG_LEVEL=error`): o
  "aguardando_onda" e as linhas `[Trilha]` de nível info não aparecem no
  `laravel.log`. Conferir pela base: rascunho sem `ia_preparo.etapas` = a IA
  não rodou. A saída do `trilha:soltar-ondas` vai para
  `storage/logs/trilha-ondas.log` (o `appendOutputTo` do agendamento).
- **A chave foi ligada para a 459 em 02/11** (`TRILHA_TRABALHO_EMPRESAS=459`,
  backup `.env.bak_trilha_trabalho_20261102`). Produtos de TESTE criados lá
  pelos serviços do Portal: 86 Cadeira (2 cores), 87 Mesa, 88 Poltrona
  (códigos `TRILHA-*`). A ficha da poltrona veio sem "Cor" porque a origem
  (QA23A) tem a cor como VARIAÇÃO — copiar ficha de produto com eixo de cor
  para um sem eixo deixa a Cor em branco.
- **Defeitos que a conferência por mutação achou depois do deploy** (só na
  empresa com a chave) e o conserto da rodada seguinte:
  - *Save engolido pela soltura*: o debounce (marca nova supera o Job velho)
    fazia o Job restrito da soltura engolir o save do cliente de 20 s antes.
    Agora o Job mais novo leva a UNIÃO do que o anterior ainda não começado ia
    preparar ("todos" vence); o preparo marca `comecou` ao passar da espera de
    editor, e o que começou não entra mais na soma
    (`PreparoIaAgenda::juntarPendentes`).
  - *Combo recriado herdava a onda até a limpeza*: NÃO se deduz pela idade.
    A 1ª tentativa (`comboRecriado`: todas as ofertas do combo mais novas que
    a linha da onda) errava — o combo que ganhou uma cor nova e depois perdeu
    a original nunca deixou de existir, mas parecia "recriado" e perdia a
    onda, até a que já tinha começado. O certo é o EVENTO: o
    `TrilhaComboExcluidoObserver` (`#[ObservedBy]` em `EstruturaOferta`,
    evento `deleted`) limpa a linha `c-` no ato em que a última cor do combo
    sai, na mesma transação; a soltura, o planejar, editar/apagar onda e o
    Concluir do mapeamento limpam só a órfã de verdade (item que não existe
    mais), como rede de segurança para o que apaga por fora do Eloquent.
    Teste que precisa de linha órfã apaga com `DB::table(...)->delete()`.
  - *Kit que ficou sem produto dentro de onda não saía*: tirar vale para ele.
  - Com o agente da Aprovação ligado, a recusa dele (limite do dia, teto da
    fila) gastava a única repescagem; a varredura dele pula a empresa da
    trilha. Agora, com o agente ligado, a repescagem vale uma vez POR DIA na
    janela (a recusa não custa IA).
  - Preparo desligado: a soltura não marca `pedido_em` (nenhum Job sairia).
  - O composto só de ofertas avulsas vira `sem_produto` na TELA só com a chave
    (fora dela a 1ª entrega segue como era); `TrilhaDaEmpresa::itens()` — que
    só a trava, a soltura e o serviço das ondas leem — aplica sempre.
  - Campo novo `pode_tirar` por oferta: a equipe tira da onda que não começou
    também a oferta que andou e a sem produto (a tela mostra só "Tirar").
- **Fica registrado, sem conserto:** combo com Kit N e uma cor "fora" do grupo
  (variação sem valor ou repetida): o pub avulso do combo dessa cor não é
  pedido pela soltura (o item `c-` "andou" pelo Kit N). Só um save o prepara.
  Não reproduzido; achado de leitura.

## 29. 3ª entrega (02/11/2026): o cadastro DENTRO da trilha — decisões e o plano

- A trilha vira um modo novo de usar o Publicador, SEM o Portal: a equipe cadastra o produto na etapa 1 (nome,
  categoria do ML, ficha técnica, cores/tamanhos, fotos, caixa, custo). MESMAS tabelas do Portal (só a tela é nova);
  só a equipe cadastra; o cliente segue lendo. O Portal e o Publicador de hoje continuam.
- Desenho completo e as respostas do usuário: `.planning/trilha/ENTREGA-3-DESENHO.md` (juiz sobre três desenhos;
  fatias F0–F6 + F7 planilha e F8 importar do ML).
- Por que o produto da trilha só vai ao Publicador quando COMPLETO: o Sincronizar roda a cada save, mas só aplica a
  categoria em rascunho SEM categoria e só copia foto para grupo VAZIO (`PortalParaRascunhoService`, conferido). Com
  cadastro aos poucos, o rascunho ficaria preso na 1ª categoria e na 1ª foto.
- **F0 (feita):** a régua da Lista conta as fotos POR COR quando há cor E tamanho (o tamanho sem foto própria leva as
  da cor, como `PortalParaRascunhoService::comFotosDaCor` — mudou lá, muda em
  `CompletudeDoProduto::fotosDeCadaVariacao`). Antes, o produto de moda com 2+ tamanhos nunca saía da Lista.
- "Frete ME1" vira AVISO na trilha (decisão do usuário): um sofá de 216×98×60 cm soma 374 cm e passa de qualquer
  limite do Mercado Envios — ficaria na Lista para sempre.
- Os serviços do cadastro aceitam `Company` + `AtorDoPortal` explícitos; só o CONTROLLER do Portal lê `PortalContexto`
  (que lança fora do `portal.auth`). A trilha usa controllers próprios com a guarda da 2ª entrega.

## 30. F1 do cadastro (03/11/2026): o `lazy` que tira a página do manifest, e o contrato da gaveta

- **`lazy` de uma pasta pode tirar a PÁGINA do manifest do Vite, com o build saindo 0.** A `Trilha/Empresa` carrega
  `Components/Trilha/Cadastro/` por `React.lazy` (o cliente não baixa os textos da equipe). Os pedaços do cadastro
  importam peças que só a página usa (`PainelLateral`, `Pecas`, `trilhaEscrita`…); com um caminho só até eles, o
  Rollup deixa essas peças DENTRO do pedaço da página e o faz exportá-las — e o pedaço deixa de ser a fachada da
  página: a chave `resources/js/Pages/Trilha/Empresa.jsx` some do manifest (vira `_Empresa-….js`). O `@vite([...
  "resources/js/Pages/{$page['component']}.jsx"])` do `app.blade.php` derruba a rota ("Unable to locate file in Vite
  manifest"), e nada avisa no build. Conserto escolhido: `Pages/Trilha/CadastroSobDemanda.jsx`, que não é tela — só
  pede os mesmos pedaços, criando o 2º caminho (o Rollup passa as peças comuns para um pedaço próprio). O teste de
  fonte em `trilha-empresa-render.test.js` exige que todo `lazy` de `Cadastro/` esteja nele. Não foi para
  `manualChunks` no `vite.config.js` porque ele vale para o build inteiro, num arquivo que os dois devs disputam.
  **Depois de qualquer `lazy` novo: conferir no `public/build/manifest.json` que a página continua lá** — "sair 0" não
  prova nada.
- **A trava do cadastro é UMA régua:** `TrilhaDaEmpresa::travasDoCadastro()` — a mesma no `montar`
  (`cadastro_travado`, só na porta da equipe) e nas escritas (`CadastroNaTrilha::travado`, 409
  `{travado, motivo}`). A F6 (versão) deve responder 409 SEM `travado`: a tela só vira "só leitura" com `travado`.
- **Toda linha da rota 3 leva `codigo` e `nome`** (o normalizador do Portal exige; sem eles a linha volta em `erros`
  com HTTP 200) — o `linhaParaServidor` já manda os dois sempre. Na F1 a linha sem `id` é 422 (variação nova é da F4).
- **O retrato devolve custo e medidas como NÚMERO** (o cast do model: `1890.5`, `{c: 60, kg: 8.5}`) e o título
  capitalizado pelo `TituloDoProduto`: o "mexido" da tela compara com o retrato NOVO, nunca com o que foi digitado.
- **Bling: duas travas, duas chaves.** `bling` = SKU travado (vínculo ativo); `estoque_do_bling` = estoque travado
  (só com a sincronização de estoque da conexão ligada). O serviço já ignora os dois ao gravar e avisa; a tela os
  mostra só para leitura.
- **O `criar` prende a linha de `companies`** (`lockForUpdate`) durante o `gravarLinhas`: o duplo clique ou duas abas
  caem no "código já existe", sem depender só do unique. Nenhuma chamada HTTP dentro da trava.
- A conferência de ponta a ponta (`CadastroDePontaAPontaTest` + o teste "o corpo do Salvar é o que o servidor lê" de
  `trilha-cadastro.test.js`) usa o MESMO corpo literal dos dois lados: mudar o formato da linha num lado só quebra
  um dos dois.

## 31. F2 (03/11/2026): categoria e ficha na trilha, e o Portal da loja da trilha também só manda o completo

- **O Portal também segura o incompleto na loja da trilha.** A gaveta da F1 mandava a equipe ao Portal para
  categoria, ficha e fotos — e cada save lá agendava o Publicador com o produto ainda incompleto (o rascunho ficaria
  preso na 1ª categoria e nas 1ªs fotos). Os CINCO caminhos de save do Portal que chamam `PreparoIaAgenda::aoSalvar`
  (o controller, `ProdutoCadastroService::gravarLinhas`/planilha, a oferta criada em `EstruturaOfertaService`, as
  fichas em lote, a ficha vinda do ML) passam por `CadastroNaTrilha::soOsQueCabem`: na empresa da trilha de trabalho,
  só vai o completo ou o que o Publicador já conhece. NÃO no funil `aoSalvar`: a soltura das ondas pede de propósito
  o que está com pendência (é AVISO, §27) e os testes da 2ª entrega chamam o `aoSalvar` direto como "um save".
  Caminho novo que chame `aoSalvar` a partir de um save precisa passar pela régua.
- No normalizador do Portal, `categoria_ml_id: ''` LIMPA a categoria — ao contrário da regra "texto vazio não mexe"
  dos outros campos. A trilha converte `''` em "não mexi" antes do serviço.
- A rota 4 (ficha) recusa a ficha de uma categoria diferente da gravada ("A categoria mudou: salve o produto antes de
  gravar a ficha."): sem isso a ficha de uma categoria gravaria por cima da outra.
- A sugestão de categoria lê os detalhes das candidatas em LOTE (`detalhes`): uma a uma, com o ML lento, somava 15 s
  por candidata e estourava o tempo do PHP — um 500 que o `catch (\Throwable)` não pega.
- `assertJsonPath` não aceita ponto escapado em chave como `errors.linhas\.0\.campo`; o `FichaNaTrilhaTest` usa um
  helper.

## 32. F3 servidor (03/11/2026): fotos, frete, o ME1 que vira aviso e a trilha sem o Portal

- **"Frete ME1" vira aviso só com a chave de trabalho, nos TRÊS pontos que leem a régua** (`TrilhaDaEmpresa::montar`,
  `faltasDosProdutos`, `faltasDoProduto`/`cadastroDoProduto`, que passam `$me1ComoAviso` ao
  `CompletudeDoProduto::avaliar`). Esquecer um deles faz a Lista dizer "completo" e o Mapeamento (ou o
  `prepararSeCabe`/`soOsQueCabem`) recusar — ou o contrário. O aviso é `CompletudeDoProduto::AVISO_ME1` ("vai por ME1
  (transportadora)"), em `avisos` do produto (as duas portas) e em `situacao.avisos` da gaveta.
- **O teste JS do "Pronto quando" lê `$faltas[] = '…'` do PHP**: o aviso é gravado em `$avisos[]` de propósito, para não
  virar "rótulo que segura". O texto com `trabalho` ainda diz "sem pendência de frete ME1" — continua citando o rótulo
  (o teste passa), mas não segura mais.
- **A 459 não tem ME1** (`ModalidadeDeEnvio`): lá o que passa do Mercado Envios sai "Combine a entrega", não por
  transportadora. O aviso é o texto aprovado no desenho; a rota 13 devolve `frete.fora_do_me2` (`me1`/`combinar`/`fora`)
  para a tela dizer o destino certo.
- **O rascunho junta fotos de mesmo conteúdo** (`ImagemAssetService::receber`, por sha256): três
  `UploadedFile::fake()->image()` do mesmo tamanho são o MESMO arquivo e viram UMA foto no Publicador. Teste que conta
  fotos no rascunho precisa de imagens diferentes.
- **As regras de imagem moram em `RegrasDoCadastro`** (`imagens`, `mensagensDasImagens`, `recusarEnvioQueNaoChegou`,
  `bytesDoIni`): o Portal (envio por variação e fotos em lote) e a trilha usam as mesmas. O 413 do `post_max_size`
  vira 422 no `bootstrap/app.php` pelas DUAS regexes (Portal e trilha) — rota nova de envio precisa entrar lá.
- **Com `trabalho`, nenhum endereço do Portal sai da trilha** (decisão do usuário de 03/11/2026, "a trilha substitui o
  Portal"; a F5 do servidor antecipada): equipe sem `links.portal_abrir`; cliente sem `voltar` (o "Voltar ao portal"),
  `links.planejamento`, `links.produtos` e `url_ficha`. O `TrilhaLayout` some com o `voltar` nulo. Sem a chave, tudo
  como na 1ª entrega. A varredura está em `AcessoATrilhaTest` (nenhum `/portal` nas props da página).

## 33. F4 servidor (03/11/2026): cores conferidas pelo RESULTADO, e as exclusões

- **A conferência das cores olha o que FICOU, não o pedido.** `CadastroNaTrilha::gravar` grava numa transação e, antes
  de ela valer, passa as variações do produto pelo `CoresDoGrupo::separar` (a régua com que o Publicador separa em
  produto à parte, em silêncio). Simular o pedido erraria por três coisas que o `ProdutoCadastroService` faz sozinho:
  a variação nova herda o eixo (e o 2º eixo) da 1ª variação NO MOMENTO em que é criada (ordem das linhas importa); a
  linha com erro não grava e as outras gravam; o SKU repetido só se sabe lá dentro. O caso que justificou: a 1ª cor
  (a que nomeia a variação que já existia) falha por custo inválido ou título longo, a cor nova grava sozinha, e a de
  antes fica sem nome ao lado dela. Agora isso é 422 com o erro DA LINHA (`motivo: 'linha'`), sem o "falta a cor"
  — e a variação daquela linha não leva também o erro da cor. Só pedido "estrutural" (linha sem `id` ou com
  eixo/valor/2º eixo/ordem) abre a transação; o resto segue como na F1–F3.
- **"Não piora", não "tem de estar perfeito".** Só recusa a variação fora da régua que NÃO estava fora antes do pedido
  (por id). O produto que já veio torto de outra porta (Portal, planilha, ML) não trava o salvar de outra coisa; a
  resposta do 422 traz todas as que estão fora, as antigas também. Empate de tipo: o `arsort` do PHP 8 é estável, o
  tipo da 1ª variação vence — a nova de outro tipo é a marcada.
- **Contrato do 422 das cores** (a tela usa): `errors` no formato do Laravel (`linhas.{i}.{campo}`, ou
  `variacoes.{id}.{campo}` para a variação que nenhuma linha tocou) e `erros[]` no formato dos `erros` do serviço
  (`indice`, `chave`, `codigo`, `mensagem`, `campos`) mais `variacao_id` (nulo na variação nova: o id foi desfeito) e
  `motivo` (`sem_valor`, `sem_valor_2`, `outro_tipo`, `repetida`, `linha`). Os textos não citam Portal nem Publicador
  (a trilha vira a porta do cliente).
- **O `categoriasDoLote` do serviço chama o ML antes de gravar** — com a transação da F4 aberta. Não segura trava (nada
  foi escrito ainda; leitura do InnoDB não trava) e o `conferirCategoria` já aqueceu o MESMO cache. Não ponha trava de
  linha (`lockForUpdate`) no começo dessa transação: ela passaria a segurar a chamada HTTP.
- **A última variação não sai pela rota da cor**: no serviço ela leva o produto junto (sem a prévia das montadas).
  A trilha recusa com 422 e prende a linha do produto durante a conta — duas abas tirando as duas últimas cores não
  apagam o produto.
- **"Anúncios voltam para a espera" não acontece pela trilha**: a cor com anúncio "andou" e trava o produto (409)
  antes de chegar ao serviço. O `anuncios_para_espera` da rota 14 sai 0 enquanto a trava existir.
- **A galeria da dona passa COPIANDO o arquivo** (`VariacaoImagensService::passarGaleria`): sem o arquivo no disco ela
  só registra um aviso e não passa. Teste que exclui a dona precisa gravar o arquivo das fotos (o `produtoCompleto`
  cria só as linhas).
- **Excluir o produto pela trilha não precisa de limpeza própria das ondas**: `trilha_onda_itens` (FK em `produto_id` e
  `oferta_id`) e `trilha_mapeamentos` saem pela cascata do banco, e o Combo apagado sai pelo
  `TrilhaComboExcluidoObserver` na mesma transação. A prévia (`exclusao/previa`) também responde 409 no produto
  travado: a tela sabe antes de pedir a confirmação.

## 34. F6 (03/11/2026): a versão contra a aba velha — o que não está escrito no código

- **Dois 409, uma chave decide.** O da trava traz `travado: true` (a gaveta vira só leitura); o da versão traz
  `{versao_atual, message}` e NUNCA `travado` (a gaveta mantém o digitado e o rodapé oferece [Recarregar]). Quem
  acrescentar `travado` ao 409 da versão, ou mandar outro 409 sem ele, troca o comportamento da tela sem erro
  nenhum. A trava vem antes da versão (diz mais); a versão vem antes do `conferirCategoria` (a aba velha não consulta
  o ML).
- **A resposta de foto e frete NÃO pode ser adotada como versão da gaveta.** Essas rotas não conferem a versão e
  devolvem o retrato com a versão ATUAL do banco. Se a gaveta a adotasse, a mudança que outra aba fez entraria em
  silêncio e o próximo "Salvar" passaria por cima dela. Regra (`versaoDepoisDe`): só a escrita conferida (linhas,
  ficha, remover cor) troca a versão da gaveta; nas outras, versão diferente na resposta = produto mudado por fora →
  o aviso aparece na hora. Por isso a versão mora num `ref` do hook, não no `retrato` (que é trocado por toda resposta).
- **Remover uma cor de vários tamanhos são N DELETEs**, e cada um precisa levar a versão que o anterior devolveu —
  com a da gaveta, o 2º DELETE levaria 409 da própria gaveta. Idem linhas → ficha no "Salvar" (`gravarCadastro`).
- **Fora da versão: estoque e fotos.** O estoque porque o Bling grava por fora a cada varredura
  (`gravarEstoqueDoBling`) — com ele no hash, toda gaveta aberta numa loja com Bling levaria 409 falso. As fotos
  porque enviar só acrescenta e excluir age por id. Também fora: `atributo_nome` (rótulo da ficha) e `updated_at`.
- **A versão é o hash do CONTEÚDO**, não um contador: se a outra aba desfaz a mudança, a versão velha volta a valer.
  É aceito — o que a aba velha veria já é o que está salvo.
- **O Portal não confere a versão** (risco do §11 do desenho): a trilha percebe o que o Portal gravou (409), mas o
  Portal grava por cima da trilha sem aviso. A mensagem do 409 NÃO cita o Portal (o desenho original citava; saiu
  pela regra de 03/11 "a trilha não tem nada a ver com o Portal") — `CadastroNaTrilha::MSG_VERSAO`.
- O DELETE leva a versão no CORPO (`axios.delete(url, {data})`); o Laravel lê JSON no DELETE pelo `input()`
  normalmente. Sem `versao` (nula ou vazia), nada é conferido — compatível com quem ainda não manda.

## 35. F7 — planilha (servidor) (03/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §17. Serviço `App\Services\Trilha\PlanilhaDaTrilha`, controller
`Trilha\PlanilhaDaTrilhaController`, testes `PlanilhaNaTrilhaTest` e `CustoDaPlanilhaTest`.

- **Dois achados do Portal (fora do escopo, NÃO consertados):**
  1. O Modelo Completo do Portal **corta em silêncio** o que passa da linha 1.001 e da coluna 200: é o `IReadFilter`
     de `ImportadorFichasTecnicas::ler` (`MAX_LINHAS + 1`, `MAX_COLUNAS`). Ninguém avisa. A trilha usa o mesmo
     leitor e se protege por fora (`recusarDadoForaDoCorte`): `listWorksheetInfo` (só percorre o XML) e, se a aba
     passa da área, relê SÓ fora dela — havendo valor, o arquivo volta 422. O modelo formata as colunas de código
     até a linha 1.001, então o arquivo nosso sempre declara 1.001 linhas: a releitura só acontece quando alguém
     escreveu além.
  2. O "Baixar modelo" do Portal **enfileira `GerarExplicacoesDeAtributosJob`** (IA paga): `ModeloCompleto::camposDa`
     usa `definicaoComExplicacoes`, que pede à IA a explicação dos campos que ainda não têm. O perfil da trilha usa
     `definicao()` e o teste prova `Queue::assertNotPushed`. O teste de "arquivo que não serve" gera um arquivo do
     Portal e confere que ELE enfileira — é o que dá sentido à prova da trilha.
- **A "Variação" da trilha vai como `valor`, nunca como `variacao`.** O `NormalizadorDeLinha` lê a coluna `variacao`
  como ORDINAL quando é número ou "única" (o modelo antigo): o tamanho "1" ou "2" viraria `ordem` e a variação
  ficaria sem valor. Idem o `2ª variação` → `valor_2` (string com formato texto no modelo).
- **A Ref é a chave e vai a do BANCO ao serviço.** O `ProdutoCadastroService` grava `codigo` em toda linha com `id`
  (está sempre em `presentes`): a Ref digitada em outra caixa ("lisa-1" × "LISA-1") casa pela `chaveCodigo`, mas
  trocaria o SKU da oferta. A planilha nunca troca nem apaga SKU (§17.1).
- **A Cor só sai da ficha de quem varia por ela se o atributo tiver `allow_variations`** (`eixo_do_portal`,
  `FichaTecnicaDaCategoria::campoDe`). Sem a tag, a coluna "Cor" da ficha grava como campo comum. Catálogo de
  mentira em teste precisa da tag, senão o teste "a Cor não entra" falha sem bug nenhum.
- **`EstruturaProduto::titulo` tem mutator** (`TituloDoProduto::capitalizar`): o `create` do teste já grava "Título
  De Venda…". Para provar "branco não apaga", compare com o título lido do banco antes, não com o literal.
- **A versão (F6) só vale para quem VAI gravar.** A tela devolve `versoes[id]` de todo produto que tinha versão na
  prévia, inclusive o recusado e o sem mudança; a conferência fica DENTRO da transação, depois de saber se o produto
  muda. Sem mudança com versão velha = `sem_mudanca`, sem erro. Id que não é número, ou de produto fora do arquivo:
  ignorado.
- **A prévia é `beginTransaction` + `rollBack` por produto.** Os eventos dos serviços (`produtos_gravados`,
  `ficha_tecnica_gravada`) somem no rollback e o `DB::afterCommit` é descartado. A prova é por contagem de TODAS as
  `estrutura_*`, `activity_log` e `jobs`, mais o `VersaoDoCadastro::conteudo` do produto existente igual antes e
  depois (contagem não pega UPDATE).
- **Erro de linha do serviço não é exceção**: `gravarLinhas` devolve `erros` com tudo o mais gravado. A planilha
  confere `$r['erros']` e desfaz o produto inteiro (decisão do usuário: o produto com erro fica inteiro de fora).
  O 422 das cores (`HttpResponseException`) é lido pelo corpo (`erros[].indice/campos`).
- **422 sempre em JSON nas três rotas** (`emJson` no controller): o download pode sair de um link, e o `validate()`
  do Laravel redirecionaria com a sessão de erros quando o pedido não diz que aceita JSON.
- **Custo medido** (SQLite em memória, máquina local; MariaDB da VPS não medido): 84 consultas por produto na
  prévia e 97 no gravar (produto de 2 cores + ficha), constantes. O fixo do arquivo inclui a régua da trava, lida
  UMA vez (`CadastroNaTrilha::motivosDasTravas`); com a empresa VAZIA ela pula consultas — o teste faz um envio antes
  de medir, senão a diferença de 1 consulta parece custo por produto. **500 linhas** (125 produtos × 4 variações +
  ficha): prévia 5,6 s, gravar 11,1 s. O `carregar` do `ProdutoCadastroService` lê a empresa INTEIRA a cada
  `gravarLinhas` (2 por produto novo: `criar` + `gravar`) — consultas fixas, mas as linhas crescem com o catálogo:
  loja com milhares de variações vai pesar mais que isso.
- **Cache antes das transações (D25)**: `detalhes` em lote, `definicao` por aba e, havendo título acima de 60
  caracteres, `LimiteDoTitulo::daCategoria` (o schema pode ir ao catálogo). Categoria que não responde aqui recusa o
  ARQUIVO (gravar sem ela deixaria a categoria "não validada" e sem ficha). Com o cache quente, prévia e gravar não
  fazem nenhuma requisição (`Http::recorded()` igual antes e depois).
- **Matriz de acesso**: o download é `StreamedResponse`, sem o `status()` que o `TestResponse` repassa — a matriz usa
  `getStatusCode()`. O baixar junta TODO erro de validação em `errors.categorias` (o Laravel poria o item inválido
  em `categorias.0`, chave que a tela não lê e que o `assertJsonPath` nem alcança — §31).
- **Diferenças de propósito em relação ao Portal**: aba de categoria RENOMEADA com dado recusa o arquivo (o Portal
  ignorava a aba com aviso — o produto sumia); termo do título com "portal" sai da seta (`NAO_VAI_NA_TRILHA`). Nome
  de campo e opção da ficha NÃO são filtrados por "portal" (é dado da categoria). Linha sem grupo vira produto
  sozinho pela Ref, como no Portal.
- **Regras do upload em `RegrasDoCadastro`** (`planilha()`, `MENSAGENS_DA_PLANILHA`, `caminhoDaPlanilha`): extraídas
  do controller do Portal, que passou a usá-las (comportamento igual). `recusarPlanilhaQueNaoChegou` só na trilha. O
  413 vira 422 `errors.arquivo` pela regex `^trilha/[0-9]+/planilha(/previa)?$` no `bootstrap/app.php`.

### F7 — planilha (tela), 03/11/2026
- **`lazy` em teste com esbuild:** o bundle de teste (sem divisão em pedaços) transforma o `import()` em promessa já
  resolvida — depois do 1º render, os renders seguintes do MESMO arquivo de teste desenham o componente real, não o
  fallback. "No render do servidor o `lazy` ainda não aparece" só vale no 1º render; foi o que deixou o teste antigo
  do "Pela planilha" dependente da ordem dos testes.
- **Botão desligado em teste de render:** procurar `disabled` na tag de um botão da trilha sempre acha (classes
  `disabled:…` do Tailwind). Conferir `disabled=""`; o ocupado é `aria-disabled="true"`.
- **Nome do download:** `nomeDoArquivoBaixado` (`lib/modeloCompleto.js`) só aceita `[A-Za-z0-9._-]` terminando em
  `.xlsx`; com espaço ou acento no `Content-Disposition` o arquivo sai como `planilha-da-trilha.xlsx`. O servidor
  da trilha gera o nome nessa regra (coberto em `PlanilhaNaTrilhaTest`).
- **O gravar manda os bytes da prévia:** a tela copia o arquivo em memória na hora da escolha; mexer no arquivo no
  disco depois da prévia não muda o que grava. O servidor relê e refaz tudo (sem estado entre prévia e gravar).
- **A suíte JS inteira (`node --test tests/js/`) estoura a pilha** em 8 arquivos `publicador-*-187/188` e no
  `estrutura-grade-glide`; sozinhos passam (ex.: `publicador-fila-linha-187` 23/23). Não é regressão da trilha. Eles
  deixam `tests/js/.pub-*-inertia-stub-*.mjs` soltos quando caem — apagar, não commitar.

## 36. F8 — importar do ML (servidor) (03/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §18. Serviço `App\Services\Trilha\ImportacaoDoMercadoLivre`, Job
`App\Jobs\Trilha\ImportarAnuncioNaTrilhaJob`, controller `Trilha\MercadoLivreDaTrilhaController`, teste
`ImportarDoMlNaTrilhaTest`. Extraídos do Portal sem mudar o comportamento dele: `FotosDoAnuncioMl` (só baixa) e
`TradutorAnuncioMlParaPortal::entradasDaFicha` (pura); `JardinagemDaFicha::completar` virou público.

- **A espera transforma o importado em produto "no ar" (D37).** O `gravarLinhas` do cadastro termina com
  `EstruturaOfertaService::varrerEspera`: a linha de `estrutura_anuncios_espera` cujo SKU (`normalizarSku`: trim +
  minúscula) casa com a oferta nova é PROMOVIDA a `EstruturaAnuncio` daquela oferta. O produto recém-importado nasce
  ligado ao anúncio original, "andou" pela régua única (`SituacaoDasOfertas::andou`) e fica travado (409) — sem
  ninguém ter mexido nele. Por isso a trilha recusa o anúncio ANTES de gravar quando algum SKU dele está na espera.
  O outro caminho de importação não confere isso (fora do escopo, não consertado).
- **`jardinagem` nula deixa o estado do outro caminho "em andamento" para sempre.** O `estado()` do
  `ProdutosDoMercadoLivreService` conta como em andamento o item `pronto` cuja origem tem `jardinagem` nula e sem
  erro (= "a IA ainda vai passar"). A trilha SEMPRE grava o registro: o da IA, o do erro (`{erro, em}`) ou, sem
  categoria, `{campos_ia: [], sem_categoria: true, em}`. Quem criar origem por outro caminho precisa fazer o mesmo.
- **Por que a fila da IA (`PreparoIaAgenda::fila()`, `publicador-ia`) e não a `high`.** Em produção a `high` tem UM
  worker, que também publica anúncio e manda o código de acesso do cliente; cada anúncio da trilha baixa até 12 fotos
  por cor e faz DUAS chamadas pagas à IA (ficha e título), e o lote vai a 20. O Job do outro caminho fica na `high`
  porque não chama a IA na hora (a jardinagem dele já ia para a fila da IA). **No deploy**: o `deploy.sh` só reinicia
  `ecf-worker:*` (§28) — o `ecf-worker-ia` segue com o código velho, sem a classe do Job novo, até
  `php artisan queue:restart`.
- **Nenhum HTTP com a transação aberta (D30) — e a prova não pode usar "`> 0`".** Com `RefreshDatabase` o próprio
  teste roda dentro de uma transação: `DB::transactionLevel()` já é 1 no `setUp`. O detector do teste guarda o nível
  do `setUp` e anota toda requisição feita acima dele. Por que passa: o `criar` prende `companies`, e dentro da
  transação o `conferirCategoria` (`detalhe`), o `categoriasDoLote` do serviço (`detalhes`) e o `gravarParcial`
  (`definicao`) leem só cache — aquecido ANTES (`detalhes` em lote e `definicao`); as fotos são baixadas antes e
  guardadas depois do commit. Prova por mutação: esfriar `ml_meta_categoria_{id}` (a chave do `CategoriaSugestaoService`,
  a MESMA do `MlCatalogoMetaService::categoria`) depois do aquecimento faz o detector anotar `GET …/categories/…`.
- **Fila `sync` nos testes: a exceção do Job vira 500 do POST.** O `importar` despacha e o Job roda dentro da
  requisição; em produção, não. Um 500 no teste do importar é o Job, não o controller.
- **O título do robô (D32)** sai de `PalavrasChaveService::gerarTitulosDoProduto` (aditivo; o caminho do Publicador
  não mudou — as suítes de título dele passam): o mesmo `titulosPorTermos`, `finalizarTitulo`, `semRepetir` e a
  maiúscula, com o contexto montado do PRODUTO (nome, caminho e `max_title_length` do schema — o que o
  `LimiteDoTitulo` lê —, fatos da ficha GRAVADA sem a marca, a marca como proibição). Os termos seguem a régua da IA
  do Publicador (`termosParaIa`: os da categoria, relacionados primeiro, até 50), não a da tela (`termosDaCategoria`
  completa com o nível de cima, que o Publicador só mostra). Termos FORA (exceção do `/trends`) = título para a
  equipe, sem gastar a chamada da IA; termos VAZIOS (404, a categoria sem lista) = a IA só com o nome, como no
  Publicador. `evitar` = o título do anúncio: quando a IA o devolve, o `diferenciar` troca a ordem das duas últimas
  palavras. Um título só: o do Clássico (o do Premium só quando o do Clássico não deu para diferenciar), gravado pela
  gaveta (`CadastroNaTrilha::gravar` na 1ª variação) para passar pelo normalizador e pelo limite.
- **Ref que já existe: dois lugares.** Na lista e no pedido, os `skus` do acervo × as Refs da loja (recusa na hora,
  sem Job); no Job, os SKUs do anúncio lido. O `skus` do acervo pode ser NULO (não coletado) — aí só o Job sabe.
  `na_lista.como = sku` NÃO some com `mostrar_na_lista` desligado: o acervo filtra antes de paginar e não sabe das
  Refs.
- **Trava ocupada pelo outro caminho:** o Job da trilha marca `erro` ("já está sendo importado agora"). O do outro
  caminho volta calado e deixa o item em `fila` por 6 h (a tela dele pergunta para sempre) — achado, não consertado.
- **Contrato do estado (correção da tela de 03/11):** a §18.4 tinha `titulo` duas vezes no item. Ficou:
  `titulo_do_anuncio` = o do anúncio (sempre, desde a fila); `titulo` = o título de venda do robô (nulo até ele
  escrever; nulo no `novo` em que ele falhou). Na LISTA, `titulo` é o do anúncio. O retrato da gaveta ganhou
  `produto.titulo_do_anuncio` (nulo no produto que não veio do ML, sem consulta a mais).
- **Custo medido** (SQLite em memória; MariaDB não medido): 143 consultas por anúncio simples (o POST + o Job na
  fila `sync`, com o cache da categoria quente), iguais no 2º e no 3º anúncio. O 1º anúncio de uma categoria paga
  a mais o schema, os termos e a ficha dela.
- **Moda:** a categoria cujos atributos têm `SIZE_GRID_ID`/`grid_id` (`MlGradeService::categoriaExigeGrade`) entra
  com o aviso "ainda não publica pelo sistema".

### F8 — importar do ML (tela), 03/11/2026
- **`lib/trilhaEscrita.js` é importado de forma ESTÁTICA pela página que o cliente baixa.** Qualquer import novo nele
  entra no pacote do cliente — no 1º build da F8 ele puxou `lib/produtosDoMl.js` (com "Já está no Portal") para os
  `imports` da página. Sintoma só no `manifest.json` (build sai 0, testes passam). Regra: a lib da gaveta monta os
  dados e passa prontos; `trilhaEscrita` não importa lib de gaveta (há teste que proíbe).
- **[Abrir] para um `e:{id}` que ainda não está em `dados.produtos` FECHA a gaveta** (`painelDaChave` devolve null e
  a página chama `fechar`). Por isso a gaveta da importação recebe `dados`, só mostra [Abrir] quando o produto já está
  na Lista ("Entrando na Lista…" enquanto não) e pede uma recarga por produto que falta.
- **A guarda da importação não descarta nada:** fechar não para o Job; o botão é "Sair assim mesmo" (não é de
  perigo) e não há `beforeunload`. A Lista só recarrega sozinha com a gaveta aberta; reabrir retoma pelo `GET estado`.
- **D40 na prática:** o quadro de `Cadastro/QuadrosDaLista.jsx` pode citar "Mercado Livre"; a página e o fallback do
  `lazy` não (o cliente baixa os dois). `trilhaImportarMl.js` é varrido pelo teste de "nenhum Mercado Livre fora de
  `Cadastro/`".
- **Os textos "Portal" que seguem no pacote da página** (`portal_abrir`, "Pela planilha… do Portal") são da porta das
  lojas SEM a chave de trabalho; com a chave (459) os links vêm nulos e o quadro antigo não aparece. Não é vazamento
  da F7/F8 — some quando a trilha de trabalho valer para todas as lojas.

## 37. 4a — prévia (servidor) (03/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §19 (D42/D44). Serviço `App\Services\Trilha\PreviaNaTrilha`, controller
`Trilha\PreviaDaTrilhaController`, rotas `trilha.previa` e `trilha.previa.foto`, teste `PreviaNaTrilhaTest` (+ as duas
rotas na matriz do `CadastroNaTrilhaAcessoTest`). Nada do Publicador mudou: `PreviaDoAnuncioService::montar` é chamado
como está.

- **O `gerada` é CÓPIA da régua das imagens automáticas — mudou lá, muda aqui.** `PreviaNaTrilha::geradas()` copia o
  filtro de `CriativosAutomaticosService::grupos()` (que tira as geradas das referências): ativo do acervo com
  `acervo_imagens.origem = 'ia'` OU foto apontada por `ml_anuncio_criativos.pub_imagem_id`. NÃO é o `de_ia` da
  Aprovação (outra régua). Nenhum teste compara as duas cópias. Efeito conhecido: o Kit N (cópia do rascunho da Simples)
  leva o ativo — a marca do acervo vai junto —, mas o criativo aponta para a linha da SIMPLES, e a cópia da foto que só
  tinha a marca do criativo sai "do cliente". Hoje a aprovação de um criativo já grava o ativo `ia`; o caso é de foto
  anterior ao acervo (e, nas imagens automáticas do Kit N, a mesma cópia contaria como referência — achado do
  Publicador, não consertado).
- **A etapa da prévia é a da TELA, não a de `itens()`.** Sem as ondas, `TrilhaDaEmpresa::itens()` decide pela régua de
  sempre: o rascunho DRAFT com o resumo validado e sem o robô ter mexido sai "Pré-pronto · esperando a revisão"; a tela,
  com a trilha de trabalho, o põe no Planejamento/na fila (não andou → régua da onda). A prévia lê
  `itens($empresa, comoNaTela: true)` (+3 consultas das ondas e as fixas das faltas, que só mudam o texto "pendência na
  Lista"); a trava e a soltura seguem sem o parâmetro. `itens()` ganhou `status`, `alerta`, `pub_produto_id` e
  `preparo` (aditivo) — o `pub_produto_id` não depende da onda.
- **A rota do Publicador marca `EditorEmUso` até num GET** (`MlbPublicadorController::produto()` → `marcar`): "em uso"
  faz a fila de publicação ESPERAR (`AgendadorDaFila`). Por isso a trilha nunca passa por ela; o teste prova o
  contraste (a da trilha não marca, a de lá marca).
- **O `montar` LANÇA** quando o schema da categoria precisa vir do ML e não vem (cache frio, ML fora, token): a rota do
  Publicador dá 500. A trilha pega (`\Throwable`, loga `[Trilha] Prévia do item…`) e responde 200 com
  `MOTIVO_INDISPONIVEL` — o painel não cai.
- **Fora da revisão: 200 com `pronta: false`, não 409** (o contrato §19.5 já descreve o "não pronta"; o item vai junto,
  com etapa e status da linha). No ar (`lanc`), só há prévia se o rascunho foi publicado pelo sistema
  (`PUBLISHED`/`PARTIALLY_PUBLISHED`): o anúncio colado/importado não tem plano que o descreva → `MOTIVO_SEM_PREVIA`.
  Os motivos do Publicador ("na etapa Produto", "em Condições de venda", `RegraViolada` citando o Mercado Livre) nunca
  saem; o `envio.aviso` dele também não (sai sempre nulo).
- **O preço da Precificação é relido.** O `montar` não devolve o "da Precificação"; a trilha refaz a MESMA leitura
  (`DadosEfetivosService::daProduto` → `RascunhoSnapshot::comEfetivosDe` → `dados.portal[lt].anunciado`, o preço da oferta
  da cor ou o da âncora) — a Precificação roda duas vezes por prévia. `valor` e `da_precificacao` são da 1ª cor ativa
  do tipo; `ajustado` olha TODAS (gravado no rascunho, sem a marca `preco_do_portal`, e diferente em ≥ 0,005 — ou a
  Precificação sem preço). O preço de cada cor está em `anuncios[].preco`.
- **A foto confere pela mesma régua, a cada pedido.** `trilha.previa.foto` roda `itens()` (sem as ondas) para achar o
  rascunho do item: consultas fixas, mas lê o `step_state` de TODOS os rascunhos da loja por foto (a galeria pede ~6–12).
  Na 459 não pesa; se pesar, guardar item → rascunho por poucos minutos (não feito: seria a 1ª escrita, em cache, de uma
  fatia só de leitura). A foto NÃO confere a etapa (a lista das fotos já vem da prévia). `caminho` nulo (Anunciar antigo)
  redireciona para o `ml_url` SEMPRE em https (`http://` guardado viraria conteúdo misto e o navegador bloquearia — a
  regra do `emHttps` da F8); outro esquema = 404.
- **O contador não é o `trilha.previa`**: esse prefixo de throttle já era o da prévia do "Mapear"
  (`trilha.mapeamento.previa`) — as rotas novas usam `trilha.revisao.previa`/`trilha.revisao.foto` (os NOMES de rota são
  os do desenho).
- **Testes:** o controller fica guardado na rota entre os pedidos do MESMO teste (`Route::getController`; só o Octane
  chama `flushController`) — dublê trocado no container depois do 1º pedido não chega. O da Precificação é um objeto
  mudado no lugar. O cenário é o do preparo de verdade (`CenarioDoPreparo`), com as chaves da trilha ligadas só DEPOIS do
  preparo (com elas o robô esperaria a onda) e o relógio 5 min à frente (a marca "agendado" de 80 s deixaria a Simples
  em "Na fila do agente"). Na conta das consultas, relação vazia o Eloquent PULA (`familia` nula em todos os produtos =
  zero consultas; o 1º com família = +1): a base do teste já tem de tudo antes de medir, senão +1 parece custo por produto.
- **Custo medido** (SQLite em memória): 105 consultas por prévia e 15 por foto, iguais com mais produtos, kits,
  outra loja e mais fotos; nenhuma chamada ao ML com a modalidade da conta no cache (sem ela, a conta liberada lê
  `users/me`/`shipping_preferences` — o detector do teste pega).

### 4a — prévia (tela), 03/11/2026
- **A pasta `Revisao/` também precisa do 2º caminho** em `CadastroSobDemanda.jsx` (`PEDACOS_DA_REVISAO`), como a
  `Cadastro/`. O teste dos `lazy` cobre `Cadastro|Revisao`, e `lib/trilhaPrevia.js` só pode ser importada de dentro
  de `Revisao/` (senão vai para o pacote do cliente).
- **"Revisar" abre `v:` só na etapa `revisao`.** A oferta publicada continua abrindo `o:`, com [Ver prévia] como ação
  secundária. Sem `escrita.revisao` (cliente, "Ver como: Cliente"), tudo fica como antes e o link do Publicador volta
  a ser o principal (só admin).
- **Pendência para a 4e (D59):** o "Onde está" mostra "Publicador" como dono da etapa 5 — texto que vem de `ETAPAS`
  em `lib/trilha.js` e já vai no pacote do cliente. Trocar antes de a prévia ir ao cliente.
- **`fmtPreco` usa `toLocaleString`**, que põe espaço sem quebra (U+00A0) em "R$ 899,90": teste que compara texto
  precisa normalizar esse espaço.
- **`variante` é a chave canônica do Publicador** (`__single__`, `COLOR=id:52049`…), não `v1`; a tela não depende do
  formato (as fixtures usam `v1`).

## 38. 4c — preço (servidor) (04/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §19.11 (D45/D46/D47). Serviço `App\Services\Trilha\PrecoNaTrilha`, controller
`Trilha\PrecoDaTrilhaController`, rota `trilha.preco` (`PUT …/ofertas/{item}/preco`, contador `trilha.revisao.preco`),
`escrita.revisao.preco` com `__ITEM__`, teste `PrecoNaTrilhaTest` (+ a rota na matriz do `CadastroNaTrilhaAcessoTest`).
Nada do Publicador mudou: o `EditorRascunhoService::salvarVariantes` é chamado como está.

- **O `salvarVariantes` troca o mapa `precos` INTEIRO da variante** (`gravarVariacao` grava `dados.precos[lt] ?? null`
  para cada alvo). Por isso a trilha manda, por cor, o mapa GRAVADO (o `RascunhoRepository::snapshot` cru) com o tipo
  pedido trocado — NUNCA o do `comEfetivosDe`: o efetivo já traz o preço herdado da Precificação, e gravá-lo congelaria
  esse preço no rascunho (o defeito do Anunciar antigo, `16` §1.6; a mutação que troca o cru pelo efetivo derruba o
  teste de "todas as cores": o Premium deixa de ser nulo). Só as cores que mudam vão ao `salvarVariantes`; o pedido
  que não muda nada (comparado com o GRAVADO — `null` num tipo que já herda é "nada") não grava: a `revisao` não sobe e
  a conferência não perde a validade à toa.
- **O `salvarVariantes` não aceita ator**: o histórico é da trilha (`RegistroEstrutura`, canal `portal`, evento
  `trilha_preco_ajustado`, origem `interno`, causer = o usuário, subject = o `PubRascunho`), UM registro por tipo que
  mudou, com `tipo`, `cor` (a do pedido; nula = todas), `de` (cor → o preço EFETIVO antes, o que iria ao anúncio) e
  `para` (`null` = voltou à Precificação).
- **A ordem do 409**: estado primeiro (na fila → publicando → publicado → fora da revisão), depois a `revisao` — o
  estado diz mais (recarregar não tira da fila). "Publicando agora" é etapa `revisao` na linha da trilha
  (`SituacaoDasOfertas::decidir`): o 409 tem de olhar o `status` do rascunho, não a etapa. "A publicação falhou"
  (`FAILED`) continua editável. A `revisao` AUSENTE (ou fora da forma) é 422 `errors.revisao` — o contrato não dizia;
  sem ela não há como saber se a aba é velha. Nenhum 409 leva `travado` (o 409 da revisão leva `revisao_atual`).
- **Sem transação de fora, de propósito.** O `salvarVariantes` trava o rascunho só dentro da transação dele e propaga o
  estoque aos kits DEPOIS dela; embrulhá-lo numa trava de fora juntaria duas travas de rascunho (T-175-40). A conferência
  da revisão e do estado fica antes, sem trava: na janela de milissegundos vale o que o editor do Publicador já faz (a
  última escrita vence), e se a fila pegar o item no meio a `revisao` nova o tira como `precisa_revisar` (R5).
- **D46 só nas cores que o pedido TOCA** (interpretação do "cada cor" do contrato): o empate que já existia noutra cor —
  a própria Precificação pode dar Premium = Clássico numa cor — não pode impedir de consertar cor por cor. A prévia
  avisa (`preco.premium_igual`, que olha TODAS as cores ativas). **A 4d (publicar) precisa conferir todas.** O efetivo
  "depois" de cada cor: o gravado pedido; senão o gravado de antes; senão o da Precificação DAQUELA cor
  (`dados.portal[lt].anunciado`, a linha da oferta da cor ou a da âncora). Só com os dois tipos ligados. O espelho da
  tela usa o `preco.{tipo}.da_precificacao` (da 1ª cor) no `null`: com Precificação diferente por cor ele pode errar —
  o servidor decide.
- **O `minimo` é o da Central de Promoções** (`DadosEfetivosService::precosDePromocao`, ADR PORTAL-02), da MESMA linha
  da Precificação que dá o preço da cor (`dados.portal[lt].minimo`). O aviso é um por tipo, com o MAIOR mínimo que o
  valor não alcança (meio centavo de folga: no mínimo exato não avisa); `null` nunca avisa. Texto com o tipo, para a
  tela poder pôr no lugar: "Abaixo do mínimo da Precificação no Clássico (R$ 185,50)." (o contrato tinha só
  "Abaixo do mínimo da Precificação (R$ …).").
- **Tipo desligado no pedido é 422 mesmo com `null`** (a letra do contrato); a cor desativada no anúncio não é "cor
  dele" (422 `errors.cor`). Valor: `numeric|gt:0|decimal:0,2|max:9999999` (o teto do editor do Publicador); todas as
  regras com mensagem própria — o idioma do Laravel aqui é o inglês.
- **O `preco` da prévia cresceu de forma aditiva** (`minimo` por tipo, `por_cor`, `premium_igual`) — no GET também, a
  caixa editável é desenhada por ele. As asserções da 4a que comparavam o `preco` inteiro e a `escrita.revisao` (aqui e no
  `AcessoATrilhaTest`) foram ajustadas. O PUT devolve
  as chaves da prévia + `avisos` (o GET não tem `avisos`). Quando a prévia for ao cliente (4e), cortar também o `minimo`
  (o contrato só cita `da_precificacao`).
- **Testes:** o JSON tira o `.0` (170.0 sai `170`): comparação estrita com número redondo usa inteiro. `assertJsonPath`
  não alcança `errors.precos.premium` (§31) — helper `assertErro`. Na matriz, o "passa" do preço precisa do item na
  REVISÃO: o `rascunhoDoPub` sozinho deixa a Simples no Planejamento (o robô não "andou") — `ia_preparo.etapas` ok + os
  dois alvos + a variante única; e o corpo é montado DEPOIS disso (leva a `revisao`). O cenário de duas cores é a
  cadeira do `CenarioDoPreparo` com uma 2ª variação (`COLOR=id:52028` Azul, `COLOR=id:52049` Preto, um pub_produto só).
- **Custo** (SQLite em memória): a Precificação roda 3× por PUT (o efetivo de antes, o `montar` e o `precos()` da
  prévia nova); nenhuma chamada ao ML com o cache quente; nenhum Job (o `propagar` do estoque dos kits só grava quando o
  NÚMERO do estoque muda — preço não muda estoque).

### 4c — preço (tela), 04/11/2026
- **`linhasDoPreco` (`lib/trilhaPrevia.js`) filtra OBJETOS:** a 4c pôs bandeiras booleanas em `preco` (`por_cor`,
  `premium_igual`); filtrar só por "valor verdadeiro" as transformava em linhas de tipo fantasmas. Campo novo em
  `preco` que seja objeto vira linha.
- **`Revisao/` não importa `Cadastro/`:** o `PecasDoCadastro` puxava ~50 kB do cadastro para a gaveta da prévia, que
  vai ao cliente na 4e. Campo, aviso e confirmação foram redesenhados em `Revisao/CaixaDoPreco.jsx`; há teste que
  proíbe o import.
- **As libs `trilha*` não importam `trilhaPrevia`** (o teste de pastas as trata como "de fora"): `trilhaPreco.js`
  tem cópias do marcador `__ITEM__` e do formato do real, e um teste confere que são iguais.
- **SSR:** o React escreve `readonly=""` (não `readOnly`); quando dois pacotes do esbuild montam o mesmo componente,
  compare pelo `.name`, não pela referência.
- **Limitação aceita (04/11):** com uma cor escolhida, a referência "da Precificação" e o "mínimo" exibidos são os da
  1ª cor ativa (`preco.{tipo}`); com Precificação diferente por cor, a referência pode divergir. O valor gravado e a
  regra Premium = Clássico são decididos pelo servidor, cor a cor.
- **Pendências registradas para a frente:** 4d confere Premium = Clássico em TODAS as cores (a 4c só nas tocadas);
  4e corta `preco.*.minimo` e `da_precificacao` da prévia do cliente.

## 39. 4d — publicar (servidor) (04/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §19.13 (D46–D50, D59). Serviço `App\Services\Trilha\PublicacaoNaTrilha`, Job
`App\Jobs\Trilha\PublicarPelaTrilhaJob` (fila `high`, `tries 1`), controller `Trilha\PublicacaoDaTrilhaController`, rotas
`trilha.publicar` (`POST …/ofertas/{item}/publicar`, 10/min, contador `trilha.revisao.publicar`) e `trilha.publicacao`
(`GET …/publicacao`, 240/min, contador `trilha.revisao.publicacao`), `escrita.revisao.publicar`/`publicacao` com `__ITEM__`,
teste `PublicarNaTrilhaTest` (+ as duas rotas na matriz do `CadastroNaTrilhaAcessoTest`, que agora roda com a empresa 459).
Nada do Publicador mudou: `ConferenciaService::conferir`, `FilaPublicacaoService::agendar` e
`ProgramasPublicadorService::resolver` (MÉTODO de instância, não estático) são chamados como estão.

- **A trilha nunca publica.** Ela confere e AGENDA na fila de publicação da conta (`resolver('company-459')`); quem manda
  `POST /items` é o `AgendadorDaFila`, na passada dele (todo minuto, rodadas de `produtos_por_rodada` a cada
  `intervalo_minutos`, só na janela — o "na fila" pode durar), e ele confere de novo o plano e a revisão gravados no item.
  O teste prova com `Http::preventStrayRequests` só com o que a conferência pergunta: um `POST /items` estouraria.
  **Na 459 é publicação DE VERDADE**: `publicador.contas_liberadas.todas` está ligada em produção desde 26/10 — a
  `ContasLiberadas` não segura nada lá. A única trava é a D48.
- **D48 confere as âncoras CANDIDATAS, não só a de agora.** `PublicacaoNaTrilha::daLoja`: (1) a Company do endereço é a
  `LOJA` (constante, NUNCA `.env`); (2) `pub_produtos.company_id` é a 459; (3) a MlbEmpresa do anúncio (se houver) e a
  âncora com token (`contaOuNula()`, MlbEmpresa ANTES de Company) são da 459. Conferir só a de agora deixaria a janela do
  token que reconecta entre o agendar e a publicação. Âncora nula (sem token) passa a D48 — a conferência sai só local
  (`LOCAL`/`V-ACC-01`) e vira `recusado` "precisa ser reconectada", que é o texto certo (um 403 "não liberado para esta
  loja" mentiria). Roda no pedido, no `GET` (403 também) e DUAS vezes no Job: antes de conferir e de novo antes de agendar
  (a conferência leva segundos e chama o ML). Cada uma das três sozinha tem teste com dado cruzado de propósito (a do
  endereço só cai sozinha com um anúncio da 460 marcado `company_id` 459 — num cenário normal da 460 as três caem juntas
  e a mutação de uma não aparece). 13 mutações + 2 do contrato da tela, todas derrubam teste.
- **Sem os "padrões do editor" antes de conferir, de propósito.** O `ConferirRascunhoJob` e o lote aplicam
  `PadroesDoRascunhoService::aplicar(comFrete)` como rede de segurança — isso sobe a `revisao` e muda o payload DEPOIS
  de a pessoa aprovar a prévia. A trilha confere o que foi aprovado; o preparo do robô já aplicou os padrões (com frete).
  Se faltar algo, a conferência diz e o item volta `recusado`.
- **A revisão aprovada é conferida no Job.** O Job leva a `revisao` do pedido; se o rascunho mudou antes de ele rodar (o
  preço de outra aba, o robô), `recusado` "mudou" — sem conferir nem agendar a N+1 que ninguém viu.
- **Ordem do pedido:** 404 item → 403 D48 → 409 do estado (na fila → publicando → publicado → fora da revisão) → 422/409 da
  `revisao` (a regra da 4c) → 422 `ciente` inválido → 409 prévia não pronta (`message` = o `motivo` neutro da prévia) →
  422 D46. A D46 do publicar é o `preco.premium_igual` da prévia (o preço do PLANO de cada cor ativa, a mesma régua
  `PreviaNaTrilha::mesmoPreco` da 4c) — TODAS as cores, não só as tocadas; erro em `errors["precos.premium"]` e no
  `message`.
- **Dois cliques, uma conferência — sem `ShouldBeUnique`.** O pedido segura uma `Cache::lock` curta e, com `conferindo`
  recente no estado, devolve o MESMO objeto (202) sem Job novo. `ShouldBeUnique` também atrapalharia o teste: a trava
  única só é solta pelo worker, e com `Queue::fake` o 2º pedido (o [Publicar assim mesmo]) seria engolido. `conferindo`
  com mais de 30 min (`CONFERINDO_VALE_MIN`; o Job tem 5 de timeout, mas a `high` de produção tem UM worker) é Job que
  morreu: a situação diz `erro` e um pedido novo enfileira de novo. O `failed()` grava `erro`.
- **A situação** (`PublicacaoNaTrilha::situacao`, o MESMO cálculo no `GET` e no campo `publicacao` da prévia): (1) item
  VIVO na fila do anúncio vence tudo (inclusive o agendado pelo Publicador sem a trilha — é o mesmo motivo do 409, e a
  tela não oferece [Aprovar e publicar] à toa); (2) o estado do pedido (`trilha:publicar:{pub}`, 6 h); depois de
  `na_fila`, o item da fila em que ele entrou e a `pub_publicacoes` dele — a publicação terminada já vale `publicado`
  antes de o agendador fechar o item; (3) sem pedido, só o rascunho `PUBLISHING`; senão `null`. `mlbs` = lista de
  STRINGS (a tela só conta), Clássico antes do Premium, só os `CREATED`. `mensagem` nunca vazia (reserva por situação).
  Passadas as 6 h, um anúncio já publicado volta a `publicacao: null` (não é mais "pedido"; a linha diz o resto).
- **Textos neutros:** as mensagens da conferência e o `motivo` da fila citam "Mercado Livre", "Publicador", "editor".
  `PublicacaoNaTrilha::neutro` troca "Mercado Livre" → "canal de venda" (o artigo masculino de antes continua certo),
  "Publicador" → "sistema", "Portal" → "cadastro", "agente" → "robô". Mensagem nova no dicionário `publicador_erros` com
  outra marca passa crua.
- **Histórico:** o pedido grava `trilha_publicacao_pedida` (canal `portal`, ator da equipe, `item`, `revisao`, `ciente`) —
  é o único registro de QUEM aprovou (na fila, `criada_por` é o mesmo usuário e vira o ator das publicações).
- **D59 é só da trilha.** `SituacaoDasOfertas::publicados` lê `pub_publicacao_itens.criado_em` (gravado no 201): o
  `under_review` criado há menos de 24 h sai `"Em análise"` (anúncio) e a linha `"Em análise · {tipos}"` SEM alerta;
  ao lado de um à venda, `"No ar · …"` sem alerta; um parado de verdade ao lado segue `"Fora do ar"` com alerta. Sem
  `criado_em`, a regra antiga (o `TrilhaDaEmpresaTest` monta assim e continua valendo). Publicador e Polos não leem
  `SituacaoDasOfertas`. **Na tela:** `estaNoAr()` (`lib/trilha.js`) lê o prefixo "No ar" — a linha "Em análise · …" cai
  em "Conferir" na pílula da equipe, e o Mapa a pinta de âmbar (parado). A contagem por etapa não muda (etapa 6).
- **Os endereços saem em toda loja com escrita**, não só na 459 (a forma do contrato não muda por loja): fora dela o
  `POST` responde 403 com `MSG_LOJA`.
- **Testes:** o cenário do preparo de verdade com a empresa criada com `id` 459 (`Company::factory()->create(['id' => …])`;
  na matriz, `empresaDaTrilha(id: …)`). Para a conferência passar: uma foto POR COR já enviada (`ENVIADA` +
  `ml_picture_id`, senão V-IMG-05 e a conferência sobe foto), a garantia (V-SAL-05), o preço gravado, a modalidade de
  envio no cache e um `shipping_options/free` SEM `coverage` (o passo 9 do frete cota pela Precificação; sem cotação,
  não vira aviso). A conferência de verdade NÃO sobe a `revisao` (o [Publicar assim mesmo] usa a mesma). Na rede do
  Publicador, além do `FotosDoPainelServiceTest` (§26), o `ProximoARevisarServiceTest` também cai neste worktree pelo
  MESMO arquivo de design não versionado (`design_handoff_publicador/Aprovacao.dc.html`) — ambiente, não regressão.
- **Deploy:** Job novo na fila `high` — o `deploy.sh` só reinicia `ecf-worker:*`; o `ecf-worker-high` precisa de
  `php artisan queue:restart` (como www-data) para conhecer a classe (§28).

### 4d — publicar (tela), 04/11/2026
- **O servidor manda `mensagem` em toda situação;** nas que andam ela repete o título ("Na fila de publicação.",
  "Publicando agora…"). A tela esconde a mensagem quando ela começa pelo título (sem contar caixa e pontuação final).
  Mudou o texto no servidor (`PublicacaoNaTrilha::MSG_*`), confira aqui.
- **O estado da publicação na tela fica preso à prévia aberta:** chegou prévia nova ([Recarregar], preço salvo), vale
  o `publicacao` dela e o acompanhamento recomeça.
- **O primário [Aprovar e publicar] espelha `preco.premium_igual`** (botão desligado com dica) e também desliga com
  preço digitado e não salvo (publicaria o preço velho). Quem decide continua sendo o servidor (422).
- **[Publicar assim mesmo] é um clique só** (a decisão é o próprio botão). Acompanhamento a cada 3 s, com teto de
  30 min ([Acompanhar de novo]); para em 401/403/404/419; rede, 429 e 5xx tentam de novo na volta seguinte. 409 que
  não é de revisão: pergunta o estado uma vez — se outra aba já publicou, passa a acompanhar.
- **Publicado mostra só QUANTOS anúncios foram criados**, não os códigos (o cartão vai ao cliente na 4e).
- **Avisos, recusado e erro não travam o preço** (ajustar e tentar de novo é o caminho); só conferindo, na fila,
  publicando e publicado travam.
- `lib/trilhaPublicar.js` copia o marcador `__ITEM__` e a régua das mensagens do framework (as libs `trilha*` não
  importam a lib da prévia nem a do preço); um teste confere que o marcador é o mesmo.
- **Sem DOM na suíte:** o relógio de 3 s e o foco foram provados por funções puras, render no servidor e teste de
  fonte. Conferir no navegador real quando a 459 tiver um item para publicar (publicar cria anúncio DE VERDADE).
- **"Em análise" na Lista e no Mapa (04/11):** o servidor dá "Em análise · …" sem alerta nas primeiras 24 h (D59),
  mas `estaNoAr()` lê o prefixo "No ar" — a linha caía em "Conferir" (âmbar) e o Mapa pintava de âmbar.
  `estaEmAnalise()` + ação neutra "Em análise" na linha e `situacaoDoAnuncio` → 'analise' (cor da Revisão, com item
  na legenda). Mudou o texto do status no servidor (`SituacaoDasOfertas`), mude os prefixos aqui.
- **`FotosDoPainelServiceTest` falha neste worktree por ambiente** (`design_handoff_publicador/Aprovacao.dc.html` não
  versionado — §26); não é regressão da trilha.

## 40. 4b — ajuste ao robô (servidor) (04/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §19.14 (D51/D60). Serviço `App\Services\Trilha\AjusteDoRoboNaTrilha`, Job
`App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob` (fila da IA, `tries 1`, `timeout` 540), controller
`Trilha\AjusteDaTrilhaController`, rotas `trilha.ajuste` (`POST …/ofertas/{item}/ajuste`, 10/min, contador
`trilha.revisao.ajuste`) e `trilha.ajuste.estado` (`GET` no mesmo endereço, 240/min, contador `trilha.revisao.ajuste.estado`),
`escrita.revisao.ajuste` com `__ITEM__`, teste `AjusteDoRoboNaTrilhaTest` (+ as duas rotas na matriz do
`CadastroNaTrilhaAcessoTest`). Nada do Publicador mudou: `executarEtapa`, `gerarTitulos`, `gerarModelo` e `DescricaoIaService::gerar`
são chamados como estão; nenhum prompt mudou.

- **O `PreparoIaDoRascunhoService.php` é vigiado por portões** (Fases 188/193: `FotosDoPainelServiceTest` confere os leitores
  do `EditorEmUso` nele por método, e `tests/gates/contagens-188-03.txt` conta asserções — a âncora por NÚMERO DE LINHA já
  reprovou mudança legítima em 20/10). Esse teste falha neste worktree por ambiente (§26), então não haveria como provar que
  os portões seguem verdes: a 4b não tocou o arquivo e mora aqui uma CÓPIA de três regras privadas de lá — mudou lá, muda
  aqui: `livres()` (o "é do robô?" do título e da descrição, e do Modelo), `categoriaTemModelo()` e
  `consumirCota()`. As duas primeiras só economizam a chamada da IA: quem decide a escrita é o `executarEtapa`, sob a trava
  do rascunho (o teste da equipe que edita DURANTE a geração prova). A da cota usa a MESMA chave
  (`publicador:preparo:cota:{empresa}:{Y-m-d}`) e o MESMO teto: o ajuste gasta do orçamento do preparo, 1 por pedido que vai
  chamar a IA (parte toda "mantida" não gasta). O teste com o teto em 1 depois do preparo do `setUp` prova que o contador é
  um só (mutação: outra chave → a IA é chamada).
- **`executarEtapa` com `$valorPronto` exige o hash GUARDADO** (`step_state.ia_preparo.hash`); sem ele devolve `superado`
  sempre. Sem hash = o texto não é do robô: o kit da fase N (o Combo N, `produto_base_id` — `avaliarIa` dá `kit_da_fase`) e o
  rascunho que chegou à revisão sem preparo. Para eles o POST é 409 `MSG_SEM_ROBO` e a prévia diz
  `ajustes_restantes_hoje: 0` (a tela só oferece o botão com saldo). O ajuste usa o hash guardado, não recalcula
  `hashDosFatos` — o título e a descrição não entram no hash, então o ajuste não dispara preparo novo.
- **O título planejado nunca é refeito.** A régua do robô conta o título PLANEJADO (aba Anúncios, ou o título de venda do
  cadastro, que vira o planejado do Clássico) como preenchido. Na trilha o título do cadastro é escrito pela equipe (e o F8
  grava o título do robô no cadastro, na 1ª variação): nesses produtos o ajuste refaz só o Premium e diz "Título do Premium
  refeito; o do Clássico é da equipe e ficou." Com os dois tipos da equipe, `mantido` sem chamar a IA.
- **O `evitar` é o "não repita" do prompt E a garantia do servidor**: `gerarTitulos` passa os títulos atuais ao
  `titulosPorTermos` (8º argumento) e o `RegrasDoTitulo::semRepetir` os diferencia (troca as duas últimas palavras, ou põe
  um termo). O teste usa a IA falsa devolvendo os MESMOS títulos de antes — só o `evitar` faz sair diferente (mutação:
  `evitar` vazio → `igual`). `igual` (o robô chegou ao mesmo texto) sai como `erro` "nada mudou".
- **O Modelo vem com o título** ("título — com o Modelo —", §19.1): depois do título escrito ou adiado, `gerarModelo` com os
  títulos NOVOS e `executarEtapa(MODELO)`, só se o Modelo ainda é do robô e a categoria tem MODEL. Melhor esforço: não é
  parte do contrato e não muda o desfecho do título.
- **Adiado (editor em uso, na fila, "Anunciar por IA", agente parado):** o `executarEtapa` põe o valor gerado num
  `GerarPreparoIaJob` adiado do PUBLICADOR (até `max_adiamentos` × `adiar_min`, ~2 h) — a IA não é chamada de novo. A parte
  fica `fila` ("O robô já refez e grava assim que o anúncio estiver livre.") e o mesmo Job da trilha, em modo
  `acompanhar` (zero IA, a cada `adiar_min` + 1 min, até 30 vezes), lê o desfecho no `step_state`. **A etapa sai `ok` tanto
  quando grava quanto quando a equipe editou no meio** — o que separa `refeito` de `mantido` é a memória `ia_escrito`: mudou
  (sha1 de antes guardado no estado) E o campo está com ela. Hash dos fatos trocado no meio = `erro` "o anúncio mudou".
- **O limite de 3/dia conta o `activity_log`** (`trilha_ajuste_pedido`, subject = o rascunho, desde a meia-noite de São
  Paulo): durável (sobrevive a reinício do cache) e é o mesmo registro de QUEM pediu e quais partes. A corrida de dois
  cliques fecha com `Cache::lock` curta + o 409 "em andamento" relido dentro da trava. Conta o PEDIDO, inclusive o que
  terminou em erro ou mantido (§19.12: "3 pedidos"). ⚠️ `ACTIVITY_LOGGER_ENABLED=false` desliga o histórico e, com ele, o
  limite (a cota da loja segue valendo).
- **Ordem da guarda do POST:** 404 item → 409 na fila → publicando → publicado → fora da revisão → o "Aprovar e publicar"
  (4d) `conferindo` → texto não é do robô → ajuste em andamento → `revisao` (ausente = 422 `errors.revisao`; velha = 409
  `{revisao_atual, message}` sem `travado`) → prévia não pronta (409, o `motivo` neutro) → 422 `errors.refazer` (vazio,
  fora da forma, parte desconhecida) → 422 limite. E a 4d ganhou o espelho: publicar com ajuste andando é 409
  (`MSG_PUBLICAR_COM_AJUSTE`) — sem isso a conferência validaria o texto velho e a escrita do robô viria depois.
- **Nenhum texto livre:** o controller passa `$request->all()` (como 4c/4d) e o SERVIÇO lê só `revisao` e `refazer` (lista
  fechada). O teste manda chaves a mais com uma marca e confere que ela não aparece no Job serializado, no estado, no
  `activity_log` nem em NENHUM argumento de NENHUMA chamada à IA falsa (mutação: a marca no estado → `evitar` → a asserção
  do estado pega; sem ela e sem a do Job, a da IA pega sozinha).
- **D60 é uma troca DEPOIS da decisão de sempre**, em `SituacaoDasOfertas::doItem`: com o ajuste em `fila`/`fazendo` (lido
  em lote, um `Cache::many` para todos os anúncios com rascunho — `AjusteDoRoboNaTrilha::refazendoEmLote`), o item que a régua
  poria no robô ou na revisão vira `revisao` "Robô refazendo · título, descrição" (as partes que ainda andam), sem alerta.
  Sem a troca, a etapa adiada (não está em `TEXTO_PRONTO`) com o resumo vencido pela revisão nova devolveria o item a
  "Preparando" (etapa 4). Publicando/publicado/no ar: a régua de sempre. O prefixo é `SituacaoDasOfertas::REFAZENDO`.
- **Vencido:** `fila`/`fazendo` sem atualização há mais de 180 min (`ANDAMENTO_VALE_MIN`, a mesma régua do "agente parou")
  é Job que morreu: o GET e a prévia dizem `erro` "O robô não terminou", a linha sai do D60 e um pedido novo passa. O
  acompanhamento do adiado atualiza o estado a cada passada, então espera longa de editor não vence.
- **Testes:** o Job roda pelo `handle` (fila de mentira); a IA falsa é um mock que ANOTA método e argumentos
  (`andReturnUsing`), trocado DEPOIS do preparo do `setUp`. A empresa é a 459 (o teste "publicar espera o ajuste" passa
  pela D48). 12 mutações (limite, histórico, mantido, `executarEtapa`, texto livre ×2, fila, cota, D60, `evitar`, desfecho
  do adiado, Job repetido) — todas derrubam teste. O Job só refaz com o estado em `fila`: o mesmo pedido entregue duas
  vezes pela fila (`retry_after`) não paga a IA de novo.
- **Deploy:** Job novo na fila `publicador-ia` — o `deploy.sh` só reinicia `ecf-worker:*`; o `ecf-worker-ia` precisa de
  `php artisan queue:restart` (como www-data) para conhecer a classe (§28).

### 4b — ajuste ao robô (tela), 04/11/2026
- **Ordem dos hooks:** o hook do ajuste precisa da publicação que vale agora, e o publicar precisa saber se o ajuste
  anda. Resolvido na vista (`comTravaDaPublicacao`/`comTravaDoPreco` sobre os controles); o hook da 4d não muda.
- **O ajuste da gaveta NÃO zera com prévia nova** (ao contrário da 4d): ele é marcado com a prévia aberta e a prévia
  que chega depois vence (`ajusteEfetivo`) — assim o resultado visto sobrevive à recarga do fim.
- **Os testes de fonte da 4c/4d travam linhas de `PreviaDoAnuncio.jsx`** (`if (publicar) …` seguida de
  `if (preco?.pedido) …`, e o fim `publicar={publicar} preco={preco} />;`). O rodapé do ajuste entrou como `else if`
  depois delas — quem mexer ali, confira esses testes.
- **`ajustes_restantes_hoje` é 0 também quando o texto não é do robô** (Kit da fase N, rascunho sem preparo): a tela
  esconde o botão quando o `preparo` da prévia não tem título nem descrição do robô ("limite atingido" seria mentira).
- Acompanhamento a cada 3 s com teto de 30 min (`MAX_CONSULTAS`), como a 4d — o adiado (editor do Publicador aberto)
  pode durar ~2 h no servidor.
- **Git Bash:** `grep -c $'\r'` dentro de laço contou todas as linhas de arquivos em LF — conferir CRLF pelos bytes.

## 41. 4f — fotos do cliente (servidor) (04/11/2026): o que não está escrito no código

Desenho: `ENTREGA-3-DESENHO.md` §19.15 (D57/D58, R9). Serviço `App\Services\Trilha\FotosDoClienteNaTrilha`, controller
`Trilha\Cliente\FotosDoClienteController`, rotas `portal.trilha.fotos.{quadro,ver,enviar,excluir}` (grupo `portal.auth`
de `routes/trilha.php`, contadores `trilha.cliente.fotos*`), guarda `CadastroNaTrilha::empresaDoCliente` (+ `clienteEscreve`
e `produto`), `cliente_escrita.fotos` em `ApresentacaoDaTrilha::paraCliente`, teste `FotosDoClienteNaTrilhaTest`. Nenhuma
migration; o `enviarFotos`/`excluirFoto` da equipe são chamados como estão, com `AtorDoPortal::cliente`.

- **A 1ª escrita do cliente precisou de cinco lugares fora do controller (R9):** a rota no `portal.auth`; uma linha POR
  ROTA em `RestringeDominioDoPortal::PERMITIDO_COM_ID` (sem ela, 404 só no domínio do cliente — no localhost passa); a
  regex do 413 no `bootstrap/app.php`; o CSRF (já valia: `portal/*` não está nas exceções — o teste o liga com
  `runningUnitTests()` falso); e a varredura do `AcessoATrilhaTest` (`PROPS_DA_PAGINA` ganhou `cliente_escrita`, e a
  conferência "nenhum `/portal` nas props" passou a aceitar só `/portal/{id}/trilha/…` — os endereços da PRÓPRIA trilha
  sob o prefixo da porta do cliente, não um link do Portal). E um sexto, de teste: o
  `PortalCliente/Estrutura/Produtos/AcessoAosProdutosTest` compara o `PERMITIDO_COM_ID` INTEIRO — linha nova na allowlist
  (a 4e vai pôr as dela) cai lá; acrescente-a ao `assertSame` com a data.
- **Os nomes `portal.trilha.fotos.*` NÃO começam com `portal.auth.`** (são os do desenho). O `DominioLiberaTodoModuloTest`
  (que varre o router atrás de rota do Portal sem linha na allowlist) filtra pelo NOME `portal.auth.*` e não as vê; o
  antigo "a porta do cliente não tem rota de escrita" do `OndasDaTrilhaTest` também filtrava pelo nome e seguiria verde
  com as escritas novas. Os dois testes da trilha agora varrem pelo ENDEREÇO (`portal/{portalEmpresa}/trilha…`): 5 rotas,
  2 escritas. Rota nova na porta do cliente derruba os dois de propósito — é decisão, não acidente.
- **"O cliente de verdade":** no modo equipe do Portal as 4 rotas são 404 e `cliente_escrita` vem nulo (não há
  `PortalUsuario` para ser o ator; a equipe tem a gaveta dela). O Portal mantém as duas sessões exclusivas (a entrada da
  equipe derruba o guard `portal`; o login do cliente esquece a `SESSAO_EQUIPE`), mas `PortalContexto::usuario()` lê o
  guard `portal` mesmo numa sessão de equipe: se as duas coexistissem, `AtorDoPortal::cliente(usuario)` gravaria EM NOME
  DO CLIENTE o que a equipe fez. Por isso o controller confere `ator()->equipe` além de `usuario() !== null` — o teste
  monta a sessão com as duas chaves (sem ele, a mutação que tira a conferência passava).
- **Guards vivos entre pedidos do MESMO teste:** `entrarNoPortal` com OUTRO `PortalUsuario` não troca quem está logado — o
  guard `portal` devolve o do pedido anterior (em produção cada pedido é um processo novo). Sem `forgetGuards()`, "o
  cliente da outra loja grava aqui" respondeu 200: um falso alarme de IDOR. Helper `outroCliente()` no teste.
- **"Na Lista" = `TrilhaDaEmpresa::faltasDoProduto() !== []` (com o ME1 como aviso) E sem a trava do cadastro.** A última
  foto que completa o produto o tira da Lista NA HORA: a resposta já vem `editavel: false` + o aviso `AVISO_SEGUIU`, e o
  `prepararSeCabe` o leva ao robô (uma vez, como na equipe). Daí em diante o cliente não exclui — não "descompleta" o
  produto que o robô pode já estar preparando. O 409 é `{message}` SEM `travado`: a chave mexe na gaveta da EQUIPE.
- **Ordem da guarda diferente da porta da equipe:** aqui 404 → 409 → 422 (o envio vazio no produto que andou é 409); na
  equipe a forma (422) vem antes da trava. O 413 do `post_max_size` não respeita ordem nenhuma (o `ValidatePostSize`
  roda antes da rota): vira 422 até no produto de outra loja — aceito, como na equipe.
- **`AVISO_JA_EM_PREPARO`:** o produto que voltou à Lista já estando no Publicador (a equipe tirou uma foto) recebe a
  foto nova só no cadastro — o Sincronizar só copia foto para galeria VAZIA do rascunho (R8). O cliente é avisado para
  falar com a equipe; nada automático avisa a equipe (não feito).
- **`CadastroNaTrilha::donasDaCor` virou pública:** o quadro escolhe a variação de cada cor (com cor E tamanho) pela MESMA
  régua da gaveta da equipe; o teste compara as donas das duas portas lado a lado (a mutação "dona = 1ª variação da cor"
  cai). A ordem das caixas é a da 1ª aparição da cor, não a da dona. `rotulo` nulo no produto que não varia.
- **422 sempre em JSON, com `message` = o 1º erro.** O `ValidationException` resume com "(and N more errors)" em INGLÊS —
  texto que o cliente leria. O controller monta a resposta (também sem `Accept` de JSON: nunca o redirect da sessão);
  `errors` no formato de sempre (`imagens`, `imagens.N`).
- **Mutante equivalente (registrado):** tirar o `company_id` da busca do produto no QUADRO não muda a resposta — o
  `faltasDoProduto` também busca pela empresa e dá 404. Defesa em camadas; o teste de "outra empresa" prova a resposta.
  14 mutações, 13 derrubam teste, esta é a equivalente.
- `limites.tipos` junta `jpeg` em `jpg` (a config tem os dois). O throttle do cliente conta por IP (o guard padrão `web`
  não tem usuário nele). Por POST, a trava (itens da empresa inteira) e as faltas rodam duas vezes (antes e depois do
  envio) — consultas fixas.

### 4f — fotos do cliente (tela), 04/11/2026
- **No teste com esbuild, o `import()` do `lazy` só se resolve depois de um giro do laço de eventos:** dois renders
  seguidos no mesmo teste mostram os dois o fallback; espere um `setTimeout(0)` entre eles.
- **`Cliente/` é a 3ª pasta que exige o 2º caminho** em `CadastroSobDemanda.jsx` (`PEDACOS_DO_CLIENTE`). O `lazy` mora
  no `PainelLateral`, então no manifest ele aparece nos `dynamicImports` de `_PainelLateral-…`, não nos da página.
- **A confirmação da exclusão sobe ao rodapé** como um elemento React guardado no estado do `PainelDoItem`
  (`rodapeDasFotos`).
- **A caixa aparece em todo `p:` do cliente com `cliente_escrita.fotos`;** fora da Lista fica só leitura com o
  `motivo` do servidor. A resposta do POST pode já vir `editavel: false` (a foto completou o produto e ele seguiu) —
  a caixa vira só leitura na hora e mostra os `avisos` em tom neutro. `variacao_id` de cada cor é a "dona" da cor
  (nem sempre a 1ª variação): o envio vai exatamente para ela. `cliente_escrita` vem `null` (não ausente) na porta
  da equipe e no modo equipe.
- **Pendência da 4e (D59):** o "Onde está" no painel do produto do cliente ainda mostra "Revisão e publicação ·
  Publicador" (`ETAPAS` em `lib/trilha.js`).
- Mutação em `trilhaEscrita.js`: há duas linhas `append('imagens[]')` (equipe e cliente) — trocar só a 1ª acerta a da
  equipe.
