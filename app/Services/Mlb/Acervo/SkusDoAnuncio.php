<?php

namespace App\Services\Mlb\Acervo;

/**
 * Extrai a LISTA de SKUs distintos de um item do multiget do Mercado Livre
 * (quick 261010-rie, D-RIE-01/D-RIE-05).
 *
 * ─── A receita é reusada, não reinventada ───────────────────────────────────
 *
 * A fonte é `AnunciosMercadoLivreService::skuDoAnuncio()` (L626/L632, Portal
 * Estrutura): atributo **`SELLER_SKU`** primeiro, **`seller_custom_field`**
 * como fallback, a mesma regra aplicada por variação. Aquele arquivo NÃO foi
 * alterado — é gate de diff deste plano.
 *
 * ─── A diferença é deliberada ───────────────────────────────────────────────
 *
 * Lá, variações com SKUs DIVERGENTES devolvem `null`: uma linha da colagem da
 * planilha é um SKU só, então SKU ambíguo é pior que SKU nenhum. Aqui, TODAS
 * entram na lista, porque o propósito é outro — **achar o anúncio por qualquer
 * um dos seus SKUs**. Devolver `null` tornaria inachável justamente o anúncio
 * com variações, que é o caso mais comum nas contas grandes.
 *
 * ─── Por que uma lista cabe nesta linha ─────────────────────────────────────
 *
 * `ml_acervo_itens` tem uma linha por ANÚNCIO (D-17) e essa MESMA linha já
 * guarda `variations` com o payload BRUTO das variações — ordens de grandeza
 * maior que a lista de SKUs extraída dele. A lista de SKUs é o resíduo barato
 * de um dado que já está ali.
 *
 * Ordem: o SKU do item-pai primeiro (quando existe), depois os das variações
 * na ordem em que o ML devolve. A tela mostra o primeiro, então essa ordem é
 * contrato: nunca apresentar um SKU de variação como se fosse "o" SKU do
 * anúncio.
 */
final class SkusDoAnuncio
{
    /**
     * @param  array $item  o `body` de um item do multiget (`GET /items?ids=`)
     * @return list<string> SKUs distintos, já com trim e sem vazios
     */
    public static function extrair(array $item): array
    {
        $skus = [];

        foreach (self::doNivel($item) as $sku) {
            $skus[] = $sku;
        }

        $variacoes = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        foreach ($variacoes as $variacao) {
            if (! is_array($variacao)) {
                continue;
            }

            foreach (self::doNivel($variacao) as $sku) {
                $skus[] = $sku;
            }
        }

        // Distintos preservando a ordem de descoberta (pai antes das variações).
        return array_values(array_unique($skus));
    }

    /**
     * SKU de UM nível (item-pai ou variação): atributo `SELLER_SKU` vence,
     * `seller_custom_field` é o fallback. Devolve lista de 0 ou 1 elemento —
     * lista, e não `?string`, para o chamador não precisar de guarda de null.
     *
     * @return list<string>
     */
    private static function doNivel(array $nivel): array
    {
        $doAtributo = null;

        $atributos = is_array($nivel['attributes'] ?? null) ? $nivel['attributes'] : [];
        foreach ($atributos as $atributo) {
            if (is_array($atributo) && ($atributo['id'] ?? null) === 'SELLER_SKU') {
                $doAtributo = self::texto($atributo['value_name'] ?? null);
                break;
            }
        }

        $sku = $doAtributo ?? self::texto($nivel['seller_custom_field'] ?? null);

        return $sku !== null ? [$sku] : [];
    }

    /**
     * Normaliza um valor cru da API em texto útil, ou `null`.
     *
     * `value_name` pode chegar como array/objeto (atributo de lista) e
     * `seller_custom_field` pode chegar em branco ou com espaços — nos dois
     * casos a resposta é "não tem SKU", nunca um `Array to string conversion`
     * nem uma string vazia inventada.
     */
    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor) && ! is_int($valor) && ! is_float($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto !== '' ? $texto : null;
    }
}
