<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corta o acesso de um usuário ao MCP do ECF Admin (`/mcp`): revoga todos os
 * tokens OAuth dele (acesso e refresh). O conector para de responder na hora;
 * para voltar, a pessoa conecta de novo e passa pelo login.
 *
 *   php artisan mcp:revogar dev.01@ecfconsultoria.com.br
 *   php artisan mcp:revogar 24
 */
class McpRevogar extends Command
{
    protected $signature = 'mcp:revogar {usuario : e-mail ou id do usuário}';

    protected $description = 'Revoga todos os tokens do MCP (OAuth) de um usuário';

    public function handle(): int
    {
        $alvo    = (string) $this->argument('usuario');
        $usuario = ctype_digit($alvo)
            ? User::withTrashed()->find((int) $alvo)
            : User::withTrashed()->where('email', $alvo)->first();

        if (! $usuario) {
            $this->error("Usuário não encontrado: {$alvo}");

            return self::FAILURE;
        }

        $tokens = DB::table('oauth_access_tokens')
            ->where('user_id', $usuario->id)
            ->where('revoked', false)
            ->pluck('id');

        DB::transaction(function () use ($tokens) {
            DB::table('oauth_access_tokens')->whereIn('id', $tokens)->update(['revoked' => true]);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokens)->update(['revoked' => true]);
        });

        $this->info("[MCP] {$tokens->count()} token(s) revogado(s) de {$usuario->name} (#{$usuario->id}).");

        return self::SUCCESS;
    }
}
