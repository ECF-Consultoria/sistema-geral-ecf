<?php

namespace App\Http\Controllers;

use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function wizard(Request $request, MlbEmpresa $mlbEmpresa)
    {
        // T-75-05: empresa deve pertencer ao publicador ou o usuário é admin
        abort_unless(
            $request->user()->isAdmin() || $mlbEmpresa->responsavel_id === $request->user()->id,
            403,
            'Empresa não atribuída a este publicador.'
        );

        // Carrega token ML e implementacao em uma única chamada (evita N+1)
        $mlbEmpresa->loadMissing(['company.mlToken', 'implementacao']);

        return Inertia::render('Mlb/AnunciarML', [
            'empresa' => [
                'id'         => $mlbEmpresa->id,
                'nome'       => $mlbEmpresa->nome,
                'company_id' => $mlbEmpresa->company_id,
                'tem_token'  => $mlbEmpresa->company_id !== null
                    && $mlbEmpresa->company?->mlToken !== null,
            ],
            'rascunhos' => MlAnuncioRascunho::where('mlb_empresa_id', $mlbEmpresa->id)
                ->latest()
                ->limit(50)
                ->get(['id', 'company_id', 'mlb_empresa_id', 'user_id', 'status',
                       'category_id', 'ml_item_id', 'updated_at', 'sku_origem', 'listing_tier']),
            // DRAFT-01: produtos do cliente lidos de mlb_implementacoes.dados
            'produtos'  => $this->montarProdutosDoCliente($mlbEmpresa->implementacao?->dados),
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
            // SEL-07: mlb_empresa_id obrigatório — empresa fixada na criação
            'mlb_empresa_id' => ['required', 'integer', 'exists:mlb_empresas,id'],
            'category_id'    => ['nullable', 'string', 'max:20'],
            'payload'        => ['nullable', 'array'],
        ]);

        $mlbEmpresa = MlbEmpresa::findOrFail($dados['mlb_empresa_id']);

        // T-75-06: SEL-04 — empresa deve pertencer ao publicador autenticado ou usuário é admin
        abort_unless(
            $request->user()->isAdmin() || $mlbEmpresa->responsavel_id === $request->user()->id,
            403,
            'Empresa não atribuída a este publicador.'
        );

        // company_id derivado da empresa; user_id do publicador autenticado (não do cliente)
        $rascunho = MlAnuncioRascunho::create([
            'mlb_empresa_id' => $mlbEmpresa->id,
            'company_id'     => $mlbEmpresa->company_id,
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
        ]);

        $rascunho->update([
            'category_id' => array_key_exists('category_id', $dados) ? $dados['category_id'] : $rascunho->category_id,
            'payload'     => $dados['payload'] ?? $rascunho->payload,
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
    public function rascunhoPorProduto(Request $request, MlbEmpresa $mlbEmpresa): JsonResponse
    {
        // T-76-01: double-check idêntico ao wizard() — empresa deve pertencer ao publicador ou usuário é admin
        abort_unless(
            $request->user()->isAdmin() || $mlbEmpresa->responsavel_id === $request->user()->id,
            403,
            'Empresa não atribuída a este publicador.'
        );

        // T-76-03: valida apenas sku e tier — company_id/user_id derivados server-side (T-76-02)
        $dados = $request->validate([
            'sku'  => ['required', 'string', 'max:100'],
            'tier' => ['nullable', 'string', 'in:classico,premium'],
        ]);

        $mlbEmpresa->loadMissing('implementacao');

        // Monta a lista de produtos com preços e busca o produto solicitado por SKU
        $produtos = $this->montarProdutosDoCliente($mlbEmpresa->implementacao?->dados);
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
            'mlb_empresa_id' => $mlbEmpresa->id,
            'company_id'     => $mlbEmpresa->company_id,
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
        return response()->json($this->meta->preverCategoria((string) $request->query('q', '')));
    }

    /** Detalhe da categoria + atributos (formulário dinâmico). */
    public function atributos(string $categoryId)
    {
        return response()->json([
            'categoria' => $this->meta->categoria($categoryId),
            'atributos' => $this->meta->atributos($categoryId),
        ]);
    }

    /** Tipos de anúncio do site (clássico, premium, grátis...). */
    public function tiposAnuncio()
    {
        return response()->json($this->meta->tiposDeAnuncio());
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
     * Retorna a coleção de empresas MLB com estado do token ML serializado.
     *
     * Escopo imposto NA QUERY DO BANCO (não em PHP pós-busca — Armadilha 2):
     * - Publicador: só empresas onde responsavel_id === seu id
     * - Admin: todas as empresas
     *
     * Empresa com company_id=null ou sem MlToken aparece com tem_token=false,
     * mas NÃO é filtrada fora da lista (SEL-06 — card de "sem conta ML").
     *
     * @return Collection<int, array{id: int, nome: string, company_id: int|null, tem_token: bool, token_expirado: bool, rascunhos_abertos: int}>
     */
    private function empresas(Request $request): Collection
    {
        // SEL-02: escopo por responsavel_id via query scope reutilizável (filtro no banco).
        // Gate atual role:admin mantém o filtro dormant (todo acessante é admin); o scope
        // já vale quando o gate abrir para permission:mlb.anunciar.
        return MlbEmpresa::with(['company.mlToken'])
            ->visiveisPara($request->user())
            ->orderBy('nome')
            ->get()
            ->map(function ($e) {
                // Contagem de rascunhos em aberto (rascunho, validado ou erro) para este empresa
                $abertos = MlAnuncioRascunho::where('mlb_empresa_id', $e->id)
                    ->whereIn('status', [
                        MlAnuncioRascunho::STATUS_RASCUNHO,
                        MlAnuncioRascunho::STATUS_VALIDADO,
                        MlAnuncioRascunho::STATUS_ERRO,
                    ])
                    ->count();

                return [
                    'id'              => $e->id,
                    'nome'            => $e->nome,
                    'company_id'      => $e->company_id,
                    // T-75-03: expõe apenas booleans — access_token permanece hidden
                    'tem_token'       => $e->company_id !== null && $e->company?->mlToken !== null,
                    'token_expirado'  => $e->company?->mlToken?->isExpired() ?? false,
                    'rascunhos_abertos' => (int) $abertos,
                ];
            });
    }
}
