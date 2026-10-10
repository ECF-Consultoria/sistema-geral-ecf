<?php

namespace App\Http\Controllers;

use App\Models\PubTarefa;
use App\Models\User;
use App\Services\Publicador\Alavancas\PromocaoAutomaticaService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Fila "Publicados aguardando alavancas" (tarefas pós-publicação, 09/10/2026). Tela GLOBAL do Publicador
 * (sem `BarraDaConta`): toda conta numa lista só, filtrável por "Minhas"/"Todas", status e conta.
 *
 * Acesso: `permission:mlb.alavancas` na rota (admin passa por qualquer chave) — quem usa as alavancas
 * pode não ser admin. Escrever no Mercado Livre continua só para admin: o botão "Abrir Alavancas" só
 * vem para quem abre a tela das Alavancas (`role:admin`), e o responsável padrão só o admin escolhe.
 */
class MlbPublicadorTarefasController extends Controller
{
    private const POR_PAGINA = 50;

    private const ESCOPOS = ['todas', 'minhas'];

    private const STATUS = ['abertas', PubTarefa::PENDENTE, PubTarefa::EM_ANDAMENTO, PubTarefa::FEITA, 'todas'];

    private const NOME_TIPO = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    public function __construct(
        private TarefasPosPublicacao $tarefas,
        private ProgramasPublicadorService $programas,
    ) {}

    public function index(Request $request)
    {
        $u = $request->user();
        $destacada = ctype_digit((string) $request->query('tarefa', '')) ? (int) $request->query('tarefa') : null;

        // Vindo do sino (`?tarefa=`), a tarefa aparece qualquer que seja o status.
        $escopo = in_array($request->query('escopo'), self::ESCOPOS, true) ? (string) $request->query('escopo') : 'todas';
        $status = in_array($request->query('status'), self::STATUS, true)
            ? (string) $request->query('status')
            : ($destacada !== null ? 'todas' : 'abertas');

        $alvo = null;
        $chaveConta = (string) $request->query('conta', '');
        if (preg_match('/^(empresa|company)-\d+$/D', $chaveConta) === 1) {
            $alvo = $this->programas->resolver($chaveConta);
        }

        $base = fn () => PubTarefa::query()->alavancas()
            ->when($alvo !== null, fn ($q) => $q->daConta($alvo['mlb_empresa'], $alvo['company']));

        $hoje = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d');
        $consulta = $base()
            ->when($escopo === 'minhas', fn ($q) => $q->where('responsavel_id', $u->id))
            ->when($status === 'abertas', fn ($q) => $q->abertas())
            ->when(! in_array($status, ['abertas', 'todas'], true), fn ($q) => $q->where('status', $status));

        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) $request->query('pagina', 1)), $ultima);

        // Abertas primeiro (o prazo mais curto em cima); depois as concluídas, a mais recente em cima.
        $daPagina = $consulta
            ->with(['produto.oferta', 'produto.estruturaProduto', 'responsavel:id,name', 'publicadoPor:id,name',
                'publicacao:id,ator', 'company:id,name', 'mlbEmpresa:id,nome'])
            ->orderByRaw("case when status in ('".PubTarefa::PENDENTE."', '".PubTarefa::EM_ANDAMENTO."') then 0 else 1 end")
            ->orderBy('prazo')->orderBy('publicado_em')->orderByDesc('id')
            ->forPage($pagina, self::POR_PAGINA)
            ->get();
        // A promoção automática de cada anúncio (10/10/2026), numa consulta só para a página.
        $promocoes = PromocaoAutomaticaService::dasTarefas($daPagina);
        $linhas = $daPagina
            ->map(fn (PubTarefa $t) => [...$this->linha($t, $u, $hoje), 'promocoes' => $promocoes[$t->id] ?? []])
            ->values();

        $responsavelGravado = $this->tarefas->responsavelPadraoGravado();
        $responsavel = $this->tarefas->responsavelPadrao();

        return Inertia::render('Mlb/Publicador/Tarefas', [
            'tarefas' => $linhas,
            'filtros' => [
                'escopo' => $escopo,
                'status' => $status,
                'conta' => $alvo['chave'] ?? null,
                'conta_nome' => $alvo !== null ? ($alvo['mlb_empresa']?->nome ?? $alvo['company']?->name) : null,
            ],
            'contagens' => [
                'abertas' => $base()->abertas()->count(),
                'minhas' => $base()->abertas()->where('responsavel_id', $u->id)->count(),
                'atrasadas' => $base()->abertas()->where('prazo', '<', $hoje)->count(),
                'hoje' => $base()->abertas()->where('prazo', '=', $hoje)->count(),
            ],
            'paginacao' => ['pagina' => $pagina, 'por_pagina' => self::POR_PAGINA, 'total' => $total, 'ultima' => $ultima],
            'tarefa_destacada' => $destacada,
            'checklist' => collect(PubTarefa::CHECKLIST_ALAVANCAS)->map(fn ($rotulo, $chave) => ['chave' => $chave, 'rotulo' => $rotulo])->values(),
            'responsavel_padrao' => [
                'id' => $responsavel?->id,
                'nome' => $responsavel?->name,
                // Configurado, mas o usuário saiu ou perdeu a chave: as tarefas novas caem na fila comum.
                'invalido' => $responsavelGravado !== null && $responsavel === null,
            ],
            'candidatos' => $u->isAdmin()
                ? $this->tarefas->usuariosDaFila()->map(fn (User $c) => ['id' => $c->id, 'nome' => $c->name])->values()
                : [],
            'pode_configurar' => $u->isAdmin(),
            'pode_escrever' => $u->isAdmin(),
        ]);
    }

    public function pegar(Request $request, PubTarefa $tarefa): RedirectResponse
    {
        $this->daFila($tarefa);
        $this->executar(fn () => $this->tarefas->pegar($tarefa, $request->user()));

        return back()->with('success', 'A tarefa é sua.');
    }

    public function marcar(Request $request, PubTarefa $tarefa, string $chave): RedirectResponse
    {
        $this->daFila($tarefa);
        $dados = $request->validate([
            'estado' => ['required', Rule::in(PubTarefa::ESTADOS_DO_ITEM)],
            'motivo' => ['nullable', 'string', 'max:'.TarefasPosPublicacao::MOTIVO_MAX, 'required_if:estado,'.PubTarefa::ITEM_NAO_SE_APLICA],
        ], [
            'motivo.required_if' => 'Diga por que não se aplica.',
            'motivo.max' => 'Use até '.TarefasPosPublicacao::MOTIVO_MAX.' caracteres no motivo.',
        ]);

        $this->executar(fn () => $this->tarefas->marcar($tarefa, $chave, $dados['estado'], $dados['motivo'] ?? null, $request->user()));

        return back()->with('success', match ($dados['estado']) {
            PubTarefa::ITEM_FEITO => PubTarefa::CHECKLIST_ALAVANCAS[$chave].': feito.',
            PubTarefa::ITEM_NAO_SE_APLICA => PubTarefa::CHECKLIST_ALAVANCAS[$chave].': não se aplica.',
            default => PubTarefa::CHECKLIST_ALAVANCAS[$chave].': de volta a pendente.',
        });
    }

    public function concluir(Request $request, PubTarefa $tarefa): RedirectResponse
    {
        $this->daFila($tarefa);
        $this->executar(fn () => $this->tarefas->concluir($tarefa, $request->user()));

        return back()->with('success', 'Tarefa concluída.');
    }

    public function observacao(Request $request, PubTarefa $tarefa): RedirectResponse
    {
        $this->daFila($tarefa);
        $dados = $request->validate([
            'observacao' => ['nullable', 'string', 'max:'.TarefasPosPublicacao::OBSERVACAO_MAX],
        ], ['observacao.max' => 'Use até '.TarefasPosPublicacao::OBSERVACAO_MAX.' caracteres.']);

        $this->tarefas->observar($tarefa, $dados['observacao'] ?? null);

        return back()->with('success', 'Observação salva.');
    }

    /** Só admin (a rota também pede `role:admin`): vale para as tarefas que nascerem daqui em diante. */
    public function responsavelPadrao(Request $request): RedirectResponse
    {
        $dados = $request->validate(['responsavel_id' => ['nullable', 'integer', 'exists:users,id']]);
        $usuario = isset($dados['responsavel_id']) ? User::query()->find($dados['responsavel_id']) : null;

        try {
            $this->tarefas->definirResponsavelPadrao($usuario);
        } catch (RegraViolada $e) {
            throw ValidationException::withMessages(['responsavel_id' => $e->getMessage()]);
        }

        return back()->with('success', $usuario
            ? "As próximas publicações vão para {$usuario->name}."
            : 'As próximas publicações vão para a fila comum.');
    }

    // ═══ Internos ═══

    /** Só a fila das alavancas é endereçável aqui (os tipos futuros terão a deles). */
    private function daFila(PubTarefa $t): void
    {
        abort_unless($t->tipo === PubTarefa::TIPO_ALAVANCAS, 404);
    }

    /** Regra da fila (tarefa já concluída, checklist incompleto…) volta como erro de formulário. */
    private function executar(\Closure $fn): void
    {
        try {
            $fn();
        } catch (RegraViolada $e) {
            throw ValidationException::withMessages(['tarefa' => $e->getMessage()]);
        }
    }

    /** Uma linha da fila — tudo que a tela mostra, já resolvido no servidor. */
    private function linha(PubTarefa $t, User $u, string $hoje): array
    {
        $produto = $t->produto;
        $contaTela = $this->contaDaTela($t);
        $podeEscrever = $u->isAdmin();

        $itens = collect((array) $t->itens)->filter(fn ($i) => is_array($i) && ! empty($i['ml_item_id']))->map(fn (array $i) => [
            'ml_item_id' => (string) $i['ml_item_id'],
            'tipo' => self::NOME_TIPO[$i['listing_type'] ?? ''] ?? (string) ($i['listing_type'] ?? ''),
            'permalink' => self::linkSeguro($i['permalink'] ?? null),
            'titulo' => isset($i['titulo']) ? (string) $i['titulo'] : null,
            'url_alavancas' => $podeEscrever && $contaTela !== null
                ? route('mlb.anuncios.publicador.alavancas.index', ['conta' => $contaTela, 'aba' => 'promocoes', 'item' => (string) $i['ml_item_id']])
                : null,
        ])->values();

        $prazo = substr((string) $t->prazo, 0, 10);
        $selo = ! $t->aberta() ? null : match (true) {
            $prazo < $hoje => 'atrasado',
            $prazo === $hoje => 'hoje',
            default => 'programado',
        };

        $checklist = [];
        foreach ($t->checklistCompleto() as $chave => $item) {
            $checklist[] = [
                'chave' => $chave,
                'rotulo' => PubTarefa::CHECKLIST_ALAVANCAS[$chave],
                'estado' => $item['estado'],
                'motivo' => $item['motivo'],
                'por' => is_array($item['por']) ? ($item['por']['nome'] ?? null) : null,
                'em' => $item['em'],
                'automatico' => $item['escrita_id'] !== null,
            ];
        }

        return [
            'id' => $t->id,
            'status' => $t->status,
            'empresa' => [
                'nome' => $t->mlbEmpresa?->nome ?? $t->company?->name ?? $produto?->mlbEmpresa?->nome ?? $t->conta_chave,
                'conta' => $contaTela,
            ],
            'produto' => [
                'id' => $produto?->id,
                'nome' => $produto?->nomeExibido() ?? ($itens->first()['titulo'] ?? 'Produto removido'),
                'sku' => $produto?->skuExibido(),
            ],
            'itens' => $itens,
            'publicado_por' => $t->publicadoPor?->name ?? ($t->publicacao?->ator['nome'] ?? null),
            'publicado_em' => $t->publicado_em?->toIso8601String(),
            'prazo' => $prazo,
            'selo' => $selo,
            'responsavel' => $t->responsavel ? ['id' => $t->responsavel->id, 'nome' => $t->responsavel->name] : null,
            'minha' => $t->responsavel_id === $u->id,
            'checklist' => $checklist,
            'resolvida' => $t->checklistResolvido(),
            'observacao' => $t->observacao,
            'iniciada_em' => $t->iniciada_em?->toIso8601String(),
            'concluida_em' => $t->concluida_em?->toIso8601String(),
            // Conta fora da lista das Alavancas: a equipe faz no Seller Center e marca aqui.
            'liberada' => AlavancasLiberadas::liberaChave($t->conta_chave),
        ];
    }

    /** A chave que a tela das Alavancas resolve: `company-N` sempre resolve (e redireciona para a canônica). */
    private function contaDaTela(PubTarefa $t): ?string
    {
        if ($t->company_id !== null) {
            return 'company-'.$t->company_id;
        }
        if ($t->mlb_empresa_id !== null) {
            return 'empresa-'.$t->mlb_empresa_id;
        }

        return preg_match('/^(empresa|company)-\d+$/D', (string) $t->conta_chave) === 1 ? $t->conta_chave : null;
    }

    /** Só link https do Mercado Livre vira link na tela. */
    private static function linkSeguro(mixed $url): ?string
    {
        $texto = trim((string) $url);

        return preg_match('#^https://([a-z0-9-]+\.)*mercadoli(vre|bre)\.com(\.br)?/#i', $texto) === 1 ? $texto : null;
    }
}
