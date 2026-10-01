<?php

namespace App\Http\Middleware;

use App\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trava de rota de um módulo que pode estar oculto (`modulo:<key>`).
 *
 * Oculto em Dev → Controle Dev (`modules.visivel_para_todos = false`) — ou
 * ainda não sincronizado — só o Dev entra; os demais recebem 404, como se a
 * rota não existisse. Sem isto, ocultar esconderia só o item do menu e a
 * página continuaria abrindo por URL direta.
 */
class EnsureModuloLiberado
{
    public function __construct(private ModuleRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless($this->registry->liberadoPara($request->user(), $key), 404);

        return $next($request);
    }
}
