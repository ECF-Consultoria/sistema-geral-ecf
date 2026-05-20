<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * CRUD de usuários. Substituiu a estrutura legacy de publication_role + setor
 * string por vínculos com Setor/Cargo via tabela pivot user_setores.
 *
 * Mantém role (admin/consultor/mentor) porque ainda é usada por Goal/PortfolioGoal/
 * PpaController/company_users.role.
 */
class UserController extends Controller
{
    public function index()
    {
        $mapUser = function (User $u) {
            $setores = $u->setores()->get(['setores.id', 'nome', 'slug'])->map(function ($s) {
                $cargoId = $s->pivot->cargo_id;
                $cargoNome = $cargoId ? Cargo::find($cargoId)?->nome : null;
                return [
                    'id'           => $s->id,
                    'nome'         => $s->nome,
                    'slug'         => $s->slug,
                    'cargo_id'     => $cargoId,
                    'cargo_nome'   => $cargoNome,
                    'is_principal' => (bool) $s->pivot->is_principal,
                ];
            });

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'email'           => $u->email,
                'role'            => $u->role,
                'is_admin'        => $u->role === 'admin',
                'active'          => $u->active,
                'phone'           => $u->phone,
                'setores'         => $setores,
                'lideres_setores' => $u->setoresLiderados()->pluck('setores.id'),
                'companies_count' => $u->companies_count,
                'created_by_name' => $u->createdBy?->name,
                'created_at'      => $u->created_at->format('d/m/Y'),
                'deleted_at'      => $u->deleted_at?->format('d/m/Y H:i'),
            ];
        };

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

        // Catálogo de setores + cargos pra dropdowns do form
        $setoresDisponiveis = Setor::where('active', true)
            ->with(['cargos' => fn($q) => $q->where('active', true)->orderBy('ordem')->orderBy('nome')])
            ->orderBy('nome')
            ->get()
            ->map(fn($s) => [
                'id'         => $s->id,
                'nome'       => $s->nome,
                'slug'       => $s->slug,
                'is_system'  => (bool) $s->is_system,
                'cargos'     => $s->cargos->map(fn($c) => ['id' => $c->id, 'nome' => $c->nome])->all(),
            ]);

        return Inertia::render('Users/Index', [
            'users'              => $users,
            'deletedUsers'       => $deletedUsers,
            'setoresDisponiveis' => $setoresDisponiveis,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateUser($request, isUpdate: false);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => $this->resolveSystemRole($isAdmin, $data['vinculos'] ?? []),
            'phone'      => $data['phone'] ?? null,
            'created_by' => $request->user()->id,
            'active'     => true,
        ]);

        $this->syncVinculos($user, $isAdmin, $data['vinculos'] ?? []);

        return back()->with('success', "Usuário {$user->name} criado.");
    }

    public function update(Request $request, User $user)
    {
        $data = $this->validateUser($request, isUpdate: true, userId: $user->id);

        $isAdmin = (bool) ($data['is_admin'] ?? false);

        $update = [
            'name'   => $data['name'],
            'email'  => $data['email'],
            'phone'  => $data['phone'] ?? null,
            'active' => $data['active'] ?? $user->active,
            'role'   => $this->resolveSystemRole($isAdmin, $data['vinculos'] ?? []),
        ];
        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        $this->syncVinculos($user, $isAdmin, $data['vinculos'] ?? []);

        return back()->with('success', 'Usuário atualizado.');
    }

    private function validateUser(Request $request, bool $isUpdate, ?int $userId = null): array
    {
        $rules = [
            'name'                    => ['required', 'string', 'max:255'],
            'email'                   => [
                'required', 'email',
                $isUpdate ? Rule::unique('users')->ignore($userId) : Rule::unique('users'),
            ],
            'phone'                   => ['nullable', 'string', 'max:20'],
            'is_admin'                => ['boolean'],
            'active'                  => ['boolean'],
            'vinculos'                => ['array'],
            'vinculos.*.setor_id'     => ['required', 'integer', 'exists:setores,id'],
            'vinculos.*.cargo_id'     => ['nullable', 'integer', 'exists:cargos,id'],
            'vinculos.*.is_principal' => ['boolean'],
        ];

        if ($isUpdate) {
            $rules['password'] = ['nullable', 'string', 'min:8', 'confirmed'];
        } else {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $request->validate($rules);
    }

    /**
     * Decide role do sistema (admin/consultor/mentor) baseado em:
     * - is_admin flag → role=admin
     * - se algum vínculo tem cargo "Mentor" → role=mentor
     * - senão → role=consultor (default)
     */
    private function resolveSystemRole(bool $isAdmin, array $vinculos): string
    {
        if ($isAdmin) return 'admin';

        foreach ($vinculos as $v) {
            if (empty($v['cargo_id'])) continue;
            $cargo = Cargo::find($v['cargo_id']);
            if ($cargo && $cargo->slug === 'mentor') return 'mentor';
        }
        return 'consultor';
    }

    /**
     * Sincroniza user_setores: remove vínculos que não vieram, atualiza os existentes,
     * cria os novos. Garante no máximo 1 is_principal.
     */
    private function syncVinculos(User $user, bool $isAdmin, array $vinculos): void
    {
        // Admin tem 1 vínculo fixo: setor Administração + cargo Admin
        if ($isAdmin) {
            $admin = Setor::where('slug', 'administracao')->first();
            if ($admin) {
                $cargoAdmin = Cargo::where('setor_id', $admin->id)->where('slug', 'admin')->first();
                $vinculos = [[
                    'setor_id'     => $admin->id,
                    'cargo_id'     => $cargoAdmin?->id,
                    'is_principal' => true,
                ]];
            }
        }

        // Garante no máx 1 is_principal — se o front mandou múltiplos, pega o primeiro
        $principalEncontrado = false;
        foreach ($vinculos as &$v) {
            if (!empty($v['is_principal'])) {
                if ($principalEncontrado) $v['is_principal'] = false;
                else $principalEncontrado = true;
            }
        }
        unset($v);
        // Se nenhum vínculo foi marcado como principal, marca o primeiro
        if (!$principalEncontrado && count($vinculos) > 0) {
            $vinculos[0]['is_principal'] = true;
        }

        DB::transaction(function () use ($user, $vinculos) {
            $setorIdsAtuais = DB::table('user_setores')->where('user_id', $user->id)->pluck('setor_id')->all();
            $setorIdsNovos  = array_column($vinculos, 'setor_id');

            // Remove os que sumiram
            $aRemover = array_diff($setorIdsAtuais, $setorIdsNovos);
            if (!empty($aRemover)) {
                DB::table('user_setores')
                    ->where('user_id', $user->id)
                    ->whereIn('setor_id', $aRemover)
                    ->delete();
            }

            // Upsert dos vínculos atuais
            foreach ($vinculos as $v) {
                DB::table('user_setores')->updateOrInsert(
                    ['user_id' => $user->id, 'setor_id' => $v['setor_id']],
                    [
                        'cargo_id'     => $v['cargo_id'] ?? null,
                        'is_principal' => !empty($v['is_principal']),
                        'assigned_at'  => now(),
                        'updated_at'   => now(),
                        'created_at'   => now(),
                    ]
                );
            }
        });
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        $user->delete();
        return back()->with('success', "Usuário \"{$nome}\" excluído.");
    }

    public function restore(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();
        return back()->with('success', "Usuário \"{$user->name}\" restaurado.");
    }

    public function forceDestroy(Request $request, int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['delete' => 'Você não pode excluir sua própria conta.']);
        }

        $nome = $user->name;
        $user->forceDelete();
        return back()->with('success', "Usuário \"{$nome}\" removido permanentemente.");
    }
}
