<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setor;
use App\Models\SetorGoal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD de metas por setor.
 * Acessível por admin (sistema.setores) ou pelo líder do setor (lideranca.definir_metas).
 * O gate é resolvido nas rotas pra dar flexibilidade.
 */
class SetorGoalController extends Controller
{
    public function store(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'metric'       => ['required', 'string', Rule::in(array_keys(SetorGoal::$metricLabels))],
            'target_value' => ['required', 'numeric', 'min:0'],
            'value_type'   => ['required', Rule::in(['absolute', 'percentage'])],
            'period_type'  => ['required', Rule::in(['monthly', 'quarterly', 'annual'])],
            'description'  => ['nullable', 'string', 'max:1000'],
            'active'       => ['boolean'],
        ]);

        $setor->metas()->create($data + [
            'active'     => $data['active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Meta criada.');
    }

    public function update(Request $request, SetorGoal $meta)
    {
        $data = $request->validate([
            'metric'       => ['required', 'string', Rule::in(array_keys(SetorGoal::$metricLabels))],
            'target_value' => ['required', 'numeric', 'min:0'],
            'value_type'   => ['required', Rule::in(['absolute', 'percentage'])],
            'period_type'  => ['required', Rule::in(['monthly', 'quarterly', 'annual'])],
            'description'  => ['nullable', 'string', 'max:1000'],
            'active'       => ['boolean'],
        ]);

        $meta->update($data);

        return back()->with('success', 'Meta atualizada.');
    }

    public function destroy(SetorGoal $meta)
    {
        $meta->delete();
        return back()->with('success', 'Meta excluída.');
    }
}
