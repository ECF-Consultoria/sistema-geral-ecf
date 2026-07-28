<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cargo Dev vira um cargo de verdade (quick 260727-mx3).
 *
 * Antes, o cargo Dev só existia como flag `users.is_dev`, concedida numa lista
 * própria dentro de /dev/modulos. Agora ele é concedido no cadastro do usuário
 * (/users) e materializa um vínculo real em `user_setores`: setor
 * "Desenvolvimento" + cargo "Dev". `users.is_dev` permanece como ESPELHO
 * derivado desse vínculo — é o que `User::isAdminDev()` lê, para não custar uma
 * query extra no menu (que roda em toda request).
 *
 * O setor nasce `is_system = true`: é infraestrutura do painel, não um setor de
 * negócio, e não deve ser apagado por engano em Administração → Setores.
 *
 * Idempotente e cross-driver (DB::table, sem Eloquent), no mesmo padrão de
 * `2026_07_14_120000_seed_setor_shopee_e_usuarios.php`.
 */
return new class extends Migration
{
    private const SETOR_SLUG = 'desenvolvimento';
    private const CARGO_SLUG = 'dev';

    public function up(): void
    {
        $agora = now();

        // `setores` usa SoftDeletes e o slug é UNIQUE — uma linha soft-deletada
        // bloquearia o insert. Se existir, reativa em vez de tentar recriar.
        $setorId = DB::table('setores')->where('slug', self::SETOR_SLUG)->value('id');
        if ($setorId) {
            DB::table('setores')->where('id', $setorId)->update([
                'active'     => true,
                'deleted_at' => null,
                'updated_at' => $agora,
            ]);
        } else {
            $setorId = DB::table('setores')->insertGetId([
                'nome'       => 'Desenvolvimento',
                'slug'       => self::SETOR_SLUG,
                'descricao'  => 'Setor técnico do painel. O cargo Dev enxerga todos os módulos, inclusive os ocultos.',
                'active'     => true,
                'is_system'  => true,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }

        $cargoId = DB::table('cargos')
            ->where('setor_id', $setorId)
            ->where('slug', self::CARGO_SLUG)
            ->value('id');

        if (! $cargoId) {
            $cargoId = DB::table('cargos')->insertGetId([
                'setor_id'   => $setorId,
                'nome'       => 'Dev',
                'slug'       => self::CARGO_SLUG,
                'ordem'      => 0,
                'active'     => true,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }

        // Backfill: quem já era Dev pela flag ganha o vínculo correspondente,
        // senão o cadastro do usuário mostraria "Dev" desligado para quem é Dev.
        // `is_principal = false` de propósito — o setor principal de um dev
        // continua sendo o setor de negócio dele.
        $devIds = DB::table('users')->where('is_dev', true)->pluck('id');

        foreach ($devIds as $userId) {
            $jaTem = DB::table('user_setores')
                ->where('user_id', $userId)
                ->where('setor_id', $setorId)
                ->exists();

            if ($jaTem) {
                DB::table('user_setores')
                    ->where('user_id', $userId)
                    ->where('setor_id', $setorId)
                    ->update(['cargo_id' => $cargoId, 'updated_at' => $agora]);
                continue;
            }

            DB::table('user_setores')->insert([
                'user_id'      => $userId,
                'setor_id'     => $setorId,
                'cargo_id'     => $cargoId,
                'is_principal' => false,
                'assigned_at'  => $agora,
                'created_at'   => $agora,
                'updated_at'   => $agora,
            ]);
        }
    }

    /**
     * Desfaz na ordem inversa. NÃO mexe em `users.is_dev`: a flag é anterior a
     * esta migration (Fase 97) e continua sendo a fonte de leitura do gate —
     * zerá-la no rollback tiraria o acesso dos devs sem necessidade.
     */
    public function down(): void
    {
        $setorId = DB::table('setores')->where('slug', self::SETOR_SLUG)->value('id');
        if (! $setorId) {
            return;
        }

        DB::table('user_setores')->where('setor_id', $setorId)->delete();
        DB::table('cargos')->where('setor_id', $setorId)->where('slug', self::CARGO_SLUG)->delete();
        DB::table('setores')->where('id', $setorId)->delete();
    }
};
