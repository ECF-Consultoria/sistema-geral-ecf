<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permite acesso à rota se o user tem QUALQUER uma das permission keys passadas
 * (lógica OR — útil pra rotas compartilhadas entre vários setores).
 *
 * Uso:
 *   Route::middleware('permission:mlb.vendas')
 *   Route::middleware('permission:core.empresas,admin.empresas')
 *
 * Admin (User::isAdmin) sempre passa via short-circuit em User::hasPermission().
 */
class EnsurePermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Acesso não autorizado.');
        }

        foreach ($keys as $key) {
            if ($user->hasPermission($key)) {
                return $next($request);
            }
        }

        abort(403, 'Você não tem permissão para acessar esta área.');
    }
}
