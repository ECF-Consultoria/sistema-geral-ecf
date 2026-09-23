<?php

namespace App\Http\Controllers;

use App\Models\Chamado;
use App\Models\DevDemanda;
use App\Models\DevReuniao;
use App\Models\User;
use App\Models\GoogleToken;
use App\Services\DevDemandas\ChamadoService;
use App\Services\DevDemandas\DemandasDevService;
use App\Services\DevDemandas\ReuniaoDevGoogleService;
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
    public function __construct(
        private DemandasDevService $service,
        private ReuniaoDevGoogleService $google,
        private ChamadoService $chamados,
    ) {}

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
        $equipe = Chamado::ehEquipe($user);

        $areas = DevDemanda::areasDisponiveis();

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
            // Caixa de chamados — só para a equipe dev (admin ou cargo Dev).
            'equipe'   => $equipe,
            'chamados' => $equipe
                ? $this->chamados->daEquipe($user)
                    ->with(['responsavel:id,name', 'demanda:id,codigo', 'ultimaMensagemPublica'])
                    ->orderByDesc('ultima_interacao_em')->orderByDesc('id')
                    ->get()
                    ->map(fn (Chamado $c) => $this->chamados->resumo($c, comoEquipe: true))
                    ->values()
                    ->all()
                : [],
            'devs'     => $equipe ? Chamado::devsDisponiveis() : [],
            // Ticket aberto no painel lateral (?ticket=ID) — só se esta pessoa pode atuar nele.
            'chamado_detalhe' => function () use ($request, $user) {
                $id = (int) $request->query('ticket');
                $chamado = $id ? Chamado::find($id) : null;

                return $chamado && $this->chamados->podeAtuar($user, $chamado)
                    ? $this->chamados->detalhe($chamado, $user)
                    : null;
            },
            // Agendar com convite usa a agenda de quem agenda — a tela oferece conectar antes de falhar.
            'google'   => [
                'conectado'    => GoogleToken::where('user_id', $user->id)->exists(),
                'conectar_url' => route('google.connect', ['retorno' => '/dev/demandas']),
            ],
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

    /**
     * Agenda (com convite no Google Agenda + Meet) ou registra uma reunião que já
     * aconteceu (sem convite). Se o Google recusar, nada é gravado.
     */
    public function storeReuniao(Request $request)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $convite = $request->boolean('convite');
        $dados = $this->validarReuniao($request, $convite);

        try {
            $reuniao = $this->google->agendar($request->user(), $dados, $convite);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reuniao->temConvite()
            ? 'Reunião agendada. O Google enviou o convite aos participantes.'
            : 'Reunião registrada.');
    }

    /** Edita a reunião; se ela tem convite, data, hora, pauta e participantes vão ao Google. */
    public function updateReuniao(Request $request, DevReuniao $reuniao)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        $dados = $this->validarReuniao($request, $reuniao->temConvite() && ! $reuniao->cancelada_em);

        try {
            $this->google->atualizar($reuniao, $dados);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reuniao->temConvite() ? 'Reunião atualizada. O Google avisou os participantes.' : 'Reunião atualizada.');
    }

    /** Cancela a reunião e o convite (o Google avisa os participantes). */
    public function cancelarReuniao(Request $request, DevReuniao $reuniao)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        try {
            $this->google->cancelar($reuniao);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reunião cancelada. O Google avisou os participantes.');
    }

    /** Puxa gravação, transcrição e anotações do Gemini dos anexos do evento. */
    public function buscarGravacao(Request $request, DevReuniao $reuniao)
    {
        abort_unless($this->service->podeGerenciar($request->user()), 403);

        try {
            $novos = $this->google->buscarAnexos($reuniao);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if (! $novos) {
            return back()->with('aviso', 'O Google ainda não anexou gravação nem transcrição a esse evento. Costuma levar alguns minutos depois do fim da reunião — ou cole os links à mão.');
        }

        $nomes = ['link_gravacao' => 'gravação', 'link_transcricao' => 'transcrição', 'link_resumo' => 'anotações do Gemini'];

        return back()->with('success', 'Encontrado no Google: ' . implode(', ', array_map(fn ($c) => $nomes[$c], $novos)) . '.');
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

    private function validarReuniao(Request $request, bool $comConvite): array
    {
        return $request->validate([
            'titulo'           => ['required', 'string', 'max:255'],
            'modulo'           => ['nullable', 'string', 'max:60'],
            'pauta'            => ['nullable', 'string', 'max:5000'],
            'data'             => ['required', 'date_format:Y-m-d'],
            // Convite exige horário; reunião antiga registrada à mão pode ficar só com o dia.
            'hora'             => [$comConvite ? 'required' : 'nullable', 'date_format:H:i'],
            'duracao'          => ['required', 'integer', 'min:15', 'max:480'],
            'participantes'    => ['array'],
            'participantes.*'  => ['integer', Rule::exists('users', 'id')],
            'demandas'         => ['array'],
            'demandas.*'       => ['integer', Rule::exists('dev_demandas', 'id')],
            'decisoes'         => ['nullable', 'string', 'max:10000'],
            'link_gravacao'    => ['nullable', 'url', 'max:500'],
            'link_transcricao' => ['nullable', 'url', 'max:500'],
            'link_resumo'      => ['nullable', 'url', 'max:500'],
        ], [
            'hora.required' => 'Informe o horário — o convite do Google precisa dele.',
        ]);
    }
}
