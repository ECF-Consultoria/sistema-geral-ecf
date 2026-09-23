<?php

namespace App\Http\Controllers;

use App\Models\Chamado;
use App\Models\ChamadoAnexo;
use App\Models\DevDemanda;
use App\Services\DevDemandas\ChamadoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Chamados — a Central de Chamados de quem pede ajuda (/chamados) e as ações
 * da equipe dev sobre um chamado (/dev/demandas/chamados/...).
 *
 * Toda autorização passa por ChamadoService (podeVer / podeAtuar / podeConverter).
 * Chamado de outra pessoa responde 404 — não confirma nem que ele existe.
 */
class ChamadoController extends Controller
{
    public function __construct(private ChamadoService $chamados) {}

    // Tipos aceitos em anexo: imagem, PDF e texto/planilha simples. Nada de SVG/HTML (XSS).
    private const ANEXOS_MIMES = 'jpg,jpeg,png,webp,gif,pdf,txt,csv,log,xlsx,docx';
    private const ANEXO_MAX_KB = 10240;

    // ═══ Central de Chamados (qualquer usuário logado) ═══

    public function index(Request $request)
    {
        $user = $request->user();

        $meus = Chamado::query()
            ->with(['responsavel:id,name', 'ultimaMensagemPublica'])
            ->where('solicitante_id', $user->id)
            ->orderByDesc('ultima_interacao_em')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Chamado $c) => $this->chamados->resumo($c, comoEquipe: false))
            ->all();

        return Inertia::render('Chamados/Index', [
            'chamados' => $meus,
            'devs'     => Chamado::devsDisponiveis(),
            'areas'    => DevDemanda::areasDisponiveis(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $dados = $request->validate([
            'tipo'               => ['required', Rule::in(array_keys(Chamado::TIPO_LABELS))],
            'area'               => ['nullable', 'string', Rule::in(DevDemanda::areasDisponiveis())],
            'responsavel_id'     => ['nullable', 'integer'],
            'titulo'             => ['required', 'string', 'max:150'],
            'descricao'          => ['required', 'string', 'max:10000'],
            'impacto'            => ['required', Rule::in(array_keys(Chamado::IMPACTO_LABELS))],
            'contexto_tentando'  => ['nullable', 'string', 'max:5000'],
            'contexto_aconteceu' => ['nullable', 'string', 'max:5000'],
            'contexto_esperado'  => ['nullable', 'string', 'max:5000'],
            'anexos'             => ['array', 'max:' . ChamadoService::MAX_ANEXOS],
            'anexos.*'           => ['file', 'mimes:' . self::ANEXOS_MIMES, 'max:' . self::ANEXO_MAX_KB],
        ], $this->mensagensDeAnexo() + [
            'area.in' => 'Escolha uma área da lista.',
        ]);

        // Duplo clique / reenvio: o mesmo pedido no último minuto devolve o chamado já aberto.
        $repetido = Chamado::query()
            ->where('solicitante_id', $user->id)
            ->where('titulo', $dados['titulo'])
            ->where('descricao', $dados['descricao'])
            ->where('created_at', '>=', now()->subMinute())
            ->first();
        if ($repetido) {
            return redirect()->route('chamados.show', $repetido)->with('success', "Seu ticket {$repetido->codigo} já foi aberto.");
        }

        try {
            $chamado = $this->chamados->abrir($user, $dados, $request->file('anexos', []));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['responsavel_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('chamados.show', $chamado)
            ->with('success', "Ticket {$chamado->codigo} aberto. Você acompanha tudo por aqui.");
    }

    public function show(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeVer($user, $chamado), 404);

        // A equipe trabalha o chamado dentro de /dev/demandas; esta tela é de quem abriu.
        if (! $this->chamados->ehSolicitante($user, $chamado)) {
            return redirect("/dev/demandas?aba=tickets&ticket={$chamado->id}");
        }

        return Inertia::render('Chamados/Show', [
            'chamado' => $this->chamados->detalhe($chamado, $user, comoSolicitante: true),
        ]);
    }

    /** Mensagem no chamado: quem abriu (sempre pública) ou a equipe (pública ou interna). */
    public function mensagem(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeVer($user, $chamado), 404);

        $dados = $request->validate([
            'texto'     => ['required', 'string', 'max:10000'],
            'interna'   => ['boolean'],
            'anexos'    => ['array', 'max:' . ChamadoService::MAX_ANEXOS],
            'anexos.*'  => ['file', 'mimes:' . self::ANEXOS_MIMES, 'max:' . self::ANEXO_MAX_KB],
        ], $this->mensagensDeAnexo());

        try {
            $m = $this->chamados->responder($chamado, $user, $dados['texto'], (bool) ($dados['interna'] ?? false), $request->file('anexos', []));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $m->ehInterna() ? 'Nota interna registrada.' : 'Mensagem enviada.');
    }

    public function reabrir(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeVer($user, $chamado), 404);
        $dados = $request->validate(['motivo' => ['nullable', 'string', 'max:5000']]);

        try {
            $this->chamados->reabrir($chamado, $user, $dados['motivo'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Ticket {$chamado->codigo} reaberto.");
    }

    public function cancelar(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeVer($user, $chamado), 404);
        $dados = $request->validate(['motivo' => ['nullable', 'string', 'max:5000']]);

        try {
            $this->chamados->cancelar($chamado, $user, $dados['motivo'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Ticket {$chamado->codigo} cancelado.");
    }

    public function anexo(Request $request, Chamado $chamado, ChamadoAnexo $anexo)
    {
        abort_unless($this->chamados->podeBaixar($request->user(), $chamado, $anexo), 404);

        return $this->chamados->arquivo($anexo);
    }

    // ═══ Equipe dev ═══

    public function status(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeAtuar($user, $chamado), 403);
        $dados = $request->validate(['status' => ['required', Rule::in(ChamadoService::STATUS_MANUAIS)]]);

        try {
            $this->chamados->mudarStatus($chamado, $user, $dados['status']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$chamado->codigo}: " . Chamado::STATUS_LABELS[$dados['status']] . '.');
    }

    public function transferir(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeAtuar($user, $chamado), 403);
        $dados = $request->validate([
            'responsavel_id' => ['required', 'integer'],
            'motivo'         => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->chamados->transferir($chamado, $user, (int) $dados['responsavel_id'], $dados['motivo'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$chamado->codigo} agora está com " . $chamado->fresh('responsavel')->responsavel->name . '.');
    }

    /** Cria a demanda dev a partir do chamado (idempotente). */
    public function converter(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeConverter($user, $chamado), 403);

        $dados = $request->validate([
            'prefixo'        => ['required', 'string', 'regex:/^[A-Za-z]{2,6}$/'],
            'titulo'         => ['required', 'string', 'max:255'],
            'area'           => ['nullable', 'string', 'max:60'],
            'escopo'         => ['nullable', 'string', 'max:5000'],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'prioridade'     => ['required', 'integer', Rule::in(array_keys(DevDemanda::PRIORIDADE_LABELS))],
            'data_entrada'   => ['required', 'date'],
            'prazo'          => ['nullable', 'date'],
            'observacoes'    => ['nullable', 'string', 'max:5000'],
        ], [
            'prioridade.required' => 'Defina a prioridade (P0 a P3).',
        ]);

        [$demanda, $criada] = $this->chamados->converterEmDemanda($chamado, $user, $dados);

        return back()->with('success', $criada
            ? "Demanda {$demanda->codigo} criada a partir do {$chamado->codigo}."
            : "O {$chamado->codigo} já tinha virado a demanda {$demanda->codigo}.");
    }

    public function resolver(Request $request, Chamado $chamado)
    {
        $user = $request->user();
        abort_unless($this->chamados->podeAtuar($user, $chamado), 403);
        $dados = $request->validate(['resolucao' => ['required', 'string', 'max:10000']], [
            'resolucao.required' => 'Conte para quem abriu o que foi feito.',
        ]);

        try {
            $this->chamados->resolver($chamado, $user, $dados['resolucao']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$chamado->codigo} resolvido. Quem abriu foi avisado.");
    }

    private function mensagensDeAnexo(): array
    {
        return [
            'anexos.max'     => 'Envie no máximo ' . ChamadoService::MAX_ANEXOS . ' arquivos.',
            'anexos.*.mimes' => 'Tipo de arquivo não aceito. Envie imagem, PDF, TXT, CSV, XLSX ou DOCX.',
            'anexos.*.max'   => 'Cada arquivo pode ter até 10 MB.',
        ];
    }
}
