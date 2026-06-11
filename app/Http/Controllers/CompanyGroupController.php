<?php

namespace App\Http\Controllers;

use App\Models\CompanyGroup;
use Illuminate\Http\Request;

/**
 * CRUD de grupos nomeados de empresas (tipo carteira) exibidos em /companies.
 *
 * Acesso restrito a admin via grupo de rotas role:admin (routes/web.php).
 * Atribuição de empresa a grupo é feita via CompanyController::update
 * (campo company_group_id) — aqui só gerimos os grupos em si.
 */
class CompanyGroupController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
        ]);

        CompanyGroup::create([
            'name'  => $data['name'],
            'color' => $data['color'] ?: '#ffe600',
        ]);

        return back()->with('success', 'Grupo criado.');
    }

    public function update(Request $request, CompanyGroup $group)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
        ]);

        $group->update([
            'name'  => $data['name'],
            'color' => $data['color'] ?: $group->color,
        ]);

        return back()->with('success', 'Grupo atualizado.');
    }

    public function destroy(CompanyGroup $group)
    {
        // FK nullOnDelete desvincula as empresas automaticamente (não as exclui).
        $nome = $group->name;
        $group->delete();

        return back()->with('success', "Grupo \"{$nome}\" excluído.");
    }
}
