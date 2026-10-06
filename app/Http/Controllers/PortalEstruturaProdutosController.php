<?php

namespace App\Http\Controllers;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
    /** Limites mostrados na tela (o servidor valida por conta própria). */
    private const LIMITES = ['colar' => 200, 'arquivo_mb' => 2, 'linhas_arquivo' => 1000];

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
            'vocabulario' => $this->vocabulario(),
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            'frete_tabela' => $this->freteTabela(),
            'limites' => self::LIMITES,
        ]);
    }

    /** Ficha de produto novo (D-27): página inteira, sem painel. */
    public function novo()
    {
        return $this->renderFicha(null);
    }

    /**
     * Ficha do produto (D-27). Produto de outra empresa responde 404, igual ao
     * inexistente — dizer "proibido" confirmaria que ele existe.
     */
    public function ficha(int $produto)
    {
        $p = EstruturaProduto::query()
            ->where('company_id', PortalContexto::empresa()->id)
            ->findOrFail($produto);

        return $this->renderFicha($p);
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
            $this->linhasComoVieram($request),
            PortalContexto::ator(),
            ProdutoCadastroService::MODO_GRADE,
        );

        return response()->json([...$resultado, 'listas' => $this->listasDaEmpresa()]);
    }

    /**
     * As linhas como o navegador mandou. No contrato de linha `null` explícito LIMPA
     * o campo (custo, eixo, valor, família) e texto vazio é "não mexi"; o middleware
     * global `ConvertEmptyStringsToNull` transformaria todo `''` em `null` e um campo
     * vazio por engano apagaria o dado. Por isso o JSON é lido cru — a estrutura é a
     * mesma que o `validate` acima já conferiu.
     *
     * @return array<int, mixed>
     */
    private function linhasComoVieram(Request $request): array
    {
        if ($request->isJson()) {
            $corpo = json_decode((string) $request->getContent(), true);
            if (is_array($corpo) && is_array($corpo['linhas'] ?? null)) {
                return array_values($corpo['linhas']);
            }
        }

        return (array) $request->input('linhas');
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

    // ═══ Planilha: modelo e importação ══════════════════════════════════════

    /** Baixa a planilha-modelo (D-13): os 11 cabeçalhos e uma linha de exemplo. */
    public function modelo()
    {
        return response()->streamDownload(
            fn () => (new Xlsx(ModeloProdutosXlsx::gerar()))->save('php://output'),
            'modelo-produtos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Lê o arquivo e mostra o que mudaria — nada é gravado. */
    public function previaImportacao(Request $request)
    {
        $request->validate(['arquivo' => 'required|file|max:2048|mimes:xlsx']);
        $caminho = $this->caminhoDoUpload($request);

        return response()->json($this->importador->previa(PortalContexto::empresa(), $caminho));
    }

    /** Grava de verdade. Falha geral volta como erro em `arquivo`; sucesso, como flash `success`. */
    public function aplicarImportacao(Request $request)
    {
        $request->validate(['arquivo' => 'required|file|max:2048|mimes:xlsx']);
        $caminho = $this->caminhoDoUpload($request);

        $r = $this->importador->aplicar(PortalContexto::empresa(), $caminho, PortalContexto::ator());

        if (isset($r['erro_geral'])) {
            return back()->withErrors(['arquivo' => $r['erro_geral']]);
        }

        return back()->with('success', "Importação concluída: {$r['novos']} novos, {$r['atualizados']} atualizados.");
    }

    // ═══ Categoria do Mercado Livre ═════════════════════════════════════════

    /**
     * Busca categorias pelo nome. Dado público: o serviço usa o APP token, nunca
     * o token do cliente. O preditor do ML degrada para lista vazia quando cai;
     * por isso lista vazia sai com `indisponivel` (a tela diz "nada encontrado
     * ou Mercado Livre fora do ar") e nunca com erro.
     */
    public function buscarCategorias(Request $request)
    {
        $dados = $request->validate(['q' => 'required|string|min:2|max:120']);

        try {
            $categorias = [];
            foreach ($this->categorias->sugerir($dados['q']) as $c) {
                $categorias[] = $this->categoriaParaTela($c, $this->categorias->detalhe($c['id'])['folha'] ?? null);
            }
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Produtos] busca de categoria falhou', ['erro' => $e->getMessage()]);

            return response()->json(['categorias' => [], 'indisponivel' => true]);
        }

        return response()->json(['categorias' => $categorias, 'indisponivel' => $categorias === []]);
    }

    /**
     * Sugere a categoria de até 10 produtos pelo nome (D-06). NÃO grava nada:
     * aceitar é decisão da pessoa, pelo POST de linhas ("Aceitar marcadas").
     */
    public function sugerirCategorias(Request $request)
    {
        $dados = $request->validate([
            'produto_ids'   => 'required|array|min:1|max:10',
            'produto_ids.*' => 'integer',
        ]);

        // Só produtos da empresa da sessão: id de fora simplesmente não aparece.
        $produtos = EstruturaProduto::query()
            ->where('company_id', PortalContexto::empresa()->id)
            ->whereIn('id', $dados['produto_ids'])
            ->orderBy('id')
            ->get(['id', 'nome']);

        $sugestoes = [];
        $falhou = false;
        foreach ($produtos as $produto) {
            $achada = null;
            try {
                foreach ($this->categorias->sugerir($produto->nome) as $c) {
                    if ($this->categorias->detalhe($c['id'])['folha'] ?? false) {
                        $achada = $this->categoriaParaTela($c, true);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[Estrutura Produtos] sugestão de categoria falhou', ['produto' => $produto->id, 'erro' => $e->getMessage()]);
                $falhou = true;
            }

            $sugestoes[] = [
                'produto_id' => (int) $produto->id,
                'nome'       => $produto->nome,
                'sugestao'   => $achada ? ['id' => $achada['id'], 'nome' => $achada['nome'], 'caminho_texto' => $achada['caminho_texto']] : null,
            ];
        }

        return response()->json([
            'sugestoes'    => $sugestoes,
            'indisponivel' => $falhou || ($sugestoes !== [] && collect($sugestoes)->every(fn ($s) => $s['sugestao'] === null)),
        ]);
    }

    // ═══ Frete ══════════════════════════════════════════════════════════════

    /**
     * Consulta de fretes pela conta conectada (D-16). Sem conta, devolve só a
     * estimativa da tabela e `conectado: false`, sem nenhuma requisição ao ML.
     * Nada é gravado: o frete cotado fica em cache, nunca em precificação.
     */
    public function cotarFretes(Request $request)
    {
        $dados = $request->validate([
            'variacao_ids'   => 'required|array|min:1|max:200',
            'variacao_ids.*' => 'integer',
        ]);

        $empresa = PortalContexto::empresa();
        $variacoes = EstruturaProdutoVariacao::query()
            ->where('company_id', $empresa->id)
            ->whereIn('id', $dados['variacao_ids'])
            ->with('volumes')
            ->get();

        $itens = [];
        foreach ($variacoes as $v) {
            $volumes = $v->volumes
                ->map(fn ($x) => ['c' => (float) $x->comprimento, 'l' => (float) $x->largura, 'a' => (float) $x->altura, 'kg' => (float) $x->peso])
                ->values()->all();
            $log = LogisticaProduto::daVolumes($volumes);

            $itens[$v->id] = [
                'pacote'        => $log['pacote'],
                'peso_faturado' => $log['peso_faturado'],
                'logistica'     => $log['logistica'],
                'custo'         => $v->custo,
            ];
        }

        return response()->json($this->frete->cotar($empresa, $itens));
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    private function renderFicha(?EstruturaProduto $produto)
    {
        $empresa = PortalContexto::empresa();

        return Inertia::render('Portal/EstruturaProdutoFicha', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.produtos', PortalContexto::ator()),
            'produto'      => $produto ? ['id' => (int) $produto->id, 'nome' => $produto->nome] : null,
            'linhas'       => $produto ? $this->linhas->paraProdutos($empresa, [(int) $produto->id]) : [],
            'listas'       => $this->listasDaEmpresa(),
            'vocabulario'  => $this->vocabulario(),
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            'frete_tabela' => $this->freteTabela(),
            'limites'      => self::LIMITES,
        ]);
    }

    /** @return array{eixos: array, logisticas: array, pendencias: array} */
    private function vocabulario(): array
    {
        return [
            'eixos'      => EstruturaProdutoVariacao::EIXOS,
            'logisticas' => ['me2_full' => 'ME2 · Full', 'me2' => 'ME2', 'me1' => 'ME1', 'pendente' => 'Pendente'],
            'pendencias' => PendenciasDoProduto::ROTULOS,
        ];
    }

    /** @return array{vigente_desde: mixed, reputacao: mixed} */
    private function freteTabela(): array
    {
        return [
            'vigente_desde' => config('estrutura_produtos.frete.vigente_desde'),
            'reputacao'     => config('estrutura_produtos.frete.reputacao'),
        ];
    }

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

    /**
     * Caminho REAL do arquivo temporário do upload (nunca um caminho vindo do
     * cliente). `.xlsm` é recusado mesmo com MIME de zip: macro não entra (T-167-44).
     */
    private function caminhoDoUpload(Request $request): string
    {
        $arquivo = $request->file('arquivo');
        if (strtolower((string) $arquivo->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['arquivo' => 'Envie a planilha no formato .xlsx.']);
        }

        return (string) $arquivo->getRealPath();
    }

    /** @param  array{id: string, nome: string, caminho: array<int, array{id: string, nome: string}>}  $c */
    private function categoriaParaTela(array $c, ?bool $folha): array
    {
        return [
            'id'            => $c['id'],
            'nome'          => $c['nome'],
            'caminho'       => $c['caminho'],
            'caminho_texto' => implode(' > ', array_column($c['caminho'], 'nome')),
            'folha'         => $folha,
        ];
    }
}
