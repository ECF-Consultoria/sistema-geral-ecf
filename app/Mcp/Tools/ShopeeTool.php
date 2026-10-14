<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Mcp\Shopee\LeitorShopee;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * `shopee` — tudo o que a API da Shopee entrega das lojas conectadas, ao vivo.
 *
 * Pedido do usuário (14/10/2026): "preciso de tudo que dá pra puxar da API da
 * Shopee no MCP". Decisões dele: consulta AO VIVO (sem tabela nova) e acesso
 * de qualquer usuário logado — a mesma régua do /dashboard/shopee, que mostra
 * todas as lojas conectadas a todos.
 *
 * Uma ferramenta só, com `dado` escolhendo o recorte (como `ler_tela` faz com
 * `campo`): os dados prontos cobrem o que a equipe pergunta, e `dado: "api"`
 * abre qualquer outro endpoint de LEITURA. A lógica fica em
 * {@see LeitorShopee}; a chamada (app ERP × ADS, erro legível) em
 * {@see \App\Mcp\Shopee\ChamadaShopee}.
 */
#[Name('shopee')]
#[Title('Dados da Shopee (API ao vivo)')]
#[Description(<<<'TXT'
Dados das lojas com Shopee conectada no Admin, consultados AO VIVO na API da Shopee (Open Platform v2). Comece com dado "lojas" (sem argumentos) para ver as lojas conectadas e o que cada dado traz.
Dados: lojas · resumo (faturamento, pedidos, itens, ticket e ADS/TACoS por período, do banco do Admin — mesma fonte do /dashboard/shopee; sem "loja" soma todas) · loja · saude (saúde da conta, penalidades, atrasos) · produtos (preço, estoque, vendas, visualizações, curtidas, nota; "busca" e "ordenar") · produto (item_id: variações, promoção, diagnóstico) · avaliacoes · pedidos (até 15 dias) · pedido (order_sn + repasse, comissão, taxas) · vendas (até 31 dias: pedido feito × produto pago, compradores, recompra) · devolucoes / devolucao · repasses · carteira (extrato) · promocoes · ads (gasto, ROAS, ACoS, TACoS, saldo; últimos 6 meses) · ads_campanhas · api (qualquer endpoint de leitura; sem "caminho" lista os conhecidos).
A API da Shopee NÃO tem o funil de tráfego do Seller Centre: visitantes, visualizações por período, taxa de rejeição, carrinho e taxas de conversão não existem por aqui — diga isso em vez de estimar.
Datas em AAAA-MM-DD, horário de Brasília; valores em R$. "loja" aceita o ID da empresa no Admin, o nome (ou parte) ou o shop_id. Quando vier "proximo_cursor", repita com o mesmo filtro e esse cursor.
TXT)]
class ShopeeTool extends FerramentaEcf
{
    public const DADOS = [
        'lojas', 'resumo', 'loja', 'saude', 'produtos', 'produto', 'avaliacoes', 'pedidos', 'pedido',
        'vendas', 'devolucoes', 'devolucao', 'repasses', 'carteira', 'promocoes', 'ads', 'ads_campanhas', 'api',
    ];

    /** Argumentos de texto repassados ao leitor como vieram. */
    private const TEXTOS = [
        'de', 'ate', 'status', 'item_id', 'pedido', 'devolucao', 'busca', 'ordenar',
        'tipo', 'situacao', 'campo_data', 'caminho', 'metodo', 'cursor',
    ];

    protected function podeUsar(User $usuario): bool
    {
        // Mesma régua do /dashboard/shopee: só login (decisão de 14/10/2026).
        return true;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dado' => $schema->string()
                ->enum(self::DADOS)
                ->description('O que consultar. Padrão: "lojas" (lista as lojas conectadas e o que cada dado traz).'),
            'loja' => $schema->string()
                ->description('A loja: ID da empresa no Admin, nome (ou parte dele) ou shop_id da Shopee. Obrigatório em todos os dados menos "lojas"; em "resumo", sem loja soma todas.'),
            'de' => $schema->string()
                ->description('Início do período, AAAA-MM-DD (horário de Brasília). Padrão: dia 1º do mês em resumo/vendas; 7 dias atrás nos demais.'),
            'ate' => $schema->string()
                ->description('Fim do período, AAAA-MM-DD. Padrão: hoje.'),
            'status' => $schema->string()
                ->description('produtos: NORMAL (padrão), UNLIST, BANNED, REVIEWING, SELLER_DELETE, SHOPEE_DELETE. pedidos: UNPAID, READY_TO_SHIP, PROCESSED, SHIPPED, TO_CONFIRM_RECEIVE, IN_CANCEL, CANCELLED, TO_RETURN, COMPLETED. devolucoes: REQUESTED, ACCEPTED, CANCELLED, JUDGING, CLOSED, PROCESSING, SELLER_DISPUTE.'),
            'item_id' => $schema->string()
                ->description('ID do anúncio na Shopee (número) — obrigatório em "produto"; opcional em "avaliacoes".'),
            'pedido' => $schema->string()
                ->description('order_sn da Shopee — obrigatório em "pedido".'),
            'devolucao' => $schema->string()
                ->description('return_sn da Shopee — obrigatório em "devolucao".'),
            'busca' => $schema->string()
                ->description('produtos: filtra por parte do nome ou do SKU.'),
            'ordenar' => $schema->string()
                ->enum(LeitorShopee::ORDENACOES)
                ->description('produtos: ranqueia do maior para o menor (vendas, visualizacoes, curtidas, nota, avaliacoes, estoque, preco).'),
            'tipo' => $schema->string()
                ->enum(LeitorShopee::TIPOS_PROMOCAO)
                ->description('promocoes: descontos, cupons, combos, leve_junto, relampago ou todas (padrão).'),
            'situacao' => $schema->string()
                ->enum(LeitorShopee::SITUACOES)
                ->description('promocoes: andamento (padrão), agendadas, encerradas ou todas.'),
            'campo_data' => $schema->string()
                ->enum(['criacao', 'atualizacao'])
                ->description('pedidos: o período vale pela data de criação (padrão) ou de atualização do pedido.'),
            'caminho' => $schema->string()
                ->description('api: endpoint de leitura da Shopee, ex.: /api/v2/logistics/get_tracking_info (também aceita v2.logistics.get_tracking_info).'),
            'parametros' => $schema->object()
                ->description('api: parâmetros do endpoint, ex.: {"order_sn": "2410140ABCDEF"}. Lista em GET vai como texto separado por vírgula.'),
            'metodo' => $schema->string()
                ->enum(['GET', 'POST'])
                ->description('api: GET (padrão) ou POST — os endpoints de lote da Shopee (ex.: get_escrow_detail_batch) exigem POST.'),
            ...$this->schemaPaginacao($schema),
        ];
    }

    protected function consultar(Request $request, User $usuario): array
    {
        $dado = $this->texto($request, 'dado') ?? 'lojas';
        if (! in_array($dado, self::DADOS, true)) {
            throw new ErroDaFerramenta('Dado inválido: use '.implode(', ', self::DADOS).'.');
        }

        $leitor = app(LeitorShopee::class);
        $a      = $this->argumentos($request);
        $filtro = $this->texto($request, 'loja');
        $loja   = fn (): Company => $leitor->resolverLoja($filtro);

        return match ($dado) {
            'lojas'         => $leitor->lojas(),
            'resumo'        => $leitor->resumo($filtro === null ? null : $loja(), $a),
            'api'           => $leitor->api($filtro === null ? null : $loja(), $a),
            'loja'          => $leitor->loja($loja()),
            'saude'         => $leitor->saude($loja()),
            'produtos'      => $leitor->produtos($loja(), $a),
            'produto'       => $leitor->produto($loja(), $a),
            'avaliacoes'    => $leitor->avaliacoes($loja(), $a),
            'pedidos'       => $leitor->pedidos($loja(), $a),
            'pedido'        => $leitor->pedido($loja(), $a),
            'vendas'        => $leitor->vendas($loja(), $a),
            'devolucoes'    => $leitor->devolucoes($loja(), $a),
            'devolucao'     => $leitor->devolucao($loja(), $a),
            'repasses'      => $leitor->repasses($loja(), $a),
            'carteira'      => $leitor->carteira($loja(), $a),
            'promocoes'     => $leitor->promocoes($loja(), $a),
            'ads'           => $leitor->ads($loja(), $a),
            'ads_campanhas' => $leitor->adsCampanhas($loja(), $a),
        };
    }

    /**
     * Resposta da Shopee pode ser grande (cadastro de anúncio, lista de
     * pedidos): vai só como texto, sem o `structuredContent` que dobraria o
     * tamanho — como o `ler_tela`.
     */
    protected function responder(array $dados): Response|ResponseFactory
    {
        return Response::json($dados);
    }

    /** @return array<string, mixed> */
    private function argumentos(Request $request): array
    {
        $a = [];
        foreach (self::TEXTOS as $chave) {
            $a[$chave] = $this->texto($request, $chave);
        }

        $a['limite']     = $this->inteiro($request, 'limite');
        $a['parametros'] = $this->objeto($request, 'parametros');

        return $a;
    }

    /** @return array<string, mixed> */
    private function objeto(Request $request, string $chave): array
    {
        $valor = $request->get($chave);
        if ($valor === null || $valor === '') {
            return [];
        }
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }
        if (is_object($valor)) {
            $valor = (array) $valor;
        }
        if (! is_array($valor)) {
            throw new ErroDaFerramenta("\"{$chave}\" precisa ser um objeto, ex.: {\"order_sn\": \"2410140ABCDEF\"}.");
        }

        return $valor;
    }
}
