<?php

namespace App\Http\Controllers;

use App\Jobs\PublicarAnuncioMlJob;
use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\User;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlFreteService;
use App\Services\Mlb\Publicacao\MlGradeService;
use App\Services\Mlb\Publicacao\MlImagemService;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use App\Services\MercadoLivreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Módulo "Anunciar Mercado Livre".
 *
 * Momento 1 (painel de cards): index() lista as empresas MLB com estado do token
 * ML por empresa. Escopo imposto no banco por responsavel_id (publicador vê
 * apenas as suas; admin vê todas). Acesso: usuários autenticados com permissão
 * mlb.anunciar (por ora role:admin em Dev, conforme gate temporário nas rotas).
 *
 * Momento 2 (wizard): wizard() abre o AnunciarML com a empresa já fixada e
 * faz double-check de pertencimento antes de renderizar (abort 403 se a empresa
 * não pertencer ao publicador logado).
 *
 * SEL-01: painel de cards separado do wizard
 * SEL-02: escopo por responsavel_id na query do banco
 * SEL-06: empresa sem token ML aparece com tem_token=false (não filtrada)
 * SEL-07: wizard fixa empresa e carrega rascunhos por mlb_empresa_id
 */
class MlbAnuncioController extends Controller
{
    public function __construct(
        private MlCatalogoMetaService $meta,
        private MlPublicacaoService $publicacao,
        // WIZ-05: injetado para upload imediato de imagens por variação (Phase 77 Plan 02)
        private MlImagemService $imagem,
        // WIZ-06: service de grades de tamanho (Phase 77 Plan 03)
        private MlGradeService $grade,
        // SHIP-02: cotação automática de frete por dimensões/peso (Phase 78 Plan 01)
        private MlFreteService $frete,
        // BULK-02: pré-check de token 1x no lote antes de qualquer dispatch
        private MercadoLivreService $ml,
    ) {}

    /**
     * Momento 1: painel de cards — uma empresa por card com estado de conta ML.
     *
     * Publicador vê só as empresas onde responsavel_id === seu id.
     * Admin vê todas. Filtro imposto na query do banco (não em PHP pós-busca).
     */
    public function index(Request $request)
    {
        return Inertia::render('Mlb/AnunciosEmpresas', [
            'empresas' => $this->empresas($request),
        ]);
    }

    /**
     * Momento 2: wizard com a empresa já fixada (SEL-07).
     *
     * Double-check de pertencimento: admin passa sempre; publicador só acessa
     * se a empresa foi atribuída a ele (abort 403 caso contrário — T-75-05).
     */
    public function wizard(Request $request, Company $company)
    {
        // Só empresas com conta ML conectada podem publicar
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        // mlb_empresa ligada (se houver) → dados do cliente para pré-preenchimento (Phase 76)
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)
            ->with('implementacao')
            ->first();

        return Inertia::render('Mlb/AnunciarML', [
            'empresa' => [
                'id'         => $company->id,   // âncora = company_id
                'nome'       => $company->name,
                'company_id' => $company->id,
                'tem_token'  => true,
            ],
            'rascunhos' => MlAnuncioRascunho::where('company_id', $company->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn ($r) => [
                    'id'            => $r->id,
                    'status'        => $r->status,
                    // Título do anúncio (para identificar o rascunho na lista, não só a empresa)
                    'titulo'        => (string) data_get($r->payload, 'title', ''),
                    'category_id'   => $r->category_id,
                    'ml_item_id'    => $r->ml_item_id,
                    'listing_tier'  => $r->listing_tier,
                    'updated_at'    => $r->updated_at,
                    // Erro resumido em 1 linha (o painel mostra isso por padrão)…
                    'erro_resumo'   => $this->resumoErro($r->validation_errors),
                    // …e o erro completo, que o publicador pode expandir/copiar quando precisar depurar
                    'erro_completo' => $this->erroCompleto($r->validation_errors),
                    // payload completo → permite Abrir/editar o rascunho no wizard
                    'payload'       => $r->payload,
                ]),
            // DRAFT-01: produtos do cliente lidos de mlb_implementacoes.dados (se houver vínculo)
            'produtos'  => $this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados),
            // HIST-86-2: quando o "Anunciar semelhante" do histórico manda ?rascunho=N,
            // o wizard já abre com o clone carregado. Sem o parâmetro vem null e nada muda.
            'abrirRascunhoId' => $request->query('rascunho') ? (int) $request->query('rascunho') : null,
        ]);
    }

    /**
     * Grade de anúncio em massa (SHEET-01) — a empresa é fixada ANTES da grade,
     * mantendo a mesma proteção de "publicar na conta certa" do wizard (Phase 75).
     *
     * Espelha wizard(): loadMissing('mlToken') + abort_unless(mlToken, 404). O
     * escopo por responsavel_id fica DORMANT sob o gate role:admin (todo acessante
     * é admin e vê todas), igual ao resto do módulo — quando o gate abrir à equipe
     * de publicação, o double-check por responsavel_id já vale sem rework.
     *
     * Props para a grade (Plan 02/03):
     *   - empresa   : âncora company_id (mesmo shape do wizard)
     *   - rascunhos : TODOS os rascunhos abertos da empresa (não só 50), com
     *                 category_id e payload → a grade reconstrói as linhas por
     *                 category_id (o "lote" de uma aba = category_id + empresa).
     *   - produtos  : lista do cliente p/ pré-preenchimento por linha (SHEET-04).
     */
    public function massa(Request $request, Company $company)
    {
        // Só empresas com conta ML conectada podem publicar (mesma trava do wizard)
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        // mlb_empresa ligada (se houver) → dados do cliente para pré-preenchimento (Phase 76)
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)
            ->with('implementacao')
            ->first();

        return Inertia::render('Mlb/AnunciarMassa', [
            'empresa' => [
                'id'         => $company->id,   // âncora = company_id
                'nome'       => $company->name,
                'company_id' => $company->id,
                'tem_token'  => true,
            ],
            // Rascunhos abertos da empresa (por company_id) para a grade reconstruir
            // as linhas agrupadas por category_id. Inclui 'publicando' — a grade
            // mostra o estado assíncrono (BULK-04) por linha ao reabrir a página.
            'rascunhos' => MlAnuncioRascunho::where('company_id', $company->id)
                ->whereIn('status', [
                    MlAnuncioRascunho::STATUS_RASCUNHO,
                    MlAnuncioRascunho::STATUS_VALIDADO,
                    MlAnuncioRascunho::STATUS_ERRO,
                    MlAnuncioRascunho::STATUS_PUBLICANDO,
                ])
                ->latest()
                ->get()
                ->map(fn ($r) => [
                    'id'            => $r->id,
                    'status'        => $r->status,
                    'titulo'        => (string) data_get($r->payload, 'title', ''),
                    // category_id agrupa os rascunhos por aba na grade (SHEET-02/03)
                    'category_id'   => $r->category_id,
                    'listing_tier'  => $r->listing_tier,
                    'updated_at'    => $r->updated_at,
                    // Erro resumido em 1 linha + completo expansível (reuso do wizard)
                    'erro_resumo'   => $this->resumoErro($r->validation_errors),
                    'erro_completo' => $this->erroCompleto($r->validation_errors),
                    // payload completo → a grade reidrata cada célula da linha
                    'payload'       => $r->payload,
                ]),
            // SHEET-04: produtos do cliente lidos de mlb_implementacoes.dados (se houver vínculo)
            'produtos'  => $this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados),
        ]);
    }

    /**
     * HIST-86-1/HIST-86-3 — Histórico: os anúncios já PUBLICADOS da empresa.
     *
     * Por que precisa de consulta própria: `massa()` e `index()` filtram
     * `whereIn([rascunho, validado, erro, publicando])` — 'publicado' fica de fora
     * de propósito (a grade edita o que ainda não foi), então o anúncio some da
     * tela justamente quando dá certo. Esta é a consulta que o traz de volta, e é
     * a base do "Anunciar semelhante".
     *
     * Retorna item enxuto (sem `payload`): a listagem só precisa mostrar; quem
     * clona é o `duplicarComoTemplate`, que lê o payload direto do banco.
     */
    public function historico(Request $request, Company $company)
    {
        // Só empresas com conta ML conectada (mesma trava do wizard e da grade)
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        $busca = trim((string) $request->query('busca', ''));

        // Todos os publicados da empresa. O agrupamento por lote precisa do conjunto
        // inteiro: a publicação em massa cria N rascunhos SOLTOS (sem coluna de lote no
        // banco — ver publicarLote), então o lote é reconstruído aqui pelos dados que
        // sobraram (category_id + dia de published_at).
        $publicados = MlAnuncioRascunho::where('company_id', $company->id)
            ->where('status', MlAnuncioRascunho::STATUS_PUBLICADO)
            ->when($busca !== '', function ($q) use ($busca) {
                // O grupo é OBRIGATÓRIO: um orWhere solto sobe ao topo do WHERE e
                // anula o escopo por company_id/status — vazaria anúncio de outra
                // empresa na busca.
                $q->where(function ($s) use ($busca) {
                    $s->where('payload->title', 'like', "%{$busca}%")
                      ->orWhere('sku_origem', 'like', "%{$busca}%");
                });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        // ─── Agrupa por LOTE = categoria + dia de publicação ───
        // O módulo já define lote como empresa + categoria (massa()/grade: 1 aba = 1
        // category_id, comentário em massa()). O dia separa corridas de massa distintas
        // da mesma categoria. groupBy preserva a ordem (published_at desc) → o lote mais
        // recente vem primeiro. Anúncio avulso vira um grupo de total=1 (a tela o renderiza
        // como card solto; só total>1 colapsa num cabeçalho de lote).
        $chaveLote = fn ($r) => ($r->category_id ?? 'sem-cat')
            . '|' . (optional($r->published_at)->toDateString() ?? 'sem-data');

        $grupos = $publicados
            ->groupBy($chaveLote)
            ->map(function ($itens) use ($chaveLote) {
                $primeiro = $itens->first();

                return [
                    'chave'        => $chaveLote($primeiro),
                    'category_id'  => $primeiro->category_id,
                    'categoria'    => $this->nomeCategoria($primeiro->category_id),
                    'data'         => optional($primeiro->published_at)->toDateString(),
                    'published_at' => optional($primeiro->published_at)->toIso8601String(),
                    'total'        => $itens->count(),
                    'itens'        => $itens->map(fn ($r) => [
                        'id'           => $r->id,
                        'titulo'       => (string) data_get($r->payload, 'title', ''),
                        'preco'        => data_get($r->payload, 'price'),
                        'foto'         => data_get($r->payload, 'pictures.0.source'),
                        'sku_origem'   => $r->sku_origem,
                        'listing_tier' => $r->listing_tier,
                        'category_id'  => $r->category_id,
                        'published_at' => $r->published_at,
                        'ml_item_id'   => $r->ml_item_id,
                    ])->values(),
                ];
            })
            ->values();

        // Paginação por GRUPO (mantém a UI de paginação existente do módulo).
        $porPagina    = 12;
        $pagina       = max(1, (int) $request->query('page', 1));
        $gruposPagina = new LengthAwarePaginator(
            $grupos->forPage($pagina, $porPagina)->values(),
            $grupos->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => collect($request->query())->except('page')->all()],
        );

        return Inertia::render('Mlb/AnunciosHistorico', [
            'empresa'  => [
                'id'   => $company->id,
                'nome' => $company->name,
            ],
            'grupos'   => $gruposPagina,
            'resumo'   => [
                'total_anuncios' => $publicados->count(),
                'total_lotes'    => $grupos->count(),
            ],
            'filtros'  => ['busca' => $busca],
        ]);
    }

    /**
     * Lista completa dos produtos do cliente para pré-preenchimento por linha (SHEET-01/SHEET-04).
     *
     * Endpoint JSON irmão de rascunhoPorProduto(): reusa o MESMO topo (loadMissing +
     * abort_unless) e o MESMO helper montarProdutosDoCliente() — NÃO reimplementa o
     * cálculo de preço. Diferença: aqui NÃO cria rascunho, só devolve a lista para a
     * grade oferecer as opções de pré-preenchimento por SKU.
     *
     * A criação do rascunho por linha reusa rascunhoPorProduto (produto único por SKU)
     * ou salvarRascunho — decisão do Plan 02/03. O badge de origem (cliente × publicador)
     * virá de meta_campos no payload, já gravado por rascunhoPorProduto (Phase 76 DRAFT-04).
     */
    public function produtosDoClienteMassa(Request $request, Company $company): JsonResponse
    {
        // Só empresas com conta ML conectada (mesma trava do wizard / rascunhoPorProduto)
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin)

        // mlb_empresa ligada (se houver) → dados do cliente
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)->with('implementacao')->first();

        return response()->json([
            'ok'       => true,
            'produtos' => $this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados),
        ]);
    }

    /**
     * Cria um rascunho (início do wizard).
     *
     * SEL-07: mlb_empresa_id é obrigatório — o rascunho nasce ancorado na empresa.
     * company_id e user_id são derivados automaticamente (não vêm do cliente).
     *
     * T-75-06: double-check por responsavel_id antes de create() — impede que um
     * publicador crie rascunho em empresa não atribuída a ele.
     */
    public function salvarRascunho(Request $request)
    {
        $dados = $request->validate([
            // Âncora = company_id (empresa com conta ML conectada)
            'company_id'  => ['required', 'integer', 'exists:companies,id'],
            'category_id' => ['nullable', 'string', 'max:20'],
            'payload'     => ['nullable', 'array'],
        ]);

        $company = Company::findOrFail($dados['company_id']);

        // Só empresas com conta ML conectada podem receber rascunho
        abort_unless($company->mlToken !== null, 422, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin)

        // mlb_empresa ligada (se houver) — vínculo opcional para dados do cliente
        $mlbEmpresaId = MlbEmpresa::where('company_id', $company->id)->value('id');

        // company_id da âncora; user_id do publicador autenticado (não do cliente)
        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $mlbEmpresaId,
            'user_id'        => $request->user()->id,
            'category_id'    => $dados['category_id'] ?? null,
            'payload'        => $dados['payload'] ?? [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return response()->json(['rascunho' => $rascunho]);
    }

    /**
     * Autosave do rascunho (cada passo do wizard). Editar invalida a validação anterior.
     *
     * SEL-03: company_id e mlb_empresa_id NÃO entram no validate nem no update —
     * empresa fixada na criação é imutável. Qualquer valor enviado no corpo é ignorado.
     *
     * SEL-04: double-check por responsavel_id da empresa do rascunho antes de qualquer
     * efeito. ATENÇÃO: usa $rascunho->mlbEmpresa?->responsavel_id, NUNCA $rascunho->user_id
     * — o escopo é por empresa (quem é responsável pela MlbEmpresa), não por posse do
     * rascunho. Fallback p/ rascunhos legados (sem mlb_empresa_id): aceita admin ou dono.
     */
    public function atualizarRascunho(Request $request, MlAnuncioRascunho $rascunho)
    {
        // SEL-04: garante que a empresa do rascunho está atribuída ao publicador autenticado
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // SEL-03: company_id e mlb_empresa_id NÃO entram no validate nem no update — empresa fixada na criação é imutável
        $dados = $request->validate([
            'category_id' => ['nullable', 'string', 'max:20'],
            'payload'     => ['nullable', 'array'],
            // WIZ-01: título validado contra max_title_length da categoria escolhida.
            // Regra nullable para não bloquear autosave de outras etapas que não enviam título.
            // Fallback 60 = limite padrão do ML quando a categoria não está no cache.
            // Usa mb_strlen (não strlen) para contar caracteres, não bytes — pt-BR tem acentos.
            'payload.title' => [
                'nullable',
                'string',
                function (string $attr, mixed $val, \Closure $fail) use ($request) {
                    $categoryId = $request->input('category_id') ?? '';
                    $maxLen     = data_get(
                        $this->meta->categoria($categoryId),
                        'settings.max_title_length',
                        60  // fallback ao limite padrão do ML
                    );
                    if (mb_strlen((string) $val) > $maxLen) {
                        $fail("Título excede o limite de {$maxLen} caracteres para esta categoria.");
                    }
                },
            ],
        ]);

        // GOTCHA Laravel: validar a chave aninhada `payload.title` faz $dados['payload']
        // conter APENAS { title }, descartando o resto (category_id/price/available_quantity/
        // attributes/shipping...). Por isso gravamos o payload COMPLETO via $request->input(),
        // não $dados['payload']. A validação do título continua rodando acima, à parte.
        $rascunho->update([
            'category_id' => array_key_exists('category_id', $dados) ? $dados['category_id'] : $rascunho->category_id,
            'payload'     => $request->input('payload', $rascunho->payload),
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return response()->json(['rascunho' => $rascunho->fresh()]);
    }

    /** Valida o rascunho no ML (/items/validate, dry-run) e devolve os erros em pt-BR. */
    public function validar(MlAnuncioRascunho $rascunho)
    {
        return response()->json($this->publicacao->validar($rascunho));
    }

    /**
     * Exclui um rascunho (limpa a lista de "Rascunhos recentes").
     *
     * Double-check por empresa (SEL-04) antes de apagar. Não bloqueia por status —
     * o publicador pode remover rascunhos com erro, em branco ou já publicados
     * (apagar o rascunho NÃO remove o anúncio no ML, só a nossa cópia local).
     */
    public function excluirRascunho(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        if ($rascunho->mlb_empresa_id !== null) {
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        $rascunho->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Publica o rascunho de verdade (POST /items).
     *
     * NÃO bloqueia pelo /items/validate: esse endpoint dá falso-positivo em
     * algumas contas (ex.: `shipping.lost_me1_by_user` em contas com Full/Flex),
     * enquanto o POST /items real cria o anúncio normalmente. O POST é a fonte
     * da verdade — se falhar de fato, o service grava o erro real no rascunho.
     *
     * T-75-01: double-check por responsavel_id antes de qualquer chamada à API ML
     * (operação irreversível — publica na conta do cliente). ATENÇÃO: usa
     * $rascunho->mlbEmpresa?->responsavel_id, NUNCA $rascunho->user_id.
     * Fallback p/ rascunhos legados (sem mlb_empresa_id): aceita admin ou dono.
     */
    public function publicar(Request $request, MlAnuncioRascunho $rascunho)
    {
        // T-75-01: SEL-04 — double-check por empresa antes de publicar (chamada irreversível à API ML)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        try {
            $r = $this->publicacao->publicar($rascunho);

            return response()->json([
                'ok'         => $r->status === MlAnuncioRascunho::STATUS_PUBLICADO,
                'status'     => $r->status,
                'ml_item_id' => $r->ml_item_id,
                'erros'      => $r->validation_errors,
            ]);
        } catch (\Throwable $e) {
            $fresh = $rascunho->fresh();

            return response()->json([
                'ok'     => false,
                'status' => $fresh?->status,
                'erros'  => $fresh?->validation_errors ?? [['mensagem' => 'Falha ao publicar. Tente novamente.']],
            ], 422);
        }
    }

    /**
     * Cria um rascunho com o tier oposto ao do rascunho de origem.
     *
     * DUP-01: tier oposto usa gold_pro (premium) ou gold_special (clássico) com preço derivado.
     * DUP-02: gera o par Clássico+Premium a partir de um único rascunho.
     * DUP-03: título do novo rascunho recebe sufixo mínimo (" - Premium" ou " - Clássico")
     *         com strip idempotente para garantir diferença e evitar cancelamento por duplicata no ML.
     * DUP-04: ml_item_id_classico e ml_item_id_premium zerados no novo rascunho
     *         (o rascunho duplicado ainda não foi publicado).
     *
     * SEL-04: double-check de pertencimento (cópia exata de publicar(), linhas 222–237)
     *         antes de criar qualquer dado.
     */
    public function duplicarTier(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // SEL-04: double-check (cópia exata de publicar() — operação irreversível)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // criarDuplicataInterna lança InvalidArgumentException quando os títulos ficam idênticos
        // Capturamos e retornamos 422 com mensagem pt-BR (DUP-03)
        try {
            $duplicado = $this->criarDuplicataInterna($rascunho, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok'   => false,
                'erros' => [['mensagem' => $e->getMessage()]],
            ], 422);
        }

        $tierNovo = $duplicado->listing_tier;

        return response()->json([
            'ok'        => true,
            'rascunho'  => $duplicado,
            'tier_novo' => $tierNovo,
        ]);
    }

    /**
     * Cria um rascunho-template a partir de um anúncio publicado (UX-03 — Phase 81).
     *
     * Diferença em relação a duplicarTier(): NÃO troca o tier, NÃO adiciona sufixo
     * de tier ao título e NÃO recalcula preço. É uma cópia fiel do payload do publicado
     * (título, listing_type_id, price, atributos, etc.) com os três ml_item_id*
     * zerados e status voltando a STATUS_RASCUNHO.
     *
     * SEL-04: double-check de pertencimento idêntico ao duplicarTier() antes de
     * qualquer escrita.
     */
    public function duplicarComoTemplate(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // SEL-04: double-check (cópia exata de duplicarTier() — operação irreversível)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        $novo = $this->criarTemplateInterno($rascunho, $request->user());

        return response()->json(['ok' => true, 'rascunho' => $novo]);
    }

    /**
     * "Anunciar semelhante em massa" — clona um LOTE inteiro do histórico como
     * templates novos (extensão do "Anunciar semelhante" individual, Phase 86).
     *
     * Recebe os ids dos anúncios do lote e cria um rascunho-template de cada um
     * (criarTemplateInterno: título/tier/payload intactos, ml_item_ids zerados,
     * status rascunho). Devolve os ids criados; o front navega para a grade
     * (massa) — como os clones nascem STATUS_RASCUNHO com o mesmo category_id, a
     * grade os monta automaticamente na aba da categoria, já pré-preenchidos.
     *
     * Escopo espelha o publicarLote (BULK-01/T-80-02/T-80-03): double-check de
     * empresa + teto de 50 por chamada. Só clona rascunhos da própria empresa.
     */
    public function duplicarLoteComoTemplate(Request $request, Company $company): JsonResponse
    {
        $dados = $request->validate([
            'rascunho_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'rascunho_ids.*' => ['integer', 'exists:ml_anuncio_rascunhos,id'],
        ]);

        // SEL-04: double-check de empresa (mesmo do publicarLote/BULK-01)
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)->first();
        abort_unless(
            $request->user()->isAdmin() || $mlbEmpresa?->responsavel_id === $request->user()->id,
            403,
            'Empresa não atribuída a este publicador.'
        );

        // T-80-02: TODOS os ids devem ser da empresa informada — nunca clonar de outra
        $rascunhos = MlAnuncioRascunho::whereIn('id', $dados['rascunho_ids'])
            ->where('company_id', $company->id)
            ->get();

        if ($rascunhos->count() !== count($dados['rascunho_ids'])) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Um ou mais anúncios não pertencem à empresa informada.']],
            ], 403);
        }

        $novos = $rascunhos->map(fn ($r) => $this->criarTemplateInterno($r, $request->user())->id)->values();

        return response()->json([
            'ok'           => true,
            'criados'      => $novos->count(),
            'rascunho_ids' => $novos,
        ]);
    }

    /**
     * Publica o rascunho como Clássico E Premium em sequência (DUP-02).
     *
     * Fluxo:
     *   1. SEL-04 double-check de empresa antes de qualquer chamada ML.
     *   2. criarDuplicataInterna() cria o rascunho do tier oposto.
     *   3. Publica os 2 rascunhos via tentarPublicar() — falha de um não aborta o outro.
     *
     * DUP-03: os dois rascunhos têm títulos diferentes (garantido em criarDuplicataInterna).
     * DUP-04: cada publicação grava no campo do tier correto (MlPublicacaoService::publicar).
     * DUP-02: falha de um tier não aborta o outro — resultado retorna ok_classico e ok_premium
     *         independentes mapeados por listing_tier (não por posição de array).
     */
    public function publicarDuplo(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // T-79-02: SEL-04 — double-check (cópia exata de publicar()) antes de qualquer chamada ML
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // Cria o rascunho do tier oposto (títulos idênticos retorna 422 antes de publicar qualquer coisa)
        try {
            $rascunhoDuplo = $this->criarDuplicataInterna($rascunho, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok'   => false,
                'erros' => [['mensagem' => $e->getMessage()]],
            ], 422);
        }

        // Publica os 2 rascunhos — DUP-04: falha de um não aborta o outro
        $resultadoA = $this->tentarPublicar($rascunho);
        $resultadoB = $this->tentarPublicar($rascunhoDuplo);

        // Mapeia resultados por listing_tier (não por posição) — DUP-02
        // Garante que 'classico' e 'premium' na resposta correspondem ao tier real
        $resultados = collect([$resultadoA, $resultadoB])->keyBy('tier');
        $classico   = $resultados->get('classico', $resultadoA);
        $premium    = $resultados->get('premium',  $resultadoB);

        return response()->json([
            'ok'      => $classico['ok'] || $premium['ok'],
            'classico' => $classico,
            'premium'  => $premium,
        ]);
    }

    /**
     * Envia o binário de uma imagem para o ML e devolve o picture_id da variação.
     *
     * WIZ-05: upload imediato de imagem por variação — o front envia o arquivo ao
     * criar/editar uma variação; o picture_id retornado é gravado no rascunho
     * antes de chamar /items/validate ou /items (o ML exige picture_ids registrados,
     * não URLs brutas, no campo variations[].picture_ids).
     *
     * T-77-04: double-check de empresa (cópia exata de atualizarRascunho, linhas 142-156)
     *          antes de qualquer chamada à API ML — impede publicador sem atribuição de
     *          fazer upload na conta do cliente.
     * T-77-05: validação file+image+max:10240 (10 MB) antes de repassar ao ML.
     * T-77-06: try/catch \Throwable → 422 pt-BR genérico; detalhe real apenas em Log
     *          (o service pode lançar RuntimeException quando a empresa não tem token).
     */
    public function uploadImagem(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // T-77-04: double-check por empresa — cópia do bloco de atualizarRascunho (SEL-04)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // T-77-05: valida tipo e tamanho antes de enviar ao ML (10 MB máximo)
        $request->validate([
            'imagem' => ['required', 'file', 'image', 'max:10240'],
        ]);

        try {
            // Monta os parâmetros de upload — binário + nome original do arquivo
            $company   = $rascunho->company;
            $arquivo   = $request->file('imagem');
            $pictureId = $this->imagem->enviar($company, $arquivo->get(), $arquivo->getClientOriginalName());
        } catch (\Throwable $e) {
            // T-77-06: detalhe técnico apenas no log; resposta genérica em pt-BR para o front
            \Illuminate\Support\Facades\Log::error(
                "[MLB Publicacao] Falha no upload de imagem empresa {$rascunho->company_id}: {$e->getMessage()}"
            );

            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Falha no upload da imagem para o Mercado Livre.']],
            ], 422);
        }

        // Retorna null quando o ML aceita a requisição mas não retorna um id (ex.: HTTP 2xx sem body)
        if ($pictureId === null) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Falha no upload da imagem para o Mercado Livre.']],
            ], 422);
        }

        // Sucesso: devolve o picture_id para o front gravar na variação correspondente
        return response()->json(['ok' => true, 'picture_id' => $pictureId]);
    }

    /**
     * Cria um rascunho pré-preenchido a partir de um produto da planilha do cliente.
     *
     * Busca o produto pelo SKU dentro de mlb_implementacoes.dados, calcula preços
     * com calcPreco() e monta o payload completo (SELLER_PACKAGE_*, price, estoque,
     * descrição). Isso elimina a necessidade de abrir o Link do Publicador para ver
     * os dados do cliente antes de iniciar o wizard.
     *
     * DRAFT-01: leitura de mlb_implementacoes.dados
     * DRAFT-02: rascunho pré-preenchido com SELLER_PACKAGE_* (peso/dims), estoque, descrição, SKU
     * DRAFT-03: preço calculado server-side (calcPreco PHP = calcPreco JS)
     *
     * T-76-01: abort_unless por responsavel_id/isAdmin antes de qualquer leitura.
     * T-76-02: company_id e user_id derivados server-side (nunca do request).
     * T-76-03: sku e tier validados; sku casado por comparação exata na planilha.
     */
    public function rascunhoPorProduto(Request $request, Company $company): JsonResponse
    {
        // Só empresas com conta ML conectada
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin)

        // T-76-03: valida apenas sku e tier — company_id/user_id derivados server-side (T-76-02)
        $dados = $request->validate([
            'sku'  => ['required', 'string', 'max:100'],
            'tier' => ['nullable', 'string', 'in:classico,premium'],
        ]);

        // mlb_empresa ligada (se houver) → dados do cliente
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)->with('implementacao')->first();

        // Monta a lista de produtos com preços e busca o produto solicitado por SKU
        $produtos = $this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados);
        $skuBusca = trim($dados['sku']);
        $produto  = collect($produtos)->first(fn ($p) => trim($p['sku']) === $skuBusca);

        if ($produto === null) {
            return response()->json([
                'ok'   => false,
                'erro' => 'Produto não encontrado nos dados do cliente.',
            ], 422);
        }

        $tier  = $dados['tier'] ?? 'classico';
        $price = $tier === 'premium' ? $produto['preco_anunciado_p'] : $produto['preco_anunciado_c'];

        // ─── Monta atributos SELLER_PACKAGE_* (somente campos preenchidos) ───
        // Conversão: peso_kg (kg) → SELLER_PACKAGE_WEIGHT em gramas (ex.: 2.5 kg → 2500 g)
        // Mapeamento: profundidade do cliente → SELLER_PACKAGE_LENGTH (comprimento no ML)
        $attributes = [];

        $pesoG = round(floatval($produto['peso_kg']) * 1000);
        if ($pesoG > 0) {
            $attributes[] = ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => "{$pesoG} g"];
        }

        $alturaCm = floatval($produto['altura']);
        if ($alturaCm > 0) {
            $attributes[] = ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_name' => "{$alturaCm} cm"];
        }

        $larguraCm = floatval($produto['largura']);
        if ($larguraCm > 0) {
            $attributes[] = ['id' => 'SELLER_PACKAGE_WIDTH', 'value_name' => "{$larguraCm} cm"];
        }

        // profundidade → LENGTH (mapeamento do campo do cliente para a nomenclatura ML)
        $comprimentoCm = floatval($produto['profundidade']);
        if ($comprimentoCm > 0) {
            $attributes[] = ['id' => 'SELLER_PACKAGE_LENGTH', 'value_name' => "{$comprimentoCm} cm"];
        }

        // ─── Monta payload no shape do montarPayload() do wizard (AnunciarML.jsx:136) ───
        // title truncado a 60 chars (limite aceito pelo ML)
        $title = mb_substr(trim($produto['produto']), 0, 60);

        $payload = [
            'title'              => $title,
            'category_id'        => null,                        // publicador escolhe no passo seguinte
            'price'              => $price,                      // null se custo não informado
            'currency_id'        => 'BRL',
            'available_quantity' => intval($produto['estoque']),
            'condition'          => 'new',
            'listing_type_id'    => $tier === 'premium' ? 'gold_pro' : 'gold_special',
            'attributes'         => $attributes,
            'pictures'           => [],
            'sale_terms'         => [],
            'shipping'           => [
                'mode'          => 'me2',
                'local_pick_up' => false,
                'free_shipping' => false,
            ],
            'description'        => $produto['descricao'] ?? '',
            // meta_campos: mapa de origem por campo — consumido por 76-02 para distinção visual.
            // Cada chave é um campo individual (não agrupado) para que editar 1 campo não
            // marque os outros como 'publicador' (degrada fidelidade do DRAFT-04).
            'meta_campos'        => [
                'title'              => 'cliente',
                'price'              => 'cliente',
                'available_quantity' => 'cliente',
                'description'        => 'cliente',
                'pesoG'              => 'cliente',
                'alturaCm'           => 'cliente',
                'larguraCm'          => 'cliente',
                'comprimentoCm'      => 'cliente',
            ],
        ];

        // T-76-02: company_id e user_id derivados server-side (NUNCA do request)
        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => $mlbEmpresa?->id,   // vínculo opcional
            'user_id'        => $request->user()->id,
            'category_id'    => null,
            'payload'        => $payload,
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
            'sku_origem'     => $produto['sku'],
            'listing_tier'   => $tier,
        ]);

        return response()->json([
            'ok'                => true,
            'rascunho'          => $rascunho,
            'preco_indisponivel' => $price === null,
        ]);
    }

    // ─── Metadados do wizard (JSON, via app token cacheado) ───

    /** Preditor de categoria pelo texto do título. */
    public function preverCategoria(Request $request)
    {
        $candidatos = $this->meta->preverCategoria((string) $request->query('q', ''));

        // WIZ-02 (best-effort): enriquece cada candidato com o caminho da categoria
        // usando apenas o cache — sem nova chamada HTTP. O custo é zero se já estiver
        // em cache; se não estiver, o candidato fica sem "path" (degradação graciosa).
        // O front pede o breadcrumb completo ao escolher a categoria via atributos().
        $candidatos = array_map(function (array $candidato) {
            $catId = $candidato['category_id'] ?? '';
            if ($catId === '') {
                return $candidato;
            }

            // Usa a chave interna do cache do MlCatalogoMetaService (ml_meta_categoria_{id})
            $cached = \Illuminate\Support\Facades\Cache::get("ml_meta_categoria_{$catId}");
            if (is_array($cached) && ! empty($cached['path_from_root'])) {
                $candidato['path'] = array_column($cached['path_from_root'], 'name');
            }

            return $candidato;
        }, $candidatos);

        return response()->json($candidatos);
    }

    /** Detalhe da categoria + atributos (formulário dinâmico). */
    public function atributos(string $categoryId)
    {
        $categoria = $this->meta->categoria($categoryId);
        $atributos = $this->meta->atributos($categoryId);

        // WIZ-03: catálogo obrigatório sinalizado para o front bloquear publicação
        // sem catalog_product_id. Verdadeiro quando qualquer atributo da categoria
        // tiver tags.catalog_required = true (ex.: eletrônicos de marca, instrumentos).
        $catalogRequired = collect($atributos)->contains(
            fn ($a) => data_get($a, 'tags.catalog_required') === true
        );

        return response()->json([
            'categoria'        => $categoria,
            'atributos'        => $atributos,
            'catalog_required' => $catalogRequired,
        ]);
    }

    /**
     * Colunas da grade em massa para UMA categoria (SHEET-02, SHEET-03).
     *
     * SHEET-02: devolve APENAS os atributos obrigatórios desta categoria — nunca a
     * união de 28 colunas de todas as categorias de móveis. Cada aba da grade tem
     * só as suas colunas.
     * SHEET-03: devolve o caminho completo (breadcrumb) da categoria, resolvendo a
     * queixa do usuário de que só o `MLBxxxx` é confuso.
     *
     * O filtro de OBRIGATÓRIOS espelha EXATAMENTE o wizard (AnunciarML.jsx:1102):
     *   required && !allow_variations && id !== 'SIZE_GRID_ID' && !contains(id,'GRID')
     * — exclui Cor/Tamanho (vão em Variações, não na grade em massa) e a grade de
     * moda (SIZE_GRID_ID / *GRID*). Cada coluna traz `values` só quando value_type
     * == 'list', para a grade oferecer um <select> na célula.
     */
    public function colunasCategoria(string $categoryId): JsonResponse
    {
        $categoria = $this->meta->categoria($categoryId);
        $atributos = $this->meta->atributos($categoryId);

        // Breadcrumb: nomes de path_from_root (ex.: Casa, Móveis › … › Cadeiras) — SHEET-03
        $caminho = array_column(
            (array) data_get($categoria, 'path_from_root', []),
            'name'
        );

        // Título máximo aceito pela categoria (fallback 60, igual à coluna base do sketch)
        $maxTitulo = (int) (data_get($categoria, 'settings.max_title_length') ?: 60);

        // ═══════════════════════════════════════════════════════════════════
        // Obrigatórios que viram COLUNA na grade em massa.
        //
        // ATENÇÃO — este filtro DIVERGE do wizard DE PROPÓSITO. Não "corrija"
        // para igualar: os contextos são diferentes e igualar reabre um erro de
        // produção real.
        //
        // O wizard (AnunciarML.jsx) monta 1 anúncio com N variações, e lá os
        // atributos `allow_variations` (COLOR, SIZE…) são preenchidos DENTRO de
        // cada variação — por isso ele os tira da ficha técnica.
        //
        // A grade em massa é 1 linha = 1 anúncio SIMPLES, sem variação. Se
        // filtrarmos `allow_variations` aqui, um atributo que é `required: true`
        // some da planilha e o publicador não tem onde preenchê-lo — e o ML
        // recusa a publicação:
        //   "The attributes [COLOR, SIZE] are required for category MLB108791"
        //   (erro 400 real, publicação em massa, 2026-07-15)
        // O próprio erro aponta a saída: o atributo pode ir na lista `attributes`
        // do item OU nas variações. Sem variação, vai na lista — logo, precisa
        // de coluna.
        //
        // GRID continua fora: SIZE_GRID_ID (`value_type: grid_id`) não é um valor
        // que se digita numa célula — é a referência a uma tabela de medidas que o
        // wizard resolve com uma UI própria (rota rascunho.grades).
        // ═══════════════════════════════════════════════════════════════════
        $ehGrid = fn (string $id) => $id === 'SIZE_GRID_ID' || str_contains($id, 'GRID');

        $obrigatorios = collect($atributos)
            ->filter(function ($a) use ($ehGrid) {
                $id = (string) data_get($a, 'id', '');

                return data_get($a, 'tags.required') === true
                    && ! $ehGrid($id);
            })
            ->map(fn ($a) => [
                'id'         => data_get($a, 'id'),
                'name'       => data_get($a, 'name'),
                'value_type' => data_get($a, 'value_type'),
                // values só faz sentido para listas (a grade monta o <select> a partir daqui)
                'values'     => data_get($a, 'value_type') === 'list'
                    ? array_values((array) data_get($a, 'values', []))
                    : [],
            ])
            ->values()
            ->all();

        // ═══════════════════════════════════════════════════════════════════
        // Características secundárias: os atributos OPCIONAIS da categoria.
        //
        // O ML pede esses campos no anúncio dele e eles pesam na qualidade/busca —
        // não são enfeite. O wizard já os oferece (seção "Características
        // secundárias"); a grade em massa não os recebia, então quem publica em
        // lote não tinha como preenchê-los. Paridade: o que existe no individual
        // tem que existir no em massa.
        //
        // Filtro espelha o do wizard (AnunciarML.jsx:1258): fora os obrigatórios
        // (que já vão em `obrigatorios`), os de variação, os ocultos/read-only, as
        // grades de moda, e os que têm campo próprio na grade (GTIN/SKU) ou UI
        // dedicada (CATALOG_PRODUCT_ID).
        // ═══════════════════════════════════════════════════════════════════
        $opcionais = collect($atributos)
            ->filter(function ($a) use ($ehGrid) {
                $id = (string) data_get($a, 'id', '');

                return data_get($a, 'tags.required') !== true
                    && data_get($a, 'tags.allow_variations') !== true
                    && data_get($a, 'tags.hidden') !== true
                    && data_get($a, 'tags.read_only') !== true
                    && ! $ehGrid($id)
                    && ! in_array($id, ['CATALOG_PRODUCT_ID', 'GTIN', 'SELLER_SKU'], true);
            })
            ->map(fn ($a) => [
                'id'         => data_get($a, 'id'),
                'name'       => data_get($a, 'name'),
                'value_type' => data_get($a, 'value_type'),
                'values'     => data_get($a, 'value_type') === 'list'
                    ? array_values((array) data_get($a, 'values', []))
                    : [],
            ])
            ->values()
            ->all();

        // WIZ-03 / catálogo obrigatório (mesma lógica de atributos()) — a grade bloqueia
        // publicação sem catalog_product_id quando a categoria exige (ex.: eletrônicos).
        $catalogRequired = collect($atributos)->contains(
            fn ($a) => data_get($a, 'tags.catalog_required') === true
        );

        return response()->json([
            'caminho'          => $caminho,          // array de strings (breadcrumb) — SHEET-03
            'max_title_length' => $maxTitulo,        // int
            'obrigatorios'     => $obrigatorios,     // obrigatórios da categoria (ficha técnica)
            'opcionais'        => $opcionais,        // características secundárias (paridade com o wizard)
            'catalog_required' => $catalogRequired,  // bool
        ]);
    }

    /**
     * Lista as grades de tamanho disponíveis para o vendedor no domínio da categoria.
     *
     * WIZ-06: endpoint consumido pelo wizard quando a categoria exige grade de tamanho
     * (atributo SIZE_GRID_ID / value_type grid_id). Retorna um select de grades em vez
     * do aviso "próxima versão".
     *
     * T-77-08: double-check de empresa antes de consultar grades da conta ML do cliente.
     * T-77-09: cache de 1h por company+domínio (feito no MlGradeService).
     * T-77-10: domain_id validado (string max:60) — vem do front (input não confiável).
     */
    public function listarGrades(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // T-77-08: double-check por empresa (cópia exata do bloco de atualizarRascunho, linhas 142-156)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // T-77-10: valida domain_id — vem do front (não confiável), string curta esperada
        $dados = $request->validate([
            'domain_id' => ['required', 'string', 'max:60'],
        ]);

        $domainId = $dados['domain_id'];

        try {
            // T-77-09: cache de 1h por company+domínio feito dentro do service
            $grades = $this->grade->listarGrades($rascunho->company, $domainId);

            return response()->json([
                'ok'     => true,
                'grades' => $grades['results'] ?? [],
            ]);
        } catch (\Throwable $e) {
            // Detalhe técnico apenas no log; resposta genérica em pt-BR para o front
            \Illuminate\Support\Facades\Log::error(
                "[MLB Grade] Falha ao listar grades rascunho {$rascunho->id}: {$e->getMessage()}"
            );

            return response()->json([
                'ok'     => false,
                'erros'  => [['mensagem' => 'Falha ao carregar grades do Mercado Livre.']],
            ], 422);
        }
    }

    /**
     * Cota o frete automaticamente a partir das dimensões e peso do pacote.
     *
     * Endpoint informativo do simulador de preço (SHIP-02): retorna estimativa de frete
     * via GET /users/{seller_id}/shipping_options/free. A estimativa é indicativa —
     * ME2 pode ignorar as dimensões enviadas (CAVEAT STACK.md linha 329).
     *
     * A publicação NUNCA é bloqueada por falha de cotação: quando o ML não responde
     * (conta sem ME, token expirado, endpoint fora do ar), o service retorna null e
     * este método responde 200 com estimativa_frete=null (degradação graciosa SHIP-02).
     *
     * T-78-01: double-check de empresa (cópia exata do bloco de listarGrades, linhas 514-529)
     *          antes de qualquer chamada à API ML via token de empresa.
     * T-78-02: validação de params (peso/dims/preço/tipo) antes de repassar ao service.
     */
    public function cotarFrete(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // T-78-01: double-check por empresa (cópia exata do bloco de listarGrades)
        if ($rascunho->mlb_empresa_id !== null) {
            // Caminho principal: usa responsavel_id da empresa (escopo correto por empresa)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->mlbEmpresa?->responsavel_id === $request->user()->id,
                403,
                'Empresa não atribuída a este publicador.'
            );
        } else {
            // Fallback para rascunhos legados criados antes do SEL-07 (sem mlb_empresa_id)
            abort_unless(
                $request->user()->isAdmin() || $rascunho->user_id === $request->user()->id,
                403,
                'Rascunho não pertence ao publicador autenticado.'
            );
        }

        // T-78-02: valida parâmetros de cotação — vêm do front (não confiáveis)
        $dados = $request->validate([
            'peso_g'          => ['required', 'numeric', 'min:1'],
            'altura_cm'       => ['required', 'numeric', 'min:1'],
            'largura_cm'      => ['required', 'numeric', 'min:1'],
            'comprimento_cm'  => ['required', 'numeric', 'min:1'],
            'item_price'      => ['required', 'numeric', 'min:0.01'],
            'listing_type_id' => ['required', 'string', 'in:gold_special,gold_pro'],
        ]);

        // MlFreteService retorna null em falha — não propaga exceção (SHIP-02)
        $resultado = $this->frete->cotar($rascunho->company, $dados);

        // Resposta sempre 200: estimativa_frete é float quando ML responde, null em falha
        // O front exibe o campo vazio em vez de bloquear a publicação (degradação graciosa)
        return response()->json([
            'ok'               => true,
            'estimativa_frete' => data_get($resultado, 'shipping_options.0.list_cost'),
            'opcoes'           => $resultado['shipping_options'] ?? [],
        ]);
    }

    /** Tipos de anúncio do site (clássico, premium, grátis...). */
    public function tiposAnuncio()
    {
        return response()->json($this->meta->tiposDeAnuncio());
    }

    // ─── Helpers privados — Phase 79 ───

    /**
     * Cria um rascunho com o tier oposto ao do rascunho de origem.
     *
     * Helper interno compartilhado por duplicarTier() e publicarDuplo().
     * Não faz double-check de empresa nem retorna JsonResponse — esses cuidados
     * ficam nos métodos públicos que chamam este helper.
     *
     * DUP-03: sufixo mínimo com strip idempotente:
     *   - Remove qualquer sufixo de tier anterior antes de anexar o novo.
     *   - Trunca a 60 chars (limite ML — mb_substr para acentos pt-BR).
     *   - Lança InvalidArgumentException quando os títulos ficam idênticos
     *     (capturada pelos métodos públicos que retornam 422).
     *
     * DUP-01: preço do tier oposto derivado de montarProdutosDoCliente() quando
     *   sku_origem estiver preenchido; caso contrário, copia do payload original
     *   (o publicador ajusta antes de publicar).
     *
     * DUP-04: ml_item_id_classico e ml_item_id_premium zerados — rascunho duplicado
     *   ainda não foi publicado.
     *
     * @throws \InvalidArgumentException quando os dois títulos ficariam idênticos.
     */
    private function criarDuplicataInterna(MlAnuncioRascunho $rascunho, User $user): MlAnuncioRascunho
    {
        // Determina o tier oposto e o listing_type_id correspondente (DUP-01)
        $tierAtual   = $rascunho->listing_tier ?? 'classico';
        $tierNovo    = $tierAtual === 'classico' ? 'premium' : 'classico';
        $listingNovo = $tierNovo === 'premium'   ? 'gold_pro' : 'gold_special';

        // Copia o payload e troca o listing_type_id
        $payloadNovo = $rascunho->payload ?? [];
        $payloadNovo['listing_type_id'] = $listingNovo;

        // DUP-03: strip idempotente de sufixos de tier conhecidos
        // Remove qualquer variação do sufixo no FINAL da string antes de reanexar
        $sufixosConhecidos = [' - Premium', ' - Clássico', ' - Classico', ' - Classic', ' - Pro'];
        $tituloBase        = $payloadNovo['title'] ?? '';
        foreach ($sufixosConhecidos as $s) {
            // Usa preg_replace para remover o sufixo exato apenas no final da string (case-insensitive)
            // Comentário: str_replace simples não garante remoção apenas no final; por isso usamos preg
            $tituloBase = preg_replace('/' . preg_quote($s, '/') . '\s*$/ui', '', $tituloBase);
        }
        $tituloBase = trim($tituloBase);

        $sufixoNovo          = $tierNovo === 'premium' ? ' - Premium' : ' - Clássico';
        $tituloNovo          = mb_substr($tituloBase . $sufixoNovo, 0, 60);
        $payloadNovo['title'] = $tituloNovo;

        // DUP-03: defesa anti-duplicata — nunca publicar se os dois títulos são idênticos
        // (compara o título GERADO com o título ORIGINAL do rascunho de origem)
        $tituloOriginal = $rascunho->payload['title'] ?? '';
        if ($tituloOriginal === $tituloNovo) {
            throw new \InvalidArgumentException(
                'Os dois títulos ficaram idênticos — ajuste o título antes de gerar o tier oposto.'
            );
        }

        // DUP-01: preço do tier oposto a partir de montarProdutosDoCliente (se SKU disponível)
        // Fallback: copia o preço do rascunho original (publicador ajusta antes de publicar)
        if ($rascunho->sku_origem !== null) {
            $mlbEmpresa = MlbEmpresa::where('id', $rascunho->mlb_empresa_id)->with('implementacao')->first();
            $produtos   = $this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados);
            $produto    = collect($produtos)->first(fn ($p) => trim($p['sku']) === trim($rascunho->sku_origem));

            if ($produto !== null) {
                // Usa o preço do tier oposto calculado no servidor
                $payloadNovo['price'] = $tierNovo === 'premium'
                    ? $produto['preco_anunciado_p']
                    : $produto['preco_anunciado_c'];
            }
            // Se o produto não for encontrado, mantém o preço do payload original (degradação graciosa)
        }

        // DUP-04: criação com ml_item_id_classico e ml_item_id_premium zerados
        // company_id e mlb_empresa_id copiados do rascunho de origem (imutáveis — SEL-03)
        return MlAnuncioRascunho::create([
            'company_id'          => $rascunho->company_id,
            'mlb_empresa_id'      => $rascunho->mlb_empresa_id,
            'user_id'             => $user->id,
            'category_id'         => $rascunho->category_id,
            'payload'             => $payloadNovo,
            'status'              => MlAnuncioRascunho::STATUS_RASCUNHO,
            'sku_origem'          => $rascunho->sku_origem,
            'listing_tier'        => $tierNovo,
            'ml_item_id_classico' => null, // DUP-04: zerado — ainda não publicado
            'ml_item_id_premium'  => null,
        ]);
    }

    /**
     * Cria um rascunho-template a partir do rascunho de origem (UX-03 — Phase 81).
     *
     * Template = cópia fiel do publicado: mantém título, listing_type_id, tier e
     * todos os campos do payload intactos. Não adiciona sufixo, não troca tier,
     * não recalcula preço. Os três ml_item_id* nascem null (novo anúncio, ainda
     * não publicado). Status sempre STATUS_RASCUNHO.
     *
     * Não lança exceção — não há verificação de título idêntico neste caminho.
     */
    private function criarTemplateInterno(MlAnuncioRascunho $rascunho, User $user): MlAnuncioRascunho
    {
        return MlAnuncioRascunho::create([
            'company_id'          => $rascunho->company_id,
            'mlb_empresa_id'      => $rascunho->mlb_empresa_id,
            'user_id'             => $user->id,
            'category_id'         => $rascunho->category_id,
            'sku_origem'          => $rascunho->sku_origem,
            'listing_tier'        => $rascunho->listing_tier,
            'payload'             => $rascunho->payload ?? [],
            'status'              => MlAnuncioRascunho::STATUS_RASCUNHO,
            'ml_item_id'          => null, // UX-03: zerado — novo anúncio não publicado
            'ml_item_id_classico' => null,
            'ml_item_id_premium'  => null,
        ]);
    }

    /**
     * Tenta publicar um rascunho ENGOLINDO a exceção (DUP-04: falha de um tier não aborta o outro).
     *
     * Diferença crítica em relação a MlPublicacaoService::publicar():
     *   - publicar() RELANÇA a exceção (comportamento original preservado);
     *   - tentarPublicar() ENGOLE e retorna array com ok=false + erros.
     * Isso garante que a falha do 2º tier não interrompa o fluxo de publicarDuplo().
     *
     * @return array{ok: bool, status: ?string, ml_item_id: ?string, tier: ?string, erros: ?array}
     */
    private function tentarPublicar(MlAnuncioRascunho $r): array
    {
        try {
            $publicado = $this->publicacao->publicar($r);

            return [
                'ok'         => $publicado->status === MlAnuncioRascunho::STATUS_PUBLICADO,
                'status'     => $publicado->status,
                'ml_item_id' => $publicado->ml_item_id,
                'tier'       => $r->listing_tier,
                'erros'      => $publicado->validation_errors,
            ];
        } catch (\Throwable $e) {
            Log::error("[MLB Publicacao] Falha ao publicar tier {$r->listing_tier} rascunho {$r->id}: {$e->getMessage()}");
            $fresh = $r->fresh();

            return [
                'ok'         => false,
                'status'     => $fresh?->status,
                'ml_item_id' => null,
                'tier'       => $r->listing_tier,
                'erros'      => $fresh?->validation_errors ?? [['mensagem' => 'Falha ao publicar. Tente novamente.']],
            ];
        }
    }

    // ─── Helpers privados ───

    /**
     * Porta PHP de calcPreco() do ImplementacaoPublicador.jsx (linha 9).
     *
     * Fórmula: preço = (custo + frete) / (1 - comissao - imposto - mc - ll)
     * Retorna null se o denominador for <= 0 (comissões somam >= 100%)
     * ou se o custo for <= 0 (sem custo = sem preço calculável).
     *
     * @param float $custo    custo de aquisição (R$)
     * @param float $frete    frete estimado para o tier (R$)
     * @param float $comissao comissão do tier (0-1, ex: 0.115 para Clássico)
     * @param float $imposto  imposto (0-1, ex: 0.19)
     * @param float $mc       margem de contribuição alvo (0-1, default 0)
     * @param float $ll       lucro líquido alvo (0-1, default 0)
     * @return float|null     preço base calculado (sem acréscimo) ou null quando inviável
     */
    private function calcPreco(
        float $custo,
        float $frete,
        float $comissao,
        float $imposto,
        float $mc,
        float $ll
    ): ?float {
        $d = 1 - $comissao - $imposto - $mc - $ll;
        if ($d <= 0 || $custo <= 0) {
            return null;
        }

        return ($custo + $frete) / $d;
    }

    /**
     * Lê os produtos do cliente de mlb_implementacoes.dados e une com os preços
     * da precificação, calculando preço clássico, premium e anunciado (com acréscimo).
     *
     * Porta PHP de mergeProdutos() do ImplementacaoPublicador.jsx (linha 27).
     * Usa floatval() / intval() / trim() antes de qualquer cálculo para lidar com
     * as strings arbitrárias digitadas pelo cliente (incluindo '—' e vazios).
     *
     * Retorna array_values para garantir array indexado (não associativo).
     * Produto sem nome e sem SKU é pulado (linha em branco da planilha).
     *
     * @param  array|null $dados  Conteúdo de MlbImplementacao::$dados (cast array)
     * @return array              Lista de produtos com preços calculados. Vazio se dados == null.
     */
    private function montarProdutosDoCliente(?array $dados): array
    {
        if ($dados === null) {
            return [];
        }

        // Extrai os arrays de produtos e precificação com defensivos de ausência de chave
        $precif   = $dados['itens']['precificacao']                     ?? [];
        $produtos = $dados['itens']['planilha_produtos']['produtos']     ?? [];

        if (empty($produtos)) {
            return [];
        }

        // Parâmetros globais com defaults defensivos (espelham dadosPadrao() do modelo)
        $comissaoC = floatval($precif['classico']['comissao']  ?? 0.115);
        $impostoC  = floatval($precif['classico']['imposto']   ?? 0.19);
        $comissaoP = floatval($precif['premium']['comissao']   ?? 0.165);
        $impostoP  = floatval($precif['premium']['imposto']    ?? 0.19);
        $mc        = floatval($precif['margem_contribuicao']   ?? 0);
        $ll        = floatval($precif['lucro_liquido']         ?? 0);
        $acr       = floatval($precif['acrescimo']             ?? 0.20);

        // Monta mapa SKU → preços (análogo ao pricingMap do JS, linha 39)
        $pricingMap = [];
        foreach ($precif['produtos'] ?? [] as $i => $p) {
            $key             = trim($p['sku'] ?? '') ?: "__idx_{$i}";
            $pricingMap[$key] = $p;
        }

        $resultado = [];

        foreach ($produtos as $idx => $produto) {
            // Pula linhas completamente em branco (sem nome e sem SKU)
            if (!trim($produto['produto'] ?? '') && !trim($produto['sku'] ?? '')) {
                continue;
            }

            // Chave de join: SKU do produto ou fallback posicional
            $key = trim($produto['sku'] ?? '') ?: "__idx_{$idx}";
            $pr  = $pricingMap[$key] ?? [];

            // Valores numéricos: floatval() converte '—' e '' para 0.0
            $custo         = floatval($pr['custo']          ?? 0);
            $freteClassico = floatval($pr['frete_classico'] ?? 0);
            $fretePremiun  = floatval($pr['frete_premium']  ?? 0);

            // Calcula preços dos dois tiers
            $precoC = $this->calcPreco($custo, $freteClassico, $comissaoC, $impostoC, $mc, $ll);
            $precoP = $this->calcPreco($custo, $fretePremiun,  $comissaoP, $impostoP, $mc, $ll);

            // Preço anunciado = preço base * (1 + acréscimo), arredondado em 2 casas
            $precoAnunciadoC = $precoC !== null ? round($precoC * (1 + $acr), 2) : null;
            $precoAnunciadoP = $precoP !== null ? round($precoP * (1 + $acr), 2) : null;

            // tem_dimensoes = todos os campos de embalagem preenchidos (não-vazio)
            $temDimensoes = (
                trim($produto['altura']       ?? '') !== '' &&
                trim($produto['largura']      ?? '') !== '' &&
                trim($produto['profundidade'] ?? '') !== '' &&
                trim($produto['peso_kg']      ?? '') !== ''
            );

            $resultado[] = [
                'sku'              => trim($produto['sku']            ?? ''),
                'produto'          => $produto['produto']             ?? '',
                'curva'            => $produto['curva']               ?? '',
                'altura'           => $produto['altura']              ?? '',
                'largura'          => $produto['largura']             ?? '',
                'profundidade'     => $produto['profundidade']        ?? '',
                'peso_kg'          => $produto['peso_kg']             ?? '',
                'estoque'          => $produto['estoque']             ?? '',
                'descricao'        => $produto['descricao']           ?? '',
                'especificacoes'   => $produto['especificacoes']      ?? '',
                'custo'            => $custo,
                'frete_classico'   => $freteClassico,
                'frete_premium'    => $fretePremiun,
                'preco_classico'   => $precoC,
                'preco_premium'    => $precoP,
                'preco_anunciado_c' => $precoAnunciadoC,
                'preco_anunciado_p' => $precoAnunciadoP,
                'tem_dimensoes'    => $temDimensoes,
                'tem_preco'        => $custo > 0,
            ];
        }

        return array_values($resultado);
    }

    /**
     * Publica um lote de rascunhos da mesma empresa de forma assíncrona.
     *
     * BULK-01: double-check de empresa (SEL-04) — rascunho_ids de outra empresa é 403.
     * BULK-02: pré-check de token 1x antes de qualquer dispatch — conta desconectada é 422.
     * BULK-02: fan-out com delay escalonado de 3s por posição respeita rate limit do ML.
     * BULK-03: ShouldBeUnique no job garante que duplo-envio não duplica os jobs.
     * BULK-04: cada rascunho recebe status=publicando imediatamente após o dispatch.
     *
     * Máximo de 50 rascunhos por chamada (T-80-03 — proteção contra DoS / flood 429).
     */
    public function publicarLote(Request $request): JsonResponse
    {
        // Valida a entrada — company_id + lista de ids de rascunhos
        $dados = $request->validate([
            'company_id'    => ['required', 'integer', 'exists:companies,id'],
            'rascunho_ids'  => ['required', 'array', 'min:1', 'max:50'],
            'rascunho_ids.*' => ['integer', 'exists:ml_anuncio_rascunhos,id'],
        ]);

        $company = Company::findOrFail($dados['company_id']);
        $company->loadMissing('mlToken');

        // SEL-04: double-check por empresa (BULK-01) — publicador só pode publicar em empresa atribuída
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)->first();
        abort_unless(
            $request->user()->isAdmin() || $mlbEmpresa?->responsavel_id === $request->user()->id,
            403,
            'Empresa não atribuída a este publicador.'
        );

        // BULK-02: pré-check de token 1x — verificação única antes de qualquer dispatch
        $token = $this->ml->ensureValidToken($company);
        if (! $token) {
            return response()->json([
                'ok'   => false,
                'erros' => [['mensagem' => "Conta ML {$company->name} desconectada — reconecte via Configurações."]],
            ], 422);
        }

        // Double-check de pertencimento: TODOS os rascunho_ids devem ser da empresa informada (T-80-02)
        $rascunhos = MlAnuncioRascunho::whereIn('id', $dados['rascunho_ids'])
            ->where('company_id', $dados['company_id'])
            ->get();

        if ($rascunhos->count() !== count($dados['rascunho_ids'])) {
            return response()->json([
                'ok'   => false,
                'erros' => [['mensagem' => 'Um ou mais rascunhos não pertencem à empresa informada.']],
            ], 403);
        }

        // Fan-out com delay escalonado (BULK-02) + marca STATUS_PUBLICANDO (BULK-04)
        // 3s por posição respeita rate limit do ML (~2 chamadas HTTP por publicação)
        // ShouldBeUnique no job já evita duplicatas de dispatch
        foreach ($rascunhos->values() as $i => $r) {
            // Pula rascunhos que já estão em processo de publicação ou publicados
            if (in_array($r->status, [MlAnuncioRascunho::STATUS_PUBLICANDO, MlAnuncioRascunho::STATUS_PUBLICADO], true)) {
                continue;
            }

            // Marca como publicando imediatamente para feedback visual no painel (BULK-04)
            $r->update(['status' => MlAnuncioRascunho::STATUS_PUBLICANDO]);

            // Enfileira com delay escalonado para não saturar a API ML
            PublicarAnuncioMlJob::dispatch($r->id)->delay(now()->addSeconds($i * 3));
        }

        $totalEnfileirado = $rascunhos->whereNotIn('status', [
            MlAnuncioRascunho::STATUS_PUBLICANDO,
            MlAnuncioRascunho::STATUS_PUBLICADO,
        ])->count();

        return response()->json([
            'ok'          => true,
            'enfileirados' => $rascunhos->count(),
            'delays'      => ['inicio' => 0, 'fim' => max(0, ($rascunhos->count() - 1) * 3)],
        ]);
    }

    /**
     * Retorna as empresas que PODEM receber publicação: as `companies` com conta
     * ML conectada (ml_token ativo). Esta é a fonte de verdade do painel — o que
     * "pode publicar" de fato é a conta que fez OAuth, não a régua do onboarding.
     *
     * Escopo por publicador está DEFERIDO: sob o gate role:admin todo acessante é
     * admin e vê todas as contas conectadas. Quando o vínculo publicador→conta ML
     * for modelado, o filtro entra aqui.
     *
     * `tem_dados_cliente` indica se existe uma mlb_empresa ligada a esta company
     * (via company_id) com implementação preenchida — habilita o pré-preenchimento
     * do rascunho a partir da planilha do cliente (Phase 76). Onde não há vínculo,
     * o wizard abre em branco (degradação graciosa).
     *
     * @return Collection<int, array{id: int, nome: string, company_id: int, tem_token: bool, token_expirado: bool, tem_dados_cliente: bool, rascunhos_abertos: int, publicando_count: int}>
     */
    /**
     * Resume os erros de publicação de um rascunho em uma única linha legível
     * (o painel "Rascunhos recentes" não mostra mais o JSON cru do ML).
     */
    /**
     * Nome curto (folha do breadcrumb) de uma categoria ML, para o cabeçalho do lote
     * no histórico — "Meias" em vez do MLBxxxx cru. Reusa o cache do
     * MlCatalogoMetaService (ml_meta_categoria_{id}); a categoria de um lote já publicado
     * costuma estar quente do uso na grade. Best-effort: degrada para o próprio código
     * se a meta não resolver (rede/token), nunca derruba o render do histórico.
     */
    private function nomeCategoria(?string $categoryId): ?string
    {
        if (! $categoryId) {
            return null;
        }

        try {
            $cat  = $this->meta->categoria($categoryId);
            $path = array_column((array) data_get($cat, 'path_from_root', []), 'name');

            return ! empty($path)
                ? (string) end($path)
                : ((string) data_get($cat, 'name', '') ?: $categoryId);
        } catch (\Throwable $e) {
            return $categoryId;
        }
    }

    private function resumoErro(?array $errors): ?string
    {
        if (empty($errors)) {
            return null;
        }

        $primeiro = $errors[0] ?? null;
        $msg = is_array($primeiro)
            ? ($primeiro['mensagem'] ?? $primeiro['message'] ?? '')
            : (string) $primeiro;

        $msg = trim(preg_replace('/\s+/', ' ', (string) $msg));
        if ($msg === '') {
            return 'Falha na publicação.';
        }

        return mb_strlen($msg) > 120 ? mb_substr($msg, 0, 117) . '…' : $msg;
    }

    /**
     * Texto completo dos erros de publicação (todas as causas), para o publicador
     * expandir/copiar quando precisar depurar. Uma causa por linha.
     */
    private function erroCompleto(?array $errors): ?string
    {
        if (empty($errors)) {
            return null;
        }

        return collect($errors)
            ->map(fn ($e) => is_array($e)
                ? ($e['mensagem'] ?? $e['message'] ?? json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : (string) $e)
            ->implode("\n");
    }

    private function empresas(Request $request): Collection
    {
        // Fonte: companies com ml_token. O whereHas filtra no banco — só conectadas.
        return Company::query()
            ->whereHas('mlToken')
            ->with('mlToken')
            ->orderBy('name')
            ->get()
            ->map(function ($c) {
                // Rascunhos em aberto contados por company_id (âncora do rascunho)
                $abertos = MlAnuncioRascunho::where('company_id', $c->id)
                    ->whereIn('status', [
                        MlAnuncioRascunho::STATUS_RASCUNHO,
                        MlAnuncioRascunho::STATUS_VALIDADO,
                        MlAnuncioRascunho::STATUS_ERRO,
                    ])
                    ->count();

                // BULK-04: contador de rascunhos em processo de publicação assíncrona
                $publicando = MlAnuncioRascunho::where('company_id', $c->id)
                    ->where('status', MlAnuncioRascunho::STATUS_PUBLICANDO)
                    ->count();

                // mlb_empresa ligada (se houver) → habilita dados do cliente (Phase 76)
                $mlbEmp = MlbEmpresa::where('company_id', $c->id)
                    ->with('implementacao')
                    ->first();

                return [
                    'id'                => $c->id,   // âncora = company_id
                    'nome'              => $c->name,
                    'company_id'        => $c->id,
                    // Expõe apenas booleans — access_token permanece hidden
                    'tem_token'         => true,     // filtrado por whereHas('mlToken')
                    'token_expirado'    => $c->mlToken?->isExpired() ?? false,
                    'tem_dados_cliente' => $mlbEmp?->implementacao !== null,
                    'rascunhos_abertos' => (int) $abertos,
                    // BULK-04: quantos rascunhos estão em publicação assíncrona agora
                    'publicando_count'  => (int) $publicando,
                ];
            });
    }
}
