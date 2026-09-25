<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EstruturaAgendaItem;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use App\Services\Portal\Estrutura\ColagemAnunciosService;
use App\Services\Portal\Estrutura\EstruturaAgendaService;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaVisaoService;
use App\Services\Portal\PortalClienteService;
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
    ) {
    }

    // ═══ Visões ═════════════════════════════════════════════════════════════

    public function index(Request $request)
    {
        $empresa = PortalContexto::empresa();

        $filtro = (string) $request->query('situacao', 'todas');
        $busca = (string) $request->query('q', '');
        $pagina = (int) $request->query('pagina', 1);

        return Inertia::render('Portal/Estrutura', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA, PortalContexto::ator()),
            'estrutura'   => $this->visao->paginaOfertas($empresa, $filtro, $busca, $pagina),
            'filtros'     => ['situacao' => $filtro, 'q' => $busca],
            'vocabulario' => EstruturaVisaoService::vocabulario(),
            // A conta do ML está conectada? Liga "Importar do Mercado Livre"
            // e a busca nos anúncios da conta.
            'ml_conectado' => AnunciosMercadoLivreService::conectado($empresa),
            // Só quando o diálogo pede — a espera e a lista de ofertas podem
            // ter milhares de linhas, e a maioria das visitas não as abre.
            'espera_linhas'  => Inertia::optional(fn () => $this->visao->espera($empresa)),
            'opcoes_ofertas' => Inertia::optional(fn () => $this->visao->opcoesDeOfertas($empresa)),
        ]);
    }

    public function agendaIndex()
    {
        $empresa = PortalContexto::empresa();

        return Inertia::render('Portal/EstruturaAgenda', [
            ...$this->portal->contextoAutenticado($empresa, ModulosPortal::ESTRUTURA, PortalContexto::ator()),
            'agenda'      => $this->visao->agenda($empresa),
            'vocabulario' => EstruturaVisaoService::vocabulario(),
        ]);
    }

    // ═══ Ofertas ════════════════════════════════════════════════════════════

    public function criarOferta(Request $request)
    {
        [$oferta, $absorvidos] = $this->ofertas->criar(PortalContexto::empresa(), $this->dadosOferta($request), PortalContexto::ator());

        return back()->with('success', $this->mensagemOferta("Oferta {$oferta->sku} criada.", $absorvidos));
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
            'q'    => ['nullable', 'string', 'max:200'],
            'tipo' => ['nullable', Rule::in(array_keys(EstruturaAnuncio::TIPOS))],
        ]);

        return response()->json($this->anunciosMl->buscar(PortalContexto::empresa(), $dados['q'] ?? '', $dados['tipo'] ?? null));
    }

    public function ligarAnuncioMl(Request $request, int $oferta)
    {
        $dados = $request->validate(['ml_item_id' => ['required', 'string', 'max:30']]);

        $anuncio = $this->anunciosMl->ligar($this->oferta($oferta), $dados['ml_item_id'], PortalContexto::ator());

        return back()->with('success', EstruturaAnuncio::TIPOS[$anuncio->tipo]." {$anuncio->codigo_mlb} ligado a {$anuncio->oferta->sku}.");
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
