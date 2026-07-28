<?php

namespace App\Http\Controllers;

use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Tela de controle de visibilidade dos módulos no menu (/dev/modulos).
 *
 * Exclusiva do Admin Dev (`users.is_dev`, Fase 97): é AQUI que o Dev decide o
 * que Admin e demais papéis enxergam no menu lateral. Por isso o gate de acesso
 * é `isAdminDev()` no servidor (defesa real da ferramenta) — mesmo o grupo de
 * rotas sendo `role:admin`, um admin comum NÃO pode abrir/alterar esta tela,
 * senão a premissa "ocultar coisas até dos Admins" cairia.
 *
 * Alterar `visivel_para_todos` dispara `Module::saved` → `ModuleRegistry::flush()`,
 * então o menu de todos reflete a mudança no próximo request, sem deploy.
 *
 * Conceder o cargo Dev NÃO é feito aqui (quick 260727-mx3): virou um cargo de
 * verdade, atribuído no cadastro do usuário em /users (UserController::syncCargoDev).
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

        return Inertia::render('Dev/Modulos', [
            'modulos'      => $modulos->values(),
            'totalOcultos' => $modulos->where('visivel_para_todos', false)->count(),
        ]);
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
