<?php

namespace App\Services\Portal\Estrutura\Produtos;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Lê a aba "Produtos" de um .xlsx enviado pelo cliente, sem confiar nele (D-13).
 *
 * - Confere tamanho e assinatura zip (PK) ANTES de abrir; leitor fixo Xlsx.
 * - `setReadDataOnly(true)` e nada de calcular fórmula. Em coluna de número
 *   "=1+1" volta como texto e cai na validação do custo; em coluna de texto vale
 *   o valor que o Excel salvou em cache, e sem cache a linha é recusada (BE-WR-02).
 * - Qualquer exceção do PhpSpreadsheet vira a mensagem fixa; o detalhe só vai
 *   para o log (o cliente nunca vê o texto da exceção).
 *
 * ### Limite ANTES de montar qualquer coisa (BE-WR-01)
 * O `.xlsx` declara a própria dimensão: uma célula vazia em `XFD1048576` num
 * arquivo de poucos KB fazia o `toArray()` tentar 17 bilhões de posições, e o
 * estouro de memória é fatal (não cai em nenhum catch). Por isso, nesta ordem:
 * 1. soma do descompactado do zip e tamanho do XML da aba (bomba de zip);
 * 2. sondagem em STREAMING (XMLReader) do XML da aba: quantas linhas têm valor e
 *    qual a última — célula vazia não conta, então o "Google Sheets exporta 1.000
 *    linhas em branco" não é recusado;
 * 3. carga só da aba certa, só das células com valor (`setReadEmptyCells(false)`),
 *    com filtro até a última linha com valor e `MAX_COLUNAS`;
 * 4. matriz ESPARSA pelas coordenadas que existem — nunca `toArray()`/`rangeToArray()`
 *   sobre a dimensão declarada.
 *
 * As colunas são casadas pelo nome normalizado (sem caixa/acento), então a
 * ordem não importa e a planilha original do Planejamento entra como está.
 */
final class LeitorPlanilhaProdutos
{
    public const MAX_LINHAS = 1000;
    public const MAX_BYTES  = 2 * 1024 * 1024;

    /** Colunas lidas: o modelo tem 11; colunas além disso não são importadas. */
    public const MAX_COLUNAS = 60;

    /** Soma do descompactado do zip (a planilha real do Planejamento, 6 abas, dá ~3 MB). */
    private const MAX_DESCOMPACTADO = 30 * 1024 * 1024;

    /** XML da aba lida (1.000 linhas × 60 colunas com estilo cabem com folga). */
    private const MAX_XML_DA_ABA = 10 * 1024 * 1024;

    /**
     * Folga da sondagem sobre as 1.000 linhas: ela conta linha com valor em QUALQUER
     * coluna lida (até anotação fora das colunas do modelo). O limite exato de 1.000
     * é conferido depois, só nas colunas que importamos.
     */
    private const FOLGA_DA_SONDAGEM = 100;

    private const MSG_ILEGIVEL = 'Não conseguimos ler este arquivo. Use o modelo (.xlsx) e confira se a aba se chama Produtos.';
    private const MSG_MUITAS   = 'A planilha tem mais de 1.000 linhas. Divida em arquivos menores.';
    private const MSG_GRANDE   = 'O arquivo é grande demais para importar. Divida a planilha em arquivos menores.';

    /** Campos cujo valor numérico da célula deve virar texto (código "1014" vem como número). */
    private const TEXTUAIS = ['codigo', 'grupo', 'variacao', 'nome', 'familia', 'ambientes', 'categoria', 'volumes_texto'];

    /** Chaves da célula com fórmula na matriz: o texto da fórmula e o valor em cache. */
    private const FORMULA = 'f';
    private const CACHE   = 'cache';

    /**
     * `erro` só vem na linha que não pode entrar (fórmula sem valor salvo em coluna de texto).
     *
     * @return array{erro_geral: ?string, colunas: list<string>, linhas: list<array{numero: int, bruta: array<string, mixed>, erro?: string}>}
     */
    public function ler(string $caminho): array
    {
        $vazio = fn (?string $erro, array $colunas = [], array $linhas = []) => ['erro_geral' => $erro, 'colunas' => $colunas, 'linhas' => $linhas];

        if (! is_file($caminho) || filesize($caminho) === false || filesize($caminho) === 0) {
            return $vazio(self::MSG_ILEGIVEL);
        }
        if (filesize($caminho) > self::MAX_BYTES) {
            return $vazio('O arquivo passa de 2 MB. Divida em arquivos menores.');
        }

        $f = fopen($caminho, 'rb');
        $assinatura = $f ? fread($f, 4) : '';
        if ($f) {
            fclose($f);
        }
        if ($assinatura !== "PK\x03\x04") {
            return $vazio(self::MSG_ILEGIVEL);
        }

        try {
            // ─── 1. Bomba de zip e escolha da aba, sem abrir planilha nenhuma ───
            $zip = new ZipArchive();
            if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
                return $vazio(self::MSG_ILEGIVEL);
            }
            try {
                $descompactado = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $descompactado += (int) ($zip->statIndex($i)['size'] ?? 0);
                }
                if ($descompactado > self::MAX_DESCOMPACTADO) {
                    return $vazio(self::MSG_GRANDE);
                }

                $aba = self::abaDeProdutos($zip);
                if ($aba === null) {
                    return $vazio(self::MSG_ILEGIVEL);
                }
                [$nomeDaAba, $entrada] = $aba;

                $tamanho = $zip->statName($entrada)['size'] ?? null;
                if ($tamanho === null) {
                    return $vazio(self::MSG_ILEGIVEL);
                }
                if ($tamanho > self::MAX_XML_DA_ABA) {
                    return $vazio(self::MSG_GRANDE);
                }
                $xmlDaAba = (string) $zip->getFromName($entrada);
            } finally {
                $zip->close();
            }

            // ─── 2. Sondagem em streaming: linhas com valor e a última delas ───
            [$comValor, $ultimaLinha] = self::sondar($xmlDaAba);
            unset($xmlDaAba);
            if ($comValor > self::MAX_LINHAS + 1 + self::FOLGA_DA_SONDAGEM) {
                return $vazio(self::MSG_MUITAS);
            }
            if ($comValor === 0) {
                return $vazio(self::MSG_ILEGIVEL);
            }

            // ─── 3. Carga limitada: só a aba, só célula com valor, só até a última linha ───
            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setReadEmptyCells(false);
            $leitor->setLoadSheetsOnly([$nomeDaAba]);
            $leitor->setReadFilter(new class($ultimaLinha, self::MAX_COLUNAS) implements IReadFilter {
                public function __construct(private int $ultimaLinha, private int $maxColunas) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->ultimaLinha
                        && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColunas;
                }
            });
            $planilha = $leitor->load($caminho);
            $folha = $planilha->getSheetByName($nomeDaAba) ?? $planilha->getSheet(0);

            // ─── 4. Matriz esparsa: só as coordenadas que existem ───
            // Célula com fórmula guarda o texto dela e o valor em cache que o Excel salvou
            // (`getOldCalculatedValue`) — nada é calculado aqui (BE-WR-02).
            $matriz = [];
            foreach ($folha->getCellCollection()->getCoordinates() as $coordenada) {
                [$coluna, $linha] = Coordinate::indexesFromString($coordenada);
                $celula = $folha->getCell($coordenada);
                $matriz[$linha][$coluna - 1] = $celula->isFormula()
                    ? [self::FORMULA => (string) $celula->getValue(), self::CACHE => $celula->getOldCalculatedValue()]
                    : $celula->getValue();
            }
            $planilha->disconnectWorksheets();
            unset($planilha, $folha);
        } catch (Throwable $e) {
            Log::warning('[Estrutura Produtos] falha ao ler a planilha: '.$e->getMessage());

            return $vazio(self::MSG_ILEGIVEL);
        }

        // ─── Cabeçalho (primeira linha da aba) ───
        $mapa = [];
        $titulos = [];
        $cabecalho = $matriz[1] ?? [];
        ksort($cabecalho);
        foreach ($cabecalho as $i => $titulo) {
            if (is_array($titulo)) {
                $titulo = $titulo[self::CACHE];
            }
            $titulo = is_scalar($titulo) ? trim((string) $titulo) : '';
            $campo = self::campoDoCabecalho($titulo);
            if ($campo !== null && ! in_array($campo, $mapa, true)) {
                $mapa[$i] = $campo;
                $titulos[$i] = $titulo;
            }
        }

        if (! in_array('codigo', $mapa, true) || ! in_array('nome', $mapa, true)) {
            return $vazio(self::MSG_ILEGIVEL);
        }

        // ─── Linhas de dados ───
        ksort($matriz);
        $linhas = [];
        foreach ($matriz as $numero => $celulas) {
            if ($numero === 1) {
                continue;
            }

            $bruta = [];
            $temAlgo = false;
            $erro = null;
            foreach ($mapa as $i => $campo) {
                $crua = $celulas[$i] ?? null;
                if (is_array($crua)) {
                    [$crua, $erroDaFormula] = self::daFormula($crua, $campo, $titulos[$i]);
                    $erro ??= $erroDaFormula;
                }
                $valor = self::valor($crua, $campo);
                if ($valor !== null && $valor !== '') {
                    $temAlgo = true;
                }
                $bruta[$campo] = $valor;
            }

            if (! $temAlgo && $erro === null) {
                continue;
            }

            $linhas[] = ['numero' => $numero, 'bruta' => $bruta] + ($erro !== null ? ['erro' => $erro] : []);

            if (count($linhas) > self::MAX_LINHAS) {
                return $vazio(self::MSG_MUITAS, array_values($mapa));
            }
        }

        return $vazio(null, array_values($mapa), $linhas);
    }

    // ═══ Zip e sondagem ═════════════════════════════════════════════════════

    /**
     * A aba "Produtos" (ou a primeira) e o caminho do XML dela dentro do zip, lidos
     * dos três XMLs pequenos do pacote: `_rels/.rels` → workbook → rels do workbook.
     * Os nomes de elemento/atributo são casados sem namespace, então serve tanto ao
     * OOXML de transição (Excel, LibreOffice, Google Sheets) quanto ao estrito.
     *
     * @return array{0: string, 1: string}|null [nome da aba, caminho no zip]
     */
    private static function abaDeProdutos(ZipArchive $zip): ?array
    {
        $workbook = 'xl/workbook.xml';
        foreach (self::filhos(self::xmlDoZip($zip, '_rels/.rels')) as $rel) {
            if (str_ends_with((string) ($rel['Type'] ?? ''), '/officeDocument')) {
                $workbook = ltrim((string) $rel['Target'], '/');
                break;
            }
        }
        $pasta = dirname($workbook) === '.' ? '' : dirname($workbook).'/';

        $alvos = [];
        foreach (self::filhos(self::xmlDoZip($zip, $pasta.'_rels/'.basename($workbook).'.rels')) as $rel) {
            $alvos[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $abas = [];
        foreach (self::xmlDoZip($zip, $workbook)->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
            $rid = null;
            foreach ($sheet->getNamespaces(true) + ['' => ''] as $ns) {
                $atributos = $sheet->attributes($ns);
                if (isset($atributos['id'])) {
                    $rid = (string) $atributos['id'];
                    break;
                }
            }
            $alvo = $rid !== null ? ($alvos[$rid] ?? null) : null;
            if ($alvo === null) {
                continue;
            }
            $abas[] = [(string) $sheet['name'], str_starts_with($alvo, '/') ? ltrim($alvo, '/') : $pasta.$alvo];
        }

        foreach ($abas as $aba) {
            if (Str::lower(trim($aba[0])) === 'produtos') {
                return $aba;
            }
        }

        return $abas[0] ?? null;
    }

    /** XML pequeno do pacote (rels/workbook). Sem rede, sem entidade externa. */
    private static function xmlDoZip(ZipArchive $zip, string $entrada): SimpleXMLElement
    {
        $conteudo = $zip->getFromName($entrada);
        if ($conteudo === false || $conteudo === '' || stripos($conteudo, '<!DOCTYPE') !== false) {
            throw new RuntimeException("entrada ausente ou recusada no zip: {$entrada}");
        }
        $xml = simplexml_load_string($conteudo, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException("XML ilegível no zip: {$entrada}");
        }

        return $xml;
    }

    /** @return list<SimpleXMLElement> filhos diretos, de qualquer namespace */
    private static function filhos(SimpleXMLElement $xml): array
    {
        return $xml->xpath('./*') ?: [];
    }

    /**
     * Percorre o XML da aba em streaming (sem DOM) e devolve quantas linhas têm algum
     * valor nas colunas lidas e qual é a última delas. Célula sem valor (`<c r=".." s=".."/>`,
     * só estilo) não conta.
     *
     * @return array{0: int, 1: int} [linhas com valor, última linha com valor]
     */
    private static function sondar(string $xmlDaAba): array
    {
        if (stripos(substr($xmlDaAba, 0, 4096), '<!DOCTYPE') !== false) {
            throw new RuntimeException('XML da aba com DOCTYPE recusado');
        }

        $xml = new XMLReader();
        if (! $xml->XML($xmlDaAba, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('XML da aba ilegível');
        }

        $comValor = 0;
        $ultima = 0;
        $linha = 0;
        $coluna = 0;
        $temValor = false;
        $fecharLinha = function () use (&$comValor, &$ultima, &$linha, &$temValor) {
            if ($temValor) {
                $comValor++;
                $ultima = max($ultima, $linha);
            }
            $temValor = false;
        };

        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($xml->localName) {
                case 'row':
                    $fecharLinha();
                    $r = $xml->getAttribute('r');
                    $linha = ($r !== null && ctype_digit($r)) ? (int) $r : $linha + 1;
                    $coluna = 0;
                    break;
                case 'c':
                    $r = (string) $xml->getAttribute('r');
                    $letras = preg_replace('/[^A-Za-z]/', '', $r);
                    $coluna = $letras !== '' ? Coordinate::columnIndexFromString(strtoupper($letras)) : $coluna + 1;
                    break;
                case 'f':
                    if ($coluna >= 1 && $coluna <= self::MAX_COLUNAS) {
                        $temValor = true;
                    }
                    break;
                case 'v':
                case 't':
                    if (! $temValor && $coluna >= 1 && $coluna <= self::MAX_COLUNAS && trim($xml->readString()) !== '') {
                        $temValor = true;
                    }
                    break;
            }
        }
        $fecharLinha();
        $xml->close();

        return [$comValor, $ultima];
    }

    // ═══ Cabeçalho e valores ════════════════════════════════════════════════

    /** Campo lógico a partir do nome da coluna; null para colunas que não importamos. */
    private static function campoDoCabecalho(string $titulo): ?string
    {
        $t = Str::lower(Str::ascii($titulo));
        $t = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $t)));

        return match (true) {
            $t === '' => null,
            $t === 'ref', $t === 'codigo', $t === 'sku', str_starts_with($t, 'ref ') => 'codigo',
            str_starts_with($t, 'grupo') => 'grupo',
            str_starts_with($t, 'variacao') => 'variacao',
            $t === 'produto', $t === 'nome', str_starts_with($t, 'produto ') => 'nome',
            str_starts_with($t, 'familia') => 'familia',
            str_starts_with($t, 'ambiente') => 'ambientes',
            str_starts_with($t, 'categoria') => 'categoria',
            (bool) preg_match('/^(n|no|num|numero|qtd|qtde)( de)? volumes/', $t) => 'n_volumes',
            str_starts_with($t, 'volumes') => 'volumes_texto',
            str_starts_with($t, 'peso total') => 'peso_total',
            str_starts_with($t, 'custo') => 'custo',
            default => null,
        };
    }

    /**
     * Célula com fórmula (BE-WR-02). Em coluna de TEXTO (Ref, grupo, nome…) vale o
     * valor em cache que o Excel salvou — "=B2&\"-\"&C2" vira "1014-1", não o código
     * literal "=B2&…". Sem cache (o arquivo foi gerado sem calcular) ou com erro de
     * fórmula (#REF!…), a linha não entra e a pessoa é avisada. Em coluna de NÚMERO
     * a fórmula volta como texto e cai na validação, como sempre foi.
     *
     * @param  array{f: string, cache: mixed}  $formula
     * @return array{0: mixed, 1: ?string} [valor, erro da linha]
     */
    private static function daFormula(array $formula, string $campo, string $titulo): array
    {
        if (! in_array($campo, self::TEXTUAIS, true)) {
            return [$formula[self::FORMULA], null];
        }

        $cache = $formula[self::CACHE];
        if ($cache === null || (is_string($cache) && array_key_exists($cache, DataType::getErrorCodes()))) {
            return [null, "{$titulo} com fórmula — cole como valor."];
        }

        return [$cache, null];
    }

    private static function valor(mixed $v, string $campo): mixed
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' ? null : $v;
        }
        if (is_int($v) || is_float($v)) {
            if (in_array($campo, self::TEXTUAIS, true)) {
                return is_float($v) && floor($v) !== $v ? rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') : (string) (int) $v;
            }

            return $v;
        }

        return null;
    }
}
