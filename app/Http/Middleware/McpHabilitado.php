<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chave geral do MCP do ECF Admin (`/mcp`).
 *
 * Desligar é `ECF_MCP_HABILITADO=false` no .env + `config:cache` — sem
 * deploy. É um middleware, e não um `if` em `routes/ai.php`, porque a rota vai
 * para o `route:cache` do deploy: um `if` na definição só seria relido no
 * próximo `route:cache`.
 */
class McpHabilitado
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('mcp.ecf_habilitado', true), 404);

        return $next($request);
    }
}
