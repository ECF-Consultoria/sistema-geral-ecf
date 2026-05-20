<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setor;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

/**
 * CRUD de setores + sync de permissões.
 * Acesso gated por middleware permission:sistema.setores em routes/web.php.
 */
class SetorController extends Controller
{
    public function index()
    {
        $setores = Setor::query()
            ->withCount(['membros', 'cargos as cargos_count', 'lideres as lideres_count'])
            ->orderBy('is_system', 'desc')
            ->orderBy('nome')
            ->get();

        return Inertia::render('Admin/Setores/Index', [
            'setores' => $setores,
        ]);
    }

    public function show(Setor $setor)
    {
        $setor->load([
            'cargos',
            'permissoes',
            'metas' => fn($q) => $q->orderBy('active', 'desc')->orderBy('id', 'desc'),
            'membros' => fn($q) => $q->select('users.id', 'users.name', 'users.email', 'users.active'),
            'lideres' => fn($q) => $q->select('users.id', 'users.name', 'users.email'),
        ]);

        // Mapeia cargo_id → nome do cargo pra cada membro
        $membros = $setor->membros->map(function ($u) use ($setor) {
            $cargo = $u->pivot->cargo_id ? $setor->cargos->firstWhere('id', $u->pivot->cargo_id) : null;
            return [
                'id'           => $u->id,
                'name'         => $u->name,
                'email'        => $u->email,
                'active'       => (bool) $u->active,
                'cargo_id'     => $u->pivot->cargo_id,
                'cargo_nome'   => $cargo?->nome,
                'is_principal' => (bool) $u->pivot->is_principal,
            ];
        });

        // Lista de TODOS os users pra dropdown de adicionar membro/líder
        $todosUsers = User::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('Admin/Setores/Show', [
            'setor'           => $setor,
            'membros'         => $membros,
            'todos_users'     => $todosUsers,
            'catalogo_perms'  => Permissions::catalog(),
            'permission_keys' => $setor->permissoes->pluck('permission_key'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nome'      => ['required', 'string', 'max:100', 'unique:setores,nome'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'active'    => ['boolean'],
        ]);

        $setor = Setor::create([
            'nome'       => $data['nome'],
            'descricao'  => $data['descricao'] ?? null,
            'active'     => $data['active'] ?? true,
            'is_system'  => false,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.setores.show', $setor)
            ->with('success', "Setor '{$setor->nome}' criado.");
    }

    public function update(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'nome'      => ['required', 'string', 'max:100', "unique:setores,nome,{$setor->id}"],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'active'    => ['boolean'],
        ]);

        $setor->update($data);

        return back()->with('success', 'Setor atualizado.');
    }

    public function destroy(Setor $setor)
    {
        if ($setor->is_system) {
            return back()->with('error', "Setor '{$setor->nome}' é de sistema e não pode ser excluído.");
        }
        if ($setor->membros()->exists()) {
            return back()->with('error', "Setor '{$setor->nome}' tem membros — remova-os antes de excluir.");
        }

        $setor->delete();
        return redirect()->route('admin.setores.index')
            ->with('success', "Setor '{$setor->nome}' excluído.");
    }

    /**
     * Sincroniza a lista de permission keys do setor.
     * Body: { permissions: ['core.dashboard', 'mlb.vendas', ...] }
     */
    public function syncPermissoes(Request $request, Setor $setor)
    {
        $data = $request->validate([
            'permissions'   => ['array'],
            'permissions.*' => ['string', 'max:60'],
        ]);

        $keys = collect($data['permissions'] ?? [])
            ->unique()
            ->filter(fn($k) => Permissions::isValid($k))
            ->values();

        if ($setor->is_system) {
            // Setor de sistema sempre tem TODAS as permissões — não permite reduzir
            $keys = collect(Permissions::all());
        }

        // Diff: remove keys que saíram, adiciona as novas
        $existentes = $setor->permissoes()->pluck('permission_key');
        $aRemover   = $existentes->diff($keys);
        $aAdicionar = $keys->diff($existentes);

        if ($aRemover->isNotEmpty()) {
            $setor->permissoes()->whereIn('permission_key', $aRemover)->delete();
        }
        foreach ($aAdicionar as $k) {
            $setor->permissoes()->create(['permission_key' => $k]);
        }

        return back()->with('success', 'Permissões atualizadas.');
    }
}
