<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * O MODELO COMPLETO da planilha (13/10/2026): o produto e a ficha técnica dele na MESMA linha.
 *
 * O pedido do usuário: "preciso dela completa; o problema é que a ficha técnica vem de acordo com a
 * categoria". Até aqui eram dois arquivos em dois tempos — o de Produtos (sem ficha) e, só depois de
 * cadastrar e confirmar a categoria, o da Ficha técnica. Uma planilha não consulta nada sozinha e cada
 * categoria tem colunas diferentes; por isso a categoria é escolhida ANTES de baixar (pelo nome do
 * produto ou navegando), como nos modelos por tipo de produto de outros marketplaces.
 *
 * No mesmo dia o usuário fechou a regra: "todos têm que vir tudo completo, não ter opção de baixar
 * apenas modelo". Por isso há DOIS arquivos, os dois completos: o modelo vazio das categorias escolhidas
 * ({@see self::gerar()}) e "meus produtos" ({@see self::exportar()}), já preenchido com o que a empresa
 * tem — produto, variações e a ficha salva.
 *
 * ### O arquivo
 * Uma aba por categoria, com o nome dela. Em cada aba, as colunas do produto (as do modelo de Produtos,
 * sem a "Categoria" — a aba já é a categoria —, com o grupo na frente e com as medidas de cada volume
 * em COLUNAS SEPARADAS: comprimento, largura, altura e peso; pedido do usuário, "vir separado por
 * coluna", no lugar do texto "120×80×10 · 25,0") e, depois, uma coluna por campo
 * da ficha da categoria, com as mesmas listas, "*" e unidades da planilha da ficha
 * ({@see PlanilhaDasFichasTecnicas::colunasDaFicha()}). Mais "Instruções" e duas ocultas: "Listas" (as
 * opções) e "Categorias" (qual categoria é cada aba). No exportado, o produto que ainda não tem categoria
 * vai para a aba "Sem categoria": só as colunas do produto, mais a "Categoria" por escrito.
 *
 * ### Sigilo
 * Nada cita a plataforma. A aba "Categorias" guarda só os NÚMEROS do código da categoria (sem as
 * letras do prefixo) e o nome dela; {@see self::categoriasDoArquivo()} devolve o código inteiro.
 *
 * A importação é {@see ImportadorModeloCompleto}: as duas que já existiam, na ordem certa.
 */
class ModeloCompleto
{
    public const ABA_CATEGORIAS = 'Categorias';
    public const ABA_INSTRUCOES = 'Instruções';
    public const ABA_SEM_CATEGORIA = 'Sem categoria';

    /** A1 da aba oculta: é por ela que a importação reconhece o modelo. */
    public const MARCA = 'modelo-completo-v1';

    /** Quantas categorias a pessoa escolhe por modelo vazio. */
    public const MAX_CATEGORIAS = 10;

    /** Teto de abas do exportado: categorias além disso ficam de fora, com aviso nas instruções (raro). */
    public const MAX_ABAS = 60;

    /** Prefixo do código da categoria, tirado na ida e devolvido na volta (o arquivo nunca o carrega). */
    private const PREFIXO = 'MLB';

    /** Na aba oculta, o que marca a aba sem categoria. */
    private const SEM_CODIGO = '-';

    /** As colunas do produto antes e depois das medidas: o grupo na frente, sem a Categoria. */
    public const CAMPOS_ANTES = ['grupo', 'codigo', 'eixo', 'variacao', 'nome', 'familia', 'ambientes'];
    public const CAMPOS_DEPOIS = ['custo', 'estoque', 'descricao'];

    /** As quatro medidas de cada volume, na ordem das colunas (as chaves são as de {@see VolumesTexto}). */
    public const MEDIDAS = ['c' => 'comprimento (cm)', 'l' => 'largura (cm)', 'a' => 'altura (cm)', 'kg' => 'peso (kg)'];

    /** Quantos volumes o modelo vazio oferece; o exportado abre quantos o produto com mais volumes tiver. */
    public const VOLUMES_NO_MODELO = 3;
    public const MAX_VOLUMES = 8;

    private const LARGURAS = ['grupo' => 18, 'codigo' => 14, 'eixo' => 17, 'variacao' => 18, 'nome' => 32, 'familia' => 24, 'ambientes' => 28,
        'custo' => 13, 'estoque' => 13, 'descricao' => 50, 'categoria' => 28];

    public function __construct(
        private FichaTecnicaDaCategoria $definicao,
        private CategoriaSugestaoService $categorias,
        private PlanilhaDasFichasTecnicas $fichas,
        private PlanilhaDosProdutos $produtos,
    ) {}

    /** "Volume 2: largura (cm)" — o cabeçalho de uma medida de um volume. */
    public static function tituloDoVolume(int $numero, string $medida): string
    {
        return "Volume {$numero}: ".self::MEDIDAS[$medida];
    }

    /**
     * As colunas do produto, na ordem: cada uma com a `chave` (o campo do modelo de Produtos, ou
     * `vol:N:medida`), o `titulo` do cabeçalho e a `largura`.
     *
     * @return list<array{chave: string, titulo: string, largura: int}>
     */
    public static function colunas(int $volumes = self::VOLUMES_NO_MODELO, bool $comCategoria = false): array
    {
        $porCampo = array_combine(ModeloProdutosXlsx::CAMPOS, ModeloProdutosXlsx::CABECALHOS);
        $campo = fn (string $c) => ['chave' => $c, 'titulo' => $porCampo[$c], 'largura' => self::LARGURAS[$c]];

        $saida = array_map($campo, self::CAMPOS_ANTES);
        for ($n = 1; $n <= max(1, min($volumes, self::MAX_VOLUMES)); $n++) {
            foreach (array_keys(self::MEDIDAS) as $m) {
                $saida[] = ['chave' => "vol:{$n}:{$m}", 'titulo' => self::tituloDoVolume($n, $m), 'largura' => 22];
            }
        }

        return [...$saida, ...array_map($campo, [...self::CAMPOS_DEPOIS, ...($comCategoria ? ['categoria'] : [])])];
    }

    /** Os cabeçalhos das colunas do produto, na ordem. */
    public static function cabecalhosDoProduto(int $volumes = self::VOLUMES_NO_MODELO, bool $comCategoria = false): array
    {
        return array_column(self::colunas($volumes, $comCategoria), 'titulo');
    }

    /**
     * Cabeçalho (sem caixa, acento nem "*") => chave da coluna, para QUALQUER quantidade de volumes e com
     * a Categoria: é por aqui que a importação sabe de que é cada coluna do produto.
     *
     * @return array<string, string>
     */
    public static function chavesDosCabecalhos(): array
    {
        $saida = [];
        foreach (self::colunas(self::MAX_VOLUMES, true) as $c) {
            $saida[PlanilhaDasFichasTecnicas::chave(str_replace('*', '', $c['titulo']))] = $c['chave'];
        }

        return $saida;
    }

    /**
     * O modelo VAZIO das categorias escolhidas.
     *
     * @param  list<string>  $ids  códigos das categorias escolhidas (só a última de cada ramo serve)
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: string, nome: string, aba: string, campos: int}>, recusadas: list<array{id: string, motivo: string}>}
     */
    public function gerar(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_map(fn ($i) => mb_strtoupper(trim((string) $i)), $ids))), 0, self::MAX_CATEGORIAS);
        $detalhes = $ids === [] ? [] : $this->categorias->detalhes($ids);

        $dados = [];
        $recusadas = [];
        foreach ($ids as $id) {
            $d = $detalhes[$id] ?? null;
            if ($d === null) {
                $recusadas[] = ['id' => $id, 'motivo' => 'Não achamos esta categoria agora.'];
                continue;
            }
            if (! $d['folha']) {
                $recusadas[] = ['id' => $id, 'motivo' => "“{$d['nome']}” ainda tem categorias dentro dela. Escolha uma mais específica."];
                continue;
            }
            $campos = $this->camposDa($id);
            if ($campos === []) {
                $recusadas[] = ['id' => $id, 'motivo' => "A ficha técnica de “{$d['nome']}” não está disponível agora. Tente de novo em instantes."];
                continue;
            }
            $dados[] = ['id' => $id, 'nome' => $d['nome'], 'caminho' => implode(' > ', array_column($d['caminho'], 'nome')), 'campos' => $campos, 'linhas' => []];
        }

        return ['recusadas' => $recusadas] + $this->montar($dados, [], false);
    }

    /**
     * "Meus produtos", completo: os produtos da empresa, cada um na aba da categoria dele, com as
     * variações e a ficha técnica que está salva. Produto sem categoria vai para a aba "Sem categoria".
     *
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: ?string, nome: string, aba: string, campos: int}>, produtos: int}
     */
    public function exportar(Company $empresa): array
    {
        $porProduto = $this->produtos->porProduto($empresa);
        if ($porProduto === []) {
            return ['planilha' => null, 'abas' => [], 'produtos' => 0];
        }

        $salvos = [];
        EstruturaProdutoAtributo::query()
            ->where('company_id', $empresa->id)
            ->whereIn('produto_id', array_map(fn ($p) => (int) $p['produto']->id, $porProduto))
            ->get()
            ->each(function (EstruturaProdutoAtributo $a) use (&$salvos) {
                $salvos[(int) $a->produto_id][(string) $a->atributo_id] = $a;
            });

        $porCategoria = [];
        $semCategoria = [];
        foreach ($porProduto as $p) {
            $produto = $p['produto'];
            $id = mb_strtoupper(trim((string) $produto->categoria_ml_id));
            $nome = trim((string) $produto->categoria_ml_nome);
            // Só a categoria confirmada (código e nome) dá aba própria; o resto fica na "Sem categoria".
            if ($id === '' || $nome === '' || (! isset($porCategoria[$id]) && count($porCategoria) >= self::MAX_ABAS)) {
                foreach ($p['linhas'] as $linha) {
                    $semCategoria[] = ['produto' => $linha, 'ficha' => []];
                }
                continue;
            }
            $porCategoria[$id] ??= ['id' => $id, 'nome' => $nome, 'caminho' => '', 'campos' => $this->camposDa($id), 'linhas' => []];
            foreach ($p['linhas'] as $n => $linha) {
                $ficha = [];
                if ($n === 0) {
                    // A ficha é do produto: vai na 1ª linha dele.
                    foreach ($porCategoria[$id]['campos'] as $campo) {
                        $ficha[$campo['id']] = PlanilhaDasFichasTecnicas::textoDaCelula($campo, $salvos[(int) $produto->id][$campo['id']] ?? null);
                    }
                }
                $porCategoria[$id]['linhas'][] = ['produto' => $linha, 'ficha' => $ficha];
            }
        }
        uasort($porCategoria, fn ($a, $b) => strnatcasecmp($a['nome'], $b['nome']));

        return ['produtos' => count($porProduto)] + $this->montar(array_values($porCategoria), $semCategoria, true);
    }

    /**
     * As categorias de um arquivo do modelo completo: nome da aba => {id, nome}. `id` nulo = a aba dos
     * produtos sem categoria. Vazio = não é o modelo completo (ou a aba oculta foi apagada).
     *
     * @return array<string, array{id: ?string, nome: string}>
     */
    public static function categoriasDoArquivo(string $caminho): array
    {
        try {
            $leitor = IOFactory::createReader('Xlsx');
            $leitor->setReadDataOnly(true);
            $leitor->setLoadSheetsOnly([self::ABA_CATEGORIAS]);
            $planilha = $leitor->load($caminho);
            $folha = $planilha->getSheetByName(self::ABA_CATEGORIAS);
            if ($folha === null || trim((string) $folha->getCell('A1')->getValue()) !== self::MARCA) {
                return [];
            }

            $saida = [];
            $ultima = min((int) $folha->getHighestDataRow(), self::MAX_ABAS + 2);
            for ($linha = 2; $linha <= $ultima; $linha++) {
                $aba = trim((string) $folha->getCell("A{$linha}")->getValue());
                $numero = trim((string) $folha->getCell("B{$linha}")->getValue());
                if ($aba === '') {
                    continue;
                }
                if ($numero === self::SEM_CODIGO) {
                    $saida[$aba] = ['id' => null, 'nome' => ''];
                } elseif (preg_match('/^\d{1,17}$/D', $numero) === 1) {
                    $saida[$aba] = ['id' => self::PREFIXO.$numero, 'nome' => trim((string) $folha->getCell("C{$linha}")->getValue())];
                }
            }
            $planilha->disconnectWorksheets();

            return $saida;
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══ Montagem ═══════════════════════════════════════════════════════════

    /** @return list<array<string, mixed>>  os campos da ficha da categoria; vazio quando ela não respondeu */
    private function camposDa(string $id): array
    {
        try {
            return array_values(FichaTecnicaDaCategoria::camposPorId($this->definicao->definicao($id)));
        } catch (\Throwable $e) {
            Log::warning("[Estrutura Produtos] ficha da categoria {$id} indisponível para o modelo completo", ['erro' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<array{id: string, nome: string, caminho: string, campos: list<array>, linhas: list<array{produto: array<string, string>, ficha: array<string, string>}>}>  $categorias
     * @param  list<array{produto: array<string, string>, ficha: array}>  $semCategoria
     * @return array{planilha: ?Spreadsheet, abas: list<array{id: ?string, nome: string, aba: string, campos: int}>}
     */
    private function montar(array $categorias, array $semCategoria, bool $preenchido): array
    {
        if ($categorias === [] && $semCategoria === []) {
            return ['planilha' => null, 'abas' => []];
        }

        $planilha = new Spreadsheet();
        $planilha->getProperties()->setCreator('ECF')->setLastModifiedBy('ECF')->setTitle('Produtos e ficha técnica')
            ->setSubject('')->setDescription('')->setKeywords('')->setCategory('');
        $planilha->removeSheetByIndex(0);

        // Quantos volumes cabem em cada linha: os do modelo, ou mais, se algum produto exportado tiver mais.
        $volumes = self::VOLUMES_NO_MODELO;
        foreach ([...array_merge([], ...array_column($categorias, 'linhas')), ...$semCategoria] as $linha) {
            $volumes = max($volumes, count(VolumesTexto::interpretar($linha['produto']['volumes_texto'] ?? '')['volumes']));
        }
        $volumes = min($volumes, self::MAX_VOLUMES);
        $doProduto = self::colunas($volumes);

        $listas = new Worksheet($planilha, PlanilhaDasFichasTecnicas::ABA_LISTAS);
        $colunaDaLista = 0;
        $usados = [self::ABA_SEM_CATEGORIA];
        $abas = [];
        $mapa = [];

        foreach ($categorias as $c) {
            $nomeDaAba = PlanilhaDasFichasTecnicas::nomeDeAba($c['nome'], $usados);
            $usados[] = $nomeDaAba;
            $aba = new Worksheet($planilha, $nomeDaAba);
            $planilha->addSheet($aba);
            $fim = max(LeitorPlanilhaProdutos::MAX_LINHAS + 1, count($c['linhas']) + 1);

            $this->colunasDoProduto($aba, $doProduto, $fim);
            $primeiraDaFicha = count($doProduto) + 1;
            if ($c['campos'] !== []) {
                $this->fichas->colunasDaFicha($aba, $c['campos'], $primeiraDaFicha, $fim, $listas, $colunaDaLista);
            }
            foreach ($c['linhas'] as $n => $linha) {
                self::linhaDoProduto($aba, $doProduto, $n + 2, $linha['produto']);
                foreach ($c['campos'] as $i => $campo) {
                    $texto = (string) ($linha['ficha'][$campo['id']] ?? '');
                    if ($texto !== '') {
                        self::texto($aba, Coordinate::stringFromColumnIndex($primeiraDaFicha + $i).($n + 2), $texto);
                    }
                }
            }
            self::cabecalho($aba, count($doProduto), count($c['campos']));

            $abas[] = ['id' => $c['id'], 'nome' => $c['nome'], 'aba' => $nomeDaAba, 'campos' => count($c['campos'])];
            $mapa[] = [$nomeDaAba, substr($c['id'], strlen(self::PREFIXO)), $c['nome'], $c['caminho']];
        }

        if ($semCategoria !== []) {
            $comCategoria = self::colunas($volumes, true);
            $aba = new Worksheet($planilha, self::ABA_SEM_CATEGORIA);
            $planilha->addSheet($aba);
            $this->colunasDoProduto($aba, $comCategoria, max(LeitorPlanilhaProdutos::MAX_LINHAS + 1, count($semCategoria) + 1));
            foreach ($semCategoria as $n => $linha) {
                self::linhaDoProduto($aba, $comCategoria, $n + 2, $linha['produto']);
            }
            self::cabecalho($aba, count($comCategoria), 0);
            $abas[] = ['id' => null, 'nome' => self::ABA_SEM_CATEGORIA, 'aba' => self::ABA_SEM_CATEGORIA, 'campos' => 0];
            $mapa[] = [self::ABA_SEM_CATEGORIA, self::SEM_CODIGO, '', ''];
        }

        $instrucoes = new Worksheet($planilha, self::ABA_INSTRUCOES);
        $planilha->addSheet($instrucoes);
        self::preencherInstrucoes($instrucoes, count($categorias), $preenchido, $semCategoria !== []);

        $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $planilha->addSheet($listas);

        $oculta = new Worksheet($planilha, self::ABA_CATEGORIAS);
        $planilha->addSheet($oculta);
        self::texto($oculta, 'A1', self::MARCA);
        foreach ($mapa as $n => $linha) {
            foreach ($linha as $i => $valor) {
                self::texto($oculta, Coordinate::stringFromColumnIndex($i + 1).($n + 2), (string) $valor);
            }
        }
        $oculta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $planilha->setActiveSheetIndex(0);

        return ['planilha' => $planilha, 'abas' => $abas];
    }

    /** Os cabeçalhos, larguras e formatos das colunas do produto, e a lista do "Tipo de variação". */
    private function colunasDoProduto(Worksheet $aba, array $colunas, int $fim): void
    {
        foreach ($colunas as $i => $c) {
            $coluna = Coordinate::stringFromColumnIndex($i + 1);
            self::texto($aba, $coluna.'1', $c['titulo']);
            $aba->getColumnDimension($coluna)->setWidth($c['largura']);
            if (in_array($c['chave'], ['grupo', 'codigo', 'variacao'], true)) {
                $aba->getStyle("{$coluna}2:{$coluna}{$fim}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            if ($c['chave'] === 'eixo') {
                $aba->setDataValidation("{$coluna}2:{$coluna}{$fim}", self::listaDeTipos());
            }
        }
    }

    /** Uma linha do produto: os campos de sempre e, das medidas em texto, uma célula por medida de cada volume. */
    private static function linhaDoProduto(Worksheet $aba, array $colunas, int $numero, array $linha): void
    {
        $volumes = VolumesTexto::interpretar($linha['volumes_texto'] ?? '')['volumes'];
        foreach ($colunas as $i => $c) {
            if (str_starts_with($c['chave'], 'vol:')) {
                [, $n, $medida] = explode(':', $c['chave']);
                $bruto = $volumes[(int) $n - 1][$medida] ?? null;
                $valor = $bruto === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format((float) $bruto, 3, '.', ''), '0'), '.'));
            } else {
                $valor = trim((string) ($linha[$c['chave']] ?? ''));
            }
            if ($valor !== '') {
                self::texto($aba, Coordinate::stringFromColumnIndex($i + 1).$numero, $valor);
            }
        }
    }

    /** Dois tons no cabeçalho: os dados do produto e, mais claro, a ficha técnica da categoria. */
    private static function cabecalho(Worksheet $aba, int $doProduto, int $daFicha): void
    {
        $estilo = fn (string $cor) => [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $cor]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
        $aba->getStyle('A1:'.Coordinate::stringFromColumnIndex($doProduto).'1')->applyFromArray($estilo('1F2430'));
        if ($daFicha > 0) {
            $aba->getStyle(Coordinate::stringFromColumnIndex($doProduto + 1).'1:'.Coordinate::stringFromColumnIndex($doProduto + $daFicha).'1')->applyFromArray($estilo('3B4257'));
        }
        $aba->getRowDimension(1)->setRowHeight(32);
        $aba->freezePane('C2');
    }

    private static function listaDeTipos(): DataValidation
    {
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        $v->setShowInputMessage(true);
        $v->setPromptTitle('Tipo de variação');
        $v->setPrompt('Por onde o produto varia. Deixe em branco se não varia.');
        $v->setFormula1('"'.implode(',', array_values(EstruturaProdutoVariacao::EIXOS)).'"');

        return $v;
    }

    private static function preencherInstrucoes(Worksheet $aba, int $categorias, bool $preenchido, bool $temSemCategoria): void
    {
        $linhas = [
            'Como preencher',
            $preenchido
                ? 'Estes são os seus produtos, cada um na aba da categoria dele. Mude o que quiser e envie de novo: só o que mudou é atualizado.'
                : ($categorias === 1
                    ? 'A primeira aba é a categoria que você escolheu. Todos os produtos dela vão nessa aba.'
                    : 'Cada aba é uma das categorias que você escolheu. Ponha cada produto na aba da categoria dele.'),
            'Uma linha por variação do produto (cada cor ou tamanho é uma linha). Produto sem variação ocupa uma linha só.',
            'Produto (grupo)*: o código que junta as variações de um mesmo produto. Repita o mesmo código em todas as linhas dele.',
            'Ref*: o código de cada variação. Não pode repetir.',
            'Volumes: cada caixa do produto embalado tem quatro colunas — comprimento, largura e altura em cm e o peso em kg. Se o produto vai em mais de uma caixa, preencha o Volume 2, o Volume 3… Deixe em branco os que não usar.',
            'As colunas de cabeçalho mais claro são a ficha técnica da categoria. Preencha na PRIMEIRA linha do produto; nas outras variações pode ficar em branco.',
            'Coluna com * é obrigatória. Onde houver lista, escolha uma opção; em algumas listas você também pode escrever o seu valor.',
            'Para marcar mais de uma opção num campo, separe com | . Para dizer que o campo não vale para o produto, escreva: Não se aplica.',
            'Célula em branco não apaga nada do que já está cadastrado. Enviar de novo atualiza pelo código da variação.',
            'Não mude o nome das abas nem dos cabeçalhos. As fotos entram depois, em "Enviar fotos em lote".',
            ...($temSemCategoria ? ['A aba "Sem categoria" traz os produtos que ainda não têm categoria: sem ela não há ficha técnica. Escolha a categoria de cada um na tela e baixe de novo para ver a ficha.'] : []),
            ...($preenchido ? ['Para cadastrar produto de uma categoria que ainda não está aqui, use "Baixar modelo" e escolha a categoria.'] : []),
        ];
        foreach ($linhas as $n => $texto) {
            self::texto($aba, 'A'.($n + 1), $texto);
        }
        $aba->getStyle('A1')->getFont()->setBold(true);
        $aba->getColumnDimension('A')->setWidth(140);
        $aba->getStyle('A1:A'.count($linhas))->getAlignment()->setWrapText(true);
    }

    private static function texto(Worksheet $folha, string $celula, string $valor): void
    {
        $folha->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }
}
