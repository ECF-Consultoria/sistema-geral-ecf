<?php

namespace App\Http\Controllers;

use App\Jobs\GerarAnaliseAnuncioIaJob;
use App\Jobs\GerarCriativoIaJob;
use App\Jobs\PlanejarKitCriativosJob;
use App\Jobs\PublicarAnuncioMlJob;
use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlAcervoMetricaDiaria;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioIaAnalise;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\User;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativeKitDespachante;
use App\Services\Creative\CreativeKitPublicacao;
use App\Services\Creative\CreativePermissao;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\IaParaRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicacoesDaListaService;
use App\Services\Mlb\Acervo\AnuncioSaudeService;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlCompatibilidadeService;
use App\Services\Mlb\Publicacao\MlFreteService;
use App\Services\Mlb\Publicacao\MlGradeService;
use App\Services\Mlb\Publicacao\MlImagemService;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use App\Services\MercadoLivreService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    /**
     * `listing_type_id` real → o tipo que a tela de Publicações mostra
     * (LIST-20). É o INVERSO do mapa fechado que
     * `AcervoTriagemService::filtros()` usa para o filtro de tipo; as duas
     * chaves de saída vêm das constantes PÚBLICAS daquele serviço, para que o
     * nome do tipo exista num lugar só.
     *
     * ⚠️ `listing_type_id` fora destes dois (`free`, `silver`, `bronze`, nulo)
     * não pertence a tipo nenhum e o item sai com `tipo => null`. Não cai no
     * Clássico "porque é o mais comum" — é a mesma recusa que o serviço faz na
     * ida. O gate que liga os dois lados é o caso de teste que filtra
     * `tipo=premium` e confere que TODO item devolvido tem `tipo === 'premium'`:
     * se este mapa divergir do de lá, ele reprova.
     */
    private const TIPO_POR_LISTING_TYPE = [
        'gold_special' => AcervoTriagemService::TIPO_CLASSICO,
        'gold_pro'     => AcervoTriagemService::TIPO_PREMIUM,
    ];

    /**
     * O nome do grupo das publicações que NÃO têm produto no Publicador —
     * as "Legado" (LIST-09/EXMP-01).
     *
     * ⚠️ Ele DIZ o que é, em vez de inventar um nome de produto ou de mostrar
     * "—". O selo de origem da própria tela já descreve o caso como *"Anúncio
     * do cliente, fora deste módulo"* (`MeusAnuncios.jsx:111`) — este rótulo é a
     * versão de cabeçalho de grupo daquela mesma verdade.
     *
     * ⚠️ Isto é o nome do GRUPO, não a palavra "Legado" da linha: a linha
     * continua recebendo as chaves de metodologia como `null` e quem desenha o
     * selo é a tela.
     */
    public const GRUPO_SEM_PRODUTO = 'Sem produto no Publicador';

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
        // AUTO-01: compatibilidades de autopeças (detecção + cascata de veículos)
        private MlCompatibilidadeService $compat,
        // Fase 160 Plano 01: leitor da chave liga/desliga do Creative Engine (OPS-03)
        private CreativeEngineAtivo $creativeAtivo,
        // Fase 160 Plano 01: staging da foto de referência em disco privado (FOTO-02)
        private ReferenciaEfemeraService $referenciaEfemera,
        // Fase 161: permissão explícita de planejar/gerar/regenerar/aprovar (OPS-04)
        private CreativePermissao $creativePermissao,
        // Fase 161: rótulos/objetivos padrão dos tipos de slot, para a resposta de status
        private CreativeSlotCatalog $creativeSlotCatalog,
        // Fase 161 Plano 02: despacho em ondas dos 7 slots do kit (GEN-03)
        private CreativeKitDespachante $creativeKitDespachante,
        // Fase 161 Plano 03: dono de payload.pictures quando há kit (PUB-01/04)
        private CreativeKitPublicacao $creativeKitPublicacao,
    ) {}

    /**
     * Momento 2: wizard com a empresa já fixada (SEL-07).
     *
     * Double-check de pertencimento: admin passa sempre; publicador só acessa
     * se a empresa foi atribuída a ele (abort 403 caso contrário — T-75-05).
     */
    public function wizard(Request $request, Company $company, ProgramasPublicadorService $programas)
    {
        // Só empresas com conta ML conectada podem publicar
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        // Fase 172-02: resolve a conta pela mesma chave do Publicador (company-N) —
        // prop NOVA, irmã de `empresa`, para o BarraDaConta/AbasDaConta da Wave 2.
        // Não substitui nem lê `empresa` em nenhum outro ponto desta action.
        $alvo  = $programas->resolver('company-'.$company->id);
        $conta = $alvo !== null ? $programas->empresaParaTela($alvo) : null;

        // mlb_empresa ligada (se houver) → dados do cliente para pré-preenchimento (Phase 76)
        $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)
            ->with('implementacao')
            ->first();

        // Fase 134 Plano 09: a sub-aba Rascunhos (meus()) agora lista o acervo
        // INTEIRO de rascunhos, não só os 50 mais recentes — o publicador passa a
        // clicar em rascunhos antigos com frequência. Sem esta busca complementar,
        // o efeito de `abrirRascunhoId` em AnunciarML.jsx (linha ~1276) procura o
        // alvo dentro dos 50 recebidos aqui, não encontra, e a tela abre em branco
        // sem nenhum erro visível. Escopada por company_id — mesma fronteira do
        // resto da action (T-134-01).
        $rascunhoAlvoId = $request->query('rascunho') ? (int) $request->query('rascunho') : null;

        $rascunhosRecentes = MlAnuncioRascunho::where('company_id', $company->id)
            ->latest()
            ->limit(50)
            ->get();

        if ($rascunhoAlvoId !== null && ! $rascunhosRecentes->contains('id', $rascunhoAlvoId)) {
            $rascunhoAlvo = MlAnuncioRascunho::where('company_id', $company->id)
                ->where('id', $rascunhoAlvoId)
                ->first();
            if ($rascunhoAlvo !== null) {
                $rascunhosRecentes->push($rascunhoAlvo);
            }
        }

        return Inertia::render('Mlb/AnunciarML', [
            'empresa' => [
                'id'         => $company->id,   // âncora = company_id
                'nome'       => $company->name,
                'company_id' => $company->id,
                'tem_token'  => true,
            ],
            // Fase 172-02: prop nova — shape completo de empresaParaTela() (chave,
            // programa, programa_rotulo, token, portal, etc.), para a Wave 2.
            'conta' => $conta,
            // Corte do frete grátis obrigatório do ME2 (R$): do config, o mesmo da IA do rascunho
            // e da cotação do Portal — o wizard não guarda mais um 79 próprio (09/10/2026).
            'frete_gratis_a_partir' => (float) config('estrutura_produtos.frete.gratis_obrigatorio_a_partir'),
            // Sobrevive ao F5. A análise leva minutos e mora no banco; sem
            // isto, recarregar a página no meio da geração dava a impressão
            // de que o trabalho tinha sido perdido — ele seguia rodando,
            // invisível. Janela de 2h: mais velho que isso é outro anúncio.
            'iaAnalise' => $this->ultimaAnaliseIa($company),
            'rascunhos' => $rascunhosRecentes
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
            'abrirRascunhoId' => $rascunhoAlvoId,
            // Fase 160 Plano 01: a tela não decide nada — só reflete a chave do
            // servidor (OPS-03). Com a chave desligada, PainelCriativosIa.jsx
            // não renderiza nada e a etapa 5 do wizard fica idêntica à de hoje.
            'creativeAtivo' => $this->creativeAtivo->ativa(),
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
    public function massa(Request $request, Company $company, ProgramasPublicadorService $programas, CabecalhoPublicadorService $cabecalhos)
    {
        // Só empresas com conta ML conectada podem publicar (mesma trava do wizard)
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        // Fase 172-02: prop `conta` nova — ver comentário equivalente em wizard().
        $alvo  = $programas->resolver('company-'.$company->id);
        $conta = $alvo !== null ? $programas->empresaParaTela($alvo) : null;

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
            // Fase 172-02: prop nova — ver comentário em wizard().
            'conta' => $conta,
            // Fase 177-03: prop ADITIVA do header global (HEAD-10). Pode ser `null`: aqui o
            // `resolver()` devolve null quando a Company não cai em nenhum programa — o header
            // tem de sobreviver a `cabecalho = null` (quem prova isso é o render do 177-04).
            'cabecalho' => $alvo !== null ? $cabecalhos->para($alvo, $conta, 'produtos') : null,
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
     * Fase 173-05: fonte trocada de `ml_anuncio_rascunhos` (assistente antigo)
     * para `pub_publicacoes`/`pub_publicacao_itens` (editor novo, Fase 134/164).
     * Medido em produção: o assistente antigo tem ZERO publicações em toda conta
     * de teste — esta aba ficava vazia enquanto as publicações reais (editor
     * novo) não apareciam em lugar nenhum. Decisão do usuário: "Histórico agora,
     * descontinuar depois" (a descontinuação do resto do assistente antigo — Em
     * massa, wizard, Meus Anúncios — é etapa própria e futura).
     *
     * `pode_duplicar=false` em todo item desta fonte: não existe hoje nenhuma
     * rotina de clonar um `PubRascunho` (só existe para `MlAnuncioRascunho`, via
     * `duplicarComoTemplate`/`duplicarLoteComoTemplate`, abaixo). Construir o
     * equivalente para o editor novo é recurso novo, não troca de fonte — por
     * isso a tela desabilita (nunca esconde) "Anunciar semelhante"/"duplicar
     * lote" para estes itens.
     */
    public function historico(Request $request, Company $company, ProgramasPublicadorService $programas, CabecalhoPublicadorService $cabecalhos)
    {
        // Só empresas com conta ML conectada (mesma trava do wizard e da grade)
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');
        // (escopo por publicador deferido — gate role:admin garante que é admin)

        // Fase 172-02: prop `conta` nova — ver comentário equivalente em wizard().
        $alvo  = $programas->resolver('company-'.$company->id);
        $conta = $alvo !== null ? $programas->empresaParaTela($alvo) : null;

        $busca = trim((string) $request->query('busca', ''));

        // Mesma dupla-âncora usada no resto do módulo (`$alvo`/`$conta` acima resolvem
        // pelo Publicador; aqui a query filtra direto em `pub_produtos`, que é quem
        // guarda as duas âncoras — MlbEmpresa antes de Company, D15).
        $mlbEmpresaId = MlbEmpresa::where('company_id', $company->id)->value('id');

        $query = PubPublicacaoItem::query()
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            // Só o que de fato nasceu no ML (CREATED) — SENT/PENDING/FAILED/UNKNOWN
            // não são "publicado" (equivalente ao antigo STATUS_PUBLICADO).
            ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
            ->where(function ($q) use ($company, $mlbEmpresaId) {
                $q->where('pub_produtos.company_id', $company->id);
                if ($mlbEmpresaId !== null) {
                    $q->orWhere('pub_produtos.mlb_empresa_id', $mlbEmpresaId);
                }
            });

        if ($busca !== '') {
            // O grupo é OBRIGATÓRIO: um orWhere solto sobe ao topo do WHERE e anula
            // o escopo por empresa/status — vazaria anúncio de outra empresa na busca.
            $query->where(function ($s) use ($busca) {
                $s->where('pub_publicacao_itens.payload->family_name', 'like', "%{$busca}%")
                  ->orWhere('pub_produtos.sku', 'like', "%{$busca}%");
            });
        }

        // Todos os publicados da empresa. O agrupamento por lote precisa do conjunto
        // inteiro: a publicação em massa cria N itens SEM coluna de lote no banco,
        // então o lote é reconstruído aqui (categoria do rascunho + dia de conclusão).
        $publicados = $query
            ->select([
                'pub_publicacao_itens.id',
                'pub_publicacao_itens.payload',
                'pub_publicacao_itens.listing_type_id',
                'pub_publicacao_itens.ml_item_id',
                'pub_rascunhos.id as rascunho_id',
                'pub_rascunhos.categoria_id as category_id',
                'pub_produtos.id as produto_id',
                'pub_publicacoes.concluida_em as published_at',
            ])
            ->orderByDesc('pub_publicacoes.concluida_em')
            ->orderByDesc('pub_publicacao_itens.id')
            ->get();

        // ─── SKU exibido (PubProduto::skuExibido(), não a coluna cru — mesma
        // convenção de produtosParaTela()) e foto (o payload só guarda o
        // ml_picture_id enviado ao ML; a URL que dá para exibir mora em
        // pub_imagens.ml_url) — os dois em lote, para não virar N+1. ───
        $produtoIds  = $publicados->pluck('produto_id')->unique()->filter()->values();
        $rascunhoIds = $publicados->pluck('rascunho_id')->unique()->filter()->values();

        $produtos = PubProduto::with('oferta')->whereIn('id', $produtoIds)->get()->keyBy('id');
        $imagensPorRascunho = PubImagem::whereIn('rascunho_id', $rascunhoIds)
            ->whereNotNull('ml_url')
            ->get()
            ->groupBy('rascunho_id');

        $fotoDoItem = function ($item) use ($imagensPorRascunho) {
            $picId   = data_get($item->payload, 'pictures.0.id');
            $imagens = $imagensPorRascunho->get($item->rascunho_id, collect());
            if ($picId !== null && ($match = $imagens->firstWhere('ml_picture_id', $picId)) !== null) {
                return $match->ml_url;
            }

            // Fallback: a primeira foto enviada do rascunho, quando o id do payload
            // não bate com nenhuma (dado legado/migração) — nunca inventa URL.
            return $imagens->sortBy('id')->first()?->ml_url;
        };

        // ─── Agrupa por LOTE = categoria + dia de conclusão (MESMA chave de hoje) ───
        $chaveLote = fn ($i) => ($i->category_id ?? 'sem-cat')
            . '|' . (optional($i->published_at ? \Illuminate\Support\Carbon::parse($i->published_at) : null)->toDateString() ?? 'sem-data');

        $grupos = $publicados
            ->groupBy($chaveLote)
            ->map(function ($itens) use ($chaveLote, $produtos, $fotoDoItem) {
                $primeiro    = $itens->first();
                $publicadoEm = $primeiro->published_at ? \Illuminate\Support\Carbon::parse($primeiro->published_at) : null;

                return [
                    'chave'        => $chaveLote($primeiro),
                    'category_id'  => $primeiro->category_id,
                    'categoria'    => $this->nomeCategoria($primeiro->category_id),
                    'data'         => optional($publicadoEm)->toDateString(),
                    'published_at' => optional($publicadoEm)->toIso8601String(),
                    'total'        => $itens->count(),
                    'itens'        => $itens->map(function ($i) use ($produtos, $fotoDoItem) {
                        $produto = $produtos->get($i->produto_id);

                        return [
                            'id'           => $i->id,
                            'titulo'       => (string) data_get($i->payload, 'family_name', ''),
                            'preco'        => data_get($i->payload, 'price'),
                            'foto'         => $fotoDoItem($i),
                            'sku_origem'   => $produto?->skuExibido(),
                            // Raw do ML ('gold_special'/'gold_pro') — MESMO literal que
                            // `listing_tier` já guardava no modelo antigo; rotuloTier()
                            // no front (anuncioHistoricoUtils.js) é indexado por esse
                            // valor, não pelo rótulo interno 'classico'/'premium'.
                            'listing_tier' => $i->listing_type_id,
                            'category_id'  => $i->category_id,
                            'published_at' => $i->published_at,
                            'ml_item_id'   => $i->ml_item_id,
                            // Não existe hoje rotina de clonar um PubRascunho (ver
                            // docblock do método) — desabilitado no front, nunca escondido.
                            'pode_duplicar' => false,
                        ];
                    })->values(),
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
            // Fase 172-02: prop nova — ver comentário em wizard().
            'conta'    => $conta,
            // Fase 177-03: prop ADITIVA do header global. Histórico é a sub-aba de Publicações
            // (No ar | Histórico), por isso `aba = publicacoes`. Pode ser `null` — ver massa().
            'cabecalho' => $alvo !== null ? $cabecalhos->para($alvo, $conta, 'publicacoes') : null,
            'grupos'   => $gruposPagina,
            'resumo'   => [
                'total_anuncios' => $publicados->count(),
                'total_lotes'    => $grupos->count(),
            ],
            'filtros'  => ['busca' => $busca],
        ]);
    }

    /**
     * "Meus Anúncios" (Fase 134) — acervo vivo da conta ML, lido EXCLUSIVAMENTE
     * do snapshot em `ml_acervo_itens` (D-05). Nenhuma chamada HTTP acontece
     * aqui: a coleta é feita por SyncMlAcervoCompanyJob/SyncMlAcervoDetalheJob
     * (134-04/134-05), agendados diariamente (134-06) ou disparados pelo botão
     * "Atualizar agora" (atualizarAgora(), abaixo).
     *
     * D-01: lista o acervo INTEIRO da conta, não só o que este módulo
     * publicou — `Publicacao::considerado()` não entra aqui, essa query LISTA,
     * não CONTA (regra travada em 134-CONTEXT.md, canonical_refs).
     */
    public function meus(
        Request $request,
        Company $company,
        ProgramasPublicadorService $programas,
        AcervoTriagemService $acervoTriagem,
        CabecalhoPublicadorService $cabecalhos,
        PublicacoesDaListaService $publicacoes,
    ) {
        // Mesma trava de todas as outras actions do módulo — nenhuma exceção (D-02, T-134-01/02).
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        // Fase 172-02: prop `conta` nova — ver comentário equivalente em wizard().
        $alvo  = $programas->resolver('company-'.$company->id);
        $conta = $alvo !== null ? $programas->empresaParaTela($alvo) : null;

        // ⚠️ `is_string` ANTES do `trim`, e não `(string)`: `?busca[]=x` entrega
        // um ARRAY, e `(string) []` emite `Array to string conversion`, que o
        // `HandleExceptions` do Laravel transforma em `ErrorException` — ou seja,
        // 500 numa querystring malformada. Mesma trava em `status`, `sub`,
        // `visao` e `sel` abaixo (T-180-04-T). Nenhum nome e nenhum tipo de prop
        // muda com isso: `busca` continua string.
        $buscaBruta = $request->query('busca', '');
        $busca      = is_string($buscaBruta) ? trim($buscaBruta) : '';

        // D-03: só ativos por padrão. Valor fora da lista fechada cai no default —
        // nunca interpolar querystring em SQL.
        // Default 'acionaveis' = active + paused + under_review. A FONTE ÚNICA
        // do mapeamento é o `match` de `AcervoTriagemService::escopo()` — o
        // porquê de cada status está lá, não aqui.
        //
        // Histórico curto: `paused` entrou em 2026-08-10 (emenda ao D-03,
        // 134-CONTEXT.md) porque com só 'active' no default o chip "Pausado" do
        // D-09 ficava permanentemente em 0 e o topo da ordenação do D-12 nunca
        // tinha pausado. `under_review` entrou em 2026-10-10 (quick 261010-nke)
        // porque é o status em que o anúncio recém-publicado nasce: fora do
        // default, ele não aparecia na tela nem na busca.
        //
        // A lista fechada de valores aceitos abaixo NÃO muda: o select da tela
        // continua com as mesmas 5 opções e nada em `resources/` foi tocado.
        //
        // ─── Emenda de 2026-10-10 (quick 261010-rie) ──────────────────────
        // `em_revisao` é opção NOVA na lista fechada, a pedido do usuário
        // (*"Em revisão quero poder isolar"*): isola `under_review`, que desde
        // a quick 261010-nke só aparecia diluído dentro de `acionaveis`. A
        // lista passa a ter 6 valores; `acionaveis` SEGUE sendo o default e
        // segue cobrindo `active` + `paused` + `under_review`. O rótulo na tela
        // é "Em revisão" — `under_review` nunca aparece para o usuário.
        //
        // ─── Emenda de 2026-10-13 (Fase 180, plano 04) ────────────────────
        // A lista passa a ter SETE valores: `com_erro` entrou (LIST-05). Ele é
        // o único que NÃO é um valor de `ml_acervo_itens.status` — é o eixo
        // `sub_status` do Mercado Livre, de lista fechada em
        // `AcervoTriagemService::SUB_STATUS_COM_ERRO`, aplicado por fora do
        // `match` daquele serviço. ⚠️ Sem este 7º valor a aba nova NUNCA chega
        // ao serviço: a lista COAGE o resto para `acionaveis`, então
        // `?status=com_erro` viraria `acionaveis` em silêncio. O rótulo
        // "Com erro" é da tela; aqui e no payload circula a CHAVE.
        // `acionaveis` segue sendo o default.
        $statusFiltro = $request->query('status', 'acionaveis');
        if (! in_array($statusFiltro, ['acionaveis', 'ativos', 'pausados', 'em_revisao', 'encerrados', 'todos', AcervoTriagemService::STATUS_COM_ERRO], true)) {
            $statusFiltro = 'acionaveis';
        }

        // Motivo é validado contra a whitelist fechada de MlAcervoItem::MOTIVO_*
        // antes de qualquer uso — nunca aceito de forma livre.
        $motivosDef     = $acervoTriagem->motivosDef();
        $motivosValidos = array_column($motivosDef, 'chave');
        $motivo         = $request->query('motivo');
        $motivo         = in_array($motivo, $motivosValidos, true) ? $motivo : null;

        // Decisão A2 do UI-SPEC: Publicados é a sub-aba padrão. A listagem de
        // Rascunhos em si é escopo do 134-09 — aqui só o contador da sub-aba.
        $sub = $request->query('sub', 'publicados');
        if (! in_array($sub, ['publicados', 'rascunhos'], true)) {
            $sub = 'publicados';
        }

        // Fase 173-02: filtro novo, opcional — o indicador "Com venda" da
        // Visão geral linka para aqui. NÃO entra no escopo de triagem/defasagem
        // abaixo: aqueles continuam mostrando o universo completo do status
        // filtrado, senão os chips ficariam errados com comVenda=1 ativo.
        $comVenda = $request->boolean('comVenda');

        // ═══ Fase 180, plano 04 — os 7 parâmetros NOVOS da URL (LIST-19) ═════
        //
        // LIST-19 exige que recarregar a página devolva o MESMO estado, então
        // todo filtro vive na querystring e volta na prop `filtros`. A regra de
        // saneamento é a que `status`/`motivo`/`sub` já seguiam e que agora vale
        // para os sete: **lista FECHADA ou cast**, valor fora dela cai no
        // default, e NADA é interpolado em SQL (T-180-04-T).
        //
        // ⚠️ Nenhum deles usa `(string) $request->query(...)`: array na
        // querystring (`?tipo[]=x`) viraria `ErrorException` via
        // `HandleExceptions` — 500 de graça. `in_array` com `true` estrito
        // recebe array sem reclamar e devolve `false`, que é o default.
        //
        // ⚠️ `catalogo` vem de `$request->boolean()`, NUNCA de truthiness: a
        // STRING `'false'` que uma querystring entrega é truthy em PHP, e
        // `AcervoTriagemService::filtros()` compara com `=== true` justamente
        // para que o filtro não ligue ao contrário do que o usuário pediu.

        // `fase` = a fase do Publicador em que a publicação nasceu (1 base, 2
        // kit). Chega como string na querystring; lista fechada de duas.
        $faseBruta = $request->query('fase');
        $fase      = in_array($faseBruta, ['1', '2', 1, 2], true) ? (int) $faseBruta : null;

        // `tipo` = Clássico/Premium. As duas chaves são as PÚBLICAS do serviço,
        // que também é quem conhece o `listing_type_id` real de cada uma — aqui
        // nunca aparece `gold_special`/`gold_pro` como entrada do usuário.
        $tipoBruto = $request->query('tipo');
        $tipo      = in_array($tipoBruto, [AcervoTriagemService::TIPO_CLASSICO, AcervoTriagemService::TIPO_PREMIUM], true)
            ? $tipoBruto
            : null;

        $catalogo = $request->boolean('catalogo');

        // `responsavel` = ids de USUÁRIO DA EQUIPE. ⚠️ Array de querystring
        // chega de todo jeito: escalar (`?responsavel=7`), array associativo
        // (`?responsavel[a]=7`), com nulo dentro, com string não numérica. O
        // saneamento é FECHADO — só `int > 0` ou string decimal sem sinal,
        // espaço, ponto nem expoente sobrevive. `'; DROP'`, `'-1'`, `'0'`,
        // `'1.5'`, `'1e3'`, array aninhado e `null` caem fora.
        $responsavelBruto = $request->query('responsavel', []);
        if (! is_array($responsavelBruto)) {
            $responsavelBruto = [$responsavelBruto];
        }
        $responsavel = [];
        foreach ($responsavelBruto as $bruto) {
            if (is_int($bruto) && $bruto > 0) {
                $responsavel[] = $bruto;
            } elseif (is_string($bruto) && preg_match('/^[1-9]\d{0,17}$/', $bruto) === 1) {
                $responsavel[] = (int) $bruto;
            }
        }
        $responsavel = array_values(array_unique($responsavel));

        // `periodo` = quando a publicação foi CONCLUÍDA. ⚠️ Não sai do acervo:
        // `ml_acervo_itens` NÃO tem coluna de data de publicação (medido no
        // plano 180-03 — as únicas datas são `coletado_em`,
        // `detalhe_coletado_em` e os `timestamps`, e `created_at` é a data em
        // que a COLETA criou a linha). A data real é
        // `pub_publicacoes.concluida_em`, e por isso este filtro entra pelo
        // mesmo caminho de `fase` e `responsavel`: `ml_item_ids`.
        $periodoBruto = $request->query('periodo');
        $periodo      = in_array($periodoBruto, ['hoje', '7d', '30d'], true) ? $periodoBruto : null;

        // `visao` = Por produto (default, LIST-09) | Lista.
        $visaoBruta = $request->query('visao', 'grupo');
        $visao      = in_array($visaoBruta, ['grupo', 'lista'], true) ? $visaoBruta : 'grupo';

        // `sel` = a publicação selecionada. ⚠️ Validado pela FORMA (`MLB` +
        // dígitos) e NÃO lido do banco: nesta fase a tela só precisa saber qual
        // linha pintar. Ler o item para confirmar que existe custaria uma
        // consulta a cada request, e quem vai ler de propósito é o painel de
        // inspeção da Fase 181 — que usa este MESMO parâmetro.
        $selBruto = $request->query('sel');
        $sel      = is_string($selBruto) && preg_match('/^MLB\d+$/', $selBruto) === 1 ? $selBruto : null;

        // ─── Fase, Responsável e Período → `ml_item_ids` ──────────────────────
        //
        // Os três vivem no PUBLICADOR (`pub_produtos.fase`,
        // `pub_publicacoes.ator`, `pub_publicacoes.concluida_em`), não no
        // acervo, e por isso são resolvidos para uma lista de `ml_item_id` que o
        // `filtros()` aplica com um `whereIn`. Os três compõem por INTERSEÇÃO —
        // dois ativos ao mesmo tempo é E, não OU — porque são o MESMO `WHERE` de
        // uma consulta só.
        //
        // ⚠️⚠️ `null` É "SEM FILTRO"; `[]` É "O FILTRO CASOU COM ZERO". Colapsar
        // os dois mostraria o acervo INTEIRO justamente quando o recorte não
        // achou nada — o pior tipo de mentira de tela. `whereIn(col, [])` gera
        // `0 = 1`, que é exatamente a verdade.
        $idsPorMetodologia = $this->idsPorMetodologia($company, $fase, $responsavel, $periodo);

        // Os filtros que vivem no acervo, numa estrutura só — a MESMA passada
        // para a listagem e para as contagens das abas. `com_vendas` é o
        // predicado `sold_quantity > 0` roteado pela definição ÚNICA de
        // `AcervoTriagemService::filtros()` (Fase 173-02 o tinha escrito inline
        // aqui; deixar os dois é deixar a regra escrita duas vezes).
        $filtrosDoAcervo = [
            'tipo'         => $tipo,
            'catalogo'     => $catalogo,
            'com_vendas'   => $comVenda,
            'ml_item_ids'  => $idsPorMetodologia,
        ];

        // ⚠️ A triagem recebe os MESMOS filtros MENOS `com_vendas`, e isso é
        // deliberado: a decisão da Fase 173-02 (comentário acima, e o teste
        // `MeusAnunciosTest::com_venda_filtra_a_listagem_sem_mudar_a_triagem`)
        // é que os chips mostram o universo completo do status filtrado quando
        // `comVenda=1` está ligado. Os filtros NOVOS entram porque sem eles os
        // chips contariam um universo maior que a lista — que é o defeito que o
        // plano 180-03 consertou ao dar `$filtros` e `$lista` à `triagem()`.
        $filtrosDaTriagem = $filtrosDoAcervo;
        unset($filtrosDaTriagem['com_vendas']);

        // ─── Listagem — ordenação por gravidade (D-12), determinística, sem
        // nenhum dado da camada cara: 3 níveis de desempate + tie-break estável. ───
        // ⚠️ O 4º argumento (`$publicacoes`) é o que liga a BUSCA POR NOME DE
        // PRODUTO (LIST-03, plano 180-03): sem ele o braço do produto nunca é
        // montado e o trabalho daquele plano fica inacessível pela tela.
        // ⚠️ O `com_vendas` saiu do `when()` inline e virou chave de
        // `$filtrosDoAcervo` — fonte única.
        $anuncios = $this->comMotivo(
            $acervoTriagem->filtros(
                $acervoTriagem->escopo($company, $busca, $statusFiltro, $publicacoes),
                $filtrosDoAcervo,
            ),
            $motivo,
        )
            ->orderByDesc('severidade')
            ->orderByRaw('nota_ecf IS NULL ASC') // não avaliado vai para o fim, não para o topo (D-12/D-18)
            ->orderBy('nota_ecf')
            ->orderBy('ml_item_id') // tie-break estável entre requests
            ->paginate(50)
            ->withQueryString();

        // ═══ A metodologia da linha (LIST-11/LIST-13/LIST-20) ════════════════
        //
        // De onde o anúncio veio: produto, fase, kit, autor e quando foi
        // publicado. ⚠️ Resolvido AQUI, UMA vez, com os ≤50 `ml_item_id` da
        // página — e o mapa é fechado dentro do `through()` abaixo. Chamar o
        // serviço DENTRO do `through()` seria um N+1 de 50 consultas por
        // request, e há gate que trava o número de consultas justamente para
        // que mover esta linha para lá reprove.
        //
        // ⚠️ `ml_item_id` AUSENTE do mapa é a resposta para "Legado": o anúncio
        // não nasceu neste módulo. As chaves vão `null` e quem desenha a palavra
        // é a tela — a mesma disciplina do `null` × `0` da Fase 179, e evita que
        // o rótulo exista em dois lugares.
        $idsDaPagina = $anuncios->getCollection()
            ->pluck('ml_item_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $metodologia = $publicacoes->metodologiaPorMlItemId($company, $idsDaPagina);

        $anuncios->through(fn (MlAcervoItem $item) => [
            'ml_item_id'          => $item->ml_item_id,
            'titulo'              => (string) $item->title,
            'thumbnail'           => $item->thumbnail,
            'permalink'           => $item->permalink,
            'origem'              => $item->origem,
            'rascunho_id'         => $item->rascunho_id,
            'listing_tier'        => $item->listing_type_id,
            // Quick 261010-rie: os SKUs do anúncio (pai + variações). Array
            // SEMPRE, nunca null — a linha da tabela só precisa mostrar, e o
            // aviso de "SKU ainda não coletado" é a prop `skuNaoColetado`
            // abaixo, calculada uma vez para a empresa inteira.
            'skus'                => $item->skus ?? [],
            'status'              => $item->status,
            'estoque'             => $item->available_quantity,
            'vendas'              => $item->sold_quantity,
            'visitas'             => $item->visitas_30d,
            'visitas_dias'        => $item->detalhe_coletado_em !== null
                ? $item->detalhe_coletado_em->diffInDays(now())
                : null,
            'buybox_status'       => $item->buybox_status,
            'buybox_nao_avaliado' => $item->naoAvaliadoBuyBox(),
            'nota_ecf'            => $item->nota_ecf,
            'nota_base'           => AnuncioSaudeService::BASE, // literal — "X de 86" (D-22), nunca renormalizada
            'motivos'             => $item->motivos ?? [],
            'severidade'          => $item->severidade,
            // D-21 (emenda 2026-08-10, veredicto DISPONÍVEL — Variante A do
            // UI-SPEC): duas medidas de saúde do próprio ML, em escalas
            // PRÓPRIAS que a tela nunca converte uma na outra nem preenche
            // uma a partir da outra. `health_ml` é a camada barata (0.00–1.00,
            // vem de graça no multiget); `performance_score` é a camada cara
            // rotativa (0–100, D-23). `saudeMlNaoSeAplica()` distingue "não se
            // aplica" (catálogo/encerrado) de "ainda não avaliado" (rotação
            // não chegou lá) — os dois são estados diferentes e a tela precisa
            // dizer coisas diferentes.
            'health_ml'              => $item->health_ml !== null ? round((float) $item->health_ml, 2) : null,
            'performance_score'      => $item->performance_score,
            'performance_level'      => $item->performance_level,
            'performance_acoes'      => $item->performance_acoes ?? [], // title já redigido em pt-BR pelo ML — nunca reescrito
            'saude_ml_nao_se_aplica' => $item->saudeMlNaoSeAplica(),

            // ─── Fase 180, plano 04: chaves ADITIVAS, nenhuma removida ───────
            //
            // ⚠️ `health_ml`, `performance_*`, `buybox_*`, `motivos` e
            // `severidade` acima CONTINUAM: LIST-24 diz que eles MIGRAM para o
            // painel de inspeção (Fase 181) e para os chips, não que são
            // apagados. Remover a chave agora quebraria o `ModalDetalheAnuncio`
            // e os testes de `MeusAnunciosTest`.
            //
            // ⚠️⚠️ `margem`, `lucro`, `repasse` e `tarifa` NÃO EXISTEM AQUI, e
            // isso é LIST-12, não esquecimento: a tarifa do Mercado Livre não
            // está no banco. A margem é carregada de forma ASSÍNCRONA depois da
            // lista, pelo endpoint que já existe em produção
            // (`MlbAlavancasController::analise` + `AnaliseAlavancasService`,
            // ligado na Fase 181), e até ela chegar a célula "Preço" mostra
            // **"—"**. ⚠️ NUNCA calcular margem aqui, nunca mandar `0`, nunca
            // estimar a tarifa por percentual: zero é um número, e "não
            // medimos" não é zero (EXMP-01). Há asserção NEGATIVA, com o item
            // preenchido, provando que estas quatro chaves não existem.
            'produto_id'     => $metodologia[(string) $item->ml_item_id]['produto_id'] ?? null,
            'produto_nome'   => $metodologia[(string) $item->ml_item_id]['produto_nome'] ?? null,
            'produto_sku'    => $metodologia[(string) $item->ml_item_id]['produto_sku'] ?? null,
            'fase'           => $metodologia[(string) $item->ml_item_id]['fase'] ?? null,
            'quantidade_kit' => $metodologia[(string) $item->ml_item_id]['quantidade_kit'] ?? null,
            'autor_nome'     => $metodologia[(string) $item->ml_item_id]['autor_nome'] ?? null,
            'autor_tipo'     => $metodologia[(string) $item->ml_item_id]['autor_tipo'] ?? null,
            'publicada_em'   => $metodologia[(string) $item->ml_item_id]['publicada_em'] ?? null,

            // Clássico/Premium do `listing_type_id` real. ⚠️ Qualquer outro
            // valor (`free`, `silver`, `bronze`, nulo) é `null` — LIST-20: nada
            // inferido do título, e nada caindo no Clássico "porque é o mais
            // comum". `listing_tier` acima continua mandando o valor CRU, para
            // quem precisa dele.
            'tipo'           => is_string($item->listing_type_id)
                ? (self::TIPO_POR_LISTING_TYPE[$item->listing_type_id] ?? null)
                : null,
            'catalogo'       => (bool) $item->catalog_listing,

            // ─── Fase 180, plano 04B: as TRÊS chaves que faltavam ────────────
            //
            // O plano 180-08 construiu a tabela e MEDIU (§6 da SUMMARY dele)
            // que três props não chegavam daqui, deixando três requisitos
            // inalcançáveis. As três linhas abaixo são ADITIVAS e **não custam
            // consulta nenhuma**: `price` e `sub_status` vêm da MESMA linha do
            // acervo que a página já carregou (`AcervoTriagemService::escopo()`
            // não restringe colunas) e `publicada_em` já está no mapa de
            // metodologia resolvido acima, fora do `through()`.

            // ⚠️ O PREÇO (LIST-12). Sem ele a célula "Preço" mostrava `—` no
            // valor E na margem, em TODAS as linhas.
            //
            // ⚠️ Chega como STRING (`"399.90"`): o cast é `'decimal:2'`
            // (`MlAcervoItem.php:101`) e o Eloquent serializa decimal em JSON
            // como string. **Isso é de propósito e está medido:**
            // `CelulasDaLinha.jsx:132`-`:143` (`numeroMedido`) aceita string
            // numérica de forma FECHADA, com `RE_NUMERO` (`:117`). Não
            // converter para `float` aqui — a forma já foi medida como aceita e
            // o cast preserva as 2 casas do banco.
            //
            // ⚠️ `price` nulo continua NULO, não `"0.00"`: `'decimal'` está em
            // `HasAttributes::$primitiveCastTypes`. "Nunca medimos" ≠ "medimos
            // e é zero" (EXMP-01) — a tela mostra `—` no primeiro caso e
            // `R$ 0,00` no segundo.
            //
            // ⚠️⚠️ `margem`, `lucro`, `repasse` e `tarifa` CONTINUAM NÃO
            // EXISTINDO (ver o bloco acima). Este plano entrega a 1ª linha da
            // célula (o valor); a 2ª ("Margem x%" / "▲ Prejuízo") é a camada
            // ASSÍNCRONA da Fase 181 e segue em `—`. NUNCA estimar a tarifa por
            // percentual para preencher a lacuna.
            'preco'          => $item->price,

            // ⚠️ O BADGE "NOVA" e o fundo de linha `#14120A` (LIST-11).
            //
            // A RÉGUA FOI MEDIDA NO PROTÓTIPO, não escolhida:
            //  · `isNew` é dado da PUBLICAÇÃO, hardcoded item por item em
            //    `PUBS` (`Publicacoes v2.dc.html:433`-`:434`) — e NÃO um estado
            //    de sessão (a faixa de sucesso é a prop `showSuccess`, `:506`);
            //  · os ÚNICOS dois itens com `isNew: true` são exatamente os dois
            //    com `d: '2026-10-10T…'`, e o "hoje" do protótipo é o literal
            //    `new Date('2026-10-10T00:00')` de `:491`. Dos 24 itens do mock,
            //    2 caem nesse dia e são os 2 marcados: zero falso positivo,
            //    zero falso negativo;
            //  · a mesma régua já é a de `days(d) === 0`, que escreve
            //    "Hoje, hh:mm" (`:498`) e move o filtro `period: 'today'`
            //    (`:523`).
            //
            // ➡️ "NOVA" = publicada no DIA CIVIL de hoje. O fuso é DECLARADO e
            // não herdado: `config/app.php:68` dá `America/Sao_Paulo`, o mesmo
            // de `hojeEmSaoPaulo()` na tela e o mesmo do `periodo=hoje` que
            // `idsPorMetodologia()` já usa (`now()->startOfDay()`).
            //
            // ⚠️ `ml_acervo_itens.created_at` foi CONSIDERADO E RECUSADO: ele é
            // quando a COLETA viu o anúncio, não quando ele foi publicado — a
            // tela chamaria de "nova" uma publicação antiga recém-coletada.
            // Há asserção negativa para essa régua.
            //
            // ⚠️ A fonte é a MESMA string que a coluna "Publicada" mostra
            // (`publicada_em`), para que a linha não possa dizer "NOVA" e a
            // data dizer outro dia. Legado (sem `publicada_em`) é `false`:
            // ausência de dado não é novidade.
            //
            // ⚠️ Isto NÃO é a faixa de sucesso. `faixaSucesso` segue `null`
            // SEMPRE (plano 04 §2: não existe chave de flash de publicação e a
            // publicação termina assíncrona) — são dois eixos diferentes.
            'nova'           => $this->publicadaHoje(
                $metodologia[(string) $item->ml_item_id]['publicada_em'] ?? null,
            ),

            // ⚠️ "Com erro" NA LINHA (LIST-10), com a MESMA lista fechada da ABA.
            //
            // `com_erro` é a CHAVE (`AcervoTriagemService::STATUS_COM_ERRO`), a
            // mesma que o `?status=` e a aba já usam — nunca o rótulo, que mora
            // na tela.
            //
            // ⚠️ O que sai daqui é um BOOL derivado, e não o `sub_status` cru:
            //  (a) `sub_status` é cast `'array'` (`MlAcervoItem.php:86`) e campo
            //      objeto/array novo no item é a herança da tela preta de
            //      07/10/2026;
            //  (b) a lista fechada tem de viver em UM lugar — mandar o eixo cru
            //      empurraria a régua para o JS e a LINHA poderia discordar da
            //      ABA, que é o defeito que este plano existe para impedir.
            //
            // ⚠️ PROIBIDO derivar de `severidade` ou de `motivos` (docblock de
            // `SUB_STATUS_COM_ERRO`, `AcervoTriagemService.php:67`-`:72`): um
            // anúncio moderado pode ter `severidade = 0`, e "Pausado"/"Sem
            // estoque" é outra coisa. Há caso negativo para as duas tentações.
            'com_erro'       => $this->temSubStatusComErro($item),
        ]);

        // ─── Triagem (D-09) e defasagem (D-08) — extraídas para
        // AcervoTriagemService (Fase 173-02): fonte única também usada pela
        // Visão geral, mesmos números de antes. ───
        // ⚠️ `$filtrosDaTriagem` e `$publicacoes` (plano 180-03): sem eles a tela
        // mostraria uma linha achada pelo nome do produto e os chips diriam 0.
        $triagem   = $acervoTriagem->triagem($company, $busca, $statusFiltro, $filtrosDaTriagem, $publicacoes);
        $defasagem = $acervoTriagem->defasagem($company);

        // ═══ As contagens das 7 abas de status (LIST-05) ══════════════════════
        //
        // "Calculadas sobre os DEMAIS filtros": o eixo de status é o que cada
        // aba representa, então ele NÃO entra; busca, motivo, tipo, catálogo,
        // vendas e `ml_item_ids` entram. É o que faz a aba "Ativas" dizer
        // quantas ativas existem DENTRO do recorte atual, e não quantas existem
        // na conta inteira.
        //
        // ⚠️ UMA consulta agregada, nunca 7 `count()` — a mesma disciplina de
        // `CockpitDaContaService::resumoDePublicacoes()` ("nunca quatro
        // `count()`"). E aqui o ganho é maior que 7 contra 1: com busca ativa,
        // cada chamada a `escopo()` resolveria DE NOVO os `ml_item_id` do
        // produto, então 7 abas custariam 14 consultas em vez de 2.
        //
        // ⚠️ A soma de `acionaveis` e o nome das colunas de `status` repetem o
        // `match` de `AcervoTriagemService::escopo()`. A duplicação é guardada
        // por um gate que compara, para TODAS as 7 chaves, este agregado com o
        // `filtros(escopo(…, <chave>), …)->count()` do próprio serviço — se o
        // `match` mudar e esta soma não, o teste reprova.
        $porStatus = $this->comMotivo(
            $acervoTriagem->filtros(
                $acervoTriagem->escopo($company, $busca, 'todos', $publicacoes),
                $filtrosDoAcervo,
            ),
            $motivo,
        )
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $somaDeStatus = static fn (array $colunas) => (int) array_sum(
            array_map(static fn (string $coluna) => (int) ($porStatus[$coluna] ?? 0), $colunas),
        );

        // `com_erro` é consulta PRÓPRIA porque não é um valor de `status`: é o
        // eixo `sub_status`, e o serviço é a fonte fechada dele.
        $totalComErro = $this->comMotivo(
            $acervoTriagem->filtros(
                $acervoTriagem->escopo($company, $busca, AcervoTriagemService::STATUS_COM_ERRO, $publicacoes),
                $filtrosDoAcervo,
            ),
            $motivo,
        )->count();

        $totalDeRascunhos = MlAnuncioRascunho::where('company_id', $company->id)->count();

        $contagens = [
            // `todos` é a soma de TUDO que o agregado trouxe, inclusive status
            // que nenhuma aba nomeia (`inactive`, por exemplo) — é o mesmo
            // universo que `escopo(…, 'todos')`, que não filtra status.
            'todos'      => (int) $porStatus->sum(),
            'acionaveis' => $somaDeStatus(['active', 'paused', 'under_review']),
            'ativos'     => $somaDeStatus(['active']),
            'pausados'   => $somaDeStatus(['paused']),
            'em_revisao' => $somaDeStatus(['under_review']),
            'encerrados' => $somaDeStatus(['closed']),
            'com_erro'   => (int) $totalComErro,
            'rascunhos'  => $totalDeRascunhos,
        ];

        // As SEIS abas que a tela desenha, NA ORDEM (plano 180-06). ⚠️
        // `encerrados` e `acionaveis` NÃO têm aba no layout novo, mas continuam
        // valores válidos de `status` e continuam com contagem em `$contagens` —
        // são os destinos que a Visão Geral da Fase 179 monta
        // (`CockpitDaContaService::resumoDePublicacoes()`), e `acionaveis` é o
        // DEFAULT da tela.
        //
        // ⚠️ `comDot` em camelCase, e isso é de propósito: é o nome LITERAL que
        // `AbasDeStatus.jsx` consome (`180-06-PLAN.md:94`). Mandar `com_dot`
        // faria o componente ler `undefined` e NUNCA desenhar o ponto vermelho,
        // em silêncio. O dot é calculado AQUI (`com_erro > 0`) porque o
        // componente não recalcula — a regra mora num lugar só.
        $abas = [
            ['chave' => 'todos',                               'rotulo' => 'Todas',      'total' => $contagens['todos'],      'comDot' => false],
            ['chave' => 'ativos',                              'rotulo' => 'Ativas',     'total' => $contagens['ativos'],     'comDot' => false],
            ['chave' => 'pausados',                            'rotulo' => 'Pausadas',   'total' => $contagens['pausados'],   'comDot' => false],
            ['chave' => AcervoTriagemService::STATUS_COM_ERRO, 'rotulo' => 'Com erro',   'total' => $contagens['com_erro'],   'comDot' => $contagens['com_erro'] > 0],
            // ⚠️ Fase 186, plano 01 (ABA-03 / VOC-01) — o rótulo desta aba passou
            // de "Em revisão" para "Em análise pelo ML", medido no protótipo
            // ("Publicacoes v2.dc.html":534, `tabsDef`). É a versão CURTA de
            // propósito: a aba é estreita, e o selo da linha é que traz o nome
            // inteiro ("Em análise pelo Mercado Livre").
            //
            // POR QUÊ: "Em revisão" é substring de "Em revisão pela ECF", o
            // estado INTERNO da aprovação que esta milestone introduz (VOC-02);
            // com os dois na tela ninguém sabe de quem é a revisão.
            //
            // ⚠️ A CHAVE `em_revisao` NÃO MUDOU (TERR-08): `?status=em_revisao`
            // na URL, filtro salvo e a validação de `escopo()` dependem dela.
            // Só o rótulo mudou.
            ['chave' => 'em_revisao',                          'rotulo' => 'Em análise pelo ML', 'total' => $contagens['em_revisao'], 'comDot' => false],
            // LIST-25: "Rascunhos" entrou no MESMO trilho das abas de status. O
            // dado e a query continuam os de hoje (`sub`/`subTotais`, D-14) — o
            // que mudou é o lugar do atalho.
            ['chave' => 'rascunhos',                           'rotulo' => 'Rascunhos',  'total' => $contagens['rascunhos'],  'comDot' => false],
        ];

        // ═══ A visão "Por produto" (LIST-09/LIST-16) ══════════════════════════
        //
        // ⚠️ Os grupos saem do MESMO conjunto PAGINADO, nunca de outra consulta:
        // se viessem de um recorte diferente, o rodapé ("N de M publicações")
        // mentiria sobre o que a tela está mostrando.
        //
        // ⚠️ `cobertura` e `total_no_produto` vêm de `coberturaPorProduto()`, que
        // conta TODAS as publicações do produto (não só as da página) — é isso
        // que produz o "x de N" quando há filtro.
        $linhasDaPagina = $anuncios->getCollection();

        $produtoIdsDaPagina = $linhasDaPagina
            ->pluck('produto_id')
            ->filter(fn ($id) => is_int($id))
            ->unique()
            ->values()
            ->all();

        // UMA consulta para todos os produtos da página. Lista vazia não
        // consulta (o serviço devolve `[]` sem tocar o banco).
        $cobertura = $publicacoes->coberturaPorProduto($company, $produtoIdsDaPagina);

        $grupos = $visao === 'grupo'
            ? $this->gruposPorProduto($linhasDaPagina, $cobertura, $alvo)
            : []; // visão "Lista": a tela usa `anuncios.data` direto

        // ─── O rodapé (LIST-16) ──────────────────────────────────────────────
        //
        // ⚠️ `total` é o do paginator — a contagem que o `paginate(50)` JÁ fez,
        // de graça. Contar de novo seria pagar duas vezes pelo mesmo número.
        // ⚠️ `produtos` é a contagem DISTINTA de `produto_id` da página; as
        // publicações sem produto (Legado) não entram, porque não há produto que
        // contar. O grupo delas existe e aparece na tela do mesmo jeito.
        $rodape = [
            'visiveis' => $anuncios->count(),
            'total'    => $anuncios->total(),
            'produtos' => count($produtoIdsDaPagina),
        ];

        // ─── "● Sincronizado há X" do cabeçalho (LIST-01) ────────────────────
        //
        // ⚠️ Derivado do `$defasagem` que esta action JÁ calculou — repetir a
        // consulta seria pagar duas vezes pelo mesmo número, exatamente o que o
        // comentário da prop `cabecalho` avisa.
        //
        // ⚠️ O `rotulo` chega PRONTO em pt-BR porque o componente não monta data:
        // `CabecalhoDePublicacoes.jsx` não tem `new Date()` nenhum, de propósito
        // (`Invalid Date` e o deslocamento de fuso de data-only em BRT já
        // morderam este projeto), e ele RECUSA rótulo com cara de data crua.
        //
        // ⚠️ "nunca medimos" ≠ "medimos e é zero": sem coleta o `rotulo` é
        // `null`, `nunca_coletado` é `true`, e o bloco inteiro desaparece da
        // tela — nunca aparece "Sincronizado há —". Quem resolve é o botão
        // "Atualizar", que continua.
        $sincronizacao = [
            'coletado_em'    => $defasagem['coletado_em'],
            'horas'          => $defasagem['horas'],
            'defasado'       => $defasagem['defasado'],
            'nunca_coletado' => $defasagem['nunca_coletado'],
            'rotulo'         => $this->rotuloDeSincronizacao($defasagem),
        ];

        // ─── A faixa verde de sucesso (LIST-02) ──────────────────────────────
        //
        // ⚠️⚠️ `null` SEMPRE, e isso é MEDIDO, não preguiça. Três razões
        // independentes, e qualquer uma basta:
        //   1. ninguém grava um resumo de publicação em sessão — zero
        //      `session()->put(`/`->flash(` de publicação em `app/`, inclusive em
        //      `PublicacaoService` e `PublicarRascunhoJob`;
        //   2. mesmo que gravasse, a chave morreria no caminho: a lista BRANCA de
        //      `flash` de `HandleInertiaRequests` (`:64-98`) tem 10 chaves e
        //      nenhuma de publicação — defeito que já aconteceu duas vezes neste
        //      projeto, documentado nos comentários de `nps_link_existente` e
        //      `portal_codigo_enviado`;
        //   3. a publicação é ASSÍNCRONA (`MlbPublicadorController::publicar()`
        //      devolve 202 e enfileira), então quando o usuário volta para a
        //      lista o job pode nem ter rodado — "N publicações criadas agora"
        //      não é um fato disponível no retorno.
        //
        // ⚠️ O que isso NÃO autoriza é fabricar o resumo. O protótipo traz
        // "2 publicações criadas agora · Poltrona Beny" e aquilo é dado MOCK
        // (EXMP-01). O componente devolve `null` e a faixa não aparece.
        //
        // O caminho nomeado para quem for ligar a fonte (plano futuro, não este):
        // `pub_publicacoes.concluida_em` + `pub_publicacao_itens` já têm tudo;
        // falta (a) uma chave de flash NOVA declarada no `HandleInertiaRequests`
        // e (b) decidir o que a tela mostra quando a fila ainda não concluiu —
        // que é decisão de produto, não de código.
        $faixaSucesso = null;

        // ─── RIE-04 (quick 261010-rie): o vazio da busca explica, não mente ──
        //
        // POR QUE EXISTE: a busca passou a cobrir SKU, mas a coluna `skus` só é
        // preenchida pela varredura diária (D-RIE-04, sem backfill). Na janela
        // em que a empresa ainda não foi varrida, buscar por SKU devolveria
        // "não achei" — como se o anúncio não existisse. É mentira por omissão.
        // Com esta prop a tela diz que o SKU ainda não foi coletado e oferece
        // o "Atualizar agora", que já existe e cobre a empresa na hora.
        //
        // POR QUE É BARATA: `$anuncios->total()` vem do `count` que o
        // `paginate()` já fez (de graça), e o `exists()` só roda quando a busca
        // não achou NADA — nunca no caminho normal da tela.
        $skuNaoColetado = $busca !== '' && $anuncios->total() === 0
            && ! MlAcervoItem::where('company_id', $company->id)->whereNotNull('skus')->exists();

        // Fase 134 Plano 09: sub-aba Rascunhos — a tela oficial de rascunhos, com
        // TODOS os registros da empresa (não só os 50 mais recentes do wizard).
        // Sem `payload`: é o campo mais pesado do registro e esta listagem só
        // precisa mostrar; quem precisa do payload completo é o wizard, que
        // carrega o rascunho por conta própria ao abrir (T-134-23).
        // Publicados não paga por este dado — array vazio.
        $rascunhosProp = $sub === 'rascunhos'
            ? MlAnuncioRascunho::where('company_id', $company->id)
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn ($r) => [
                    'id'           => $r->id,
                    'status'       => $r->status,
                    'titulo'       => (string) data_get($r->payload, 'title', ''),
                    'categoria'    => $this->nomeCategoria($r->category_id),
                    'category_id'  => $r->category_id,
                    'listing_tier' => $r->listing_tier,
                    'foto'         => data_get($r->payload, 'pictures.0.source'),
                    'updated_at'   => $r->updated_at,
                    'erro_resumo'  => $this->resumoErro($r->validation_errors),
                    'ml_item_id'   => $r->ml_item_id,
                ])
            : [];

        return Inertia::render('Mlb/MeusAnuncios', [
            'empresa'   => ['id' => $company->id, 'nome' => $company->name],
            // Fase 172-02: prop nova — ver comentário em wizard().
            'conta'     => $conta,
            // Fase 177-03: prop ADITIVA do header global. `$defasagem` vai como 4º argumento —
            // esta action JÁ o calculou acima, e repetir a consulta seria pagar duas vezes pelo
            // mesmo número. Pode ser `null` — ver massa().
            'cabecalho' => $alvo !== null ? $cabecalhos->para($alvo, $conta, 'publicacoes', $defasagem) : null,
            'sub'       => $sub,
            'subTotais' => [
                'publicados' => MlAcervoItem::where('company_id', $company->id)->count(),
                // ⚠️ Reaproveita o número que as abas já contaram — o mesmo
                // `count()` escrito duas vezes é uma consulta paga duas vezes.
                'rascunhos'  => $totalDeRascunhos,
            ],
            'anuncios'          => $anuncios,
            'rascunhos'         => $rascunhosProp,
            'triagem'           => $triagem,
            // ⚠️ ADITIVA: as 4 chaves de hoje (`busca`, `status`, `motivo`,
            // `com_venda`) ficam com o MESMO nome e o MESMO tipo — 22 testes de
            // `MeusAnunciosTest` as leem. As 7 novas entram ao lado, saneadas, e
            // é isso que faz recarregar a página devolver o mesmo estado
            // (LIST-19). `sub` continua em prop própria, como hoje; `page` é do
            // paginator, que já carrega tudo pelo `withQueryString()`.
            'filtros'           => [
                'busca'       => $busca,
                'status'      => $statusFiltro,
                'motivo'      => $motivo,
                'com_venda'   => $comVenda,
                'fase'        => $fase,
                'tipo'        => $tipo,
                'catalogo'    => $catalogo,
                'responsavel' => $responsavel,
                'periodo'     => $periodo,
                'visao'       => $visao,
                'sel'         => $sel,
            ],
            // Fase 180, plano 04 — props NOVAS, nenhuma substituição.
            'abas'              => $abas,
            'contagens'         => $contagens,
            'grupos'            => $grupos,
            'rodape'            => $rodape,
            'sincronizacao'     => $sincronizacao,
            'faixaSucesso'      => $faixaSucesso,
            'skuNaoColetado'    => $skuNaoColetado,
            'defasagem'         => $defasagem,
            'saudeMlDisponivel' => (bool) config('mlb_acervo.saude_ml_disponivel'),
            'rotacaoN'          => (int) config('mlb_acervo.rotacao_n'),
        ]);
    }

    /**
     * O filtro de MOTIVO da triagem (D-09), escrito UMA vez.
     *
     * A listagem e as contagens das abas aplicam o MESMO predicado; escrevê-lo
     * duas vezes no mesmo método é o caminho mais curto para as duas
     * divergirem na primeira correção feita de um lado só.
     *
     * ⚠️ `$motivo` já veio coagido contra a whitelist fechada de
     * `AcervoTriagemService::motivosDef()` em `meus()` — nunca chega aqui valor
     * livre de querystring. O `like '%"chave"%'` é o mesmo padrão sobre coluna
     * json que `triagem()` e `comMotivos()` usam.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\MlAcervoItem>  $q
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\MlAcervoItem>
     */
    private function comMotivo($q, ?string $motivo)
    {
        return $q->when($motivo !== null, fn ($b) => $b->where('motivos', 'like', '%"' . $motivo . '"%'));
    }

    /**
     * Fase, Responsável e Período → a lista de `ml_item_id` que o
     * `AcervoTriagemService::filtros()` aplica (LIST-07/LIST-19).
     *
     * Os três filtros NÃO vivem no acervo: a fase é `pub_produtos.fase`, o
     * responsável é `pub_publicacoes.ator` e a data de publicação é
     * `pub_publicacoes.concluida_em` — medido no plano 180-03 que
     * `ml_acervo_itens` não tem coluna de data de publicação. A ponte com o
     * acervo é `pub_publicacao_itens.ml_item_id`, a MESMA que
     * `PublicacoesDaListaService` e `historico()` percorrem (e **não** o
     * `ml_acervo_itens.rascunho_id`, que aponta para `ml_anuncio_rascunhos`, o
     * assistente ANTIGO — a divergência medida do LIST-20).
     *
     * ⚠️ UMA consulta para os três, e por isso eles compõem por INTERSEÇÃO: é o
     * mesmo `WHERE`. Dois ativos ao mesmo tempo é E, nunca OU.
     *
     * ⚠️⚠️ O retorno distingue três coisas:
     *   · `null` — nenhum dos três filtros está ativo: **não filtra nada**;
     *   · `[]`   — os filtros estão ativos e casaram com ZERO publicações:
     *              `whereIn(col, [])` gera `0 = 1` e a tela mostra zero linhas,
     *              que é a verdade;
     *   · lista  — os `ml_item_id` que sobreviveram.
     * Colapsar `[]` em `null` mostraria o acervo INTEIRO exatamente quando o
     * recorte não achou nada.
     *
     * ⚠️ Por que o responsável é resolvido em PHP e não em SQL: `ator` é uma
     * coluna json (`{"equipe":bool,"id":int,"nome":string,"conta":{…}}`) e um
     * `like '%"id":5%'` casaria também com `"id":55` — o `id` não é
     * necessariamente a última chave, então não há sufixo confiável. Classificar
     * em PHP é o padrão que `PainelVisaoGeralService::classificarAtor()` (`:151`)
     * já estabeleceu para esta mesma coluna, e é exato.
     *
     * ⚠️ T-180-04-I: o id de usuário ENTRA (é o que o filtro manda de volta na
     * URL) e NUNCA SAI daqui — o retorno é só `ml_item_id`, que é público (está
     * no permalink do ML). Ator de CLIENTE do Portal nunca casa, mesmo que o id
     * coincida: só `equipe === true` entra.
     *
     * @param  array<int,int>  $responsaveis  ids de usuário da equipe, já saneados
     * @return array<int,string>|null
     */
    private function idsPorMetodologia(Company $company, ?int $fase, array $responsaveis, ?string $periodo): ?array
    {
        if ($fase === null && $responsaveis === [] && $periodo === null) {
            return null;
        }

        $q = PubPublicacaoItem::query()
            ->join('pub_publicacoes', 'pub_publicacoes.id', '=', 'pub_publicacao_itens.publicacao_id')
            ->join('pub_rascunhos', 'pub_rascunhos.id', '=', 'pub_publicacoes.rascunho_id')
            ->join('pub_produtos', 'pub_produtos.id', '=', 'pub_rascunhos.produto_id')
            // Só o que de fato nasceu no ML — o mesmo filtro de `historico()` e
            // de `PublicacoesDaListaService::base()`.
            ->where('pub_publicacao_itens.status', PubPublicacaoItem::CREATED)
            ->whereNotNull('pub_publicacao_itens.ml_item_id')
            // O escopo por empresa, AGRUPADO. Um `orWhere` solto aqui subiria ao
            // topo do WHERE e o filtro passaria a casar publicação de outra
            // conta (T-134-01). A âncora vem por SUBCONSULTA porque
            // `mlb_empresas.company_id` não é unique e um `value('id')` deixaria
            // invisível o produto ancorado na segunda.
            ->where(function ($escopo) use ($company) {
                $escopo->where('pub_produtos.company_id', $company->id)
                    ->orWhereIn('pub_produtos.mlb_empresa_id', function ($ancoras) use ($company) {
                        $ancoras->select('id')->from('mlb_empresas')->where('company_id', $company->id);
                    });
            });

        if ($fase !== null) {
            $q->where('pub_produtos.fase', $fase);
        }

        if ($periodo !== null) {
            // Janela DESLIZANTE para 7d/30d (as últimas 168 h / 720 h) e o
            // começo do dia para "hoje". `match` sem `default` de propósito: o
            // valor vem de lista fechada, e alguém que a amplie sem ampliar
            // isto tem de ver o erro, não um filtro que silenciosamente não
            // filtra.
            $q->where('pub_publicacoes.concluida_em', '>=', match ($periodo) {
                'hoje' => now()->startOfDay(),
                '7d'   => now()->subDays(7),
                '30d'  => now()->subDays(30),
            });
        }

        $linhas = $q->select(['pub_publicacao_itens.ml_item_id', 'pub_publicacoes.ator'])->get();

        $ids = [];
        foreach ($linhas as $linha) {
            if ($responsaveis !== [] && ! $this->atorEhDaEquipe($linha->ator, $responsaveis)) {
                continue;
            }

            $mlItemId = (string) $linha->ml_item_id;
            if ($mlItemId !== '') {
                $ids[$mlItemId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * A publicação caiu no DIA CIVIL de hoje? (Fase 180, plano 04B — LIST-11.)
     *
     * É a régua do badge "NOVA" e do fundo de linha `#14120A`, MEDIDA no
     * protótipo (`Publicacoes v2.dc.html:433`/`:434`/`:491`) e não escolhida —
     * ver o comentário longo no item de `meus()`.
     *
     * ⚠️ A entrada é a MESMA string ISO-8601 que a tela mostra na coluna
     * "Publicada" (`PublicacoesDaListaService::metodologiaPorMlItemId` →
     * `isoOuNulo(concluida_em)`): derivar do mesmo valor é o que impede a linha
     * dizer "NOVA" e a data dizer outro dia.
     *
     * ⚠️ O fuso é o da APLICAÇÃO (`America/Sao_Paulo`, `config/app.php:68`), o
     * mesmo de `hojeEmSaoPaulo()` na tela — e a comparação é por dia CIVIL,
     * nunca por "24 horas atrás": `isSameDay()` compara `Y-m-d`, que é o
     * `days(d) === 0` do protótipo.
     *
     * ⚠️ Valor malformado devolve `false` em vez de derrubar a listagem inteira
     * (Rule 2): o único produtor é o nosso próprio serviço, mas uma exceção
     * dentro do `through()` custaria a PÁGINA, e "não sei" aqui é `false` —
     * o badge não acende, que é o lado seguro.
     */
    private function publicadaHoje(mixed $publicadaEm): bool
    {
        if (! is_string($publicadaEm) || $publicadaEm === '') {
            return false;
        }

        try {
            return Carbon::parse($publicadaEm)
                ->setTimezone(config('app.timezone'))
                ->isSameDay(now());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * O anúncio está no eixo "Com erro" do Mercado Livre? (Plano 04B — LIST-10.)
     *
     * ⚠️⚠️ **A LISTA FECHADA É A MESMA DA ABA**, por referência à constante
     * `AcervoTriagemService::SUB_STATUS_COM_ERRO` — nunca copiada. Se a LINHA e
     * a ABA tivessem duas cópias, elas divergiriam na primeira emenda, e dois
     * números que discordam na mesma tela já custaram uma decisão do usuário
     * nesta milestone. Há mutação cujo único efeito é trocar a lista desta
     * função por uma diferente, e ela TEM de reprovar.
     *
     * ⚠️ PROIBIDO derivar de `severidade` ou de `motivos` — ver o docblock da
     * constante (`AcervoTriagemService.php:67`-`:72`). Anúncio moderado pode
     * ter `severidade = 0`.
     *
     * ⚠️ EQUIVALÊNCIA COM O SQL DA ABA, e o limite: a aba faz
     * `orWhere('sub_status', 'like', '%"<valor>"%')` por valor da constante;
     * aqui é `array_intersect` sobre os valores do array. Para o json que o ML
     * emite (lista achatada de strings) os dois coincidem, inclusive na borda
     * (`%"warning"%` NÃO casa dentro de `"pre_warning"`, porque as aspas
     * ancoram os dois lados). **Se o `sub_status` chegasse como OBJETO com uma
     * chave chamada `warning`, o `LIKE` casaria e isto não** — o ML emite
     * array, e a divergência fica escrita em vez de escondida.
     */
    private function temSubStatusComErro(MlAcervoItem $item): bool
    {
        $subStatus = $item->sub_status;

        if (! is_array($subStatus)) {
            return false;
        }

        $valores = array_filter($subStatus, 'is_string');

        return array_intersect($valores, AcervoTriagemService::SUB_STATUS_COM_ERRO) !== [];
    }

    /**
     * A visão "Por produto" (LIST-09): as linhas da página agrupadas pelo
     * produto que as originou.
     *
     * ⚠️ **ORDEM DOS GRUPOS = ordem de APARIÇÃO na lista já ordenada por
     * gravidade (D-12).** É o que preserva o critério 13 (a ordenação por
     * gravidade continua valendo) E agrupa ao mesmo tempo. Não reordenar por
     * nome — o protótipo também não reordena (`order.push(p.prod)` na ordem em
     * que a linha aparece, `Publicacoes v2.dc.html:564`).
     *
     * ⚠️ **ORDEM DAS LINHAS DENTRO DO GRUPO**, conferida no protótipo
     * (`:566`): `fase DESC`, Premium antes de Clássico, depois data DESC.
     * ⚠️ DIVERGÊNCIA MEDIDA: no protótipo o terceiro termo é INALCANÇÁVEL por
     * construção — o comparador é
     * `b.ph - a.ph || (a.t === 'premium' ? -1 : 1) || b.d.localeCompare(a.d)`, e
     * o termo do meio devolve `-1` ou `1`, **nunca `0`**, então o desempate por
     * data nunca roda (e o comparador é inconsistente: para dois clássicos ele
     * devolve `1` nas duas direções). Replicar o defeito daria ordem instável
     * entre requests. Aqui o comparador é consistente e a INTENÇÃO do protótipo
     * é respeitada inteira: fase DESC → Premium antes → data DESC →
     * `ml_item_id` ASC como tie-break estável (a mesma disciplina do D-12).
     *
     * ⚠️ As publicações SEM produto (Legado) formam **um grupo próprio, no
     * FIM**, com `produto_id` nulo e o nome que DIZ o que é
     * (`self::GRUPO_SEM_PRODUTO`) — nunca um nome inventado, nunca "—"
     * (EXMP-01).
     *
     * ⚠️ `ver_produto_url` e `criar_combo_url` só existem quando há destino
     * REAL; `null` faz a tela não desenhar o link. **Nenhum botão sem destino**
     * (EXMP-01) — e nenhum `href="#"`.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $linhas  as linhas da página, já pelo `through()`
     * @param  array<int, array{f1_classico:bool, f1_premium:bool, f2_classico:bool, f2_premium:bool, total:int}>  $cobertura
     * @param  array<string, mixed>|null  $alvo  o alvo do `ProgramasPublicadorService` (dá a chave da conta)
     * @return array<int, array<string, mixed>>
     */
    private function gruposPorProduto($linhas, array $cobertura, ?array $alvo): array
    {
        $porProduto = [];
        $legado     = [];

        foreach ($linhas as $linha) {
            $produtoId = $linha['produto_id'] ?? null;

            if (! is_int($produtoId)) {
                $legado[] = $linha;

                continue;
            }

            $porProduto[$produtoId][] = $linha;
        }

        $chaveDaConta = is_array($alvo) && isset($alvo['chave']) && is_string($alvo['chave'])
            ? $alvo['chave']
            : null;

        $grupos = [];

        foreach ($porProduto as $produtoId => $doProduto) {
            $ordenadas = $this->ordemDentroDoGrupo($doProduto);

            $celulas = $cobertura[$produtoId] ?? [
                'f1_classico' => false, 'f1_premium' => false,
                'f2_classico' => false, 'f2_premium' => false, 'total' => 0,
            ];

            // ⚠️ `sem_fase2` sai das CÉLULAS de F2, que é o que a tela desenha.
            // Limitação registrada: uma publicação de fase 2 cujo
            // `listing_type_id` não seja `gold_special` nem `gold_pro` conta no
            // `total` do serviço e não acende célula nenhuma, então não limpa
            // esta flag. Corrigir exige contagem POR FASE em
            // `PublicacoesDaListaService` — território do plano 180-02.
            $semFase2 = $celulas['f2_classico'] !== true && $celulas['f2_premium'] !== true;

            $totalNoProduto = max((int) $celulas['total'], count($ordenadas));

            $grupos[] = [
                'produto_id'       => (int) $produtoId,
                'produto_nome'     => $ordenadas[0]['produto_nome'] ?? null,
                'produto_sku'      => $ordenadas[0]['produto_sku'] ?? null,
                // ⚠️ O estoque vem do ACERVO (`available_quantity`, já na
                // linha), e não de `pub_produtos.estoque_calculado`, que é
                // BOOLEANO — era a pendência nomeada do plano 180-02. Pode ser
                // `null`, e então a tela mostra "Estoque —", que é honesto;
                // nunca um zero inventado.
                'estoque'          => $ordenadas[0]['estoque'] ?? null,
                'cobertura'        => [
                    'f1_classico' => (bool) $celulas['f1_classico'],
                    'f1_premium'  => (bool) $celulas['f1_premium'],
                    'f2_classico' => (bool) $celulas['f2_classico'],
                    'f2_premium'  => (bool) $celulas['f2_premium'],
                ],
                'total_no_produto' => $totalNoProduto,
                'total_visivel'    => count($ordenadas),
                'sem_fase2'        => $semFase2,
                'ver_produto_url'  => route('mlb.anuncios.publicador.editor', ['produto' => (int) $produtoId]),
                // ⚠️ MEDIDO: não existe rota GET que crie o combo DE UM PRODUTO
                // específico. O destino real — e é o MESMO que a linha
                // "Prontos para a Fase 2" da Visão Geral já usa
                // (`PainelVisaoGeralService::linhaDeProdutos()`, `:624`) — é a
                // tela de Produtos filtrada por quem ainda pode ganhar fase.
                // Ela NÃO pré-seleciona o produto: `Produtos.jsx` lê `?filtro=`
                // e `?fase=` da URL, mas a busca é estado local (`:463`), então
                // não há como mandar o produto na querystring. Sem conta
                // resolvida e sem fase 2 faltando, é `null` e o link não
                // aparece — nunca um `href="#"`.
                'criar_combo_url'  => $semFase2 && $chaveDaConta !== null
                    ? route('mlb.anuncios.publicador.produtos', [
                        'conta'  => $chaveDaConta,
                        'filtro' => 'publicados',
                        'fase'   => 'so_base',
                    ])
                    : null,
                'ml_item_ids'      => array_values(array_map(
                    fn (array $l) => (string) ($l['ml_item_id'] ?? ''),
                    $ordenadas,
                )),
            ];
        }

        if ($legado !== []) {
            $ordenadas = $this->ordemDentroDoGrupo($legado);

            $grupos[] = [
                'produto_id'       => null,
                'produto_nome'     => self::GRUPO_SEM_PRODUTO,
                'produto_sku'      => null,
                'estoque'          => $ordenadas[0]['estoque'] ?? null,
                // Sem produto não há fase nem tipo que cobrir: as 4 células
                // ficam apagadas, que é a verdade medida, não "não sabemos".
                'cobertura'        => [
                    'f1_classico' => false, 'f1_premium' => false,
                    'f2_classico' => false, 'f2_premium' => false,
                ],
                // `total_no_produto === total_visivel` de propósito: não existe
                // um "total do produto" para quem não tem produto, então a tela
                // não desenha o "x de N" aqui.
                'total_no_produto' => count($ordenadas),
                'total_visivel'    => count($ordenadas),
                // Não há produto para ganhar uma fase 2 — a flag não se aplica,
                // e `false` é o que impede a tela de oferecer o atalho.
                'sem_fase2'        => false,
                'ver_produto_url'  => null,
                'criar_combo_url'  => null,
                'ml_item_ids'      => array_values(array_map(
                    fn (array $l) => (string) ($l['ml_item_id'] ?? ''),
                    $ordenadas,
                )),
            ];
        }

        return $grupos;
    }

    /**
     * A ordem das linhas DENTRO de um grupo — ver o docblock de
     * `gruposPorProduto()` para a conferência no protótipo e a divergência.
     *
     * @param  array<int, array<string, mixed>>  $linhas
     * @return array<int, array<string, mixed>>
     */
    private function ordemDentroDoGrupo(array $linhas): array
    {
        usort($linhas, static function (array $a, array $b) {
            // 1. Fase DESC. Fase ausente (Legado) vai para o fim do grupo.
            $faseA = is_int($a['fase'] ?? null) ? $a['fase'] : 0;
            $faseB = is_int($b['fase'] ?? null) ? $b['fase'] : 0;
            if ($faseA !== $faseB) {
                return $faseB <=> $faseA;
            }

            // 2. Premium antes de Clássico. Tipo desconhecido (`null`) vai
            //    depois dos dois — não é promovido a Clássico.
            $peso = static fn (?string $tipo) => match ($tipo) {
                AcervoTriagemService::TIPO_PREMIUM => 0,
                AcervoTriagemService::TIPO_CLASSICO => 1,
                default => 2,
            };
            $pesoA = $peso(is_string($a['tipo'] ?? null) ? $a['tipo'] : null);
            $pesoB = $peso(is_string($b['tipo'] ?? null) ? $b['tipo'] : null);
            if ($pesoA !== $pesoB) {
                return $pesoA <=> $pesoB;
            }

            // 3. Data DESC. ⚠️ Comparação de STRING ISO 8601, nunca `new
            //    DateTime`: a data chega pronta do serviço e ISO 8601 ordena
            //    lexicograficamente. Data ausente (`''`) vai para o fim.
            $dataA = is_string($a['publicada_em'] ?? null) ? $a['publicada_em'] : '';
            $dataB = is_string($b['publicada_em'] ?? null) ? $b['publicada_em'] : '';
            if ($dataA !== $dataB) {
                return strcmp($dataB, $dataA);
            }

            // 4. Tie-break estável entre requests, como o D-12 da listagem.
            return strcmp(
                (string) ($a['ml_item_id'] ?? ''),
                (string) ($b['ml_item_id'] ?? ''),
            );
        });

        return array_values($linhas);
    }

    /**
     * "há 25 min" / "agora" / "há 3 h" / "há 2 dias" — o rótulo PRONTO do
     * "● Sincronizado X" do cabeçalho (LIST-01).
     *
     * ⚠️ Montado no SERVIDOR porque `CabecalhoDePublicacoes.jsx` não tem (e não
     * pode ter) `new Date()`: `Invalid Date` e o deslocamento de fuso de
     * data-only em BRT já morderam este projeto duas vezes.
     *
     * ⚠️ NUNCA devolve carimbo cru: o componente RECUSA rótulo que casa com
     * `/\d{4}-\d{2}-\d{2}/`, e com razão — "Sincronizado 2026-10-13T12:00:00Z"
     * é jargão de máquina, que a regra permanente do projeto proíbe.
     *
     * ⚠️ Acervo nunca coletado devolve `null`, e então o bloco inteiro
     * desaparece da tela. "nunca medimos" ≠ "medimos e é zero": não existe
     * "Sincronizado há —" nem "Sincronizado nunca".
     *
     * @param  array{coletado_em:?string, horas:?int, defasado:bool, nunca_coletado:bool, motivo:?string}  $defasagem
     */
    private function rotuloDeSincronizacao(array $defasagem): ?string
    {
        if (($defasagem['nunca_coletado'] ?? true) === true) {
            return null;
        }

        $coletadoEm = $defasagem['coletado_em'] ?? null;
        if (! is_string($coletadoEm) || $coletadoEm === '') {
            return null;
        }

        $minutos = (int) Carbon::parse($coletadoEm)->diffInMinutes(now());

        if ($minutos < 1) {
            return 'agora';
        }

        if ($minutos < 60) {
            return 'há '.$minutos.' min';
        }

        $horas = intdiv($minutos, 60);
        if ($horas < 48) {
            return 'há '.$horas.' h';
        }

        $dias = intdiv($horas, 24);

        return 'há '.$dias.' dias';
    }

    /**
     * O `ator` de uma publicação é de um destes usuários DA EQUIPE?
     *
     * ⚠️ `ator` chega CRU (string json) quando vem de um `select` com join — o
     * cast `array` do model não se aplica a coluna de outra tabela. Aceita os
     * dois formatos de propósito.
     *
     * ⚠️ `equipe !== true` nunca casa: o ator do CLIENTE pelo Portal também tem
     * `id` gravado, e o id dele vive em `portal_usuarios`, outra tabela. Casar
     * por id sem olhar `equipe` confundiria um cliente com um colega de mesmo
     * id numérico (T-173-09).
     *
     * @param  array<int,int>  $idsPermitidos
     */
    private function atorEhDaEquipe(mixed $bruto, array $idsPermitidos): bool
    {
        $ator = is_array($bruto) ? $bruto : (is_string($bruto) ? json_decode($bruto, true) : null);
        if (! is_array($ator)) {
            return false;
        }

        if (($ator['equipe'] ?? null) !== true) {
            return false;
        }

        $id = $ator['id'] ?? null;

        return is_numeric($id) && in_array((int) $id, $idsPermitidos, true);
    }

    /**
     * "Atualizar agora" (D-05) — enfileira a coleta da camada barata da
     * empresa e devolve na hora. NUNCA coleta nada em processo: nenhuma
     * chamada a MlAcervoService, nenhuma chamada HTTP. Molde exato:
     * ShopeeOAuthController::sync() — enfileira 1 job para 1 empresa e volta
     * com flash, sem bloquear o request. NÃO copiar
     * MercadoLivreOAuthController::syncNow(), que é síncrono e violaria D-05.
     */
    public function atualizarAgora(Request $request, Company $company)
    {
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        if ($company->mlToken->status !== 'active') {
            return back()->with('error', "{$company->name} não tem conexão com o Mercado Livre ativa — reconecte a conta antes de atualizar.");
        }

        // ShouldBeUnique do job já impede que cliques repetidos empilhem
        // execuções da mesma empresa (134-04) — o cooldown do botão no
        // cliente (134-08) é conforto, não a garantia.
        SyncMlAcervoCompanyJob::dispatch($company);

        Log::info("[MLB Anuncios] coleta manual enfileirada — empresa {$company->id} ({$company->name}) por " . auth()->user()?->name);

        return back()->with('success', 'Coleta enfileirada — pode levar alguns minutos. A tela mostra os dados mais novos na próxima visita.');
    }

    /**
     * Detalhe de um anúncio (Fase 134 Plano 10) — checklist de sinais que
     * fecha com a nota (D-10/D-22) e série de até 90 dias (D-07b), lidos
     * EXCLUSIVAMENTE do banco (D-05). Carregado lazy pelo modal ao abrir —
     * não vem no payload de meus() (decisão A10 do UI-SPEC): 50 itens × até
     * 90 pontos de série seriam milhares de registros que a maioria das
     * aberturas de página nunca usa.
     */
    public function detalheAnuncio(Request $request, Company $company, string $mlItemId): JsonResponse
    {
        // Mesma trava de todas as outras actions do módulo (D-02, T-134-01/02).
        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 404, 'Empresa sem conta ML conectada.');

        // T-134-01: company_id na cláusula é o que impede que um mlItemId de
        // outra empresa seja lido trocando a URL.
        $item = MlAcervoItem::where('company_id', $company->id)
            ->where('ml_item_id', $mlItemId)
            ->firstOrFail();

        // ─── Checklist (D-10/D-22) — montado a partir de nota_sinais JÁ
        // PERSISTIDO pela coleta (MlAcervoService::avaliar), nunca
        // recalculado aqui. Pesos vêm de AnuncioSaudeService::PESOS, nunca
        // escritos à mão — a ordem do array já é a ordem do UI-SPEC. ───
        $sinaisPersistidos = $item->nota_sinais ?? [];
        $labels = [
            'titulo'            => 'Título ≥ 20 caracteres',
            'categoria'         => 'Categoria definida',
            'ficha_obrigatoria' => 'Ficha técnica obrigatória completa',
            'ficha_opcional'    => 'Ficha técnica opcional ≥ 60%',
            'foto'              => 'Ao menos 1 foto',
            'dimensoes'         => 'Dimensões de pacote completas',
            'preco'             => 'Preço definido',
        ];
        // Os dois únicos sinais que analisarAnuncio() classifica como `erro`
        // bloqueante no wizard — a distinção crítico/neutro espelha essa
        // mesma separação, não é arbitrária (UI-SPEC, "Checklist dos sinais").
        $criticos = ['ficha_obrigatoria', 'foto'];

        $checklist = [];
        $somaOk    = 0;
        foreach (AnuncioSaudeService::PESOS as $chave => $peso) {
            $ok = (bool) ($sinaisPersistidos[$chave]['ok'] ?? false);
            if ($ok) {
                $somaOk += $peso;
            }
            $checklist[] = [
                'chave'   => $chave,
                'label'   => $labels[$chave],
                'peso'    => $peso,
                'ok'      => $ok,
                'critico' => in_array($chave, $criticos, true),
            ];
        }

        // Asserção defensiva (T-134-21): a soma dos sinais verdadeiros
        // PRECISA fechar com nota_ecf. Não silenciar quando não fecha — a
        // tela precisa poder mostrar que algo está errado em vez de exibir
        // uma conta que não bate (o mesmo modo de falha já vivido com
        // nps_medio ≠ pontos_componentes.nps, .planning/learnings/desempenho-bonificacao.md).
        $divergencia = $somaOk !== (int) $item->nota_ecf;
        if ($divergencia) {
            Log::warning("[MLB Anuncios] checklist não fecha com a nota — empresa {$company->id}, item {$mlItemId}");
        }

        // ─── Série de até 90 dias (D-07b) — a coleta grava em
        // ml_acervo_metricas_diarias só quando algo muda (decisão do plano
        // 134-04), então a série chega esparsa. Um ponto por dia do
        // intervalo é montado abaixo, com uma assimetria deliberada entre
        // campos de ESTADO e campos de FLUXO (mesma disciplina de
        // honestidade do selo de defasagem, D-08):
        //   • ESTADO (vendas, notaEcf): buraco = "não mudou" — o último
        //     valor conhecido continua válido até a próxima linha gravada.
        //     Preenchimento para frente.
        //   • FLUXO (visitas): buraco = a rotação do D-23 não passou por
        //     este item naquele dia. Preencher aqui inventaria tráfego que
        //     ninguém mediu — fica nulo, e o `connectNulls={false}` do
        //     gráfico existe justamente para mostrar esse buraco como
        //     buraco. ───
        $inicio = now()->subDays(89)->startOfDay();
        $fim    = now()->startOfDay();

        $registros = MlAcervoMetricaDiaria::where('company_id', $company->id)
            ->where('ml_item_id', $mlItemId)
            ->whereBetween('data', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('data')
            ->get()
            ->keyBy(fn ($r) => $r->data->toDateString());

        $serie        = [];
        $ultimoVendas = null;
        $ultimoNota   = null;
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $chave    = $d->toDateString();
            $registro = $registros->get($chave);

            if ($registro !== null) {
                // ESTADO: só atualiza o "último valor conhecido" quando existe registro nesse dia.
                $ultimoVendas = $registro->sold_quantity;
                $ultimoNota   = $registro->nota_ecf;
            }

            $serie[] = [
                'data'    => $chave,
                'visitas' => $registro?->visitas, // FLUXO — nunca preenchido para frente
                'vendas'  => $ultimoVendas,        // ESTADO — preenchimento para frente
                'notaEcf' => $ultimoNota,          // ESTADO — preenchimento para frente
            ];
        }

        return response()->json([
            'item' => [
                'ml_item_id'          => $item->ml_item_id,
                'titulo'              => (string) $item->title,
                'thumbnail'           => $item->thumbnail,
                'permalink'           => $item->permalink,
                'origem'              => $item->origem,
                'rascunho_id'         => $item->rascunho_id,
                'status'              => $item->status,
                'listing_tier'        => $item->listing_type_id,
                'estoque'             => $item->available_quantity,
                'vendas'              => $item->sold_quantity,
                'visitas'             => $item->visitas_30d,
                'visitas_dias'        => $item->detalhe_coletado_em !== null
                    ? $item->detalhe_coletado_em->diffInDays(now())
                    : null,
                'buybox_status'       => $item->buybox_status,
                'buybox_nao_avaliado' => $item->naoAvaliadoBuyBox(),
                'nota_ecf'            => $item->nota_ecf,
                'nota_base'           => AnuncioSaudeService::BASE, // literal — "X de 86" (D-22), nunca renormalizada
                'motivos'             => $item->motivos ?? [],
                // D-21 (Variante A, veredicto DISPONÍVEL) — mesma disciplina
                // de meus(): escalas próprias, nunca convertidas uma na
                // outra; ausência vira "não se aplica" ou "não avaliado",
                // nunca um número inventado.
                'health_ml'              => $item->health_ml !== null ? round((float) $item->health_ml, 2) : null,
                'performance_score'      => $item->performance_score,
                'performance_level'      => $item->performance_level,
                'performance_acoes'      => $item->performance_acoes ?? [], // title já redigido em pt-BR pelo ML — nunca reescrito
                'saude_ml_nao_se_aplica' => $item->saudeMlNaoSeAplica(),
            ],
            'checklist'         => $checklist,
            'checklistTotal'    => AnuncioSaudeService::BASE, // nunca somado no cliente
            'divergencia'       => $divergencia,
            'serie'             => $serie,
            'saudeMlDisponivel' => (bool) config('mlb_acervo.saude_ml_disponivel'),
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
            $company  = $rascunho->company;
            $arquivo  = $request->file('imagem');
            $resposta = $this->imagem->enviar($company, $arquivo->get(), $arquivo->getClientOriginalName());
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
        if ($resposta === null) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Falha no upload da imagem para o Mercado Livre.']],
            ], 422);
        }

        // Sucesso: `picture_id` (consumido pelo wizard individual) e `url` pública da
        // imagem (consumida pela grade em massa p/ preencher as colunas de Foto).
        return response()->json([
            'ok'         => true,
            'picture_id' => $resposta['id'],
            'url'        => $resposta['url'],
        ]);
    }

    /**
     * ─── Creative Engine (Fase 160) ───
     *
     * Upload da(s) foto(s) de referência do produto (FOTO-01), em disco
     * privado (FOTO-02), atrás da chave liga/desliga (OPS-03).
     *
     * Ordem obrigatória: (1) chave ligada, (2) double-check de empresa,
     * (3) validação de arquivo, (4) criação do criativo, (5) gravação em
     * disco. A chave vem ANTES de tudo — com ela desligada a rota nem chega
     * a olhar para o corpo da requisição (404 puro).
     */
    public function criativoReferenciaStore(Request $request, MlAnuncioRascunho $rascunho): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em disco nem banco.
        abort_unless($this->creativeAtivo->ativa(), 404);

        // FOTO-05: double-check de empresa — cópia literal do bloco de uploadImagem()
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

        // FOTO-04: tipo e tamanho validados antes de aceitar, mensagem em pt-BR.
        $request->validate([
            'referencias'   => ['required', 'array', 'max:' . ReferenciaEfemeraService::MAX_REFERENCIAS],
            'referencias.*' => ['required', 'file', 'image', 'max:10240'],
        ], [
            'referencias.*.image' => 'Envie uma imagem (JPG ou PNG) de até 10 MB.',
            'referencias.*.max'   => 'Envie uma imagem (JPG ou PNG) de até 10 MB.',
            'referencias.required' => 'Envie ao menos uma foto do produto.',
        ]);

        // company_id/mlb_empresa_id/user_id SEMPRE derivados do rascunho e do
        // usuário autenticado — nunca do corpo da requisição (T-160-01).
        $criativo = MlAnuncioCriativo::create([
            'token'          => Str::random(32),
            'company_id'     => $rascunho->company_id,
            'mlb_empresa_id' => $rascunho->mlb_empresa_id,
            'rascunho_id'    => $rascunho->id,
            'user_id'        => $request->user()->id,
            'slot'           => 'hero',
            'status'         => MlAnuncioCriativo::STATUS_PENDENTE,
        ]);

        $referencias = $this->referenciaEfemera->guardar($criativo, $request->file('referencias'));
        $criativo->update(['referencias' => $referencias]);

        return response()->json([
            'ok'       => true,
            'criativo' => [
                'token'       => $criativo->token,
                'status'      => $criativo->status,
                'referencias' => collect($referencias)->map(fn ($ref) => [
                    'indice' => $ref['indice'],
                    'nome'   => $ref['nome'],
                    'url'    => route('mlb.anuncios.criativo.referencia.ver', [
                        'token'  => $criativo->token,
                        'indice' => $ref['indice'],
                    ]),
                ])->values(),
            ],
        ], 201);
    }

    /**
     * Leitura da foto de referência por token (FOTO-02) — nunca por URL
     * pública nem adivinhável. `role:admin` no grupo de rotas + double-check
     * de empresa pelo rascunho do criativo + `Cache-Control: private, no-store`.
     */
    public function criativoReferenciaVer(Request $request, string $token, int $indice): Response
    {
        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Referência não encontrada.');

        $rascunho = $criativo->rascunho;
        if ($rascunho !== null) {
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
        }

        $referencia = collect($criativo->referenciasVivas())->firstWhere('indice', $indice);
        abort_if($referencia === null, 404, 'Referência já foi removida.');

        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disco->exists($referencia['path']), 404, 'Referência já foi removida.');

        return response($disco->get($referencia['path']), 200, [
            'Content-Type'  => $referencia['mime'] ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Double-check de empresa pelo rascunho do criativo — cópia literal do
     * bloco de `uploadImagem()`. Compartilhado pelos três endpoints do
     * Plano 02 (gerar/status/imagem): o criativo pode já ter perdido o
     * rascunho (excluído), e nesse caso nenhum escopo adicional é imposto
     * (mesma tolerância de `criativoReferenciaVer`).
     */
    private function checarEscopoDoCriativo(Request $request, MlAnuncioCriativo $criativo): void
    {
        $this->checarEscopoDoRascunho($request, $criativo->rascunho);
    }

    /**
     * Double-check de empresa pelo RASCUNHO — extraído quando o mesmo bloco
     * precisou aparecer uma 3ª vez (Fase 161, `criativoKitStatus`, via
     * `$kit->rascunho`), sem mudar comportamento nenhum dos dois call sites
     * anteriores (`checarEscopoDoCriativo`).
     */
    private function checarEscopoDoRascunho(Request $request, ?MlAnuncioRascunho $rascunho): void
    {
        if ($rascunho === null) {
            return;
        }

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
    }

    /**
     * Dispara a geração da imagem do criativo (GEN-01) — SEMPRE 202 antes de
     * qualquer chamada ao provedor: a geração roda fora da request HTTP, no
     * `GerarCriativoIaJob` (fila `high`).
     *
     * Idempotente: clique repetido num criativo já `emAndamento()` devolve
     * 202 com o mesmo estado, SEM enfileirar de novo — 2ª camada depois do
     * `ShouldBeUnique` do job (GEN-06). `pronto`/`aprovado` são recusados com
     * 422: nesta fase não existe "regenerar" (chega em 160-04) — suba uma
     * foto nova para gerar outro criativo.
     *
     * Throttle na ROTA, não aqui (`throttle:6,1`): cada chamada custa ~US$
     * 0,101 (medição do spike) — dinheiro, não só proteção de abuso.
     */
    public function criativoGerar(Request $request, string $token): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em banco nem enfileirar.
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        // ATENÇÃO: NÃO usar emAndamento() aqui. `pendente` é o estado de
        // REPOUSO logo depois do upload da referência (160-01) — tratá-lo
        // como "já em andamento" faria o PRIMEIRO clique em "Gerar" nunca
        // despachar nada (bug pego em teste de aceitação, 2026-10-02). Só
        // `rodando` significa de fato "já sendo processado agora"; para
        // `pendente`, o `ShouldBeUnique` do próprio job (GEN-06) é quem
        // garante que um clique duplo nesta janela não gera um segundo job.
        if ($criativo->status === MlAnuncioCriativo::STATUS_RODANDO) {
            return response()->json(['token' => $criativo->token, 'status' => $criativo->status], 202);
        }

        if ($criativo->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este criativo já foi aprovado — suba uma foto nova para gerar outro.']],
            ], 422);
        }

        if ($criativo->status === MlAnuncioCriativo::STATUS_PRONTO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Regenerar chega na próxima fase; suba uma foto nova para gerar outro.']],
            ], 422);
        }

        if ($criativo->referenciasVivas() === []) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Suba ao menos uma foto do produto antes de gerar.']],
            ], 422);
        }

        $criativo->update(['status' => MlAnuncioCriativo::STATUS_PENDENTE, 'erro_mensagem' => null]);

        GerarCriativoIaJob::dispatch($criativo->id);

        Log::info("[Creative] Geração enfileirada — criativo {$criativo->id} (rascunho {$criativo->rascunho_id}) por " . $request->user()->name);

        return response()->json(['token' => $criativo->token, 'status' => $criativo->status], 202);
    }

    /**
     * Status do criativo para o polling do painel — SÓ o que a tela usa.
     * NUNCA `prompt`/`contexto`/`truth` nem path de disco (T-160-10): isso
     * fica no servidor, mesmo para o admin que disparou a geração.
     *
     * Chama `encerrarSeTravada()` ANTES de responder — garante que o
     * polling tem fim mesmo se o worker morreu calado (mesma disciplina do
     * job).
     */
    public function criativoStatus(Request $request, string $token): JsonResponse
    {
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        $criativo->encerrarSeTravada();

        return response()->json([
            'token'        => $criativo->token,
            'status'       => $criativo->status,
            'etapa'        => $criativo->etapa,
            'em_andamento' => $criativo->emAndamento(),
            'erro'         => $criativo->erro_mensagem,
            'started_at'   => $criativo->started_at,
            'modelo'       => $criativo->modelo,
            'latencia_ms'  => $criativo->latencia_ms,
            'aprovado_em'  => $criativo->aprovado_em,
            'referencias'  => collect($criativo->referenciasVivas())
                ->map(fn ($ref) => [
                    'indice' => $ref['indice'],
                    'nome'   => $ref['nome'],
                    'url'    => route('mlb.anuncios.criativo.referencia.ver', [
                        'token'  => $criativo->token,
                        'indice' => $ref['indice'],
                    ]),
                ])
                ->values(),
            'imagem_url' => $criativo->imagem_path !== null
                ? route('mlb.anuncios.criativo.imagem', ['token' => $criativo->token])
                : null,
        ]);
    }

    /**
     * Binário da imagem gerada — disco privado, nunca URL pública nem
     * adivinhável (mesma disciplina de `criativoReferenciaVer`).
     */
    public function criativoImagem(Request $request, string $token): Response
    {
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        abort_if($criativo->imagem_path === null, 404, 'Imagem ainda não foi gerada.');

        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disco->exists($criativo->imagem_path), 404, 'Imagem não encontrada.');

        return response($disco->get($criativo->imagem_path), 200, [
            'Content-Type'  => $criativo->imagem_mime ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Aprova o criativo `pronto` (Fase 160, Plano 03) — sobe a imagem ao
     * Mercado Livre pelo caminho de SEMPRE (`MlImagemService::enviar()`,
     * PUB-02) e grava o resultado na posição do slot em
     * `ml_anuncio_rascunhos.payload.pictures` (PUB-01), na MESMA forma que
     * o wizard produz (`['source' => url]`) — ver a seção da armadilha no
     * topo do `160-03-PLAN.md`: só a forma idêntica garante que o próximo
     * autosave do wizard, depois do front chamar `setImagemUrl()` (Task 3),
     * reconstrua o mesmo array em vez de zerá-lo.
     *
     * O estado é o guarda (APROV-05): só `pronto` pode ser aprovado. Falha
     * no upload ao ML (exceção ou resposta sem id) deixa o criativo
     * INTOCADO em `pronto` — meia aprovação não existe.
     */
    public function criativoAprovar(Request $request, string $token): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em banco nem falar com o ML.
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        // OPS-04: conferida DEPOIS do escopo — mesma disciplina dos demais endpoints do kit.
        $this->creativePermissao->exigir($request->user(), 'aprovar');

        $rascunho = $criativo->rascunho;
        abort_unless($rascunho !== null, 422, 'O rascunho deste criativo não existe mais.');

        // APROV-05: o estado é o guarda — mensagem distinta para "ainda
        // gerando" (ou erro) e para "já aprovado", mas os dois são 422.
        if ($criativo->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este criativo já foi aprovado.']],
            ], 422);
        }

        if ($criativo->status !== MlAnuncioCriativo::STATUS_PRONTO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este criativo ainda não está pronto para ser aprovado.']],
            ], 422);
        }

        // Fase 162 (VAL-01/04/06) — a validação automática é GATE no
        // SERVIDOR, não só a tela escondendo o botão. VAL-06 primeiro:
        // pendente há mais de 10 min nunca termina — libera como
        // `indisponivel` antes de recusar por estar "em andamento".
        $criativo->encerrarValidacaoSeTravada();
        $criativo->refresh();

        if ($criativo->validacao_status === MlAnuncioCriativo::VALIDACAO_PENDENTE) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'A validação automática desta imagem ainda está em andamento. Aguarde alguns segundos e tente de novo.']],
            ], 422);
        }

        // T-162-09: único campo aceito do corpo neste endpoint — mesma
        // disciplina do `motivo` de `criativoRegenerar()`.
        $request->validate(['confirmar_risco' => ['sometimes', 'boolean']]);

        if ($criativo->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA) {
            if (! $request->boolean('confirmar_risco')) {
                // VAL-04: reprovada só sobe com confirmação explícita de
                // risco. Mensagem do veredito, montada no servidor — nunca
                // id interno, nunca nome de classe (T-162-13).
                return response()->json([
                    'ok'    => false,
                    'erros' => [['mensagem' => $criativo->validacaoMensagem() ?? 'A validação automática identificou um risco nesta imagem. Confira antes de aprovar.']],
                ], 422);
            }

            // T-162-10: override auditado NA TABELA (não só no log) — quem
            // assumiu o risco e quando. Merge no array existente: NUNCA
            // sobrescreve o veredito gravado pelo juiz.
            $validacaoComOverride = $criativo->validacao ?? [];
            $validacaoComOverride['override'] = [
                'user_id' => $request->user()->id,
                'em'      => now()->toDateTimeString(),
            ];
            $criativo->update(['validacao' => $validacaoComOverride]);

            Log::warning("[Creative] Aprovação com risco confirmado — criativo {$criativo->id} (kit {$criativo->kit_id}) por " . $request->user()->name);
        }

        if ($criativo->imagem_path === null) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'A imagem gerada deste criativo não foi encontrada.']],
            ], 422);
        }

        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disco->exists($criativo->imagem_path)) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'A imagem gerada deste criativo não foi encontrada.']],
            ], 422);
        }

        try {
            $resposta = $this->imagem->enviar(
                $rascunho->company,
                $disco->get($criativo->imagem_path),
                "criativo-{$criativo->token}.jpg",
            );
        } catch (\Throwable $e) {
            // Detalhe técnico só no log; resposta genérica em pt-BR para o
            // front (mesma disciplina de uploadImagem()). O criativo segue
            // `pronto` — nada foi gravado, nada precisa ser desfeito.
            Log::error("[Creative] Falha ao aprovar criativo {$criativo->id}: {$e->getMessage()}");

            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Falha no upload da imagem para o Mercado Livre.']],
            ], 422);
        }

        if ($resposta === null) {
            Log::error("[Creative] Falha ao aprovar criativo {$criativo->id}: upload ao ML não retornou id.");

            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Falha no upload da imagem para o Mercado Livre.']],
            ], 422);
        }

        $criativo->update([
            'status'         => MlAnuncioCriativo::STATUS_APROVADO,
            'aprovado_por'   => $request->user()->id,
            'aprovado_em'    => now(),
            'ml_picture_id'  => $resposta['id'],
            'ml_picture_url' => $resposta['url'],
        ]);

        $kit = $criativo->kit;

        if ($kit !== null) {
            // Decisão 8 (161-03): com kit, `payload.pictures` é reconstruído
            // do zero a partir de TODOS os aprovados — nunca escrito por
            // índice (criaria buracos quando a ordem de aprovação do
            // operador não bate com `slot_indice`).
            $this->creativeKitPublicacao->aplicarPictures($rascunho);
        } else {
            // PUB-01 (fluxo de 1 imagem, Fase 160): substitui SÓ a posição do
            // slot (hero = posição 0), preserva as demais fotos na ordem.
            // Forma idêntica à do wizard (T-160-16) — caminho INTOCADO.
            $payload = $rascunho->payload ?? [];
            $pictures = $payload['pictures'] ?? [];
            $pictures[0] = ['source' => $resposta['url']];
            $payload['pictures'] = array_values($pictures);
            $rascunho->update(['payload' => $payload]);
        }

        // Armadilha 1 (161-03): com kit, a referência do PORTADOR só é
        // apagada quando o KIT INTEIRO é aprovado (`criativoKitAprovar()`)
        // — apagar aqui mataria a regeneração dos outros slots que ainda
        // não foram aprovados. Sem kit, o comportamento é o de sempre
        // (FOTO-03, Fase 160 Plano 04): apaga na hora, com `try/catch` que
        // nunca desfaz uma aprovação já confirmada pelo Mercado Livre.
        if ($kit === null) {
            try {
                $this->referenciaEfemera->apagar($criativo);
            } catch (\Throwable $e) {
                Log::warning("[Creative] Falha ao apagar referência do criativo {$criativo->id} após aprovação: {$e->getMessage()}");
            }
        }

        Log::info("[Creative] Criativo {$criativo->id} aprovado", [
            'rascunho_id' => $rascunho->id,
            'kit_id'      => $criativo->kit_id,
            'picture_id'  => $resposta['id'],
            'usuario'     => $request->user()->id,
        ]);

        // Sem kit: a URL é a do próprio criativo (igual sempre foi). Com
        // kit: a URL devolvida ao front é a do SLOT 1 (hero) — mesmo que
        // não seja ele o slot recém-aprovado — para `onImagemAprovada(url)`
        // continuar apontando o `imagemUrl` do wizard para a imagem
        // principal certa (armadilha 2, 161-03-PLAN.md). Pode vir `null`
        // quando o slot 1 ainda não foi aprovado — o autosave, no pior
        // caso, só reduz a lista temporariamente; quem publica reconstrói
        // (161-04).
        $urlPrincipal = $kit === null
            ? $resposta['url']
            : $kit->slots()->where('slot_indice', 1)->first()?->ml_picture_url;

        return response()->json([
            'ok'         => true,
            'token'      => $criativo->token,
            'status'     => $criativo->status,
            'url'        => $urlPrincipal,
            'picture_id' => $resposta['id'],
        ]);
    }

    /**
     * Regenera UM slot de um kit (Fase 161, Plano 03, APROV-02) — "a mesma
     * intenção, outra tentativa" (Decisão 10 do 161-03-PLAN.md): reusa o
     * `slot_plano` já planejado, nunca replaneja, e enfileira UM job em
     * `creative` — os outros 6 slots ficam intocados (status e
     * `imagem_path` inalterados).
     *
     * Recurso EXCLUSIVO do kit: um criativo sem kit (fluxo de 1 imagem, Fase
     * 160) tem como caminho equivalente subir uma foto nova — o painel já
     * diz isso (ver a mensagem de `criativoGerar` para `status=pronto`).
     *
     * `regeneracoes` (deste criativo e do kit) conta CLIQUES do operador,
     * propositalmente separada de `tentativas` (que também sobe em
     * retentativa automática do Laravel, `GerarCriativoIaJob::$tries = 2`,
     * sem nenhum clique) — ver docblock da migration
     * `..._add_regeneracoes_...` e `MlAnuncioCriativoKit::podeRegenerarAsset()`.
     *
     * Ordem: (1) chave ligada, (2) criativo existe, (3) double-check de
     * empresa, (4) permissão explícita (OPS-04), (4b) validação do `motivo`
     * (Quick 261003-l8o, T-L8O-03 — único campo aceito do corpo), (5)
     * recusas em pt-BR — criativo sem kit, `aprovado` (já foi ao ML),
     * `pendente`/`rodando` (já está acontecendo) ou teto do asset/kit
     * atingido —, (6) transação que acrescenta entrada em
     * `regenerar_motivos`, reabre o slot e incrementa as duas contagens,
     * (7) despacho de UM job (nunca onda — é um só), (8) recálculo do status
     * do kit, (9) 202.
     *
     * `motivo` (opcional, até 300 caracteres): o que o operador escreveu em
     * "o que não ficou bom?" — vira AJUSTE no prompt da regeneração
     * (`CreativePromptBuilder::linhasVariacao()`), sanitizado e NUNCA tratado
     * como fato sobre o produto (TRUTH-02/03 continuam intocados).
     */
    public function criativoRegenerar(Request $request, string $token): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em banco nem enfileirar.
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        // OPS-04: conferida DEPOIS do escopo — mesma disciplina dos demais endpoints do kit.
        $this->creativePermissao->exigir($request->user(), 'regenerar');

        // Quick 261003-l8o (correção 2, T-L8O-01/03): único campo aceito do
        // cliente — opcional, "o que não ficou bom?" do operador. Validado
        // ANTES das recusas de estado, DEPOIS da permissão (mesma disciplina
        // de ordem dos demais checks deste endpoint).
        $request->validate(
            ['motivo' => ['nullable', 'string', 'max:300']],
            ['motivo.max' => 'O texto do que não ficou bom deve ter no máximo 300 caracteres.'],
        );

        $kit = $criativo->kit;

        if ($kit === null || $criativo->slot_indice === null) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Regenerar é um recurso do kit de 7 — suba uma foto nova para gerar outro criativo.']],
            ], 422);
        }

        if ($criativo->status === MlAnuncioCriativo::STATUS_APROVADO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este slot já foi aprovado e enviado ao Mercado Livre — não é possível regenerar.']],
            ], 422);
        }

        if (in_array($criativo->status, MlAnuncioCriativo::STATUS_EM_ANDAMENTO, true)) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este slot já está sendo gerado.']],
            ], 422);
        }

        if (! $kit->podeRegenerarAsset($criativo)) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => $kit->motivoDoTetoAsset($criativo) ?? 'Este slot não pode ser regenerado agora.']],
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($criativo, $kit, $request) {
            // Quick 261003-l8o (T-L8O-05): cada clique ACRESCENTA uma
            // entrada (nunca sobrescreve) — é isso que impede o texto de uma
            // regeneração anterior de vazar para a próxima e o que torna a
            // métrica de motivos do §19 (OPS-02) exata. `texto` fica `null`
            // quando o operador não escreveu nada.
            $texto   = trim((string) $request->input('motivo', ''));
            $motivos = $criativo->regenerar_motivos ?? [];
            $motivos[] = [
                'em'      => now()->toDateTimeString(),
                'user_id' => $request->user()->id,
                'texto'   => $texto !== '' ? $texto : null,
            ];

            $criativo->update([
                'status'             => MlAnuncioCriativo::STATUS_PENDENTE,
                'etapa'              => null,
                'erro_mensagem'      => null,
                'regenerar_motivos'  => $motivos,
            ]);
            $criativo->increment('regeneracoes');
            $kit->increment('regeneracoes');
        });

        // Regeneração é um disparo SÓ (não é onda do despachante) — o
        // `slot_plano` é exatamente o mesmo de antes (Decisão 10).
        GerarCriativoIaJob::dispatch($criativo->id);

        $kit->recalcularStatus();

        Log::info("[Creative] Regeneração enfileirada — criativo {$criativo->id} (kit {$kit->id}, slot {$criativo->slot_indice}) por " . $request->user()->name);

        $criativo->refresh();

        return response()->json([
            'token'                  => $criativo->token,
            'status'                 => $criativo->status,
            'regeneracoes_restantes' => $kit->regeneracoesRestantesAsset($criativo),
        ], 202);
    }

    /**
     * ─── Kit de 7 criativos (Fase 161) ───
     *
     * Dispara o planejamento do kit (PLAN-01/02/03/04) — SEMPRE 202 antes de
     * qualquer chamada ao provedor: o planejamento roda fora da request, em
     * `PlanejarKitCriativosJob` (fila `creative`). Nenhuma imagem é gerada
     * aqui (objetivo do 161-02).
     *
     * Ordem obrigatória: (1) chave ligada, (2) criativo existe, (3)
     * double-check de empresa, (4) permissão explícita — DEPOIS do escopo,
     * para não revelar existência do criativo por diferença entre 403 e 404
     * (OPS-04), (5)-(7) sob lock por rascunho (ver `planejarKitSobLock()` —
     * check-then-act sem lock nasceria DOIS kits para o mesmo rascunho em
     * duas requisições concorrentes de verdade, apontado pelo
     * gsd-plan-checker em 2026-10-02), (8) despacho do job FORA do lock, (9) 202.
     */
    public function criativoKitPlanejar(Request $request, string $token): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em banco.
        abort_unless($this->creativeAtivo->ativa(), 404);

        $criativo = MlAnuncioCriativo::where('token', $token)->first();
        abort_if($criativo === null, 404, 'Criativo não encontrado.');

        $this->checarEscopoDoCriativo($request, $criativo);

        // OPS-04: conferida DEPOIS do escopo (não antes) — ver docblock acima.
        $this->creativePermissao->exigir($request->user(), 'planejar');

        $lock = Cache::lock("criativo-kit-planejar:{$criativo->rascunho_id}", 5);

        try {
            $resultado = $lock->block(3, fn () => $this->planejarKitSobLock($criativo, $request->user()));
        } catch (LockTimeoutException) {
            // Outra requisição está nos passos (5)-(7) agora mesmo — reusa o
            // que existir em vez de arriscar um segundo kit para o mesmo
            // rascunho.
            Log::info("[Creative] Planejamento de kit concorrente — rascunho {$criativo->rascunho_id}, reutilizando o que existir.");

            $kitExistente = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)->latest('id')->first();

            if ($kitExistente !== null) {
                return response()->json(['kit_token' => $kitExistente->token, 'status' => $kitExistente->status], 202);
            }

            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Outra pessoa está planejando o kit deste rascunho agora. Tente novamente em alguns segundos.']],
            ], 409);
        }

        if (($resultado['erro'] ?? false) === true) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Suba ao menos uma foto do produto antes de planejar o kit.']],
            ], 422);
        }

        // (8) despacho do job FORA do lock — segurar o lock enquanto
        // despacha é desperdício.
        if (($resultado['criado'] ?? false) === true) {
            PlanejarKitCriativosJob::dispatch($criativo->id, $resultado['kit_id']);

            Log::info("[Creative] Kit planejamento enfileirado — kit {$resultado['kit_id']} (rascunho {$criativo->rascunho_id}) por " . $request->user()->name);
        }

        return response()->json(['kit_token' => $resultado['kit_token'], 'status' => $resultado['status']], 202);
    }

    /**
     * Passos (5)-(7) do planejamento — DENTRO do lock por rascunho. Devolve
     * um array simples (não HTTP) porque é chamado de dentro de
     * `Cache::lock()->block()`.
     *
     * @return array{kit_token: string, status: string, kit_id?: int, criado?: bool, erro?: bool}
     */
    private function planejarKitSobLock(MlAnuncioCriativo $criativo, User $user): array
    {
        // (5) já existe kit em andamento/pronto para este rascunho? Devolve
        // o mesmo token — idempotência de clique no nível do kit (GEN-06).
        $kitExistente = MlAnuncioCriativoKit::where('rascunho_id', $criativo->rascunho_id)
            ->whereIn('status', [
                MlAnuncioCriativoKit::STATUS_PLANEJANDO,
                MlAnuncioCriativoKit::STATUS_PLANEJADO,
                MlAnuncioCriativoKit::STATUS_GERANDO,
                MlAnuncioCriativoKit::STATUS_PARCIAL,
                MlAnuncioCriativoKit::STATUS_PRONTO,
            ])
            ->first();

        if ($kitExistente !== null) {
            return ['kit_token' => $kitExistente->token, 'status' => $kitExistente->status];
        }

        // (6) sem referência viva não há o que planejar.
        if ($criativo->referenciasVivas() === []) {
            return ['kit_token' => '', 'status' => '', 'erro' => true];
        }

        // (7) cria o kit — todos os ids derivados do criativo e do usuário
        // autenticado, nunca do corpo da requisição.
        $kit = MlAnuncioCriativoKit::create([
            'token'                  => Str::random(32),
            'company_id'             => $criativo->company_id,
            'mlb_empresa_id'         => $criativo->mlb_empresa_id,
            'rascunho_id'            => $criativo->rascunho_id,
            'user_id'                => $user->id,
            'criativo_referencia_id' => $criativo->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PLANEJANDO,
        ]);

        return ['kit_token' => $kit->token, 'status' => $kit->status, 'kit_id' => $kit->id, 'criado' => true];
    }

    /**
     * Dispara a geração das 7 imagens do kit (GEN-01/02/03) — SEMPRE 202
     * antes de qualquer chamada ao provedor: o despacho só enfileira os
     * jobs em `CreativeKitDespachante`, nenhuma imagem é gerada aqui.
     *
     * Ordem obrigatória (mesma disciplina de `criativoKitPlanejar`): (1)
     * chave ligada, (2) kit existe, (3) double-check de empresa pelo
     * rascunho, (4) permissão explícita (DEPOIS do escopo, OPS-04), (5)
     * `encerrarSeTravado()`, (6) recusas em pt-BR (planejamento ainda não
     * terminou, teto de imagens atingido), (7) idempotência — kit já
     * `gerando` não redespacha (GEN-06), (8) despacho de verdade.
     */
    public function criativoKitGerar(Request $request, string $kitToken): JsonResponse
    {
        abort_unless($this->creativeAtivo->ativa(), 404);

        $kit = MlAnuncioCriativoKit::where('token', $kitToken)->first();
        abort_if($kit === null, 404, 'Kit não encontrado.');

        $this->checarEscopoDoRascunho($request, $kit->rascunho);

        // OPS-04: conferida DEPOIS do escopo (não antes) — mesma disciplina
        // de `criativoKitPlanejar`.
        $this->creativePermissao->exigir($request->user(), 'gerar');

        $kit->encerrarSeTravado();

        if ($kit->status === MlAnuncioCriativoKit::STATUS_PLANEJANDO
            || ($kit->status === MlAnuncioCriativoKit::STATUS_ERRO && $kit->totalSlots() === 0)) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'O planejamento deste kit ainda não terminou — aguarde antes de gerar as imagens.']],
            ], 422);
        }

        if ($kit->tetoDeImagensAtingido()) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => $kit->motivoDoTeto()]],
            ], 422);
        }

        // GEN-06: kit já gerando não despacha de novo — o polling já está
        // acompanhando o que foi disparado antes.
        if ($kit->status === MlAnuncioCriativoKit::STATUS_GERANDO) {
            return response()->json(['kit_token' => $kit->token, 'status' => $kit->status, 'enfileirados' => 0], 202);
        }

        $resultado = $this->creativeKitDespachante->despachar($kit);

        Log::info("[Creative] Geração do kit {$kit->id} disparada — "
            ."{$resultado['enfileirados']} enfileirados por " . $request->user()->name);

        return response()->json([
            'kit_token'    => $kit->token,
            'status'       => $kit->fresh()->status,
            'enfileirados' => $resultado['enfileirados'],
        ], 202);
    }

    /**
     * Status do kit para o polling do painel — SÓ o que a tela usa (T-161-05
     * / T-161-12). O `plano` cru NUNCA vai ao navegador — só a `estrategia`
     * e os campos por slot (indice/tipo/rotulo/objetivo/status/etapa/erro/
     * token/imagem_url/modelo/latencia_ms); `prompt`, `contexto`, `truth` e
     * `imagem_path` dos slots nunca aparecem aqui.
     *
     * Chama `encerrarSeTravado()`/`recalcularStatus()` ANTES de responder —
     * garante que o polling tem fim mesmo se o worker morreu calado (mesma
     * disciplina de `criativoStatus`).
     */
    public function criativoKitStatus(Request $request, string $kitToken): JsonResponse
    {
        abort_unless($this->creativeAtivo->ativa(), 404);

        $kit = MlAnuncioCriativoKit::where('token', $kitToken)->first();
        abort_if($kit === null, 404, 'Kit não encontrado.');

        $this->checarEscopoDoRascunho($request, $kit->rascunho);

        $kit->encerrarSeTravado();
        $kit->recalcularStatus();

        // Fase 162 Plano 04: a trava de tempo da validação (VAL-06) tem
        // efeito visível AQUI — é o endpoint do polling. Molde literal do
        // mesmo laço em `criativoKitAprovar()`: slot pendente há mais de
        // `LIMITE_VALIDACAO_MINUTOS` libera como `indisponivel` antes de
        // montar a resposta, nunca deixando a tela presa em "validando…".
        $kit->slots()
            ->where('validacao_status', MlAnuncioCriativo::VALIDACAO_PENDENTE)
            ->get()
            ->each(fn (MlAnuncioCriativo $slot) => $slot->encerrarValidacaoSeTravada());

        $portador = $kit->criativoReferencia;

        $slots = $kit->slots()->get()->map(function (MlAnuncioCriativo $slot) use ($kit) {
            $padrao = $this->creativeSlotCatalog->padraoDe((string) $slot->slot) ?? [];

            // Fase 162 Plano 04 (APROV-04/VAL-03) — flags calculadas no
            // SERVIDOR; a tela nunca recalcula a régua de validação (mesma
            // disciplina de `pode_aprovar`/`exige_confirmacao_risco` com o
            // gate real de `criativoAprovar()`).
            $podeAprovar = $slot->status === MlAnuncioCriativo::STATUS_PRONTO
                && $slot->validacao_status !== MlAnuncioCriativo::VALIDACAO_PENDENTE
                && $slot->validacao_status !== MlAnuncioCriativo::VALIDACAO_REPROVADA;

            $exigeConfirmacaoRisco = $slot->status === MlAnuncioCriativo::STATUS_PRONTO
                && $slot->validacao_status === MlAnuncioCriativo::VALIDACAO_REPROVADA;

            return [
                'indice'      => $slot->slot_indice,
                'tipo'        => $slot->slot,
                'rotulo'      => $padrao['rotulo'] ?? $slot->slot,
                'objetivo'    => $slot->slot_plano['objetivo'] ?? ($padrao['objetivo_padrao'] ?? null),
                'status'      => $slot->status,
                'etapa'       => $slot->etapa,
                'erro'        => $slot->erro_mensagem,
                'token'       => $slot->token,
                // Só aponta para a rota quando a imagem existe (161-02) —
                // a rota já faz escopo e `Cache-Control: private, no-store`.
                'imagem_url'  => $slot->imagem_path !== null
                    ? route('mlb.anuncios.criativo.imagem', ['token' => $slot->token])
                    : null,
                'modelo'      => $slot->modelo,
                'latencia_ms' => $slot->latencia_ms,
                // Fase 161 Plano 03 (APROV-02) — a tela mostra "restantes",
                // nunca recalcula a régua: o servidor já aplicou os dois
                // tetos (asset e kit) em `regeneracoesRestantesAsset()`.
                'regeneracoes'            => $slot->regeneracoes,
                'regeneracoes_restantes'  => $kit->regeneracoesRestantesAsset($slot),
                // Fase 161 Plano 03 (APROV-03) — link para a imagem já
                // aprovada no Mercado Livre; a grade some os botões e mostra
                // este link quando preenchido (evita segundo upload).
                'ml_picture_url'          => $slot->ml_picture_url,
                // Fase 162 Plano 04 (APROV-04/VAL-03) — whitelist fechada:
                // só estes 5 campos; nada do json cru de `validacao` vaza
                // (nunca `fidelidade`, `tipo`, `override`, `motivo_curto`
                // por item — T-162-18).
                'validacao_status'   => $slot->validacao_status,
                'validacao_mensagem' => $slot->validacaoMensagem(),
                'validacao_problemas' => collect($slot->validacao['problemas'] ?? [])
                    ->map(fn ($problema) => [
                        'gravidade'  => $problema['gravidade'] ?? null,
                        'explicacao' => $problema['explicacao'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'pode_aprovar'            => $podeAprovar,
                'exige_confirmacao_risco' => $exigeConfirmacaoRisco,
            ];
        })->values();

        return response()->json([
            'kit_token'        => $kit->token,
            'status'           => $kit->status,
            'etapa'            => $kit->etapa,
            'em_andamento'     => in_array($kit->status, MlAnuncioCriativoKit::STATUS_EM_ANDAMENTO, true),
            'erro'             => $kit->erro_mensagem,
            'estrategia'       => $kit->plano['estrategia'] ?? null,
            'minimo_aprovadas' => $kit->minimo_aprovadas,
            // Fase 161 Plano 03 (APROV-03) — a tela decide se "Aprovar kit"
            // já pode ser clicado só com estes dois números; nunca recalcula
            // a régua a partir dos slots.
            'prontas'          => $kit->prontas(),
            'aprovadas'        => $kit->aprovadas(),
            // Fase 162 Plano 04 (VAL-04 em lote) — a tela não recalcula régua
            // nenhuma (decisão da 161-03): precisa destes dois números prontos
            // do servidor para o botão "Aprovar kit" e para o aviso de topo.
            'prontas_sem_risco' => $kit->prontasSemRisco(),
            'reprovadas'        => $kit->reprovadas(),
            'referencias'      => $portador === null ? [] : collect($portador->referenciasVivas())
                ->map(fn ($ref) => [
                    'indice' => $ref['indice'],
                    'nome'   => $ref['nome'],
                    'url'    => route('mlb.anuncios.criativo.referencia.ver', [
                        'token'  => $portador->token,
                        'indice' => $ref['indice'],
                    ]),
                ])
                ->values(),
            'slots' => $slots,
        ]);
    }

    /**
     * Aprova o KIT INTEIRO de uma vez (Fase 161, Plano 03, APROV-03): sobe
     * cada slot `pronto` ao Mercado Livre por `MlImagemService::enviar()`
     * (PUB-02, nenhum caminho novo), em ordem de `slot_indice`, e SÓ FECHA o
     * kit (`status=aprovado`) quando NENHUM slot falhou e o mínimo
     * congelado (`minimo_aprovadas`) foi atingido — meia aprovação não
     * existe por slot (cada upload é definitivo assim que sobe), mas o KIT
     * só fecha quando fecha (molde `publicarDuplo`: resultado por item,
     * nunca tudo-ou-nada).
     *
     * Armadilha 1 (161-03-PLAN.md): a referência do PORTADOR só é apagada
     * AQUI — na aprovação do kit inteiro —, nunca na aprovação de um slot
     * isolado (`criativoAprovar()`), porque os slots ainda não aprovados
     * podem precisar regenerar e leem a MESMA foto do portador.
     *
     * Ordem: (1) chave ligada, (2) kit existe, (3) double-check de empresa
     * pelo rascunho, (4) permissão explícita (OPS-04), (5)
     * `encerrarSeTravado()`, (6) recusas em pt-BR — kit já `aprovado`,
     * mínimo não atingido —, (7) upload slot a slot (resumo por índice),
     * (8) `aplicarPictures()` reconstrói o payload com o que subiu, (9) se
     * e só se nada falhou e o mínimo foi atingido, fecha o kit e apaga a
     * referência (try/catch que nunca desfaz aprovação já confirmada).
     */
    public function criativoKitAprovar(Request $request, string $kitToken): JsonResponse
    {
        // OPS-03: chave desligada → 404, sem tocar em banco nem falar com o ML.
        abort_unless($this->creativeAtivo->ativa(), 404);

        $kit = MlAnuncioCriativoKit::where('token', $kitToken)->first();
        abort_if($kit === null, 404, 'Kit não encontrado.');

        $this->checarEscopoDoRascunho($request, $kit->rascunho);

        // OPS-04: conferida DEPOIS do escopo — mesma disciplina dos demais endpoints do kit.
        $this->creativePermissao->exigir($request->user(), 'aprovar');

        $kit->encerrarSeTravado();

        // Fase 162 (VAL-06): a tela chama este endpoint depois de polling,
        // então a trava de tempo da validação precisa valer AQUI também —
        // cada slot `pendente` travado há mais de 10 min libera como
        // `indisponivel` antes de decidir se o mínimo foi atingido.
        $kit->slots()
            ->where('validacao_status', MlAnuncioCriativo::VALIDACAO_PENDENTE)
            ->get()
            ->each(fn (MlAnuncioCriativo $slot) => $slot->encerrarValidacaoSeTravada());

        if ($kit->status === MlAnuncioCriativoKit::STATUS_APROVADO) {
            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => 'Este kit já foi aprovado.']],
            ], 422);
        }

        // Fase 162 (VAL-04 em lote): `prontasSemRisco()` nunca conta slot
        // reprovado nem ainda em validação — aprovar o kit NUNCA sobe uma
        // imagem reprovada em silêncio.
        $disponiveis = $kit->prontasSemRisco() + $kit->aprovadas();
        if ($disponiveis < $kit->minimo_aprovadas) {
            $faltam = $kit->minimo_aprovadas - $disponiveis;

            $mensagem = "Faltam {$faltam} imagem(ns) para o mínimo de {$kit->minimo_aprovadas} aprovadas.";

            $reprovadasCount = $kit->reprovadas();
            $pendentesCount  = $kit->slots()->where('validacao_status', MlAnuncioCriativo::VALIDACAO_PENDENTE)->count();

            $partes = [];
            if ($reprovadasCount > 0) {
                $partes[] = $reprovadasCount === 1
                    ? '1 imagem foi reprovada na validação automática'
                    : "{$reprovadasCount} imagens foram reprovadas na validação automática";
            }
            if ($pendentesCount > 0) {
                $partes[] = $pendentesCount === 1
                    ? '1 ainda está sendo validada'
                    : "{$pendentesCount} ainda estão sendo validadas";
            }

            if ($partes !== []) {
                $mensagem .= ' ' . implode(' e ', $partes) . ' — gere de novo ou aprove essas imagens uma a uma assumindo o risco.';
            }

            return response()->json([
                'ok'    => false,
                'erros' => [['mensagem' => $mensagem]],
            ], 422);
        }

        $rascunho = $kit->rascunho;
        abort_unless($rascunho !== null, 422, 'O rascunho deste kit não existe mais.');

        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        $aprovadas = 0;
        $falharam  = [];

        // Fase 162 (VAL-04 em lote): reprovadas/pendentes são PULADAS, nunca
        // tratadas como falha de upload — não entram em `falharam` para não
        // contaminar a regra de `$kitFicaAprovado`.
        $reprovadas = $kit->slots()
            ->where('status', MlAnuncioCriativo::STATUS_PRONTO)
            ->where('validacao_status', MlAnuncioCriativo::VALIDACAO_REPROVADA)
            ->orderBy('slot_indice')
            ->pluck('slot_indice')
            ->all();

        $validando = $kit->slots()
            ->where('status', MlAnuncioCriativo::STATUS_PRONTO)
            ->where('validacao_status', MlAnuncioCriativo::VALIDACAO_PENDENTE)
            ->orderBy('slot_indice')
            ->pluck('slot_indice')
            ->all();

        $slotsProntos = $kit->slots()
            ->where('status', MlAnuncioCriativo::STATUS_PRONTO)
            ->where(function ($query) {
                $query->whereNotIn('validacao_status', [
                    MlAnuncioCriativo::VALIDACAO_REPROVADA,
                    MlAnuncioCriativo::VALIDACAO_PENDENTE,
                ])->orWhereNull('validacao_status');
            })
            ->orderBy('slot_indice')
            ->get();

        foreach ($slotsProntos as $slot) {
            if ($slot->imagem_path === null || ! $disco->exists($slot->imagem_path)) {
                Log::error("[Creative] Falha ao aprovar slot {$slot->id} do kit {$kit->id}: imagem não encontrada em disco.");
                $falharam[] = $slot->slot_indice;
                continue;
            }

            try {
                $resposta = $this->imagem->enviar(
                    $rascunho->company,
                    $disco->get($slot->imagem_path),
                    "criativo-{$slot->token}.jpg",
                );
            } catch (\Throwable $e) {
                // Detalhe técnico só no log (nunca bytes) — o slot continua
                // `pronto`, nada foi gravado, e os demais seguem na mesma
                // chamada (um upload não aborta os outros).
                Log::error("[Creative] Falha ao aprovar slot {$slot->id} do kit {$kit->id}: {$e->getMessage()}");
                $falharam[] = $slot->slot_indice;
                continue;
            }

            if ($resposta === null) {
                Log::error("[Creative] Falha ao aprovar slot {$slot->id} do kit {$kit->id}: upload ao ML não retornou id.");
                $falharam[] = $slot->slot_indice;
                continue;
            }

            $slot->update([
                'status'         => MlAnuncioCriativo::STATUS_APROVADO,
                'aprovado_por'   => $request->user()->id,
                'aprovado_em'    => now(),
                'ml_picture_id'  => $resposta['id'],
                'ml_picture_url' => $resposta['url'],
            ]);

            $aprovadas++;
        }

        // Decisão 8: reconstrói o payload do que SUBIU de verdade até aqui —
        // roda sempre, mesmo em falha parcial, para a tela/rascunho ficarem
        // coerentes com o que já está no Mercado Livre.
        $totalEscrito = $this->creativeKitPublicacao->aplicarPictures($rascunho);

        // Fase 162 (Decisão 4 do 162-02-PLAN.md): o kit só fecha quando NÃO
        // há slot pulado por risco ou validação em andamento — fechar o kit
        // apaga a foto de referência do portador (Armadilha 1 da 161-03) e
        // isso mataria a regeneração do slot reprovado/pendente que o
        // operador ainda vai querer corrigir.
        $kitFicaAprovado = $falharam === []
            && $reprovadas === []
            && $validando === []
            && $kit->aprovadas() >= $kit->minimo_aprovadas;

        if ($kitFicaAprovado) {
            $kit->update([
                'status'       => MlAnuncioCriativoKit::STATUS_APROVADO,
                'aprovado_por' => $request->user()->id,
                'aprovado_em'  => now(),
            ]);

            // Armadilha 1: só agora — todo o kit fechou — o papel da foto
            // do cliente termina. `try/catch`: falha ao apagar NUNCA desfaz
            // uma aprovação que já chegou ao ML (mesma disciplina de
            // `criativoAprovar()`); o que escapar é recolhido pela
            // varredura diária (`creative:limpar-referencias`, FOTO-03).
            $portador = $kit->criativoReferencia;
            if ($portador !== null) {
                try {
                    $this->referenciaEfemera->apagar($portador);
                } catch (\Throwable $e) {
                    Log::warning("[Creative] Falha ao apagar referência do kit {$kit->id} após aprovação: {$e->getMessage()}");
                }
            }
        }
        // Falha parcial: o kit NÃO muda de status aqui de propósito —
        // `recalcularStatus()` não sabe interpretar a mistura de slots
        // `aprovado`+`pronto` (ele só conta pendente/rodando/pronto/erro);
        // o estado de GERAÇÃO do kit não mudou, só o de APROVAÇÃO de cada
        // slot individual.

        Log::info("[Creative] Kit {$kit->id} aprovação em lote", [
            'aprovadas'         => $aprovadas,
            'falharam'          => $falharam,
            'reprovadas'        => $reprovadas,
            'validando'         => $validando,
            'fotos_no_payload'  => $totalEscrito,
            'kit_aprovado'      => $kitFicaAprovado,
            'usuario'           => $request->user()->id,
        ]);

        // Mesma regra de `criativoAprovar()`: o front usa a URL do SLOT 1
        // (hero) para `onImagemAprovada(url)` — `null` quando ele não está
        // entre os aprovados.
        $urlPrincipal = $kit->slots()->where('slot_indice', 1)->first()?->ml_picture_url;

        $mensagem = "{$aprovadas} imagem(ns) aprovada(s) com sucesso.";
        if ($falharam !== []) {
            $mensagem = "{$aprovadas} imagem(ns) subiu(ram); falhou o upload do(s) slot(s) " . implode(', ', $falharam) . '.';
        } elseif ($reprovadas !== [] || $validando !== []) {
            // Fase 162: pular reprovada/pendente não é falha de upload — a
            // mensagem de sucesso parcial diz isso em pt-BR, sem termo técnico.
            $partes = [];
            if ($reprovadas !== []) {
                $partes[] = 'reprovada(s) na validação automática';
            }
            if ($validando !== []) {
                $partes[] = 'ainda em validação';
            }
            $mensagem .= ' ' . implode(' e ', $partes) . ' — pulada(s) por enquanto, não sobe(em) em lote.';
        }

        return response()->json([
            'ok'         => $falharam === [],
            'kit_token'  => $kit->token,
            'status'     => $kit->fresh()->status,
            'aprovadas'  => $aprovadas,
            'falharam'   => $falharam,
            'reprovadas' => $reprovadas,
            'validando'  => $validando,
            'url'        => $urlPrincipal,
            'mensagem'   => $mensagem,
        ]);
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

        // O custo de /users/{id}/shipping_options/free vem em `coverage.all_country.list_cost`
        // (respostas reais da #459, tests/fixtures-ml/sondagem/conta). `shipping_options[0]` é de
        // outro endpoint e nunca existiu nesta resposta: a estimativa saía sempre vazia (09/10/2026).
        $cobertura = data_get($resultado, 'coverage.all_country');

        // Resposta sempre 200: estimativa_frete é float quando ML responde, null em falha
        // O front exibe o campo vazio em vez de bloquear a publicação (degradação graciosa)
        return response()->json([
            'ok'               => true,
            'estimativa_frete' => is_array($cobertura) && is_numeric($cobertura['list_cost'] ?? null) ? (float) $cobertura['list_cost'] : null,
            'opcoes'           => is_array($cobertura) ? [$cobertura] : [],
        ]);
    }

    /** Tipos de anúncio do site (clássico, premium, grátis...). */
    public function tiposAnuncio()
    {
        return response()->json($this->meta->tiposDeAnuncio());
    }

    // ─── Autopeças: compatibilidades de veículos (AUTO-01) ───
    // Metadados públicos (app token, cacheados). O picker do wizard só aparece
    // quando `aceita` é true; a cascata degrada para lista vazia em falha.

    /** A categoria aceita compatibilidade de veículos? (heurística por árvore) */
    public function compatCategoria(string $categoryId): JsonResponse
    {
        return response()->json($this->compat->aceitaCompatibilidades($categoryId));
    }

    /** Marcas de veículo (top_values BRAND). */
    public function compatMarcas(): JsonResponse
    {
        return response()->json(['marcas' => $this->compat->marcas()]);
    }

    /** Modelos de uma marca (top_values MODEL). */
    public function compatModelos(Request $request): JsonResponse
    {
        $brandId = (string) $request->query('brand_id', '');

        return response()->json(['modelos' => $this->compat->modelos($brandId)]);
    }

    /** Anos de um modelo (top_values VEHICLE_YEAR). */
    public function compatAnos(Request $request): JsonResponse
    {
        $brandId = (string) $request->query('brand_id', '');
        $modelId = (string) $request->query('model_id', '');

        return response()->json(['anos' => $this->compat->anos($brandId, $modelId)]);
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

    // ═══ Anunciar por IA — metodologia MAG T8 ════════════════════════════════

    /**
     * Última análise da empresa, para a tela se recuperar de um F5.
     *
     * Só as 2 últimas horas: análise de ontem é de outro anúncio e reabrir ela
     * confundiria mais do que ajudaria. Devolve `null` quando não há nada —
     * a tela então abre o painel em branco, como antes.
     */
    private function ultimaAnaliseIa(Company $company): ?array
    {
        $a = MlAnuncioIaAnalise::where('company_id', $company->id)
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->first();

        if (! $a) {
            return null;
        }

        $a->encerrarSeTravada();

        return [
            'id'           => $a->id,
            'status'       => $a->status,
            'etapa'        => $a->etapa,
            // A tela conta o tempo a partir DAQUI, não do momento em que a
            // página abriu — senão um F5 zera o cronômetro e dá a impressão
            // de que a geração recomeçou do nada.
            'started_at'   => $a->started_at?->toISOString(),
            'em_andamento' => $a->emAndamento(),
            'erro'         => $a->erro_mensagem,
            'produto'      => $a->produto,
            'specs'        => $a->specs,
            'loja'         => $a->loja,
            'titulos'      => $a->titulos(),
            'descricao'    => $a->descricao(),
            'analise'      => $a->analise(),
            'modelo'       => $a->modelo,
            'duracao_ms'   => $a->duracao_ms,
            'sku'          => $a->resultado['cliente']['sku'] ?? null,
        ] + $this->preenchimentoIa($a);
    }

    /**
     * O que a IA cadastrou além da copy: resumo da ficha e o rascunho gravado.
     *
     * O rascunho vai com o payload inteiro porque a tela o abre no wizard na
     * hora em que a geração termina — o mesmo formato da lista de rascunhos.
     * Só o da MESMA empresa: o id vem do JSON da análise, não do navegador,
     * mas a checagem custa uma cláusula.
     */
    private function preenchimentoIa(MlAnuncioIaAnalise $a): array
    {
        $id       = $a->rascunhoId();
        $rascunho = $id !== null
            ? MlAnuncioRascunho::where('id', $id)->where('company_id', $a->company_id)->first()
            : null;

        return [
            'ficha'    => $a->resumoFicha(),
            'rascunho' => $rascunho ? [
                'id'          => $rascunho->id,
                'status'      => $rascunho->status,
                'category_id' => $rascunho->category_id,
                'ml_item_id'  => $rascunho->ml_item_id,
                'payload'     => $rascunho->payload,
            ] : null,
        ];
    }

    /**
     * Dispara a análise por IA e devolve o id para o front acompanhar.
     *
     * Responde na hora com `pendente` e joga o trabalho para a fila: a geração
     * levou 103s na medição de 21/09/2026, então nenhum request aguenta esperar.
     *
     * `loja` é derivada da conta ML no servidor — o publicador não digita, e o
     * cliente não teria como forjar.
     */
    public function iaAnaliseStore(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'company_id' => ['nullable', 'required_without:produto_id', 'integer', 'exists:companies,id'],
            // D14: do Publicador o pedido é por produto (pub_produtos) e o
            // resultado vai para o rascunho novo, não para o wizard antigo.
            'produto_id' => ['nullable', 'required_without:company_id', 'integer', 'exists:pub_produtos,id'],
            'substituir' => ['sometimes', 'boolean'],
            'produto'    => ['required_without:produto_id', 'nullable', 'string', 'max:300'],
            'specs'      => ['nullable', 'string', 'max:8000'],
            // Produto da planilha do cliente (opcional): preço, estoque e
            // medidas do rascunho saem DAQUI, lidos no servidor — o navegador
            // só diz qual SKU.
            'sku'        => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($dados['produto_id'])) {
            return $this->iaAnaliseStorePublicador($request, $dados);
        }

        $company = Company::findOrFail($dados['company_id']);

        $company->loadMissing('mlToken');
        abort_unless($company->mlToken !== null, 422, 'Empresa sem conta ML conectada.');

        $resultado = null;
        $sku       = trim((string) ($dados['sku'] ?? ''));

        if ($sku !== '') {
            $mlbEmpresa = MlbEmpresa::where('company_id', $company->id)->with('implementacao')->first();
            $produto    = collect($this->montarProdutosDoCliente($mlbEmpresa?->implementacao?->dados))
                ->first(fn ($p) => trim((string) $p['sku']) === $sku);

            if ($produto === null) {
                return response()->json(['message' => 'Produto não encontrado nos dados do cliente.'], 422);
            }

            // Retrato do produto NA HORA do pedido: a geração leva minutos e
            // o rascunho tem que sair com o preço que o publicador viu.
            $resultado = ['cliente' => [
                'sku'          => $produto['sku'],
                'produto'      => $produto['produto'],
                'preco_c'      => $produto['preco_anunciado_c'],
                'preco_p'      => $produto['preco_anunciado_p'],
                'estoque'      => $produto['estoque'],
                'peso_kg'      => $produto['peso_kg'],
                'altura'       => $produto['altura'],
                'largura'      => $produto['largura'],
                'profundidade' => $produto['profundidade'],
            ]];
        }

        $analise = MlAnuncioIaAnalise::create([
            'company_id' => $company->id,
            'user_id'    => $request->user()->id,
            'produto'    => trim($dados['produto']),
            'loja'       => $company->nomeContaMl(),
            'specs'      => $dados['specs'] ?? null,
            'status'     => MlAnuncioIaAnalise::STATUS_PENDENTE,
            'resultado'  => $resultado,
        ]);

        GerarAnaliseAnuncioIaJob::dispatch($analise->id);

        return response()->json([
            'id'     => $analise->id,
            'status' => $analise->status,
        ], 202);
    }

    /**
     * D14: "Anunciar por IA" de um produto do Publicador. A análise nasce com o
     * destino `publicador` (produto + rascunho + revisão do pedido) e o job grava
     * no rascunho `pub_*` pelo motor. NÃO exige token: a IA só preenche rascunho.
     *
     * `substituir` só vale se a equipe não editar durante a geração (a revisão do
     * rascunho é comparada na hora de aplicar) — o que se digita vence.
     */
    private function iaAnaliseStorePublicador(Request $request, array $dados): JsonResponse
    {
        $p = PubProduto::findOrFail((int) $dados['produto_id']);

        // T-164-40: empresa arquivada ou sem dono não existe para o Publicador.
        abort_if(app(ProgramasPublicadorService::class)->empresaDoProduto($p) === null, 404);

        $r = app(EditorRascunhoService::class)->abrir($p);
        if (IaParaRascunhoService::intocavel($r)) {
            return response()->json(['message' => 'Este anúncio já foi publicado ou está publicando.'], 422);
        }

        $resultado = [
            'destino' => [
                'tipo'         => 'publicador',
                'produto_id'   => $p->id,
                'rascunho_id'  => $r->id,
                'revisao_base' => (int) $r->revisao,
                'substituir'   => (bool) ($dados['substituir'] ?? false),
            ],
        ];

        // Dados do cliente (preço, estoque, medidas) pela planilha do onboarding, achados pelo SKU.
        $sku = trim((string) $p->skuExibido());
        if ($sku !== '' && $p->mlbEmpresa) {
            $achado = collect($this->montarProdutosDoCliente($p->mlbEmpresa->implementacao?->dados))
                ->first(fn ($x) => trim((string) $x['sku']) === $sku);
            if ($achado !== null) {
                $resultado['cliente'] = [
                    'sku'          => $achado['sku'],
                    'produto'      => $achado['produto'],
                    'preco_c'      => $achado['preco_anunciado_c'],
                    'preco_p'      => $achado['preco_anunciado_p'],
                    'estoque'      => $achado['estoque'],
                    'peso_kg'      => $achado['peso_kg'],
                    'altura'       => $achado['altura'],
                    'largura'      => $achado['largura'],
                    'profundidade' => $achado['profundidade'],
                ];
            }
        }

        $analise = MlAnuncioIaAnalise::create([
            'company_id'     => $p->company_id,
            'mlb_empresa_id' => $p->mlb_empresa_id,
            'user_id'        => $request->user()->id,
            'produto'        => trim((string) ($dados['produto'] ?? '')) ?: $p->nomeExibido(),
            'loja'           => $p->contaOuNula()?->nomeContaMl() ?? ($p->mlbEmpresa?->nome ?? $p->company?->name ?? ''),
            'specs'          => $dados['specs'] ?? null,
            'status'         => MlAnuncioIaAnalise::STATUS_PENDENTE,
            'resultado'      => $resultado,
        ]);

        GerarAnaliseAnuncioIaJob::dispatch($analise->id);

        return response()->json([
            'id'          => $analise->id,
            'status'      => $analise->status,
            'rascunho_id' => $r->id,
        ], 202);
    }

    /**
     * Estado da análise — o front chama em intervalo até sair de "em andamento".
     *
     * Só devolve o que a tela usa. O prompt e o payload cru do provedor ficam
     * no servidor: não há motivo para trafegar isso ao navegador.
     */
    public function iaAnaliseStatus(Request $request, MlAnuncioIaAnalise $analise): JsonResponse
    {
        // Cada análise pertence a uma conta; sem esta checagem o id sequencial
        // viraria uma janela para o trabalho de outra empresa.
        // D14: a do Publicador não depende de token (a IA só preenche rascunho) e
        // a rota já é role:admin; o escopo é o produto, não a conta.
        if ($analise->company_id !== null && $analise->destinoPublicador() === null) {
            $company = Company::findOrFail($analise->company_id);
            $company->loadMissing('mlToken');
            abort_unless($company->mlToken !== null, 404);
        }

        // Garante que o polling tem fim mesmo se o worker morreu calado.
        $analise->encerrarSeTravada();

        return response()->json([
            'id'          => $analise->id,
            'status'      => $analise->status,
            'etapa'       => $analise->etapa,
            'started_at'  => $analise->started_at?->toISOString(),
            'em_andamento' => $analise->emAndamento(),
            'erro'        => $analise->erro_mensagem,
            'produto'     => $analise->produto,
            'loja'        => $analise->loja,
            'titulos'     => $analise->titulos(),
            'descricao'   => $analise->descricao(),
            'analise'     => $analise->analise(),
            'modelo'      => $analise->modelo,
            'duracao_ms'  => $analise->duracao_ms,
            // D14: o que a IA gravou no rascunho do Publicador (null no caminho antigo).
            'publicador'  => $analise->resultado['publicador'] ?? null,
        ] + $this->preenchimentoIa($analise));
    }
}
