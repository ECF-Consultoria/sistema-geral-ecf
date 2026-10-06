<?php

namespace App\Support\Acessos;

use App\Models\User;

/**
 * Quem acessa o Onboarding dos Polos (`/mlb/implementacao`).
 *
 * Saiu de `MlbImplementacaoController::checkAccess()` para a régua ser UMA só
 * entre a tela e a ferramenta `onboarding_polos` do MCP — o MCP não pode ver
 * mais (nem menos) do que a tela.
 */
final class AcessoImplementacaoPolos
{
    public static function permite(User $usuario): bool
    {
        $perms = $usuario->publication_permissions ?? [];

        return $usuario->role === 'admin'
            || in_array('empresas', $perms)
            || in_array($usuario->publication_role, ['gestor', 'analista', 'lider'])
            // Setor-based (novo): membros do setor Polos ganham mlb.implementacao e
            // acessam o Onboarding sem depender dos campos legados publication_*.
            || $usuario->hasPermission('mlb.implementacao');
    }
}
