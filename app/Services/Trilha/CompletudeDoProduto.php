<?php

namespace App\Services\Trilha;

use App\Models\EstruturaProduto;
use App\Models\MlCategoriaSchema;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\PendenciasDoProduto;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A etapa 1 da trilha ("Lista de produtos", dono: o cliente): o que falta no cadastro de UM produto
 * para ele sair da lista, e os dados que a planilha da tela mostra (medidas, embalagem, fotos, custo).
 *
 * As réguas são as que o sistema JÁ usa, nunca uma nova:
 * - pendências de cada variação: {@see PendenciasDoProduto::daLinha()} (a da tela de Produtos), montada
 *   como o {@see \App\Services\Portal\Estrutura\FunilDoMapeamento} monta — inclusive a logística pela
 *   modalidade de envio já lida (só cache);
 * - ficha técnica: os obrigatórios da categoria, sem o Modelo (a IA o preenche) e sem os eixos do produto,
 *   pela definição GUARDADA (tabela do schema ou o cache de 7 dias). Categoria sem definição guardada fica
 *   "não conferida" e não vira falta: conferir exigiria chamar o Mercado Livre, e a trilha nunca chama.
 *
 * Diferença de propósito em relação ao Funil: os eixos que saem da ficha são os DOIS de cada variação
 * (`eixo` e `eixo_2`), como em {@see FichaTecnicaDoProduto::eixosDoProduto()} desde 28/10/2026 (na meia de
 * cor E tamanho, nem a Cor nem o Tamanho são da ficha). O Funil ainda olha só o `eixo`.
 *
 * Tudo aqui é puro sobre o que quem chama já carregou, menos {@see definicoesGuardadas()}, que lê as
 * definições de TODAS as categorias de uma vez (uma consulta e uma leitura de cache em lote).
 */
class CompletudeDoProduto
{
    /** Fotos que cada cor precisa ter (a régua do protótipo do Erlon: "3 fotos em cada cor"). */
    public const FOTOS_MINIMAS = 3;

    /**
     * As medidas do produto MONTADO que viram o texto "180 × 90 × 76 cm", na ordem do bloco da ficha
     * ({@see FichaTecnicaDaCategoria::IDS_MEDIDA_DO_PRODUTO}, sem o peso, que vai à parte).
     */
    private const DIMENSOES = ['LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER'];

    private const PESO = 'WEIGHT';

    /**
     * As definições da ficha técnica das categorias, só do que já está guardado: o schema da categoria
     * (tabela `ml_categoria_schemas`) e, na falta dele, os atributos no cache de 7 dias — a mesma ordem do
     * `FunilDoMapeamento::definicaoGuardada()`, mas em LOTE: uma consulta e um `Cache::many`, qualquer que
     * seja o número de categorias. Categoria que não está em lugar nenhum fica de fora (não dá para conferir).
     *
     * @param  iterable<string|null>  $categorias  ids de categoria (vazios e repetidos são ignorados)
     * @return array<string, array<int, array{grupo: string, campos: array<int, array>}>>  categoria → definição
     */
    public static function definicoesGuardadas(iterable $categorias): array
    {
        $ids = [];
        foreach ($categorias as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $ids[$c] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $atributos = [];
        foreach (MlCategoriaSchema::query()->whereIn('category_id', array_keys($ids))->get(['category_id', 'atributos']) as $schema) {
            if (is_array($schema->atributos) && $schema->atributos !== []) {
                $atributos[(string) $schema->category_id] = $schema->atributos;
            }
        }

        $semSchema = array_values(array_diff(array_keys($ids), array_keys($atributos)));
        if ($semSchema !== []) {
            $chaves = array_map(fn (string $c) => "ml_meta_atributos_{$c}", $semSchema);
            $emCache = Cache::many($chaves);
            foreach ($semSchema as $i => $c) {
                $guardado = $emCache[$chaves[$i]] ?? null;
                if (is_array($guardado) && $guardado !== []) {
                    $atributos[$c] = $guardado;
                }
            }
        }

        $definicoes = [];
        foreach ($atributos as $c => $crus) {
            $definicao = FichaTecnicaDaCategoria::daAtributos($crus);
            if ($definicao !== []) {
                $definicoes[$c] = $definicao;
            }
        }

        return $definicoes;
    }

    /**
     * O produto pela régua da lista.
     *
     * @param  EstruturaProduto  $produto  com `familia`, `ambientes` e `variacoes.volumes` já carregados
     * @param  Collection<int, \App\Models\EstruturaProdutoAtributo>  $salvos  a ficha gravada do produto
     * @param  array<int, int>  $fotosPorVariacao  variacao_id → quantas fotos tem
     * @param  array<string, array>  $definicoes  saída de {@see definicoesGuardadas()}
     * @param  ?string  $modalidade  `ModalidadeDeEnvio::emCache()` da empresa
     * @return array{faltas: list<string>, ficha: string, medidas: ?string, embalagem: ?string, fotos_min: int, tem_descricao: bool, custo_min: ?float, custo_max: ?float}
     */
    public static function avaliar(EstruturaProduto $produto, Collection $salvos, array $fotosPorVariacao, array $definicoes, ?string $modalidade): array
    {
        $faltas = self::pendencias($produto, $modalidade);

        $temDescricao = trim((string) $produto->descricao) !== '';
        if (! $temDescricao) {
            $faltas[] = 'descrição';
        }

        // A menor contagem entre as variações: a régua é "3 fotos em CADA cor". Produto sem variação tem zero.
        $contagens = self::fotosDeCadaVariacao($produto, $fotosPorVariacao);
        $fotosMin = $contagens->isEmpty() ? 0 : (int) $contagens->min();
        if ($fotosMin < self::FOTOS_MINIMAS) {
            $faltas[] = 'fotos ('.$fotosMin.' de '.self::FOTOS_MINIMAS.')';
        }

        $categoria = trim((string) $produto->categoria_ml_id);
        if ($categoria === '') {
            // A falta "categoria" já veio das pendências; sem ela não há ficha a conferir.
            $ficha = 'sem_categoria';
        } elseif (! isset($definicoes[$categoria])) {
            $ficha = 'nao_conferida';
        } else {
            $emBranco = self::obrigatoriosEmBranco($definicoes[$categoria], self::eixos($produto), $salvos);
            $ficha = $emBranco === [] ? 'completa' : 'incompleta';
            foreach ($emBranco as $nome) {
                $faltas[] = 'ficha: '.mb_strtolower($nome);
            }
        }

        $custos = $produto->variacoes->pluck('custo')
            ->filter(fn ($c) => $c !== null && (float) $c > 0)
            ->map(fn ($c) => (float) $c);

        return [
            'faltas'        => $faltas,
            'ficha'         => $ficha,
            'medidas'       => self::medidas($salvos),
            'embalagem'     => self::embalagem($produto),
            'fotos_min'     => $fotosMin,
            'tem_descricao' => $temDescricao,
            'custo_min'     => $custos->isEmpty() ? null : (float) $custos->min(),
            'custo_max'     => $custos->isEmpty() ? null : (float) $custos->max(),
        ];
    }

    /**
     * As pendências do sistema, unidas entre as variações, na ordem fixa de {@see PendenciasDoProduto::ORDEM}
     * e com os rótulos dela. A entrada é montada como no Funil (com o título, que conta desde 19/10/2026).
     *
     * Produto sem variação nenhuma é avaliado como uma linha vazia: sem variação não há medida, peso nem
     * custo — melhor a lista dizer o que falta do que o produto passar por completo.
     *
     * @return list<string>
     */
    private static function pendencias(EstruturaProduto $produto, ?string $modalidade): array
    {
        $ambientes = $produto->ambientes->pluck('nome')->all();
        $achadas = [];

        foreach ($produto->variacoes->isEmpty() ? [null] : $produto->variacoes->all() as $v) {
            $volumes = $v === null ? [] : $v->volumes
                ->map(fn ($vol) => ['c' => (float) $vol->comprimento, 'l' => (float) $vol->largura, 'a' => (float) $vol->altura, 'kg' => (float) $vol->peso])
                ->values()->all();

            $codigos = PendenciasDoProduto::daLinha([
                'volumes'         => $volumes,
                'custo'           => $v?->custo,
                'categoria_ml_id' => $produto->categoria_ml_id,
                'titulo'          => $produto->titulo,
                'familia'         => $produto->familia?->nome,
                'ambientes'       => $ambientes,
                'logistica'       => LogisticaProduto::daVolumes($volumes, $modalidade)['logistica'],
            ]);
            foreach ($codigos as $codigo) {
                $achadas[$codigo] = true;
            }
        }

        $rotulos = [];
        foreach (PendenciasDoProduto::ORDEM as $codigo) {
            if (isset($achadas[$codigo])) {
                $rotulos[] = PendenciasDoProduto::ROTULOS[$codigo];
            }
        }

        return $rotulos;
    }

    /**
     * Quantas fotos cada variação leva, na ordem das variações. Com UM eixo, as dela. Com cor E tamanho (dois eixos,
     * 28/10/2026), as fotos são da COR e moram no 1º tamanho dela: o tamanho sem foto própria conta as da cor — a mesma
     * regra com que o Publicador as leva ao rascunho (`PortalParaRascunhoService::comFotosDaCor`; mudou lá, muda aqui).
     * Antes, o produto de moda com 2 tamanhos ou mais nunca saía da Lista ("fotos (0 de 3)"), embora a tela dissesse
     * "3 fotos em cada cor" (3ª entrega da trilha, F0, 02/11/2026).
     *
     * @param  array<int, int>  $fotosPorVariacao  variacao_id → quantas fotos tem
     * @return Collection<int, int>
     */
    private static function fotosDeCadaVariacao(EstruturaProduto $produto, array $fotosPorVariacao): Collection
    {
        $variacoes = $produto->variacoes->values();
        $proprias = $variacoes->map(fn ($v) => (int) ($fotosPorVariacao[(int) $v->id] ?? 0));
        if (! $variacoes->contains(fn ($v) => trim((string) $v->eixo_2) !== '')) {
            return $proprias;
        }

        // A da cor = a da 1ª variação dessa cor que tem foto (o mesmo laço do Publicador).
        $daCor = [];
        foreach ($variacoes as $i => $v) {
            $cor = ChaveCanonica::texto((string) $v->valor);
            if ($proprias[$i] > 0 && ! isset($daCor[$cor])) {
                $daCor[$cor] = $proprias[$i];
            }
        }

        return $variacoes->map(fn ($v, $i) => $proprias[$i] > 0 ? $proprias[$i] : ($daCor[ChaveCanonica::texto((string) $v->valor)] ?? 0));
    }

    /**
     * Os eixos usados em alguma variação do produto — os DOIS (`eixo` e `eixo_2`), como em
     * {@see FichaTecnicaDoProduto::eixosDoProduto()}; o campo da ficha que é um deles vem da variação.
     *
     * @return list<string>
     */
    private static function eixos(EstruturaProduto $produto): array
    {
        return $produto->variacoes
            ->flatMap(fn ($v) => [$v->eixo, $v->eixo_2])
            ->map(fn ($e) => trim((string) $e))
            ->filter(fn ($e) => $e !== '')
            ->unique()->values()->all();
    }

    /**
     * Os nomes dos campos obrigatórios da ficha que estão em branco, na ordem da definição. A régua do
     * preparo no Publicador e do Funil: sem o Modelo (gerado depois) e sem os eixos do produto; preenchido
     * é `valor` com texto ou `valor_id` com opção — "não se aplica" (`-1`) não vale em obrigatório.
     *
     * @param  list<string>  $eixos
     * @return list<string>
     */
    private static function obrigatoriosEmBranco(array $definicao, array $eixos, Collection $salvos): array
    {
        $porId = $salvos->keyBy('atributo_id');
        $emBranco = [];

        foreach (FichaTecnicaDaCategoria::camposPorId(FichaTecnicaDaCategoria::doProduto($definicao, $eixos)) as $id => $campo) {
            if (empty($campo['obrigatorio']) || (string) $id === FichaTecnicaDaCategoria::ID_MODELO) {
                continue;
            }
            $s = $porId->get($id);
            $valorId = trim((string) $s?->valor_id);
            $preenchido = $s !== null
                && (trim((string) $s->valor) !== '' || ($valorId !== '' && $valorId !== FichaTecnicaDoProduto::NAO_SE_APLICA));
            if (! $preenchido) {
                $emBranco[] = (string) $campo['nome'];
            }
        }

        return $emBranco;
    }

    /**
     * O produto montado, pela ficha técnica: "180 × 90 × 76 cm" (e " · 12 kg" quando o peso do produto foi
     * informado). Null quando nenhuma medida foi preenchida. Unidades diferentes entre as medidas saem uma a uma.
     */
    private static function medidas(Collection $salvos): ?string
    {
        $porId = $salvos->keyBy('atributo_id');

        $dimensoes = [];
        foreach (self::DIMENSOES as $id) {
            $s = $porId->get($id);
            $n = self::numero($s?->valor);
            if ($n !== null) {
                $dimensoes[] = [$n, trim((string) $s->unidade)];
            }
        }

        $partes = [];
        if ($dimensoes !== []) {
            $unidades = array_values(array_unique(array_column($dimensoes, 1)));
            if (count($unidades) === 1) {
                $partes[] = trim(implode(' × ', array_map(fn ($d) => self::br($d[0]), $dimensoes)).' '.$unidades[0]);
            } else {
                $partes[] = implode(' × ', array_map(fn ($d) => trim(self::br($d[0]).' '.$d[1]), $dimensoes));
            }
        }

        $peso = $porId->get(self::PESO);
        $kg = self::numero($peso?->valor);
        if ($kg !== null) {
            $partes[] = trim(self::br($kg).' '.trim((string) $peso->unidade));
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * A embalagem: o 1º volume da 1ª variação, "186 × 96 × 18 cm · 42 kg", com " · 2 volumes" quando a
     * variação vai em mais de uma caixa. Medida ou peso zerados ficam de fora (são pendência, não dado).
     */
    private static function embalagem(EstruturaProduto $produto): ?string
    {
        $volumes = $produto->variacoes->first()?->volumes;
        $vol = $volumes?->first();
        if ($vol === null) {
            return null;
        }

        $partes = [];
        $c = (float) $vol->comprimento;
        $l = (float) $vol->largura;
        $a = (float) $vol->altura;
        if ($c > 0 && $l > 0 && $a > 0) {
            $partes[] = self::br($c).' × '.self::br($l).' × '.self::br($a).' cm';
        }
        if ((float) $vol->peso > 0) {
            $partes[] = self::br((float) $vol->peso).' kg';
        }
        if ($volumes->count() > 1) {
            $partes[] = $volumes->count().' volumes';
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /** Número positivo gravado na ficha ("180", "12.5"); null para vazio, "não se aplica" ou texto. */
    private static function numero(mixed $valor): ?float
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if ($texto === '' || ! is_numeric($texto)) {
            return null;
        }
        $n = (float) $texto;

        return $n > 0 ? $n : null;
    }

    /** 180 → "180"; 12.5 → "12,5"; 1500 → "1.500" (sem zeros à direita). */
    private static function br(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }
}
