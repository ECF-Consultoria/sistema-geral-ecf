<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EstruturaAgendaItem;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\PubRascunho;
use App\Models\PubValidacao;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\ColagemAnunciosService;
use App\Services\Portal\Estrutura\EstruturaAgendaService;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\EstruturaPublicacaoService;
use App\Services\Portal\Estrutura\EstruturaVisaoService;
use App\Services\Portal\PortalClienteService;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\PortalContexto;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Mapeamento Estrutural — o mecanismo da planilha do Projeto Polos como módulo
 * do Portal do Cliente. Decisões: `.planning/adrs/PORTAL-01-mapeamento-estrutural-schema.md`.
 *
 * Duas visões (Ofertas e Agenda) e as escritas das duas. Só portal
 * AUTENTICADO: o token está aposentado (`AposentaTokenDoPortal`).
 *
 * ### A empresa vem SEMPRE da sessão
 * Toda oferta, anúncio, linha da espera e item de agenda é resolvido pelo id
 * DENTRO da empresa do `PortalContexto` — nunca por route model binding solto.
 * Um id de outra empresa responde 404, igual a um id que não existe: dizer
 * "proibido" confirmaria que ele existe em algum lugar.
 *
 * ### Quem escreve
 * Cliente e equipe (sessão de equipe no portal). O lado de quem fez vai para o
 * activity log como `origem`, pelo `RegistroEstrutura`.
 */
class PortalEstruturaController extends Controller
{
    public function __construct(
        private PortalClienteService $portal,
        private EstruturaVisaoService $visao,
        private EstruturaOfertaService $ofertas,
        private EstruturaAnuncioService $anuncios,
        private ColagemAnunciosService $colagem,
        private EstruturaAgendaService $agenda,
        private AnunciosMercadoLivreService $anunciosMl,
        private EstruturaPrecificacaoService $precificacao,
        private EstruturaPublicacaoService $publicacoes,
    ) {
    }

    // ═══ Visões ═════════════════════════════════════════════════════════════

    /**
     * A porta do módulo. Sem nada na URL, abre a Lista SKUs — o primeiro passo
     * de quem começa do zero. Os links antigos (`?abrir=ID` da agenda,
     * `?abrir=ID&metricas=1` da Jardinagem, `?q=`/`?situacao=`/`?pagina=`)
     * eram da página que hoje é o Mapeamento: vão para ela, com a query intacta.
     */
    public function entrada(Request $request)
    {
        $daVisaoAntiga = $request->hasAny(['abrir', 'q', 'situacao', 'pagina', 'metricas']);

        return redirect()->route(
            $daVisaoAntiga ? 'portal.auth.estrutura.mapeamento' : 'portal.auth.estrutura.lista',
            $request->query(),
        );
    }

    /** Lista SKUs: os produtos e as variações deles — sem anúncio nem métrica. */
    public function lista(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $busca = (string) $request->query('q', '');

        return Inertia::render('Portal/EstruturaLista', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.lista', PortalContexto::ator()),
            'estrutura'   => $this->visao->paginaOfertas($empresa, 'todas', $busca, (int) $request->query('pagina', 1)),
            'filtros'     => ['q' => $busca],
            'vocabulario' => EstruturaVisaoService::vocabulario(),
            'ml_conectado'   => AnunciosMercadoLivreService::conectado($empresa),
            'opcoes_ofertas' => Inertia::optional(fn () => $this->visao->opcoesDeOfertas($empresa)),
        ]);
    }

    /** Anúncios: SKU · código MLB · título · tipo · catálogo, como a aba da planilha. */
    public function anunciosIndex(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $busca = (string) $request->query('q', '');

        return Inertia::render('Portal/EstruturaAnuncios', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.anuncios', PortalContexto::ator()),
            'estrutura'   => $this->visao->paginaOfertas($empresa, 'todas', $busca, (int) $request->query('pagina', 1)),
            'filtros'     => ['q' => $busca],
            'vocabulario' => EstruturaVisaoService::vocabulario(),
            'ml_conectado'   => AnunciosMercadoLivreService::conectado($empresa),
            'espera_linhas'  => Inertia::optional(fn () => $this->visao->espera($empresa)),
            'opcoes_ofertas' => Inertia::optional(fn () => $this->visao->opcoesDeOfertas($empresa)),
        ]);
    }

    /**
     * Precificação: os produtos da Lista SKUs com custo, fretes e o preço de
     * Clássico e Premium — a conta da Calculadora de Custo, feita no PHP
     * (ADR PORTAL-02). O resumo é da empresa inteira; o detalhe, da página.
     */
    public function precificacaoIndex(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $busca = (string) $request->query('q', '');
        $estrutura = $this->visao->paginaOfertas($empresa, 'todas', $busca, (int) $request->query('pagina', 1));
        $ids = array_merge(...array_map(fn ($b) => array_column($b['ofertas'], 'id'), $estrutura['blocos'] ?: [['ofertas' => []]]));

        return Inertia::render('Portal/EstruturaPrecificacao', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.precificacao', PortalContexto::ator()),
            'estrutura'    => $estrutura,
            'precificacao' => $this->precificacao->pagina($empresa, $ids),
            'filtros'      => ['q' => $busca],
            'vocabulario'  => EstruturaVisaoService::vocabulario(),
        ]);
    }

    public function salvarParametrosPreco(Request $request)
    {
        $this->precificacao->salvarParametros(PortalContexto::empresa(), $request->all(), PortalContexto::ator());

        return back()->with('success', 'Parâmetros de preço salvos. Os preços foram recalculados.');
    }

    public function salvarPrecificacao(Request $request, int $oferta)
    {
        $registro = $this->oferta($oferta);
        $this->precificacao->salvarOferta($registro, $request->only([
            'custo', 'frete_classico', 'frete_premium', ...EstruturaPrecificacao::EXCECOES,
        ]), PortalContexto::ator());

        return back()->with('success', "Preço de {$registro->sku} salvo.");
    }

    /** Mapeamento: o pós-publicação — situação de cada oferta, estação do produto, métricas. */
    public function mapeamento(Request $request)
    {
        $empresa = PortalContexto::empresa();

        $filtro = (string) $request->query('situacao', 'todas');
        $busca = (string) $request->query('q', '');
        $pagina = (int) $request->query('pagina', 1);

        // Pré-aquece a parte lenta das métricas (os pedidos da loja) na fila
        // high: quando a estação do produto abrir, ela costuma já estar pronta.
        $this->anunciosMl->aquecerVendas($empresa);

        return Inertia::render('Portal/EstruturaMapeamento', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.mapeamento', PortalContexto::ator()),
            'estrutura'   => $this->visao->paginaOfertas($empresa, $filtro, $busca, $pagina),
            'filtros'     => ['situacao' => $filtro, 'q' => $busca],
            'vocabulario' => EstruturaVisaoService::vocabulario(),
            // A conta do ML está conectada? Liga as métricas da estação e a
            // busca nos anúncios da conta.
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            // Só quando o diálogo pede — a lista de ofertas pode ter milhares
            // de linhas, e a maioria das visitas não a abre.
            'opcoes_ofertas' => Inertia::optional(fn () => $this->visao->opcoesDeOfertas($empresa)),
        ]);
    }

    public function agendaIndex()
    {
        $empresa = PortalContexto::empresa();

        return Inertia::render('Portal/EstruturaAgenda', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.planejamento', PortalContexto::ator()),
            'agenda'      => $this->visao->agenda($empresa),
            'vocabulario' => EstruturaVisaoService::vocabulario(),
        ]);
    }

    /**
     * Anunciar: publicar o par Clássico + Premium de cada oferta no Mercado
     * Livre, pelo portal (ADR PORTAL-03). A lista da esquerda vem paginada do
     * servidor; o formulário da oferta escolhida vem por JSON
     * (`abrirPublicacao`), como a estação do produto.
     */
    public function anunciarIndex(Request $request)
    {
        $empresa = PortalContexto::empresa();
        $filtro = (string) $request->query('filtro', 'a_anunciar');
        $busca = (string) $request->query('q', '');

        $pagina = $this->publicacoes->pagina($empresa, $filtro, $busca, (int) $request->query('pagina', 1));
        $piloto = PortalPublicadorController::noPiloto($empresa);
        if ($piloto) {
            $pagina['ofertas'] = $this->comProntidaoDoPublicador($pagina['ofertas']);
        }

        return Inertia::render('Portal/EstruturaAnunciar', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA.'.anunciar', PortalContexto::ator()),
            'anunciar'     => $pagina,
            'filtros'      => ['filtro' => $filtro, 'q' => $busca],
            'vocabulario'  => EstruturaVisaoService::vocabulario(),
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            // Piloto do Publicador novo (variações, fotos por grupo, conferência em fila).
            'publicador_novo' => $piloto,
        ]);
    }

    /** Piloto do Publicador: o selo de cada card vem do rascunho novo, não do par antigo. */
    private function comProntidaoDoPublicador(array $ofertas): array
    {
        $rascunhos = PubRascunho::with('produto:id,oferta_id')
            ->whereHas('produto', fn ($q) => $q->whereIn('oferta_id', array_column($ofertas, 'id')))
            ->get()->keyBy(fn (PubRascunho $r) => $r->produto->oferta_id);
        $ultimas = PubValidacao::whereIn('rascunho_id', $rascunhos->pluck('id'))->orderBy('id')->get()->keyBy('rascunho_id');

        return array_map(function ($o) use ($rascunhos, $ultimas) {
            $r = $rascunhos[$o['id']] ?? null;

            return [...$o, 'prontidao' => EditorRascunhoService::prontidao($r, $r ? ($ultimas[$r->id] ?? null) : null)];
        }, $ofertas);
    }

    // ═══ Anunciar — o par de uma oferta (JSON) ══════════════════════════════

    /** O formulário da oferta: dados efetivos, referências, categoria e pendências. Não grava. */
    public function abrirPublicacao(int $oferta)
    {
        return response()->json($this->publicacoes->abrir($this->oferta($oferta)));
    }

    /** O rascunho, salvo sozinho pela tela. A regra de negócio mora no service. */
    public function salvarPublicacao(Request $request, int $oferta)
    {
        $registro = $this->oferta($oferta);
        $pub = $this->publicacoes->salvarRascunho($registro, $this->dadosPublicacao($request), PortalContexto::ator());

        // A tela precisa saber se o que ficou salvo ainda é o que o ML aprovou,
        // e o que ainda falta — as duas coisas saem da mesma leitura.
        $aberto = $this->publicacoes->abrir($registro);

        return response()->json(['publicacao' => $aberto['publicacao'], 'pendencias' => $aberto['pendencias'], 'status' => $pub->status]);
    }

    /** Uma foto do computador → Mercado Livre → `{id, url}` no rascunho. */
    public function fotoPublicacao(Request $request, int $oferta)
    {
        $registro = $this->oferta($oferta);
        $request->validate(['imagem' => ['required', 'file', 'image', 'max:10240']]);

        $arquivo = $request->file('imagem');
        $pub = $this->publicacoes->enviarFoto($registro, $arquivo->get(), $arquivo->getClientOriginalName(), PortalContexto::ator());

        return response()->json(['fotos' => $pub->dados['fotos'], 'status' => $pub->status]);
    }

    /** Conferir com o Mercado Livre (pendências locais, depois /items/validate). */
    public function validarPublicacao(int $oferta)
    {
        return response()->json($this->publicacoes->validar($this->oferta($oferta), PortalContexto::ator()));
    }

    /** Publicar o par (ou o que falta dele). Síncrono: dois POST /items. */
    public function publicarPublicacao(int $oferta)
    {
        return response()->json($this->publicacoes->publicar($this->oferta($oferta), PortalContexto::ator()));
    }

    /** Categorias do ML para um texto — a sugestão pelo título e a busca do "trocar". */
    public function categoriasAnunciar(Request $request)
    {
        $dados = $request->validate(['q' => ['required', 'string', 'max:200']]);

        return response()->json($this->publicacoes->categorias($dados['q']));
    }

    /** A meta de uma categoria: caminho, limite do título, atributos obrigatórios. */
    public function categoriaAnunciar(string $categoria)
    {
        $meta = $this->publicacoes->categoria($categoria);

        abort_if($meta === null, 404);

        return response()->json($meta);
    }

    // ═══ Ofertas ════════════════════════════════════════════════════════════

    public function criarOferta(Request $request)
    {
        $dados = $this->dadosOferta($request);
        // "+ Produto" com anúncios escolhidos na lista do ML: cria e liga junto.
        $mlbs = $request->validate([
            'anuncios_ml'   => ['nullable', 'array', 'max:50'],
            'anuncios_ml.*' => ['string', 'max:30'],
        ])['anuncios_ml'] ?? [];

        if ($mlbs) {
            [$oferta, $absorvidos, $ligados] = $this->anunciosMl->criarOfertaComAnuncios(PortalContexto::empresa(), $dados, $mlbs, PortalContexto::ator());

            return back()->with('success', $this->mensagemOferta("Oferta {$oferta->sku} criada com {$ligados} anúncio(s) do Mercado Livre.", $absorvidos));
        }

        // Lista SKUs: o produto simples já nasce com os combos marcados na
        // pergunta "dá combo? em quantas unidades?". Só no cadastro à mão — com
        // anúncios do ML escolhidos, os combos seguem pelo "+ Variação".
        $combos = $dados['fase'] === EstruturaOferta::FASE_SIMPLES
            ? ($request->validate([
                'combos'   => ['nullable', 'array', 'max:50'],
                'combos.*' => ['integer', 'min:2', 'max:999'],
            ])['combos'] ?? [])
            : [];

        [$oferta, $absorvidos, $r] = $this->ofertas->criarComCombos(PortalContexto::empresa(), $dados, $combos, PortalContexto::ator());

        $texto = $r['criados']
            ? "Produto {$oferta->sku} criado com ".count($r['criados']).' combo(s): '.implode(', ', $r['criados']).'.'
            : "Oferta {$oferta->sku} criada.";

        return back()->with('success', $this->mensagemOferta($texto, $absorvidos + $r['absorvidos']));
    }

    /** Vários combos de um produto de uma vez ("2, 3, 4, 5, 6"). */
    public function criarCombos(Request $request, int $oferta)
    {
        $dados = $request->validate([
            'quantidades'   => ['required', 'array', 'min:1', 'max:50'],
            'quantidades.*' => ['integer', 'min:2', 'max:999'],
            'logistica'     => ['nullable', Rule::in(array_keys(EstruturaOferta::LOGISTICAS))],
            'observacoes'   => ['nullable', 'string', 'max:2000'],
        ]);

        $r = $this->ofertas->criarCombos($this->oferta($oferta), $dados['quantidades'],
            $dados['logistica'] ?? null, $dados['observacoes'] ?? null, PortalContexto::ator());

        $partes = array_filter([
            $r['criados'] ? count($r['criados']).' combo(s) criado(s): '.implode(', ', $r['criados']).'.' : null,
            $r['pulados'] ? 'Já existiam combos de '.implode(', ', $r['pulados']).' unidades — ficaram como estavam.' : null,
            $r['absorvidos'] ? "{$r['absorvidos']} anúncio(s) colado(s) foram vinculados a eles." : null,
        ]);

        return back()->with('success', implode(' ', $partes));
    }

    public function atualizarOferta(Request $request, int $oferta)
    {
        [$registro, $absorvidos] = $this->ofertas->atualizar($this->oferta($oferta), $this->dadosOferta($request), PortalContexto::ator());

        return back()->with('success', $this->mensagemOferta("Oferta {$registro->sku} salva.", $absorvidos));
    }

    public function excluirOferta(int $oferta)
    {
        $registro = $this->oferta($oferta);
        $tinhaAnuncios = $registro->anuncios()->count();

        $this->ofertas->excluir($registro, PortalContexto::ator());

        return back()->with('success', "Oferta {$registro->sku} excluída."
            .($tinhaAnuncios ? " Os {$tinhaAnuncios} anúncio(s) dela voltaram para os colados que aguardam oferta." : ''));
    }

    // ═══ Anúncios ═══════════════════════════════════════════════════════════

    /**
     * Cadastrar pela gaveta da oferta ou CONCLUIR pela agenda. A diferença é
     * uma só: vindo da agenda (`via_agenda`), o MLB é obrigatório — quem
     * acabou de publicar tem o código na tela.
     */
    public function criarAnuncio(Request $request, int $oferta)
    {
        $registro = $this->oferta($oferta);
        $viaAgenda = $request->boolean('via_agenda');

        $anuncio = $this->anuncios->cadastrar($registro, $this->dadosAnuncio($request), PortalContexto::ator(), exigirMlb: $viaAgenda);

        return back()->with('success', EstruturaAnuncio::TIPOS[$anuncio->tipo]." cadastrado em {$registro->sku}.");
    }

    public function atualizarAnuncio(Request $request, int $anuncio)
    {
        $this->anuncios->atualizar($this->anuncio($anuncio), $this->dadosAnuncio($request), PortalContexto::ator());

        return back()->with('success', 'Anúncio salvo.');
    }

    public function excluirAnuncio(int $anuncio)
    {
        $this->anuncios->excluir($this->anuncio($anuncio), PortalContexto::ator());

        return back()->with('success', 'Anúncio excluído.');
    }

    // ═══ Colagem e espera ═══════════════════════════════════════════════════

    /** A prévia — JSON, porque não grava nada e não muda de página. */
    public function previaColagem(Request $request)
    {
        $dados = $this->dadosColagem($request);

        return response()->json($this->colagem->previa(PortalContexto::empresa(), $dados['texto'], $dados['modo']));
    }

    public function aplicarColagem(Request $request)
    {
        $dados = $this->dadosColagem($request);
        $totais = $this->colagem->aplicar(PortalContexto::empresa(), $dados['texto'], $dados['modo'], PortalContexto::ator());

        if (isset($totais['erro_geral'])) {
            return back()->withErrors(['texto' => $totais['erro_geral']]);
        }

        $partes = array_filter([
            $totais['novos'] ? "{$totais['novos']} novo(s)" : null,
            $totais['atualizados'] ? "{$totais['atualizados']} atualizado(s)" : null,
            $totais['espera'] ? "{$totais['espera']} aguardando oferta" : null,
            $totais['removidos'] ? "{$totais['removidos']} removido(s)" : null,
            $totais['erros'] ? "{$totais['erros']} linha(s) com erro não gravada(s)" : null,
        ]);

        return back()->with('success', 'Anúncios colados: '.($partes ? implode(', ', $partes) : 'nada mudou').'.');
    }

    public function vincularEspera(Request $request, int $linha)
    {
        $dados = $request->validate(['oferta_id' => ['required', 'integer']]);

        $this->anuncios->vincularEspera($this->linhaEspera($linha), $this->oferta((int) $dados['oferta_id']), PortalContexto::ator());

        return back()->with('success', 'Anúncio vinculado.');
    }

    public function descartarEspera(int $linha)
    {
        $this->anuncios->descartarEspera($this->linhaEspera($linha), PortalContexto::ator());

        return back()->with('success', 'Anúncio colado descartado.');
    }

    // ═══ Anúncios do Mercado Livre (OAuth) ═════════════════════════════════

    /** Começa a leitura da conta em segundo plano. */
    public function iniciarImportacao()
    {
        $this->anunciosMl->iniciar(PortalContexto::empresa());

        return response()->json($this->anunciosMl->estado(PortalContexto::empresa()));
    }

    /** Estado da leitura; quando pronta, já com a prévia. */
    public function estadoImportacao()
    {
        return response()->json($this->anunciosMl->estado(PortalContexto::empresa()));
    }

    public function aplicarImportacao()
    {
        $t = $this->anunciosMl->aplicar(PortalContexto::empresa(), PortalContexto::ator());

        return back()->with('success', "Do Mercado Livre: {$t['ofertas']} oferta(s) criada(s), "
            ."{$t['novos']} anúncio(s) novo(s), {$t['atualizados']} atualizado(s), {$t['espera']} aguardando oferta.");
    }

    /** A exceção: procurar o anúncio pelo título ou MLB, quando o SKU não casou. */
    public function buscarAnunciosMl(Request $request)
    {
        $dados = $request->validate([
            'q'      => ['nullable', 'string', 'max:200'],
            'tipo'   => ['nullable', Rule::in(array_keys(EstruturaAnuncio::TIPOS))],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        return response()->json($this->anunciosMl->buscar(PortalContexto::empresa(), $dados['q'] ?? '', $dados['tipo'] ?? null, (int) ($dados['pagina'] ?? 1)));
    }

    public function ligarAnuncioMl(Request $request, int $oferta)
    {
        $dados = $request->validate(['ml_item_id' => ['required', 'string', 'max:30']]);

        $anuncio = $this->anunciosMl->ligar($this->oferta($oferta), $dados['ml_item_id'], PortalContexto::ator());

        return back()->with('success', EstruturaAnuncio::TIPOS[$anuncio->tipo]." {$anuncio->codigo_mlb} ligado a {$anuncio->oferta->sku}.");
    }

    /** A estação do produto: a família inteira, com tudo o que a página sabe de cada oferta. */
    public function estacao(int $oferta)
    {
        return response()->json($this->visao->estacao(PortalContexto::empresa(), $this->oferta($oferta)->id));
    }

    /** O SKU e as fotos de cada anúncio da oferta no ML, para a estação. */
    public function detalhesAnunciosMl(int $oferta)
    {
        return response()->json($this->anunciosMl->detalhesDaOferta($this->oferta($oferta)));
    }

    /** Visitas e vendas de 7 dias e buy box de cada anúncio da oferta, lidos no ML. */
    public function metricasAnunciosMl(int $oferta)
    {
        return response()->json($this->anunciosMl->metricasDaOferta($this->oferta($oferta)));
    }

    // ═══ Agenda ═════════════════════════════════════════════════════════════

    public function agendar(Request $request)
    {
        $dados = $request->validate([
            'oferta_id' => ['required', 'integer'],
            'data'      => ['required', 'string'],
            'acao'      => ['required', Rule::in(array_keys(EstruturaAgendaItem::ACOES))],
        ]);

        $item = $this->agenda->agendar($this->oferta((int) $dados['oferta_id']), $dados['data'], $dados['acao'], PortalContexto::ator());

        return back()->with('success', EstruturaAgendaItem::ACOES[$item->acao].' agendada para '.$item->data->format('d/m/Y').'.');
    }

    public function remarcar(Request $request, int $item)
    {
        $dados = $request->validate(['data' => ['required', 'string']]);

        $this->agenda->remarcar($this->itemAgenda($item), $dados['data'], PortalContexto::ator());

        return back()->with('success', 'Remarcado.');
    }

    public function excluirAgenda(int $item)
    {
        $this->agenda->excluir($this->itemAgenda($item), PortalContexto::ator());

        return back()->with('success', 'Removido da agenda.');
    }

    public function jardinagem(Request $request, int $item)
    {
        $dados = $request->validate(['feita' => ['required', 'boolean']]);

        $this->agenda->marcarJardinagem($this->itemAgenda($item), (bool) $dados['feita'], PortalContexto::ator());

        return back();
    }

    /** "Agendar o que falta" — a proposta, em JSON. `ofertas` restringe e recalcula as datas. */
    public function proposta(Request $request)
    {
        $dados = $request->validate(['ofertas' => ['nullable', 'array'], 'ofertas.*' => ['integer']]);

        $somente = array_key_exists('ofertas', $dados) && $dados['ofertas'] !== null
            ? array_map('intval', $dados['ofertas'])
            : null;

        return response()->json(['itens' => $this->agenda->proposta(PortalContexto::empresa(), $somente)]);
    }

    public function aplicarProposta(Request $request)
    {
        $dados = $request->validate(['ofertas' => ['required', 'array', 'min:1'], 'ofertas.*' => ['integer']]);

        $n = $this->agenda->agendarProposta(PortalContexto::empresa(), $dados['ofertas'], PortalContexto::ator());

        return back()->with('success', "{$n} publicação(ões) agendada(s), uma por dia.");
    }

    // ═══ Resolução dentro da empresa ════════════════════════════════════════

    private function oferta(int $id): EstruturaOferta
    {
        return EstruturaOferta::where('company_id', $this->empresaId())->findOrFail($id);
    }

    private function anuncio(int $id): EstruturaAnuncio
    {
        return EstruturaAnuncio::whereHas('oferta', fn ($q) => $q->where('company_id', $this->empresaId()))->findOrFail($id);
    }

    private function linhaEspera(int $id): EstruturaAnuncioEspera
    {
        return EstruturaAnuncioEspera::where('company_id', $this->empresaId())->findOrFail($id);
    }

    private function itemAgenda(int $id): EstruturaAgendaItem
    {
        return EstruturaAgendaItem::whereHas('oferta', fn ($q) => $q->where('company_id', $this->empresaId()))->findOrFail($id);
    }

    private function empresaId(): int
    {
        return PortalContexto::empresa()->id;
    }

    // ═══ Forma do request (a regra de negócio mora nos services) ═══════════

    private function dadosOferta(Request $request): array
    {
        return $request->validate([
            'sku'                      => ['required', 'string', 'max:120'],
            'fase'                     => ['required', Rule::in(array_keys(EstruturaOferta::FASES))],
            'nome'                     => ['nullable', 'string', 'max:255'],
            'logistica'                => ['nullable', Rule::in(array_keys(EstruturaOferta::LOGISTICAS))],
            'observacoes'              => ['nullable', 'string', 'max:2000'],
            'componentes'              => ['nullable', 'array', 'max:50'],
            'componentes.*.id'         => ['required', 'integer'],
            'componentes.*.quantidade' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
    }

    private function dadosAnuncio(Request $request): array
    {
        return $request->validate([
            'tipo'        => ['required', Rule::in(array_keys(EstruturaAnuncio::TIPOS))],
            'status'      => ['nullable', Rule::in(array_keys(EstruturaAnuncio::STATUS))],
            'catalogo'    => ['nullable', 'boolean'],
            'kit_virtual' => ['nullable', 'boolean'],
            // 500, não 30: o cliente pode colar o LINK do anúncio, e o código
            // sai dele em `EstruturaAnuncio::normalizarMlb()`.
            'codigo_mlb'  => ['nullable', 'string', 'max:500'],
            'titulo'      => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** A forma do rascunho do par; a normalização (e o hash) é do service. */
    private function dadosPublicacao(Request $request): array
    {
        return $request->validate([
            'categoria_id'             => ['nullable', 'string', 'max:20'],
            'categoria_nome'           => ['nullable', 'string', 'max:255'],
            'categoria_origem'         => ['nullable', Rule::in(['sugerida', 'escolhida'])],
            'atributos'                => ['nullable', 'array', 'max:100'],
            'atributos.*'              => ['array'],
            'atributos.*.value_id'     => ['nullable', 'string', 'max:100'],
            'atributos.*.value_name'   => ['nullable', 'string', 'max:255'],
            'fotos'                    => ['nullable', 'array', 'max:'.EstruturaPublicacaoService::MAX_FOTOS],
            'fotos.*.id'               => ['required', 'string', 'max:100'],
            'fotos.*.url'              => ['nullable', 'string', 'max:500'],
            'estoque'                  => ['nullable', 'integer', 'min:0', 'max:99999'],
            'condicao'                 => ['nullable', Rule::in(array_keys(EstruturaPublicacaoService::CONDICOES))],
            'envio'                    => ['nullable', 'array'],
            'envio.modo'               => ['nullable', Rule::in(array_keys(EstruturaPublicacaoService::ENVIOS))],
            'envio.frete_gratis'       => ['nullable', 'boolean'],
            'embalagem'                => ['nullable', 'array'],
            'embalagem.peso_g'         => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'embalagem.altura_cm'      => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'embalagem.largura_cm'     => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'embalagem.comprimento_cm' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'garantia'                 => ['nullable', Rule::in(EstruturaPublicacaoService::GARANTIAS)],
            'descricao'                => ['nullable', 'string', 'max:50000'],
            'tipos'                    => ['nullable', 'array'],
            'tipos.classico.titulo'    => ['nullable', 'string', 'max:255'],
            'tipos.classico.preco'     => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tipos.premium.titulo'     => ['nullable', 'string', 'max:255'],
            'tipos.premium.preco'      => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);
    }

    private function dadosColagem(Request $request): array
    {
        return $request->validate([
            'texto' => ['required', 'string', 'max:2000000'],
            'modo'  => ['required', Rule::in([ColagemAnunciosService::MODO_ACRESCENTAR, ColagemAnunciosService::MODO_SUBSTITUIR])],
        ]);
    }

    private function mensagemOferta(string $base, int $absorvidos): string
    {
        return $absorvidos
            ? $base." {$absorvidos} anúncio(s) colado(s) com este SKU foram vinculados a ela."
            : $base;
    }
}
