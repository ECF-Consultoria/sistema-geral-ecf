<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    private const PUB_ROLES = ['gestor', 'lider', 'publicador', 'analista'];

    /**
     * Setor → role interna do sistema.
     * - "Mentor" (legado) → mentor
     * - qualquer outro   → consultor
     */
    private const SETOR_ROLE_MAP = [
        'Mentor' => 'mentor',
    ];

    public function index()
    {
        $mapUser = fn($u) => [
            'id'                      => $u->id,
            'name'                    => $u->name,
            'email'                   => $u->email,
            'role'                    => $u->role,
            'is_admin'                => $u->role === 'admin',
            'setor'                   => $u->setor,
            'active'                  => $u->active,
            'phone'                   => $u->phone,
            'publication_role'        => $u->publication_role,
            'publication_meta'        => $u->publication_meta ?? 220,
            'publication_permissions' => $u->publication_permissions,
            'companies_count'         => $u->companies_count,
            'created_by_name'         => $u->createdBy?->name,
            'created_at'              => $u->created_at->format('d/m/Y'),
            'deleted_at'              => $u->deleted_at?->format('d/m/Y H:i'),
        ];

        $users = User::with('createdBy')
            ->withCount('companies')
            ->orderBy('name')
            ->get()
            ->map($mapUser);

        $deletedUsers = User::onlyTrashed()
            ->with('createdBy')
            ->withCount('companies')
            ->orderBy('name')
            ->get()
            ->map($mapUser);

        $setoresDb = User::whereNotNull('setor')
            ->where('setor', '!=', '')
            ->where('role', '!=', 'admin')
            ->distinct()
            ->pluck('setor')
            ->sort()
            ->values();

        return Inertia::render('Users/Index', [
            'users'        => $users,
            'deletedUsers' => $deletedUsers,
            'setoresDb'    => $setoresDb,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'email'                   => 'required|email|unique:users',
            'password'                => 'required|string|min:8|confirmed',
            'is_admin'                => 'boolean',
            'setor'                   => 'nullable|string|max:100',
            'phone'                   => 'nullable|string|max:20',
            'publication_role'        => ['nullable', Rule::in(self::PUB_ROLES)],
            'publication_meta'        => 'nullable|integer|min:0|max:9999',
            'publication_permissions' => 'nullable|array',
            'publication_permissions.*' => ['string', Rule::in(\App\Models\User::ALL_PUB_PERMISSIONS)],
        ]);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        if ($isAdmin) {
            $payload = [
                'name'                    => $data['name'],
                'email'                   => $data['email'],
                'password'                => Hash::make($data['password']),
                'role'                    => 'admin',
                'setor'                   => null,
                'phone'                   => $data['phone'] ?? null,
                'publication_role'        => null,
                'publication_meta'        => null,
                'publication_permissions' => null,
                'created_by'              => $request->user()->id,
                'active'                  => true,
            ];
        } else {
            $role = self::SETOR_ROLE_MAP[$data['setor'] ?? ''] ?? 'consultor';
            $payload = [
                'name'                    => $data['name'],
                'email'                   => $data['email'],
                'password'                => Hash::make($data['password']),
                'role'                    => $role,
                'setor'                   => $data['setor'] ?? null,
                'phone'                   => $data['phone'] ?? null,
                'publication_role'        => $data['publication_role'] ?? null,
                'publication_meta'        => $data['publication_meta'] ?? 220,
                'publication_permissions' => $data['publication_permissions'] ?? null,
                'created_by'              => $request->user()->id,
                'active'                  => true,
            ];
        }

        $user = User::create($payload);

        if (!$isAdmin && !empty($data['publication_role']) && isset($data['publication_meta'])) {
            $this->salvarMetaHistorico($user->id, $data['publication_meta'] ?? 220);
        }

        return back()->with('success', "Usuário {$user->name} criado com sucesso.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'email'                   => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'is_admin'                => 'boolean',
            'setor'                   => 'nullable|string|max:100',
            'phone'                   => 'nullable|string|max:20',
            'active'                  => 'boolean',
            'password'                => 'nullable|string|min:8|confirmed',
            'publication_role'        => ['nullable', Rule::in(self::PUB_ROLES)],
            'publication_meta'        => 'nullable|integer|min:0|max:9999',
            'publication_permissions' => 'nullable|array',
            'publication_permissions.*' => ['string', Rule::in(\App\Models\User::ALL_PUB_PERMISSIONS)],
        ]);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $update = [
            'name'   => $data['name'],
            'email'  => $data['email'],
            'phone'  => $data['phone'] ?? null,
            'active' => $data['active'] ?? $user->active,
        ];

        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        if ($isAdmin) {
            $update['role']                    = 'admin';
            $update['setor']                   = null;
            $update['publication_role']        = null;
            $update['publication_meta']        = null;
            $update['publication_permissions'] = null;
        } else {
            $update['role']                    = self::SETOR_ROLE_MAP[$data['setor'] ?? ''] ?? 'consultor';
            $update['setor']                   = $data['setor'] ?? null;
            $update['publication_role']        = $data['publication_role'] ?? null;
            $update['publication_meta']        = $data['publication_meta'] ?? 220;
            $update['publication_permissions'] = $data['publication_permissions'] ?? null;
        }

        $user->update($update);

        if (!$isAdmin && !empty($data['publication_role']) && isset($data['publication_meta'])) {
            $this->salvarMetaHistorico($user->id, $data['publication_meta'] ?? 220);
        }

        return back()->with('success', 'Usuário atualizado com sucesso.');
    }

    private function salvarMetaHistorico(int $userId, int $meta): void
    {
        DB::table('mlb_meta_historico')->upsert(
            [
                'user_id'    => $userId,
                'mes_inicio' => now()->format('Y-m'),
                'meta'       => $meta,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['user_id', 'mes_inicio'],
            ['meta', 'updated_at']
        );
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        // Soft delete — mantém registros vinculados intactos e permite restauração
        $user->delete();
        return back()->with('success', "Usuário \"{$nome}\" excluído. Os registros dele foram preservados.");
    }

    public function restore(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();
        return back()->with('success', "Usuário \"{$user->name}\" restaurado com sucesso.");
    }

    public function forceDestroy(Request $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        $user->forceDelete();
        return back()->with('success', "Usuário \"{$nome}\" removido permanentemente do sistema.");
    }

    /**
     * Remove uma opção customizada de setor: zera o setor de todos os usuários
     * que usavam esse valor.
     */
    public function destroyOpcaoSetor(Request $request)
    {
        $data = $request->validate([
            'valor' => 'required|string|max:100',
        ]);

        $affected = User::where('setor', $data['valor'])
            ->where('role', '!=', 'admin')
            ->update(['setor' => null]);

        return back()->with(
            'success',
            "Setor \"{$data['valor']}\" removido ({$affected} usuário" . ($affected !== 1 ? 's' : '') . ' atualizado' . ($affected !== 1 ? 's' : '') . ')'
        );
    }
}
