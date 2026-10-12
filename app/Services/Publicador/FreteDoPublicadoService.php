<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf;
use App\Support\Publicador\ConferenciaDeFrete;
use App\Support\Publicador\ContasLiberadas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A terceira conferência de frete (11/10/2026): o que o Mercado Livre cobra do anúncio JÁ PUBLICADO, contra o
 * que a Precificação do Portal usou no preço.
 *
 * O usuário: "o mais confiável é no final, quando publicar e dentro do Mercado Livre tiver o preço que
 * realmente estamos pagando; tem que bater com os dois anteriores. Senão, nós teríamos que reprecificar."
 *
 * Medido em produção nos anúncios da #459 (learnings publicador-ml §29):
 *   - `GET /users/{seller}/shipping_options/free?item_id={MLB}` devolve o custo por anúncio (`list_cost`);
 *   - ele responde também para anúncio que saiu "a combinar" (`shipping.mode` ≠ `me2`), mas ali o Mercado
 *     Livre não cobra frete nenhum — o número vem das medidas PADRÃO da categoria. Por isso o modo do anúncio
 *     é lido antes, e fora do Mercado Envios a conferência é "não se aplica".
 *
 * A referência é a linha de frete da conferência que liberou esta publicação (`respostas_ml.frete`); sem ela
 * (conferência anterior a este recurso), o que a Precificação diz agora.
 *
 * O anúncio é publicado no preço CHEIO e a Precificação cotou no preço da PROMOÇÃO. O frete muda por faixa de
 * preço: quando os dois caem em faixas diferentes, o que se compara é uma cotação na faixa da promoção com a
 * logística e as medidas do próprio anúncio; o valor no preço cheio fica guardado ao lado.
 *
 * O resultado mora em `pub_publicacao_itens.avisos.frete`. Só leitura no Mercado Livre; nada aqui desfaz nem
 * repete a publicação.
 */
class FreteDoPublicadoService
{
    private const NOMES = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    public function __construct(
        private ClienteMlPublicador $cliente,
        private DadosEfetivosService $efetivos,
    ) {}

    /**
     * @return array{conferidos: int, divergentes: int, sem_mercado_envios: int, sem_resposta: int}
     */
    public function conferir(PubPublicacao $p): array
    {
        $resumo = ['conferidos' => 0, 'divergentes' => 0, 'sem_mercado_envios' => 0, 'sem_resposta' => 0];
        $r = PubRascunho::find($p->rascunho_id);
        $produto = $r === null ? null : PubProduto::with(['mlbEmpresa.mlToken', 'company.mlToken', 'oferta'])->find($r->produto_id);
        $conta = $produto?->contaOuNula();
        $seller = (string) ($p->ator['conta']['seller'] ?? $conta?->mlToken?->ml_user_id ?? '');

        // A mesma conta que publicou, e liberada: fora disso não se lê nada (D26).
        if ($conta === null || $seller === '' || ! ContasLiberadas::libera($conta) || (string) ($conta->mlToken?->ml_user_id ?? '') !== $seller) {
            Log::info("[Publicador] frete do publicado: publicação {$p->id} sem conta liberada do mesmo vendedor — nada conferido.");

            return $resumo;
        }

        $referencias = $this->referencias($p, $r, $produto);

        foreach ($p->itens()->where('status', PubPublicacaoItem::CREATED)->whereNotNull('ml_item_id')->orderBy('id')->get() as $item) {
            $ref = $referencias['da_conferencia'][$item->listing_type_id.'|'.$item->variante_chave]
                ?? $this->referenciaDeAgora($item, $referencias);
            $frete = $this->doItem($conta, $seller, $item, $ref);
            if ($frete === null) {
                $resumo['sem_resposta']++;

                continue;
            }

            $item->update(['avisos' => [...(array) $item->fresh()->avisos, 'frete' => $frete]]);
            match (true) {
                ! $frete['aplicavel'] => $resumo['sem_mercado_envios']++,
                in_array($frete['nivel'] ?? null, [ConferenciaDeFrete::DIFERENTE, ConferenciaDeFrete::REPRECIFICAR], true) => $resumo['divergentes']++,
                default => $resumo['conferidos']++,
            };
        }

        Log::info("[Publicador] frete do publicado: publicação {$p->id} — ".json_encode($resumo));

        return $resumo;
    }

    /**
     * O frete de cada anúncio das tarefas de uma página, numa consulta só: `tarefa_id → ml_item_id → frete`
     * (só o que a tela mostra).
     *
     * @param  Collection<int, \App\Models\PubTarefa>  $tarefas
     * @return array<int, array<string, array>>
     */
    public static function dasTarefas(Collection $tarefas): array
    {
        $porMlb = [];
        foreach ($tarefas as $t) {
            foreach ((array) $t->itens as $i) {
                if (is_array($i) && ! empty($i['ml_item_id'])) {
                    $porMlb[(string) $i['ml_item_id']] = (int) $t->id;
                }
            }
        }
        if ($porMlb === []) {
            return [];
        }

        $saida = [];
        PubPublicacaoItem::query()->whereIn('ml_item_id', array_keys($porMlb))->orderBy('id')
            ->get(['id', 'ml_item_id', 'avisos'])
            ->each(function (PubPublicacaoItem $i) use (&$saida, $porMlb) {
                $f = $i->avisos['frete'] ?? null;
                if (is_array($f)) {
                    $saida[$porMlb[(string) $i->ml_item_id]][(string) $i->ml_item_id] = self::paraTela($i->id, $f);
                }
            });

        return $saida;
    }

    /** O que a tela precisa do frete de um anúncio publicado (escalares, sempre). */
    public static function paraTela(int $itemId, array $f): array
    {
        $diverge = in_array($f['nivel'] ?? null, [ConferenciaDeFrete::DIFERENTE, ConferenciaDeFrete::REPRECIFICAR], true);

        return [
            'item_id' => $itemId,
            'aplicavel' => (bool) ($f['aplicavel'] ?? false),
            'nivel' => isset($f['nivel']) ? (string) $f['nivel'] : null,
            'ml' => isset($f['ml']) ? (float) $f['ml'] : null,
            'portal' => isset($f['portal']) ? (float) $f['portal'] : null,
            'mensagem' => isset($f['mensagem']) ? (string) $f['mensagem'] : null,
            'levado_em' => isset($f['levado_em']) ? (string) $f['levado_em'] : null,
            'pode_levar' => $diverge && ! empty($f['oferta_id']) && empty($f['levado_em']),
        ];
    }

    // ═══ Internos ══════════════════════════════════════════════════════════

    /**
     * As referências desta publicação: as linhas de frete da conferência que a liberou (por tipo e variante)
     * e, de reserva, o que a Precificação diz agora.
     */
    private function referencias(PubPublicacao $p, PubRascunho $r, PubProduto $produto): array
    {
        // A conferência do MESMO plano; publicação antiga sem `plano_hash` usa a última anterior a ela.
        $v = ($p->plano_hash !== null ? $r->validacoes()->where('plano_hash', $p->plano_hash)->orderByDesc('id')->first() : null)
            ?? $r->validacoes()->where('created_at', '<=', $p->created_at ?? now())->orderByDesc('id')->first();
        $frete = (array) ($v?->respostas_ml['frete'] ?? []);

        $daConferencia = [];
        if (! empty($frete['aplicavel'])) {
            foreach ((array) ($frete['linhas'] ?? []) as $l) {
                if (! isset($l['listing_type'], $l['variante']) || ! is_numeric($l['portal'] ?? null)) {
                    continue;
                }
                $daConferencia[$l['listing_type'].'|'.$l['variante']] = [
                    'portal' => (float) $l['portal'], 'origem' => $l['origem'] ?? null,
                    'na_conferencia' => is_numeric($l['ml'] ?? null) ? (float) $l['ml'] : null,
                    'preco' => is_numeric($l['preco'] ?? null) ? (float) $l['preco'] : null,
                    'oferta_id' => isset($l['oferta_id']) ? (int) $l['oferta_id'] : null,
                    'dimensions' => isset($frete['dimensions']) ? (string) $frete['dimensions'] : null,
                ];
            }
        }

        return [
            'da_conferencia' => $daConferencia,
            'fretes' => $this->efetivos->fretesDoProduto($produto),
            'efetivos' => $this->efetivos->daProduto($produto),
        ];
    }

    /** Sem linha da conferência: o frete e o preço da promoção que a Precificação tem agora para o SKU do item. */
    private function referenciaDeAgora(PubPublicacaoItem $item, array $referencias): ?array
    {
        $lt = (string) $item->listing_type_id;
        $sku = null;
        foreach ((array) ($item->payload['attributes'] ?? []) as $a) {
            if (($a['id'] ?? null) === 'SELLER_SKU') {
                $sku = EstruturaOferta::normalizarSku($a['value_name'] ?? null);
            }
        }

        $ref = ($sku !== null ? ($referencias['fretes']['por_variante'][$sku][$lt] ?? null) : null) ?? ($referencias['fretes']['por_tipo'][$lt] ?? null);
        if ($ref === null || $ref['valor'] === null) {
            return null;
        }
        $e = $referencias['efetivos'];
        $preco = ($sku !== null ? ($e['promocoes_por_variante'][$sku][$lt] ?? null) : null) ?? ($e['promocoes'][$lt] ?? null);

        return ['portal' => (float) $ref['valor'], 'origem' => $ref['origem'], 'na_conferencia' => null,
            'preco' => $preco !== null ? (float) $preco : null, 'oferta_id' => $ref['oferta_id'], 'dimensions' => null];
    }

    /**
     * O frete de UM anúncio publicado. Nulo = o Mercado Livre não respondeu (fica para a próxima tentativa).
     */
    private function doItem(mixed $conta, string $seller, PubPublicacaoItem $item, ?array $ref): ?array
    {
        $estado = $this->cliente->daConta($conta, 'GET', "/items/{$item->ml_item_id}", ['attributes' => 'id,price,status,shipping,listing_type_id']);
        if (! $estado->ok() || ! is_array($estado->corpo)) {
            return null;
        }
        $envio = (array) ($estado->corpo['shipping'] ?? []);
        $modo = (string) ($envio['mode'] ?? '');
        $preco = is_numeric($estado->corpo['price'] ?? null) ? (float) $estado->corpo['price'] : null;
        $base = ['em' => now()->toIso8601String(), 'modo' => $modo, 'preco' => $preco];

        // "A combinar" ou envio próprio: o Mercado Livre não cobra frete deste anúncio.
        if ($modo !== 'me2') {
            return [...$base, 'aplicavel' => false];
        }

        $porItem = $this->cliente->daConta($conta, 'GET', "/users/{$seller}/shipping_options/free", ['item_id' => $item->ml_item_id, 'verbose' => 'true']);
        $cobertura = $porItem->ok() && is_array($porItem->corpo) ? ($porItem->corpo['coverage']['all_country'] ?? null) : null;
        if (! is_numeric($cobertura['list_cost'] ?? null)) {
            return null;
        }
        $noAnuncio = (float) $cobertura['list_cost'];
        $medido = $noAnuncio;
        $faixaDiferente = false;

        // Preço cheio e preço da promoção em faixas de frete diferentes: compara na faixa da promoção.
        if ($ref !== null && $ref['preco'] !== null && $preco !== null && TabelaFreteEcf::coluna($preco) !== TabelaFreteEcf::coluna($ref['preco'])) {
            $faixaDiferente = true;
            $dims = is_string($envio['dimensions'] ?? null) && $envio['dimensions'] !== '' ? $envio['dimensions'] : $ref['dimensions'];
            if ($dims !== null) {
                $naPromocao = $this->cliente->daConta($conta, 'GET', "/users/{$seller}/shipping_options/free", [
                    'item_price' => round($ref['preco'], 2), 'listing_type_id' => $item->listing_type_id, 'mode' => 'me2', 'condition' => 'new',
                    'logistic_type' => (string) ($envio['logistic_type'] ?? config('estrutura_produtos.modalidade_padrao')),
                    'free_shipping' => TabelaFreteEcf::gratisObrigatorio($ref['preco']) ? 'true' : 'false', 'dimensions' => $dims, 'verbose' => 'true',
                ]);
                $custo = $naPromocao->ok() && is_array($naPromocao->corpo) ? ($naPromocao->corpo['coverage']['all_country']['list_cost'] ?? null) : null;
                if (is_numeric($custo)) {
                    $medido = (float) $custo;
                    $faixaDiferente = false;
                }
            }
        }

        $frete = [...$base, 'aplicavel' => true, 'ml' => $medido, 'no_anuncio' => $noAnuncio,
            'peso_faturado' => is_numeric($cobertura['billable_weight'] ?? null) ? (int) $cobertura['billable_weight'] : null,
            'faixa_diferente' => $faixaDiferente];

        // Sem frete na Precificação não há com o que comparar: fica só o registro do que o ML cobra.
        if ($ref === null) {
            return [...$frete, 'portal' => null, 'nivel' => null];
        }

        $c = ConferenciaDeFrete::comparar($ref['portal'], $medido);

        return [...$frete, 'portal' => $ref['portal'], 'origem' => $ref['origem'], 'na_conferencia' => $ref['na_conferencia'],
            'preco_da_referencia' => $ref['preco'], 'oferta_id' => $ref['oferta_id'], ...$c,
            'mensagem' => $c['nivel'] === ConferenciaDeFrete::IGUAL ? null
                : ConferenciaDeFrete::mensagem('do '.(self::NOMES[$item->listing_type_id] ?? $item->listing_type_id), $ref['portal'], $ref['origem'], $medido, $c['nivel'], publicado: true),
        ];
    }
}
