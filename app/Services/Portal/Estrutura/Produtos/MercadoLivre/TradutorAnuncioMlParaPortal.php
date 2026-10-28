<?php

namespace App\Services\Portal\Estrutura\Produtos\MercadoLivre;

use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;

/**
 * Um anúncio do Mercado Livre (o corpo de `GET /items/{id}?include_attributes=all`) → o que o
 * cadastro de Produtos do Portal grava (28/10/2026, "trazer do ML para Produtos").
 *
 * Função PURA: sem HTTP, sem banco. Quem lê o anúncio e grava é o {@see ImportadorDeAnuncioMl}.
 *
 * ### O que vira o quê
 * - Uma variação do ML = uma linha (variação do Portal). Anúncio sem `variations` (o modelo
 *   User Products, em que cada cor já é um anúncio) = uma linha só.
 * - Nome do produto = título do anúncio. O TÍTULO DE VENDA fica vazio de propósito: título
 *   preenchido no cadastro conta como "planejado" e a IA do Publicador não o reescreve — e
 *   reescrever pela metodologia é o motivo de importar.
 * - Ref da variação = `SELLER_SKU` (da variação, depois do anúncio, depois `seller_custom_field`);
 *   sem SKU, `MLB…` (e `MLB…-n` com variações). Grupo = SKU do anúncio ou o MLB.
 * - Volume = a caixa que o vendedor informou (`SELLER_PACKAGE_*`, convertida para cm e kg);
 *   sem ela, `shipping.dimensions` ("altura x largura x comprimento,peso em gramas"). O ML guarda
 *   UMA caixa: móvel que vai em vários volumes chega com um volume só (aviso).
 * - Ficha técnica = os atributos do anúncio pelo id (a ficha do Portal usa os mesmos ids do
 *   catálogo). O Modelo NÃO vai para a ficha (saiu dela em 09/10/2026): fica no retrato, como fato.
 * - Fotos = as do anúncio, pela ordem; com variações, as `picture_ids` de cada uma.
 *
 * Medida que o anúncio não tem não é inventada aqui: o que não veio fica vazio.
 */
final class TradutorAnuncioMlParaPortal
{
    /** Atributo de combinação do ML → eixo da variação no Portal. */
    private const EIXO_DO_ATRIBUTO = [
        'COLOR'      => 'cor',
        'MAIN_COLOR' => 'cor',
        'SIZE'       => 'tamanho',
        'VOLTAGE'    => 'voltagem',
        'MATERIAL'   => 'material',
        'FLAVOR'     => 'sabor',
    ];

    /** A caixa informada pelo vendedor, em ordem comprimento, largura, altura, peso. */
    private const CAIXA = [
        'c'  => 'SELLER_PACKAGE_LENGTH',
        'l'  => 'SELLER_PACKAGE_WIDTH',
        'a'  => 'SELLER_PACKAGE_HEIGHT',
        'kg' => 'SELLER_PACKAGE_WEIGHT',
    ];

    /** Não entram na ficha: código, caixa (vira volume) e o Modelo (fica no retrato). */
    private const FORA_DA_FICHA = ['SELLER_SKU', 'MODEL', 'GTIN', 'EMPTY_GTIN_REASON', 'ITEM_CONDITION', 'SELLER_PACKAGE_LENGTH',
        'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT', 'SELLER_PACKAGE_TYPE', 'PACKAGE_LENGTH',
        'PACKAGE_WIDTH', 'PACKAGE_HEIGHT', 'PACKAGE_WEIGHT', 'SHIPMENT_PACKING'];

    private const MAX_FOTOS_PADRAO = 12;

    /**
     * @param  array  $item  corpo de `GET /items/{id}` (com `include_attributes=all`)
     * @param  ?string  $descricao  `plain_text` de `GET /items/{id}/description` (null = não tem)
     * @return array{
     *     linhas: list<array>,
     *     fotos_por_linha: list<list<array{id: string, url: string}>>,
     *     atributos: array<string, array>,
     *     avisos: list<string>,
     *     retrato: array
     * }
     */
    public static function traduzir(array $item, ?string $descricao, int $maxFotos = self::MAX_FOTOS_PADRAO): array
    {
        $mlb = strtoupper(trim((string) ($item['id'] ?? '')));
        $avisos = [];

        $atributos = self::porId($item['attributes'] ?? []);
        $nome = self::texto($item['title'] ?? '') ?: $mlb;
        $skuDoAnuncio = self::valor($atributos['SELLER_SKU'] ?? null) ?? self::texto($item['seller_custom_field'] ?? '') ?: null;

        [$volume, $avisoVolume] = self::volume($atributos, $item['shipping'] ?? []);
        if ($avisoVolume !== null) {
            $avisos[] = $avisoVolume;
        }

        $fotosDoAnuncio = [];
        foreach ((array) ($item['pictures'] ?? []) as $p) {
            $id = (string) ($p['id'] ?? '');
            $url = self::url($p['secure_url'] ?? $p['url'] ?? null);
            if ($id !== '' && $url !== null) {
                $fotosDoAnuncio[$id] = ['id' => $id, 'url' => $url];
            }
        }

        $variacoesMl = array_values(array_filter((array) ($item['variations'] ?? []), 'is_array'));
        $descricao = self::descricao($descricao);
        $categoria = self::texto($item['category_id'] ?? '');

        $linhas = [];
        $fotosPorLinha = [];

        if ($variacoesMl === []) {
            $codigo = $skuDoAnuncio ?? $mlb;
            $linhas[] = self::linha($codigo, $codigo, $nome, $categoria, $descricao, $volume, null, null, 1, self::inteiro($item['available_quantity'] ?? null));
            $fotosPorLinha[] = array_slice(array_values($fotosDoAnuncio), 0, $maxFotos);
        } else {
            $grupo = $skuDoAnuncio ?? $mlb;
            $usados = [];
            $multi = false;
            foreach ($variacoesMl as $v) {
                if (count((array) ($v['attribute_combinations'] ?? [])) > 1) {
                    $multi = true;
                }
            }

            foreach ($variacoesMl as $i => $v) {
                $atributosDaVariacao = self::porId($v['attributes'] ?? []);
                $codigo = self::valor($atributosDaVariacao['SELLER_SKU'] ?? null)
                    ?? (self::texto($v['seller_custom_field'] ?? '') ?: null)
                    ?? $mlb.'-'.($i + 1);
                // A mesma Ref em duas variações do anúncio derrubaria a segunda (código repetido).
                $chave = mb_strtolower($codigo);
                if (isset($usados[$chave]) || mb_strtolower($grupo) === $chave && count($variacoesMl) > 1) {
                    $codigo = $mlb.'-'.($i + 1);
                    $chave = mb_strtolower($codigo);
                }
                $usados[$chave] = true;

                [$eixo, $valor] = self::eixoEValor((array) ($v['attribute_combinations'] ?? []), $multi);

                $linhas[] = self::linha($codigo, $grupo, $nome, $categoria, $i === 0 ? $descricao : null, $volume, $eixo, $valor, $i + 1,
                    self::inteiro($v['available_quantity'] ?? null));

                $fotos = [];
                foreach ((array) ($v['picture_ids'] ?? []) as $pid) {
                    if (isset($fotosDoAnuncio[(string) $pid])) {
                        $fotos[] = $fotosDoAnuncio[(string) $pid];
                    }
                }
                $fotosPorLinha[] = array_slice($fotos !== [] ? $fotos : array_values($fotosDoAnuncio), 0, $maxFotos);
            }

            if ($multi) {
                $avisos[] = 'O anúncio varia em mais de uma característica ao mesmo tempo (ex.: cor e voltagem): as variações entraram como “Outro”, com os valores juntos.';
            }
        }

        $daFicha = [];
        foreach ($atributos as $id => $a) {
            if (! in_array($id, self::FORA_DA_FICHA, true) && self::preenchido($a)) {
                $daFicha[$id] = $a;
            }
        }

        if (count($fotosDoAnuncio) > $maxFotos && $variacoesMl === []) {
            $avisos[] = "O anúncio tem ".count($fotosDoAnuncio)." fotos; entraram as {$maxFotos} primeiras.";
        }

        return [
            'linhas'          => $linhas,
            'fotos_por_linha' => $fotosPorLinha,
            'atributos'       => $daFicha,
            'avisos'          => $avisos,
            'retrato'         => self::retrato($item, $atributos, $volume, $descricao, count($fotosDoAnuncio), count($variacoesMl)),
        ];
    }

    /**
     * Um atributo do ML → a entrada que a tela da ficha mandaria para aquele campo
     * ({valor, unidade, nao_se_aplica, proprio, proprios}). Null = não dá para levar.
     *
     * @param  array  $campo  campo da definição ({@see \App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria})
     * @param  array  $atributo  atributo cru do anúncio
     */
    public static function entradaDaFicha(array $campo, array $atributo): ?array
    {
        $valueId = isset($atributo['value_id']) ? (string) $atributo['value_id'] : null;
        $nome = self::valor($atributo);

        if ($valueId === '-1' && $nome === null) {
            return ($campo['nao_se_aplica'] ?? false) ? ['nao_se_aplica' => true] : null;
        }

        $opcoes = [];
        foreach ((array) ($campo['valores'] ?? []) as $o) {
            $opcoes[(string) $o['id']] = $o;
        }

        switch ($campo['tipo'] ?? null) {
            case 'lista':
                if ($campo['multivalor'] ?? false) {
                    $ids = [];
                    $proprios = [];
                    $valores = (array) ($atributo['values'] ?? []);
                    if ($valores === [] && $nome !== null) {
                        $valores = array_map(fn ($n) => ['name' => trim($n)], preg_split('/\s*[,|]\s*/u', $nome) ?: []);
                    }
                    foreach ($valores as $v) {
                        $id = isset($v['id']) ? (string) $v['id'] : null;
                        $texto = trim((string) ($v['name'] ?? ''));
                        if ($id !== null && isset($opcoes[$id])) {
                            $ids[] = $id;
                        } elseif (($o = self::opcaoPeloNome($opcoes, $texto)) !== null) {
                            $ids[] = (string) $o['id'];
                        } elseif ($texto !== '') {
                            $ids[] = $texto;
                            $proprios[] = $texto;
                        }
                    }

                    return $ids === [] ? null : ['valor' => $ids, 'proprios' => $proprios];
                }
                if ($valueId !== null && isset($opcoes[$valueId])) {
                    return ['valor' => $valueId];
                }
                if ($nome === null) {
                    return null;
                }
                $o = self::opcaoPeloNome($opcoes, $nome);

                return $o !== null ? ['valor' => (string) $o['id']] : ['valor' => $nome, 'proprio' => true];

            case 'numero_unidade':
                $struct = $atributo['value_struct'] ?? ($atributo['values'][0]['struct'] ?? null);
                if (is_array($struct) && is_numeric($struct['number'] ?? null)) {
                    return ['valor' => (string) $struct['number'], 'unidade' => (string) ($struct['unit'] ?? '')];
                }
                if ($nome !== null && preg_match('/^([\d.,]+)\s*([^\d\s.,][^\d]*)$/u', $nome, $m)) {
                    return ['valor' => $m[1], 'unidade' => trim($m[2])];
                }

                return $nome !== null ? ['valor' => $nome] : null;

            case 'numero':
                $struct = $atributo['value_struct'] ?? null;
                if (is_array($struct) && is_numeric($struct['number'] ?? null)) {
                    return ['valor' => (string) $struct['number']];
                }

                return $nome !== null ? ['valor' => $nome] : null;

            default:
                return $nome !== null ? ['valor' => $nome] : null;
        }
    }

    // ═══ Internos ═══════════════════════════════════════════════════════════

    private static function linha(string $codigo, string $grupo, string $nome, string $categoria, ?string $descricao, ?array $volume, ?string $eixo, ?string $valor, int $ordem, ?int $estoque): array
    {
        $linha = [
            'codigo' => mb_substr($codigo, 0, 120),
            'grupo'  => mb_substr($grupo, 0, 120),
            'nome'   => mb_substr($nome, 0, 255),
            'ordem'  => $ordem,
        ];
        if ($categoria !== '') {
            $linha['categoria_ml_id'] = $categoria;
        }
        if ($descricao !== null) {
            $linha['descricao'] = $descricao;
        }
        if ($volume !== null) {
            $linha['volumes'] = [$volume];
        }
        if ($eixo !== null) {
            $linha['eixo'] = $eixo;
        }
        if ($valor !== null) {
            $linha['valor'] = $valor;
        }
        if ($estoque !== null && $estoque >= 0) {
            $linha['estoque'] = $estoque;
        }

        return $linha;
    }

    /** @return array{0: ?string, 1: ?string} [eixo, valor] */
    private static function eixoEValor(array $combinacoes, bool $multi): array
    {
        $partes = [];
        $eixo = null;
        foreach ($combinacoes as $c) {
            $v = self::texto($c['value_name'] ?? '');
            if ($v === '') {
                continue;
            }
            $partes[] = $v;
            $eixo ??= self::EIXO_DO_ATRIBUTO[strtoupper((string) ($c['id'] ?? ''))] ?? 'outro';
        }
        if ($partes === []) {
            return [null, null];
        }
        if ($multi) {
            $eixo = 'outro';
        }

        return [$eixo, mb_substr(implode(' · ', $partes), 0, NormalizadorDeLinha::MAX_VALOR)];
    }

    /**
     * A caixa em cm e kg, ou null com o motivo. `SELLER_PACKAGE_*` primeiro; `shipping.dimensions`
     * ("AxLxC,gramas") só quando a caixa do vendedor não veio inteira.
     *
     * @return array{0: ?array{c: float, l: float, a: float, kg: float}, 1: ?string}
     */
    private static function volume(array $atributos, mixed $shipping): array
    {
        $medidas = [];
        foreach (self::CAIXA as $chave => $id) {
            $medidas[$chave] = self::medida($atributos[$id] ?? null, $chave === 'kg');
        }

        if (in_array(null, $medidas, true)) {
            $dim = is_array($shipping) ? ($shipping['dimensions'] ?? null) : null;
            if (is_string($dim) && preg_match('/^\s*([\d.]+)x([\d.]+)x([\d.]+),([\d.]+)\s*$/i', $dim, $m)) {
                $medidas = ['c' => (float) $m[3], 'l' => (float) $m[2], 'a' => (float) $m[1], 'kg' => round((float) $m[4] / 1000, 3)];
            } else {
                return [null, 'O anúncio não tem as medidas da caixa: informe-as no produto.'];
            }
        }

        foreach (['c', 'l', 'a'] as $k) {
            if ($medidas[$k] < NormalizadorDeLinha::MIN_MEDIDA || $medidas[$k] > NormalizadorDeLinha::MAX_MEDIDA) {
                return [null, 'As medidas da caixa do anúncio ficam fora da faixa aceita: informe-as no produto.'];
            }
        }
        if ($medidas['kg'] < NormalizadorDeLinha::MIN_PESO || $medidas['kg'] > NormalizadorDeLinha::MAX_PESO) {
            return [null, 'O peso da caixa do anúncio fica fora da faixa aceita: informe-o no produto.'];
        }

        return [['c' => round($medidas['c'], 2), 'l' => round($medidas['l'], 2), 'a' => round($medidas['a'], 2), 'kg' => round($medidas['kg'], 3)], null];
    }

    /** Medida de um atributo número+unidade → cm (ou kg, no peso). Null quando não dá para ler. */
    private static function medida(?array $atributo, bool $peso): ?float
    {
        if ($atributo === null) {
            return null;
        }
        $struct = $atributo['value_struct'] ?? ($atributo['values'][0]['struct'] ?? null);
        $numero = is_array($struct) ? ($struct['number'] ?? null) : null;
        $unidade = is_array($struct) ? mb_strtolower(trim((string) ($struct['unit'] ?? ''))) : '';

        if (! is_numeric($numero)) {
            $nome = self::valor($atributo);
            if ($nome === null || ! preg_match('/^([\d.,]+)\s*([a-z"]*)$/iu', $nome, $m)) {
                return null;
            }
            $numero = (float) str_replace(',', '.', $m[1]);
            $unidade = mb_strtolower($m[2]);
        }
        $n = (float) $numero;

        if ($peso) {
            return match ($unidade) {
                'kg'    => $n,
                'g', '' => $n / 1000,
                'mg'    => $n / 1000000,
                'lb'    => $n * 0.45359237,
                default => null,
            };
        }

        return match ($unidade) {
            'cm', '' => $n,
            'mm'     => $n / 10,
            'm'      => $n * 100,
            '"', 'in', 'pol' => $n * 2.54,
            default  => null,
        };
    }

    private static function retrato(array $item, array $atributos, ?array $volume, ?string $descricao, int $fotos, int $variacoes): array
    {
        $lista = [];
        foreach ($atributos as $id => $a) {
            if (self::preenchido($a)) {
                $lista[] = ['id' => $id, 'nome' => (string) ($a['name'] ?? $id), 'valor' => mb_substr((string) self::valor($a), 0, 255)];
            }
        }

        return [
            'mlb'          => strtoupper((string) ($item['id'] ?? '')),
            'titulo'       => (string) ($item['title'] ?? ''),
            'categoria_id' => $item['category_id'] ?? null,
            'dominio'      => $item['domain_id'] ?? null,
            'tipo'         => $item['listing_type_id'] ?? null,
            'status'       => $item['status'] ?? null,
            'preco'        => $item['price'] ?? null,
            'estoque'      => $item['available_quantity'] ?? null,
            'vendidos'     => $item['sold_quantity'] ?? null,
            'catalogo'     => (bool) ($item['catalog_listing'] ?? false),
            'permalink'    => $item['permalink'] ?? null,
            'modelo'       => self::valor($atributos['MODEL'] ?? null),
            'marca'        => self::valor($atributos['BRAND'] ?? null),
            'atributos'    => $lista,
            'fotos'        => $fotos,
            'variacoes'    => $variacoes,
            'caixa'        => $volume,
            'frete'        => [
                'modo'      => $item['shipping']['mode'] ?? null,
                'logistica' => $item['shipping']['logistic_type'] ?? null,
                'gratis'    => $item['shipping']['free_shipping'] ?? null,
            ],
            'garantia'        => array_values(array_map(fn ($s) => ($s['name'] ?? $s['id'] ?? '').': '.($s['value_name'] ?? ''), array_filter((array) ($item['sale_terms'] ?? []), 'is_array'))),
            'descricao_chars' => $descricao === null ? 0 : mb_strlen($descricao),
        ];
    }

    /** @return array<string, array> id => atributo */
    private static function porId(mixed $lista): array
    {
        $saida = [];
        foreach ((array) $lista as $a) {
            if (is_array($a) && trim((string) ($a['id'] ?? '')) !== '') {
                $saida[strtoupper(trim((string) $a['id']))] = $a;
            }
        }

        return $saida;
    }

    private static function preenchido(array $a): bool
    {
        return self::valor($a) !== null || (isset($a['value_id']) && (string) $a['value_id'] !== '');
    }

    /** O texto do valor do atributo (value_name, ou o nome do 1º de `values`). */
    public static function valor(?array $a): ?string
    {
        if ($a === null) {
            return null;
        }
        $nome = $a['value_name'] ?? ($a['values'][0]['name'] ?? null);
        $nome = is_scalar($nome) ? trim((string) $nome) : '';

        return $nome === '' ? null : $nome;
    }

    private static function opcaoPeloNome(array $opcoes, string $texto): ?array
    {
        $alvo = self::chave($texto);
        if ($alvo === '') {
            return null;
        }
        foreach ($opcoes as $o) {
            if (self::chave((string) $o['nome']) === $alvo) {
                return $o;
            }
        }

        return null;
    }

    private static function chave(string $t): string
    {
        $t = mb_strtolower(trim($t));
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/\s+/u', ' ', $t);
    }

    private static function descricao(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $t = trim(str_replace(["\r\n", "\r"], "\n", $texto));
        if ($t === '') {
            return null;
        }

        return mb_substr($t, 0, NormalizadorDeLinha::MAX_DESCRICAO);
    }

    private static function texto(mixed $v): string
    {
        return is_scalar($v) ? trim((string) preg_replace('/\s+/u', ' ', (string) $v)) : '';
    }

    private static function inteiro(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    private static function url(mixed $u): ?string
    {
        if (! is_string($u) || trim($u) === '') {
            return null;
        }

        return (string) preg_replace('#^http://#', 'https://', trim($u));
    }
}
