<?php

namespace App\Http\Controllers;

use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Produtos — o primeiro submódulo do Mapeamento Estrutural (Fase 167): o
 * cadastro do produto e das variações dele, antes de qualquer oferta.
 *
 * Este controller só ORQUESTRA: a regra mora nos serviços de
 * `App\Services\Portal\Estrutura\Produtos`.
 *
 * ### A empresa vem SEMPRE da sessão
 * Tudo é resolvido DENTRO da empresa do `PortalContexto` — nunca pelo corpo da
 * requisição. Um id de outra empresa responde 404, igual a um id que não
 * existe: dizer "proibido" confirmaria que ele existe em algum lugar.
 *
 * ### Quem escreve
 * Cliente e equipe (sessão de equipe no portal). O `AtorDoPortal` segue para
 * os serviços e o lado de quem fez vai para o activity log como `origem`
 * (`cliente` ou `interno`).
 */
class PortalEstruturaProdutosController extends Controller
{
    public function __construct(
        private PortalClienteService $portal,
        private ProdutoLinhas $linhas,
        private ProdutoCadastroService $cadastro,
        private ListasDaEmpresaService $listas,
        private ImportadorProdutos $importador,
        private CategoriaSugestaoService $categorias,
        private FreteMe2Service $frete,
    ) {
    }

    // ═══ Visão ══════════════════════════════════════════════════════════════

    /** Produtos: a grade de cadastro, com as listas de família e ambiente da empresa. */
    public function index(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $busca = (string) $request->query('q', '');

        return Inertia::render('Portal/EstruturaProdutos', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', PortalContexto::ator()),
            'produtos'    => $this->linhas->pagina($empresa, $busca, (int) $request->query('pagina', 1)),
            'filtros'     => ['q' => $busca],
            'listas'      => $this->listasDaEmpresa(),
            'vocabulario' => [
                'eixos'      => EstruturaProdutoVariacao::EIXOS,
                'logisticas' => ['me2_full' => 'ME2 · Full', 'me2' => 'ME2', 'me1' => 'ME1', 'pendente' => 'Pendente'],
                'pendencias' => PendenciasDoProduto::ROTULOS,
            ],
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            'frete_tabela' => [
                'vigente_desde' => config('estrutura_produtos.frete.vigente_desde'),
                'reputacao'     => config('estrutura_produtos.frete.reputacao'),
            ],
            'limites' => ['colar' => 200, 'arquivo_mb' => 2, 'linhas_arquivo' => 1000],
        ]);
    }

    // ═══ Escritas por JSON ══════════════════════════════════════════════════

    /**
     * Grava as linhas da grade (até 200). Erro de uma linha não impede as
     * outras: o resultado traz `erros` por linha e `linhas` já calculadas no
     * servidor (logística, peso cubado, frete, pendências).
     */
    public function gravarLinhas(Request $request)
    {
        $request->validate([
            'linhas'   => 'required|array|min:1|max:'.ProdutoCadastroService::MAX_GRADE,
            'linhas.*' => 'array',
        ]);

        $resultado = $this->cadastro->gravarLinhas(
            PortalContexto::empresa(),
            $request->input('linhas'),
            PortalContexto::ator(),
            ProdutoCadastroService::MODO_GRADE,
        );

        return response()->json([...$resultado, 'listas' => $this->listasDaEmpresa()]);
    }

    /** Exclui uma variação (D-22: mesma regra da Lista SKUs). */
    public function excluirVariacao(int $variacao)
    {
        $res = $this->cadastro->excluirVariacao(PortalContexto::empresa(), $variacao, PortalContexto::ator());

        $mensagem = "Variação {$res['sku']} excluída.";
        if ($res['anuncios_para_espera'] > 0) {
            $mensagem .= " {$res['anuncios_para_espera']} anúncio(s) voltaram para a área de espera.";
        }

        return response()->json([
            'produto_excluido'     => $res['produto_excluido'],
            'anuncios_para_espera' => $res['anuncios_para_espera'],
            'mensagem'             => $mensagem,
        ]);
    }

    public function criarFamilia(Request $request)
    {
        return $this->criarLista($request, ListasDaEmpresaService::FAMILIA);
    }

    public function renomearFamilia(Request $request, int $lista)
    {
        return $this->renomearLista($request, ListasDaEmpresaService::FAMILIA, $lista);
    }

    public function excluirFamilia(int $lista)
    {
        return $this->excluirLista(ListasDaEmpresaService::FAMILIA, $lista);
    }

    public function criarAmbiente(Request $request)
    {
        return $this->criarLista($request, ListasDaEmpresaService::AMBIENTE);
    }

    public function renomearAmbiente(Request $request, int $lista)
    {
        return $this->renomearLista($request, ListasDaEmpresaService::AMBIENTE, $lista);
    }

    public function excluirAmbiente(int $lista)
    {
        return $this->excluirLista(ListasDaEmpresaService::AMBIENTE, $lista);
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    /** @return array{familias: list<array>, ambientes: list<array>} */
    private function listasDaEmpresa(): array
    {
        $empresa = PortalContexto::empresa();

        return [
            'familias'  => $this->listas->lista($empresa, ListasDaEmpresaService::FAMILIA),
            'ambientes' => $this->listas->lista($empresa, ListasDaEmpresaService::AMBIENTE),
        ];
    }

    private function criarLista(Request $request, string $tipo)
    {
        $dados = $request->validate(['nome' => 'required|string|max:80']);
        [$item, $criado] = $this->listas->criar(PortalContexto::empresa(), $tipo, $dados['nome'], PortalContexto::ator());

        return response()->json([
            'item'   => ['id' => (int) $item->id, 'nome' => $item->nome],
            'criado' => $criado,
            'listas' => $this->listasDaEmpresa(),
        ]);
    }

    private function renomearLista(Request $request, string $tipo, int $id)
    {
        $dados = $request->validate(['nome' => 'required|string|max:80']);
        $item = $this->listas->renomear(PortalContexto::empresa(), $tipo, $id, $dados['nome'], PortalContexto::ator());

        return response()->json([
            'item'   => ['id' => (int) $item->id, 'nome' => $item->nome],
            'listas' => $this->listasDaEmpresa(),
        ]);
    }

    private function excluirLista(string $tipo, int $id)
    {
        $this->listas->excluir(PortalContexto::empresa(), $tipo, $id, PortalContexto::ator());

        return response()->json(['listas' => $this->listasDaEmpresa()]);
    }
}
