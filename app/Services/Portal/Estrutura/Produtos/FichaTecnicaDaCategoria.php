<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\EstruturaProdutoVariacao;
use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\Publicador\ExplicacaoDeAtributos;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
 * A regra é a MESMA do editor interno: entra todo atributo de produto que o
 * {@see ClassificadorAtributos} deixa editar (seções PRINCIPAIS, FICHA e AVANCADO).
 * O classificador é a referência de propósito — duas regras separadas divergiam
 * (em cadeiras de escritório o editor deixava preencher 16 campos de produto que a
 * ficha não mostrava). Ficam de fora:
 * - os de sistema (read_only/inferred/fixed), os de dado de variante
 *   (`variation_attribute`: SKU, GTIN, cor principal...), condição e pacote;
 * - o atributo que é o EIXO da variação do PRODUTO — ver "Eixo por produto" abaixo;
 * - o que a ficha já cobre em outro lugar (SKU, volumes) e a grade de medidas;
 * - medida do produto que a categoria não exige ({@see self::IDS_MEDIDA_DO_PRODUTO}).
 * `allow_variations` sozinho NÃO tira mais o campo ("Material do estofamento").
 *
 * Os `hidden` editáveis (a seção AVANCADO do editor) vão num grupo próprio no fim,
 * {@see self::GRUPO_MAIS_DETALHES}, aberto como os outros (a ficha não recolhe nada).
 * Um deles que a categoria exija fica no grupo normal: o rótulo do grupo diz "opcional".
 *
 * ### Eixo por produto, não por categoria
 * O atributo que corresponde a um eixo do portal ({@see EstruturaProdutoVariacao::EIXO_PARA_ATRIBUTO})
 * e que a categoria deixa variar (`allow_variations`) ENTRA na definição, marcado com
 * `eixo_do_portal` (ex.: MATERIAL → 'material'). Quem o esconde é a ficha do produto, e só
 * quando o produto usa aquele eixo em alguma variação ({@see self::doProduto()}): a cadeira
 * que varia por cor informa o material aqui; a que varia por material, não (o valor vem da
 * variação). A definição é da categoria e não sabe o eixo de produto nenhum — por isso a
 * marca, em vez de tirar o campo de todos os produtos da categoria (como era até 08/10/2026).
 * Sem `allow_variations` o eixo é atributo comum e não leva marca (o Sincronizar faz igual).
 *
 * ### Explicação de cada campo
 * {@see self::definicaoComExplicacoes()} acrescenta `explicacao` (texto curto, NEUTRO) a cada
 * campo, de {@see ExplicacaoDeAtributos::paraPortal()} — que já aplica o filtro de sigilo.
 *
 * ### "Não se aplica"
 * O campo que o editor deixa marcar "Não se aplica" (`aceitaNaoSeAplica`: atributo do
 * produto que não é obrigatório) sai com `nao_se_aplica = true`. O que o cliente marca
 * é gravado por {@see FichaTecnicaDoProduto} com {@see FichaTecnicaDoProduto::NAO_SE_APLICA}.
 *
 * ### Qual controle cada campo vira
 * Ter opção é o que faz o campo ser uma LISTA — não o `value_type`. O catálogo
 * entrega boa parte das opções em atributo `string` que traz `values` junto; lê-las
 * só no `list` jogava esses campos em texto livre e deixava o cliente digitar valor
 * que não existe na lista. Lista marcada `multivalued` aceita mais de uma opção.
 *
 * ### Campo com opção é SÓ lista, mesmo onde o editor interno aceita texto (decisão do usuário, 09/10/2026)
 * O editor da equipe aceita texto livre em `string` com opções (`allow_custom_value: true` nas
 * respostas reais: Materiais, Salas…). A ficha do cliente, não: aqui quem tem opção só escolhe entre
 * elas (§35 de 08/10, mantido). O texto ANTIGO gravado antes de 08/10 nesses campos não se perde no
 * caminho: o Sincronizar o leva ao rascunho como texto onde o editor aceita
 * ({@see \App\Support\Publicador\Portal\PortalValorDeAtributo}).
 *
 * A montagem ({@see self::daAtributos()}) é uma função PURA: sem HTTP, sem cache.
 */
class FichaTecnicaDaCategoria
{
    public const GRUPO_PADRAO = 'Outras características';

    /** Grupo do fim da ficha: os campos que o editor interno mostra como "Avançado". Rótulo neutro. */
    public const GRUPO_MAIS_DETALHES = 'Mais detalhes';

    public const TIPO_TEXTO = 'texto';
    public const TIPO_NUMERO = 'numero';
    public const TIPO_NUMERO_UNIDADE = 'numero_unidade';
    public const TIPO_SIM_NAO = 'sim_nao';
    public const TIPO_LISTA = 'lista';

    /** As seções do classificador que o cliente preenche: as que o editor interno deixa editar como produto. */
    private const SECOES_DA_FICHA = [
        AtributoClassificado::SECAO_PRINCIPAIS,
        AtributoClassificado::SECAO_FICHA,
        AtributoClassificado::SECAO_AVANCADO,
    ];

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

    /** Tipo da tela → `value_type`, só para o texto montado da explicação ("…, em centímetros."). */
    private const TIPO_PARA_VALUE_TYPE = [
        self::TIPO_TEXTO          => 'string',
        self::TIPO_NUMERO         => 'number',
        self::TIPO_NUMERO_UNIDADE => 'number_unit',
        self::TIPO_SIM_NAO        => 'boolean',
        self::TIPO_LISTA          => 'list',
    ];

    /** Mesmos termos que o teste de sigilo procura no JSON entregue ao cliente. */
    private const TERMOS_PROIBIDOS = '/mercado|an[uú]ncio|publicar|\bmlb|\bml\b/iu';

    public function __construct(
        private MlCatalogoMetaService $catalogo,
        private ExplicacaoDeAtributos $explicacoes,
    ) {}

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
     * A definição para a TELA: a mesma de {@see self::definicao()}, com `explicacao` em cada campo
     * (o "o que é isto?" ao passar o mouse). Se a explicação falhar, a ficha segue sem ela — nunca
     * sem os campos.
     *
     * @return array<int, array{grupo: string, campos: array<int, array>}>
     */
    public function definicaoComExplicacoes(string $categoriaId): array
    {
        $grupos = $this->definicao($categoriaId);
        if ($grupos === []) {
            return $grupos;
        }

        $crus = [];
        foreach ($this->catalogo->atributos($categoriaId) as $a) {
            if (is_array($a) && trim((string) ($a['id'] ?? '')) !== '') {
                $crus[trim((string) $a['id'])] = $a;
            }
        }

        $pedidos = [];
        foreach (self::camposPorId($grupos) as $id => $campo) {
            $cru = $crus[$id] ?? [];
            $pedidos[] = [
                'id' => $id,
                'nome' => $campo['nome'],
                'tooltip' => is_string($cru['tooltip'] ?? null) ? $cru['tooltip'] : null,
                'hint' => is_string($cru['hint'] ?? null) ? $cru['hint'] : null,
                // O texto montado fala pelo controle que a tela mostra (lista, número…), não pelo `value_type`.
                'tipo' => self::TIPO_PARA_VALUE_TYPE[$campo['tipo']] ?? 'string',
                'unidades' => array_column($campo['unidades'], 'id'),
                'unidade_padrao' => $campo['unidade_padrao'],
                'valores' => array_map(fn ($v) => ['name' => $v['nome']], $campo['valores']),
            ];
        }

        try {
            $textos = $this->explicacoes->paraPortal($pedidos);
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Produtos] explicações da ficha técnica indisponíveis', ['erro' => $e->getMessage()]);

            return $grupos;
        }

        foreach ($grupos as &$grupo) {
            foreach ($grupo['campos'] as &$campo) {
                $campo['explicacao'] = $textos[$campo['id']] ?? null;
            }
            unset($campo);
        }
        unset($grupo);

        return $grupos;
    }

    /**
     * A definição como vale para UM produto: sem os campos que são o eixo de alguma variação dele
     * (`eixo_do_portal` entre os eixos usados). Grupo que fica vazio sai. A tela aplica a mesma
     * regra (`gruposDoProduto` em `@/lib/fichaTecnica`).
     *
     * @param  array<int, array{grupo: string, campos: array<int, array>}>  $grupos
     * @param  iterable<string|null>  $eixosDoProduto  chaves de {@see EstruturaProdutoVariacao::EIXOS} usadas nas variações
     * @return array<int, array{grupo: string, campos: array<int, array>}>
     */
    public static function doProduto(array $grupos, iterable $eixosDoProduto): array
    {
        $usados = [];
        foreach ($eixosDoProduto as $eixo) {
            $eixo = trim((string) $eixo);
            if ($eixo !== '') {
                $usados[$eixo] = true;
            }
        }

        $saida = [];
        foreach ($grupos as $grupo) {
            $campos = array_values(array_filter(
                $grupo['campos'],
                fn (array $c) => ! isset($usados[(string) ($c['eixo_do_portal'] ?? '')]),
            ));
            if ($campos !== []) {
                $saida[] = ['grupo' => $grupo['grupo'], 'campos' => $campos];
            }
        }

        return $saida;
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
        $atributos = array_values(array_filter($atributos, 'is_array'));
        $classificados = self::classificar($atributos);

        $grupos = [];
        $maisDetalhes = [];

        foreach ($atributos as $atributo) {
            $classificado = $classificados[trim((string) ($atributo['id'] ?? ''))] ?? null;
            if ($classificado === null || ! in_array($classificado->secao, self::SECOES_DA_FICHA, true)) {
                continue;
            }

            $campo = self::campoDe($atributo, $classificado);
            if ($campo === null) {
                continue;
            }

            if ($classificado->secao === AtributoClassificado::SECAO_AVANCADO && ! $campo['obrigatorio']) {
                $maisDetalhes[] = $campo;

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

        $saida = array_map(fn ($x) => $x[1], $indexados);

        // Os "Avançado" do editor, sempre por último (nenhum é obrigatório, por construção).
        if ($maisDetalhes !== []) {
            $saida[] = ['grupo' => self::GRUPO_MAIS_DETALHES, 'campos' => $maisDetalhes];
        }

        return $saida;
    }

    /**
     * Classifica os atributos como o editor interno faria num produto novo SEM eixo: a definição
     * é da categoria e não sabe por onde cada produto varia. O atributo que pode ser eixo do
     * portal sai daqui como atributo de produto e recebe a marca `eixo_do_portal` em
     * {@see self::campoDe()}; quem o tira é {@see self::doProduto()}. Só id/nome/tags/tipo entram:
     * é o que decide papel, seção e "Não se aplica"; opções e unidades são lidas aqui mesmo,
     * com o filtro de sigilo.
     *
     * @param  list<array>  $atributos
     * @return array<string, AtributoClassificado>
     */
    private static function classificar(array $atributos): array
    {
        $enxutos = [];

        foreach ($atributos as $atributo) {
            $id = trim((string) ($atributo['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $tags = ClassificadorAtributos::tags($atributo['tags'] ?? []);
            // `values` cru entra só para o classificador decidir `aceitaTextoLivre` como o editor decide
            // (sem opção = texto; com opção, `string` aceita texto e `list`/`boolean` não) — usado só
            // quando o filtro de sigilo derruba todas as opções. As opções que vão para a tela continuam
            // saindo de {@see self::opcoes()}, com o filtro de sigilo.
            $valores = array_values(array_filter((array) ($atributo['values'] ?? []), fn ($v) => is_array($v) && trim((string) ($v['id'] ?? '')) !== ''));
            $enxutos[] = ['id' => $id, 'name' => (string) ($atributo['name'] ?? $id), 'tags' => $tags,
                'value_type' => (string) ($atributo['value_type'] ?? 'string'), 'values' => $valores];
        }

        if ($enxutos === []) {
            return [];
        }

        $schema = CategorySchema::dasFontes('', [], $enxutos, [], []);

        return (new ClassificadorAtributos())->classificar($schema, new ContextoClassificacao('new', []))->atributos;
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

    /**
     * Um atributo cru → campo da tela, ou null quando ele não pertence à ficha do cliente.
     * Quem chama já conferiu a seção no classificador; aqui ficam as regras próprias do portal.
     */
    private static function campoDe(array $atributo, AtributoClassificado $classificado): ?array
    {
        $id = trim((string) ($atributo['id'] ?? ''));
        $nome = trim((string) ($atributo['name'] ?? ''));

        if ($id === '' || $nome === '' || in_array($id, self::IDS_FORA, true) || str_contains($id, 'GRID')) {
            return null;
        }

        $tags = is_array($atributo['tags'] ?? null) ? $atributo['tags'] : [];

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
            // Sem opção que possa ir à tela, só sobra digitar — e só onde o editor interno aceitaria
            // texto livre. A lista FECHADA cujas opções o filtro de sigilo derrubou inteiras
            // (§35: "500 ml") não vira texto: o cliente gravaria valor que não publica. Sai da ficha.
            if (! $classificado->aceitaTextoLivre && in_array($tipo, [self::TIPO_LISTA, self::TIPO_TEXTO], true)) {
                return null;
            }
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

        // Só é eixo do portal onde a categoria deixa variar por ele — senão é atributo comum
        // (o Sincronizar faz igual: sem `allow_variations`, a variação vai como eixo próprio).
        $eixoDoPortal = self::temTag($tags, 'allow_variations')
            ? (array_flip(EstruturaProdutoVariacao::EIXO_PARA_ATRIBUTO)[$id] ?? null)
            : null;

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
            // Mesma regra do editor interno; obrigatório da ficha nunca aceita (lá também não).
            'nao_se_aplica' => $classificado->aceitaNaoSeAplica && ! $obrigatorio,
            // Chave do eixo do portal que este atributo É (ex.: 'material'), ou null. Interna: a tela
            // usa só para esconder o campo no produto que varia por ele; nunca é mostrada.
            'eixo_do_portal' => $eixoDoPortal,
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
