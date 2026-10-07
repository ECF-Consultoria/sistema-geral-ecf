<?php

namespace App\Http\Controllers;

use App\Models\Autenticador;
use App\Models\User;
use App\Services\Autenticadores\AutenticadorService;
use App\Services\Autenticadores\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

/**
 * Módulo "Autenticadores 2FA": cofre interno dos códigos TOTP das contas
 * operadas pela ECF. Acesso de todos os colaboradores logados (gate na rota);
 * cada visualização/cópia de código é auditada no activity_log (log_name
 * 'autenticadores'). O secret nunca sai do backend — só o código de 6 dígitos.
 */
class AutenticadorController extends Controller
{
    private const LOG = 'autenticadores';

    public function __construct(
        private AutenticadorService $service,
        private TotpService $totp,
    ) {}

    public function index(Request $request)
    {
        $busca = trim((string) $request->query('q', ''));

        $query = Autenticador::query()
            ->with('responsavel:id,name')
            ->when($busca !== '', function ($q) use ($busca) {
                // Cobre os jeitos que o time busca: nome da loja/empresa, parte
                // antes do @, número no domínio e serviço — tudo via LIKE em
                // cliente/conta/servico.
                $termo = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $busca) . '%';
                $q->where(function ($sub) use ($termo) {
                    $sub->where('cliente', 'like', $termo)
                        ->orWhere('conta', 'like', $termo)
                        ->orWhere('servico', 'like', $termo)
                        ->orWhere('issuer', 'like', $termo);
                });
            })
            ->when($request->filled('servico'), fn ($q) => $q->where('servico', $request->query('servico')))
            ->when($request->filled('responsavel'), fn ($q) => $q->where('responsavel_id', $request->query('responsavel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('cliente');

        $autenticadores = $query->get()->map(fn (Autenticador $a) => $this->publico($a));

        return Inertia::render('Autenticadores/Index', [
            'autenticadores' => $autenticadores,
            'filtros'        => [
                'q'           => $busca,
                'servico'     => $request->query('servico', ''),
                'responsavel' => $request->query('responsavel', ''),
                'status'      => $request->query('status', ''),
            ],
            'servicos'       => Autenticador::query()->distinct()->orderBy('servico')->pluck('servico'),
            'responsaveis'   => User::query()->orderBy('name')->get(['id', 'name']),
            'ultimosAcessos' => $this->ultimosAcessos(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'uri'            => ['nullable', 'string', 'max:20000'],
            'secret'         => ['nullable', 'string', 'max:512'],
            'cliente'        => ['nullable', 'string', 'max:150'],
            'conta'          => ['nullable', 'string', 'max:150'],
            'servico'        => ['nullable', 'string', 'max:100'],
            'responsavel_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $resultado = $this->service->importar($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $add = count($resultado['added']);
        $skip = count($resultado['skipped']);

        if ($add === 0 && $skip > 0) {
            return back()->with('error', "Nenhuma conta nova: {$skip} já estava(m) cadastrada(s).");
        }

        $msg = $add === 1 ? '1 autenticador adicionado' : "{$add} autenticadores adicionados";
        if ($skip > 0) {
            $msg .= " · {$skip} já existia(m) e foi(ram) ignorada(s)";
        }

        return back()->with('success', $msg . '.');
    }

    /**
     * Código TOTP vigente de um autenticador. Audita a visualização.
     * Retorna só o código (nunca o secret).
     */
    public function codigo(Request $request, Autenticador $autenticador): JsonResponse
    {
        $resultado = $this->totp->gerarCodigo(
            $autenticador->secret,
            $autenticador->algoritmo,
            $autenticador->digitos,
            $autenticador->periodo,
        );

        $this->auditar($request, $autenticador, 'Visualizou o código', 'visualizou');

        return response()->json([
            'code'         => $resultado['code'],
            'digits'       => $resultado['digits'],
            'period'       => $resultado['period'],
            'step'         => $resultado['step'],
            'remaining_ms' => $resultado['remaining_ms'],
            'server_time'  => now()->valueOf(),
        ]);
    }

    /** Audita a cópia do código (o 6 dígitos o front já tem da tela). */
    public function copiar(Request $request, Autenticador $autenticador): JsonResponse
    {
        $this->auditar($request, $autenticador, 'Copiou o código', 'copiou');

        return response()->json(['ok' => true]);
    }

    /** Histórico de acessos de um autenticador (para "Ver histórico"). */
    public function historico(Autenticador $autenticador): JsonResponse
    {
        $acessos = Activity::where('log_name', self::LOG)
            ->where('subject_type', Autenticador::class)
            ->where('subject_id', $autenticador->id)
            ->with('causer:id,name')
            ->latest()
            ->take(50)
            ->get()
            ->map(fn (Activity $a) => [
                'descricao'  => $a->description,
                'usuario'    => $a->causer?->name ?? 'Sistema',
                'created_at' => $a->created_at?->format('d/m/Y H:i:s'),
            ]);

        return response()->json(['acessos' => $acessos]);
    }

    public function destroy(Request $request, Autenticador $autenticador)
    {
        $autenticador->delete();

        return back()->with('success', 'Autenticador removido.');
    }

    // ─── Helpers ───

    private function publico(Autenticador $a): array
    {
        return [
            'id'              => $a->id,
            'cliente'         => $a->cliente,
            'conta'           => $a->conta,
            'servico'         => $a->servico,
            'issuer'          => $a->issuer,
            'status'          => $a->status,
            'algoritmo'       => $a->algoritmo,
            'digitos'         => $a->digitos,
            'periodo'         => $a->periodo,
            'responsavel'     => $a->responsavel?->name,
            'responsavel_id'  => $a->responsavel_id,
            'criado_em'       => $a->created_at?->format('d/m/Y H:i'),
            'atualizado_em'   => $a->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function auditar(Request $request, Autenticador $a, string $descricao, string $acao): void
    {
        activity(self::LOG)
            ->causedBy($request->user())
            ->performedOn($a)
            ->withProperties([
                'acao'    => $acao,
                'cliente' => $a->cliente,
                'servico' => $a->servico,
            ])
            ->log($descricao);
    }

    private function ultimosAcessos()
    {
        return Activity::where('log_name', self::LOG)
            ->with('causer:id,name')
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Activity $a) => [
                'descricao'  => $a->description,
                'usuario'    => $a->causer?->name ?? 'Sistema',
                'cliente'    => $a->properties['cliente'] ?? null,
                'acao'       => $a->properties['acao'] ?? null,
                'created_at' => $a->created_at?->format('d/m/Y H:i'),
            ]);
    }
}
