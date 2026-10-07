<?php

namespace App\Http\Controllers;

use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Geracao\DecisoesDasSugestoes;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sugestões de ofertas (Fase 168): Combo, Kit e Combit sugeridos a partir dos
 * produtos do Mapeamento Estrutural.
 *
 * Este controller só ORQUESTRA: a regra mora em `App\Services\Portal\Estrutura\Geracao`.
 * D-02 e D-20: a tela mora no Mapeamento, entra pela mesma porta de Produtos
 * (cliente e equipe) e NÃO cria submódulo no menu — a chave ativa é a de Produtos.
 *
 * ### A empresa vem SEMPRE da sessão
 * Tudo é resolvido DENTRO da empresa do `PortalContexto`, nunca pelo corpo da
 * requisição. Um id de outra empresa responde 404, igual a um id que não existe.
 * Do navegador só chegam chaves, nome e SKU; a composição é regerada no servidor.
 *
 * ### Quem escreve
 * Cliente e equipe. O `AtorDoPortal` segue para os serviços e o lado de quem
 * fez vai para o activity log como `origem` (`cliente` ou `interno`).
 *
 * ### Mercado Livre
 * A listagem não faz nenhuma requisição ao ML; a cotação real é a rota `frete`,
 * sob demanda, com teto de chaves e só leitura na conta do cliente (D-18).
 */
class PortalEstruturaSugestoesController extends Controller
{
    /** Chave de composição (1 a 3 componentes), a mesma de `ChaveDeComposicao::valida()`. */
    private const REGRA_CHAVE = ['required', 'string', 'max:100', 'regex:/^v\d+\*\d+(\+v\d+\*\d+){0,2}$/D'];

    private const ABAS = ['sugestoes', 'sem_tipo', 'descartadas'];
    private const FASES = ['combo', 'kit', 'combit'];

    public function __construct(
        private PortalClienteService $portal,
        private ListaDeSugestoes $lista,
        private DecisoesDasSugestoes $decisoes,
    ) {
    }

    // ═══ Visão ══════════════════════════════════════════════════════════════

    /** A tela de sugestões: painel sobre o conjunto, página no servidor. Nenhuma chamada ao ML. */
    public function index(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $filtros = $this->filtros($request);

        return Inertia::render('Portal/EstruturaSugestoes', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', PortalContexto::ator()),
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
        ]);
    }

    // ═══ Escritas por JSON ══════════════════════════════════════════════════

    /** Aceita até `lote_aceite` sugestões; o erro de uma não derruba as outras. */
    public function aceitar(Request $request)
    {
        $dados = $request->validate([
            'sugestoes'         => 'required|array|min:1|max:'.(int) config('estrutura_geracao.lote_aceite'),
            'sugestoes.*'       => 'array',
            'sugestoes.*.chave' => self::REGRA_CHAVE,
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
            'chaves.*' => self::REGRA_CHAVE,
        ]);

        return response()->json($this->lista->cotarPagina(PortalContexto::empresa(), array_values($dados['chaves'])));
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** @return list<string> */
    private function chavesValidadas(Request $request): array
    {
        $dados = $request->validate([
            'chaves'   => 'required|array|min:1|max:'.(int) config('estrutura_geracao.lote_aceite'),
            'chaves.*' => self::REGRA_CHAVE,
        ]);

        return array_values($dados['chaves']);
    }

    /**
     * Filtros da URL contra listas fechadas: valor desconhecido vira "sem filtro"
     * (a aba, que sempre existe, volta para 'sugestoes').
     *
     * @return array{aba: string, fase: ?string, familia: ?string, tipo: ?string, q: string}
     */
    private function filtros(Request $request): array
    {
        $aba = (string) $request->query('aba', '');
        $fase = (string) $request->query('fase', '');
        $familia = trim((string) $request->query('familia', ''));
        $tipo = (string) $request->query('tipo', '');

        return [
            'aba'     => in_array($aba, self::ABAS, true) ? $aba : 'sugestoes',
            'fase'    => in_array($fase, self::FASES, true) ? $fase : null,
            'familia' => $familia !== '' && mb_strlen($familia) <= 20 ? $familia : null,
            'tipo'    => preg_match('/^[a-z0-9-]{2,40}$/D', $tipo) === 1 ? $tipo : null,
            'q'       => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
    }
}
