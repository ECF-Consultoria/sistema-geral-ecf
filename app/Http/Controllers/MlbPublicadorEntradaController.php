<?php

namespace App\Http\Controllers;

use App\Jobs\Publicador\PreencherRascunhoDoPortalJob;
use App\Models\CreativeIdentidade;
use App\Models\MlAnuncioRascunho;
use App\Models\PubProduto;
use App\Models\PubTarefa;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\AcervoTriagemService;
use App\Services\Publicador\AcoesDaContaService;
use App\Services\Publicador\AdiamentoDeAcoes;
use App\Services\Publicador\CabecalhoPublicadorService;
use App\Services\Publicador\CockpitDaContaService;
use App\Services\Publicador\ExcluirProdutoService;
use App\Services\Publicador\Fila\FilaPublicacaoService;
use App\Services\Publicador\JornadaDosProdutosService;
use App\Services\Publicador\NovaPublicacaoService;
use App\Services\Publicador\PainelVisaoGeralService;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Services\Publicador\ResumoDoSincronizar;
use App\Support\Publicador\ContasLiberadas;
use App\Support\Publicador\NaFilaDePublicacao;
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

    /**
     * Quantas contas o dropdown de empresa do header (HEAD-05) recebe de uma vez.
     * Acima disso o cliente completa pela rota de busca que JÁ existe
     * (`publicador.empresas-busca`) — o endpoint do header não ganha parâmetro de
     * busca para não virar um segundo caminho para a mesma coisa.
     */
    private const HEADER_LIMITE = 200;

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

    /**
     * JSON do dropdown de empresa do header global (Fase 177, HEAD-05): as contas dos
     * 3 programas no shape leve de `ProgramasPublicadorService::contasLeves()`, mais o
     * TOTAL REAL — é o "N empresas sob gestão" do rodapé do dropdown.
     *
     * ⚠️ **Sem nenhum parâmetro de entrada, de propósito (T-177-08).** O escopo é o do
     * usuário autenticado, resolvido no servidor, e a rota vive no grupo
     * `['auth','verified','role:admin']`. Não aceitar `company`/`conta` é o que torna
     * IDOR por parâmetro estruturalmente impossível aqui — não é promessa de
     * comentário. Quem precisar filtrar usa `publicador.empresas-busca`.
     *
     * ⚠️ NUNCA devolve `access_token`/`refresh_token` nem o objeto `MlToken` — só a
     * string de estado `token` (`ativo|expirado|sem_token`), o mesmo recorte de campos
     * que `buscaEmpresas()` já usa em produção (T-177-07).
     */
    public function empresasDoHeader(): JsonResponse
    {
        $contas = $this->programas->contasLeves();

        return response()->json([
            'total' => $contas->count(),
            'limite' => self::HEADER_LIMITE,
            'empresas' => $contas->take(self::HEADER_LIMITE)->values(),
        ]);
    }

    /**
     * JSON do modal "Nova publicação" (Fase 178, NOVA-08): os três grupos do
     * próximo passo de cada produto da conta, montados por
     * `NovaPublicacaoService::grupos()`.
     *
     * ⚠️ **Por que endpoint e não prop.** O header global vive nas 9 páginas do
     * Publicador. Pendurar os três grupos na prop `cabecalho` faria TODA página
     * pagar `produtosParaTela()` — a consulta mais pesada do módulo — em todo
     * request, inclusive para quem nunca abre o modal. O padrão já estabelecido
     * nesta milestone é o do seletor de empresa (177-03/177-06): **endpoint
     * próprio, uma chamada por montagem, só quando o usuário abre**.
     *
     * ⚠️ **Nenhum parâmetro de entrada.** Sem `q`, sem `grupo`, sem `limite`: a
     * busca é LOCAL no cliente (é assim no protótipo) e qualquer parâmetro a
     * mais seria superfície nova sem necessidade — a mesma disciplina de
     * T-177-08. O único dado de entrada é `{conta}`, e ele já morre na rota
     * quando sai do padrão `(empresa|company)-[0-9]+`.
     *
     * ⚠️ **Sem redirect de normalização de chave.** `produtos()`/`visaoGeral()`
     * redirecionam quando `$alvo['chave'] !== $conta`; aqui NÃO: isto é JSON
     * consumido por `axios`, e um 302 viraria resposta estranha no cliente.
     * Segue-se com `$alvo['chave']` (a canônica), que é o que o serviço usa para
     * montar a `url` de cada item.
     */
    public function novaPublicacao(string $conta, NovaPublicacaoService $nova): JsonResponse
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        return response()->json($nova->grupos($alvo));
    }

    /** Tela B: produtos da empresa (do Portal e cadastrados aqui) + abas irmãs (D23). */
    public function produtos(Request $request, string $conta, CreativeEngineAtivo $creativeAtivo, CreativePermissao $creativePermissao, FilaPublicacaoService $filas, CabecalhoPublicadorService $cabecalhos)
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
            'abas' => [
                'company_id' => $companyId,
                // 09/10/2026 — contagem da aba Alavancas: publicados desta conta aguardando as alavancas.
                'alavancas_pendentes' => PubTarefa::abertasDaConta($alvo['mlb_empresa'], $alvo['company']),
            ],
            // Fase 177-03: prop ADITIVA do header global (HEAD-10 — contadores reais, nenhum
            // número fixo no JSX). `abas` acima FICA: as páginas antigas e os gates de fonte a
            // leem, e a troca de consumo é das plans 177-07/08.
            'cabecalho' => $cabecalhos->para($alvo, $empresa, 'produtos'),
            // 10/10/2026 — a fila de publicação em lote VIVA da conta (o aviso acima da lista), ou null.
            'fila_publicacao' => $filas->aviso($alvo['chave']),
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

        // 10/10/2026: produto agendado/publicando na fila de publicação foi CONFERIDO e vai ao ML como está — o
        // Sincronizar não escreve no rascunho dele (como o preparo pela IA). Volta a valer depois que ele
        // publicar ou sair da fila; se a equipe mudar algo, a fila manda para `precisa_revisar`.
        $naFila = NaFilaDePublicacao::dentre($r['para_preencher']);
        $r['para_preencher'] = array_values(array_filter($r['para_preencher'], fn ($id) => ! isset($naFila[(int) $id])));

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
        // Planejamento × Fase N (09/10/2026): o Combo de uma cor é variante do kit da Fase N da família;
        // sem o kit, ele espera o "Criar Fase" do produto (não vira produto avulso).
        $aguardando = (int) ($r['combos_aguardando_fase'] ?? 0);
        if ($aguardando > 0) {
            $mensagem .= $aguardando === 1
                ? ' 1 combo do Planejamento aguarda o "Criar Fase" do produto.'
                : " {$aguardando} combos do Planejamento aguardam o \"Criar Fase\" do produto.";
        }
        if ($naFila !== []) {
            $mensagem .= count($naFila) === 1
                ? ' 1 produto na fila de publicação ficou como estava.'
                : ' '.count($naFila).' produtos na fila de publicação ficaram como estavam.';
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
            'combos_aguardando_fase' => $aguardando,
            'combos_na_fase' => (int) ($r['combos_na_fase'] ?? 0),
            'combos_absorvidos' => (int) ($r['combos_absorvidos'] ?? 0),
            'na_fila_de_publicacao' => array_keys($naFila),
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

    // ═══ Excluir produtos que nunca foram publicados (10/10/2026) ═══════════

    /** O que pode sair e por que o resto fica (só leitura). A regra é do `ExcluirProdutoService`. */
    public function previaDaExclusao(Request $request, string $conta, ExcluirProdutoService $exclusao): JsonResponse
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        return response()->json($exclusao->previa($alvo, $this->produtosDaExclusao($request)));
    }

    /**
     * Exclui o que pode ser excluído (um por um, cada qual na sua transação) e devolve os motivos do que ficou.
     * Nenhum saiu: 422, com a mensagem e os motivos, e o diálogo continua aberto.
     */
    public function excluirProdutos(Request $request, string $conta, ExcluirProdutoService $exclusao): JsonResponse
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        $r = $exclusao->excluir($alvo, $this->produtosDaExclusao($request), $request->user());
        $saiu = count($r['excluidos']);
        $ficou = count($r['recusados']);

        if ($saiu === 0) {
            return response()->json([
                ...$r,
                'message' => $ficou > 0 ? 'Nenhum produto pôde ser excluído.' : 'Estes produtos não existem mais.',
            ], 422);
        }

        $mensagem = $saiu === 1 ? '1 produto excluído.' : "{$saiu} produtos excluídos.";
        if ($ficou > 0) {
            $mensagem .= $ficou === 1 ? ' 1 não pôde ser excluído.' : " {$ficou} não puderam ser excluídos.";
        }

        return response()->json([...$r, 'mensagem' => $mensagem]);
    }

    /** @return list<int> os ids pedidos; a conta vem sempre da rota, nunca do corpo */
    private function produtosDaExclusao(Request $request): array
    {
        return $request->validate([
            'produtos'   => ['required', 'array', 'min:1', 'max:'.ExcluirProdutoService::MAXIMO],
            'produtos.*' => ['integer', 'min:1'],
        ], [
            'produtos.required' => 'Escolha ao menos um produto.',
            'produtos.min'      => 'Escolha ao menos um produto.',
            'produtos.max'      => 'Dá para excluir até '.ExcluirProdutoService::MAXIMO.' produtos de uma vez.',
        ])['produtos'];
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
     *
     * ─── Fase 179, Plano 05 — o cockpit (VG-01, VG-18..VG-20) ───────────────
     *
     * As NOVE props novas (`jornada`, `acoes`, `banner`, `saude`, `publicacoesMl`,
     * `acervoImagens`, `desempenho`, `recentes`, `atividade`) são **ADITIVAS**: as
     * 14 de antes continuam todas, com o mesmo nome e o mesmo valor.
     *
     * ⚠️ **`produtosPorFase` e `quemPublicou` FICAM, de propósito.** Em
     * 12/10/2026 o usuário decidiu, informado, que os blocos "Produtos por fase" e
     * "Quem publicou" **saem da tela** (plano 179-09). A decisão é de **TELA**, e
     * o payload não é a tela: quem perde o consumidor é o **render**, não o
     * contrato. Três motivos medidos para não "limpar" isto:
     *   1. as duas custam **ZERO** consulta — `produtosPorFase()` é passthrough
     *      sobre o `$contagemProdutos` já calculado e `quemPublicou` reusa o
     *      `$publicados` que os `indicadores` já pedem;
     *   2. tirá-las exigiria cirurgia em **14 asserções** de
     *      `ListaPorFaseTest`/`VisaoGeralTest`, uma delas o
     *      `test_visao_geral_entrega_produtos_por_fase_sem_perder_nenhuma_prop`,
     *      cujo propósito inteiro é provar que o contrato não perde prop;
     *   3. `PainelVisaoGeral.jsx` fica no disco e é renderizado por 2 gates de JS.
     *
     * ⚠️ **As cinco saídas reusadas sobem para variável** (`$indicadores`,
     * `$linhas`, `$ultimasPublicacoes`, `$atividadeEquipe`, `$integracoes`) junto
     * com `$cabecalho`: cada uma alimenta uma prop antiga **e** uma nova, e
     * chamá-las duas vezes pagaria duas vezes pelas consultas mais caras da tela
     * (T-179-19). Mesma mudança que a Fase 177-03 fez ao extrair `$empresa`:
     * mesmo valor, nenhuma mudança de comportamento.
     *
     * ⚠️ Do `Request` só se lê `$request->user()` — o escopo do adiamento. A Visão
     * geral **não aceita parâmetro de entrada** (REG-06, mesma disciplina de
     * `empresasDoHeader()`).
     */
    public function visaoGeral(Request $request, string $conta, AcervoTriagemService $acervoTriagem, PainelVisaoGeralService $painel, CabecalhoPublicadorService $cabecalhos, JornadaDosProdutosService $jornadas, AcoesDaContaService $acoesDaConta, CockpitDaContaService $cockpit)
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

        // Fase 179-05: as saídas que o cockpit REUSA — cada uma serve uma prop
        // antiga E uma nova. Ver o docblock: chamar duas vezes é o que o gate de
        // N+1 pega.
        // Quick 261009-t02: `$produtos` entra para o `no_ar_por_fase` — é a
        // MESMA lista que a tela já carrega, nenhuma consulta nova.
        $indicadores = $painel->indicadores($alvo, $contagemProdutos, $produtos);
        $linhas = $painel->oQueFazerAgora($alvo, $empresa, $contagemProdutos, $triagem, $defasagem, $situacaoPortal, $produtos);
        $ultimasPublicacoes = $painel->ultimasPublicacoes($alvo);
        // Quick 261010-hdr: a "Atividade da equipe" era MOCKADA e o usuário
        // apontou isso em 10/10 — agora é a linha do tempo real das três
        // fontes já gravadas (publicou / gerou criativos / conferiu).
        $atividadeEquipe = $painel->atividadeDaEquipe($alvo);
        $integracoes = $painel->integracoes($alvo, $empresa);
        // Fase 177-03: prop ADITIVA do header global. `$defasagem` vai como 4º argumento —
        // esta action JÁ o calculou acima, e repetir a consulta seria pagar duas vezes pelo
        // mesmo número. ⚠️ Fase 179-05: subiu para variável porque a prop
        // `publicacoesMl` precisa de `$cabecalho['contadores']` — montar um segundo
        // cabeçalho dentro do `Inertia::render` pagaria duas vezes pelos contadores.
        $cabecalho = $cabecalhos->para($alvo, $empresa, 'visao-geral', $company !== null ? $defasagem : null);

        // A Jornada (o anel) e as Ações, nesta ordem: a saída da Jornada é entrada
        // das Ações — é de lá que nasce a ação `sem_publicacao` (VG-19).
        $jornada = $jornadas->jornada($alvo, $produtos);
        $acoes = $acoesDaConta->acoes($alvo, $linhas, $jornada, $produtos, $request->user()?->id);

        return Inertia::render('Mlb/Publicador/VisaoGeral', [
            'empresa' => $empresa,
            'liberada' => ContasLiberadas::libera(PubProduto::ancoraComToken($alvo['mlb_empresa'], $company)),
            'indicadores' => $indicadores,
            'oQueFazerAgora' => $linhas,
            // Quick 261009-t02: os "Alertas" da coluna lateral são a triagem que
            // já está carregada acima — reformatada, nunca recalculada.
            'alertas' => $painel->alertas($alvo, $triagem),
            'situacaoProdutos' => $painel->situacaoProdutos($contagemProdutos),
            // §7 (plano 175-08): bloco NOVO, ao lado do de cima — a spec mandava
            // substituir, mas nada que existe pode sumir (ver o docblock do
            // `produtosPorFase()`). Mesma fonte de contagem, logo os números batem
            // com a lista de Produtos por construção.
            'produtosPorFase' => $painel->produtosPorFase($contagemProdutos),
            'ultimasPublicacoes' => $ultimasPublicacoes,
            'atividadeEquipe' => $atividadeEquipe,
            'integracoes' => $integracoes,
            'identidadeResumo' => $painel->identidadeResumo($alvo),
            'quemPublicou' => [
                'equipe' => $publicados['equipe'],
                'cliente' => $publicados['cliente'],
                'origem_antiga' => $publicados['origem_antiga'],
            ],
            'abas' => [
                'company_id' => $company?->id,
                // 09/10/2026 — contagem da aba Alavancas (tarefas pós-publicação abertas da conta).
                'alavancas_pendentes' => PubTarefa::abertasDaConta($alvo['mlb_empresa'], $company),
            ],
            'cabecalho' => $cabecalho,

            // ─── Fase 179-05: as NOVE props do cockpit, todas ADITIVAS ───────
            'jornada' => $jornada,
            'acoes' => $acoes,
            'banner' => $cockpit->banner($empresa),
            // ⚠️ A coleta vem do CABEÇALHO, não do `$defasagem` cru desta action, e
            // isso é correção deliberada (Rule 2): sem Company o stub local diz
            // `nunca_coletado: false` (pré-existente, e é o que `indicadores()` lê),
            // enquanto o header diz `true` — `CabecalhoPublicadorService::para()`
            // carimba a ausência explicitamente para D23. Ler o cru aqui faria o
            // chrome dizer "nunca coletado" e a Saúde dizer "tudo conectado" sobre a
            // MESMA conta, na MESMA tela. Fonte única: o header.
            'saude' => $cockpit->saude($empresa, $integracoes, $cabecalho['integracoes']['coleta'], $company?->id),
            'publicacoesMl' => $cockpit->resumoDePublicacoes($company, $cabecalho['contadores']),
            'acervoImagens' => $cockpit->acervoDeImagens($alvo, $indicadores),
            'desempenho' => $cockpit->desempenho($alvo, $indicadores),
            'recentes' => $cockpit->recentes($ultimasPublicacoes, $produtos),
            'atividade' => $cockpit->atividade($alvo, $atividadeEquipe),
        ]);
    }

    /**
     * Fase 179, Plano 05 (VG-11) — adiar uma ação de "O que fazer agora" até
     * amanhã às 8h, só para QUEM adiou e só nesta conta.
     *
     * ⚠️ **A trava que importa é a lista FECHADA de chaves**, conferida ANTES de
     * qualquer escrita (T-179-16). `AdiamentoDeAcoes::adiar()` também se recusa a
     * gravar chave fora do mapa, mas isso é **defesa em profundidade**: ele falha
     * em **silêncio**, e um request com chave inválida tem de receber **422**, não
     * 200 com nada acontecido. A conferência é por `array_key_exists` sobre a
     * constante `AcoesDaContaService::SEVERIDADE` — nunca um `in_array` num array
     * literal reescrito aqui, que divergiria da lista do serviço na primeira
     * chave nova.
     *
     * ⚠️ Grava-se `$alvo['chave']` (a **canônica**), não o `{conta}` do request:
     * `empresa-7` e `company-3` podem ser a MESMA conta, e duas grafias gravariam
     * dois sacos de cache. Mesma lição da Fase 178-01.
     *
     * ⚠️ **Sem redirect de normalização de chave** (como as outras actions de
     * escrita — ver o docblock de `novaPublicacao()`): `back()` devolve o usuário
     * para onde ele estava, e a recarga do Inertia já traz `acoes` sem a ação.
     * O servidor continua sendo a fonte da verdade — o cliente não "esconde" nada.
     *
     * ⚠️ **Sem flash e sem `Log::`**: o adiamento é silencioso por desenho (o
     * protótipo só esconde a linha) e é ação de rotina, por tela — logá-lo
     * encheria o log sem informação.
     *
     * ⚠️ **Nenhuma migration:** o adiamento vive no cache, por decisão da wave 1.
     * Perder um só faz a ação **reaparecer** — o lado benigno da falha.
     */
    public function adiarAcao(Request $request, string $conta, AdiamentoDeAcoes $adiamento)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        $dados = $request->validate(['chave' => ['required', 'string', 'max:60']]);

        abort_unless(
            array_key_exists($dados['chave'], AcoesDaContaService::SEVERIDADE),
            422,
            'Ação desconhecida.'
        );

        $userId = $request->user()?->id;
        abort_if($userId === null, 403);

        $adiamento->adiar((int) $userId, (string) $alvo['chave'], $dados['chave']);

        return back();
    }

    /**
     * Fase 173, Plano 04 (CONF-02/03) — "Configurações da conta": identidade visual
     * (texto cru, sem resumo), conexões (somente leitura) e programa/responsável.
     * Nada novo no banco.
     */
    public function configuracoes(string $conta, PainelVisaoGeralService $painel, CabecalhoPublicadorService $cabecalhos)
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
            // Fase 177-03: prop ADITIVA do header global. Configurações sai das abas (HEAD-08),
            // mas o header continua no topo — por isso a prop existe aqui também.
            'cabecalho' => $cabecalhos->para($alvo, $empresa, 'configuracoes'),
        ]);
    }
}
