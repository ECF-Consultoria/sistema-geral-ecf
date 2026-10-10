<?php

namespace App\Http\Controllers;

use App\Models\PubAlavancaEscrita;
use App\Models\PubProduto;
use App\Models\PubTarefa;
use App\Services\Publicador\Alavancas\AlertasAlavancas;
use App\Services\Publicador\Alavancas\AnaliseAlavancasService;
use App\Services\Publicador\Alavancas\AtacadoLeitura;
use App\Services\Publicador\Alavancas\ContaAlavanca;
use App\Services\Publicador\Alavancas\ContextoAlavancas;
use App\Services\Publicador\Alavancas\CuponsLeitura;
use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\PanoramaService;
use App\Services\Publicador\Alavancas\ProdutosDaContaService;
use App\Services\Publicador\Alavancas\PromocoesLeitura;
use App\Services\Publicador\Alavancas\PublicidadeLeitura;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\RegraViolada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Fase 166 — Alavancas no Publicador: página e leituras JSON. Só admins — o grupo
 * de rotas aplica role:admin (D-06). A conta vem SEMPRE do servidor (resolver),
 * nunca do corpo. Leitura não é travada (D-03); escrita é do MlbAlavancasEscritaController.
 */
class MlbAlavancasController extends Controller
{
    private const POR_PAGINA_HISTORICO = 20;

    private const SEM_BUSINESS = 'O Mercado Livre libera o preço por quantidade por convite; esta conta ainda não tem essa liberação.';

    public function __construct(
        private ProgramasPublicadorService $programas,
        private ContextoAlavancas $contexto,
    ) {}

    // ═══ Página ═══

    public function index(Request $request, string $conta)
    {
        // 09/10/2026 — a tarefa pós-publicação abre aqui com `?aba=promocoes&item=MLB…`: o anúncio vira o
        // painel do topo. Só o formato é conferido; a leitura do item segue pelas rotas por item (da conta).
        $item = preg_match('/^MLB\d{1,17}$/D', (string) $request->query('item', '')) === 1 ? (string) $request->query('item') : null;
        $aba = in_array($request->query('aba'), ['promocoes', 'cupons', 'publicidade', 'atacado'], true) ? (string) $request->query('aba') : null;

        $alvo = $this->alvo($conta);
        if ($alvo['chave'] !== $conta) {
            // A chave canônica não perde o anúncio nem a aba pedidos (o link da fila usa `company-N`).
            return redirect()->route('mlb.anuncios.publicador.alavancas.index', array_filter(['conta' => $alvo['chave'], 'aba' => $aba, 'item' => $item]));
        }

        $ancora = PubProduto::ancoraComToken($alvo['mlb_empresa'], $alvo['company']);
        $temConta = $this->contexto->daTela($alvo) !== null;
        $liberada = AlavancasLiberadas::libera($ancora);

        return Inertia::render('Mlb/Publicador/Alavancas', [
            'empresa' => $this->programas->empresaParaTela($alvo),
            'alavancas' => [
                'liberada' => $liberada,
                'motivo' => $liberada ? null : AlavancasLiberadas::MOTIVO,
                'tem_conta' => $temConta,
                'limites' => [
                    'itens_por_lote' => (int) config('publicador.alavancas.limites.itens_por_lote', 50),
                    'itens_por_analise' => (int) config('publicador.alavancas.limites.itens_por_analise', 10),
                ],
                'alertas' => (array) config('publicador.alavancas.alertas', []),
                'item' => $item,
                // Contagem da aba Alavancas: publicados desta conta aguardando as alavancas.
                'tarefas_abertas' => PubTarefa::abertasDaConta($alvo['mlb_empresa'], $alvo['company']),
            ],
        ]);
    }

    // ═══ Leituras no ML ═══

    public function panorama(Request $request, string $conta, PanoramaService $panorama): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => $panorama->montar($c, $request->boolean('atualizar')));
    }

    public function promocoes(Request $request, string $conta, PromocoesLeitura $promocoes): JsonResponse
    {
        return $this->comConta($conta, function (ContaAlavanca $c) use ($request, $promocoes) {
            $lista = $promocoes->convites($c, $request->boolean('atualizar'));
            $lista['itens'] = array_map(fn (array $convite) => [...$convite, 'alertas' => AlertasAlavancas::doConvite($convite)], $lista['itens']);

            return $lista;
        });
    }

    public function itensDaPromocao(Request $request, string $conta, string $promocao, PromocoesLeitura $promocoes): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(TiposDePromocao::TODOS)],
            'cursor' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['candidate', 'pending', 'started'])],
            'status_item' => ['nullable', Rule::in(['active', 'paused'])],
        ]);
        $filtros = array_filter(['status' => $dados['status'] ?? null, 'status_item' => $dados['status_item'] ?? null]);

        return $this->comConta($conta, fn (ContaAlavanca $c) => $promocoes->itensDaPromocao($c, $promocao, $dados['tipo'], $dados['cursor'] ?? null, $filtros));
    }

    public function produtos(Request $request, string $conta, ProdutosDaContaService $produtos): JsonResponse
    {
        $dados = $request->validate([
            'busca' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->comConta($conta, fn (ContaAlavanca $c) => $produtos->listar($c, $dados['busca'] ?? null, (int) ($dados['pagina'] ?? 1)));
    }

    public function promocoesDoItem(string $conta, string $item, PromocoesLeitura $promocoes): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => ['itens' => $promocoes->promocoesDoItem($c, $item)]);
    }

    public function analise(Request $request, string $conta, AnaliseAlavancasService $analise): JsonResponse
    {
        $max = (int) config('publicador.alavancas.limites.itens_por_analise', 10);
        $dados = $request->validate([
            'itens' => ['required', 'array', 'min:1', "max:{$max}"],
            'itens.*.item_id' => ['required', 'regex:/^MLB\d{1,17}$/D'],
            'itens.*.preco_promocao' => ['nullable', 'numeric', 'gt:0'],
            'itens.*.promotion_type' => ['nullable', Rule::in(TiposDePromocao::TODOS)],
            'itens.*.meli_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.seller_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'itens.*.estoque_minimo' => ['nullable', 'integer', 'min:0'],
            'itens.*.boost' => ['nullable', 'array'],
        ]);

        return $this->comConta($conta, fn (ContaAlavanca $c) => $analise->analisar($c, array_values($dados['itens'])));
    }

    public function cupons(Request $request, string $conta, CuponsLeitura $cupons): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => $cupons->cupons($c, $request->boolean('atualizar')));
    }

    public function exclusao(string $conta, PromocoesLeitura $promocoes): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => $promocoes->exclusaoDaConta($c));
    }

    public function exclusaoDoItem(string $conta, string $item, PromocoesLeitura $promocoes): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => $promocoes->exclusaoDoItem($c, $item));
    }

    // ═══ Publicidade e atacado ═══

    public function publicidade(Request $request, string $conta, PublicidadeLeitura $publicidade): JsonResponse
    {
        $dados = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // Padrão: os últimos 30 dias no fuso de São Paulo (as duas pontas contam).
        $ate = $dados['ate'] ?? DatasDoMl::hoje()->format('Y-m-d');
        $de = $dados['de'] ?? DatasDoMl::ler($ate)->subDays(29)->format('Y-m-d');

        return $this->comConta($conta, function (ContaAlavanca $c) use ($publicidade, $de, $ate) {
            $publicidade->janela($de, $ate);

            $anunciante = $publicidade->anunciante($c);
            if ($anunciante['indisponivel'] !== null) {
                return ['indisponivel' => $anunciante['indisponivel']];
            }

            $campanhas = $publicidade->campanhas($c, $de, $ate);
            if (isset($campanhas['indisponivel'])) {
                return ['indisponivel' => $campanhas['indisponivel']];
            }

            return [
                'de' => $de,
                'ate' => $ate,
                'anunciante' => $anunciante,
                'campanhas' => $campanhas['campanhas'],
                'resumo' => $campanhas['resumo'],
                'bonificacoes' => $publicidade->bonificacoes($c),
            ];
        });
    }

    public function adGroups(Request $request, string $conta, PublicidadeLeitura $publicidade): JsonResponse
    {
        $dados = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
            'itens' => ['nullable', 'string', 'max:500'],
        ]);
        $ate = $dados['ate'] ?? DatasDoMl::hoje()->format('Y-m-d');
        $de = $dados['de'] ?? DatasDoMl::ler($ate)->subDays(29)->format('Y-m-d');

        $itens = array_values(array_filter(array_map('trim', explode(',', (string) ($dados['itens'] ?? ''))), fn (string $i) => $i !== ''));
        foreach ($itens as $id) {
            if (! preg_match('/^MLB\d{1,17}$/D', $id)) {
                throw ValidationException::withMessages(['itens' => 'Use só códigos de anúncio, como MLB123, separados por vírgula.']);
            }
        }
        if (count($itens) > 20) {
            throw ValidationException::withMessages(['itens' => 'Peça até 20 anúncios por vez.']);
        }

        return $this->comConta($conta, fn (ContaAlavanca $c) => $publicidade->adGroups($c, $de, $ate, $itens));
    }

    public function atacado(string $conta, AtacadoLeitura $atacado): JsonResponse
    {
        return $this->comConta($conta, function (ContaAlavanca $c) use ($atacado) {
            $business = $atacado->habilitado($c);

            return ['business' => $business, 'explicacao' => $business ? null : self::SEM_BUSINESS];
        });
    }

    public function atacadoDoItem(string $conta, string $item, AtacadoLeitura $atacado): JsonResponse
    {
        return $this->comConta($conta, fn (ContaAlavanca $c) => $atacado->faixas($c, $item));
    }

    public function recomendacoes(Request $request, string $conta, string $item, AtacadoLeitura $atacado): JsonResponse
    {
        $dados = $request->validate([
            'quantidades' => ['required', 'array', 'min:1', 'max:5'],
            'quantidades.*' => ['integer', 'min:1', 'max:100'],
            'preco' => ['required', 'numeric', 'gt:0'],
        ]);

        // ALAV-LIB (conta não liberada) vira 403 no comConta, sem nenhuma chamada ao ML.
        return $this->comConta($conta, fn (ContaAlavanca $c) => $atacado->recomendacoes(
            $c, $item, array_map('intval', array_values($dados['quantidades'])), (float) $dados['preco'],
        ));
    }

    // ═══ Histórico (D-05, AL166-07) — não depende do ML, abre mesmo sem token ═══

    public function historico(Request $request, string $conta): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $dados = $request->validate([
            'alavanca' => ['nullable', Rule::in(PubAlavancaEscrita::ALAVANCAS)],
            'resultado' => ['nullable', Rule::in(PubAlavancaEscrita::RESULTADOS)],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        // As âncoras vêm do resolver (IDOR: nunca filtrar por parâmetro do pedido).
        $consulta = PubAlavancaEscrita::query()->daEmpresa($alvo['mlb_empresa'], $alvo['company'])
            ->when($dados['alavanca'] ?? null, fn ($q, $v) => $q->where('alavanca', $v))
            ->when($dados['resultado'] ?? null, fn ($q, $v) => $q->where('resultado', $v));

        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / self::POR_PAGINA_HISTORICO));
        $pagina = min((int) ($dados['pagina'] ?? 1), $ultima);

        $linhas = $consulta->orderByDesc('id')
            ->forPage($pagina, self::POR_PAGINA_HISTORICO)
            ->get()
            ->map(fn (PubAlavancaEscrita $l) => [
                'id' => $l->id,
                'quando' => $l->created_at?->toIso8601String(),
                'enviado_em' => $l->enviado_em?->toIso8601String(),
                'ator_nome' => $l->ator_nome,
                'alavanca' => $l->alavanca,
                'acao' => $l->acao,
                'promotion_type' => $l->promotion_type,
                'promotion_id' => $l->promotion_id,
                'item_id' => $l->item_id,
                'resultado' => $l->resultado,
                'http_status' => $l->http_status,
                'erro_codigo' => $l->erro_codigo,
                'mensagem' => $l->mensagem,
                'lote_uuid' => $l->lote_uuid,
            ])->values();

        return response()->json([
            'linhas' => $linhas,
            'paginacao' => ['pagina' => $pagina, 'por_pagina' => self::POR_PAGINA_HISTORICO, 'total' => $total, 'ultima' => $ultima],
        ]);
    }

    public function historicoMostrar(string $conta, int $escrita): JsonResponse
    {
        $alvo = $this->alvo($conta);

        $l = PubAlavancaEscrita::query()->daEmpresa($alvo['mlb_empresa'], $alvo['company'])->whereKey($escrita)->first();
        abort_if($l === null, 404);

        return response()->json([
            'id' => $l->id,
            'quando' => $l->created_at?->toIso8601String(),
            'enviado_em' => $l->enviado_em?->toIso8601String(),
            'concluido_em' => $l->concluido_em?->toIso8601String(),
            'ator_nome' => $l->ator_nome,
            'alavanca' => $l->alavanca,
            'acao' => $l->acao,
            'promotion_type' => $l->promotion_type,
            'promotion_id' => $l->promotion_id,
            'item_id' => $l->item_id,
            'resultado' => $l->resultado,
            'http_status' => $l->http_status,
            'erro_codigo' => $l->erro_codigo,
            'mensagem' => $l->mensagem,
            'lote_uuid' => $l->lote_uuid,
            'metodo' => $l->metodo,
            'caminho' => $l->caminho,
            'conta_chave' => $l->conta_chave,
            'ml_seller_id' => $l->ml_seller_id,
            'payload' => $l->payload,
            'resumo' => $l->resumo,
            'resposta' => $l->resposta,
        ]);
    }

    // ═══ Internos ═══

    /** @return array{mlb_empresa: ?\App\Models\MlbEmpresa, company: ?\App\Models\Company, programa: string, chave: string} */
    private function alvo(string $conta): array
    {
        $alvo = $this->contexto->resolver($conta);
        abort_if($alvo === null, 404);

        return $alvo;
    }

    /**
     * Resolve a conta e roda a leitura. JSON nunca redireciona: usa o alvo resolvido.
     * Conta sem token = 409 V-ACC-01 sem HTTP; falha inesperada = 502 com texto fixo (nunca 500).
     */
    private function comConta(string $conta, \Closure $fn): JsonResponse
    {
        $alvo = $this->alvo($conta);
        $ctx = $this->contexto->daTela($alvo);
        if ($ctx === null) {
            return response()->json([
                'message' => 'A conta do Mercado Livre desta empresa precisa ser reconectada. Conecte de novo pelo Onboarding e volte aqui.',
                'regra' => 'V-ACC-01',
            ], 409);
        }

        try {
            return response()->json($fn($ctx));
        } catch (RegraViolada $e) {
            $status = in_array($e->regra, [AlavancasLiberadas::REGRA, 'V-ACC-03'], true) ? 403 : 422;

            return response()->json(['message' => $e->getMessage(), 'regra' => $e->regra], $status);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning("[Alavancas] leitura {$alvo['chave']} (".request()->route()?->getName().'): '.$e->getMessage());

            return response()->json(['message' => 'Não deu para ler agora. Tente de novo em instantes.'], 502);
        }
    }
}
