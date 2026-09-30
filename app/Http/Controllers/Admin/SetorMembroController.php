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
    /**
     * Adiciona user como membro do setor com cargo opcional.
     *
     * D-10 (Fase 159, plano 159-02): uma pessoa pode ter mais de um cargo no
     * mesmo setor (D-01). Esta tela é o segundo caminho que grava
     * `user_setores` — precisa conviver com o que a tela /users grava, então
     * "adicionar cargo a quem já é membro" passa a ser permitido:
     *   - cargo informado e já existe linha com ESSE cargo → recusa (par repetido)
     *   - cargo NÃO informado e já existe QUALQUER linha → recusa (par sem
     *     cargo colidiria com outra linha sem cargo — defesa de aplicação,
     *     não de banco, conforme decisão de schema da migration 159-01)
     *   - cargo informado e existe linha com cargo NULL → converte a linha
     *     existente (não cria uma segunda — é o único caso em que o unique
     *     do banco não protege)
     *   - caso contrário → insert de uma nova linha (fluxo de antes)
     */
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

        $linhasDoPar = DB::table('user_setores')
            ->where('user_id', $data['user_id'])
            ->where('setor_id', $setor->id)
            ->get();

        if (!empty($data['cargo_id'])) {
            $jaTemEsseCargo = $linhasDoPar->contains(fn ($l) => (int) $l->cargo_id === (int) $data['cargo_id']);
            if ($jaTemEsseCargo) {
                return back()->with('error', 'Usuário já tem este cargo neste setor.');
            }
        } elseif ($linhasDoPar->isNotEmpty()) {
            return back()->with('error', 'Usuário já é membro deste setor. Para acrescentar outro cargo, escolha qual.');
        }

        $linhaSemCargo = $linhasDoPar->first(fn ($l) => $l->cargo_id === null);

        DB::transaction(function () use ($data, $setor, $linhaSemCargo) {
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

            if (!empty($data['cargo_id']) && $linhaSemCargo) {
                // Converte a linha sem cargo em vez de criar uma segunda —
                // a linha "sem cargo" é o único caso em que o unique do
                // banco não distingue duas linhas do mesmo par.
                DB::table('user_setores')
                    ->where('id', $linhaSemCargo->id)
                    ->update([
                        'cargo_id'     => $data['cargo_id'],
                        'is_principal' => $isPrincipal,
                        'updated_at'   => now(),
                    ]);

                return;
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

        $mensagem = (!empty($data['cargo_id']) && $linhaSemCargo) ? 'Cargo atribuído ao membro.' : 'Membro adicionado.';

        return back()->with('success', $mensagem);
    }

    /**
     * Remove membro do setor. D-10: remoção passa a ser POR CARGO.
     *
     * Com `vinculo` (id da linha, vindo por query string): apaga só aquela
     * linha — buscada restrita a setor_id E user_id da rota (nunca por id
     * isolado, T-159-05/IDOR). Não achou → 404, nada apagado.
     *
     * Sem `vinculo`: comportamento de antes é preservado SE o par tem
     * exatamente 1 linha (apaga). Com 2+ linhas, recusa — uma tela não pode
     * apagar os dois cargos de quem tem dois, silenciosamente.
     *
     * Se a linha removida era principal e a pessoa ainda tem outras linhas
     * sem nenhuma principal, a de MENOR id vira principal.
     */
    public function destroyMembro(Request $request, Setor $setor, User $user)
    {
        $vinculoId = $request->integer('vinculo') ?: null;

        if ($vinculoId) {
            $linha = DB::table('user_setores')
                ->where('id', $vinculoId)
                ->where('setor_id', $setor->id)
                ->where('user_id', $user->id)
                ->first();

            if (!$linha) {
                abort(404);
            }
        } else {
            $linhasDoPar = DB::table('user_setores')
                ->where('setor_id', $setor->id)
                ->where('user_id', $user->id)
                ->get();

            if ($linhasDoPar->count() > 1) {
                return back()->with('error', 'Este usuário tem mais de um cargo neste setor — remova um cargo por vez.');
            }

            $linha = $linhasDoPar->first();
            if (!$linha) {
                return back()->with('success', 'Membro removido.');
            }
        }

        DB::transaction(function () use ($linha, $user) {
            DB::table('user_setores')->where('id', $linha->id)->delete();

            if ($linha->is_principal) {
                $proximaPrincipal = DB::table('user_setores')
                    ->where('user_id', $user->id)
                    ->where('is_principal', false)
                    ->orderBy('id')
                    ->first();

                if ($proximaPrincipal) {
                    DB::table('user_setores')
                        ->where('id', $proximaPrincipal->id)
                        ->update(['is_principal' => true]);
                }
            }
        });

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
