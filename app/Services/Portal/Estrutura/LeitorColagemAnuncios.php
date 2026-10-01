<?php

namespace App\Services\Portal\Estrutura;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use Illuminate\Support\Str;

/**
 * Lê o texto colado na tela "Colar anúncios" e devolve linhas normalizadas —
 * SEM consultar o banco. É o ÚNICO lugar que sabe o nome das colunas e o
 * vocabulário aceito, com teste próprio (`LeitorColagemAnunciosTest`).
 *
 * ### Este é o ponto que mais vai precisar de ajuste
 * Ainda não houve uma exportação real de anúncios do ML para calibrar. Os
 * nomes abaixo vêm de duas fontes documentadas no repositório:
 * - a aba "Anúncios" da planilha do Projeto Polos (SKU, Código MLB, Título,
 *   Tipo, Catálogo?, Status);
 * - `ml_acervo_itens`, que modela anúncio do ML (`ml_item_id`, `title`,
 *   `listing_type_id`, `status`, `catalog_listing`) — e o vocabulário
 *   `gold_special` = Clássico, `gold_pro` = Premium de
 *   `2026_07_13_100001_alter_ml_anuncio_rascunhos_add_empresa_tier_sku.php`.
 *
 * NÃO calibrar pelos `Anunciar-*.xlsx`: são o template de PUBLICAÇÃO em massa
 * do ML, não exportação do que já existe. Quando a primeira exportação real
 * aparecer, o ajuste é acrescentar sinônimos em {@see self::CABECALHOS} e um
 * caso no teste.
 *
 * ### Formato
 * Colunas separadas por TAB — o que se copia de Excel ou Sheets. Com linha de
 * cabeçalho (duas ou mais colunas reconhecidas), mapeia por nome; sem ela,
 * vale a ordem da aba Anúncios da planilha ({@see self::ORDEM_DA_PLANILHA}).
 *
 * ### Mínimo por linha
 * SKU + tipo, que é o que a aula documenta ("Cole aqui o SKU e o tipo"), ou
 * MLB + tipo — no ML o SKU é o atributo `SELLER_SKU`, não campo de primeira
 * classe, e a exportação pode vir sem ele.
 */
final class LeitorColagemAnuncios
{
    /** Teto por colagem — acima do maior seller da carteira (2.688 ativos). */
    public const MAX_LINHAS = 5000;

    /** A aba "Anúncios" da planilha, coluna B em diante; kit virtual opcional ao fim. */
    public const ORDEM_DA_PLANILHA = ['sku', 'codigo_mlb', 'titulo', 'tipo', 'catalogo', 'status', 'kit_virtual'];

    /**
     * Nomes de coluna aceitos, por campo. Comparados depois de
     * {@see self::chave()}: sem acento, sem caixa, `_`/`?` viram espaço.
     */
    public const CABECALHOS = [
        'sku'         => ['sku', 'seller sku', 'sku do vendedor', 'codigo sku'],
        'codigo_mlb'  => ['codigo mlb', 'mlb', 'ml item id', 'item id', 'id do anuncio', 'codigo do anuncio', 'numero do anuncio', 'numero da publicacao'],
        'titulo'      => ['titulo', 'titulo do anuncio', 'title'],
        'tipo'        => ['tipo', 'tipo de anuncio', 'tipo de publicacao', 'listing type id', 'listing type'],
        'catalogo'    => ['catalogo', 'catalog listing', 'e catalogo'],
        'status'      => ['status', 'situacao', 'estado'],
        'kit_virtual' => ['kit virtual'],
    ];

    /** Tipo do anúncio. `gold_*` é o `listing_type_id` do ML. */
    public const TIPOS = [
        'classico'     => EstruturaAnuncio::TIPO_CLASSICO,
        'gold special' => EstruturaAnuncio::TIPO_CLASSICO,
        'premium'      => EstruturaAnuncio::TIPO_PREMIUM,
        'gold pro'     => EstruturaAnuncio::TIPO_PREMIUM,
    ];

    /**
     * Status. Em branco = ativo, como na planilha (lá `"<>Inativo"` contava a
     * célula vazia). `under_review` é pausado: o anúncio existe e não foi
     * encerrado.
     */
    public const STATUS = [
        ''             => EstruturaAnuncio::STATUS_ATIVO,
        'ativo'        => EstruturaAnuncio::STATUS_ATIVO,
        'active'       => EstruturaAnuncio::STATUS_ATIVO,
        'pausado'      => EstruturaAnuncio::STATUS_PAUSADO,
        'paused'       => EstruturaAnuncio::STATUS_PAUSADO,
        'under review' => EstruturaAnuncio::STATUS_PAUSADO,
        'inativo'      => EstruturaAnuncio::STATUS_INATIVO,
        'inactive'     => EstruturaAnuncio::STATUS_INATIVO,
        'closed'       => EstruturaAnuncio::STATUS_INATIVO,
        'finalizado'   => EstruturaAnuncio::STATUS_INATIVO,
    ];

    /** Sim/Não. Em branco = não. */
    public const BOOLEANOS = [
        ''      => false,
        'sim'   => true,  's' => true, 'true' => true, '1' => true, 'yes' => true, 'verdadeiro' => true, 'x' => true,
        'nao'   => false, 'n' => false, 'false' => false, '0' => false, 'no' => false, 'falso' => false,
    ];

    /**
     * @return array{
     *   erro_geral: ?string,
     *   cabecalho: bool,
     *   colunas: array<int, ?string>,
     *   linhas: array<int, array{numero: int, sku: ?string, codigo_mlb: ?string, titulo: ?string, tipo: string, status: string, catalogo: bool, kit_virtual: bool}>,
     *   erros: array<int, array{numero: int, texto: string, motivo: string}>
     * }
     *
     * `$maxLinhas`: teto de linhas. A importação pela API passa o dela, porque
     * a maior conta da carteira tem 66.747 anúncios.
     */
    public function ler(string $texto, ?int $maxLinhas = null): array
    {
        $maxLinhas ??= self::MAX_LINHAS;
        $resultado = ['erro_geral' => null, 'cabecalho' => false, 'colunas' => [], 'linhas' => [], 'erros' => []];

        $brutas = preg_split('/\r\n|\r|\n/', $texto);
        $linhas = [];
        foreach ($brutas as $i => $bruta) {
            if (trim($bruta) !== '') {
                $linhas[$i + 1] = $bruta;
            }
        }

        if (! $linhas) {
            $resultado['erro_geral'] = 'Nada foi colado.';

            return $resultado;
        }

        if (count($linhas) > $maxLinhas + 1) {
            $resultado['erro_geral'] = 'Cole no máximo '.number_format($maxLinhas, 0, ',', '.').' anúncios por vez.';

            return $resultado;
        }

        $primeiroNumero = array_key_first($linhas);
        $colunas = $this->colunasDoCabecalho($linhas[$primeiroNumero]);

        if ($colunas !== null) {
            $resultado['cabecalho'] = true;
            unset($linhas[$primeiroNumero]);

            if (! in_array('tipo', $colunas, true)
                || (! in_array('sku', $colunas, true) && ! in_array('codigo_mlb', $colunas, true))) {
                $resultado['erro_geral'] = 'O cabeçalho precisa ter a coluna Tipo e ao menos uma entre SKU e Código MLB.';
                $resultado['colunas'] = $colunas;

                return $resultado;
            }
        } else {
            $colunas = self::ORDEM_DA_PLANILHA;
        }

        $resultado['colunas'] = $colunas;

        $vistosMlb = [];
        $vistosSemMlb = [];

        foreach ($linhas as $numero => $bruta) {
            $celulas = explode("\t", $bruta);
            $campos = [];
            foreach ($colunas as $i => $campo) {
                if ($campo !== null) {
                    $campos[$campo] = trim($celulas[$i] ?? '');
                }
            }

            [$linha, $motivo] = $this->interpretar($numero, $campos);

            if ($motivo === null && $linha['codigo_mlb'] !== null) {
                if (isset($vistosMlb[$linha['codigo_mlb']])) {
                    $motivo = "O MLB {$linha['codigo_mlb']} já apareceu na linha {$vistosMlb[$linha['codigo_mlb']]}.";
                } else {
                    $vistosMlb[$linha['codigo_mlb']] = $numero;
                }
            } elseif ($motivo === null) {
                // Sem MLB, a chave é SKU + tipo: duas linhas iguais seriam o
                // mesmo anúncio, e não há como saber qual das duas vale.
                $chave = EstruturaOferta::normalizarSku($linha['sku']).'|'.$linha['tipo'];
                if (isset($vistosSemMlb[$chave])) {
                    $motivo = "Mesmo SKU e tipo, sem MLB, da linha {$vistosSemMlb[$chave]}. Informe o MLB para distinguir os dois.";
                } else {
                    $vistosSemMlb[$chave] = $numero;
                }
            }

            if ($motivo !== null) {
                $resultado['erros'][] = ['numero' => $numero, 'texto' => mb_substr($bruta, 0, 200), 'motivo' => $motivo];
                continue;
            }

            $resultado['linhas'][] = $linha;
        }

        return $resultado;
    }

    /**
     * Normalização dos nomes e dos valores: sem acento, minúsculas, `_` e `?`
     * viram espaço, espaços colapsados. "Catálogo?" e "catalog_listing" chegam
     * aqui como "catalogo" e "catalog listing".
     */
    public static function chave(string $texto): string
    {
        $t = Str::ascii(mb_strtolower(trim($texto)));
        $t = str_replace(['_', '?', ':'], ' ', $t);

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    /**
     * A primeira linha é cabeçalho quando DUAS ou mais células são nomes
     * conhecidos. Com uma só, "Premium" numa linha de dados sem cabeçalho não
     * pode ser confundido com o nome da coluna Tipo.
     *
     * @return array<int, ?string>|null campo por posição (null = coluna ignorada)
     */
    private function colunasDoCabecalho(string $linha): ?array
    {
        $colunas = [];
        $reconhecidas = 0;

        foreach (explode("\t", $linha) as $celula) {
            $chave = self::chave($celula);
            $campo = null;

            foreach (self::CABECALHOS as $nome => $sinonimos) {
                if (in_array($chave, $sinonimos, true) && ! in_array($nome, $colunas, true)) {
                    $campo = $nome;
                    break;
                }
            }

            $colunas[] = $campo;
            if ($campo !== null) {
                $reconhecidas++;
            }
        }

        return $reconhecidas >= 2 ? $colunas : null;
    }

    /** @return array{0: array, 1: ?string} a linha e o motivo da recusa, se houver */
    private function interpretar(int $numero, array $campos): array
    {
        $sku = ($campos['sku'] ?? '') === '' ? null : mb_substr($campos['sku'], 0, 120);

        $mlbBruto = $campos['codigo_mlb'] ?? '';
        $mlb = EstruturaAnuncio::normalizarMlb($mlbBruto);

        $tipo = self::TIPOS[self::chave($campos['tipo'] ?? '')] ?? null;
        $status = self::STATUS[self::chave($campos['status'] ?? '')] ?? null;
        $catalogo = self::BOOLEANOS[self::chave($campos['catalogo'] ?? '')] ?? null;
        $kit = self::BOOLEANOS[self::chave($campos['kit_virtual'] ?? '')] ?? null;

        $motivo = match (true) {
            $mlbBruto !== '' && $mlb === null => "Código MLB irreconhecível: \"{$mlbBruto}\".",
            $sku === null && $mlb === null => 'Sem SKU e sem código MLB — não há como saber de que anúncio se trata.',
            ($campos['tipo'] ?? '') === '' => 'Sem tipo. Informe Clássico ou Premium.',
            $tipo === null => "Tipo irreconhecível: \"{$campos['tipo']}\". Use Clássico ou Premium.",
            $status === null => "Status irreconhecível: \"{$campos['status']}\". Use Ativo, Pausado ou Inativo.",
            $catalogo === null => "Catálogo irreconhecível: \"{$campos['catalogo']}\". Use Sim ou Não.",
            $kit === null => "Kit virtual irreconhecível: \"{$campos['kit_virtual']}\". Use Sim ou Não.",
            default => null,
        };

        $titulo = ($campos['titulo'] ?? '') === '' ? null : mb_substr($campos['titulo'], 0, 255);

        return [[
            'numero'      => $numero,
            'sku'         => $sku,
            'codigo_mlb'  => $mlb,
            'titulo'      => $titulo,
            'tipo'        => $tipo ?? '',
            'status'      => $status ?? '',
            'catalogo'    => (bool) $catalogo,
            'kit_virtual' => (bool) $kit,
        ], $motivo];
    }
}
