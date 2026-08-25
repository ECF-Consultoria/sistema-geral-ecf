<?php

namespace App\Http\Middleware;

use App\Support\Portal\UrlDoPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem chegou ao portal pelo endereço do admin é levado para o do cliente.
 *
 * ### O passivo que isto cobre
 * Até 25/08/2026 o link do portal era montado por `route()`, que usa o host de
 * quem gera — e quem gera está sempre no admin. Os links entregues aos clientes
 * apontam para `admin.ecfconsultoria.com.br/portal-cliente/…`, e estão no
 * WhatsApp deles. Não há como recolher.
 *
 * Bloquear esse caminho quebraria todo mundo que usa o link de hoje. Redirecionar
 * não quebra ninguém e ainda leva a pessoa para o domínio certo — onde o
 * `RestringeDominioDoPortal` de fato protege, porque ali só as rotas do portal
 * existem.
 *
 * ### Por que 302 e não 301
 * 301 fica no cache do navegador para sempre. Se um dia o domínio do portal
 * mudar, ou a variável for esvaziada em emergência, um 301 gravado continuaria
 * mandando gente para um endereço que não responde mais — e sem nenhuma forma
 * de limpar isso do navegador do cliente.
 *
 * ### Não faz nada quando não precisa
 * Sem `PORTAL_CLIENTE_DOMINIO`, ou quando a requisição já chegou no domínio
 * certo, passa direto. É a mesma condição de `UrlDoPortal`, para que os dois
 * nunca discordem sobre qual é o endereço do portal.
 */
class LevaPortalParaODominioDoCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! UrlDoPortal::temDominioProprio()) {
            return $next($request);
        }

        // `fullUrl()` preserva a query string. O OAuth do Mercado Livre volta
        // com parâmetros na URL, e perdê-los aqui mataria o fluxo de conectar
        // a conta bem no fim.
        return redirect()->away(UrlDoPortal::noDominioDoCliente($request->fullUrl()));
    }
}
