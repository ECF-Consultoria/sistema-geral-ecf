<?php

namespace App\Http\Controllers;

use App\Models\MlbEmpresa;
use App\Models\Ppa;
use App\Services\Ppa\PpaListaService;
use App\Services\Ppa\PpaQuadroService;
use Illuminate\Http\JsonResponse;
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

    public function index(Request $request, PpaListaService $lista)
    {
        $user = $request->user();

        // Mesma régua de ordenação do PPA de carteira (e da tela, que é a
        // mesma): em andamento, a fazer, concluído — ver
        // `Ppa::scopeOrdenadoPorAtencao`.
        // Mesmos filtros da lista de carteira — a tela é a mesma componente.
        $filtros = Ppa::filtrosDaLista($request->only('situacao', 'ordem'));

        $query = Ppa::with(['mlbEmpresa', 'mentor', 'tasks'])
            ->doEscopo(Ppa::ESCOPO_POLOS)
            ->comContagemDeTarefas()
            ->comUltimaAtividade()
            ->daSituacao($filtros['situacao'])
            ->ordenadoPorAtencao($filtros['ordem']);

        // Mesmo recorte do PPA de carteira: não-admin só vê o que ele criou.
        if (! $user->isAdmin()) {
            $query->where('mentor_id', $user->id);
        }

        $ppas = $query->paginate(20)->through(fn ($p) => $lista->linha($p));

        return Inertia::render('Polos/Ppa/Index', [
            'ppas'      => $ppas,
            'companies' => $this->empresasPolos(),
            'escopo'    => Ppa::ESCOPO_POLOS,
            'rotas'     => $this->rotas(),
            'filtros'   => $filtros,
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

    public function kanban(Ppa $ppa, PpaQuadroService $quadro)
    {
        $this->garantirEscopo($ppa);

        // Mesmo payload do PPA de carteira — a tela é a mesma componente. O
        // workspace do cliente por token continua compartilhado entre os dois
        // escopos (rota `ppa.workspace`), como sempre foi.
        return Inertia::render('Polos/Ppa/Kanban', [
            ...$quadro->payload($ppa),
            'escopo' => Ppa::ESCOPO_POLOS,
            'rotas'  => $this->rotas(),
        ]);
    }

    public function generateWorkspaceLink(Request $request, Ppa $ppa, PpaListaService $lista)
    {
        $this->garantirEscopo($ppa);

        if (! $ppa->workspace_token) {
            $ppa->update(['workspace_token' => Str::uuid()->toString()]);
        }

        // O botão "Compartilhar" da lista pede por axios e copia o link na
        // hora — uma resposta Inertia recarregaria a página inteira.
        if ($request->wantsJson()) {
            return response()->json($lista->compartilhamento($ppa));
        }

        return back()->with([
            'success'       => 'Link do quadro gerado.',
            'workspace_url' => route('ppa.workspace', $ppa->workspace_token),
        ]);
    }

    // ── Gaveta do Painel Polos ───────────────────────────────────────────────

    /**
     * Os PPAs de UMA empresa, para a gaveta que abre na seta da linha do Painel
     * Polos (JSON, buscado ao abrir — o payload do painel já é pesado e não
     * carrega PPA de 300 empresas para mostrar o de uma).
     *
     * Entram os dois escopos: o PPA Polos (`mlb_empresa_id`) e, quando a
     * empresa tem vínculo com `companies`, o PPA de carteira dessa Company.
     * Hoje quase nenhuma empresa polo tem o vínculo (learnings de Polos §3),
     * então na prática é o PPA Polos — mas o de carteira, se existir, é plano
     * da mesma empresa e esconder seria mentir que não há.
     *
     * Rascunho entra: quem olha é a equipe, não o cliente. E todos os planos da
     * empresa, não só os de quem está olhando — a lista do PPA recorta por
     * `mentor_id` para arrumar a tela de cada um, mas o quadro de qualquer
     * plano já abre para quem tem acesso; na gaveta a pergunta é "o que foi
     * planejado para esta empresa", e ela só tem resposta com todos.
     *
     * Ordem = a mesma régua da lista ({@see Ppa::scopeOrdenadoPorAtencao}).
     */
    public function daEmpresa(MlbEmpresa $empresa): JsonResponse
    {
        $ppas = Ppa::with('mentor:id,name')
            ->where(function ($q) use ($empresa) {
                $q->where('mlb_empresa_id', $empresa->id);
                if ($empresa->company_id) {
                    $q->orWhere('company_id', $empresa->company_id);
                }
            })
            ->comContagemDeTarefas()
            ->comUltimaAtividade()
            ->ordenadoPorAtencao()
            ->get()
            ->map(fn (Ppa $p) => [
                'id'          => $p->id,
                'titulo'      => $p->title,
                'status'      => $p->status,
                'escopo'      => $p->escopo === Ppa::ESCOPO_POLOS ? Ppa::ESCOPO_POLOS : Ppa::ESCOPO_GERAL,
                'total'       => (int) $p->tasks_count,
                'feitas'      => (int) $p->tasks_done_count,
                'fazendo'     => (int) $p->tasks_doing_count,
                'prazo'       => $p->due_date?->format('d/m/Y'),
                // Calculado aqui pelo mesmo motivo de `PortalPpaService::visao()`:
                // no navegador, o fuso de quem olha mudaria o dia do atraso.
                'prazo_dias'  => $p->diasAteOPrazo(),
                'responsavel' => $p->mentor?->name,
                'criado_em'   => $p->created_at?->format('d/m/Y'),
                'atualizado_em' => $p->atualizadoEm()?->format('d/m/Y'),
                // Cada escopo abre no seu quadro: o de Polos exige o escopo certo
                // (`garantirEscopo`) e daria 404 para um PPA de carteira.
                'url'         => $p->escopo === Ppa::ESCOPO_POLOS
                    ? route('mlb.polos-ppa.kanban', $p)
                    : route('ppa.kanban', $p),
            ])
            ->values();

        return response()->json(['ppas' => $ppas]);
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
