<?php

namespace App\Services\Publicador\Alavancas;

/**
 * A matriz do RESEARCH (doc oficial lida em 04/10/2026) como dado — D-07.1.
 * A tela e as ações perguntam aqui; nunca repetem a regra de um tipo.
 *
 * [ASSUMED] Nos tipos criados pelo vendedor (campanha, cupom, desconto) o ML decide a
 * elegibilidade do anúncio: oferecemos "inscrever" a qualquer item que ainda não esteja
 * pendente/iniciado e deixamos o ML recusar com o motivo dele.
 */
final class TiposDePromocao
{
    public const TODOS = [
        'DEAL', 'MARKETPLACE_CAMPAIGN', 'VOLUME', 'DOD', 'LIGHTNING', 'PRICE_DISCOUNT',
        'PRE_NEGOTIATED', 'SELLER_CAMPAIGN', 'SMART', 'PRICE_MATCHING', 'UNHEALTHY_STOCK', 'SELLER_COUPON_CAMPAIGN',
    ];

    /** Tipos que chegam como convite do Mercado Livre. */
    public const CONVITES_DO_ML = [
        'DEAL', 'MARKETPLACE_CAMPAIGN', 'DOD', 'LIGHTNING', 'VOLUME', 'PRE_NEGOTIATED', 'SMART', 'PRICE_MATCHING', 'UNHEALTHY_STOCK',
    ];

    public const INSCREVE_COM_PRECO = ['DEAL', 'DOD', 'LIGHTNING', 'SELLER_CAMPAIGN', 'PRICE_DISCOUNT'];

    public const INSCREVE_SEM_PRECO = [
        'MARKETPLACE_CAMPAIGN', 'VOLUME', 'PRE_NEGOTIATED', 'UNHEALTHY_STOCK', 'SMART', 'PRICE_MATCHING', 'SELLER_COUPON_CAMPAIGN',
    ];

    public const EDITAVEIS = ['DEAL', 'SELLER_CAMPAIGN'];

    /** Tipos sem `promotion_id` na escrita: o item é o recurso. */
    public const SEM_PROMOTION_ID = ['DOD', 'LIGHTNING', 'PRICE_DISCOUNT'];

    public const OFFER_ID_NA_INSCRICAO = ['PRE_NEGOTIATED', 'UNHEALTHY_STOCK', 'SMART', 'PRICE_MATCHING'];

    public const OFFER_ID_NA_REMOCAO = ['MARKETPLACE_CAMPAIGN', 'VOLUME', 'PRE_NEGOTIATED', 'UNHEALTHY_STOCK', 'SMART', 'PRICE_MATCHING'];

    /** O `offer_id` precisa vir da leitura e começar por `CANDIDATE-`; nunca se inventa. */
    public const CANDIDATO_EXIGE_OFERTA = ['SMART', 'PRICE_MATCHING'];

    /** Oferta ativa não se retira; só a programada (pending). */
    public const SO_REMOVE_PROGRAMADA = ['DOD', 'LIGHTNING'];

    public const PEDE_ESTOQUE = ['LIGHTNING'];

    public const COM_ESTOQUE_MINIMO = ['DOD', 'LIGHTNING'];

    public const COFINANCIADAS = ['MARKETPLACE_CAMPAIGN', 'SMART', 'PRICE_MATCHING', 'PRE_NEGOTIATED', 'UNHEALTHY_STOCK'];

    public const DESCONTO_NO_CARRINHO = ['VOLUME', 'SELLER_COUPON_CAMPAIGN'];

    public const CRIADAS_PELO_VENDEDOR = ['SELLER_CAMPAIGN', 'SELLER_COUPON_CAMPAIGN', 'PRICE_DISCOUNT'];

    public const EXIGEM_REPUTACAO = ['PRICE_DISCOUNT', 'SELLER_CAMPAIGN', 'SELLER_COUPON_CAMPAIGN'];

    private const MOTIVO_SMART = 'Aceite este convite no Mercado Livre: a leitura não trouxe o código da oferta (offer_id).';
    private const MOTIVO_MARKETPLACE = 'Para mudar o preço: tire o produto, ajuste o preço do anúncio e inscreva de novo.';
    private const MOTIVO_OFERTA_ATIVA = 'Oferta ativa não pode ser retirada; pause o anúncio no Mercado Livre se precisar.';

    /**
     * O que a tela e as ações podem oferecer para um tipo e a entrada do item nele.
     *
     * @param  ?array  $item  entrada normalizada (`status` candidate|pending|started, `offer_id`); null = ainda sem entrada
     * @return array{inscrever: bool, preco: bool, pede_estoque: bool, alterar: bool, remover: bool, motivo: ?string}
     */
    public static function capacidades(string $tipo, ?array $item): array
    {
        $nada = ['inscrever' => false, 'preco' => false, 'pede_estoque' => false, 'alterar' => false, 'remover' => false, 'motivo' => null];

        if (! in_array($tipo, self::TODOS, true)) {
            return [...$nada, 'motivo' => 'Tipo de promoção desconhecido: só leitura.'];
        }

        $status = (string) ($item['status'] ?? 'candidate');
        $noAr = in_array($status, ['pending', 'started'], true);
        $motivo = null;

        // Inscrever: convite do ML só em candidate; tipo do vendedor em qualquer item fora do ar.
        $inscrever = in_array($tipo, self::CRIADAS_PELO_VENDEDOR, true) ? ! $noAr : $status === 'candidate';
        if ($inscrever && in_array($tipo, self::CANDIDATO_EXIGE_OFERTA, true)
            && ! str_starts_with((string) ($item['offer_id'] ?? ''), 'CANDIDATE-')) {
            // Nunca inventar offer_id: sem o código da leitura, só o aceite no ML.
            $inscrever = false;
            $motivo = self::MOTIVO_SMART;
        }

        // Alterar
        $alterar = in_array($tipo, self::EDITAVEIS, true) && $noAr;
        if (! $alterar && $status === 'started' && in_array($tipo, ['MARKETPLACE_CAMPAIGN', 'VOLUME'], true)) {
            $motivo ??= self::MOTIVO_MARKETPLACE;
        }

        // Remover
        $remover = $noAr;
        if ($status === 'started' && in_array($tipo, self::SO_REMOVE_PROGRAMADA, true)) {
            $remover = false;
            $motivo ??= self::MOTIVO_OFERTA_ATIVA;
        }

        return [
            'inscrever' => $inscrever,
            'preco' => $inscrever && in_array($tipo, self::INSCREVE_COM_PRECO, true),
            'pede_estoque' => $inscrever && in_array($tipo, self::PEDE_ESTOQUE, true),
            'alterar' => $alterar,
            'remover' => $remover,
            'motivo' => $motivo,
        ];
    }
}
