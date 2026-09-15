<?php

namespace App\Http\Middleware;

use App\Support\Portal\UrlDoPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** O link antigo só leva ao login. Nunca identifica ou autoriza uma empresa. */
class AposentaTokenDoPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        // Recusar antes do controller: abas antigas não podem continuar
        // gravando por token, mesmo se o navegador tiver uma sessão válida.
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response()->json([
                'message' => 'Este acesso por link foi encerrado. Entre no portal com seu e-mail cadastrado.',
            ], 410)->header('Cache-Control', 'no-store, private');
        }

        // Não consulta nem carimba o token. A resposta é igual para qualquer
        // valor, e nunca troca a empresa da sessão por aquela contida no link.
        return redirect()->to(UrlDoPortal::para('portal.entrada'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
