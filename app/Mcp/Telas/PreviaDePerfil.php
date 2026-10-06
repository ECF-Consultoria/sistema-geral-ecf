<?php

namespace App\Mcp\Telas;

use App\Models\User;
use App\Services\ModuleRegistry;

/**
 * Prévia das travas DECLARADAS na rota (`auth`, `role:`, `permission:`,
 * `modulo:`), para as listas do MCP (`listar_telas`, `listar_acoes`) não
 * oferecerem o que vai dar 403.
 *
 * É só prévia: as travas DENTRO do controller (abort 403, policy) valem na
 * hora em que a tela abre ou o formulário é enviado — o controller roda
 * inteiro.
 */
final class PreviaDePerfil
{
    /** A rota está atrás do login do sistema interno (guard web)? */
    public static function exigeLogin(array $middleware): bool
    {
        return collect($middleware)->contains(fn ($m) => is_string($m)
            && ($m === 'auth' || $m === 'auth:web' || str_ends_with($m, '\\Authenticate')));
    }

    /** @param  array<int, mixed>  $middleware */
    public static function permite(User $usuario, array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (! is_string($m) || ! str_contains($m, ':')) {
                continue;
            }

            [$nome, $args] = explode(':', $m, 2);
            $lista = explode(',', $args);

            $ok = match ($nome) {
                'role'       => in_array($usuario->role, $lista, true),
                'permission' => collect($lista)->contains(fn ($p) => $usuario->hasPermission($p)),
                'modulo'     => app(ModuleRegistry::class)->liberadoPara($usuario, $lista[0]),
                default      => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
