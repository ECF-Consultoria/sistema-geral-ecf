<?php

namespace App\Http\Controllers;

use App\Models\DevDemanda;
use App\Models\DevReuniao;
use App\Models\User;
use App\Services\DevDemandas\DemandasDevService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Demandas Dev — tarefas do time de desenvolvimento (/dev/demandas).
 *
 * Admin cadastra, edita e registra reuniões. O responsável por uma demanda
 * (mesmo sem ser admin) entra na tela, vê a própria fila e registra
 * atualização nas próprias demandas. Atualizações não se editam nem se apagam.
 */
class DevDemandaController extends Controller
{
    public function __construct(private DemandasDevService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->service->podeAcessar($user), 403, 'Você não tem demandas atribuídas.');

        $hoje   = now()->startOfDay();
        $linhas = $this->service->demandasVisiveis($user)
            ->map(fn (DevDemanda $d) => $this->service->serializar($d, $hoje))
            ->values()
            ->all();

        $gerencia = $this->service->podeGerenciar($user);

        // Áreas: as já usadas + as da lista original da planilha (sugestões do campo).
        $areas = collect(['Entrada', 'Onboarding', 'PPA', 'Metodologia', 'Produtos', 'Mapeamento', 'Planejamento',
            'Publicação', 'Fechamento', 'Contratos', 'Landing Pages', 'Institucional', 'Gestão Dev', 'Outros'])
            ->merge(DevDemanda::query()->whereNotNull('area')->distinct()->pluck('area'))
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Dev/Demandas/Index', [
            'demandas' => $linhas,
            'painel'   => $this->service->painel($linhas),
            'reunioes' => $this->service->reunioes($user),
            // Quem pode ser responsável: usuários ativos. Não-admin só precisa de si mesmo.
            'usuarios' => $gerencia
                ? User::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                : [['id' => $user->id, 'name' => $user->name]],
            'areas'    => $areas,
            'prefixos' => DevDemanda::PREFIXOS,
            'pode'     => ['gerenciar' => $gerencia],
            'eu'       => ['id' => $user->id, 'tem_demandas' => collect($linhas)->contains(fn ($l) => ($l['responsavel']['id'] ?? null) === $user->id)],
            'hoje'     => $hoje->toDateString(),
            // Histórico da demanda aberta no painel lateral (?demanda=ID).
            'detalhe'  => function () use ($request, $user) {
                $id = (int) $request->query('demanda');
                if (! $id) {
                    return null;
                }
                $demanda = DevDemanda::find($id);
                if (! $demanda || (! $user->isAdmin() && $demanda->responsavel_id !== $user->id)) {
                    return null;
                }

                return $this->service->detalhe($demanda);
            },
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $dados = $this->validarDemanda($request, criando: true);
        $prefixo = strtoupper($dados['prefixo']);
        unset($dados['prefixo']);

        $demanda = DB::transaction(fn () => DevDemanda::create($dados + [
            'codigo'     => DevDemanda::proximoCodigo($prefixo),
            'criado_por' => $request->user()->id,
        ]));

        return back()->with('success', "Demanda {$demanda->codigo} cadastrada.");
    }

    public function update(Request $request, DevDemanda $demanda)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $demanda->update($this->validarDemanda($request, criando: false));

        return back()->with('success', "Demanda {$demanda->codigo} atualizada.");
    }

    /** Nova linha no diário. Nunca altera linha antiga. */
    public function storeAtualizacao(Request $request, DevDemanda $demanda)
    {
        abort_unless($this->service->podeAtualizar($request->user(), $demanda), 403, 'Só o responsável ou um admin registra atualização.');

        $dados = $request->validate([
            'data'              => ['required', 'date'],
            'status'            => ['required', Rule::in(array_keys(DevDemanda::STATUS_LABELS))],
            'feito'             => ['nullable', 'string', 'max:5000'],
            'proxima_acao'      => ['nullable', 'string', 'max:1000'],
            'bloqueado'         => ['boolean'],
            'motivo_bloqueio'   => ['nullable', 'required_if:bloqueado,true', 'string', 'max:1000'],
            'previsao_revisada' => ['nullable', 'date'],
        ], [
            'motivo_bloqueio.required_if' => 'Diga o motivo do bloqueio ou de quem depende.',
        ]);

        $bloqueado = (bool) ($dados['bloqueado'] ?? false);

        $demanda->atualizacoes()->create([
            'user_id'           => $request->user()->id,
            'data'              => $dados['data'],
            'status'            => $dados['status'],
            'feito'             => $dados['feito'] ?? null,
            'proxima_acao'      => $dados['proxima_acao'] ?? null,
            'bloqueado'         => $bloqueado,
            'motivo_bloqueio'   => $bloqueado ? ($dados['motivo_bloqueio'] ?? null) : null,
            'previsao_revisada' => $dados['previsao_revisada'] ?? null,
        ]);

        return back()->with('success', "Atualização de {$demanda->codigo} registrada.");
    }

    public function storeReuniao(Request $request)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $dados = $this->validarReuniao($request);

        DB::transaction(function () use ($dados, $request) {
            $reuniao = DevReuniao::create(collect($dados)->except('demandas')->all() + ['criado_por' => $request->user()->id]);
            $reuniao->demandas()->sync($dados['demandas'] ?? []);
        });

        return back()->with('success', 'Reunião registrada.');
    }

    public function updateReuniao(Request $request, DevReuniao $reuniao)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $dados = $this->validarReuniao($request);

        DB::transaction(function () use ($dados, $reuniao) {
            $reuniao->update(collect($dados)->except('demandas')->all());
            $reuniao->demandas()->sync($dados['demandas'] ?? []);
        });

        return back()->with('success', 'Reunião atualizada.');
    }

    // ─── Validação ───────────────────────────────────────────────────────────

    private function validarDemanda(Request $request, bool $criando): array
    {
        return $request->validate([
            'prefixo'        => [$criando ? 'required' : 'prohibited', 'string', 'regex:/^[A-Za-z]{2,6}$/'],
            'titulo'         => ['required', 'string', 'max:255'],
            'area'           => ['nullable', 'string', 'max:60'],
            'escopo'         => ['nullable', 'string', 'max:5000'],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'prioridade'     => ['required', 'integer', Rule::in(array_keys(DevDemanda::PRIORIDADE_LABELS))],
            'data_entrada'   => ['required', 'date'],
            'prazo'          => ['nullable', 'date'],
            'observacoes'    => ['nullable', 'string', 'max:5000'],
        ], [
            'prefixo.regex' => 'O prefixo deve ter de 2 a 6 letras (ex.: DEV).',
        ]);
    }

    private function validarReuniao(Request $request): array
    {
        return $request->validate([
            'data'             => ['required', 'date'],
            'titulo'           => ['required', 'string', 'max:255'],
            'participantes'    => ['nullable', 'string', 'max:255'],
            'link_gravacao'    => ['nullable', 'url', 'max:500'],
            'link_transcricao' => ['nullable', 'url', 'max:500'],
            'decisoes'         => ['nullable', 'string', 'max:10000'],
            'duracao'          => ['nullable', 'string', 'max:20'],
            'demandas'         => ['array'],
            'demandas.*'       => ['integer', Rule::exists('dev_demandas', 'id')],
        ]);
    }
}
