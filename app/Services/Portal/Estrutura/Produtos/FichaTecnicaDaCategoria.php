<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use Illuminate\Support\Facades\Cache;

/**
 * A DEFINIÇÃO da ficha técnica de uma categoria: os campos que o cliente preenche
 * no cadastro do produto, montados a partir do catálogo da categoria.
 *
 * ### Sigilo (regra de negócio)
 * O cliente NÃO pode perceber de onde vêm os campos. Por isso a saída é só o
 * rótulo (`name`) e os valores das listas — nunca os textos de ajuda do catálogo
 * (que costumam citar a plataforma) e nunca o id da categoria. O `id` do campo é
 * a chave técnica de ida e volta (a tela devolve o que preencheu por ele); não é
 * mostrado. Qualquer rótulo ou opção que cite a plataforma é DESCARTADO por
 * {@see self::seguro()} em vez de repassado.
 *
 * ### O que entra e o que não entra
 * Entram os atributos que o cliente sabe responder (material, marca, largura...).
 * Ficam de fora os de sistema e os de variação — a variação já é tratada por
 * eixo/valor na ficha — e os que a ficha já cobre em outro lugar (SKU, volumes).
 * Medida do produto só entra quando a categoria a exige ({@see self::IDS_MEDIDA_DO_PRODUTO}).
 *
 * ### Qual controle cada campo vira
 * Ter opção é o que faz o campo ser uma LISTA — não o `value_type`. O catálogo
 * entrega boa parte das opções em atributo `string` que traz `values` junto; lê-las
 * só no `list` jogava esses campos em texto livre e deixava o cliente digitar valor
 * que não existe na lista. Lista marcada `multivalued` aceita mais de uma opção.
 *
 * A montagem ({@see self::daAtributos()}) é uma função PURA: sem HTTP, sem cache.
 */
class FichaTecnicaDaCategoria
{
    public const GRUPO_PADRAO = 'Outras características';

    public const TIPO_TEXTO = 'texto';
    public const TIPO_NUMERO = 'numero';
    public const TIPO_NUMERO_UNIDADE = 'numero_unidade';
    public const TIPO_SIM_NAO = 'sim_nao';
    public const TIPO_LISTA = 'lista';

    /** Tags que tiram o atributo da ficha: sistema (hidden/read_only/fixed) e variação. */
    private const TAGS_FORA = ['hidden', 'read_only', 'fixed', 'variation_attribute', 'allow_variations'];

    /** Atributos que a ficha já cobre em outro lugar (código do produto, volumes) ou que são de grade. */
    private const IDS_FORA = [
        'SELLER_SKU', 'CATALOG_PRODUCT_ID', 'SIZE_GRID_ID',
        'PACKAGE_WEIGHT', 'PACKAGE_LENGTH', 'PACKAGE_WIDTH', 'PACKAGE_HEIGHT',
    ];

    /**
     * Medidas DO PRODUTO. A ficha já pede medida em Volumes, que é a do produto EMBALADO —
     * a que vale para peso cubado, logística e frete. Ter os dois conjuntos na mesma tela
     * faz a pessoa digitar duas vezes (e foi o que aconteceu: produto 2 com 12/12/12 nos dois).
     *
     * Por isso estes só aparecem quando a categoria os EXIGE: onde o catálogo marca `required`,
     * esconder deixaria o cadastro incompleto. Onde não marca, somem e fica só Volumes.
     *
     * `MAX_WEIGHT_SUPPORTED` não entra aqui de propósito: é quanto o móvel aguenta, não medida dele.
     */
    private const IDS_MEDIDA_DO_PRODUTO = [
        'LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER', 'WEIGHT',
    ];

    private const VALUE_TYPE_PARA_TIPO = [
        'string'      => self::TIPO_TEXTO,
        'number'      => self::TIPO_NUMERO,
        'number_unit' => self::TIPO_NUMERO_UNIDADE,
        'boolean'     => self::TIPO_SIM_NAO,
        'list'        => self::TIPO_LISTA,
    ];

    /** Mesmos termos que o teste de sigilo procura no JSON entregue ao cliente. */
    private const TERMOS_PROIBIDOS = '/mercado|an[uú]ncio|publicar|\bmlb|\bml\b/iu';

    public function __construct(private MlCatalogoMetaService $catalogo) {}

    /**
     * A definição da categoria, em grupos. Lista vazia = o catálogo não respondeu (ou a
     * categoria não tem campos): quem chama trata como "indisponível".
     *
     * @return array<int, array{grupo: string, campos: array<int, array>}>
     */
    public function definicao(string $categoriaId): array
    {
        $atributos = $this->catalogo->atributos($categoriaId);

        if ($atributos === []) {
            // `MlCatalogoMetaService::atributos` guarda a resposta vazia por 7 dias, inclusive
            // a de uma queda passageira. Soltar a chave deixa a próxima tentativa refazer a chamada.
            Cache::forget("ml_meta_atributos_{$categoriaId}");
        }

        return self::daAtributos($atributos);
    }

    /**
     * Os campos da definição, achatados e indexados pelo id — o que a validação consulta.
     *
     * @param  array<int, array{grupo: string, campos: array<int, array>}>  $grupos
     * @return array<string, array>
     */
    public static function camposPorId(array $grupos): array
    {
        $mapa = [];
        foreach ($grupos as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $mapa[$campo['id']] = $campo;
            }
        }

        return $mapa;
    }

    /**
     * Atributos crus do catálogo → grupos para a tela.
     *
     * Grupos que têm campo obrigatório vêm primeiro; dentro de cada grupo os
     * obrigatórios vêm antes. A ordem relativa original é preservada nos empates.
     *
     * @param  array<int, mixed>  $atributos  resposta crua de GET /categories/{id}/attributes
     * @return array<int, array{grupo: string, campos: array<int, array>}>
     */
    public static function daAtributos(array $atributos): array
    {
        $grupos = [];

        foreach ($atributos as $atributo) {
            if (! is_array($atributo)) {
                continue;
            }

            $campo = self::campoDe($atributo);
            if ($campo === null) {
                continue;
            }

            $nomeDoGrupo = trim((string) ($atributo['attribute_group_name'] ?? ''));
            $nomeDoGrupo = ($nomeDoGrupo !== '' && self::seguro($nomeDoGrupo)) ? $nomeDoGrupo : self::GRUPO_PADRAO;

            $grupos[$nomeDoGrupo] ??= [];
            $grupos[$nomeDoGrupo][] = $campo;
        }

        $saida = [];
        foreach ($grupos as $nome => $campos) {
            // Obrigatórios antes; o índice original desempata (usort não é estável em todas as versões).
            $indexados = array_map(fn ($c, $i) => [$i, $c], $campos, array_keys($campos));
            usort($indexados, fn ($a, $b) => [! $a[1]['obrigatorio'], $a[0]] <=> [! $b[1]['obrigatorio'], $b[0]]);

            $saida[] = ['grupo' => (string) $nome, 'campos' => array_map(fn ($x) => $x[1], $indexados)];
        }

        // Grupos com obrigatório primeiro (estável pelo índice de aparição).
        $indexados = array_map(fn ($g, $i) => [$i, $g], $saida, array_keys($saida));
        usort($indexados, function ($a, $b) {
            $ta = (int) ! self::temObrigatorio($a[1]);
            $tb = (int) ! self::temObrigatorio($b[1]);

            return [$ta, $a[0]] <=> [$tb, $b[0]];
        });

        return array_map(fn ($x) => $x[1], $indexados);
    }

    private static function temObrigatorio(array $grupo): bool
    {
        foreach ($grupo['campos'] as $campo) {
            if ($campo['obrigatorio']) {
                return true;
            }
        }

        return false;
    }

    /** Um atributo cru → campo da tela, ou null quando ele não pertence à ficha do cliente. */
    private static function campoDe(array $atributo): ?array
    {
        $id = trim((string) ($atributo['id'] ?? ''));
        $nome = trim((string) ($atributo['name'] ?? ''));

        if ($id === '' || $nome === '' || in_array($id, self::IDS_FORA, true) || str_contains($id, 'GRID')) {
            return null;
        }

        $tags = is_array($atributo['tags'] ?? null) ? $atributo['tags'] : [];
        foreach (self::TAGS_FORA as $tag) {
            if (self::temTag($tags, $tag)) {
                return null;
            }
        }

        $tipo = self::VALUE_TYPE_PARA_TIPO[(string) ($atributo['value_type'] ?? '')] ?? null;

        // Rótulo que cita a plataforma não vai para o cliente — e o id nunca pode carregar o termo.
        if ($tipo === null || ! self::seguro($nome) || ! self::seguro($id)) {
            return null;
        }

        $obrigatorio = self::temTag($tags, 'required');

        // Medida do produto sem exigência da categoria sai da ficha: Volumes já pede a do embalado.
        if (! $obrigatorio && in_array($id, self::IDS_MEDIDA_DO_PRODUTO, true)) {
            return null;
        }

        // QUEM TEM OPÇÃO VIRA LISTA, qualquer que seja o `value_type`. O catálogo entrega a maior
        // parte das opções em atributo `string` COM `values` (Forma, Desenho do tecido, Materiais,
        // Tipo de pufe...). Ler só no `list` jogava tudo isso em texto livre, e o cliente digitava
        // valor que não existe na lista ("REDONDO" onde a opção é "Redonda") — que a plataforma recusa.
        $valores = self::opcoes($atributo);

        if ($valores === []) {
            // Lista sem nenhuma opção não dá para preencher: cai para texto livre.
            if ($tipo === self::TIPO_LISTA) {
                $tipo = self::TIPO_TEXTO;
            }
        } elseif ($tipo === self::TIPO_TEXTO) {
            $tipo = self::TIPO_LISTA;
        } else {
            // Número e Sim/Não continuam com o controle deles; a lista de opções não serve ali.
            $valores = $tipo === self::TIPO_LISTA ? $valores : [];
        }

        // Só lista escolhe mais de um. O catálogo marca com a tag `multivalued` (ex.: Materiais).
        $multivalor = $tipo === self::TIPO_LISTA && self::temTag($tags, 'multivalued');

        $unidades = [];
        $unidadePadrao = null;
        if ($tipo === self::TIPO_NUMERO_UNIDADE) {
            foreach ((array) ($atributo['allowed_units'] ?? []) as $u) {
                $uid = trim((string) (is_array($u) ? ($u['id'] ?? '') : $u));
                $unome = trim((string) (is_array($u) ? ($u['name'] ?? $uid) : $u));
                if ($uid !== '' && self::seguro($uid) && self::seguro($unome)) {
                    $unidades[] = ['id' => $uid, 'nome' => $unome !== '' ? $unome : $uid];
                }
            }

            $padrao = trim((string) ($atributo['default_unit'] ?? ''));
            $unidadePadrao = in_array($padrao, array_column($unidades, 'id'), true)
                ? $padrao
                : ($unidades[0]['id'] ?? null);
        }

        $max = (int) ($atributo['value_max_length'] ?? 0);

        return [
            'id'            => $id,
            'nome'          => $nome,
            'obrigatorio'   => $obrigatorio,
            'tipo'          => $tipo,
            'multivalor'    => $multivalor,
            'valores'       => $valores,
            'unidades'      => $unidades,
            'unidade_padrao' => $unidadePadrao,
            'max'           => $max > 0 ? $max : null,
        ];
    }

    /**
     * As opções do atributo, já passadas pelo filtro de sigilo. Vale para qualquer
     * `value_type`: o que define se o campo é uma lista é TER opção, não o tipo declarado.
     *
     * @return array<int, array{id: string, nome: string}>
     */
    private static function opcoes(array $atributo): array
    {
        $valores = [];
        foreach ((array) ($atributo['values'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }
            $vid = trim((string) ($v['id'] ?? ''));
            $vnome = trim((string) ($v['name'] ?? ''));
            if ($vid !== '' && $vnome !== '' && self::seguro($vid) && self::seguro($vnome)) {
                $valores[] = ['id' => $vid, 'nome' => $vnome];
            }
        }

        return $valores;
    }

    /** `tags` chega como objeto ({"required": true}) ou como lista (["required"]) — lê os dois. */
    private static function temTag(array $tags, string $tag): bool
    {
        return ($tags[$tag] ?? false) === true || in_array($tag, $tags, true);
    }

    /** O texto pode ir para o cliente? (nenhum termo que revele a origem). */
    private static function seguro(string $texto): bool
    {
        return preg_match(self::TERMOS_PROIBIDOS, $texto) !== 1;
    }
}
