<?php

namespace App\Http\Controllers;

use App\Models\MlbEmpresa;
use App\Models\Ppa;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * PPA Polos (quick 260805-dzu).
 *
 * Mesmo módulo PPA, recortado nas empresas do projeto POLOS. Compartilha a
 * tabela `ppas` (coluna `escopo`), as tarefas (`PpaTaskController`) e o
 * workspace público do cliente (`PpaController::workspace`, link por token) —
 * o que muda é a entidade alvo e quem enxerga.
 *
 * Alvo = `MlbEmpresa`, não `Company`: das empresas POLOS ativas quase nenhuma
 * tem `company_id` preenchido, então um PPA amarrado a Company nasceria vazio.
 *
 * Acesso: `permission:mlb.projetos` (mesma do Painel Polos), aplicado nas rotas.
 */
class PolosPpaController extends Controller
{
    /** Fases que compõem o projeto POLOS no Painel (mesmo recorte da tela de empresas). */
    private const FASES_POLOS = ['Aceite no Projeto', 'M0', 'M1', 'M2', 'M3', 'M4', 'Fechamento'];

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ppa::with(['mlbEmpresa', 'mentor'])
            ->doEscopo(Ppa::ESCOPO_POLOS)
            ->orderBy('created_at', 'desc');

        // Mesmo recorte do PPA de carteira: não-admin só vê o que ele criou.
        if (! $user->isAdmin()) {
            $query->where('mentor_id', $user->id);
        }

        $ppas = $query->paginate(20)->through(fn ($p) => [
            'id'               => $p->id,
            'title'            => $p->title,
            'company_name'     => $p->nomeEmpresa(),
            'company_id'       => $p->mlb_empresa_id,
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

        return Inertia::render('Polos/Ppa/Index', [
            'ppas'      => $ppas,
            'companies' => $this->empresasPolos(),
            'escopo'    => Ppa::ESCOPO_POLOS,
            'rotas'     => $this->rotas(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id'       => 'required|exists:mlb_empresas,id',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'actions'          => 'nullable|array',
            'due_date'         => 'nullable|date',
            'trello_board_url' => 'nullable|url',
        ]);

        // O front manda a empresa no mesmo campo do PPA de carteira; aqui ela é
        // uma MlbEmpresa. Só aceita empresa do projeto POLOS e não arquivada.
        $empresa = MlbEmpresa::findOrFail($data['company_id']);
        abort_unless($this->ehEmpresaPolos($empresa), 422, 'Empresa fora do projeto Polos.');

        unset($data['company_id']);

        Ppa::create([
            ...$data,
            'escopo'         => Ppa::ESCOPO_POLOS,
            'mlb_empresa_id' => $empresa->id,
            'mentor_id'      => $request->user()->id,
            'status'         => 'draft',
        ]);

        return back()->with('success', 'PPA criado com sucesso.');
    }

    public function update(Request $request, Ppa $ppa)
    {
        $this->garantirEscopo($ppa);

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
        $this->garantirEscopo($ppa);
        $ppa->delete();

        return back()->with('success', 'PPA removido.');
    }

    // ── Kanban ───────────────────────────────────────────────────────────────

    public function kanban(Ppa $ppa)
    {
        $this->garantirEscopo($ppa);
        $ppa->load(['mlbEmpresa', 'mentor', 'tasks']);

        $tasks = $ppa->tasks->map(fn ($t) => [
            'id'          => $t->id,
            'title'       => $t->title,
            'description' => $t->description,
            'status'      => $t->status,
            'order'       => $t->order,
        ]);

        return Inertia::render('Polos/Ppa/Kanban', [
            'ppa' => [
                'id'              => $ppa->id,
                'title'           => $ppa->title,
                'company_name'    => $ppa->nomeEmpresa(),
                'mentor_name'     => $ppa->mentor->name,
                'status'          => $ppa->status,
                'workspace_token' => $ppa->workspace_token,
                // Workspace do cliente é compartilhado com o PPA de carteira (rota por token).
                'workspace_url'   => $ppa->workspace_token
                    ? route('ppa.workspace', $ppa->workspace_token)
                    : null,
            ],
            'tasks'  => $tasks,
            'escopo' => Ppa::ESCOPO_POLOS,
            'rotas'  => $this->rotas(),
        ]);
    }

    public function generateWorkspaceLink(Ppa $ppa)
    {
        $this->garantirEscopo($ppa);

        if (! $ppa->workspace_token) {
            $ppa->update(['workspace_token' => Str::uuid()->toString()]);
        }

        return back()->with([
            'success'       => 'Link do quadro gerado.',
            'workspace_url' => route('ppa.workspace', $ppa->workspace_token),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** 404 se o PPA não for do escopo Polos (impede editar PPA de carteira por aqui). */
    private function garantirEscopo(Ppa $ppa): void
    {
        abort_unless($ppa->escopo === Ppa::ESCOPO_POLOS, 404);
    }

    private function ehEmpresaPolos(MlbEmpresa $empresa): bool
    {
        return $empresa->projeto() === 'POLOS' && ! $empresa->arquivada();
    }

    /**
     * Empresas selecionáveis: POLOS ativas, no formato {id, name} que a tela do
     * PPA já espera (o componente é o mesmo do módulo de carteira).
     */
    private function empresasPolos(): \Illuminate\Support\Collection
    {
        return MlbEmpresa::ativas()
            ->where(fn ($q) => $q->where('projeto', 'POLOS')
                ->orWhere(fn ($q2) => $q2->whereNull('projeto')->whereIn('fase', self::FASES_POLOS)))
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->nome])
            ->values();
    }

    /** Nomes de rota que a tela usa — o mesmo componente serve os dois escopos. */
    private function rotas(): array
    {
        return [
            'index'     => 'mlb.polos-ppa.index',
            'store'     => 'mlb.polos-ppa.store',
            'update'    => 'mlb.polos-ppa.update',
            'destroy'   => 'mlb.polos-ppa.destroy',
            'kanban'    => 'mlb.polos-ppa.kanban',
            'workspace' => 'mlb.polos-ppa.workspace.generate',
        ];
    }
}
