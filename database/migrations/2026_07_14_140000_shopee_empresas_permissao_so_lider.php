<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 78 (v16.0) — /shopee/empresas passa a ser EXCLUSIVO do líder do Setor
 * Shopee (+ admin). A migration da Phase 77 (2026_07_14_120000) concedeu
 * `shopee.empresas` ao SETOR (todos os membros via setor_permissoes). Agora a
 * permissão é de LÍDER — concedida em `User::effectivePermissions()` só a quem
 * lidera o setor 'shopee' — então removemos o grant de membros.
 *
 * É o líder quem resolve a pendência atribuindo a empresa a um analista/
 * estrategista do setor dele. Analistas/estrategistas NÃO acessam a aba.
 *
 * Idempotente + reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setorId = DB::table('setores')->where('slug', 'shopee')->value('id');
        if (! $setorId) {
            return; // Setor Shopee ainda não existe (fresh antes da Phase 77) — no-op.
        }

        DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->where('permission_key', 'shopee.empresas')
            ->delete();
    }

    public function down(): void
    {
        // Reverte: re-concede shopee.empresas ao setor (comportamento pré-Phase 78).
        $setorId = DB::table('setores')->where('slug', 'shopee')->value('id');
        if (! $setorId) {
            return;
        }

        $existe = DB::table('setor_permissoes')
            ->where('setor_id', $setorId)
            ->where('permission_key', 'shopee.empresas')
            ->exists();

        if (! $existe) {
            DB::table('setor_permissoes')->insert([
                'setor_id'       => $setorId,
                'permission_key' => 'shopee.empresas',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }
};
