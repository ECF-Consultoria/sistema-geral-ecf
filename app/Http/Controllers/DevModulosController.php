<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * MVP Cargo Dev — tela de controle de visibilidade dos módulos no menu (/dev/modulos).
 *
 * Exclusiva do Admin Dev (`users.is_dev`, Fase 97): é AQUI que o Dev decide o
 * que Admin e demais papéis enxergam no menu lateral. Por isso o gate de acesso
 * é `isAdminDev()` no servidor (defesa real da ferramenta) — mesmo o grupo de
 * rotas sendo `role:admin`, um admin comum NÃO pode abrir/alterar esta tela,
 * senão a premissa "ocultar coisas até dos Admins" cairia.
 *
 * Alterar `visivel_para_todos` dispara `Module::saved` → `ModuleRegistry::flush()`,
 * então o menu de todos reflete a mudança no próximo request, sem deploy.
 */
class DevModulosController extends Controller
{
    /** Lista os módulos (com item de menu mapeável) agrupados, com o estado de visibilidade atual. */
    public function index(Request $request): \Inertia\Response
    {
        abort_unless($request->user()?->isAdminDev(), 403);

        $modulos = Module::query()
            ->whereNotNull('route_prefix') // só os que mapeiam para um item de menu real
            ->orderBy('grupo')
            ->orderBy('name')
            ->get(['id', 'key', 'name', 'grupo', 'route_prefix', 'stage', 'visivel_para_todos'])
            ->map(fn (Module $m) => [
                'id'                 => $m->id,
                'key'                => $m->key,
                'name'               => $m->name,
                'grupo'              => $m->grupo ?? 'Sem grupo',
                'route_prefix'       => $m->route_prefix,
                'stage'              => $m->stage,
                'visivel_para_todos' => (bool) $m->visivel_para_todos,
            ]);

        // Cargo Dev — lista de usuários com o estado atual, para o Dev promover/
        // rebaixar na hora (self-service). Só um Dev enxerga e mexe nisto.
        $usuarios = User::query()
            ->orderByDesc('is_dev')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_dev'])
            ->map(fn (User $u) => [
                'id'     => $u->id,
                'name'   => $u->name,
                'email'  => $u->email,
                'role'   => $u->role,
                'is_dev' => (bool) $u->is_dev,
            ]);

        return Inertia::render('Dev/Modulos', [
            'modulos'      => $modulos->values(),
            'totalOcultos' => $modulos->where('visivel_para_todos', false)->count(),
            'usuarios'     => $usuarios->values(),
            'meuId'        => $request->user()->id,
        ]);
    }

    /**
     * Liga/desliga o cargo Dev (`users.is_dev`) de um usuário — self-service do Dev.
     *
     * `is_dev` é guarded (fora de `$fillable`, Fase 97) → gravado via `forceFill`.
     * Trava anti-lockout: o Dev não pode remover o próprio cargo (senão perderia
     * o acesso a esta tela e não conseguiria voltar).
     */
    public function updateUsuarioDev(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->isAdminDev(), 403);

        $data = $request->validate([
            'is_dev' => 'required|boolean',
        ]);

        if (! $data['is_dev'] && $user->id === $request->user()->id) {
            return back()->with('error', 'Você não pode remover o seu próprio cargo Dev.');
        }

        $user->forceFill(['is_dev' => $data['is_dev']])->save();

        $estado = $data['is_dev'] ? 'agora é Dev' : 'não é mais Dev';

        return back()->with('success', "{$user->name} {$estado}.");
    }

    /** Liga/desliga a visibilidade de um módulo para os papéis não-Dev. */
    public function updateVisibilidade(Request $request, Module $module): RedirectResponse
    {
        abort_unless($request->user()?->isAdminDev(), 403);

        $data = $request->validate([
            'visivel_para_todos' => 'required|boolean',
        ]);

        $module->update(['visivel_para_todos' => $data['visivel_para_todos']]);

        $estado = $data['visivel_para_todos'] ? 'visível para todos' : 'oculto (só Dev)';

        return back()->with('success', "Módulo \"{$module->name}\" agora está {$estado}.");
    }
}
