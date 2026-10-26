<?php

namespace App\Http\Middleware;

use App\Services\Portal\PortalAuditoria;
use App\Support\Portal\PortalContexto;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
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
 * ### A empresa no endereço (multi-abas, 26/10/2026)
 * As rotas levam a empresa no caminho (`/portal/{portalEmpresa}/…`) para cada
 * aba ficar presa à sua — com a empresa só na sessão, abrir uma segunda trocava
 * a primeira, e o "salvar" da aba antiga gravava na última aberta. O número do
 * endereço é conferido aqui, a cada requisição, exatamente como a sessão era:
 * o cliente precisa de `podeVer()`; a equipe precisa ter entrado naquela
 * empresa pela porta da equipe nesta sessão E de `podeEntrar()`. Endereço com
 * "0" (link gerado fora do portal) usa a empresa da sessão, como antes.
 *
 * ### Consulta ao banco a cada requisição
 * `podeVer()` vai ao banco toda vez, em vez de confiar num vínculo guardado na
 * sessão no momento do login. É deliberado: revogar acesso precisa valer na
 * requisição SEGUINTE, não daqui a trinta dias, quando a sessão expirar.
 */
class EnsurePortalAutenticado
{
    public function __construct(
        private PortalAuditoria $auditoria,
        private \App\Services\Portal\PortalEquipeService $equipe,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Sessão de EQUIPE vem primeiro: ali não há PortalUsuario nenhum, e
        // cair no caminho do cliente devolveria a pessoa para a tela de
        // entrar num laço sem fim.
        if ($membroId = $request->session()->get(\App\Support\Portal\PortalContexto::SESSAO_EQUIPE)) {
            return $this->equipeOuDerruba($request, $next, (int) $membroId);
        }

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

        $daUrl = $this->empresaDaUrl($request);
        $empresaId = $daUrl ?? (int) $request->session()->get('portal_empresa_id');

        if (! $empresaId || ! $usuario->podeVer($empresaId)) {
            // Registrar antes de negar: é o sinal de alguém trocando id na URL,
            // e sem este registro a tentativa seria invisível.
            if ($empresaId) {
                $this->auditoria->acessoNegado($usuario, $empresaId, $request->ip());
            }

            $padrao = $usuario->empresaPadrao();

            // Sem vínculo nenhum: autenticado e sem nada para ver. Acontece
            // quando a ECF remove a última empresa da pessoa sem desativá-la.
            if (! $padrao) {
                Auth::guard('portal')->logout();
                $request->session()->invalidate();

                return $this->naoAutenticado($request, 'Você ainda não tem acesso a nenhuma empresa. Fale com o seu analista responsável.');
            }

            // Empresa do ENDEREÇO que a pessoa não vê: nada roda nela. Leitura
            // volta para o Início da empresa padrão; escrita é recusada.
            if ($daUrl !== null) {
                return $this->paraOutraEmpresa($request, $padrao->id);
            }

            $empresaId = $padrao->id;
        }

        return $this->seguir($request, $next, $empresaId);
    }

    /**
     * A sessão de equipe, revalidada a cada requisição.
     *
     * As três perguntas são as mesmas que se faz do cliente, e pela mesma
     * razão de sempre: o vínculo pode ter mudado desde o login. Quem saiu da
     * ECF, quem foi desativado e quem perdeu a empresa da carteira deixam de
     * entrar na requisição SEGUINTE — não daqui a trinta dias.
     *
     * A sessão de equipe é derrubada por completo quando qualquer uma falha:
     * meia-sessão de equipe seria uma sessão sem dono.
     *
     * Multi-abas: a empresa do endereço também precisa estar entre as que o
     * membro ABRIU pela porta da equipe nesta sessão
     * ({@see PortalContexto::SESSAO_EMPRESAS_EQUIPE}). Trocar o número na mão
     * dá 403 e NÃO derruba as outras abas — derrubar seria punir as abas
     * legítimas por um endereço digitado.
     */
    private function equipeOuDerruba(Request $request, Closure $next, int $membroId): Response
    {
        $membro = \App\Models\User::find($membroId);
        $daUrl = $this->empresaDaUrl($request);
        $daSessao = (int) $request->session()->get('portal_empresa_id');
        $empresaId = $daUrl ?? $daSessao;
        $empresa = $empresaId ? \App\Models\Company::find($empresaId) : null;
        $entradas = PortalContexto::empresasDaEquipe();

        $sessao = [
            PortalContexto::SESSAO_EQUIPE,
            PortalContexto::SESSAO_LINK,
            PortalContexto::SESSAO_EMPRESAS_EQUIPE,
            'portal_empresa_id',
        ];

        if (! $membro || ! $membro->active) {
            $request->session()->forget($sessao);

            return $this->naoAutenticado($request, 'Sua sessão no portal do cliente terminou. Entre de novo pelo sistema.');
        }

        // Sessão aberta antes do multi-abas não tem a lista: vale a empresa da
        // sessão, como era.
        $entrouNela = $entradas === [] ? ($empresaId === $daSessao) : isset($entradas[$empresaId]);

        if ($daUrl !== null && (! $empresa || ! $entrouNela)) {
            return $this->recusar($request, 'Abra o portal desta empresa pelo sistema: Empresas › Portal do cliente.');
        }

        if (! $empresa || ! $entrouNela || ! $this->equipe->podeEntrar($membro, $empresa)) {
            $request->session()->forget($sessao);

            return $this->naoAutenticado($request, 'Sua sessão no portal do cliente terminou. Entre de novo pelo sistema.');
        }

        // Empresa aberta pelo link aberto: a lista `portal.link_equipe` é
        // reconferida aqui, a cada requisição. Para o dono (admin), a pergunta
        // de cima responde "sim" sempre — sem esta, tirar a empresa da lista só
        // barraria quem chegasse depois.
        $porLink = $entradas === []
            ? (bool) $request->session()->get(PortalContexto::SESSAO_LINK)
            : ($entradas[$empresaId] ?? null) === 'link';

        if ($porLink && ! $this->equipe->linkContinuaValendo($membro, $empresa)) {
            $request->session()->forget($sessao);

            return $this->naoAutenticado($request, 'Esse link do portal não está mais ativo.');
        }

        return $this->seguir($request, $next, $empresaId);
    }

    /**
     * A empresa do endereço, ou `null` quando ele não traz uma (rota sem o
     * parâmetro, ou o "0" de link gerado fora do portal).
     */
    private function empresaDaUrl(Request $request): ?int
    {
        $valor = $request->route()?->parameter(PortalContexto::PARAMETRO);

        if ($valor === null || (string) $valor === PortalContexto::SEM_EMPRESA_NA_URL) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Empresa conferida: segue para o controller com ela.
     */
    private function seguir(Request $request, Closure $next, int $empresaId): Response
    {
        // A última empresa usada fica na sessão: é ela que responde a endereço
        // sem empresa (link antigo, "0").
        $request->session()->put('portal_empresa_id', $empresaId);
        $request->attributes->set(PortalContexto::ATRIBUTO, $empresaId);

        // Todo link gerado daqui em diante (servidor e Ziggy, que lê estes
        // padrões no `@routes`) sai com a empresa desta aba.
        URL::defaults([PortalContexto::PARAMETRO => (string) $empresaId]);

        $rota = $request->route();

        if ($rota && $rota->hasParameter(PortalContexto::PARAMETRO)) {
            $veio = (string) $rota->parameter(PortalContexto::PARAMETRO);

            // Os controllers recebem os argumentos da rota POR POSIÇÃO: um
            // parâmetro a mais deslocaria todos eles.
            $rota->forgetParameter(PortalContexto::PARAMETRO);

            // Chegou por "0": a página passa a mostrar (e o histórico a guardar)
            // o endereço com a empresa, e o F5 dessa aba fica preso nela.
            if ($veio === PortalContexto::SEM_EMPRESA_NA_URL) {
                $prefixo = '/portal/'.PortalContexto::SEM_EMPRESA_NA_URL;

                Inertia::resolveUrlUsing(function (Request $r) use ($prefixo, $empresaId) {
                    $url = Str::start(Str::after($r->fullUrl(), $r->getSchemeAndHttpHost()), '/');

                    return Str::startsWith($url, [$prefixo.'/', $prefixo.'?']) || $url === $prefixo
                        ? '/portal/'.$empresaId.Str::after($url, $prefixo)
                        : $url;
                });
            }
        }

        return $next($request);
    }

    /**
     * Cliente num endereço de empresa que não é dele. Leitura vai ao Início da
     * empresa padrão (recarregando a página: o Ziggy da aba precisa nascer com
     * a empresa nova); escrita é recusada — ela iria para uma empresa que a
     * tela não mostrava.
     */
    private function paraOutraEmpresa(Request $request, int $empresaId): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $this->recusar($request, 'Você não tem acesso a esta empresa.');
        }

        $destino = route('portal.auth.inicio', [PortalContexto::PARAMETRO => $empresaId]);

        return $request->header('X-Inertia') ? Inertia::location($destino) : redirect()->to($destino);
    }

    private function recusar(Request $request, string $mensagem): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $mensagem], 403);
        }

        abort(403, $mensagem);
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
