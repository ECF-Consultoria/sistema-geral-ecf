<?php

namespace App\Http\Controllers;

use App\Models\MlbEmpresa;
use App\Models\PolosAgendaRevisao;
use App\Models\User;
use App\Services\Polos\FilaRevisaoPolos;
use App\Support\CustId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fila de revisão quinzenal dos Polos (TKT-0027, DEV-53): "Minha fila de hoje" e "Configurar
 * agenda". A regra mora em FilaRevisaoPolos; aqui só acesso, recorte por analista e telas.
 *
 * Acesso: o mesmo do Faturamento Polos (admin ou permissão mlb.faturamento_polos) — a fila
 * mostra faturamento e bônus e grava comentário lá. Admin troca o analista; os demais veem
 * e mexem só na própria carteira.
 */
class PolosFilaController extends Controller
{
    public function __construct(private FilaRevisaoPolos $fila) {}

    public function index(Request $request): Response
    {
        $user = $this->checkAcesso($request);

        $analistas  = $this->fila->analistas();
        $analistaId = $this->analistaDaTela($request, $analistas);
        [$faturamento, $aviso] = $this->faturamento();

        return Inertia::render('Polos/Fila/Index', [
            'analistas'        => $this->analistasVisiveis($user, $analistas),
            'analistaId'       => $analistaId,
            'podeTrocar'       => $user->isAdmin(),
            'fila'             => $this->fila->montar($analistaId, now(), $faturamento),
            'avisoFaturamento' => $aviso,
        ]);
    }

    public function agenda(Request $request): Response
    {
        $user = $this->checkAcesso($request);

        $analistas  = $this->fila->analistas();
        $analistaId = $this->analistaDaTela($request, $analistas);
        [$faturamento, $aviso] = $this->faturamento();

        $empresas = $this->fila->carteira($analistaId);
        $cards    = $this->fila->cards($empresas, now(), $faturamento);
        $dias     = PolosAgendaRevisao::whereIn('mlb_empresa_id', $empresas->pluck('id'))->pluck('dia_ciclo', 'mlb_empresa_id');

        $linhas = $empresas->map(fn (MlbEmpresa $e) => [
            'id'          => $e->id,
            'nome'        => $e->nome,
            'polo'        => $cards[$e->id]['polo'],
            'fase'        => $e->fase,
            'faturamento' => $cards[$e->id]['faturamento'],
            'pct'         => $cards[$e->id]['pct'],
            'dia_ciclo'   => isset($dias[$e->id]) ? (int) $dias[$e->id] : null,
        ])->values()->all();

        return Inertia::render('Polos/Fila/Agenda', [
            'analistas'        => $this->analistasVisiveis($user, $analistas),
            'analistaId'       => $analistaId,
            'podeTrocar'       => $user->isAdmin(),
            'empresas'         => $linhas,
            'dias'             => FilaRevisaoPolos::DIAS,
            'avisoFaturamento' => $aviso,
        ]);
    }

    public function salvarAgenda(Request $request): RedirectResponse
    {
        $user = $this->checkAcesso($request);

        $dados = $request->validate([
            'analista_id' => ['required', 'integer'],
            'dias'        => ['present', 'array'],
            'dias.*'      => ['nullable', 'integer', 'min:0', 'max:9'],
        ]);

        $analistaId = (int) $dados['analista_id'];
        abort_unless($user->isAdmin() || $analistaId === $user->id, 403, 'Só o próprio analista ou um admin muda esta agenda.');

        $r = $this->fila->salvarAgenda($analistaId, $dados['dias'], $user);

        return back()->with('success', $r['alteradas'] > 0
            ? "Agenda salva: {$r['alteradas']} empresa(s) alterada(s)."
            : 'Agenda salva. Nada mudou.');
    }

    public function marcar(Request $request): RedirectResponse
    {
        $user = $this->checkAcesso($request);

        $dados = $request->validate([
            'empresa_id' => ['required', 'integer', 'exists:mlb_empresas,id'],
            'comentario' => ['nullable', 'string', 'max:5000'],
        ]);

        $empresa = MlbEmpresa::with('implementacao:id,empresa_id,responsavel_id')->findOrFail($dados['empresa_id']);
        abort_unless(FilaRevisaoPolos::podeAtuar($user, $empresa), 403, 'Esta empresa não está na sua carteira.');

        $texto = trim((string) ($dados['comentario'] ?? ''));
        if ($texto !== '' && CustId::normaliza((string) $empresa->cust_id) === '') {
            return back()->withErrors(['comentario' => 'Empresa sem Cust ID: o comentário não tem onde ser gravado no Faturamento Polos.']);
        }

        $this->fila->marcar($empresa, $user, $texto, now());

        return back()->with('success', $texto !== '' ? 'Comentário salvo e revisão marcada.' : 'Revisão marcada.');
    }

    public function desmarcar(Request $request, MlbEmpresa $empresa): RedirectResponse
    {
        $user = $this->checkAcesso($request);

        $empresa->loadMissing('implementacao:id,empresa_id,responsavel_id');
        abort_unless(FilaRevisaoPolos::podeAtuar($user, $empresa), 403, 'Esta empresa não está na sua carteira.');

        $this->fila->desmarcar($empresa, $user, now());

        return back()->with('success', 'Revisão do dia desfeita.');
    }

    // ═══ Apoio ═══

    /** Mesmo gate do Faturamento Polos. */
    private function checkAcesso(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->hasPermission('mlb.faturamento_polos')), 403);

        return $user;
    }

    /** Admin escolhe pela query (?analista=); os demais veem sempre a própria fila. */
    private function analistaDaTela(Request $request, array $analistas): int
    {
        $user = $request->user();
        if (! $user->isAdmin()) {
            return $user->id;
        }

        $ids    = array_column($analistas, 'id');
        $pedido = (int) $request->query('analista', 0);
        if ($pedido > 0 && in_array($pedido, $ids, true)) {
            return $pedido;
        }

        return in_array($user->id, $ids, true) ? $user->id : ($ids[0] ?? $user->id);
    }

    private function analistasVisiveis(User $user, array $analistas): array
    {
        return $user->isAdmin()
            ? $analistas
            : array_values(array_filter($analistas, fn ($a) => $a['id'] === $user->id));
    }

    /**
     * Linhas do Faturamento Polos por mlb_empresa_id, e um aviso quando não há o mês corrente.
     *
     * @return array{0: array<int, array<string,mixed>>, 1: ?string}
     */
    private function faturamento(): array
    {
        try {
            $r = app(PolosController::class)->linhasDoMesCorrente();
        } catch (\Throwable $e) {
            report($e);

            return [[], 'Faturamento indisponível agora (o ECF Drive não respondeu). A fila segue sem os números do mês.'];
        }

        if ($r === null) {
            return [[], 'Faturamento indisponível: o arquivo POLOS MENSAL não está no ECF Drive.'];
        }

        $linhas = collect($r['linhas'])
            ->filter(fn ($l) => ! empty($l['mlb_empresa_id']))
            ->keyBy(fn ($l) => (int) $l['mlb_empresa_id'])
            ->all();

        $aviso = $r['parcial'] ? null : 'O mês corrente ainda não chegou do ECF Drive: faturamento e % da meta são do último mês fechado.';

        return [$linhas, $aviso];
    }
}
