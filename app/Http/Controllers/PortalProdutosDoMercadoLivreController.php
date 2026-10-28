<?php

namespace App\Http\Controllers;

use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\ProdutosDoMercadoLivreService;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Produtos → "Importar do Mercado Livre" (28/10/2026): os anúncios da conta, com o
 * diagnóstico de cada um, e a importação (por anúncio ou em lote) para o cadastro de Produtos.
 *
 * Só orquestra: a regra mora em {@see ProdutosDoMercadoLivreService}. A empresa vem SEMPRE do
 * `PortalContexto`; um MLB de outra conta não está no acervo da empresa e é ignorado.
 *
 * Aqui a tela PODE dizer "Mercado Livre": é a página de importar os anúncios dele (decisão do
 * usuário, 28/10/2026 — o produto importado leva o selo "Importado do Mercado Livre"). O sigilo
 * da ficha técnica continua valendo na ficha.
 */
class PortalProdutosDoMercadoLivreController extends Controller
{
    public function __construct(
        private PortalClienteService $portal,
        private ProdutosDoMercadoLivreService $servico,
    ) {}

    public function pagina(Request $request)
    {
        $empresa = PortalContexto::empresa();

        return Inertia::render('Portal/EstruturaProdutosDoMl', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', PortalContexto::ator()),
            'conectado' => AnunciosMercadoLivreService::conectado($empresa),
            // Os que já estão no Portal começam ESCONDIDOS (28/10/2026, o usuário): a caixa da tela é
            // "Mostrar os que já estão no Portal", desmarcada.
            'anuncios'  => $this->servico->candidatos($empresa, soNovos: true),
            'estado'    => $this->servico->estado($empresa),
            'limites'   => ['por_importacao' => ProdutosDoMercadoLivreService::MAX_POR_IMPORTACAO],
        ]);
    }

    public function anuncios(Request $request)
    {
        $dados = $request->validate([
            'q'        => ['nullable', 'string', 'max:120'],
            'ordem'    => ['nullable', 'string', 'in:'.implode(',', ProdutosDoMercadoLivreService::ORDENS)],
            'pagina'   => ['nullable', 'integer', 'min:1', 'max:10000'],
            'mostrar_no_portal' => ['nullable', 'boolean'],
            'situacao' => ['nullable', 'string', 'in:'.implode(',', ProdutosDoMercadoLivreService::SITUACOES)],
        ]);

        return response()->json($this->servico->candidatos(
            PortalContexto::empresa(),
            (string) ($dados['q'] ?? ''),
            (string) ($dados['ordem'] ?? 'oportunidade'),
            (int) ($dados['pagina'] ?? 1),
            ! (bool) ($dados['mostrar_no_portal'] ?? false),
            (string) ($dados['situacao'] ?? 'todos'),
        ));
    }

    public function diagnostico(Request $request)
    {
        $dados = $request->validate([
            'mlbs'   => ['required', 'array', 'max:'.ProdutosDoMercadoLivreService::POR_PAGINA],
            'mlbs.*' => ['string', 'max:30'],
        ]);

        return response()->json($this->servico->diagnosticar(PortalContexto::empresa(), $dados['mlbs']));
    }

    public function importar(Request $request)
    {
        $dados = $request->validate([
            'mlbs'   => ['required', 'array', 'max:'.ProdutosDoMercadoLivreService::MAX_POR_IMPORTACAO],
            'mlbs.*' => ['string', 'max:30'],
        ], [
            'mlbs.required' => 'Escolha pelo menos um anúncio.',
            'mlbs.max'      => 'Escolha até '.ProdutosDoMercadoLivreService::MAX_POR_IMPORTACAO.' anúncios por vez.',
        ]);

        return response()->json($this->servico->iniciar(PortalContexto::empresa(), $dados['mlbs'], PortalContexto::ator()));
    }

    public function estado()
    {
        return response()->json($this->servico->estado(PortalContexto::empresa()));
    }

    public function limpar()
    {
        $empresa = PortalContexto::empresa();
        $this->servico->limpar($empresa);

        return response()->json($this->servico->estado($empresa));
    }
}
