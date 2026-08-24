<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalAuditoria;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePortalAutenticado — quem é você, e você pode ver ESTA empresa?
 *
 * As duas perguntas são separadas de propósito, e é a segunda que importa:
 *
 *  - **Autenticação** diz quem a pessoa é. Sozinha, não autoriza nada.
 *  - **Autorização** confere o vínculo `portal_usuario_empresa`. Sem linha lá,
 *    não há acesso — nem para usuário perfeitamente autenticado.
 *
 * Sem essa segunda pergunta, qualquer cliente logado veria a empresa de
 * qualquer outro trocando o identificador na URL. É o IDOR clássico, e é a
 * falha mais comum em portais multiempresa.
 *
 * ### A empresa sai do SERVIDOR, nunca do request
 * `PortalContexto::empresa()` resolve a empresa a partir do usuário
 * autenticado. Nenhuma rota do Portal deve aceitar `company_id` de parâmetro,
 * de formulário ou de sessão preenchida pelo cliente.
 *
 * ### Consulta ao banco a cada requisição
 * `podeVer()` vai ao banco toda vez, em vez de confiar num vínculo guardado na
 * sessão no momento do login. É deliberado: revogar acesso precisa valer na
 * requisição SEGUINTE, não daqui a trinta dias, quando a sessão expirar.
 */
class EnsurePortalAutenticado
{
    public function __construct(private PortalAuditoria $auditoria)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::guard('portal')->user();

        if (! $usuario) {
            return $this->naoAutenticado($request);
        }

        // Relê do banco, sempre. O guard guarda o usuário resolvido em memória,
        // e confiar nessa cópia faria "revogar acesso" só valer quando a sessão
        // expirasse — trinta dias depois. Uma query por requisição é o preço de
        // a revogação ser imediata, que é o requisito.
        $usuario = $usuario->fresh();

        if (! $usuario) {
            // Apagado do banco enquanto estava logado.
            Auth::guard('portal')->logout();
            $request->session()->invalidate();

            return $this->naoAutenticado($request);
        }

        if (! $usuario->ativo) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();

            return $this->naoAutenticado($request, 'Seu acesso foi encerrado. Fale com o seu analista responsável.');
        }

        $empresaId = $request->session()->get('portal_empresa_id');

        if (! $empresaId || ! $usuario->podeVer((int) $empresaId)) {
            // Registrar antes de negar: é o sinal de alguém trocando id na URL,
            // e sem este registro a tentativa seria invisível.
            if ($empresaId) {
                $this->auditoria->acessoNegado($usuario, (int) $empresaId, $request->ip());
            }

            $padrao = $usuario->empresaPadrao();

            // Sem vínculo nenhum: autenticado e sem nada para ver. Acontece
            // quando a ECF remove a última empresa da pessoa sem desativá-la.
            if (! $padrao) {
                Auth::guard('portal')->logout();
                $request->session()->invalidate();

                return $this->naoAutenticado($request, 'Você ainda não tem acesso a nenhuma empresa. Fale com o seu analista responsável.');
            }

            $request->session()->put('portal_empresa_id', $padrao->id);
        }

        return $next($request);
    }

    /**
     * 302 para a entrada do portal — e não 401/403 — porque o alvo é um cliente
     * de navegador, não uma API. A mensagem chega por `flash`, do mesmo jeito
     * que o resto do sistema faz.
     */
    private function naoAutenticado(Request $request, ?string $mensagem = null): Response
    {
        $destino = route('portal.entrada');

        if ($mensagem) {
            $request->session()->flash('portal_aviso', $mensagem);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $mensagem ?? 'Não autenticado.'], 401)
            : redirect()->guest($destino);
    }
}
