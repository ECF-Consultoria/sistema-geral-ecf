# Trilha de produtos — o que não é dedutível do código

Escrito em 30/10/2026, na 1ª entrega (só leitura). Leia antes de mexer em
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
  Dev: liberar o módulo no Controle Dev (continua sem item de menu).
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
