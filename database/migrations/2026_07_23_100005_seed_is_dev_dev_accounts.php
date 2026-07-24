<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 97 (Fundação do Module Registry) — seed idempotente de `users.is_dev`.
 *
 * Marca `is_dev = true` SOMENTE para a conta dev canônica pelo email. NÃO marca
 * admins de negócio — `is_dev` é deliberadamente ortogonal a `role=admin`
 * (DEC-1); marcar todos os admins anularia o propósito do eixo (módulos
 * não-produção devem sumir inclusive para admins de negócio).
 *
 * Idempotente e cross-driver (DB::table + Log, sem Eloquent) — mesmo padrão
 * de `2026_07_14_120000_seed_setor_shopee_e_usuarios.php`: pula + loga se o
 * email não existir, sem lançar exceção.
 */
return new class extends Migration
{
    /** Email canônico da conta dev (Admin Dev). */
    private const EMAIL_DEV = 'dev.01@ecfconsultoria.com.br';

    public function up(): void
    {
        $userId = DB::table('users')->where('email', self::EMAIL_DEV)->value('id');

        if (! $userId) {
            Log::warning('[Modules] conta dev ausente no seed is_dev', ['email' => self::EMAIL_DEV]);
            return;
        }

        DB::table('users')->where('id', $userId)->update([
            'is_dev'     => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', self::EMAIL_DEV)->update([
            'is_dev'     => false,
            'updated_at' => now(),
        ]);
    }
};
