<?php

namespace App\Support\Portal;

use App\Models\Company;
use App\Models\PortalUsuario;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * PortalContexto — o único jeito certo de descobrir "qual empresa" numa rota do
 * Portal autenticado.
 *
 * Existe para que nenhum controller precise decidir isso sozinho. A empresa sai
 * SEMPRE do usuário autenticado mais a sessão validada pelo
 * `EnsurePortalAutenticado` — nunca de parâmetro de rota, corpo de formulário
 * ou query string.
 *
 * A regra em uma frase: **se o cliente consegue escrever, não serve para
 * decidir o que ele vê.**
 *
 * Chamar `empresa()` fora do middleware é erro de programação, não condição de
 * execução — daí a exceção em vez de `null`. Falhar alto aqui é melhor do que
 * devolver a empresa errada em silêncio.
 */
class PortalContexto
{
    /** A chave de sessão que marca uma sessão de EQUIPE. */
    public const SESSAO_EQUIPE = 'portal_equipe_user_id';

    /**
     * Marca a sessão de equipe que nasceu do link aberto
     * (`config('portal.link_equipe')`), e não da passagem de 60 segundos. É
     * por ela que o middleware sabe que precisa reconferir a lista.
     */
    public const SESSAO_LINK = 'portal_equipe_por_link';

    // ─── Multi-abas (26/10/2026) ─────────────────────────────────────────
    //
    // A empresa passou a ir no ENDEREÇO (`/portal/{portalEmpresa}/...`). Até
    // aqui ela vinha só da sessão, e a sessão é uma para todas as abas: abrir
    // o portal de uma segunda empresa trocava a primeira — e o "salvar" de uma
    // aba antiga gravava na última empresa aberta, não na que a tela mostrava.
    //
    // Continua valendo a regra de cima: o número do endereço NÃO decide nada
    // sozinho. O `EnsurePortalAutenticado` confere, a cada requisição, se quem
    // está ali pode ver aquela empresa (cliente: `podeVer()`; equipe: entrou
    // nela pela passagem E `podeEntrar()`), e só então a põe no request.

    /** O parâmetro de rota com a empresa. */
    public const PARAMETRO = 'portalEmpresa';

    /**
     * Valor padrão global do parâmetro (AppServiceProvider): "a empresa da
     * sessão", como era antes. É o que sai de `route('portal.auth.…')` gerado
     * FORA de uma requisição do portal (testes, jobs, telas do admin). Texto e
     * não número de propósito: o Ziggy trata `0` numérico como "sem padrão".
     */
    public const SEM_EMPRESA_NA_URL = '0';

    /** Onde o middleware deixa a empresa já conferida desta requisição. */
    public const ATRIBUTO = 'portal_empresa_conferida';

    /**
     * Empresas em que o membro da EQUIPE entrou nesta sessão, `[id => 'passagem'|'link']`.
     * Cada uma precisou da porta da equipe — é o que mantém um registro de
     * auditoria por empresa visitada, mesmo com várias abas abertas.
     */
    public const SESSAO_EMPRESAS_EQUIPE = 'portal_equipe_empresas';

    /** @return array<int, string> */
    public static function empresasDaEquipe(): array
    {
        return (array) session(self::SESSAO_EMPRESAS_EQUIPE, []);
    }

    /** Alguém da ECF está dentro do portal deste cliente? */
    public static function modoEquipe(): bool
    {
        return (bool) session(self::SESSAO_EQUIPE);
    }

    /**
     * O membro da equipe da sessão, ou `null` se quem está aqui é o cliente.
     *
     * Relê do banco a cada chamada, pela mesma razão que o middleware relê o
     * cliente: desligar alguém da ECF precisa valer na requisição seguinte.
     */
    public static function membroDaEquipe(): ?User
    {
        $id = session(self::SESSAO_EQUIPE);

        return $id ? User::find($id) : null;
    }

    /**
     * O cliente autenticado.
     *
     * Devolve `null` em sessão de equipe — ali não há PortalUsuario nenhum, e
     * essa é a diferença que importa. Quem só precisa de "um nome para
     * mostrar" deve usar {@see ator()}, que funciona nos dois modos.
     */
    public static function usuario(): ?PortalUsuario
    {
        return Auth::guard('portal')->user();
    }

    /**
     * Quem está usando o portal agora, seja quem for.
     *
     * É este o método que os controllers devem chamar. Falha alto fora de rota
     * autenticada porque, ali, não haver ator é erro de programação — a rota
     * esqueceu o middleware `portal.auth`.
     */
    public static function ator(): AtorDoPortal
    {
        if ($membro = self::membroDaEquipe()) {
            return AtorDoPortal::daEquipe($membro);
        }

        $usuario = self::usuario();

        if (! $usuario) {
            throw new \LogicException(
                'PortalContexto::ator() fora de rota autenticada do portal — '
                . 'a rota precisa do middleware portal.auth.'
            );
        }

        return AtorDoPortal::cliente($usuario);
    }

    /**
     * A empresa do contexto. Já validada pelo middleware; a checagem repetida
     * aqui é a rede que garante que ninguém use este método por outro caminho.
     *
     * Em sessão de equipe o vínculo conferido é o do MEMBRO com a empresa (a
     * carteira dele), não o de um PortalUsuario — a régua vive em
     * {@see \App\Services\Portal\PortalEquipeService::podeEntrar()}.
     */
    public static function empresa(): Company
    {
        // A do endereço, conferida pelo middleware nesta requisição. A sessão
        // só responde em rota sem empresa no endereço (o que não deveria sobrar).
        $id = request()->attributes->get(self::ATRIBUTO) ?? session('portal_empresa_id');

        if (! $id) {
            throw new \LogicException('Empresa do portal ausente na sessão.');
        }

        if ($membro = self::membroDaEquipe()) {
            $empresa = Company::findOrFail($id);

            if (! app(\App\Services\Portal\PortalEquipeService::class)->podeEntrar($membro, $empresa)) {
                throw new \LogicException('Membro da equipe sem acesso à empresa do portal.');
            }

            return $empresa;
        }

        $usuario = self::usuario();

        if (! $usuario || ! $usuario->podeVer((int) $id)) {
            throw new \LogicException(
                'Empresa do portal ausente ou sem vínculo com o usuário autenticado.'
            );
        }

        return Company::findOrFail($id);
    }
    /**
     * Para o seletor de empresa, quando a pessoa responde por mais de uma.
     *
     * Vazio em sessão de equipe: o membro entrou numa empresa específica,
     * vindo de /companies. Para ver outra ele volta lá e entra de novo — o
     * que mantém um registro de auditoria por empresa visitada.
     */
    public static function empresasDisponiveis()
    {
        return self::modoEquipe()
            ? collect()
            : self::usuario()->empresas()->orderBy('name')->get(['companies.id', 'companies.name']);
    }
}
