<?php

namespace App\Http\Controllers;

use App\Models\PubJardinagem;
use App\Services\Publicador\Jardinagem\JardinagemService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Jardinagem dos publicados (23/10/2026) — a análise mensal pela árvore do MAG, numa tela GLOBAL do
 * Publicador (como a fila das alavancas): toda conta numa lista só, filtrável por diagnóstico, status
 * e conta. Embaixo, os anúncios ainda dentro do 1º mês ("em observação"), com quantos dias faltam.
 *
 * Acesso: `permission:mlb.jardinagem` na rota (admin passa por qualquer chave). Nada aqui escreve no
 * Mercado Livre: a análise é o comando diário, e excluir/criar a nova oferta ainda é a equipe quem faz.
 */
class MlbPublicadorJardinagemController extends Controller
{
    private const POR_PAGINA = 50;

    private const STATUS = ['abertas', PubJardinagem::FEITA, PubJardinagem::SUBSTITUIDA, 'todas'];

    /** Quantos "em observação" a tela lista (a contagem vem inteira). */
    private const EM_OBSERVACAO_MAX = 60;

    private const NOME_TIPO = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    public function __construct(
        private JardinagemService $jardinagem,
        private ProgramasPublicadorService $programas,
    ) {}

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), self::STATUS, true) ? (string) $request->query('status') : 'abertas';
        $diagnostico = in_array($request->query('diagnostico'), PubJardinagem::DIAGNOSTICOS, true) ? (string) $request->query('diagnostico') : null;

        $alvo = null;
        $chaveConta = (string) $request->query('conta', '');
        if (preg_match('/^(empresa|company)-\d+$/D', $chaveConta) === 1) {
            $alvo = $this->programas->resolver($chaveConta);
        }

        $base = fn () => PubJardinagem::query()
            ->when($alvo !== null, fn ($q) => $q->daConta($alvo['mlb_empresa'], $alvo['company']));

        $consulta = $base()
            ->when($status === 'abertas', fn ($q) => $q->abertas())
            ->when(! in_array($status, ['abertas', 'todas'], true), fn ($q) => $q->where('status', $status))
            ->when($diagnostico !== null, fn ($q) => $q->where('diagnostico', $diagnostico));

        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) $request->query('pagina', 1)), $ultima);

        // Abertas primeiro, na ordem da árvore (sem tração em cima: é a ação mais pesada); depois o mês mais antigo.
        $ordemDiag = "case diagnostico when '".PubJardinagem::SEM_TRACAO."' then 0 when '".PubJardinagem::VISITA_SEM_VENDA."' then 1 when '"
            .PubJardinagem::CONVERSAO_BAIXA."' then 2 else 3 end";
        $linhas = $consulta
            ->with(['produto.oferta', 'produto.estruturaProduto', 'produto.mlbEmpresa:id,nome', 'publicacaoItem:id,avisos', 'responsavel:id,name',
                'company:id,name', 'mlbEmpresa:id,nome'])
            ->orderByRaw("case when status in ('".PubJardinagem::PENDENTE."', '".PubJardinagem::EM_ANDAMENTO."') then 0 else 1 end")
            ->orderByRaw($ordemDiag)
            ->orderBy('janela_fim')->orderByDesc('id')
            ->forPage($pagina, self::POR_PAGINA)
            ->get()
            ->map(fn (PubJardinagem $j) => $this->linha($j))
            ->values();

        $abertasPorDiagnostico = $base()->abertas()
            ->select('diagnostico', DB::raw('count(*) as n'))->groupBy('diagnostico')->pluck('n', 'diagnostico');

        $situacao = $this->jardinagem->situacao($alvo['chave'] ?? null);
        // "Em observação" = ainda no 1º mês. O anúncio já analisado está na lista de cima (o mês mais novo dele);
        // repeti-lo embaixo mostraria o mesmo anúncio duas vezes.
        $primeiroMes = array_values(array_filter($situacao['em_observacao'], fn (array $s) => $s['ciclo'] === 1));
        $regua = $this->jardinagem->regua();

        return Inertia::render('Mlb/Jardinagem', [
            'linhas' => $linhas,
            'filtros' => [
                'status' => $status,
                'diagnostico' => $diagnostico,
                'conta' => $alvo['chave'] ?? null,
                'conta_nome' => $alvo !== null ? ($alvo['mlb_empresa']?->nome ?? $alvo['company']?->name) : null,
            ],
            'contagens' => [
                'abertas' => (int) $abertasPorDiagnostico->sum(),
                'por_diagnostico' => collect(PubJardinagem::DIAGNOSTICOS)->mapWithKeys(fn ($d) => [$d => (int) ($abertasPorDiagnostico[$d] ?? 0)]),
                'em_observacao' => count($primeiroMes),
                'mes_fechado' => count($situacao['devidos']),
            ],
            'em_observacao' => collect($primeiroMes)->take(self::EM_OBSERVACAO_MAX)->map(fn (array $s) => [
                'ml_item_id' => (string) $s['item']->ml_item_id,
                'tipo' => self::NOME_TIPO[$s['item']->listing_type_id] ?? (string) $s['item']->listing_type_id,
                'titulo' => $s['item']->payload['family_name'] ?? $s['item']->payload['title'] ?? null,
                'permalink' => self::linkSeguro($s['item']->avisos['estado']['permalink'] ?? null),
                'conta' => $s['conta_chave'],
                'mes' => $s['ciclo'],
                'inicio' => $s['inicio'],
                'dias_passados' => $s['dias_passados'],
                'faltam' => $s['faltam'],
            ])->values(),
            'paginacao' => ['pagina' => $pagina, 'por_pagina' => self::POR_PAGINA, 'total' => $total, 'ultima' => $ultima],
            'regras' => [
                'dias_do_ciclo' => JardinagemService::diasDoCiclo(),
                'visitas_corte' => $regua->corteVisitas,
                'conversao_corte' => $regua->corteConversao,
                'diagnosticos' => collect($regua->descricoes())->map(fn ($d, $chave) => ['chave' => $chave, ...$d])->values(),
            ],
            // Desligada (padrão até o usuário liberar), a tela avisa que a análise diária ainda não roda.
            'ativa' => (bool) config('publicador.jardinagem.ativo', false),
            // O Publicador é `role:admin`: o link de volta só para quem o abre.
            'pode_abrir_publicador' => (bool) $request->user()?->isAdmin(),
        ]);
    }

    public function marcar(Request $request, PubJardinagem $jardinagem, string $chave): RedirectResponse
    {
        $dados = $request->validate([
            'estado' => ['required', Rule::in(PubJardinagem::ESTADOS_DO_ITEM)],
            'motivo' => ['nullable', 'string', 'max:'.JardinagemService::MOTIVO_MAX, 'required_if:estado,'.PubJardinagem::ITEM_NAO_SE_APLICA],
        ], [
            'motivo.required_if' => 'Diga por que não se aplica.',
            'motivo.max' => 'Use até '.JardinagemService::MOTIVO_MAX.' caracteres no motivo.',
        ]);

        $this->executar(fn () => $this->jardinagem->marcar($jardinagem, $chave, $dados['estado'], $dados['motivo'] ?? null, $request->user()));
        $rotulo = PubJardinagem::CHECKLISTS[$jardinagem->diagnostico][$chave] ?? 'Item';

        return back()->with('success', match ($dados['estado']) {
            PubJardinagem::ITEM_FEITO => "{$rotulo}: feito.",
            PubJardinagem::ITEM_NAO_SE_APLICA => "{$rotulo}: não se aplica.",
            default => "{$rotulo}: de volta a pendente.",
        });
    }

    public function concluir(Request $request, PubJardinagem $jardinagem): RedirectResponse
    {
        $this->executar(fn () => $this->jardinagem->concluir($jardinagem, $request->user()));

        return back()->with('success', 'Jardinagem do mês concluída.');
    }

    public function observacao(Request $request, PubJardinagem $jardinagem): RedirectResponse
    {
        $dados = $request->validate([
            'observacao' => ['nullable', 'string', 'max:'.JardinagemService::OBSERVACAO_MAX],
        ], ['observacao.max' => 'Use até '.JardinagemService::OBSERVACAO_MAX.' caracteres.']);

        $this->jardinagem->observar($jardinagem, $dados['observacao'] ?? null);

        return back()->with('success', 'Observação salva.');
    }

    public function proposta(PubJardinagem $jardinagem): RedirectResponse
    {
        $this->executar(fn () => $this->jardinagem->pedirOutraProposta($jardinagem));

        return back()->with('success', 'Gerando outro título com os termos da semana.');
    }

    // ═══ Internos ═══

    /** Regra da fila (linha concluída, checklist incompleto…) volta como erro de formulário. */
    private function executar(\Closure $fn): void
    {
        try {
            $fn();
        } catch (RegraViolada $e) {
            throw ValidationException::withMessages(['jardinagem' => $e->getMessage()]);
        }
    }

    /** Uma linha da fila — tudo que a tela mostra, já resolvido no servidor. */
    private function linha(PubJardinagem $j): array
    {
        $produto = $j->produto;
        $rotulos = PubJardinagem::CHECKLISTS[$j->diagnostico] ?? [];

        $checklist = [];
        foreach ($j->checklistCompleto() as $chave => $item) {
            $checklist[] = [
                'chave' => $chave,
                'rotulo' => $rotulos[$chave] ?? $chave,
                'estado' => $item['estado'],
                'motivo' => $item['motivo'],
                'por' => is_array($item['por']) ? ($item['por']['nome'] ?? null) : null,
                'em' => $item['em'],
            ];
        }

        $proposta = is_array($j->proposta) ? [
            'estado' => (string) ($j->proposta['estado'] ?? ''),
            'titulo_atual' => $j->proposta['titulo_atual'] ?? $j->titulo,
            'titulo_novo' => $j->proposta['titulo_novo'] ?? null,
            'termos_novos' => array_values((array) ($j->proposta['termos_novos'] ?? [])),
            'erro' => $j->proposta['erro'] ?? null,
            'em' => $j->proposta['em'] ?? null,
        ] : null;

        return [
            'id' => $j->id,
            'status' => $j->status,
            'diagnostico' => $j->diagnostico,
            'mes' => $j->ciclo,
            'ml_item_id' => $j->ml_item_id,
            'permalink' => self::linkSeguro($j->publicacaoItem?->avisos['estado']['permalink'] ?? null),
            'tipo' => self::NOME_TIPO[$j->listing_type_id] ?? (string) $j->listing_type_id,
            'titulo' => $j->titulo,
            'empresa' => [
                'nome' => $j->mlbEmpresa?->nome ?? $j->company?->name ?? $produto?->mlbEmpresa?->nome ?? $j->conta_chave,
                'conta' => $j->conta_chave,
            ],
            'produto' => [
                'id' => $produto?->id,
                'nome' => $produto?->nomeExibido() ?? ($j->titulo ?? 'Produto removido'),
                'sku' => $produto?->skuExibido(),
            ],
            'janela' => ['inicio' => substr((string) $j->janela_inicio, 0, 10), 'fim' => substr((string) $j->janela_fim, 0, 10)],
            'visitas' => $j->visitas,
            'vendas' => $j->vendas,
            'conversao' => $j->conversao,
            'checklist' => $checklist,
            'resolvida' => $j->checklistResolvido(),
            'proposta' => $proposta,
            'observacao' => $j->observacao,
            'responsavel' => $j->responsavel?->name,
            'concluida_em' => $j->concluida_em?->toIso8601String(),
        ];
    }

    /** Só link https do Mercado Livre vira link na tela. */
    private static function linkSeguro(mixed $url): ?string
    {
        $texto = trim((string) $url);

        return preg_match('#^https://([a-z0-9-]+\.)*mercadoli(vre|bre)\.com(\.br)?/#i', $texto) === 1 ? $texto : null;
    }
}
