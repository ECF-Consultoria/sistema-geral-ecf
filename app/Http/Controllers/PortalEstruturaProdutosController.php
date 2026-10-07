<?php

namespace App\Http\Controllers;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\ImportadorProdutos;
use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\PortalClienteService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        private FichaTecnicaDaCategoria $camposDaCategoria,
        private FichaTecnicaDoProduto $fichaTecnica,
        private VariacaoImagensService $imagens,
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

        $mensagem = "Importação concluída: {$r['novos']} novos, {$r['atualizados']} atualizados.";
        if ($r['nao_entraram'] !== []) {
            $mensagem .= ' '.self::resumoDoQueNaoEntrou($r['nao_entraram']);
        }

        return back()->with('success', $mensagem);
    }

    /** Mostra quantas e quais linhas ficaram de fora (até 5; o resto vira "e mais N"). BE-WR-04. */
    private static function resumoDoQueNaoEntrou(array $naoEntraram): string
    {
        $total = count($naoEntraram);
        $itens = array_map(function (array $e) {
            $onde = $e['linha'] !== null ? "linha {$e['linha']}" : 'linha';
            $codigo = $e['codigo'] !== null && $e['codigo'] !== '' ? " ({$e['codigo']})" : '';

            return "{$onde}{$codigo}: ".rtrim(trim($e['motivo']), '.');
        }, array_slice($naoEntraram, 0, 5));

        $texto = ($total === 1 ? '1 linha não entrou: ' : "{$total} linhas não entraram: ").implode('; ', $itens);
        if ($total > 5) {
            $texto .= '; e mais '.($total - 5);
        }

        return $texto.'.';
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

    // ═══ Ficha técnica ══════════════════════════════════════════════════════

    /**
     * Os campos da ficha técnica de uma categoria, em grupos (a tela monta o formulário
     * ao escolher a categoria). Dado público: só o app token sai da nossa casa.
     * Nada é gravado. `indisponivel` quando o catálogo não respondeu ou a categoria
     * não tem campos — a tela segue sem a ficha, nunca com erro. O sigilo da origem
     * dos campos está em {@see FichaTecnicaDaCategoria}.
     */
    public function camposDaCategoria(Request $request)
    {
        $dados = $request->validate(
            ['categoria' => ['required', 'string', 'regex:/^MLB\d{1,15}$/']],
            ['categoria.required' => 'Escolha a categoria.', 'categoria.regex' => 'Categoria inválida.'],
        );

        try {
            $grupos = $this->camposDaCategoria->definicao($dados['categoria']);
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Produtos] campos da categoria falharam', ['erro' => $e->getMessage()]);
            $grupos = [];
        }

        return response()->json(['grupos' => $grupos, 'indisponivel' => $grupos === []]);
    }

    /**
     * Grava a ficha técnica do produto (substitui o que havia). A empresa vem da
     * sessão; produto de outra empresa responde 404, igual ao inexistente. O 404
     * vem ANTES da validação: assim "não é seu" nunca vira "campo faltando".
     */
    public function gravarFichaTecnica(Request $request, int $produto)
    {
        $empresa = PortalContexto::empresa();
        $p = EstruturaProduto::query()->where('company_id', $empresa->id)->findOrFail($produto);

        $request->validate([
            'atributos'           => 'present|array|max:300',
            'atributos.*'         => 'array',
            'atributos.*.id'      => 'required|string|max:80',
            'atributos.*.unidade' => 'nullable|string|max:20',
            'atributos.*.valor'   => ['nullable', function (string $campo, mixed $valor, \Closure $falhou) {
                if (! is_scalar($valor)) {
                    $falhou('Valor inválido.');
                }
            }],
        ]);

        $salvos = $this->fichaTecnica->gravar($empresa, $p, (array) $request->input('atributos'), PortalContexto::ator());

        return response()->json(['salvos' => $salvos, 'mensagem' => 'Ficha técnica salva.']);
    }

    // ═══ Imagens da variação ════════════════════════════════════════════════

    /**
     * Guarda uma ou mais imagens (`imagens[]`) no fim da galeria da variação. A empresa vem
     * da sessão; variação de outra empresa responde 404 ANTES de qualquer validação.
     * Devolve a galeria atualizada. Tudo ou nada: se não couberem todas, nenhuma é guardada.
     */
    public function enviarImagens(Request $request, int $variacao)
    {
        $empresa = PortalContexto::empresa();
        $v = $this->variacaoDaEmpresa($empresa->id, $variacao);

        $this->recusarEnvioQueNaoChegou($request);

        $max = VariacaoImagensService::maxPorVariacao();
        $kb = (int) config('estrutura_produtos.imagens.max_kb', 10240);
        $extensoes = implode(',', (array) config('estrutura_produtos.imagens.extensoes', ['jpg', 'jpeg', 'png', 'webp']));
        $mb = rtrim(rtrim(number_format($kb / 1024, 1, ',', ''), '0'), ',');

        $request->validate([
            'imagens'   => ['required', 'array', 'min:1', "max:{$max}"],
            'imagens.*' => ['file', "mimes:{$extensoes}", "max:{$kb}"],
        ], [
            'imagens.required' => 'Escolha ao menos uma imagem.',
            'imagens.array'    => 'Escolha ao menos uma imagem.',
            'imagens.min'      => 'Escolha ao menos uma imagem.',
            'imagens.max'      => "Cada variação aceita até {$max} imagens. Envie menos de uma vez.",
            'imagens.*.file'   => 'Não foi possível receber a imagem :position. Tente de novo.',
            'imagens.*.mimes'  => 'A imagem :position não está num formato aceito. Envie JPG, PNG ou WebP.',
            'imagens.*.max'    => "A imagem :position passa de {$mb} MB; tente uma menor.",
        ]);

        $arquivos = array_values((array) $request->file('imagens'));
        $galeria = $this->imagens->enviar($empresa, $v, $arquivos, PortalContexto::ator());

        return response()->json(['imagens' => $galeria, 'mensagem' => count($arquivos) === 1 ? 'Imagem enviada.' : 'Imagens enviadas.']);
    }

    /**
     * Entrega o arquivo da imagem (o `<img src>` da tela aponta para cá; o cookie de sessão
     * autentica). Só se a variação E a imagem forem da empresa da sessão; senão, 404 uniforme.
     * Cache só do navegador (`private`): a imagem de uma empresa nunca fica em cache compartilhado.
     */
    public function verImagem(int $variacao, int $imagem)
    {
        $img = $this->imagemDaEmpresa(PortalContexto::empresa()->id, $variacao, $imagem);
        abort_unless($this->imagens->existe($img), 404);

        return Storage::disk(VariacaoImagensService::DISCO)->response($img->caminho, null, [
            'Content-Type'           => $img->mime,
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Remove a imagem (linha e arquivo); as outras sobem de posição e a próxima vira capa se preciso. */
    public function excluirImagem(int $variacao, int $imagem)
    {
        $empresa = PortalContexto::empresa();
        $v = $this->variacaoDaEmpresa($empresa->id, $variacao);
        $img = $this->imagemDaEmpresa($empresa->id, $variacao, $imagem);

        return response()->json([
            'imagens'  => $this->imagens->excluir($empresa, $v, $img, PortalContexto::ator()),
            'mensagem' => 'Imagem excluída.',
        ]);
    }

    /**
     * Regrava a ordem da galeria: `{ordem: [id, id, ...]}`, a 1ª é a capa. Ids que não são da
     * variação são ignorados; as imagens não citadas ficam no fim, como estavam.
     */
    public function ordenarImagens(Request $request, int $variacao)
    {
        $empresa = PortalContexto::empresa();
        $v = $this->variacaoDaEmpresa($empresa->id, $variacao);

        $dados = $request->validate([
            'ordem'   => 'required|array|min:1|max:100',
            'ordem.*' => 'integer',
        ], [
            'ordem.required'  => 'Informe a ordem das imagens.',
            'ordem.*.integer' => 'Ordem inválida.',
        ]);

        return response()->json([
            'imagens'  => $this->imagens->reordenar($empresa, $v, $dados['ordem'], PortalContexto::ator()),
            'mensagem' => 'Ordem das imagens salva.',
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
            // Ficha técnica já salva (a definição dos campos vem do endpoint por categoria).
            'ficha_tecnica' => ['salvos' => $produto ? $this->fichaTecnica->salvos($produto) : []],
            'linhas'       => $this->linhasComImagens($empresa, $produto),
            'listas'       => $this->listasDaEmpresa(),
            'vocabulario'  => $this->vocabulario(),
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            'frete_tabela' => $this->freteTabela(),
            'limites'      => self::LIMITES,
        ]);
    }

    /** As linhas (uma por variação) do produto, cada uma com a sua galeria em `imagens`. */
    private function linhasComImagens(\App\Models\Company $empresa, ?EstruturaProduto $produto): array
    {
        if (! $produto) {
            return [];
        }

        $linhas = $this->linhas->paraProdutos($empresa, [(int) $produto->id]);
        $galerias = $this->imagens->listarDasVariacoes($empresa, array_column($linhas, 'id'));

        return array_map(fn (array $l) => $l + ['imagens' => $galerias[$l['id']] ?? []], $linhas);
    }

    /** A variação da empresa da sessão; de outra empresa ou inexistente, 404 igual. */
    private function variacaoDaEmpresa(int $empresaId, int $variacao): EstruturaProdutoVariacao
    {
        return EstruturaProdutoVariacao::query()->where('company_id', $empresaId)->whereKey($variacao)->firstOrFail();
    }

    /** A imagem, só se ela e a variação forem da empresa da sessão (404 uniforme). */
    private function imagemDaEmpresa(int $empresaId, int $variacao, int $imagem): EstruturaProdutoVariacaoImagem
    {
        $v = $this->variacaoDaEmpresa($empresaId, $variacao);

        return EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresaId)->where('variacao_id', $v->id)->whereKey($imagem)->firstOrFail();
    }

    /**
     * O envio que estourou o limite do PHP chega SEM arquivo e sem erro: quando o corpo passa
     * de `post_max_size` o PHP descarta tudo (`$_POST` e `$_FILES` vazios), e quando um só arquivo
     * passa de `upload_max_filesize` ele vem marcado inválido. Sem esta checagem a tela só veria
     * "Escolha ao menos uma imagem" (ou nada). Aqui vira a mensagem certa.
     *
     * O caso do corpo acima de `post_max_size` NÃO chega até aqui: o `ValidatePostSize` do Laravel
     * o barra antes, com 413 sem texto. Quem o transforma na mesma mensagem é o `withExceptions`
     * de `bootstrap/app.php`. Chega aqui o resto: arquivo marcado inválido, ou corpo grande sem arquivo
     * quando o `post_max_size` do servidor é maior que o limite que o Laravel enxerga.
     */
    private function recusarEnvioQueNaoChegou(Request $request): void
    {
        $grande = VariacaoImagensService::MENSAGEM_GRANDE_DEMAIS;

        $recebidos = $request->file('imagens');
        $recebidos = is_array($recebidos) ? $recebidos : ($recebidos ? [$recebidos] : []);

        foreach ($recebidos as $arquivo) {
            if ($arquivo instanceof UploadedFile && ! $arquivo->isValid()) {
                $tamanho = in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

                throw ValidationException::withMessages(['imagens' => $tamanho ? $grande : 'Não foi possível receber a imagem. Tente de novo.']);
            }
        }

        if ($recebidos === []) {
            $corpo = (int) $request->server('CONTENT_LENGTH', 0);
            $limitePhp = self::bytesDoIni((string) ini_get('post_max_size'));

            // Corpo de mais de 1 MB sem arquivo algum, ou acima do `post_max_size`, é o PHP que descartou.
            if ($corpo > 1048576 || ($limitePhp > 0 && $corpo > $limitePhp)) {
                throw ValidationException::withMessages(['imagens' => $grande]);
            }
        }
    }

    /** "8M" / "512K" / "1G" / "1048576" do php.ini em bytes (0 = sem limite). */
    private static function bytesDoIni(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '' || $valor === '0' || $valor === '-1') {
            return 0;
        }

        $numero = (int) $valor;

        return match (strtolower(substr($valor, -1))) {
            'g'     => $numero * 1073741824,
            'm'     => $numero * 1048576,
            'k'     => $numero * 1024,
            default => $numero,
        };
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
