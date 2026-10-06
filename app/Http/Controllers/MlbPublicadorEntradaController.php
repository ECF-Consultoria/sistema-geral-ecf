<?php

namespace App\Http\Controllers;

use App\Models\MlAnuncioRascunho;
use App\Models\PubProduto;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Creative\CreativePermissao;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Publicador\PublicadorSincronizaPortalService;
use App\Support\Publicador\ContasLiberadas;
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

    public function __construct(private ProgramasPublicadorService $programas) {}

    public function index(Request $request)
    {
        $programa = (string) $request->query('programa', 'polos');
        if (! in_array($programa, ProgramasPublicadorService::PROGRAMAS, true)) {
            $programa = 'polos';
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

    /** Tela B: produtos da empresa (do Portal e cadastrados aqui) + abas irmãs (D23). */
    public function produtos(Request $request, string $conta, CreativeEngineAtivo $creativeAtivo, CreativePermissao $creativePermissao)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        if ($alvo['chave'] !== $conta) {
            return redirect()->route('mlb.anuncios.publicador.produtos', ['conta' => $alvo['chave']]);
        }

        $empresa = $this->programas->empresaParaTela($alvo);
        $produtos = $this->programas->produtosParaTela($alvo['mlb_empresa'], $alvo['company']);

        $contagens = ['todos' => count($produtos), 'rascunho' => 0, 'conferidos' => 0, 'publicados' => 0, 'com_problema' => 0];
        foreach ($produtos as $p) {
            match ($p['status']['chave']) {
                'pronto' => $contagens['conferidos']++,
                'publicado', 'parcial' => $contagens['publicados']++,
                'erro' => $contagens['com_problema']++,
                default => $contagens['rascunho']++,
            };
        }

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

    /** "Sincronizar do Portal" (D16): só acrescenta produtos das ofertas novas. */
    public function sincronizar(string $conta, PublicadorSincronizaPortalService $sincroniza)
    {
        $alvo = $this->programas->resolver($conta);
        abort_if($alvo === null, 404);

        $company = $alvo['company'];
        if ($company === null || $this->programas->situacaoPortal($company)['situacao'] === 'sem_portal') {
            return response()->json(['message' => 'Esta empresa não está ligada ao Portal do Cliente.'], 422);
        }

        $r = $sincroniza->sincronizar($alvo['mlb_empresa'], $company);

        return response()->json([
            'criados' => $r['criados'],
            'ids' => $r['ids'],
            'mensagem' => $r['criados'] > 0
                ? ($r['criados'] === 1 ? '1 produto novo do Portal.' : $r['criados'].' produtos novos do Portal.')
                : 'Nada novo no Portal.',
            'portal' => $this->programas->situacaoPortal($company),
        ]);
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
}
