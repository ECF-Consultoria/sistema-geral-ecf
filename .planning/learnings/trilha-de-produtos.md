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
