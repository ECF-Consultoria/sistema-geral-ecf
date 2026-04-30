<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('createdBy')
            ->where('role', '!=', 'admin')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'active' => $u->active,
                'phone' => $u->phone,
                'companies_count' => $u->companies()->count(),
                'created_by_name' => $u->createdBy?->name,
                'created_at' => $u->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Users/Index', ['users' => $users]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role'     => ['required', Rule::in(['consultor', 'mentor'])],
            'phone'    => 'nullable|string|max:20',
        ]);

        $user = User::create([
            ...$data,
            'password'   => Hash::make($data['password']),
            'created_by' => $request->user()->id,
            'active'     => true,
        ]);

        return back()->with('success', "Usuário {$user->name} criado com sucesso.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role'     => ['required', Rule::in(['consultor', 'mentor'])],
            'phone'    => 'nullable|string|max:20',
            'active'   => 'boolean',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return back()->with('success', 'Usuário atualizado com sucesso.');
    }

    public function destroy(User $user)
    {
        $user->update(['active' => false]);
        return back()->with('success', 'Usuário desativado.');
    }
}

