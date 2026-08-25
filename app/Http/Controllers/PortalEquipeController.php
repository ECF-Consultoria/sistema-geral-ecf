<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Portal\PortalEquipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PortalEquipeController — a ECF entrando no portal de um cliente.
 *
 * Dois métodos em dois DOMÍNIOS diferentes, e é por isso que eles existem
 * separados:
 *
 *  - {@see abrir()} roda no sistema interno, onde a pessoa já provou quem é.
 *    Confere a permissão e emite a passagem.
 *  - {@see entrar()} roda no domínio do cliente, onde ela é uma desconhecida.
 *    Troca a passagem por uma sessão.
 *
 * O que atravessa é um ticket de 60 segundos e uso único — ver
 * {@see PortalEquipeService}.
 *
 * ### Por que não é "logar como o cliente"
 * A sessão criada aqui é da PESSOA DA ECF, não do cliente. Ela não vira um
 * `PortalUsuario`, não aparece na lista de acessos e não herda credencial de
 * ninguém. O que ela ganha é a mesma VISÃO — e tudo o que fizer sai no nome
 * dela.
 */
class PortalEquipeController extends Controller
{
    public function __construct(private PortalEquipeService $equipe)
    {
    }

    /**
     * GET /companies/{company}/portal — no sistema INTERNO.
     *
     * Redireciona para o domínio do cliente com a passagem na URL.
     */
    public function abrir(Request $request, Company $company)
    {
        abort_unless($this->equipe->podeEntrar($request->user(), $company), 403,
            'Você não atende esta empresa.');

        $token = $this->equipe->emitir($request->user(), $company, $request->ip());

        // `away()` porque o destino é outro domínio — o redirect nomeado do
        // Laravel montaria a URL do domínio ATUAL, que é o do admin, e o
        // `RestringeDominioDoPortal` responderia 404 ali.
        return redirect()->away($this->equipe->urlDeEntrada($token));
    }

    /**
     * GET /equipe/entrar?t=… — no domínio do CLIENTE.
     *
     * Troca a passagem por uma sessão de equipe e segue para o portal.
     */
    public function entrar(Request $request)
    {
        $token = (string) $request->query('t', '');

        $entrada = $token ? $this->equipe->consumir($token, $request->ip()) : null;

        if (! $entrada) {
            // Sem detalhar o motivo. Quem chegou com passagem inválida não tem
            // o que ganhar sabendo qual dos motivos foi.
            return redirect()->route('portal.entrada')
                ->with('portal_aviso', 'Esse acesso expirou. Abra o portal de novo pelo sistema.');
        }

        // Derruba qualquer sessão de cliente que estivesse aberta neste
        // navegador. Sem isto, o analista que testou com a conta de um cliente
        // continuaria com a sessão dela por baixo — e a próxima ação sairia no
        // nome errado.
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $request->session()->put(\App\Support\Portal\PortalContexto::SESSAO_EQUIPE, $entrada['membro']->id);
        $request->session()->put('portal_empresa_id', $entrada['empresa']->id);

        return redirect()->route('portal.auth.inicio');
    }

    /**
     * POST /equipe/sair — encerra a sessão de equipe.
     *
     * Separado do `/sair` do cliente porque ali há um `PortalUsuario` para
     * deslogar do guard, e aqui não há nenhum.
     */
    public function sair(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $dominio = config('portal.dominio_cliente');

        // Sem domínio separado (local), a entrada do portal é o destino
        // sensato; com domínio separado, a pessoa veio do admin e é para lá
        // que ela quer voltar.
        //
        // A URL é montada de `app.url` e não por `route()`: aqui estamos NO
        // domínio do cliente, e `route()` devolveria `cliente.…/companies`,
        // que responde 404 por desenho.
        return $dominio
            ? redirect()->away(rtrim(config('app.url'), '/').'/companies?tab=onboarding')
            : redirect()->route('portal.entrada');
    }
}
