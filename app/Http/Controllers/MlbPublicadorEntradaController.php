<?php

namespace App\Http\Controllers;

use App\Jobs\Publicador\PreencherRascunhoDoPortalJob;
use App\Models\CreativeIdentidade;
use App\Models\MlAnuncioRascunho;
use App\Models\PubProduto;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Services\Publicador\ResumoDoSincronizar;
use App\Support\Publicador\ContasLiberadas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Tela A do Publicador (Fase 164): entrada em /mlb/anuncios, por programa
 * (Polos · Incubadora · Gestão). Só admins — o grupo de rotas aplica role:admin (D17).
 */
class MlbPublicadorEntradaController extends Controller
{
    private const POR_PAGINA = 50;
    private const FILTROS = ['todos', 'prontos', 'atencao', 'nunca'];
    private const BUSCA_LIMITE = 20;

    private const PROGRAMA_ROTULO = ['polos' => 'Polos', 'incubadora' => 'Incubadora', 'gestao' => 'Gestão'];

    public function __construct(private ProgramasPublicadorService $programas) {}

    public function index(Request $request)
    {
        // Fase 172-02: programa inicial por sessão (decisão do usuário — fica só
        // sessão → Polos, SEM o ramo "setor do usuário" que a spec original previa).
        // `?programa=` na URL continua mandando e grava o novo valor na sessão;
        // sem o parâmetro, lê o último programa escolhido nesta sessão, caindo em
        // 'polos' se a sessão estiver vazia ou com valor fora da whitelist.
        $programaQuery = $request->query('programa');
        if ($programaQuery !== null) {
            $programa = in_array($programaQuery, ProgramasPublicadorService::PROGRAMAS, true)
                ? $programaQuery
                : 'polos';
            session(['publicador.ultimo_programa' => $programa]);
        } else {
            $programa = (string) session('publicador.ultimo_programa', 'polos');
            if (! in_array($programa, ProgramasPublicadorService::PROGRAMAS, true)) {
                $programa = 'polos';
            }
        }

        $busca = mb_substr(trim((string) $request->query('busca', '')), 0, 120);
        $filtro = (string) $request->query('filtro', 'todos');
        if (! in_array($filtro, self::FILTROS, true)) {
            $filtro = 'todos';
        }

        $todas = $this->programas->empresas($programa);
        $indicadores = $this->programas->indicadores($programa, $todas);

        $linhas = $todas
            ->when($busca !== '', fn ($c) => $c->filter(fn ($l) => mb_stripos($l['nome'], $busca) !== false
                || mb_stripos($l['identificador'], $busca) !== false))
            ->when($filtro === 'prontos', fn ($c) => $c->filter(fn ($l) => $l['prontos'] > 0))
            ->when($filtro === 'atencao', fn ($c) => $c->filter(fn ($l) => $l['token'] !== 'ativo'))
            ->when($filtro === 'nunca', fn ($c) => $c->filter(fn ($l) => $l['portal']['situacao'] === 'nunca'))
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $total = $linhas->count();
        $pagina = max(1, (int) $request->query('pagina', 1));
        $pagina = min($pagina, max(1, (int) ceil($total / self::POR_PAGINA)));
        $deslocamento = ($pagina - 1) * self::POR_PAGINA;
        $pagBase = $linhas->slice($deslocamento, self::POR_PAGINA)->values();

        return Inertia::render('Mlb/AnunciosEmpresas', [
            'programa' => $programa,
            'programas' => $this->programas->contagens(),
            'indicadores' => $indicadores,
            'empresas' => $pagBase,
            'paginacao' => [
                'pagina' => $pagina,
                'por_pagina' => self::POR_PAGINA,
                'total' => $total,
                'de' => $total === 0 ? 0 : $deslocamento + 1,
                'ate' => $deslocamento + $pagBase->count(),
            ],
            'filtros' => ['busca' => $busca, 'filtro' => $filtro],
        ]);
    }

    /**
     * JSON do seletor "Trocar empresa" (D-06 do handoff): busca entre TODAS as contas dos
     * 3 programas, reusando `ProgramasPublicadorService::empresas()` já usado por `index()` —
     * mesmo filtro por nome/identificador (`mb_stripos`), nunca uma query nova. Junta as 3
     * coleções ANTES de filtrar (uma chamada por programa, nunca uma por letra digitada).
     * NUNCA devolve token de acesso/refresh — só os campos que o popover precisa.
     */
    public function buscaEmpresas(Request $request): JsonResponse
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        if ($q === '') {
            return response()->json([]);
        }

        $todas = collect(ProgramasPublicadorService::PROGRAMAS)
            ->flatMap(fn (string $programa) => $this->programas->empresas($programa)
                ->map(fn (array $l) => $l + ['programa' => $programa, 'programa_rotulo' => self::PROGRAMA_ROTULO[$programa]]));

        $achadas = $todas
            ->filter(fn (array $l) => mb_stripos($l['nome'], $q) !== false || mb_stripos($l['identificador'], $q) !== false)
            ->take(self::BUSCA_LIMITE)
            ->map(fn (array $l) => [
                'chave' => $l['chave'],
                'nome' => $l['nome'],
                'identificador' => $l['identificador'],
                'company_id' => $l['company_id'],
                'programa' => $l['programa'],
                'programa_rotulo' => $l['programa_rotulo'],
                'token' => $l['token'],
            ])
            ->values();

        return response()->json($achadas);
    }

    /** Tela B: produtos da empresa (do Portal e cadastrados aqui) + abas irmãs (D23). */
    public function produtos(Request $request, string $conta, CreativeEngineAtivo $creativeAtivo, CreativePermissao $creativePermissao)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.produtos', ['conta' => $alvo['chave']]);
        }

        $empresa = $this->programas->empresaParaTela($alvo);
        // A chave da conta vai para `url_produto` de cada linha (§7, plano 175-08):
        // é com ela que a coluna Fases abre a tela do Produto.
        $produtos = $this->programas->produtosParaTela($alvo['mlb_empresa'], $alvo['company'], $alvo['chave']);

        $contagens = $this->programas->contagemProdutos($produtos);

        // D22: o assistente antigo fica acessível só por este rodapé, sem aba nem card.
        $companyId = $alvo['company']?->id;
        $antigos = MlAnuncioRascunho::query()
            ->whereIn('status', [MlAnuncioRascunho::STATUS_RASCUNHO, MlAnuncioRascunho::STATUS_VALIDADO, MlAnuncioRascunho::STATUS_ERRO])
            ->where(function ($q) use ($companyId, $alvo) {
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
                if ($alvo['mlb_empresa'] !== null) {
                    $q->orWhere('mlb_empresa_id', $alvo['mlb_empresa']->id);
                }
            })->count();

        return Inertia::render('Mlb/Publicador/Produtos', [
            'empresa' => $empresa,
            'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company'])),
            'produtos' => $produtos,
            'contagens' => $contagens,
            'rascunhos_antigos' => [
                'total' => $antigos,
                'url' => $companyId !== null && $antigos > 0 ? route('mlb.anuncios.wizard', ['company' => $companyId]) : null,
            ],
            // Ponte até a Fase 165: os "Criativos por IA" (Creative Engine, v24.0) ainda moram na
            // etapa "Imagem e frete" do assistente antigo, que só existe para empresa com Company.
            // Só aparece com a chave do Creative Engine ligada e para quem pode gerar criativos.
            'criativos_ia' => [
                'url' => $companyId !== null && $creativeAtivo->ativa() && $creativePermissao->podeGerar($request->user())
                    ? route('mlb.anuncios.wizard', ['company' => $companyId])
                    : null,
            ],
            'abas' => ['company_id' => $companyId],
        ]);
    }

    /** "Sincronizar do Portal" (D16): acrescenta os produtos das ofertas novas e junta ao grupo as linhas antigas de cor vazias. */
    public function sincronizar(string $conta, PublicadorSincronizaPortalService $sincroniza, ResumoDoSincronizar $resumo)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        $company = $alvo['company'];
        if ($company === null || $this->programas->situacaoPortal($company)['situacao'] === 'sem_portal') {
            return response()->json(['message' => 'Esta empresa não está ligada ao Portal do Cliente.'], 422);
        }

        $r = $sincroniza->sincronizar($alvo['mlb_empresa'], $company);

        // Fase 172-12 (D-05/D-10): um Job por produto agrupado/composto preenche o rascunho com a ficha do
        // Portal. Sem trava de piloto: vale para qualquer empresa com Portal. Nada disso fala com o ML.
        $pedido = null;
        $preenchendo = count($r['para_preencher']);
        if ($preenchendo > 0) {
            $pedido = $resumo->abrir((int) $company->id, $r['para_preencher']);
            foreach ($r['para_preencher'] as $id) {
                PreencherRascunhoDoPortalJob::dispatch((int) $id, $pedido);
            }
        }

        $mensagem = $r['criados'] > 0
            ? ($r['criados'] === 1 ? '1 produto novo do Portal.' : $r['criados'].' produtos novos do Portal.')
            : 'Nada novo no Portal.';
        if ($preenchendo > 0) {
            $mensagem .= $preenchendo === 1
                ? ' Preenchendo 1 rascunho com o que está no Portal.'
                : " Preenchendo {$preenchendo} rascunhos com o que está no Portal.";
        }
        // Linhas antigas, uma por cor, que nada referenciava: saíram porque a cor já está no grupo.
        $absorvidos = (int) ($r['absorvidos'] ?? 0);
        if ($absorvidos > 0) {
            $mensagem .= $absorvidos === 1
                ? ' 1 linha antiga de cor foi juntada ao produto.'
                : " {$absorvidos} linhas antigas de cor foram juntadas ao produto.";
        }

        return response()->json([
            'criados' => $r['criados'],
            'ids' => $r['ids'],
            'mensagem' => $mensagem,
            'pedido' => $pedido,
            'preenchendo' => $preenchendo,
            'avisos' => $r['avisos'],
            'duplicados' => $r['duplicados'],
            'absorvidos' => $absorvidos,
            'portal' => $this->programas->situacaoPortal($company),
        ]);
    }

    /** Resumo do preenchimento de um clique; só a empresa que clicou lê (T-172-42). */
    public function resumoDoSincronizar(string $conta, string $pedido, ResumoDoSincronizar $resumo)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null || $alvo['company'] === null, 404);

        $r = $resumo->ler($pedido, (int) $alvo['company']->id);
        abort_if($r === null, 404);

        return response()->json($r);
    }

    /** "+ Produto": cadastro manual para empresa sem Portal (D15). SKU repetido é aviso, não bloqueio. */
    public function criarProduto(Request $request, string $conta)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        $request->merge([
            'sku' => trim((string) $request->input('sku')),
            'nome' => trim((string) $request->input('nome')),
        ]);
        $dados = $request->validate([
            'sku' => ['required', 'string', 'max:120'],
            'nome' => ['required', 'string', 'max:255'],
        ]);

        $repetido = $this->programas->produtosQuery($alvo['mlb_empresa'], $alvo['company'])
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($dados['sku'])])->exists();

        // As âncoras vêm do servidor (resolver), nunca do corpo da requisição.
        $produto = PubProduto::create([
            'mlb_empresa_id' => $alvo['mlb_empresa']?->id,
            'company_id' => $alvo['company']?->id,
            'sku' => $dados['sku'],
            'nome' => $dados['nome'],
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        return response()->json([
            'produto' => ['id' => $produto->id],
            'url' => route('mlb.anuncios.publicador.editor', ['produto' => $produto->id]),
            'aviso' => $repetido ? 'Já existe um produto com este SKU nesta empresa.' : null,
        ], 201);
    }

    /** Casca do editor: produto, empresa, faixa de produtos e se a publicação está liberada. */
    public function editor(int $produto, Request $request, CreativeEngineAtivo $creativeAtivo, CreativePermissao $creativePermissao)
    {
        $p = PubProduto::findOrFail($produto);
        $alvo = $this->programas->empresaDoProduto($p);
        abort_if($alvo === null, 404);

        $lista = $this->programas->produtosParaTela($alvo['mlb_empresa'], $alvo['company']);

        return Inertia::render('Mlb/Publicador/Editor', [
            'produto' => [
                'id' => $p->id, 'sku' => $p->skuExibido(), 'nome' => $p->nomeExibido(),
                'origem' => $p->origem, 'oferta_id' => $p->oferta_id,
            ],
            // WR-B01: a conta mostrada e o "liberada" são os do PRODUTO — a conta que confere e publica,
            // a mesma do `publicacao_liberada` do JSON —, não os da empresa resolvida pela tela.
            'empresa' => $this->programas->empresaParaTela($alvo, $p),
            'produtos' => array_map(fn ($i) => [
                'id' => $i['id'], 'sku' => $i['sku'], 'nome' => $i['nome'], 'status' => $i['status'],
            ], $lista),
            'liberada' => ContasLiberadas::libera($p->contaOuNula()),
            // Fase 165 (D-06): o "Gerar com IA" dos blocos de fotos — mesma chave e mesma permissão
            // do Creative Engine; NÃO exige Company (a loja vem da conta do produto). Só esconde o
            // botão: toda ação é conferida de novo no servidor.
            'criativos_ia' => $creativeAtivo->ativa() && $creativePermissao->podeGerar($request->user()),
        ]);
    }

    /**
     * Fase 173, Plano 04 (VISG-03..08) — aba inicial da conta: indicadores, "O que
     * fazer agora", situação dos produtos, últimas publicações, integrações, resumo
     * de identidade e quem publicou. ZERO chamada ao Mercado Livre neste request —
     * só leitura do que já está gravado (design_handoff_publicador/ETAPA-2-visao-geral.md).
     */
    public function visaoGeral(string $conta, AcervoTriagemService $acervoTriagem, PainelVisaoGeralService $painel)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.visao-geral', ['conta' => $alvo['chave']]);
        }

        $empresa = $this->programas->empresaParaTela($alvo);
        // A chave da conta vai para `url_produto` de cada linha (plano 175-08).
        $produtos = $this->programas->produtosParaTela($alvo['mlb_empresa'], $alvo['company'], $alvo['chave']);
        $contagemProdutos = $this->programas->contagemProdutos($produtos);
        $company = $alvo['company'];

        // D23: sem Company não há acervo pra triar/defasar — agregados vazios, nunca erro.
        $triagem = $company !== null ? $acervoTriagem->triagem($company, '', 'acionaveis') : ['total' => 0, 'chips' => [], 'nao_avaliado' => 0];
        $defasagem = $company !== null ? $acervoTriagem->defasagem($company) : ['coletado_em' => null, 'horas' => null, 'defasado' => false, 'nunca_coletado' => false, 'motivo' => null];
        $situacaoPortal = $this->programas->situacaoPortal($company);
        $publicados = $painel->publicadosRecentes($alvo);

        return Inertia::render('Mlb/Publicador/VisaoGeral', [
            'empresa' => $empresa,
            'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $company)),
            // Quick 261009-t02: `$produtos` entra para o `no_ar_por_fase` — é a
            // MESMA lista que a tela já carrega, nenhuma consulta nova.
            'indicadores' => $painel->indicadores($alvo, $contagemProdutos, $produtos),
            'oQueFazerAgora' => $painel->oQueFazerAgora($alvo, $empresa, $contagemProdutos, $triagem, $defasagem, $situacaoPortal, $produtos),
            // Quick 261009-t02: os "Alertas" da coluna lateral são a triagem que
            // já está carregada acima — reformatada, nunca recalculada.
            'alertas' => $painel->alertas($alvo, $triagem),
            'situacaoProdutos' => $painel->situacaoProdutos($contagemProdutos),
            // §7 (plano 175-08): bloco NOVO, ao lado do de cima — a spec mandava
            // substituir, mas nada que existe pode sumir (ver o docblock do
            // `produtosPorFase()`). Mesma fonte de contagem, logo os números batem
            // com a lista de Produtos por construção.
            'produtosPorFase' => $painel->produtosPorFase($contagemProdutos),
            'ultimasPublicacoes' => $painel->ultimasPublicacoes($alvo),
            'integracoes' => $painel->integracoes($alvo, $empresa),
            'identidadeResumo' => $painel->identidadeResumo($alvo),
            'quemPublicou' => [
                'equipe' => $publicados['equipe'],
                'cliente' => $publicados['cliente'],
                'origem_antiga' => $publicados['origem_antiga'],
            ],
            'abas' => ['company_id' => $company?->id],
        ]);
    }

    /**
     * Fase 173, Plano 04 (CONF-02/03) — "Configurações da conta": identidade visual
     * (texto cru, sem resumo), conexões (somente leitura) e programa/responsável.
     * Nada novo no banco.
     */
    public function configuracoes(string $conta, PainelVisaoGeralService $painel)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.configuracoes', ['conta' => $alvo['chave']]);
        }

        $empresa = $this->programas->empresaParaTela($alvo);
        $identidade = CreativeIdentidade::paraAncora($alvo['company']?->id, $alvo['mlb_empresa']?->id);

        return Inertia::render('Mlb/Publicador/Configuracoes', [
            'empresa' => $empresa,
            'identidade' => $identidade?->texto,
            'conexoes' => $painel->integracoes($alvo, $empresa),
            'programa' => $alvo['programa'],
            'responsavel' => $alvo['mlb_empresa']?->responsavel?->name,
        ]);
    }
}
