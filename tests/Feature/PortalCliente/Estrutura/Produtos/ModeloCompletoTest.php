<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ImportadorModeloCompleto;
use App\Services\Portal\Estrutura\Produtos\ModeloCompleto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 13/10/2026 — o MODELO COMPLETO da planilha. O usuário: "preciso dela completa; o problema é que a ficha
 * técnica vem de acordo com a categoria… na hora de baixar o modelo, pede o nome do produto, dá sugestões
 * da categoria, escolhemos e baixa a planilha com todas as fichas técnicas e características".
 *
 * Antes eram dois arquivos em dois tempos (produtos; depois, com a categoria confirmada, a ficha). Agora a
 * categoria é escolhida antes de baixar, a aba traz o produto e a ficha na mesma linha, e um envio só cria
 * o produto já com a categoria confirmada e a ficha preenchida.
 *
 * Nenhuma chamada real: o catálogo é um Http::fake por URL.
 */
class ModeloCompletoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const PROIBIDO = '/mercado|an[uú]ncio|publica|\bmlb|\bml\b/iu';

    /** Os nomes das categorias de mentira (ver o `Http::fake` do `setUp`). */
    private const NOMES = ['MLB1' => 'Cadeiras', 'MLB2' => 'Mesas de Jantar'];

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Queue::fake();

        Http::fake(function (Request $r) {
            if (preg_match('#/categories/(MLB\d+)/attributes$#', $r->url(), $m)) {
                return Http::response(match ($m[1]) {
                    'MLB1' => $this->fichaDaCadeira(),
                    'MLB2' => [['id' => 'SHAPE', 'name' => 'Forma', 'value_type' => 'list', 'tags' => ['required' => true],
                        'values' => [['id' => '41', 'name' => 'Redonda'], ['id' => '42', 'name' => 'Quadrada']]]],
                    default => [],
                }, 200);
            }
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m)) {
                return match ($m[1]) {
                    'MLB1' => Http::response(['id' => 'MLB1', 'name' => 'Cadeiras', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB9', 'name' => 'Móveis'], ['id' => 'MLB1', 'name' => 'Cadeiras']]]),
                    'MLB2' => Http::response(['id' => 'MLB2', 'name' => 'Mesas de Jantar', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB9', 'name' => 'Móveis'], ['id' => 'MLB2', 'name' => 'Mesas de Jantar']]]),
                    'MLB9' => Http::response(['id' => 'MLB9', 'name' => 'Móveis', 'children_categories' => [['id' => 'MLB1', 'name' => 'Cadeiras']],
                        'path_from_root' => [['id' => 'MLB9', 'name' => 'Móveis']]]),
                    default => Http::response(['message' => 'erro'], 500),
                };
            }

            return Http::response([], 404);
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
        parent::tearDown();
    }

    private function fichaDaCadeira(): array
    {
        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true], 'value_max_length' => 20],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                'values' => [['id' => '101', 'name' => 'Madeira'], ['id' => '102', 'name' => 'Metal']]],
            ['id' => 'SEAT_HEIGHT', 'name' => 'Altura do assento', 'value_type' => 'number_unit', 'tags' => [],
                'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']], 'default_unit' => 'cm'],
            ['id' => 'UPHOLSTERY_MATERIAL', 'name' => 'Material do estofamento', 'value_type' => 'string', 'tags' => [],
                'values' => [['id' => '7', 'name' => 'Couro'], ['id' => '8', 'name' => 'Tecido']]],
            ['id' => 'WITH_DRAWER', 'name' => 'Com gaveta', 'value_type' => 'boolean', 'tags' => []],
            // Lista cujas opções são números (o caso de "Quantidade de lugares": 1, 2, 3…).
            ['id' => 'SEATS_NUMBER', 'name' => 'Quantidade de lugares', 'value_type' => 'list', 'tags' => [],
                'values' => [['id' => '201', 'name' => '1'], ['id' => '202', 'name' => '2'], ['id' => '203', 'name' => '3']]],
            // Medida do produto montado: vai para o bloco "fora da caixa", longe dos volumes.
            ['id' => 'WIDTH', 'name' => 'Largura', 'value_type' => 'number_unit', 'tags' => [],
                'allowed_units' => [['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'cm'],
            // Some da ficha (cita a plataforma) e nunca pode virar coluna.
            ['id' => 'APARECE', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string', 'tags' => []],
        ];
    }

    private function baixar(Company $empresa, array $categorias): Spreadsheet
    {
        $r = $this->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.modelo_completo', ['categorias' => $categorias]))
            ->assertOk()->assertDownload(ModeloCompleto::nomeDoArquivo(array_map(fn ($id) => ['nome' => self::NOMES[$id]], $categorias)));
        $caminho = tempnam(sys_get_temp_dir(), 'mc').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());

        return IOFactory::load($caminho);
    }

    /** A linha dos produtos: a 1ª é a 4 (faixa, cabeçalho e ajuda vêm antes). */
    private const L1 = ModeloCompleto::PRIMEIRA_LINHA;

    private function coluna(Worksheet $aba, string $cabecalho, int $linha = ModeloCompleto::LINHA_DO_CABECALHO): string
    {
        for ($c = 1; $c <= 70; $c++) {
            if ((string) $aba->getCell([$c, $linha])->getValue() === $cabecalho) {
                return Coordinate::stringFromColumnIndex($c);
            }
        }
        $this->fail("A aba {$aba->getTitle()} não tem a coluna {$cabecalho}");
    }

    private function linha(Worksheet $aba, int $numero, array $valores): void
    {
        foreach ($valores as $cabecalho => $valor) {
            $celula = $this->coluna($aba, $cabecalho).$numero;
            is_string($valor) ? $aba->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING) : $aba->setCellValue($celula, $valor);
        }
    }

    private function gravar(Spreadsheet $planilha): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'mc').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function valor(EstruturaProduto $p, string $atributo): ?array
    {
        $a = EstruturaProdutoAtributo::where('produto_id', $p->id)->where('atributo_id', $atributo)->first();

        return $a ? [$a->valor, $a->valor_id, $a->unidade] : null;
    }

    /** A planilha de duas categorias, preenchida: uma cadeira em duas cores, uma com erro na ficha e uma mesa. */
    private function preenchida(Company $empresa): string
    {
        $planilha = $this->baixar($empresa, ['MLB1', 'MLB2']);
        $cadeiras = $planilha->getSheetByName('Cadeiras');
        // Cadeira Alfa: duas cores; a ficha vai na 1ª linha e a 2ª completa o que ficou em branco.
        $this->linha($cadeiras, self::L1, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-PT', 'Tipo de variação' => 'Cor', 'Variação' => 'Preto',
            'Nome do produto*' => 'Cadeira Alfa',
            'Volume 1: comprimento (cm)' => 60, 'Volume 1: largura (cm)' => 50, 'Volume 1: altura (cm)' => 40, 'Volume 1: peso (kg)' => 8.5,
            'Volume 2: comprimento (cm)' => '30', 'Volume 2: largura (cm)' => '20', 'Volume 2: altura (cm)' => '10', 'Volume 2: peso (kg)' => '1,25',
            'Custo (R$)' => 120.5, 'Estoque (un.)' => 10,
            'Marca*' => 'Bela Casa', 'Material*' => 'metal', 'Altura do assento (cm)' => '450 mm', 'Material do estofamento' => 'Bouclé texturizado']);
        $this->linha($cadeiras, self::L1 + 1, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-BR', 'Tipo de variação' => 'Cor', 'Variação' => 'Branco',
            'Nome do produto*' => 'Cadeira Alfa',
            // Volume pela metade: a linha entra, as medidas dela não, e a prévia diz qual volume completar.
            'Volume 1: comprimento (cm)' => 60, 'Volume 1: largura (cm)' => 50, 'Com gaveta' => 'Sim']);
        // Cadeira Beta: Material fora das opções (lista fechada) e sem a Marca obrigatória.
        $this->linha($cadeiras, self::L1 + 3, ['Produto (grupo)*' => 'BETA', 'Ref*' => 'BETA-1', 'Nome do produto*' => 'Cadeira Beta', 'Material*' => 'Plástico']);

        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->linha($mesas, self::L1, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Forma*' => 'Redonda']);

        return $this->gravar($planilha);
    }

    // ═══ Baixar ═════════════════════════════════════════════════════════════

    public function test_baixar_traz_uma_aba_por_categoria_com_o_produto_e_a_ficha_na_mesma_linha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1', 'MLB2']);

        $this->assertSame(['Cadeiras', 'Mesas de Jantar', 'Instruções', 'Guia dos campos', 'Listas', 'Categorias'], $planilha->getSheetNames());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Listas')->getSheetState());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Categorias')->getSheetState());

        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $cabecalhos = [];
        for ($c = 1; $c <= 40; $c++) {
            $titulo = (string) $cadeiras->getCell([$c, ModeloCompleto::LINHA_DO_CABECALHO])->getValue();
            if ($titulo !== '') {
                $cabecalhos[] = $titulo;
            }
        }
        // As colunas do produto (sem "Categoria": a aba já é a categoria), com o grupo na frente; depois, a ficha.
        $doProduto = ModeloCompleto::cabecalhosDoProduto();
        $this->assertSame($doProduto, array_slice($cabecalhos, 0, count($doProduto)));
        $this->assertSame('Produto (grupo)*', $cabecalhos[0]);
        $this->assertNotContains('Categoria', $cabecalhos);
        // As medidas vêm em colunas separadas, quatro por volume, e não mais no texto "C×L×A · kg".
        $this->assertSame(['Volume 1: comprimento (cm)', 'Volume 1: largura (cm)', 'Volume 1: altura (cm)', 'Volume 1: peso (kg)', 'Volume 2: comprimento (cm)'], array_slice($cabecalhos, 7, 5));
        $this->assertSame(7 + 4 * ModeloCompleto::VOLUMES_NO_MODELO + 3, count($doProduto));
        foreach ($cabecalhos as $titulo) {
            $this->assertStringNotContainsString("\u{00D7}", $titulo, 'nenhum cabeçalho pede medidas juntas numa célula');
        }
        $this->assertNotContains('Nº volumes', $cabecalhos);
        $this->assertSame(['Marca*', 'Material*', 'Altura do assento (cm)', 'Material do estofamento', 'Com gaveta', 'Quantidade de lugares', 'Largura (cm)'], array_slice($cabecalhos, count($doProduto)));
        $this->assertNotContains('Aparece no Mercado Livre', $cabecalhos, 'campo que cita a plataforma nunca vira coluna');

        // As listas da ficha valem como validação; a fechada é estrita, a que aceita valor próprio só sugere.
        $material = $cadeiras->getDataValidation($this->coluna($cadeiras, 'Material*').self::L1);
        $this->assertSame('list', $material->getType());
        $this->assertTrue($material->getShowErrorMessage());
        $this->assertFalse($cadeiras->getDataValidation($this->coluna($cadeiras, 'Material do estofamento').self::L1)->getShowErrorMessage());
        $this->assertSame('"Cor,Tamanho,Voltagem,Material,Sabor,Outro"', $cadeiras->getDataValidation($this->coluna($cadeiras, 'Tipo de variação').self::L1)->getFormula1());
        // As linhas de cima (faixa, cabeçalho e ajuda) não têm lista: são texto fixo.
        $this->assertNotSame('list', $cadeiras->getDataValidation($this->coluna($cadeiras, 'Material*').ModeloCompleto::LINHA_DA_AJUDA)->getType());

        // A aba oculta diz qual categoria é cada aba — só os números do código.
        $mapa = $planilha->getSheetByName('Categorias');
        $this->assertSame([ModeloCompleto::MARCA, 'Cadeiras', '1', 'Cadeiras'], [(string) $mapa->getCell('A1')->getValue(),
            (string) $mapa->getCell('A2')->getValue(), (string) $mapa->getCell('B2')->getValue(), (string) $mapa->getCell('C2')->getValue()]);

        // Sigilo: nenhuma célula, aba ou validação cita a plataforma.
        foreach ($planilha->getAllSheets() as $folha) {
            $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $folha->getTitle());
            foreach ($folha->toArray() as $linha) {
                foreach ($linha as $celula) {
                    $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, (string) $celula, "aba {$folha->getTitle()}");
                }
            }
            foreach ($folha->getDataValidationCollection() as $v) {
                $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $v->getPrompt().' '.$v->getError().' '.$v->getPromptTitle().' '.$v->getFormula1());
            }
            // As notas dos cabeçalhos (a explicação de cada coluna) também são texto para o cliente.
            foreach ($folha->getComments() as $nota) {
                $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $nota->getText()->getPlainText(), "nota da aba {$folha->getTitle()}");
            }
        }
    }

    // ═══ A apresentação: de que é cada coluna e como preencher ══════════════

    /** O texto da faixa (linha 1) que cobre a coluna: o da própria célula ou o da célula mesclada que a contém. */
    private function faixa(Worksheet $aba, string $cabecalho): string
    {
        $coluna = $this->coluna($aba, $cabecalho);
        $n = Coordinate::columnIndexFromString($coluna);
        foreach ($aba->getMergeCells() as $area) {
            [[$de, $linhaDe], [$ate]] = Coordinate::rangeBoundaries($area);
            if ((int) $linhaDe === ModeloCompleto::LINHA_DA_FAIXA && $n >= $de && $n <= $ate) {
                return (string) $aba->getCell([$de, ModeloCompleto::LINHA_DA_FAIXA])->getValue();
            }
        }

        return (string) $aba->getCell($coluna.ModeloCompleto::LINHA_DA_FAIXA)->getValue();
    }

    private function ajuda(Worksheet $aba, string $cabecalho): string
    {
        return (string) $aba->getCell($this->coluna($aba, $cabecalho).ModeloCompleto::LINHA_DA_AJUDA)->getValue();
    }

    /**
     * 13/10/2026, o usuário preenchendo a planilha: "tem muitas dimensões que pedem para preencher mas não é
     * especificado o que é, como produto por fora da caixa, embalado"; "uma medida que não deve ser aplicada
     * mostra como se eu tivesse que preencher"; "tem colunas que preciso marcar Não se aplica, mas não mostra
     * as opções". A aba passa a dizer de que é cada bloco de colunas (faixa), o que é obrigatório (cor do
     * cabeçalho) e como preencher cada coluna (linha de ajuda), e ganha a aba "Guia dos campos".
     */
    public function test_a_aba_diz_de_que_e_cada_bloco_o_que_e_obrigatorio_e_como_preencher(): void
    {
        $planilha = $this->baixar($this->empresaDoGabarito(), ['MLB1']);
        $aba = $planilha->getSheetByName('Cadeiras');

        // A faixa separa o produto EMBALADO (os volumes) das medidas do produto FORA DA CAIXA.
        // As duas colunas fixas na tela têm o pedaço delas: nenhuma mescla atravessa o limite do que fica fixo.
        $this->assertSame(['PRODUTO', 'PRODUTO'], [$this->faixa($aba, 'Produto (grupo)*'), $this->faixa($aba, 'Ref*')]);
        $this->assertStringStartsWith('PRODUTO · uma linha por variação', $this->faixa($aba, 'Nome do produto*'));
        $this->assertStringStartsWith('PRODUTO EMBALADO · Volume 1: a caixa com o produto dentro.', $this->faixa($aba, 'Volume 1: comprimento (cm)'));
        $this->assertSame($this->faixa($aba, 'Volume 1: comprimento (cm)'), $this->faixa($aba, 'Volume 1: peso (kg)'));
        $this->assertSame('PRODUTO EMBALADO · Volume 2: só se for em mais de uma caixa.', $this->faixa($aba, 'Volume 2: largura (cm)'));
        $this->assertSame('CUSTO, ESTOQUE E DESCRIÇÃO', $this->faixa($aba, 'Custo (R$)'));
        $this->assertStringStartsWith('FICHA TÉCNICA', $this->faixa($aba, 'Marca*'));
        $this->assertStringStartsWith('FICHA TÉCNICA', $this->faixa($aba, 'Quantidade de lugares'), 'o título se repete: continua à vista ao rolar para o lado');
        $this->assertSame('MEDIDAS DO PRODUTO FORA DA CAIXA · montado, sem a embalagem', $this->faixa($aba, 'Largura (cm)'));

        // A linha de ajuda diz como preencher — e, nas medidas, de que medida se trata.
        $this->assertStringStartsWith('Como preencher', $this->ajuda($aba, 'Produto (grupo)*'));
        $this->assertSame('Largura da caixa com o produto dentro. Só o número, em cm.', $this->ajuda($aba, 'Volume 1: largura (cm)'));
        $this->assertSame('Peso da caixa 2 (só se o produto for em mais de uma caixa). Só o número, em kg.', $this->ajuda($aba, 'Volume 2: peso (kg)'));
        $this->assertSame('Obrigatório. Escolha da lista.', $this->ajuda($aba, 'Material*'));
        $this->assertStringStartsWith('Obrigatório. Texto', $this->ajuda($aba, 'Marca*'));
        // O opcional diz que é opcional e mostra o "Não se aplica" que a coluna aceita — inclusive onde não há lista.
        $nsa = 'Se o produto não tem isto, escreva: Não se aplica.';
        $this->assertSame("Opcional. Só o número, em cm. {$nsa}", $this->ajuda($aba, 'Altura do assento (cm)'));
        $this->assertSame("Opcional. Escolha da lista ou escreva o seu. {$nsa}", $this->ajuda($aba, 'Material do estofamento'));
        $this->assertSame("Opcional. Sim ou Não. {$nsa}", $this->ajuda($aba, 'Com gaveta'));
        $this->assertSame("Opcional. Produto montado, fora da caixa. Só o número, em cm. {$nsa}", $this->ajuda($aba, 'Largura (cm)'));
        $this->assertStringNotContainsString('Não se aplica', $this->ajuda($aba, 'Material*'), 'o obrigatório não aceita');

        // A cor do cabeçalho separa o obrigatório (escuro, a cor da faixa) do opcional (claro).
        $cor = fn (string $cabecalho) => $aba->getStyle($this->coluna($aba, $cabecalho).ModeloCompleto::LINHA_DO_CABECALHO)->getFill()->getStartColor()->getRGB();
        $this->assertSame(['312E81', 'E0E7FF', 'FFEDD5', '1F2430', 'DCFCE7'], [$cor('Material*'), $cor('Com gaveta'), $cor('Largura (cm)'), $cor('Ref*'), $cor('Volume 1: peso (kg)')]);

        // A explicação longa ("o que é") vai de nota no cabeçalho.
        $nota = fn (string $cabecalho) => $aba->getComment($this->coluna($aba, $cabecalho).ModeloCompleto::LINHA_DO_CABECALHO)->getText()->getPlainText();
        $this->assertStringContainsString('Nome da empresa que fabrica o produto', $nota('Marca*'));
        $this->assertStringContainsString('fora da caixa', $nota('Largura (cm)'));
        $this->assertStringContainsString('caixa com o produto embalado', $nota('Volume 1: largura (cm)'));

        // Os produtos começam na linha 4, com as três de cima e as duas primeiras colunas fixas na tela.
        $this->assertSame('C4', $aba->getFreezePane());

        // A aba "Guia dos campos": uma linha por coluna, com o que é, como preencher e as opções.
        $guia = collect($planilha->getSheetByName('Guia dos campos')->toArray())->keyBy(fn ($l) => (string) $l[2]);
        $this->assertSame(['Aba', 'Bloco', 'Coluna', 'Obrigatório', 'O que é', 'Como preencher', 'Opções da lista'], array_slice($guia['Coluna'], 0, 7));
        $this->assertSame(['Cadeiras', 'Ficha técnica', 'Material*', 'Sim'], array_slice($guia['Material*'], 0, 4));
        $this->assertSame('Obrigatório. Escolha da lista.', $guia['Material*'][5]);
        $this->assertStringContainsString('Metal', (string) $guia['Material*'][6]);
        $this->assertSame(['Todas', 'Produto embalado · Volume 1', 'Volume 1: peso (kg)', 'Não'], array_slice($guia['Volume 1: peso (kg)'], 0, 4));
        $this->assertSame(['Cadeiras', 'Medidas do produto fora da caixa', 'Largura (cm)', 'Não'], array_slice($guia['Largura (cm)'], 0, 4));
        $this->assertSame('Sim · Não · Não se aplica', $guia['Com gaveta'][6]);
        $this->assertStringEndsWith('Não se aplica', (string) $guia['Material do estofamento'][6]);
    }

    /**
     * 13/10/2026: "em produto embalado, deixa apenas um volume; o resto deixa minimizado". Os volumes que não
     * estão em uso vêm recolhidos num grupo de colunas, com o botão de abrir colado no último volume aberto;
     * o que já tem medida nunca nasce escondido, e o que está recolhido continua sendo lido na importação.
     */
    public function test_so_o_primeiro_volume_vem_aberto_e_os_outros_recolhidos(): void
    {
        $empresa = $this->empresaDoGabarito();
        // [à vista?, nível do grupo] da coluna.
        $estado = fn (Worksheet $a, string $cabecalho) => [$a->getColumnDimension($this->coluna($a, $cabecalho))->getVisible(), $a->getColumnDimension($this->coluna($a, $cabecalho))->getOutlineLevel()];
        $aberto = [true, 0];
        $recolhido = [false, 1];
        $aviso = 'Mais de uma caixa? Abra os outros volumes no + acima destas colunas.';

        // O modelo vazio: só o Volume 1.
        $aba = $this->baixar($empresa, ['MLB1'])->getSheetByName('Cadeiras');
        $this->assertSame([$aberto, $aberto], [$estado($aba, 'Volume 1: comprimento (cm)'), $estado($aba, 'Volume 1: peso (kg)')]);
        $this->assertSame([$recolhido, $recolhido, $recolhido], [$estado($aba, 'Volume 2: comprimento (cm)'), $estado($aba, 'Volume 2: peso (kg)'), $estado($aba, 'Volume 3: peso (kg)')]);
        $this->assertSame([$aberto, $aberto], [$estado($aba, 'Ambiente(s)'), $estado($aba, 'Custo (R$)')], 'só os volumes são recolhidos');
        // O botão de abrir fica à esquerda do grupo, na última coluna do volume aberto.
        $this->assertFalse($aba->getShowSummaryRight());
        $this->assertTrue($aba->getColumnDimension($this->coluna($aba, 'Volume 1: peso (kg)'))->getCollapsed());
        $this->assertStringEndsWith($aviso, $this->faixa($aba, 'Volume 1: peso (kg)'));

        // "Meus produtos": a cadeira ALFA-PT usa dois volumes (ver `preenchida`, que os escreve no Volume 2
        // RECOLHIDO do modelo — e eles entram). Na aba dela ficam abertos os dois; o terceiro, recolhido.
        app(ImportadorModeloCompleto::class)->aplicar($empresa, $this->preenchida($empresa), $this->atorCliente($empresa));
        $planilha = app(ModeloCompleto::class)->exportar($empresa)['planilha'];
        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $this->assertSame('30', (string) $cadeiras->getCell($this->coluna($cadeiras, 'Volume 2: comprimento (cm)').self::L1)->getValue());
        $this->assertSame([$aberto, $aberto, $recolhido], [$estado($cadeiras, 'Volume 1: peso (kg)'), $estado($cadeiras, 'Volume 2: peso (kg)'), $estado($cadeiras, 'Volume 3: comprimento (cm)')]);
        $this->assertTrue($cadeiras->getColumnDimension($this->coluna($cadeiras, 'Volume 2: peso (kg)'))->getCollapsed());
        $this->assertStringEndsWith($aviso, $this->faixa($cadeiras, 'Volume 2: peso (kg)'));
        $this->assertStringNotContainsString($aviso, $this->faixa($cadeiras, 'Volume 1: peso (kg)'));
        // A mesa não tem volume nenhum: na aba dela, só o Volume 1 aberto.
        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->assertSame([$aberto, $recolhido], [$estado($mesas, 'Volume 1: peso (kg)'), $estado($mesas, 'Volume 2: comprimento (cm)')]);
    }

    /**
     * O erro do usuário em 13/10/2026: "Quantidade de lugares - Escolha da lista". As opções são números
     * (1, 2, 3…) e iam para a lista como TEXTO; quem digita 1 na célula digita um NÚMERO, e a validação
     * em lista compara pelo tipo. A opção que é número vai como número, e o número digitado entra.
     */
    public function test_opcao_que_e_numero_vai_como_numero_e_o_numero_digitado_entra(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1']);

        $listas = $planilha->getSheetByName('Listas');
        $tipos = [];
        for ($c = 1; $c <= 20; $c++) {
            $titulo = (string) $listas->getCell([$c, 1])->getValue();
            if ($titulo !== '') {
                $tipos[trim(explode('·', $titulo)[1])] = array_map(fn ($l) => $listas->getCell([$c, $l])->getDataType().':'.$listas->getCell([$c, $l])->getValue(), [2, 3, 4]);
            }
        }
        $this->assertSame(['n:1', 'n:2', 'n:3'], $tipos['Quantidade de lugares'], 'número de verdade, para casar com o que a pessoa digita');
        $this->assertSame('s', explode(':', $tipos['Material'][0])[0], 'as outras listas seguem como texto');

        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $this->linha($cadeiras, self::L1, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-1', 'Nome do produto*' => 'Cadeira Alfa',
            // 2 digitado como NÚMERO; e o "Não se aplica" escrito numa coluna de número.
            'Marca*' => 'Bela Casa', 'Material*' => 'Metal', 'Quantidade de lugares' => 2, 'Altura do assento (cm)' => 'não se aplica', 'Largura (cm)' => 55.5]);
        $caminho = $this->gravar($planilha);

        $previa = app(ImportadorModeloCompleto::class)->previa($empresa, $caminho);
        $this->assertSame([], collect($previa['ficha']['produtos'])->firstWhere('grupo', 'ALFA')['erros']);
        app(ImportadorModeloCompleto::class)->aplicar($empresa, $caminho, $this->atorCliente($empresa));

        $alfa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Cadeira Alfa')->firstOrFail();
        $this->assertSame(['2', '202', null], $this->valor($alfa, 'SEATS_NUMBER'));
        $this->assertSame(\App\Services\Portal\Estrutura\Produtos\FichaTecnicaDoProduto::NAO_SE_APLICA, $this->valor($alfa, 'SEAT_HEIGHT')[1], 'o "Não se aplica" vale em coluna de número');
        $this->assertSame(['55.5', null, 'cm'], $this->valor($alfa, 'WIDTH'));

        // Na volta ("meus produtos") a opção numérica sai como número, igual à lista; e o "Não se aplica", por escrito.
        $exportado = app(ModeloCompleto::class)->exportar($empresa)['planilha']->getSheetByName('Cadeiras');
        $lugares = $exportado->getCell($this->coluna($exportado, 'Quantidade de lugares').self::L1);
        $this->assertSame(['n', 2], [$lugares->getDataType(), (int) $lugares->getValue()]);
        $this->assertSame('Não se aplica', (string) $exportado->getCell($this->coluna($exportado, 'Altura do assento (cm)').self::L1)->getValue());
    }

    /** Quem baixou o modelo antes de 13/10/2026 tem o cabeçalho na linha 1 e os produtos na 2: continua entrando. */
    public function test_o_arquivo_de_antes_com_o_cabecalho_na_linha_1_continua_entrando(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1']);
        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $this->linha($cadeiras, self::L1, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-1', 'Nome do produto*' => 'Cadeira Alfa', 'Marca*' => 'Bela Casa', 'Material*' => 'Metal']);
        // Volta ao formato antigo: sem a ajuda (linha 3) e sem a faixa (linha 1).
        foreach ($cadeiras->getMergeCells() as $area) {
            $cadeiras->unmergeCells($area);
        }
        $cadeiras->removeRow(ModeloCompleto::LINHA_DA_AJUDA);
        $cadeiras->removeRow(ModeloCompleto::LINHA_DA_FAIXA);
        $this->assertSame('Produto (grupo)*', (string) $cadeiras->getCell('A1')->getValue());
        $caminho = $this->gravar($planilha);

        $previa = app(ImportadorModeloCompleto::class)->previa($empresa, $caminho);
        $this->assertNull($previa['erro_geral']);
        $this->assertSame([1, 0], [$previa['totais']['novos'], $previa['totais']['erros']]);
        $this->assertSame(2, $previa['grupos']['novos'][0]['linha']);
        $this->assertSame(2, collect($previa['ficha']['produtos'])->firstWhere('grupo', 'ALFA')['campos']);
        $this->assertSame([], $previa['ficha']['avisos']);
    }

    /** A linha de ajuda não vira produto, nem quando alguém apaga a faixa de cima. */
    public function test_a_linha_de_ajuda_nunca_e_lida_como_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1']);
        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $this->linha($cadeiras, self::L1, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-1', 'Nome do produto*' => 'Cadeira Alfa']);

        $previa = app(ImportadorModeloCompleto::class)->previa($empresa, $this->gravar($planilha));
        $this->assertSame([1, 0], [$previa['totais']['novos'], $previa['totais']['erros']], 'só a ALFA: a ajuda e a faixa não contam');
        $this->assertSame(self::L1, $previa['grupos']['novos'][0]['linha']);

        foreach ($cadeiras->getMergeCells() as $area) {
            $cadeiras->unmergeCells($area);
        }
        $cadeiras->removeRow(ModeloCompleto::LINHA_DA_FAIXA);
        $previa = app(ImportadorModeloCompleto::class)->previa($empresa, $this->gravar($planilha));
        $this->assertSame([1, 0], [$previa['totais']['novos'], $previa['totais']['erros']]);
    }

    /** 13/10/2026: "quando baixar o modelo, vir nomeado nome da categoria, ex.: poltrona-modelo.xlsx". */
    public function test_o_arquivo_vem_com_o_nome_da_categoria(): void
    {
        $empresa = $this->empresaDoGabarito();
        $rota = fn (array $ids) => route('portal.auth.estrutura.produtos.modelo_completo', ['categorias' => $ids]);

        $this->entrarNoPortal($empresa)->get($rota(['MLB1']))->assertOk()->assertDownload('cadeiras-modelo.xlsx');
        // Mais de uma: todas no nome, na ordem das abas.
        $this->entrarNoPortal($empresa)->get($rota(['MLB2', 'MLB1']))->assertOk()->assertDownload('mesas-de-jantar-cadeiras-modelo.xlsx');

        // Acento e símbolo saem; acima de 3 categorias, as 2 primeiras e quantas faltam.
        $this->assertSame('cadeiras-para-escritorio-modelo.xlsx', ModeloCompleto::nomeDoArquivo([['nome' => 'Cadeiras para Escritório']]));
        $this->assertSame('sofas-pufes-e-banquetas-modelo.xlsx', ModeloCompleto::nomeDoArquivo([['nome' => 'Sofás'], ['nome' => 'Pufes e Banquetas']]));
        $this->assertSame('poltronas-sofas-e-mais-3-modelo.xlsx', ModeloCompleto::nomeDoArquivo(
            array_map(fn ($n) => ['nome' => $n], ['Poltronas', 'Sofás', 'Pufes', 'Futon', 'Banquetas']),
        ));
        // Nome repetido conta uma vez; sem nome nenhum, o nome genérico.
        $this->assertSame('poltronas-modelo.xlsx', ModeloCompleto::nomeDoArquivo([['nome' => 'Poltronas'], ['nome' => 'poltronas']]));
        $this->assertSame('modelo.xlsx', ModeloCompleto::nomeDoArquivo([]));
        $this->assertSame('modelo.xlsx', ModeloCompleto::nomeDoArquivo([['nome' => '···']]));
    }

    public function test_baixar_recusa_categoria_que_nao_e_a_ultima_do_ramo_ou_invalida_e_exige_sessao(): void
    {
        $this->get('/portal/estrutura/produtos/modelo-completo?categorias[]=MLB1')->assertRedirect();

        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());
        $rota = fn (array $c) => route('portal.auth.estrutura.produtos.modelo_completo', ['categorias' => $c]);

        $sessao->getJson($rota(['MLB9']))->assertStatus(422)
            ->assertJsonPath('errors.categorias.0', '“Móveis” ainda tem categorias dentro dela. Escolha uma mais específica.');
        // Uma boa e uma ruim: o arquivo só sai inteiro.
        $sessao->getJson($rota(['MLB1', 'MLB9']))->assertStatus(422);
        $sessao->getJson($rota(['cadeira']))->assertStatus(422);
        $sessao->getJson(route('portal.auth.estrutura.produtos.modelo_completo'))->assertStatus(422)->assertJsonValidationErrors('categorias');
        $sessao->getJson($rota(array_map(fn ($n) => "MLB{$n}", range(1, 11))))->assertStatus(422);
    }

    // ═══ Importar ═══════════════════════════════════════════════════════════

    public function test_a_previa_mostra_os_produtos_e_a_ficha_sem_gravar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->preenchida($empresa);
        $this->assertTrue(ImportadorModeloCompleto::reconhece($caminho));

        $r = $this->entrarNoPortal($empresa)->post(route('portal.auth.estrutura.produtos.importacao.previa'),
            ['arquivo' => new UploadedFile($caminho, 'modelo-completo.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertOk();

        $this->assertNull($r->json('erro_geral'));
        $this->assertTrue($r->json('modelo_completo'));
        $this->assertSame(4, $r->json('totais.novos'), 'ALFA-PT, ALFA-BR, BETA-1 e MESA-1');
        $this->assertSame(0, $r->json('totais.erros'));
        $this->assertSame(0, $r->json('categorias_a_confirmar.total_nomes'), 'a categoria vem da aba: nada a confirmar');
        // A linha da prévia é a linha da aba enviada (a BETA está na 7, com a 6 em branco), e diz de qual aba é.
        $novos = collect($r->json('grupos.novos'))->keyBy('codigo');
        $this->assertSame([self::L1 + 3, 'Cadeiras'], [$novos['BETA-1']['linha'], $novos['BETA-1']['aba']]);
        $this->assertSame([self::L1, 'Mesas de Jantar'], [$novos['MESA-1']['linha'], $novos['MESA-1']['aba']]);

        // A ficha é conferida contra a categoria da aba mesmo com o produto ainda sem existir.
        $ficha = collect($r->json('ficha.produtos'))->keyBy('grupo');
        // `produtos` conta os que ganham algum campo (ALFA e MESA); a BETA só tem o campo recusado.
        $this->assertSame(['produtos' => 2, 'campos' => 6, 'com_erro' => 1, 'com_falta' => 1, 'nao_encontrados' => 0], $r->json('ficha.totais'));
        $this->assertSame(5, $ficha['ALFA']['campos'], 'os 4 da 1ª linha e o "Com gaveta" da 2ª variação');
        $this->assertSame([], $ficha['ALFA']['erros']);
        $this->assertSame(['Escolha uma das opções de “Material”.'], $ficha['BETA']['erros']);
        $this->assertSame(['Marca', 'Material'], $ficha['BETA']['faltam']);
        $this->assertSame(1, $ficha['MESA']['campos']);
        $this->assertSame([], $r->json('ficha.avisos'), 'as colunas do produto não são "coluna estranha" e a 2ª variação não gera aviso');
        $this->assertContains('Cadeiras, linha 5: o Volume 1 está incompleto (preencha comprimento, largura, altura e peso). As medidas desta linha não foram alteradas.', $r->json('avisos'));

        $this->assertSame(0, EstruturaProduto::count(), 'a prévia não grava nada');
        $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $r->getContent());
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $json);
    }

    public function test_importar_cria_o_produto_com_a_categoria_da_aba_e_a_ficha_de_uma_vez(): void
    {
        $empresa = $this->empresaDoGabarito();
        $caminho = $this->preenchida($empresa);

        $this->entrarNoPortal($empresa)->post(route('portal.auth.estrutura.produtos.importacao'),
            ['arquivo' => new UploadedFile($caminho, 'modelo-completo.xlsx', null, null, true)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '4 novos') && str_contains($m, 'Ficha técnica: 6 campos em 2 produtos')
                && str_contains($m, '1 produto com campo recusado') && str_contains($m, 'ainda com campo obrigatório em branco'));

        $alfa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Cadeira Alfa')->firstOrFail();
        $beta = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Cadeira Beta')->firstOrFail();
        $mesa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Mesa Gama')->firstOrFail();

        // A categoria veio da aba, já confirmada: ninguém precisa escolher de novo.
        $this->assertSame(['MLB1', 'Cadeiras', EstruturaProduto::CATEGORIA_CONFIRMADA], [$alfa->categoria_ml_id, $alfa->categoria_ml_nome, $alfa->estadoCategoria()]);
        $this->assertSame('MLB2', $mesa->categoria_ml_id);
        $this->assertSame(['ALFA-PT', 'ALFA-BR'], EstruturaProdutoVariacao::where('produto_id', $alfa->id)->orderBy('ordem')->pluck('codigo')->all());
        $this->assertEquals(120.5, (float) EstruturaProdutoVariacao::where('codigo', 'ALFA-PT')->value('custo'), 'número digitado como número chega certo');
        // As medidas em colunas viraram os dois volumes da variação, na ordem; a linha com volume pela metade ficou sem medidas.
        $volumes = fn (string $ref) => EstruturaProdutoVariacao::where('codigo', $ref)->firstOrFail()->volumes()->orderBy('ordem')->get()
            ->map(fn ($v) => [(float) $v->comprimento, (float) $v->largura, (float) $v->altura, (float) $v->peso])->all();
        $this->assertSame([[60.0, 50.0, 40.0, 8.5], [30.0, 20.0, 10.0, 1.25]], $volumes('ALFA-PT'));
        $this->assertSame([], $volumes('ALFA-BR'));

        // A ficha do produto, de uma vez: opção pelo nome, unidade ao lado do número, valor próprio onde o campo aceita.
        $this->assertSame(['Bela Casa', null, null], $this->valor($alfa, 'BRAND'));
        $this->assertSame(['Metal', '102', null], $this->valor($alfa, 'MATERIAL'));
        $this->assertSame(['450', null, 'mm'], $this->valor($alfa, 'SEAT_HEIGHT'));
        $this->assertSame(['Bouclé texturizado', null, null], $this->valor($alfa, 'UPHOLSTERY_MATERIAL'));
        $this->assertSame(['Sim', null, null], $this->valor($alfa, 'WITH_DRAWER'), 'preenchido na linha da 2ª variação');
        $this->assertSame(['Redonda', '41', null], $this->valor($mesa, 'SHAPE'));
        // O produto com erro na ficha entra (é produto válido); só o campo recusado fica de fora.
        $this->assertNull($this->valor($beta, 'MATERIAL'));

        // Enviar de novo o mesmo arquivo não duplica nem muda nada.
        $de_novo = app(ImportadorModeloCompleto::class)->aplicar($empresa, $caminho, $this->atorCliente($empresa));
        $this->assertSame([0, 0], [$de_novo['novos'], $de_novo['ficha']['campos']]);
        $this->assertSame(3, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    public function test_produto_que_ja_esta_em_outra_categoria_nao_recebe_a_ficha_desta_aba(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1', 'MLB2']);
        $this->linha($planilha->getSheetByName('Mesas de Jantar'), self::L1, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Forma*' => 'Redonda']);
        app(ImportadorModeloCompleto::class)->aplicar($empresa, $this->gravar($planilha), $this->atorCliente($empresa));
        $mesa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Mesa Gama')->firstOrFail();

        // A mesma mesa, agora na aba de Cadeiras, com a ficha de cadeira.
        $outra = $this->baixar($empresa, ['MLB1']);
        $this->linha($outra->getSheetByName('Cadeiras'), self::L1, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Marca*' => 'Bela Casa']);
        $r = app(ImportadorModeloCompleto::class)->previa($empresa, $this->gravar($outra));

        $doItem = collect($r['ficha']['produtos'])->firstWhere('grupo', 'MESA');
        $this->assertSame(['O produto MESA já está em outra categoria; a ficha técnica desta aba não vale para ele.'], $doItem['erros'] ?: [$doItem['motivo'] ?? null]);
        $this->assertSame('MLB2', $mesa->fresh()->categoria_ml_id, 'a categoria escolhida antes não é trocada');
        $this->assertNull($this->valor($mesa, 'BRAND'));
    }

    // ═══ "Baixar meus produtos": também completo ═════════════════════════════

    /**
     * "Todos têm que vir tudo completo, não ter opção de baixar apenas modelo" (usuário, 13/10/2026). O
     * arquivo dos produtos que a empresa JÁ tem sai no mesmo formato: cada produto na aba da categoria dele,
     * com as medidas em colunas e a ficha salva; o que ainda não tem categoria vai para "Sem categoria".
     * E volta pela importação sem mudar nada.
     */
    public function test_meus_produtos_sai_completo_por_categoria_e_volta_sem_mudar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        app(ImportadorModeloCompleto::class)->aplicar($empresa, $this->preenchida($empresa), $ator);
        // Um produto sem categoria, e um com QUATRO volumes (mais que os três do modelo vazio).
        app(\App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['grupo' => 'SOLTO', 'codigo' => 'SOLTO-1', 'nome' => 'Produto Solto', 'categoria' => 'Aparador', 'volumes' => [['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 1]]],
        ], $ator);
        // Categoria só por escrito (a confirmar): é o produto que fica na aba "Sem categoria".
        EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Produto Solto')->update(['categoria_ml_nome' => 'Aparador', 'categoria_ml_id' => null]);
        $mesa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Mesa Gama')->firstOrFail();
        $variacaoDaMesa = EstruturaProdutoVariacao::where('produto_id', $mesa->id)->firstOrFail();
        foreach (range(1, 4) as $n) {
            $variacaoDaMesa->volumes()->create(['company_id' => $empresa->id, 'ordem' => $n, 'comprimento' => 100 + $n, 'largura' => 50, 'altura' => 10, 'peso' => 12.5]);
        }

        $r = $this->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.exportar_completo'))->assertOk()->assertDownload('meus-produtos.xlsx');
        $caminho = tempnam(sys_get_temp_dir(), 'mp').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());
        $planilha = IOFactory::load($caminho);

        $this->assertSame(['Cadeiras', 'Mesas de Jantar', 'Sem categoria', 'Instruções', 'Guia dos campos', 'Listas', 'Categorias'], $planilha->getSheetNames());

        // A cadeira: as duas variações, as medidas em colunas e a ficha na 1ª linha.
        $cadeiras = $planilha->getSheetByName('Cadeiras');
        // `$linha` conta a partir do 1º produto da aba (1 = a linha 4 da planilha).
        $celula = fn (Worksheet $aba, string $cabecalho, int $linha) => (string) $aba->getCell($this->coluna($aba, $cabecalho).($linha + self::L1 - 1))->getValue();
        $this->assertSame(['ALFA', 'ALFA-PT', 'Cor', 'Preto'], [$celula($cadeiras, 'Produto (grupo)*', 1), $celula($cadeiras, 'Ref*', 1), $celula($cadeiras, 'Tipo de variação', 1), $celula($cadeiras, 'Variação', 1)]);
        $this->assertSame(['60', '50', '40', '8,5', '30', '1,25'], [$celula($cadeiras, 'Volume 1: comprimento (cm)', 1), $celula($cadeiras, 'Volume 1: largura (cm)', 1),
            $celula($cadeiras, 'Volume 1: altura (cm)', 1), $celula($cadeiras, 'Volume 1: peso (kg)', 1), $celula($cadeiras, 'Volume 2: comprimento (cm)', 1), $celula($cadeiras, 'Volume 2: peso (kg)', 1)]);
        $this->assertSame(['Bela Casa', 'Metal', '450 mm', 'Bouclé texturizado', 'Sim'], [$celula($cadeiras, 'Marca*', 1), $celula($cadeiras, 'Material*', 1),
            $celula($cadeiras, 'Altura do assento (cm)', 1), $celula($cadeiras, 'Material do estofamento', 1), $celula($cadeiras, 'Com gaveta', 1)]);
        $this->assertSame(['ALFA-BR', ''], [$celula($cadeiras, 'Ref*', 2), $celula($cadeiras, 'Marca*', 2)], 'a ficha é do produto: só na 1ª linha dele');

        // A mesa tem quatro volumes: o arquivo abre o quarto grupo de colunas.
        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->assertSame(['104', '12,5', 'Redonda'], [$celula($mesas, 'Volume 4: comprimento (cm)', 1), $celula($mesas, 'Volume 4: peso (kg)', 1), $celula($mesas, 'Forma*', 1)]);

        // Sem categoria: as colunas do produto e a categoria por escrito, sem ficha.
        $sem = $planilha->getSheetByName('Sem categoria');
        $this->assertSame(['SOLTO-1', 'Aparador'], [$celula($sem, 'Ref*', 1), $celula($sem, 'Categoria', 1)]);

        // E volta: nada muda, nada é criado, a ficha não regrava.
        $this->assertTrue(ImportadorModeloCompleto::reconhece($caminho));
        $previa = app(ImportadorModeloCompleto::class)->previa($empresa, $caminho);
        $this->assertNull($previa['erro_geral']);
        $this->assertSame(['novos' => 0, 'atualizados' => 0, 'erros' => 0], array_intersect_key($previa['totais'], array_flip(['novos', 'atualizados', 'erros'])));
        $this->assertSame(5, $previa['totais']['sem_mudanca'], 'ALFA-PT, ALFA-BR, BETA-1, MESA-1 e SOLTO-1');
        $this->assertSame(1, $previa['categorias_a_confirmar']['total_nomes'], 'o produto sem categoria continua pedindo a dele');
        $antes = EstruturaProdutoAtributo::count();
        $de_novo = app(ImportadorModeloCompleto::class)->aplicar($empresa, $caminho, $ator);
        $this->assertSame([0, 0, 0], [$de_novo['novos'], $de_novo['atualizados'], $de_novo['ficha']['campos']]);
        $this->assertSame($antes, EstruturaProdutoAtributo::count());
    }

    public function test_meus_produtos_sem_nenhum_produto_responde_404_e_exige_sessao(): void
    {
        $this->get('/portal/estrutura/produtos/exportar-completo')->assertRedirect();
        $this->entrarNoPortal($this->empresaDoGabarito())->get(route('portal.auth.estrutura.produtos.exportar_completo'))->assertNotFound();
    }

    public function test_os_dois_modelos_antigos_continuam_valendo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $modelo = tempnam(sys_get_temp_dir(), 'mp').'.xlsx';
        $this->temporarios[] = $modelo;
        file_put_contents($modelo, $sessao->get(route('portal.auth.estrutura.produtos.modelo'))->assertOk()->streamedContent());

        $this->assertFalse(ImportadorModeloCompleto::reconhece($modelo), 'o modelo de Produtos segue pela importação de sempre');
        $r = $sessao->post(route('portal.auth.estrutura.produtos.importacao.previa'),
            ['arquivo' => new UploadedFile($modelo, 'modelo-produtos.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertOk();
        $this->assertArrayNotHasKey('modelo_completo', $r->json());
    }
}
