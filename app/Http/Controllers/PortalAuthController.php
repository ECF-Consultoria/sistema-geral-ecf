<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalAuditoria;
use App\Services\Portal\PortalLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * PortalAuthController — entrar e sair do Portal do Cliente.
 *
 * O fluxo tem dois passos, e a tela é a mesma: a pessoa informa o e-mail,
 * recebe seis dígitos e digita ali mesmo. Não há link de entrada no e-mail — é
 * o que faz o código encaminhado não servir para ninguém.
 *
 * Toda a segurança do fluxo mora no {@see PortalLoginService}; aqui ficam só a
 * tela, a sessão e os limites de requisição.
 */
class PortalAuthController extends Controller
{
    public function __construct(
        private PortalLoginService $login,
        private PortalAuditoria $auditoria,
    ) {
    }

    /** GET / — a porta da frente do domínio do cliente. */
    public function entrada(Request $request)
    {
        // Já autenticado: vai direto para o portal, sem passar pelo login.
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.auth.inicio');
        }

        return Inertia::render('Portal/Entrada', [
            'aviso' => $request->session()->get('portal_aviso'),
        ]);
    }

    /**
     * POST /entrar/codigo — pede o código.
     *
     * Responde SEMPRE igual, exista ou não o e-mail. Ver a regra 1 no docblock
     * do `PortalLoginService`: variar a resposta transformaria esta tela num
     * verificador de quem é cliente da ECF.
     */
    public function enviarCodigo(Request $request)
    {
        $dados = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        $this->login->solicitarCodigo(
            $dados['email'],
            $this->desafioDaSessao($request),
            $request->ip(),
        );

        return back()->with([
            'portal_codigo_enviado' => true,
            'portal_email'          => $dados['email'],
        ]);
    }

    /** POST /entrar — confere o código e abre a sessão. */
    public function validarCodigo(Request $request)
    {
        $dados = $request->validate([
            'email'  => ['required', 'email', 'max:190'],
            'codigo' => ['required', 'string', 'max:10'],
        ]);

        $usuario = $this->login->validarCodigo(
            $dados['email'],
            $dados['codigo'],
            $this->desafioDaSessao($request),
            $request->ip(),
        );

        if (! $usuario) {
            // Mensagem única para todos os motivos (código errado, expirado,
            // sessão diferente, usuário inexistente). Detalhar ajudaria mais o
            // atacante do que o cliente.
            return back()
                ->withErrors(['codigo' => 'Código inválido ou expirado. Peça um novo código.'])
                ->with('portal_codigo_enviado', true);
        }

        return $this->abrirSessao($request, $usuario);
    }

    /**
     * POST /entrar/senha — a porta OPCIONAL, para quem definiu uma senha.
     *
     * Convive com o código, não o substitui. Quem nunca definiu senha nem vê
     * diferença; quem definiu entra sem esperar e-mail.
     */
    public function entrarComSenha(Request $request)
    {
        $dados = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'senha' => ['required', 'string', 'max:200'],
        ]);

        $usuario = $this->login->validarSenha($dados['email'], $dados['senha'], $request->ip());

        if (! $usuario) {
            // Mensagem única para todos os motivos — e-mail desconhecido, conta
            // sem senha, senha errada. Detalhar diria a um estranho quem é
            // cliente da ECF.
            //
            // O texto aponta a outra porta porque o motivo MAIS comum de cair
            // aqui é justamente não ter senha nenhuma.
            return back()
                ->withErrors(['senha' => 'Não conseguimos entrar com esses dados. Se preferir, peça um código por e-mail.'])
                ->with('portal_email', $dados['email']);
        }

        return $this->abrirSessao($request, $usuario);
    }

    /**
     * PUT /portal/senha — a pessoa define, troca ou remove a própria senha.
     *
     * Remover devolve o comportamento padrão do portal (só código), e é
     * importante que dê para voltar atrás: quem definiu senha num computador
     * compartilhado precisa poder desfazer.
     */
    public function salvarSenha(Request $request)
    {
        $usuario = \App\Support\Portal\PortalContexto::usuario();

        // Sessão de equipe não tem senha para mudar — a conta não é dela.
        abort_if(! $usuario, 403, 'Esta sessão não é de um acesso do portal.');

        $dados = $request->validate([
            // `nullable` é o caminho de REMOVER. Mínimo de 8 porque abaixo
            // disso a senha é pior do que o código de 6 dígitos, que ao menos
            // expira em 10 minutos.
            'senha' => ['nullable', 'string', 'min:8', 'max:200', 'confirmed'],
        ]);

        $this->login->definirSenha($usuario, $dados['senha'] ?? null);

        return back()->with('portal_sucesso', ($dados['senha'] ?? null)
            ? 'Senha salva. Da próxima vez você pode entrar direto com ela.'
            : 'Senha removida. Você volta a entrar pelo código enviado por e-mail.');
    }

    /**
     * Abre a sessão do cliente. Compartilhado pelas duas portas de entrada —
     * se cada uma montasse a sua, uma delas acabaria esquecendo o
     * `regenerate()`.
     */
    private function abrirSessao(Request $request, \App\Models\PortalUsuario $usuario)
    {
        $empresa = $usuario->empresaPadrao();

        // Regenerar o id ANTES de gravar qualquer coisa na sessão: sem isto, um
        // id de sessão plantado antes do login continuaria válido depois dele
        // (session fixation).
        $request->session()->regenerate();

        Auth::guard('portal')->login($usuario);
        $request->session()->put('portal_empresa_id', $empresa->id);

        $this->auditoria->entrou($usuario, $empresa, $request->ip());

        return redirect()->route('portal.auth.inicio');
    }

    /**
     * O identificador do NAVEGADOR, para amarrar o código a ele.
     *
     * Fica no conteúdo da sessão, não no id dela: o id é regenerado pelo
     * Laravel no login e em outras situações, e amarrar nele derrubaria o login
     * de quem apenas demorou entre pedir e digitar. O conteúdo sobrevive ao
     * `regenerate()`.
     */
    private function desafioDaSessao(Request $request): string
    {
        $desafio = $request->session()->get('portal_desafio');

        if (! $desafio) {
            $desafio = \Illuminate\Support\Str::random(48);
            $request->session()->put('portal_desafio', $desafio);
        }

        return $desafio;
    }

    /** POST /sair */
    public function sair(Request $request)
    {
        $usuario = Auth::guard('portal')->user();

        if ($usuario) {
            $this->auditoria->saiu($usuario, $request->ip());
        }

        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.entrada');
    }

    /**
     * POST /empresa — troca a empresa ativa, para quem responde por mais de uma.
     *
     * O `podeVer()` é o que impede a troca virar uma porta: sem ele, bastaria
     * mandar qualquer `company_id` neste formulário para ver a empresa de outro
     * cliente.
     */
    public function trocarEmpresa(Request $request)
    {
        $dados = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $usuario = Auth::guard('portal')->user();

        if (! $usuario || ! $usuario->podeVer($dados['company_id'])) {
            if ($usuario) {
                $this->auditoria->acessoNegado($usuario, (int) $dados['company_id'], $request->ip());
            }

            abort(403);
        }

        $request->session()->put('portal_empresa_id', $dados['company_id']);

        return redirect()->route('portal.auth.inicio');
    }
}
