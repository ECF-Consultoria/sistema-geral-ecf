<?php

namespace App\Http\Controllers;

use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubProduto;
use App\Models\PubTarefa;
use App\Services\Publicador\Fila\ConferenciaEmLoteService;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\Fila\ResumoRapidoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\GarantiaPadrao;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "Publicação em lote" da conta (10/10/2026, pedido do usuário; learnings publicador-ml §20): a visão rápida dos
 * rascunhos não publicados (títulos, preço, custo, frete, margem, pendências), "Conferir selecionados" e a fila
 * de publicação em RODADAS — alguns produtos de cada vez, com intervalo entre uma rodada e a próxima (pausar,
 * retomar, cancelar, tirar um produto).
 *
 * Só admin (grupo `role:admin` de `routes/mlb_anuncios.php`, como as rotas vizinhas). A conta vem SEMPRE da rota
 * (`ProgramasPublicadorService::resolver`), nunca do corpo; todo id de produto ou de item é filtrado por ela —
 * produto de outra conta é ignorado, item de outra conta é 404.
 */
class MlbPublicadorLoteController extends Controller
{
    /** Quantos produtos um "Conferir selecionados" aceita de uma vez (cada um são várias chamadas ao ML). */
    private const CONFERIR_MAX = 100;

    /** Quantos produtos um "Agendar publicação" aceita de uma vez. */
    private const AGENDAR_MAX = 200;

    public function __construct(
        private ProgramasPublicadorService $programas,
        private ResumoRapidoService $resumo,
        private FilaPublicacaoService $filas,
        private ConferenciaEmLoteService $conferencias,
    ) {}

    public function index(Request $request, string $conta)
    {
        $alvo = $this->alvo($conta);
        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.lote.index', array_filter(['conta' => $alvo['chave'], 'produtos' => $request->query('produtos')]));
        }

        // `?produtos=1,2,3` = a seleção que veio da lista de Produtos (só ids desta conta valem; a tela marca os que vieram).
        $pedidos = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('produtos', '')))))), 0, 500);

        return Inertia::render('Mlb/Publicador/PublicacaoEmLote', [
            'empresa' => $this->programas->empresaParaTela($alvo),
            'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])),
            'abas' => [
                'company_id' => $alvo['company']?->id,
                'alavancas_pendentes' => PubTarefa::abertasDaConta($alvo['mlb_empresa'], $alvo['company']),
            ],
            ...$this->dadosDaTela($alvo),
            'garantia_padrao' => $this->garantiaParaTela($alvo),
            'selecionados' => $pedidos,
            'config' => [
                'por_rodada_padrao' => max(1, (int) config('publicador.fila_publicacao.produtos_por_rodada', 5)),
                'por_rodada_maximo' => $this->porRodadaMaximo(),
                'teto_por_minuto' => max(1, (int) config('publicador.fila_publicacao.teto_inicios_por_minuto', 2)),
                'intervalo_padrao' => (int) config('publicador.fila_publicacao.intervalo_minutos', 20),
                'intervalo_minimo' => $this->intervaloMinimo(),
                'intervalo_maximo' => 240,
                'conferir_max' => self::CONFERIR_MAX,
                'agendar_max' => self::AGENDAR_MAX,
                'polling_s' => 10,
            ],
        ]);
    }

    /** O polling da tela: as linhas e o painel da fila, de novo (número fixo de consultas, nenhuma ao ML). */
    public function dados(string $conta): JsonResponse
    {
        return response()->json($this->dadosDaTela($this->alvo($conta)));
    }

    public function conferir(Request $request, string $conta): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $dados = $request->validate([
            'produtos' => ['required', 'array', 'min:1', 'max:'.self::CONFERIR_MAX],
            'produtos.*' => ['integer', 'min:1'],
        ], ['produtos.max' => 'Confira no máximo '.self::CONFERIR_MAX.' produtos de uma vez.']);

        $r = $this->conferencias->enfileirar($alvo, $dados['produtos']);
        $n = count($r['enfileirados']);
        $mensagem = $n === 0 ? 'Nenhum dos produtos escolhidos pode ser conferido agora.'
            : ($n === 1 ? 'Conferindo 1 produto com o Mercado Livre.' : "Conferindo {$n} produtos com o Mercado Livre, um a cada ".max(1, (int) config('publicador.fila_publicacao.conferir_espaco_s', 10)).' segundos.');

        return response()->json([
            'enfileirados' => $r['enfileirados'],
            'ignorados' => $r['ignorados'],
            'mensagem' => $mensagem,
            ...$this->dadosDaTela($alvo),
        ], $n === 0 ? 422 : 202);
    }

    public function agendar(Request $request, string $conta): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $dados = $request->validate([
            'produtos' => ['required', 'array', 'min:1', 'max:'.self::AGENDAR_MAX],
            'produtos.*' => ['integer', 'min:1'],
            'produtos_por_rodada' => ['nullable', 'integer', 'min:1', 'max:'.$this->porRodadaMaximo()],
            'intervalo_minutos' => ['nullable', 'integer', 'min:'.$this->intervaloMinimo(), 'max:240'],
            'janela_inicio' => ['nullable', 'date_format:H:i', 'required_with:janela_fim'],
            'janela_fim' => ['nullable', 'date_format:H:i', 'required_with:janela_inicio', 'different:janela_inicio'],
            'ciente' => ['boolean'],
        ], [
            'produtos_por_rodada.min' => 'Cada rodada precisa de pelo menos 1 produto.',
            'produtos_por_rodada.max' => 'Cada rodada aceita no máximo '.$this->porRodadaMaximo().' produtos.',
            'intervalo_minutos.min' => 'O intervalo mínimo entre as rodadas é de '.$this->intervaloMinimo().' minutos.',
            'janela_fim.different' => 'O fim da janela precisa ser diferente do início.',
        ]);

        if (! ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company']))) {
            return response()->json(['message' => 'A publicação ainda não foi liberada para esta conta do Mercado Livre.'], 422);
        }

        $opcoes = ['ciente' => (bool) ($dados['ciente'] ?? false)];
        foreach (['produtos_por_rodada', 'intervalo_minutos'] as $campo) {
            if (array_key_exists($campo, $dados) && $dados[$campo] !== null) {
                $opcoes[$campo] = (int) $dados[$campo];
            }
        }
        if ($request->exists('janela_inicio') || $request->exists('janela_fim')) {
            $opcoes['janela_inicio'] = $dados['janela_inicio'] ?? null;
            $opcoes['janela_fim'] = $dados['janela_fim'] ?? null;
        }

        $r = $this->filas->agendar($alvo, $dados['produtos'], $request->user(), $opcoes);
        $n = count($r['agendados']);
        if ($n === 0) {
            return response()->json([
                'message' => 'Nenhum dos produtos escolhidos está pronto para publicar.',
                'recusados' => $r['recusados'],
                ...$this->dadosDaTela($alvo),
            ], 422);
        }

        return response()->json([
            'agendados' => $r['agendados'],
            'recusados' => $r['recusados'],
            'mensagem' => ($n === 1 ? '1 produto entrou' : "{$n} produtos entraram").' na fila de publicação.',
            ...$this->dadosDaTela($alvo),
        ], 201);
    }

    /**
     * A garantia padrão da conta (10/10/2026): grava e já aplica nos rascunhos da conta que não têm garantia
     * (`GarantiaPadrao::aplicarNaConta` — nunca troca a escolhida). `tipo` vazio remove o padrão (o que já foi
     * aplicado fica nos rascunhos).
     */
    public function garantia(Request $request, string $conta): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $sem = GarantiaPadrao::SEM_GARANTIA;
        $dados = $request->validate([
            'tipo' => ['nullable', 'string', Rule::in(array_keys(GarantiaPadrao::TIPOS))],
            'tempo' => ['nullable', 'integer', 'min:1', 'max:'.GarantiaPadrao::TEMPO_MAXIMO, "required_unless:tipo,{$sem},null"],
            'unidade' => ['nullable', 'string', Rule::in(GarantiaPadrao::UNIDADES), "required_unless:tipo,{$sem},null"],
        ], [
            'tipo.in' => 'Escolha o tipo da garantia.',
            'tempo.required_unless' => 'Informe o tempo da garantia.',
            'tempo.min' => 'O tempo da garantia precisa ser de pelo menos 1.',
            'tempo.max' => 'O tempo da garantia pode ser de no máximo '.GarantiaPadrao::TEMPO_MAXIMO.'.',
            'unidade.required_unless' => 'Escolha dias, meses ou anos.',
            'unidade.in' => 'Escolha dias, meses ou anos.',
        ]);

        $tipo = (string) ($dados['tipo'] ?? '');
        $g = GarantiaPadrao::salvar($alvo, $tipo === '' ? null : $dados, $request->user());
        $n = $g === null ? 0 : GarantiaPadrao::aplicarNaConta(
            $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])->pluck('id'),
            $g,
        );
        $mensagem = $g === null
            ? 'Garantia padrão removida. O que já foi aplicado continua nos produtos.'
            : 'Garantia padrão salva: '.GarantiaPadrao::texto($g).'. '.($n === 0 ? 'Nenhum produto estava sem garantia.' : ($n === 1 ? 'Aplicada em 1 produto sem garantia.' : "Aplicada em {$n} produtos sem garantia."));

        return response()->json([
            'garantia_padrao' => $this->garantiaParaTela($alvo),
            'aplicados' => $n,
            'mensagem' => $mensagem,
            ...$this->dadosDaTela($alvo),
        ]);
    }

    public function pausar(Request $request, string $conta): JsonResponse
    {
        return $this->naFila($conta, fn (PubFilaPublicacao $f) => $this->filas->pausar($f, $request->user()));
    }

    public function retomar(Request $request, string $conta): JsonResponse
    {
        return $this->naFila($conta, fn (PubFilaPublicacao $f) => $this->filas->retomar($f, $request->user()));
    }

    public function cancelar(Request $request, string $conta): JsonResponse
    {
        return $this->naFila($conta, fn (PubFilaPublicacao $f) => $this->filas->cancelar($f, $request->user()));
    }

    public function removerItem(Request $request, string $conta, int $item): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $doItem = PubFilaPublicacaoItem::query()->whereKey($item)
            ->whereHas('fila', fn ($q) => $q->where('conta_chave', $alvo['chave']))
            ->firstOrFail();

        try {
            $this->filas->remover($doItem, $request->user());
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        }

        return response()->json($this->dadosDaTela($alvo));
    }

    // ═══ Apoio ═══════════════════════════════════════════════════════════════

    /** A conta da rota; arquivada ou sem programa = 404 (como as outras telas da conta). */
    private function alvo(string $conta): array
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        return $alvo;
    }

    /** @return array{linhas: list<array>, fila: ?array} */
    private function dadosDaTela(array $alvo): array
    {
        return [
            'linhas' => $this->resumo->linhas($alvo),
            'fila' => $this->filas->painel($this->filas->doPainel($alvo['chave'])),
        ];
    }

    private function naFila(string $conta, \Closure $acao): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $fila = $this->filas->viva($alvo['chave']);
        abort_if($fila === null, 404);

        try {
            $acao($fila);
        } catch (RegraViolada $e) {
            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], 422);
        }

        return response()->json($this->dadosDaTela($alvo));
    }

    private function intervaloMinimo(): int
    {
        return max(1, (int) config('publicador.fila_publicacao.intervalo_minimo', 2));
    }

    private function porRodadaMaximo(): int
    {
        return max(1, (int) config('publicador.fila_publicacao.produtos_por_rodada_max', 10));
    }

    /** A garantia padrão da conta e as opções do formulário. */
    private function garantiaParaTela(array $alvo): array
    {
        $g = GarantiaPadrao::daConta($alvo);

        return [
            'atual' => $g,
            'texto' => GarantiaPadrao::texto($g),
            'tipos' => array_map(fn ($id, $nome) => ['id' => (string) $id, 'nome' => $nome], array_keys(GarantiaPadrao::TIPOS), GarantiaPadrao::TIPOS),
            'unidades' => GarantiaPadrao::UNIDADES,
            'sem_garantia' => GarantiaPadrao::SEM_GARANTIA,
            'tempo_maximo' => GarantiaPadrao::TEMPO_MAXIMO,
        ];
    }
}
