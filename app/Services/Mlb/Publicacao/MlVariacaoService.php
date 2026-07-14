<?php

namespace App\Services\Mlb\Publicacao;

/**
 * Monta e valida o array `variations[]` no shape exato do POST /items do Mercado Livre.
 *
 * Responsabilidades:
 *  1. atributosDeVariacao(): identifica quais atributos da categoria aceitam variação
 *     (tags.allow_variations = true), devolvendo mapa indexado por id.
 *  2. montar(): produz o array `variations[]` no formato do ML, separando corretamente
 *     attribute_combinations (atributos com allow_variations) de attributes (GTIN/SKU);
 *     seta available_quantity=0 no item raiz quando há variações (regra do ML: o estoque
 *     passa a ser controlado por cada variação individualmente).
 *  3. validar(): valida localmente as variações ANTES de chamar /items/validate no ML
 *     (WIZ-07), detectando combinação repetida, GTIN duplicado, foto faltando e
 *     combinação vazia. Retorna o mesmo shape de erros que MlItemPayloadValidator::traduzir().
 *
 * Service puro — sem dependência de HTTP ou banco de dados.
 */
class MlVariacaoService
{
    /**
     * Mensagens de erro em pt-BR por código.
     * Espelha o padrão de constantes de MlItemPayloadValidator.
     */
    private const ERROS = [
        'combinacao_repetida' => 'Combinação de variação duplicada (ex.: Azul+M já existe).',
        'gtin_duplicado'      => 'GTIN duplicado entre variações — cada variação precisa de um GTIN único.',
        'foto_faltando'       => 'Uma ou mais variações estão sem foto.',
        'combinacao_vazia'    => 'Cada variação precisa ter pelo menos um atributo de combinação (ex.: Cor).',
    ];

    /**
     * Detecta quais atributos da categoria aceitam variações.
     *
     * Filtra o array retornado por MlCatalogoMetaService::atributos() para devolver
     * somente os que têm tags.allow_variations = true (ex.: COLOR, SIZE).
     * GTIN e SELLER_SKU nunca têm allow_variations; ficam em `attributes` da variação.
     *
     * @param  array  $atributos  retorno completo de MlCatalogoMetaService::atributos()
     * @return array<string, array>  mapa {id => atributo} para os que permitem variação
     */
    public function atributosDeVariacao(array $atributos): array
    {
        $mapa = [];

        foreach ($atributos as $atributo) {
            // Usa data_get para acessar a chave aninhada tags.allow_variations de forma segura
            if (data_get($atributo, 'tags.allow_variations') === true) {
                $mapa[$atributo['id']] = $atributo;
            }
        }

        return $mapa;
    }

    /**
     * Monta o array `variations[]` no shape exato do POST /items do ML.
     *
     * Regras críticas do shape ML:
     *  - `available_quantity` no item raiz = 0 quando há variações (o estoque fica
     *    distribuído entre as variações; o ML rejeita valores > 0 no raiz com variações).
     *  - `attribute_combinations`: contém APENAS os atributos com allow_variations=true.
     *  - `attributes` da variação: contém GTIN e SELLER_SKU (nunca em attribute_combinations).
     *  - `picture_ids`: array de picture_ids retornados pelo upload (não URLs brutas).
     *
     * @param  array  $variacoes         lista de variações no formato interno do wizard
     * @param  array  $atributosVariacao mapa id→atributo (resultado de atributosDeVariacao())
     * @return array{variations: array, available_quantity: int}
     */
    public function montar(array $variacoes, array $atributosVariacao): array
    {
        $out = [];

        foreach ($variacoes as $variacao) {
            // attribute_combinations: só os atributos presentes no mapa de variação
            // (filtra GTIN/SKU e outros atributos que não têm allow_variations=true)
            $combinations = array_filter(
                $variacao['attribute_combinations'] ?? [],
                fn (array $ac) => isset($atributosVariacao[$ac['id']])
            );

            // attributes: GTIN e SELLER_SKU (e quaisquer outros attributes informados)
            // Já vêm no formato correto do wizard — apenas repassamos
            $attributes = array_values(
                $variacao['attributes'] ?? []
            );

            $out[] = [
                'attribute_combinations' => array_values($combinations),
                'attributes'             => $attributes,
                'picture_ids'            => $variacao['picture_ids'] ?? [],
                'price'                  => $variacao['price'] ?? null,
                // Estoque por variação: controle individual (não no raiz)
                'available_quantity'     => (int) ($variacao['available_quantity'] ?? 0),
            ];
        }

        // available_quantity=0 no item raiz: regra do ML quando há variações.
        // O ML distribui o controle de estoque para cada variação individualmente.
        return [
            'variations'         => $out,
            'available_quantity' => 0,
        ];
    }

    /**
     * Valida as variações ANTES de chamar /items/validate no ML (WIZ-07).
     *
     * Executar esta validação local evita chamar a API do ML com dados inválidos,
     * fornecendo mensagens de erro em pt-BR mais específicas do que o retorno da API.
     *
     * Detecta:
     *  - combinacao_vazia: attribute_combinations vazio (sem nenhum atributo de variação)
     *  - combinacao_repetida: mesmo conjunto id:value_id aparece em mais de uma variação
     *  - gtin_duplicado: mesmo GTIN em duas ou mais variações
     *  - foto_faltando: variação sem picture_ids
     *
     * @param  array  $variacoes  lista de variações no formato interno do wizard
     * @return array<int, array{code: string, campo: string, mensagem: string}>
     */
    public function validar(array $variacoes): array
    {
        $erros            = [];
        $combinacoesVistas = [];
        $gtinsVistos       = [];

        foreach ($variacoes as $i => $variacao) {
            $campo       = "variacao_{$i}";
            $combinations = $variacao['attribute_combinations'] ?? [];

            // ─── Combinação vazia ───
            // Cada variação precisa ter pelo menos um atributo de combinação (ex.: Cor)
            if (empty($combinations)) {
                $erros[] = [
                    'code'     => 'combinacao_vazia',
                    'campo'    => $campo,
                    'mensagem' => self::ERROS['combinacao_vazia'],
                ];
                // Continua para detectar outros erros independentes (foto, GTIN)
            } else {
                // ─── Combinação repetida ───
                // Chave canônica: combina id:value_id de cada atributo, ordenada por id para
                // garantir que a mesma combinação em ordem diferente seja detectada como igual
                $chave = implode('|', array_map(
                    fn (array $ac) => $ac['id'] . ':' . ($ac['value_id'] ?? $ac['value_name'] ?? ''),
                    collect($combinations)->sortBy('id')->values()->all()
                ));

                if (in_array($chave, $combinacoesVistas, true)) {
                    $erros[] = [
                        'code'     => 'combinacao_repetida',
                        'campo'    => $campo,
                        'mensagem' => self::ERROS['combinacao_repetida'],
                    ];
                }

                $combinacoesVistas[] = $chave;
            }

            // ─── GTIN duplicado ───
            // GTIN fica em attributes (não em attribute_combinations); busca por id=GTIN
            $atributos = $variacao['attributes'] ?? [];
            $gtinEntry = collect($atributos)->first(fn (array $a) => ($a['id'] ?? '') === 'GTIN');

            if ($gtinEntry !== null) {
                $gtin = (string) ($gtinEntry['value_name'] ?? '');

                if ($gtin !== '' && in_array($gtin, $gtinsVistos, true)) {
                    $erros[] = [
                        'code'     => 'gtin_duplicado',
                        'campo'    => $campo,
                        'mensagem' => self::ERROS['gtin_duplicado'],
                    ];
                }

                if ($gtin !== '') {
                    $gtinsVistos[] = $gtin;
                }
            }

            // ─── Foto faltando ───
            // Cada variação precisa ter ao menos um picture_id para publicar
            $pictureIds = $variacao['picture_ids'] ?? [];

            if (empty($pictureIds)) {
                $erros[] = [
                    'code'     => 'foto_faltando',
                    'campo'    => $campo,
                    'mensagem' => self::ERROS['foto_faltando'],
                ];
            }
        }

        return $erros;
    }
}
