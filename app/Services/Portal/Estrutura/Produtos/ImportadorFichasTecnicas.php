<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Publicador\PreparoIaAgenda;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;
use ZipArchive;

/**
 * A volta da planilha da ficha técnica ({@see PlanilhaDasFichasTecnicas}), no padrão
 * "prévia/aplicar" da importação de produtos: o arquivo é relido e o plano refeito ao confirmar.
 *
 * - Cada linha volta para o produto pela coluna "Produto (grupo)" (o código do produto, ou a Ref
 *   de uma variação dele), só dentro da empresa recebida. O NOME da aba não importa.
 * - Cada coluna volta para o campo pelo NOME, na definição ATUAL da categoria do produto (a
 *   categoria pode ter mudado depois do download): coluna que não é campo é ignorada com aviso.
 * - Grava MESCLANDO, pelo `FichaTecnicaDoProduto::gravarParcial` (a mesma validação da tela):
 *   célula em branco não apaga; valor inválido não grava e é listado; obrigatório que continua
 *   vazio não impede o resto e aparece em "faltam".
 *
 * ### Arquivo do cliente, sem confiar nele
 * Tamanho, assinatura zip, soma do descompactado e o XML de cada aba são conferidos ANTES de
 * abrir; a leitura é só de dados, só das células com valor, até `MAX_LINHAS` por aba e
 * `MAX_COLUNAS`, pela coleção de células (nunca pela dimensão declarada). Fórmula vale pelo
 * valor que o Excel salvou; sem ele, a célula é tratada como vazia.
 */
class ImportadorFichasTecnicas
{
    public const MAX_LINHAS = 1000;
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const DETALHE_MAXIMO = 200;

    private const MAX_COLUNAS = 200;
    private const MAX_DESCOMPACTADO = 30 * 1024 * 1024;
    private const MAX_XML_DA_ABA = 10 * 1024 * 1024;

    private const MSG_ILEGIVEL = 'Não conseguimos ler este arquivo. Use a planilha da ficha técnica (.xlsx) baixada aqui.';
    private const MSG_GRANDE = 'O arquivo é grande demais para importar. Divida a planilha em arquivos menores.';

    public function __construct(
        private FichaTecnicaDoProduto $ficha,
        private FichaTecnicaDaCategoria $definicao,
    ) {}

    /**
     * Só lê: o que cada produto ganharia, o que não entra e o que ainda falta.
     *
     * @return array{erro_geral: ?string, totais: array{produtos: int, campos: int, com_erro: int, com_falta: int, nao_encontrados: int}, produtos: list<array>, avisos: list<string>}
     */
    public function previa(Company $empresa, string $caminho): array
    {
        $plano = $this->plano($empresa, $caminho);
        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral'], 'totais' => self::totais([]), 'produtos' => [], 'avisos' => []];
        }

        $itens = [];
        foreach ($plano['itens'] as $item) {
            if ($item['produto'] === null) {
                $itens[] = self::saidaDoItem($item, 0, [], []);
                continue;
            }
            try {
                $p = $this->ficha->planejarParcial($item['produto'], $item['entradas']);
                $itens[] = self::saidaDoItem($item, count($p['validos']), array_values($p['erros']), $p['faltam']);
            } catch (ValidationException $e) {
                $itens[] = self::saidaDoItem($item, 0, [(string) collect($e->errors())->flatten()->first()], []);
            }
        }

        return [
            'erro_geral' => null,
            'totais'     => self::totais($itens),
            'produtos'   => array_slice($itens, 0, self::DETALHE_MAXIMO),
            'avisos'     => $plano['avisos'],
        ];
    }

    /**
     * Grava. Cada produto é uma gravação: a falha de um não derruba os outros.
     *
     * @return array{erro_geral?: string, produtos: int, campos: int, com_erro: int, com_falta: int, nao_encontrados: int, nao_entraram: list<string>}
     */
    public function aplicar(Company $empresa, string $caminho, AtorDoPortal $ator): array
    {
        $plano = $this->plano($empresa, $caminho);
        if ($plano['erro_geral'] !== null) {
            return ['erro_geral' => $plano['erro_geral']] + self::totais([]) + ['nao_entraram' => []];
        }

        $itens = [];
        $gravados = [];
        foreach ($plano['itens'] as $item) {
            if ($item['produto'] === null) {
                $itens[] = self::saidaDoItem($item, 0, [], []);
                continue;
            }
            try {
                $r = $this->ficha->gravarParcial($empresa, $item['produto'], $item['entradas'], $ator);
                $itens[] = self::saidaDoItem($item, $r['gravados'], array_values($r['erros']), $r['faltam']);
                if ($r['gravados'] > 0) {
                    $gravados[(int) $item['produto']->id] = true;
                }
            } catch (ValidationException $e) {
                $itens[] = self::saidaDoItem($item, 0, [(string) collect($e->errors())->flatten()->first()], []);
            }
        }

        // O produto mudou: o preparo dele é agendado, como no salvar da ficha na tela.
        if ($gravados !== []) {
            app(PreparoIaAgenda::class)->aoSalvar((int) $empresa->id, array_keys($gravados));
        }

        $naoEntraram = [];
        foreach ($itens as $i) {
            foreach ($i['erros'] as $erro) {
                $naoEntraram[] = "{$i['grupo']}: {$erro}";
            }
        }

        return self::totais($itens) + ['nao_entraram' => $naoEntraram];
    }

    // ═══ Plano ══════════════════════════════════════════════════════════════

    /**
     * @return array{erro_geral: ?string, itens: list<array{aba: string, linha: int, grupo: string, produto: ?EstruturaProduto, entradas: array<string, array>, colunas: array<string, string>, motivo: ?string}>, avisos: list<string>}
     */
    private function plano(Company $empresa, string $caminho): array
    {
        $lido = $this->ler($caminho);
        if ($lido['erro_geral'] !== null) {
            return ['erro_geral' => $lido['erro_geral'], 'itens' => [], 'avisos' => []];
        }

        [$porCodigo, $porRef] = $this->produtosDaEmpresa($empresa);
        $definicoes = [];
        $avisos = [];
        $itens = [];
        $vistos = [];

        foreach ($lido['abas'] as $aba) {
            $ignoradas = [];
            foreach ($aba['linhas'] as $linha) {
                $grupo = trim((string) ($linha[$aba['coluna_grupo']] ?? ''));
                if ($grupo === '') {
                    continue;
                }
                $chave = ProdutoCadastroService::chaveCodigo($grupo);
                $produto = $porCodigo[$chave] ?? $porRef[$chave] ?? null;
                $item = ['aba' => $aba['nome'], 'linha' => $linha['__linha'], 'grupo' => $grupo, 'produto' => $produto, 'entradas' => [], 'colunas' => [], 'motivo' => null];

                if ($produto === null) {
                    $item['motivo'] = "Não achamos o produto {$grupo}. Não mude a coluna Produto (grupo).";
                    $itens[] = $item;
                    continue;
                }
                if (isset($vistos[$produto->id])) {
                    $avisos[] = "{$aba['nome']}, linha {$linha['__linha']}: o produto {$grupo} já veio na linha {$vistos[$produto->id]}; vale a primeira.";
                    continue;
                }
                $vistos[$produto->id] = "{$linha['__linha']} ({$aba['nome']})";

                $categoria = trim((string) $produto->categoria_ml_id);
                if ($categoria === '') {
                    // Sem categoria não há ficha: o `planejarParcial` devolve a mensagem certa.
                    $itens[] = $item;
                    continue;
                }
                $campos = $definicoes[$categoria] ??= $this->camposPorChave($categoria);

                foreach ($aba['cabecalhos'] as $coluna => $titulo) {
                    if ($coluna === $aba['coluna_grupo'] || $coluna === $aba['coluna_nome']) {
                        continue;
                    }
                    $campo = null;
                    foreach (PlanilhaDasFichasTecnicas::chavesDoCabecalho($titulo) as $k) {
                        $campo ??= $campos[$k] ?? null;
                    }
                    if ($campo === null) {
                        $ignoradas[$titulo] = true;
                        continue;
                    }
                    $bruto = $linha[$coluna] ?? null;
                    if ($bruto === null || (is_string($bruto) && trim($bruto) === '')) {
                        continue; // em branco não apaga
                    }
                    $item['entradas'][$campo['id']] = self::entrada($campo, $bruto);
                    $item['colunas'][$campo['id']] = $titulo;
                }
                $itens[] = $item;
            }
            foreach (array_keys($ignoradas) as $titulo) {
                $avisos[] = "{$aba['nome']}: a coluna “{$titulo}” não é um campo da ficha desta categoria e foi ignorada.";
            }
        }

        return ['erro_geral' => null, 'itens' => $itens, 'avisos' => $avisos];
    }

    /**
     * A célula → a entrada que a tela mandaria: lista pelo id da opção (casada pelo nome),
     * várias opções separadas por "|", "Não se aplica" pelo marcador, número com a unidade
     * escrita ao lado ("50 mm") ou a da coluna.
     */
    private static function entrada(array $campo, mixed $bruto): array
    {
        $texto = is_bool($bruto) ? ($bruto ? 'Sim' : 'Não') : trim((string) $bruto);

        if (PlanilhaDasFichasTecnicas::chave($texto) === PlanilhaDasFichasTecnicas::chave(PlanilhaDasFichasTecnicas::NAO_SE_APLICA)) {
            return ['nao_se_aplica' => true];
        }

        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_LISTA) {
            if ($campo['multivalor'] ?? false) {
                $ids = [];
                foreach (explode('|', $texto) as $parte) {
                    if (trim($parte) === '') {
                        continue;
                    }
                    // Nome que não é opção vai como veio: a validação devolve "Escolha uma das opções".
                    $ids[] = PlanilhaDasFichasTecnicas::opcaoPorTexto($campo, $parte)['id'] ?? trim($parte);
                }

                return ['valor' => $ids];
            }

            return ['valor' => PlanilhaDasFichasTecnicas::opcaoPorTexto($campo, $texto)['id'] ?? $texto];
        }

        if ($campo['tipo'] === FichaTecnicaDaCategoria::TIPO_NUMERO_UNIDADE && is_string($bruto)
            && preg_match('/^([\d.,]+)\s*([^\d\s.,][^\d]*)$/u', $texto, $m)) {
            return ['valor' => $m[1], 'unidade' => trim($m[2])];
        }

        return ['valor' => is_float($bruto) || is_int($bruto) ? $bruto : $texto];
    }

    /** Os campos da categoria pela chave do nome (o id nunca vai para a planilha). */
    private function camposPorChave(string $categoria): array
    {
        try {
            $campos = FichaTecnicaDaCategoria::camposPorId($this->definicao->definicao($categoria));
        } catch (Throwable $e) {
            Log::warning("[Estrutura Produtos] ficha da categoria {$categoria} indisponível na importação", ['erro' => $e->getMessage()]);
            $campos = [];
        }

        $saida = [];
        foreach ($campos as $campo) {
            $saida[PlanilhaDasFichasTecnicas::chave($campo['nome'])] ??= $campo;
        }

        return $saida;
    }

    /** @return array{0: array<string, EstruturaProduto>, 1: array<string, EstruturaProduto>} por código do produto e pela Ref das variações */
    private function produtosDaEmpresa(Company $empresa): array
    {
        $produtos = EstruturaProduto::query()->where('company_id', $empresa->id)->get()->keyBy('id');
        $porCodigo = [];
        foreach ($produtos as $p) {
            if (trim((string) $p->codigo) !== '') {
                $porCodigo[ProdutoCadastroService::chaveCodigo($p->codigo)] ??= $p;
            }
        }
        $porRef = [];
        EstruturaProdutoVariacao::query()->where('company_id', $empresa->id)->get(['id', 'produto_id', 'codigo'])
            ->each(function (EstruturaProdutoVariacao $v) use (&$porRef, $produtos) {
                if (isset($produtos[$v->produto_id])) {
                    $porRef[ProdutoCadastroService::chaveCodigo($v->codigo)] ??= $produtos[$v->produto_id];
                }
            });

        return [$porCodigo, $porRef];
    }

    private static function saidaDoItem(array $item, int $campos, array $erros, array $faltam): array
    {
        return [
            'aba'     => $item['aba'],
            'linha'   => $item['linha'],
            'grupo'   => $item['grupo'],
            'nome'    => $item['produto']?->nome,
            'campos'  => $campos,
            'erros'   => $item['motivo'] !== null ? [$item['motivo']] : array_values($erros),
            'faltam'  => array_values($faltam),
            'achado'  => $item['produto'] !== null,
        ];
    }

    /** @return array{produtos: int, campos: int, com_erro: int, com_falta: int, nao_encontrados: int} */
    private static function totais(array $itens): array
    {
        $achados = array_filter($itens, fn ($i) => $i['achado']);

        return [
            'produtos'        => count(array_filter($achados, fn ($i) => $i['campos'] > 0)),
            'campos'          => array_sum(array_column($achados, 'campos')),
            'com_erro'        => count(array_filter($achados, fn ($i) => $i['erros'] !== [])),
            'com_falta'       => count(array_filter($achados, fn ($i) => $i['faltam'] !== [])),
            'nao_encontrados' => count($itens) - count($achados),
        ];
    }

    // ═══ Leitura do arquivo ═════════════════════════════════════════════════

    /**
     * As abas de produtos do arquivo (a de instruções e a oculta de listas ficam de fora), cada
     * uma com os cabeçalhos e as linhas — só as células com valor.
     *
     * @return array{erro_geral: ?string, abas: list<array{nome: string, cabecalhos: array<int, string>, coluna_grupo: int, coluna_nome: ?int, linhas: list<array>}>}
     */
    private function ler(string $caminho): array
    {
        $falha = fn (string $msg) => ['erro_geral' => $msg, 'abas' => []];

        if (! is_file($caminho) || (int) filesize($caminho) === 0) {
            return $falha(self::MSG_ILEGIVEL);
        }
        if (filesize($caminho) > self::MAX_BYTES) {
            return $falha('O arquivo passa de 2 MB. Divida em arquivos menores.');
        }
        $f = fopen($caminho, 'rb');
        $assinatura = $f ? fread($f, 4) : '';
        if ($f) {
            fclose($f);
        }
        if ($assinatura !== "PK\x03\x04") {
            return $falha(self::MSG_ILEGIVEL);
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($caminho, ZipArchive::RDONLY) !== true) {
                return $falha(self::MSG_ILEGIVEL);
            }
            try {
                $total = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $total += (int) ($stat['size'] ?? 0);
                    if (str_starts_with((string) ($stat['name'] ?? ''), 'xl/worksheets/') && (int) ($stat['size'] ?? 0) > self::MAX_XML_DA_ABA) {
                        return $falha(self::MSG_GRANDE);
                    }
                }
                if ($total > self::MAX_DESCOMPACTADO) {
                    return $falha(self::MSG_GRANDE);
                }
            } finally {
                $zip->close();
            }

            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setReadEmptyCells(false);
            $leitor->setReadFilter(new class(self::MAX_LINHAS + 1, self::MAX_COLUNAS) implements IReadFilter {
                public function __construct(private int $maxLinha, private int $maxColuna) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->maxLinha && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColuna;
                }
            });
            $planilha = $leitor->load($caminho);

            $abas = [];
            foreach ($planilha->getAllSheets() as $folha) {
                $nome = $folha->getTitle();
                if (in_array(mb_strtolower($nome), [mb_strtolower(PlanilhaDasFichasTecnicas::ABA_INSTRUCOES), mb_strtolower(PlanilhaDasFichasTecnicas::ABA_LISTAS)], true)) {
                    continue;
                }
                $matriz = [];
                foreach ($folha->getCellCollection()->getCoordinates() as $coordenada) {
                    [$coluna, $linha] = Coordinate::indexesFromString($coordenada);
                    $celula = $folha->getCell($coordenada);
                    $valor = $celula->getValue();
                    if ($celula->isFormula()) {
                        $cache = $celula->getOldCalculatedValue();
                        $valor = ($cache === null || (is_string($cache) && array_key_exists($cache, DataType::getErrorCodes()))) ? null : $cache;
                    }
                    if ($valor !== null && ! is_scalar($valor)) {
                        $valor = null;
                    }
                    if ($valor !== null) {
                        $matriz[$linha][$coluna] = is_string($valor) ? trim($valor) : $valor;
                    }
                }
                $aba = self::abaDaMatriz($nome, $matriz);
                if ($aba !== null) {
                    $abas[] = $aba;
                }
            }
            $planilha->disconnectWorksheets();
        } catch (Throwable $e) {
            Log::warning('[Estrutura Produtos] falha ao ler a planilha da ficha técnica: '.$e->getMessage());

            return $falha(self::MSG_ILEGIVEL);
        }

        return $abas === [] ? $falha(self::MSG_ILEGIVEL) : ['erro_geral' => null, 'abas' => $abas];
    }

    /** Cabeçalho na linha 1; aba sem a coluna "Produto (grupo)" não é aba de ficha. */
    private static function abaDaMatriz(string $nome, array $matriz): ?array
    {
        $cabecalhos = [];
        foreach ($matriz[1] ?? [] as $coluna => $titulo) {
            $titulo = trim((string) $titulo);
            if ($titulo !== '') {
                $cabecalhos[$coluna] = $titulo;
            }
        }
        ksort($cabecalhos);

        $colunaGrupo = null;
        $colunaNome = null;
        foreach ($cabecalhos as $coluna => $titulo) {
            $t = PlanilhaDasFichasTecnicas::chave(str_replace('*', '', $titulo));
            if ($colunaGrupo === null && (str_starts_with($t, 'produto (grupo)') || $t === 'grupo' || $t === 'ref')) {
                $colunaGrupo = $coluna;
            } elseif ($colunaNome === null && $t === PlanilhaDasFichasTecnicas::chave(PlanilhaDasFichasTecnicas::COLUNA_NOME)) {
                $colunaNome = $coluna;
            }
        }
        if ($colunaGrupo === null) {
            return null;
        }

        ksort($matriz);
        $linhas = [];
        foreach ($matriz as $numero => $celulas) {
            if ($numero === 1) {
                continue;
            }
            $linhas[] = ['__linha' => $numero] + $celulas;
        }

        return ['nome' => $nome, 'cabecalhos' => $cabecalhos, 'coluna_grupo' => $colunaGrupo, 'coluna_nome' => $colunaNome, 'linhas' => $linhas];
    }
}
