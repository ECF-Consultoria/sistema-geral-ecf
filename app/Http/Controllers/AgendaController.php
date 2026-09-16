<?php

namespace App\Http\Controllers;

use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingEventoGoogle;
use App\Services\Agenda\AgendaService;
use App\Services\Onboarding\AgendaGoogleService;
use App\Support\Onboarding\EscopoOnboarding;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Agenda (16/09/2026): a página completa (`/agenda`) e as rotas em JSON que
 * ela e o cartão da ficha do onboarding usam.
 *
 * ### Por que JSON e não props de página
 * Trocar de semana, criar um evento ou cancelar outro não pode recarregar a
 * ficha inteira do onboarding (mapeamento, fotografia, atividade) — e o drawer
 * precisa continuar aberto para mostrar o erro quando o Google recusa.
 *
 * ### Quem pode
 * A página é de qualquer pessoa logada: cada um vê o próprio Google e os
 * eventos dos onboardings que conduz. Tudo que toca um onboarding passa por
 * `EscopoOnboarding`, a mesma régua da ficha.
 */
class AgendaController extends Controller
{
    public function __construct(
        private AgendaService $agenda,
        private AgendaGoogleService $agendaDoOnboarding,
    ) {
    }

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $contexto = null;

        if ($request->filled('onboarding')) {
            $onboarding = Onboarding::with(['company:id,name', 'servico:id,nome'])->find((int) $request->query('onboarding'));

            if ($onboarding && EscopoOnboarding::permite($usuario, $onboarding)) {
                $contexto = [
                    'id'      => $onboarding->id,
                    'empresa' => $onboarding->company?->name,
                    'servico' => $onboarding->servico?->nome,
                    'url'     => route('onboarding.painel.show', $onboarding->id),
                ];
            }
        }

        return Inertia::render('Agenda/Index', [
            'conectado'   => GoogleToken::where('user_id', $usuario->id)->exists(),
            'usuario'     => ['id' => $usuario->id, 'nome' => $usuario->name, 'email' => $usuario->email],
            'contexto'    => $contexto,
            'onboardings' => $this->onboardingsParaAgendar($request),
        ]);
    }

    /** GET /agenda/eventos?inicio=Y-m-d&fim=Y-m-d[&onboarding=id] */
    public function eventos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'inicio'     => ['required', 'date_format:Y-m-d'],
            'fim'        => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'onboarding' => ['nullable', 'integer'],
        ]);

        $de = CarbonImmutable::parse($dados['inicio'], config('app.timezone'));
        $ate = CarbonImmutable::parse($dados['fim'], config('app.timezone'));

        // Uma consulta ao Google por pedido; mais de seis semanas é engano da tela.
        abort_if($de->diffInDays($ate, true) > 45, 422, 'Intervalo grande demais.');

        $contexto = null;

        if (! empty($dados['onboarding'])) {
            $contexto = Onboarding::findOrFail($dados['onboarding']);
            abort_unless(EscopoOnboarding::permite($request->user(), $contexto), 403, 'Você não tem acesso a este onboarding.');
        }

        return response()->json($this->agenda->periodo($request->user(), $de, $ate, $contexto));
    }

    /** GET /onboarding/{onboarding}/agenda/eventos?mes=Y-m — o cartão da ficha e o drawer. */
    public function doOnboarding(Request $request, Onboarding $onboarding): JsonResponse
    {
        $this->autorizar($request, $onboarding);

        $dados = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);

        $mes = isset($dados['mes'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $dados['mes'].'-01', config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));

        return response()->json($this->agenda->doOnboarding($onboarding, $request->user(), $mes));
    }

    /** POST /agenda/eventos — com onboarding, na agenda de quem o conduz; sem, na própria. */
    public function store(Request $request): JsonResponse
    {
        $dados = $this->validarEvento($request, criando: true);

        if (! empty($dados['onboarding_id'])) {
            $onboarding = Onboarding::findOrFail($dados['onboarding_id']);
            $this->autorizar($request, $onboarding);

            return $this->responder($this->agendaDoOnboarding->criar($onboarding, $dados, $request->user()), 201);
        }

        if ($dados['tipo'] !== OnboardingEventoGoogle::TIPO_OUTRO) {
            return $this->responder(['ok' => false, 'mensagem' => 'Escolha o cliente: este tipo de evento é de um onboarding.']);
        }

        return $this->responder($this->agenda->criarNaPropriaAgenda($request->user(), $dados), 201);
    }

    /** PATCH /agenda/eventos/{evento} — evento que o sistema criou para um onboarding. */
    public function update(Request $request, OnboardingEventoGoogle $evento): JsonResponse
    {
        $this->autorizar($request, $evento->onboarding);

        $dados = $this->validarEvento($request, criando: false);

        return $this->responder($this->agendaDoOnboarding->atualizar($evento, $dados, $request->user()));
    }

    /** DELETE /agenda/eventos/{evento} */
    public function destroy(Request $request, OnboardingEventoGoogle $evento): JsonResponse
    {
        $this->autorizar($request, $evento->onboarding);

        return $this->responder($this->agendaDoOnboarding->cancelar($evento, $request->user()));
    }

    /** PATCH /agenda/google/{eventId} — evento da própria agenda, organizado pela pessoa. */
    public function atualizarGoogle(Request $request, string $eventId): JsonResponse
    {
        $dados = $this->validarEvento($request, criando: false);

        return $this->responder($this->agenda->atualizarNaPropriaAgenda($request->user(), $eventId, $dados));
    }

    /** DELETE /agenda/google/{eventId} */
    public function cancelarGoogle(Request $request, string $eventId): JsonResponse
    {
        return $this->responder($this->agenda->cancelarNaPropriaAgenda($request->user(), $eventId));
    }

    // ─── Apoio ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validarEvento(Request $request, bool $criando): array
    {
        $dados = $request->validate([
            'onboarding_id'          => [$criando ? 'nullable' : 'prohibited', 'integer'],
            'tipo'                   => [$criando ? 'required' : 'nullable', Rule::in(OnboardingEventoGoogle::TIPOS_CRIAVEIS)],
            'titulo'                 => ['required', 'string', 'max:200'],
            'inicio'                 => ['required', 'date_format:Y-m-d\TH:i'],
            'duracao'                => ['required', 'integer', 'min:5', 'max:720'],
            'plataforma'             => ['required', Rule::in(OnboardingEventoGoogle::PLATAFORMAS)],
            // `link` é o link da reunião na plataforma "link" e o endereço no
            // encontro presencial.
            'link'                   => [
                'nullable', 'string', 'max:500',
                Rule::requiredIf(fn () => $request->input('plataforma') === OnboardingEventoGoogle::PLATAFORMA_LINK),
                Rule::when($request->input('plataforma') === OnboardingEventoGoogle::PLATAFORMA_LINK, ['url']),
            ],
            'descricao'              => ['nullable', 'string', 'max:5000'],
            'participantes'          => ['nullable', 'array', 'max:50'],
            'participantes.*.email'  => ['required', 'email', 'max:255'],
            'participantes.*.nome'   => ['nullable', 'string', 'max:255'],
            'organizador_id'         => ['nullable', 'integer'],
            'somente_data'           => ['nullable', 'boolean'],
        ], [
            'link.required'  => 'Cole o link da reunião.',
            'link.url'       => 'O link da reunião precisa começar com https://.',
            'inicio.required' => 'Escolha a data e o horário.',
            'titulo.required' => 'Dê um título ao evento.',
        ]);

        $dados['inicio'] = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $dados['inicio'], config('app.timezone'));
        $dados['participantes'] = $dados['participantes'] ?? [];
        $dados['somente_data'] = (bool) ($dados['somente_data'] ?? false);

        return $dados;
    }

    /** @param  array{ok: bool, mensagem: string}  $resultado */
    private function responder(array $resultado, int $statusSucesso = 200): JsonResponse
    {
        if (isset($resultado['evento']) && $resultado['evento'] instanceof OnboardingEventoGoogle) {
            $resultado['evento'] = ['id' => $resultado['evento']->id, 'google_event_id' => $resultado['evento']->google_event_id];
        }

        return response()->json($resultado, $resultado['ok'] ? $statusSucesso : 422);
    }

    private function autorizar(Request $request, ?Onboarding $onboarding): void
    {
        abort_unless(
            $onboarding && EscopoOnboarding::permite($request->user(), $onboarding),
            403,
            'Você não tem acesso a este onboarding.'
        );
    }

    /**
     * Os onboardings em andamento que a pessoa pode agendar, para o "Novo
     * evento" da página completa. Admin vê todos; os demais, os da carteira.
     *
     * @return array<int, array<string, mixed>>
     */
    private function onboardingsParaAgendar(Request $request): array
    {
        $usuario = $request->user();

        return Onboarding::query()
            ->with(['company:id,name', 'servico:id,nome'])
            ->where('status', Onboarding::STATUS_ANDAMENTO)
            ->when(! $usuario->isAdmin(), fn ($q) => $q->whereIn(
                'company_id',
                $usuario->companies()->pluck('companies.id')
            ))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Onboarding $o) => [
                'id'      => $o->id,
                'empresa' => $o->company?->name,
                'servico' => $o->servico?->nome,
            ])
            ->sortBy('empresa', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
