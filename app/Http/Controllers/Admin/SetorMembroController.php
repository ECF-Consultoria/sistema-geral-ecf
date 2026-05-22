<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attach/detach de membros e líderes do setor.
 * Ambos resolvidos no mesmo controller porque compartilham o conceito de
 * "user vinculado ao setor" — diferem só na semântica (membro = pertence;
 * líder = pode gerenciar o setor).
 */
class SetorMembroController extends Controller
{
    /** Adiciona user como membro do setor com cargo opcional. */
    public function storeMembro(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'user_id'      => ['required', 'integer', 'exists:users,id'],
            'cargo_id'     => ['nullable', 'integer', 'exists:cargos,id'],
            'is_principal' => ['boolean'],
        ]);

        // Cargo precisa pertencer ao próprio setor
        if (!empty($data['cargo_id'])) {
            $cargoOk = Cargo::where('id', $data['cargo_id'])->where('setor_id', $setor->id)->exists();
            if (!$cargoOk) {
                return back()->with('error', 'Cargo não pertence a este setor.');
            }
        }

        if (DB::table('user_setores')->where(['user_id' => $data['user_id'], 'setor_id' => $setor->id])->exists()) {
            return back()->with('error', 'Usuário já é membro deste setor.');
        }

        DB::transaction(function () use ($data, $setor) {
            // Se este é o primeiro setor do user, marca como principal automaticamente
            $jaTemPrincipal = DB::table('user_setores')
                ->where('user_id', $data['user_id'])
                ->where('is_principal', true)
                ->exists();
            $isPrincipal = !empty($data['is_principal']) || !$jaTemPrincipal;

            // Se vai ser principal, zera os outros principais do user
            if ($isPrincipal) {
                DB::table('user_setores')
                    ->where('user_id', $data['user_id'])
                    ->update(['is_principal' => false]);
            }

            DB::table('user_setores')->insert([
                'user_id'      => $data['user_id'],
                'setor_id'     => $setor->id,
                'cargo_id'     => $data['cargo_id'] ?? null,
                'is_principal' => $isPrincipal,
                'assigned_at'  => now(),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        });

        return back()->with('success', 'Membro adicionado.');
    }

    public function destroyMembro(Setor $setor, User $user)
    {
        DB::table('user_setores')
            ->where('setor_id', $setor->id)
            ->where('user_id', $user->id)
            ->delete();

        return back()->with('success', 'Membro removido.');
    }

    /**
     * Promove/rebaixa user a líder do setor. Líder não precisa ser membro
     * (pode liderar um setor sem ocupar cargo nele).
     */
    public function storeLider(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        if (DB::table('setor_lideres')->where(['user_id' => $data['user_id'], 'setor_id' => $setor->id])->exists()) {
            return back()->with('error', 'Usuário já é líder deste setor.');
        }

        DB::table('setor_lideres')->insert([
            'setor_id'    => $setor->id,
            'user_id'     => $data['user_id'],
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return back()->with('success', 'Líder adicionado.');
    }

    public function destroyLider(Setor $setor, User $user)
    {
        DB::table('setor_lideres')
            ->where('setor_id', $setor->id)
            ->where('user_id', $user->id)
            ->delete();

        return back()->with('success', 'Líder removido.');
    }
}
