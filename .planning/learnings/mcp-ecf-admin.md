# MCP do ECF Admin (`/mcp`) — o que não está no código

Servidor MCP remoto para o Claude (claude.ai, celular, tarefas agendadas)
consultar — e, desde 06/10/2026, preencher (§10) — o Admin sem navegador. Pedido do Erlon em 05/10/2026
("Especificação — MCP do ECF Admin"). Primeira entrega: 7 ferramentas
(`listar_empresas`, `sugadores`, `demandas_dev`, `onboarding_polos`, `ppa`,
`alertas_estrategicos`, `painel_executivo`). Código em `app/Mcp/`, rotas em
`routes/ai.php`, testes em `tests/Feature/Mcp/`.

## 1. Por que OAuth (Passport) e não token Bearer

A especificação sugeria token Bearer na fase 1. **No claude.ai isso não funciona
para todo mundo:** o campo "Request headers" do conector personalizado é beta e
liberado só para algumas organizações (doc oficial "Add a connector that isn't
in the directory", out/2026). O único caminho garantido no claude.ai web,
celular e tarefas agendadas é OAuth. Por isso Passport + `Mcp::oauthRoutes()`
(registro automático de cliente, DCR) desde o início.

## 2. Instalação: duas travas do Composer

- **`ext-sodium`**: o Passport 13 puxa `lcobucci/jwt`, que exige a extensão
  sodium. O PHP do XAMPP local vem com `php_sodium.dll` mas **desligada** — rode
  composer, artisan e phpunit com `php -d extension=sodium ...`. A VPS precisa
  ter a extensão (o `composer install` do deploy recusa sem ela).
- **Passport travado em `^13.7`**: o 13.8 exige `phpseclib/phpseclib ^4`, e o
  lock está no 3.x porque o `league/flysystem-sftp-v3` (SFTP dos grants do ML)
  depende dele. Atualizar o phpseclib para caber o Passport 13.8 mexe no SFTP —
  não faça sem testar o `SyncGrantsFromSftp`.

## 3. Sem chave RSA, o guard `api` dá 500

`auth:api` (driver passport) estoura `LogicException: Invalid key supplied`
**antes** de olhar o token, se `storage/oauth-private.key` /
`oauth-public.key` não existirem. Na VPS: `php artisan passport:keys` **uma
vez**, dono `www-data`, permissão 600. As chaves são gitignored
(`/storage/*.key`) e o `reset --hard` do deploy não as apaga. Trocar as chaves
invalida todos os tokens emitidos (todo mundo reconecta).

Nos testes, `ChamaMcp::prepararChavesDoPassport()` gera um par em memória
(phpseclib) e põe em `config('passport.*_key')` — não dependa do arquivo em
`storage/`, que não existe na máquina de outro dev.

## 4. Armadilhas do pacote `laravel/mcp` (v1.0.1)

- **Atributo não herda.** `annotations()` lê `#[IsReadOnly]` só da classe
  concreta. A base `FerramentaEcf` sobrescreve `annotations()` para todas
  declararem só leitura.
- **Ferramenta não pode ter dependência no construtor.** O
  `TestListResponse::assertRegistered()` do pacote faz `new $classe` sem
  container. Resolva serviço com `app(...)` dentro do método.
- **`tools/list` já filtra por `shouldRegister()`** e `tools/call` só acha as
  ferramentas filtradas: ferramenta fora do perfil responde JSON-RPC `error`
  ("Tool [x] not found") com **HTTP 400**, não 403.
- **A tela de autorização publicada (`vendor:publish --tag=mcp-views`) usa
  `@vite(['resources/css/app.css'])`**, que não é entrada do nosso Vite (só
  `app.jsx`). Renderizaria 500. A nossa (`resources/views/mcp/authorize.blade.php`)
  é autocontida.
- **`config/mcp.php` vem com `redirect_domains = ['*']`**: qualquer site
  registraria cliente OAuth e pediria o login da equipe. Restrito a claude.ai,
  claude.com e localhost (Inspector / Claude Code).
- O Passport liga o fluxo "device code" por padrão. Desligado em
  `AppServiceProvider::register()` — no `boot()` seria tarde, as rotas do
  Passport já teriam sido registradas.

## 5. "Mesmo número da tela" — como foi garantido

Cada ferramenta usa a MESMA consulta da tela, não uma cópia:

- `listar_empresas` → `EmpresasVisiveisService` (saiu de dentro do
  `CompanyController::index()`; a tela e o MCP chamam o mesmo método).
- `onboarding_polos` → `AcessoImplementacaoPolos` (saiu do `checkAccess()`
  privado do `MlbImplementacaoController`).
- `demandas_dev` → `DemandasDevService` inteiro; `ppa` → `PpaListaService`;
  `alertas`/`painel_executivo` → mesmas chamadas do `EcfDriveService`, caindo no
  mesmo cache da tela (sem `tim_month_id` quando a tela não manda).

Os testes conferem contra a tela de verdade (props do Inertia), por perfil.
**Se você mexer na régua de uma dessas telas, o teste do MCP correspondente
quebra — é de propósito.**

## 6. Os totais de empresa divergem entre telas, e isso não é bug do MCP

A especificação cita Dashboard 160 × Painel Executivo ~172 × Cadastro 133. São
universos diferentes: o Cadastro (`/companies`) é Performance sem MlbEmpresa e
só em operação; o Painel Executivo é a carteira inteira do ECF Drive, com
Polos. O MCP repete cada tela e as descrições das ferramentas avisam o Claude
para não comparar os totais. Unificar a definição é decisão de produto, não do
MCP.

## 7. Deploy de pacote novo: o `deploy.sh` derruba o site por ~30 s

O `deploy.sh` faz `git reset --hard` → `npx vite build` → **só então**
`composer install`. Quando o commit traz um pacote novo que o código de boot
usa (aqui: `User implements OAuthenticatable` e `Passport::` no
`AppServiceProvider`), o código novo roda com o vendor ANTIGO até o composer
terminar. No deploy do MCP (05/10/2026, `2c889100`) foram **24 respostas 500
entre 16:35:50 e 16:36:23** (`Class "Laravel\Passport\Passport" not found`),
medidas no nginx e no `laravel.log`. Nenhum outro erro.

Para o próximo pacote novo de boot: rodar `composer install` na VPS com o
`composer.lock` novo ANTES do `deploy.sh` (o vendor aceita pacote a mais sem
quebrar o código antigo), ou aceitar a janela e avisar antes. Atenção: o
nginx loga em UTC e o Laravel em horário de Brasília (19:35 no nginx = 16:35
no laravel.log).

Na subida também ficou um cliente OAuth "personal access" chamado **"Teste de
deploy do MCP (05/10/2026)"** em `oauth_clients` — foi usado para o teste de
fumaça com token real (revogado em seguida com `mcp:revogar 24`). Pode ficar;
não abre rota nenhuma.

## 8. `ler_tela`: o MCP abre QUALQUER tela — e as decisões que vieram junto

Pedido do usuário em 05/10/2026: "tudo que tiver no sistema tem que ter no MCP".
São ~190 telas de leitura; uma ferramenta por tela levaria meses. A saída foi
uma ferramenta genérica (`app/Mcp/Telas/`):

- **`NavegadorDeTelas` faz uma navegação INTERNA** pelo kernel HTTP com os
  cabeçalhos do Inertia (`X-Inertia`, versão do manifest). Mesmos middlewares,
  mesmo controller, mesmas travas — o número é o da tela por construção.
  Três cuidados no código: sessão trocada para `array` durante a navegação
  (senão cada consulta criaria linha em `sessions`), usuário do token posto no
  guard `web`, e o `request` do container restaurado no fim (senão o log de
  acesso gravaria o IP/rota da tela).
- **Prop opcional/lazy** (`Inertia::optional`) só vem por recarregamento
  parcial. O Inertia devolve `null` para chave pedida que a tela não tem —
  tratar como "não existe", não como "vazio".
- **`CatalogoDeTelas`**: GET com nome, atrás de `auth`, menos padrões de
  arquivo/OAuth/portal e a lista `BLOQUEADAS` (GET que grava). **Rota GET nova
  que grava tem que entrar em `BLOQUEADAS`** — o certo é ela virar POST. O teste
  `test_toda_rota_bloqueada_existe_de_verdade` pega renomeação.
- **Decisões do usuário (05/10/2026), não refaça sem perguntar:**
  - ~~só leitura continua valendo~~ — revogado em 06/10/2026, ver §10;
  - abrir pelo MCP pode disparar o mesmo aquecimento de cache que a tela
    dispara no navegador (Desempenho "calculando…", RefreshGrossBillingCache) —
    o dashboard do consultor/mentor chega a calcular a nota na hora com cache
    frio (`PerformanceController:492`), igual ao navegador;
  - **dado pessoal (e-mail, telefone) e links/tokens de acesso vêm como a tela
    mostra** — revoga a linha "não devolver CPF/telefone/e-mail" da
    especificação. Só CREDENCIAL (senha, `access_token`, `refresh_token`,
    `api_key`, `*_secret`) sai como "[oculto]" (`LeitorDeDados::CREDENCIAL`).
- Prop compartilhado novo no `HandleInertiaRequests` precisa entrar em
  `LeitorDeDados::COMPARTILHADOS` (há teste que avisa).
- **Varredura das 197 rotas GET autenticadas (05/10/2026):** 111 só leem, 42 só
  aquecem cache, 6 devolvem arquivo e **37 gravam ao serem abertas** — não dá
  para confiar no nome. As que gravam dado de negócio ou usam token OAuth
  guardado estão em `BLOQUEADAS`, com o motivo ao lado. Destaques que
  surpreendem: `admin.contratos.show` e `comercial.entrada.show` sincronizam
  etapa/checklist a cada abertura (criam `CompanyEtapaTransicao`);
  `chamados.show` marca notificação como lida; os status de criativo/IA
  encerram job travado; agenda e disponibilidade usam o token Google de OUTRA
  pessoa (e o refresh apaga o token em `invalid_grant`).
- O aquecimento que dashboard/performance/portfolio disparam
  (`desempenho:warm-cache`) não grava só cache: faz upsert em
  `desempenho_company_score_snapshots` de competência NÃO congelada — o mesmo
  que o cron das 7h–22h faz a cada 8 min. Aceito como "igual à tela".

## 9. Operação

- Log de acesso: tabela `mcp_acessos` (usuário, ferramenta, argumentos,
  sucesso/erro, duração, IP, cliente OAuth).
- Limite: 60 chamadas/min por usuário (`RateLimiter::for('mcp')`).
- Desligar sem deploy: `ECF_MCP_HABILITADO=false` no `.env` + `config:cache`.
  Só a gravação: `ECF_MCP_ESCRITA_HABILITADA=false` (a leitura fica no ar).
- Revogar alguém: `php artisan mcp:revogar <email|id>`. **Revoga TODOS os
  tokens da pessoa**, inclusive o do conector do claude.ai dela — em 06/10 o
  teste de fumaça revogou assim o próprio token de teste e, junto, a conexão
  real do Maycon, que teve de reconectar. Para limpar só um token de teste,
  revogue pelo `id` em `oauth_access_tokens` (e o refresh dele), não pelo
  comando.
- Teste de fumaça em produção (06/10/2026, admin): 147 telas no catálogo; das
  96 sem parâmetro, 90 abriram e as 6 restantes são recusas corretas (filtro
  obrigatório com o campo indicado, ou perfil de líder). Nenhuma passou de 8 s.
- Validade: token de acesso 1 dia, refresh 30 dias (sem isso o Passport emite
  token de 1 ano).

## 10. Gravação pelo MCP (06/10/2026)

Pedido do usuário: "para quem tiver conectado poder alterar e preencher coisas
— ex.: meu gestor vai usar para colocar tickets para mim". Escolhas dele (não
refaça sem perguntar): **tudo** que a tela grava, **grava direto** (sem passo de
confirmação no servidor) e "pode editar o que quiser".

- **O token não precisa ser refeito.** O OAuth tem um escopo só (`mcp:use`),
  sem separar leitura de escrita; criar um escopo de escrita separado
  invalidaria todos os tokens — por isso não foi feito. MAS a ferramenta nova
  não aparece "na próxima conversa": o claude.ai guarda a lista de
  ferramentas em cache — ver §11.
- **Toda gravação passa pelo formulário da tela**, pela mesma navegação
  interna do `ler_tela` (`app/Mcp/Telas/NavegacaoInterna.php`), agora com
  POST/PUT/PATCH/DELETE: validação, permissão, aviso, log de atividade e
  trava anti-duplo-clique são os do controller. As ferramentas próprias
  (`abrir_ticket`, `atuar_no_ticket`, `salvar_demanda`,
  `registrar_atualizacao_demanda`) só traduzem a conversa em campos;
  `enviar_formulario` manda qualquer outro. Não duplique regra de negócio
  numa ferramenta — mude o controller.
- **CSRF sem exceção.** Nos testes o Laravel pula o CSRF (`runningUnitTests`),
  então nenhum teste comum pegaria um 419 em produção. A navegação põe o token
  na loja `array` do SessionManager ANTES do kernel — é o mesmo objeto que o
  `StartSession` usa (o manager guarda o driver criado) e o `start()` só gera
  token quando falta — e manda o token no `X-CSRF-TOKEN`.
  `EnviarFormularioToolTest::test_csrf_de_verdade...` liga o CSRF de verdade
  (bind de uma subclasse de `ValidateCsrfToken`) e prova as duas coisas: POST
  sem token dá 419, e pelo MCP passa. Se o Laravel mudar esse comportamento da
  sessão, é esse teste que quebra.
- **Pedido como JSON.** O formulário vai com `Accept: application/json` para a
  validação responder 422 com os campos (sem isso, ela redireciona e o erro se
  perde). O resultado normal de formulário Inertia é redirect + flash: o
  executor lê a sessão em memória depois — `error` em flash e `withErrors`
  viram ERRO no MCP, não "feito".
- **Cache do `ler_tela`.** Gravar troca a versão do cache de telas DAQUELE
  usuário (a chave leva `mcp.tela.versao.{id}`). Telas em cache de outros
  usuários seguem até 2 minutos.
- **Fora do MCP mesmo com permissão na tela** (`CatalogoDeAcoes::BLOQUEADAS`):
  sair/senha/perfil/verificação; conexões Google, Mercado Livre e Shopee; e o
  módulo `mlb.anuncios.*` INTEIRO — ele mistura rascunho local com escrita na
  API do ML em conta de cliente (publicar, editar, foto, alavanca), o que é
  proibido fora da conta de teste (regra do usuário de 01/10/2026). Liberar
  partes dele exige separar, rota a rota, o que fala com o ML.
- **Exclusão** (DELETE) pelo `enviar_formulario` exige `confirmo_exclusao`:
  única trava a mais que a tela, porque lá o botão de excluir pede
  confirmação. A ferramenta se declara `destructiveHint`.
- **Campos do formulário genérico**: não há catálogo de campos no sistema;
  `listar_acoes {acao}` devolve o trecho de código da validação (FormRequest,
  `->validate([...])` ou o auxiliar `$this->validarX()`), lido por reflexão
  (`RegrasDaAcao`). Lista de valores que vem de constante aparece pelo nome —
  os valores a tela mostra.
- **Quem gravou**: o `mcp_acessos` guarda os argumentos de toda chamada, e o
  log de atividade do modelo registra o usuário do token. Conta compartilhada
  (o Erlon usa a Admin #1) aparece como "Admin" nos dois.
- A tela de autorização do OAuth dizia "o acesso é só de leitura" — foi
  corrigida. Quem autorizou antes leu o texto antigo.

## 11. Tickets pelo MCP: o que parecia recorte errado (06/10/2026)

Relato: pelo `ler_tela`, o dev via em `chamados.index` só o ticket que ele
mesmo abriu, e o detalhe dava "redirecionou para fora do que o MCP pode
abrir". Não era autenticação (o MCP põe o usuário no guard `web`; "ser da
equipe" é `is_dev`/`role` na tabela, sem guard — e nenhuma tela interna decide
papel por guard ou sessão, só o Portal do Cliente, que fica fora).

- **`/tickets` é a tela de QUEM PEDIU**: lista só `solicitante_id = usuário`,
  para todo mundo, dev inclusive, no navegador também. A caixa da equipe é a
  aba Tickets de `/dev/demandas` (prop `chamados` de `dev.demandas.index`;
  detalhe no prop `chamado_detalhe` com `?ticket=ID`). Quem diz "no navegador
  eu vejo" está olhando essa aba.
- **`/tickets/{id}` fica bloqueado no `ler_tela`** (abrir marca os avisos do
  ticket como lidos). Por isso existe `ler_ticket`: lista (equipe = caixa da
  equipe + os que abriu; demais = os que abriram) e detalhe pelo
  `ChamadoService` (`podeVer` / `detalhe`), sem efeito colateral, com os
  prints como imagem (reduzidos a 1568 px em JPEG quando grandes). Nota
  interna e anexo de nota interna só para quem atua como equipe.
- **Rota sem nome vira `generated::<aleatório>` com `route:cache`** — só em
  produção; nos testes ela não tem nome. Os redirecionamentos antigos
  `/chamados` e `/chamados/{chamado}` entraram assim no `listar_telas`. Os
  dois catálogos agora descartam `generated::` (`CatalogoDeTelas::nomeGerado`).
  Teste que confia em "rota sem nome não entra" não pega isso: registre a rota
  com o nome `generated::...` explícito.
- **`tools/list` pagina de 15 em 15** no `laravel/mcp` (`defaultPaginationLength`).
  A 16ª ferramenta iria para uma 2ª página que nem todo cliente busca e
  sumiria da conversa sem erro nenhum. `EcfAdminServer` usa página de 50; o
  `ServidorMcpTest` confere que não há `nextCursor`.
- **O claude.ai guarda a lista de ferramentas em cache.** Medido no nginx
  (06/10/2026): `tools/list` (resposta de ~28 KB) às 11:47, 11:55 e 14:40 de
  Brasília; o deploy do `ler_ticket` foi ~15:00; a conversa das 15:20 só fez
  `initialize` (~3,3 KB) + `tools/call` e usou a lista das 14:40, sem o
  `ler_ticket` — o modelo leu `chamados.index` e disse "só 1 ticket". Não dá
  para saber o prazo do cache. Para ver ferramenta nova na hora: desconectar e
  reconectar o conector no claude.ai. Defesa do servidor que não depende disso:
  `AvisosDeTela` põe um "aviso" na RESPOSTA do `ler_tela`/`listar_telas`
  quando a tela engana pelo nome (hoje: `chamados.index`) — a resposta chega
  ao modelo mesmo com a lista velha. Medir pelo nginx: `grep '"POST /mcp'` e
  olhar o tamanho da resposta (`tools/list` é a grande).
- **`->orWhere()` encadeado num query builder SEM filtro vira o único filtro.**
  `ChamadoService::daEquipe()` não filtra nada para admin; o `ler_ticket`
  fazia `daEquipe($u)->orWhere('solicitante_id', $u->id)` e, para admin (o
  Maycon é admin + dev em produção), a lista caía para "só os que ele abriu".
  Os testes usavam dev com papel de consultor, que tem filtro, e passavam.
  Sempre agrupe: `where(fn ($q) => $q->whereIn(...)->orWhere(...))`, e teste o
  perfil admin quando a regra tiver ramo "admin vê tudo".
