<?php

namespace App\Http\Controllers;

use App\Models\MlbEmpresa;
use App\Models\PolosPendenciaConta;
use App\Models\PolosPendenciaContaAnexo;
use App\Services\Polos\PendenciasContaPolos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analista de Conta dos Polos (TKT-0035, DEV-58): a fila das pendências que os analistas de Ads
 * encaminham pela fila de revisão, e as ações sobre elas. As regras moram em
 * PendenciasContaPolos; aqui só acesso, validação e telas.
 *
 * Acesso: ver = admin, `mlb.analista_conta` ou quem abre a fila de revisão
 * (`mlb.faturamento_polos`); operar (status, concluir) = admin ou `mlb.analista_conta`;
 * encaminhar = admin ou o responsável da empresa.
 */
class PolosPendenciaContaController extends Controller
{
    /** Os mesmos tipos e o mesmo teto dos anexos de ticket. */
    private const ANEXOS_MIMES = 'jpg,jpeg,png,webp,gif,pdf,txt,csv,xlsx,docx';
    private const ANEXO_MAX_KB = 10240;

    /** Tipos que o navegador abre (o resto baixa). */
    private const MIMES_EM_LINHA = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'];

    public function __construct(private PendenciasContaPolos $pendencias) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && PendenciasContaPolos::podeVer($user), 403);

        return Inertia::render('Polos/AnalistaConta/Index', [
            'fila'       => $this->pendencias->fila(now()),
            'opcoes'     => PendenciasContaPolos::opcoes(),
            'podeOperar' => PendenciasContaPolos::podeOperar($user),
            'podeAcompanhar' => PendenciasContaPolos::podeAcompanhar($user),
            'eu'         => $user->id,
            // Abre já na pendência do aviso do sino (?pendencia=ID).
            'foco'       => $request->integer('pendencia') ?: null,
            // Vindo da fila de revisão: abre nas que a pessoa encaminhou (?minhas=1).
            'minhas'     => $request->boolean('minhas'),
        ]);
    }

    /**
     * Agenda semanal (fatia 2). `?semana=AAAA-MM-DD` = qualquer dia da semana; sem ela, a semana
     * atual (no fim de semana, a próxima). Quem não opera vê, mas não move cartão.
     */
    public function agenda(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && PendenciasContaPolos::podeVer($user), 403);

        $semana = PendenciasContaPolos::segundaDaAgenda(now());
        if ($request->filled('semana')) {
            try {
                $semana = Carbon::parse((string) $request->query('semana'), 'America/Sao_Paulo');
            } catch (\Throwable) {
                // Data inválida na URL: abre na semana padrão.
            }
        }

        return Inertia::render('Polos/AnalistaConta/Agenda', [
            'agenda'         => $this->pendencias->agenda($semana, now()),
            'podePlanejar'   => PendenciasContaPolos::podePlanejar($user),
            'podeAcompanhar' => PendenciasContaPolos::podeAcompanhar($user),
            'hoje'           => now()->setTimezone('America/Sao_Paulo')->toDateString(),
        ]);
    }

    /** Acompanhamento da coordenação e da liderança (fatia 3). `?dias=` 7, 30 (padrão) ou 90. */
    public function acompanhamento(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && PendenciasContaPolos::podeAcompanhar($user), 403);

        $dias = in_array((int) $request->query('dias'), [7, 30, 90], true) ? (int) $request->query('dias') : 30;

        return Inertia::render('Polos/AnalistaConta/Acompanhamento', [
            'dados'      => $this->pendencias->acompanhamento(now(), $dias),
            'podeOperar' => PendenciasContaPolos::podeOperar($user),
        ]);
    }

    /** Planeja (ou tira a data de) uma ou várias pendências — arrastar ou seleção em massa. */
    public function planejar(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PendenciasContaPolos::podePlanejar($user), 403, 'Só o Analista de Conta planeja o dia.');

        $dados = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:300'],
            'ids.*' => ['integer'],
            'data'  => ['nullable', 'date', 'after_or_equal:today', function ($attr, $valor, $falha) {
                if ($valor && Carbon::parse($valor)->isWeekend()) {
                    $falha('Escolha um dia de semana.');
                }
            }],
        ], [
            'data.after_or_equal' => 'Não dá para planejar num dia que já passou.',
        ]);

        $n = $this->pendencias->planejar($dados['ids'], $dados['data'] ?? null, $user);

        $quando = isset($dados['data']) ? 'para ' . Carbon::parse($dados['data'])->format('d/m') : 'sem data';

        return back()->with('success', $n === 0 ? 'Nada mudou.' : ($n === 1 ? "Pendência planejada {$quando}." : "{$n} pendências planejadas {$quando}."));
    }

    /** Prioridade: quem pediu pode subir para pedir antecipação; o Analista de Conta, ajustar. */
    public function prioridade(Request $request, PolosPendenciaConta $pendencia): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PendenciasContaPolos::podeComentar($user, $pendencia), 403);
        abort_if($pendencia->encerrada(), 422, 'Pendência encerrada não muda de prioridade.');

        $dados = $request->validate(['prioridade' => ['required', Rule::in(array_keys(PolosPendenciaConta::PRIORIDADES))]]);

        $this->pendencias->mudarPrioridade($pendencia, $user, $dados['prioridade']);

        return back()->with('success', 'Prioridade: ' . PolosPendenciaConta::PRIORIDADES[$dados['prioridade']] . '.');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && PendenciasContaPolos::podeVer($user), 403);

        $dados = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:mlb_empresas,id'],
            'tipo'       => ['required', Rule::in(array_keys(PolosPendenciaConta::TIPOS))],
            'descricao'  => ['required', 'string', 'max:5000'],
            'mlbs'       => ['nullable', 'string', 'max:3000'],
            'prioridade' => ['required', Rule::in(array_keys(PolosPendenciaConta::PRIORIDADES))],
            'prazo'      => ['required', 'date', 'after_or_equal:today'],
            'anexos'     => ['nullable', 'array', 'max:' . PendenciasContaPolos::MAX_ANEXOS],
            'anexos.*'   => ['file', 'mimes:' . self::ANEXOS_MIMES, 'max:' . self::ANEXO_MAX_KB],
        ], [
            'descricao.required'    => 'Descreva o que precisa ser feito.',
            'prazo.after_or_equal'  => 'O prazo não pode ser antes de hoje.',
        ]);

        $empresa = MlbEmpresa::with('implementacao:id,empresa_id,responsavel_id')->findOrFail($dados['empresa_id']);
        abort_unless(PendenciasContaPolos::podeEncaminhar($user, $empresa), 403, 'Esta empresa não está na sua carteira.');

        $this->pendencias->abrir($empresa, $user, $dados, $request->file('anexos', []));

        return back()->with('success', "Pendência de {$empresa->nome} encaminhada ao Analista de Conta.");
    }

    public function status(Request $request, PolosPendenciaConta $pendencia): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PendenciasContaPolos::podeOperar($user), 403, 'Só o Analista de Conta ou a coordenação muda o status.');

        $dados = $request->validate([
            'status' => ['required', Rule::in(PendenciasContaPolos::STATUS_MANUAIS)],
            'texto'  => ['nullable', 'string', 'max:5000', 'required_if:status,' . PolosPendenciaConta::STATUS_DEVOLVIDA],
        ], [
            'texto.required_if' => 'Diga o que falta para quem pediu.',
        ]);

        $this->pendencias->mudarStatus($pendencia, $user, $dados['status'], $dados['texto'] ?? null);

        return back()->with('success', 'Status: ' . PolosPendenciaConta::STATUS[$dados['status']] . '.');
    }

    public function concluir(Request $request, PolosPendenciaConta $pendencia): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PendenciasContaPolos::podeOperar($user), 403, 'Só o Analista de Conta ou a coordenação conclui.');

        $dados = $request->validate([
            'texto'          => ['required', 'string', 'max:5000'],
            'mlbs_alterados' => ['nullable', 'string', 'max:3000'],
        ], [
            'texto.required' => 'Conte o que foi feito.',
        ]);

        if ($pendencia->status === PolosPendenciaConta::STATUS_CONCLUIDA) {
            return back()->withErrors(['texto' => 'Esta pendência já está concluída.']);
        }

        $this->pendencias->concluir($pendencia, $user, $dados['texto'], $dados['mlbs_alterados'] ?? null);

        return back()->with('success', 'Pendência concluída. Quem pediu foi avisado.');
    }

    public function comentar(Request $request, PolosPendenciaConta $pendencia): RedirectResponse
    {
        $user = $request->user();
        abort_unless(PendenciasContaPolos::podeComentar($user, $pendencia), 403);

        $dados = $request->validate([
            'texto'    => ['required', 'string', 'max:5000'],
            'anexos'   => ['nullable', 'array', 'max:' . PendenciasContaPolos::MAX_ANEXOS],
            'anexos.*' => ['file', 'mimes:' . self::ANEXOS_MIMES, 'max:' . self::ANEXO_MAX_KB],
        ]);

        $this->pendencias->comentar($pendencia, $user, $dados['texto'], $request->file('anexos', []));

        return back()->with('success', 'Comentário registrado.');
    }

    public function anexo(Request $request, PolosPendenciaConta $pendencia, PolosPendenciaContaAnexo $anexo)
    {
        $user = $request->user();
        abort_unless($user && $this->podeVerHistorico($user) && $anexo->pendencia_id === $pendencia->id, 404);

        $disco = Storage::disk('local');
        abort_unless($disco->exists($anexo->caminho), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => $anexo->mime];

        return in_array($anexo->mime, self::MIMES_EM_LINHA, true)
            ? $disco->response($anexo->caminho, $anexo->nome_original, $headers)
            : $disco->download($anexo->caminho, $anexo->nome_original, $headers);
    }

    /** Histórico da empresa (JSON) — a gaveta da linha no Painel Polos busca ao abrir. */
    public function daEmpresa(Request $request, MlbEmpresa $empresa): JsonResponse
    {
        abort_unless($this->podeVerHistorico($request->user()), 403);

        return response()->json(['pendencias' => $this->pendencias->historico($empresa, now())]);
    }

    /**
     * O histórico aparece "na ficha da empresa para todos" (ticket): quem vê a fila e quem abre
     * o Painel Polos (`mlb.projetos`, o gate operacional do painel).
     */
    private function podeVerHistorico($user): bool
    {
        return $user && (PendenciasContaPolos::podeVer($user) || $user->hasPermission('mlb.projetos'));
    }
}
