<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Ppa;
use App\Services\Ppa\PpaQuadroService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PpaController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Escopo geral: PPA de carteira. Os PPAs de Polos vivem na mesma tabela,
        // mas têm tela própria (PolosPpaController) — não se misturam aqui.
        $query = Ppa::with(['company', 'mentor'])
            ->doEscopo(Ppa::ESCOPO_GERAL)
            ->orderBy('created_at', 'desc');

        // Ajuste UAT 2026-07-07: qualquer user não-admin só vê PPAs que ELE
        // criou. Antes o filtro era só isMentor(), o que deixava Analistas
        // (consultor) verem PPAs de todos os estrategistas do sistema.
        if (! $user->isAdmin()) {
            $query->where('mentor_id', $user->id);
        }

        $ppas = $query->paginate(20)->through(fn($p) => [
            'id'               => $p->id,
            'title'            => $p->title,
            'company_name'     => $p->nomeEmpresa(),
            'company_id'       => $p->company_id,
            'mentor_name'      => $p->mentor->name,
            'status'           => $p->status,
            'due_date'         => $p->due_date?->format('d/m/Y'),
            'sent_at'          => $p->sent_at?->format('d/m/Y H:i'),
            'completed_at'     => $p->completed_at?->format('d/m/Y H:i'),
            'trello_board_url' => $p->trello_board_url,
            'workspace_token'  => $p->workspace_token,
            'tasks_count'      => $p->tasks()->count(),
            'tasks_done'       => $p->tasks()->where('status', 'done')->count(),
            'created_at'       => $p->created_at->format('d/m/Y'),
        ]);

        $companies = $user->isAdmin()
            ? Company::where('active', true)->get(['id', 'name'])
            : $user->estrategistaCompanies()->get(['companies.id', 'companies.name']);

        return Inertia::render('Ppa/Index', [
            'ppas'      => $ppas,
            'companies' => $companies,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id'       => 'required|exists:companies,id',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'actions'          => 'nullable|array',
            'due_date'         => 'nullable|date',
            'trello_board_url' => 'nullable|url',
        ]);

        Ppa::create([
            ...$data,
            'escopo'    => Ppa::ESCOPO_GERAL,
            'mentor_id' => $request->user()->id,
            'status'    => 'draft',
        ]);

        return back()->with('success', 'PPA criado com sucesso.');
    }

    public function update(Request $request, Ppa $ppa)
    {
        $data = $request->validate([
            'title'            => 'nullable|string|max:255',
            'description'      => 'nullable|string',
            'actions'          => 'nullable|array',
            'due_date'         => 'nullable|date',
            'status'           => 'required|in:draft,sent,completed',
            'trello_board_url' => 'nullable|url',
        ]);

        if ($data['status'] === 'sent' && $ppa->status !== 'sent') {
            $data['sent_at'] = now();
        }
        if ($data['status'] === 'completed' && $ppa->status !== 'completed') {
            $data['completed_at'] = now();
        }

        $ppa->update($data);

        return back()->with('success', 'PPA atualizado.');
    }

    public function destroy(Ppa $ppa)
    {
        $ppa->delete();
        return back()->with('success', 'PPA removido.');
    }

    // ── Kanban (admin / mentor) ───────────────────────────────────────────────

    public function kanban(Ppa $ppa, PpaQuadroService $quadro)
    {
        // O payload inteiro (cabeçalho, colunas, tarefas e resumo) vem do
        // service porque a MESMA tela serve os dois escopos — ver
        // `PolosPpaController::kanban()`, que chama exatamente isto.
        return Inertia::render('Ppa/Kanban', $quadro->payload($ppa));
    }

    // ── Workspace público (cliente) ───────────────────────────────────────────

    public function workspace(string $token)
    {
        // mlbEmpresa junto: o workspace público serve os dois escopos (o link é por token).
        $ppa = Ppa::with(['company', 'mlbEmpresa', 'tasks'])->where('workspace_token', $token)->firstOrFail();

        $tasks = $ppa->tasks->map(fn($t) => [
            'id'          => $t->id,
            'title'       => $t->title,
            'description' => $t->description,
            'status'      => $t->status,
            'order'       => $t->order,
        ]);

        return Inertia::render('Ppa/Workspace', [
            'ppa' => [
                'id'           => $ppa->id,
                'title'        => $ppa->title,
                'company_name' => $ppa->nomeEmpresa(),
                'token'        => $token,
            ],
            'tasks' => $tasks,
        ]);
    }

    // ── Gerar / revogar link do workspace ────────────────────────────────────

    public function generateWorkspaceLink(Ppa $ppa)
    {
        if (!$ppa->workspace_token) {
            $ppa->update(['workspace_token' => Str::uuid()->toString()]);
        }

        return back()->with([
            'success'       => 'Link do quadro gerado.',
            'workspace_url' => route('ppa.workspace', $ppa->workspace_token),
        ]);
    }
}
