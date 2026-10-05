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
}
