<?php

use App\Http\Middleware\McpHabilitado;
use App\Mcp\Servers\EcfAdminServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP do ECF Admin — servidor remoto, SÓ LEITURA
|--------------------------------------------------------------------------
|
| O Claude (claude.ai, celular, tarefas agendadas) consulta o sistema por
| aqui sem passar pelo navegador. Cada ferramenta lê o mesmo dado da tela de
| origem, com o recorte do perfil de quem conectou.
|
| Login: OAuth 2.1 pelo Passport. `oauthRoutes()` publica a descoberta
| (`/.well-known/oauth-*`) e o registro automático de cliente
| (`/oauth/register`); o conector abre `/oauth/authorize`, que passa pela tela
| de login normal do Admin. Domínios de retorno permitidos: config/mcp.php.
|
| Este arquivo é carregado pelo McpServiceProvider FORA do grupo `web`: sem
| sessão e sem CSRF — quem autentica é o token (`auth:api`).
*/

Mcp::oauthRoutes();

Mcp::web('/mcp', EcfAdminServer::class)
    ->middleware([McpHabilitado::class, 'auth:api', 'throttle:mcp'])
    ->name('mcp.ecf');
