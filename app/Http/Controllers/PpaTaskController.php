<?php

namespace App\Http\Controllers;

use App\Models\Ppa;
use App\Models\PpaTask;
use Illuminate\Http\Request;

class PpaTaskController extends Controller
{
    // ── Admin / Mentor: criar tarefa ─────────────────────────────────────────

    public function store(Request $request, Ppa $ppa)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'nullable|in:todo,doing,done',
        ]);

        $maxOrder = $ppa->tasks()->where('status', $data['status'] ?? 'todo')->max('order') ?? -1;

        $ppa->tasks()->create([
            ...$data,
            'status'     => $data['status'] ?? 'todo',
            'order'      => $maxOrder + 1,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Tarefa adicionada.');
    }

    // ── Admin / Mentor: atualizar tarefa ──────────────────────────────────────

    public function update(Request $request, PpaTask $task)
    {
        $data = $request->validate([
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status'      => 'nullable|in:todo,doing,done',
        ]);

        $task->update($data);

        return back()->with('success', 'Tarefa atualizada.');
    }

    // ── Admin / Mentor: remover tarefa ────────────────────────────────────────

    public function destroy(PpaTask $task)
    {
        $task->delete();
        return back()->with('success', 'Tarefa removida.');
    }

    // ── Público (cliente): mover tarefa ──────────────────────────────────────
    // Valida pelo workspace_token do PPA — sem autenticação

    public function clientUpdate(Request $request, string $token, PpaTask $task)
    {
        // Verifica que a tarefa pertence ao PPA do token
        $ppa = Ppa::where('workspace_token', $token)->firstOrFail();
        abort_if($task->ppa_id !== $ppa->id, 403);

        $data = $request->validate([
            'status' => 'required|in:todo,doing,done',
        ]);

        $task->update($data);

        return response()->json(['ok' => true, 'status' => $task->status]);
    }
}
