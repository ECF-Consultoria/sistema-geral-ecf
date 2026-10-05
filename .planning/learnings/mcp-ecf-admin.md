# MCP do ECF Admin (`/mcp`) — o que não está no código

Servidor MCP remoto, **só leitura**, para o Claude (claude.ai, celular, tarefas
agendadas) consultar o Admin sem navegador. Pedido do Erlon em 05/10/2026
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

## 8. Operação

- Log de acesso: tabela `mcp_acessos` (usuário, ferramenta, argumentos,
  sucesso/erro, duração, IP, cliente OAuth).
- Limite: 60 chamadas/min por usuário (`RateLimiter::for('mcp')`).
- Desligar sem deploy: `ECF_MCP_HABILITADO=false` no `.env` + `config:cache`.
- Revogar alguém: `php artisan mcp:revogar <email|id>`.
- Validade: token de acesso 1 dia, refresh 30 dias (sem isso o Passport emite
  token de 1 ano).
