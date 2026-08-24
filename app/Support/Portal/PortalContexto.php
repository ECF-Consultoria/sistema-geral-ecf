<?php

namespace App\Support\Portal;

use App\Models\Company;
use App\Models\PortalUsuario;
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
    public static function usuario(): PortalUsuario
    {
        $usuario = Auth::guard('portal')->user();

        if (! $usuario) {
            throw new \LogicException(
                'PortalContexto::usuario() fora de rota autenticada do portal — '
                . 'a rota precisa do middleware portal.auth.'
            );
        }

        return $usuario;
    }

    /**
     * A empresa do contexto. Já validada pelo middleware; a checagem repetida
     * aqui é a rede que garante que ninguém use este método por outro caminho.
     */
    public static function empresa(): Company
    {
        $usuario = self::usuario();
        $id = session('portal_empresa_id');

        if (! $id || ! $usuario->podeVer((int) $id)) {
            throw new \LogicException(
                'Empresa do portal ausente ou sem vínculo com o usuário autenticado.'
            );
        }

        return Company::findOrFail($id);
    }

    /** Para o seletor de empresa, quando a pessoa responde por mais de uma. */
    public static function empresasDisponiveis()
    {
        return self::usuario()->empresas()->orderBy('name')->get(['companies.id', 'companies.name']);
    }
}
