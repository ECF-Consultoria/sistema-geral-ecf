<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration — adiciona 'sugadores' ao JSON publication_permissions dos
 * usuários que já têm override granular (não-NULL) e cujo papel deveria
 * ter essa permissão por padrão (gestor / lider / analista).
 *
 * Não cria coluna. Usuários com publication_permissions NULL continuam
 * usando os defaults do model (App\Models\User::DEFAULT_PUB_PERMISSIONS),
 * que já foram atualizados no mesmo deploy.
 */
return new class extends Migration
{
    private const PAPEIS_COM_SUGADORES = ['gestor', 'lider', 'analista'];

    public function up(): void
    {
        $users = DB::table('users')
            ->whereIn('publication_role', self::PAPEIS_COM_SUGADORES)
            ->whereNotNull('publication_permissions')
            ->get(['id', 'publication_permissions']);

        foreach ($users as $u) {
            $perms = json_decode($u->publication_permissions, true);
            if (!is_array($perms)) continue;
            if (in_array('sugadores', $perms, true)) continue;

            $perms[] = 'sugadores';

            DB::table('users')
                ->where('id', $u->id)
                ->update(['publication_permissions' => json_encode(array_values($perms))]);
        }
    }

    public function down(): void
    {
        $users = DB::table('users')
            ->whereNotNull('publication_permissions')
            ->get(['id', 'publication_permissions']);

        foreach ($users as $u) {
            $perms = json_decode($u->publication_permissions, true);
            if (!is_array($perms)) continue;

            $filtered = array_values(array_filter($perms, fn($p) => $p !== 'sugadores'));
            if (count($filtered) === count($perms)) continue;

            DB::table('users')
                ->where('id', $u->id)
                ->update(['publication_permissions' => json_encode($filtered)]);
        }
    }
};
