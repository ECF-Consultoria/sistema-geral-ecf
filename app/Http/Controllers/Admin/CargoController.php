<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Setor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD de cargos (nested em setor).
 * Acesso via permission:sistema.setores.
 */
class CargoController extends Controller
{
    public function store(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'nome'             => ['required', 'string', 'max:100'],
            'meta_publicacoes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'ordem'            => ['nullable', 'integer'],
            'active'           => ['boolean'],
        ]);

        $slug = Str::slug($data['nome']);
        if ($setor->cargos()->where('slug', $slug)->exists()) {
            return back()->with('error', "Já existe cargo '{$data['nome']}' neste setor.");
        }

        $setor->cargos()->create([
            'nome'             => $data['nome'],
            'slug'             => $slug,
            'meta_publicacoes' => $data['meta_publicacoes'] ?? null,
            'ordem'            => $data['ordem'] ?? 0,
            'active'           => $data['active'] ?? true,
        ]);

        return back()->with('success', "Cargo '{$data['nome']}' criado.");
    }

    public function update(Request $request, Cargo $cargo)
    {
        $data = $request->validate([
            'nome'             => ['required', 'string', 'max:100'],
            'meta_publicacoes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'ordem'            => ['nullable', 'integer'],
            'active'           => ['boolean'],
        ]);

        $cargo->update([
            'nome'             => $data['nome'],
            'slug'             => Str::slug($data['nome']),
            'meta_publicacoes' => $data['meta_publicacoes'] ?? null,
            'ordem'            => $data['ordem'] ?? $cargo->ordem,
            'active'           => $data['active'] ?? $cargo->active,
        ]);

        return back()->with('success', 'Cargo atualizado.');
    }

    public function destroy(Cargo $cargo)
    {
        // Bloqueia se houver membros nesse cargo
        $emUso = \DB::table('user_setores')->where('cargo_id', $cargo->id)->exists();
        if ($emUso) {
            return back()->with('error', "Cargo '{$cargo->nome}' está em uso por membros do setor.");
        }

        $cargo->delete();
        return back()->with('success', "Cargo '{$cargo->nome}' excluído.");
    }
}
