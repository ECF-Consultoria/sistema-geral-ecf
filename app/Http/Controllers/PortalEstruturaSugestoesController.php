<?php

namespace App\Http\Controllers;

use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Geracao\MontagemManualDeOferta;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sugestões de ofertas (Fase 168): Combo, Kit e Combit sugeridos a partir dos
 * produtos do Mapeamento Estrutural — a tela "Planejamento".
 *
 * Este controller só ORQUESTRA: a regra mora em `App\Services\Portal\Estrutura\Geracao`.
 * D-02: a tela mora no Mapeamento e entra pela mesma porta de Produtos (cliente e
 * equipe). Desde 09/10/2026 o Planejamento é submódulo do menu (chave `sugestoes`,
 * logo depois de Produtos — o D-20 caiu a pedido do usuário); a chave `planejamento`
 * continua sendo a da agenda (Cronograma).
 *
 * ### A empresa vem SEMPRE da sessão
 * Tudo é resolvido DENTRO da empresa do `PortalContexto`, nunca pelo corpo da
 * requisição. Um id de outra empresa responde 404/422, igual a um id que não existe.
 * Do navegador só chegam chaves, nome e SKU; a composição é regerada no servidor.
 *
 * ### Montar kit (09/10/2026)
 * `montar/previa` só calcula; `montar` grava pela regra da Lista SKUs. Do navegador
 * chegam os itens escolhidos (variação, ou oferta sem variação) e as quantidades; fase,
 * nome, SKU, duplicata, logística, frete e estoque são recalculados no servidor
 * ({@see MontagemManualDeOferta}).
 *
 * ### Quem escreve
 * Cliente e equipe. O `AtorDoPortal` segue para os serviços e o lado de quem
 * fez vai para o activity log como `origem` (`cliente` ou `interno`).
 *
 * ### Mercado Livre
 * A listagem e a prévia do "Montar kit" não fazem nenhuma requisição ao ML; a cotação
 * real é a rota `frete`, sob demanda, com teto de chaves e só leitura na conta do
 * cliente (D-18).
 */
class PortalEstruturaSugestoesController extends Controller
{
    private const ABAS = ['sugestoes', 'sem_tipo', 'descartadas'];
    private const FASES = ['combo', 'kit', 'combit'];

    public function __construct(
        private PortalClienteService $portal,
        private ListaDeSugestoes $lista,
        private DecisoesDasSugestoes $decisoes,
        private MontagemManualDeOferta $montagem,
    ) {
    }

    /** Chave de composição (1 a 6 componentes), a mesma de `ChaveDeComposicao::valida()`. */
    private static function regraChave(): array
    {
        return ['required', 'string', 'max:100', 'regex:'.ChaveDeComposicao::expressao()];
    }

    // ═══ Visão ══════════════════════════════════════════════════════════════

    /** A tela de sugestões: painel sobre o conjunto, página no servidor. Nenhuma chamada ao ML. */
    public function index(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $filtros = $this->filtros($request);

        return Inertia::render('Portal/EstruturaSugestoes', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.sugestoes', PortalContexto::ator()),
            'sugestoes'    => $this->lista->listar($empresa, $filtros, (int) $request->query('pagina', 1)),
            'filtros'      => $filtros,
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            'frete_tabela' => [
                'vigente_desde' => config('estrutura_produtos.frete.vigente_desde'),
                'reputacao'     => config('estrutura_produtos.frete.reputacao'),
            ],
            'vocabulario'  => [
                'fases'      => ['combo' => 'Combo', 'kit' => 'Kit', 'combit' => 'Combit'],
                'logisticas' => ['me2_full' => 'ME2 · Full', 'me2' => 'ME2', 'me1' => 'ME1', 'pendente' => 'Pendente'],
            ],
            // "Montar kit": o que dá para escolher, só quando a janela abre (pode ter milhares de linhas).
            'montagem'     => Inertia::optional(fn () => $this->montagem->catalogo($empresa)),
            // Há o que juntar? Qualquer oferta simples serve — a importada (sem produto) também.
            'montar_disponivel' => EstruturaOferta::query()->where('company_id', $empresa->id)
                ->where('fase', EstruturaOferta::FASE_SIMPLES)->exists(),
        ]);
    }

    // ═══ Escritas por JSON ══════════════════════════════════════════════════

    /** Aceita até `lote_aceite` sugestões; o erro de uma não derruba as outras. */
    public function aceitar(Request $request)
    {
        $dados = $request->validate([
            'sugestoes'         => 'required|array|min:1|max:'.(int) config('estrutura_geracao.lote_aceite'),
            'sugestoes.*'       => 'array',
            'sugestoes.*.chave' => self::regraChave(),
            'sugestoes.*.nome'  => 'nullable|string|max:255',
            // O limite de 120 do SKU é por item, no serviço: o erro de um não derruba o lote.
            'sugestoes.*.sku'   => 'nullable|string|max:255',
        ]);

        // Só chave, nome e sku seguem: qualquer composição vinda do navegador é descartada.
        $pedidos = array_map(
            fn (array $p) => ['chave' => $p['chave'], 'nome' => $p['nome'] ?? null, 'sku' => $p['sku'] ?? null],
            array_values($dados['sugestoes'])
        );

        return response()->json($this->decisoes->aceitar(PortalContexto::empresa(), $pedidos, PortalContexto::ator()));
    }

    public function descartar(Request $request)
    {
        $chaves = $this->chavesValidadas($request);

        return response()->json($this->decisoes->descartar(PortalContexto::empresa(), $chaves, PortalContexto::ator()));
    }

    public function restaurar(Request $request)
    {
        $chaves = $this->chavesValidadas($request);

        return response()->json($this->decisoes->restaurar(PortalContexto::empresa(), $chaves, PortalContexto::ator()));
    }

    /** Tipo e quantidades do produto. Produto de outra empresa responde 404 no serviço. */
    public function definirGeracao(Request $request, int $produto)
    {
        $dados = $request->validate([
            'tipo_id'    => 'nullable|integer',
            'qtd_combo'  => 'nullable|string|max:60',
            'qtd_combit' => 'nullable|string|max:60',
        ]);

        return response()->json($this->decisoes->definirGeracao(
            PortalContexto::empresa(),
            $produto,
            isset($dados['tipo_id']) ? (int) $dados['tipo_id'] : null,
            $dados['qtd_combo'] ?? null,
            $dados['qtd_combit'] ?? null,
            PortalContexto::ator(),
        ));
    }

    /**
     * Cotação real do frete da página (D-18), por ação explícita. Sem conta
     * conectada devolve `conectado: false` sem nenhuma requisição ao ML.
     */
    public function cotarFrete(Request $request)
    {
        $dados = $request->validate([
            'chaves'   => 'required|array|min:1|max:'.(int) config('estrutura_geracao.por_pagina'),
            'chaves.*' => self::regraChave(),
        ]);

        return response()->json($this->lista->cotarPagina(PortalContexto::empresa(), array_values($dados['chaves'])));
    }

    // ═══ Montar kit (09/10/2026) ════════════════════════════════════════════

    /** Prévia ao vivo do "Montar kit": só calcula, nada é gravado. */
    public function previaDaMontagem(Request $request)
    {
        [$componentes, $nome, $sku] = $this->dadosDaMontagem($request, true);

        return response()->json($this->montagem->previa(PortalContexto::empresa(), $componentes, $nome, $sku));
    }

    /** Cria a oferta montada à mão; devolve o atalho para precificá-la. */
    public function montar(Request $request)
    {
        [$componentes, $nome, $sku] = $this->dadosDaMontagem($request, false);

        $r = $this->montagem->gravar(PortalContexto::empresa(), $componentes, $nome, $sku, PortalContexto::ator());

        return response()->json([
            ...$r,
            'precificar_url' => route('portal.auth.estrutura.precificacao', ['q' => $r['oferta']['sku']]),
        ]);
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /**
     * Itens do "Montar kit": cada um pela variação OU pela oferta (a simples sem variação),
     * com a quantidade. Só isso segue para o serviço — o resto do corpo é ignorado.
     *
     * @return array{0: list<array{variacao_id: ?int, oferta_id: ?int, quantidade: int}>, 1: ?string, 2: ?string}
     */
    private function dadosDaMontagem(Request $request, bool $previa): array
    {
        $dados = $request->validate([
            // A prévia aceita a lista vazia (a janela acabou de abrir); gravar, não.
            'componentes'               => [$previa ? 'present' : 'required', 'array', 'max:'.ChaveDeComposicao::MAXIMO_COMPONENTES],
            'componentes.*'             => ['array'],
            'componentes.*.variacao_id' => ['nullable', 'integer', 'min:1', 'required_without:componentes.*.oferta_id'],
            'componentes.*.oferta_id'   => ['nullable', 'integer', 'min:1'],
            'componentes.*.quantidade'  => ['required', 'integer', 'min:1', 'max:999'],
            'nome'                      => ['nullable', 'string', 'max:255'],
            'sku'                       => ['nullable', 'string', 'max:255'],
        ], [
            'componentes.max' => 'Uma oferta pode juntar até '.ChaveDeComposicao::MAXIMO_COMPONENTES.' produtos diferentes.',
            'componentes.required' => 'Escolha os produtos que entram juntos.',
            'componentes.*.quantidade.*' => 'Cada item precisa de uma quantidade entre 1 e 999.',
            'componentes.*.variacao_id.*' => 'Escolha produtos da sua lista.',
            'componentes.*.oferta_id.*' => 'Escolha produtos da sua lista.',
        ]);

        $componentes = array_map(fn (array $c) => [
            'variacao_id' => isset($c['variacao_id']) ? (int) $c['variacao_id'] : null,
            'oferta_id'   => isset($c['oferta_id']) ? (int) $c['oferta_id'] : null,
            'quantidade'  => (int) $c['quantidade'],
        ], array_values($dados['componentes'] ?? []));

        return [$componentes, $dados['nome'] ?? null, $dados['sku'] ?? null];
    }

    /** @return list<string> */
    private function chavesValidadas(Request $request): array
    {
        $dados = $request->validate([
            'chaves'   => 'required|array|min:1|max:'.(int) config('estrutura_geracao.lote_aceite'),
            'chaves.*' => self::regraChave(),
        ]);

        return array_values($dados['chaves']);
    }

    /**
     * Filtros da URL contra listas fechadas: valor desconhecido vira "sem filtro"
     * (a aba, que sempre existe, volta para 'sugestoes').
     *
     * @return array{aba: string, fase: ?string, familia: ?string, tipo: ?string, status: ?string, q: string}
     */
    private function filtros(Request $request): array
    {
        $aba = (string) $request->query('aba', '');
        $fase = (string) $request->query('fase', '');
        $familia = trim((string) $request->query('familia', ''));
        $tipo = (string) $request->query('tipo', '');
        $status = (string) $request->query('status', '');

        // Famílias com os Combos expandidos (08/10): "12,sem". O serviço confere contra as famílias que existem.
        $combos = array_slice(array_values(array_filter(
            explode(',', (string) $request->query('combos', '')),
            fn ($v) => preg_match('/^(\d{1,12}|sem)$/D', $v) === 1
        )), 0, 50);

        return [
            'aba'     => in_array($aba, self::ABAS, true) ? $aba : 'sugestoes',
            'fase'    => in_array($fase, self::FASES, true) ? $fase : null,
            'familia' => $familia !== '' && mb_strlen($familia) <= 20 ? $familia : null,
            'tipo'    => preg_match('/^[a-z0-9-]{2,40}$/D', $tipo) === 1 ? $tipo : null,
            'status'  => in_array($status, ListaDeSugestoes::STATUS, true) ? $status : null,
            'q'       => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'combos'  => $combos,
        ];
    }
}
