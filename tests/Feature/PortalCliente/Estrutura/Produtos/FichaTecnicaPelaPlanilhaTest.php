<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\Produtos\ImportadorFichasTecnicas;
use App\Services\Portal\Estrutura\Produtos\PlanilhaDasFichasTecnicas;
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
 * A ficha técnica pela planilha (09/10/2026): o 2º arquivo, uma aba por categoria confirmada,
 * que volta casando cada coluna pelo NOME do campo e grava MESCLANDO (vazio não apaga; o válido
 * entra; o inválido e o obrigatório que falta são listados) — com a mesma validação da tela.
 *
 * Nenhuma chamada real: o catálogo é um Http::fake por URL.
 */
class FichaTecnicaPelaPlanilhaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const PROIBIDO = '/mercado|an[uú]ncio|publica|\bmlb|\bml\b/iu';

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (preg_match('#/categories/(MLB\d+)/attributes$#', $r->url(), $m)) {
                return Http::response(match ($m[1]) {
                    'MLB1' => $this->atributosDaCadeira(),
                    'MLB2' => [
                        ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => []],
                        ['id' => 'SHAPE', 'name' => 'Formato', 'value_type' => 'string', 'tags' => [],
                            'values' => [['id' => '41', 'name' => 'Redonda'], ['id' => '42', 'name' => 'Retangular']]],
                    ],
                    default => [],
                }, 200);
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

    private function atributosDaCadeira(): array
    {
        return [
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true], 'value_max_length' => 20],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                'values' => [['id' => '101', 'name' => 'Madeira'], ['id' => '102', 'name' => 'Metal']]],
            ['id' => 'SEAT_HEIGHT', 'name' => 'Altura do assento', 'value_type' => 'number_unit', 'tags' => [],
                'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']], 'default_unit' => 'cm'],
            ['id' => 'CAPACITY', 'name' => 'Capacidade', 'value_type' => 'number', 'tags' => []],
            ['id' => 'WITH_DRAWER', 'name' => 'Com gaveta', 'value_type' => 'boolean', 'tags' => []],
            ['id' => 'MATERIALS', 'name' => 'Materiais', 'value_type' => 'string', 'tags' => ['multivalued' => true],
                'values' => [['id' => '1', 'name' => 'Algodão'], ['id' => '2', 'name' => 'Couro'], ['id' => '3', 'name' => 'Microfibra']]],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true],
                'values' => [['id' => '52049', 'name' => 'Preto'], ['id' => '52055', 'name' => 'Branco']]],
            // Some da ficha (cita a plataforma) e nunca pode virar coluna.
            ['id' => 'APARECE', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string', 'tags' => []],
        ];
    }

    private function produto(Company $empresa, string $codigo, string $nome, ?string $categoria, ?string $nomeCategoria, array $variacoes): EstruturaProduto
    {
        $p = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => $codigo, 'nome' => $nome,
            'categoria_ml_id' => $categoria, 'categoria_ml_nome' => $nomeCategoria]);
        foreach ($variacoes as $i => [$ref, $eixo, $valor]) {
            EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $empresa->id, 'ordem' => $i + 1, 'codigo' => $ref, 'eixo' => $eixo, 'valor' => $valor]);
        }

        return $p;
    }

    /** @return array{0: Company, 1: EstruturaProduto, 2: EstruturaProduto, 3: EstruturaProduto, 4: EstruturaProduto} */
    private function cenario(): array
    {
        $empresa = $this->empresaDoGabarito();
        // Varia por cor: a coluna "Cor" não vale para ele.
        $alfa = $this->produto($empresa, 'CAD', 'Cadeira Alfa', 'MLB1', 'Cadeiras', [['CAD-1', 'cor', 'Preto'], ['CAD-2', 'cor', 'Branco']]);
        EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $alfa->id, 'atributo_id' => 'BRAND', 'atributo_nome' => 'Marca', 'valor' => 'Marca X']);
        $beta = $this->produto($empresa, 'CB', 'Cadeira Beta', 'MLB1', 'Cadeiras', [['CB-1', null, null]]);
        $mesa = $this->produto($empresa, 'MS', 'Mesa Gama', 'MLB2', 'Mesas de Jantar', [['MS-1', null, null]]);
        $semCategoria = $this->produto($empresa, 'SC', 'Sem categoria', null, 'Cadeira', [['SC-1', null, null]]);

        return [$empresa, $alfa, $beta, $mesa, $semCategoria];
    }

    private function gravar(Spreadsheet $planilha): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'ficha').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function upload(string $caminho): UploadedFile
    {
        return new UploadedFile($caminho, 'ficha.xlsx', null, null, true);
    }

    /** A coluna de um cabeçalho na linha 1 da aba (letra), ou null. */
    private function coluna(Worksheet $aba, string $cabecalho): ?string
    {
        for ($c = 1; $c <= 40; $c++) {
            if ((string) $aba->getCell([$c, 1])->getValue() === $cabecalho) {
                return Coordinate::stringFromColumnIndex($c);
            }
        }

        return null;
    }

    private function linhaDo(Worksheet $aba, string $grupo): ?int
    {
        for ($l = 2; $l <= 50; $l++) {
            if ((string) $aba->getCell("A{$l}")->getValue() === $grupo) {
                return $l;
            }
        }

        return null;
    }

    private function escrever(Worksheet $aba, string $cabecalho, string $grupo, ?string $valor): void
    {
        $celula = $this->coluna($aba, $cabecalho).$this->linhaDo($aba, $grupo);
        $valor === null ? $aba->getCell($celula)->setValue(null) : $aba->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
    }

    private function valor(EstruturaProduto $p, string $atributo): ?array
    {
        $a = EstruturaProdutoAtributo::where('produto_id', $p->id)->where('atributo_id', $atributo)->first();

        return $a ? [$a->valor, $a->valor_id, $a->unidade] : null;
    }

    // ═══ Download ═══════════════════════════════════════════════════════════

    public function test_baixar_traz_uma_aba_por_categoria_confirmada_com_os_campos_dela_e_o_que_esta_salvo(): void
    {
        [$empresa] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $this->produto($outra, 'X', 'Produto da Outra Empresa', 'MLB1', 'Cadeiras', [['X-1', null, null]]);

        $r = $this->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.fichas.modelo'))
            ->assertOk()->assertDownload('ficha-tecnica.xlsx');
        $caminho = tempnam(sys_get_temp_dir(), 'ficha').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());
        $planilha = IOFactory::load($caminho);

        $this->assertSame(['Cadeiras', 'Mesas de Jantar', 'Instruções', 'Listas'], $planilha->getSheetNames());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Listas')->getSheetState());

        $cadeiras = $planilha->getSheetByName('Cadeiras');
        foreach (['Produto (grupo)*', 'Nome do produto', 'Marca*', 'Material*', 'Altura do assento (cm)', 'Capacidade', 'Com gaveta', 'Materiais', 'Cor'] as $c) {
            $this->assertNotNull($this->coluna($cadeiras, $c), "faltou a coluna {$c}");
        }
        $this->assertSame(['CAD', 'CB'], [(string) $cadeiras->getCell('A2')->getValue(), (string) $cadeiras->getCell('A3')->getValue()]);
        $this->assertNull($cadeiras->getCell('A4')->getValue(), 'só os produtos desta empresa, e só os da categoria');
        $this->assertSame('Marca X', (string) $cadeiras->getCell($this->coluna($cadeiras, 'Marca*').'2')->getValue(), 'já sai com o que está salvo');

        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->assertSame(['Produto (grupo)*', 'Nome do produto', 'Marca', 'Formato'],
            array_map(fn ($c) => (string) $mesas->getCell([$c, 1])->getValue(), [1, 2, 3, 4]));

        // A lista de opções do Material está na aba oculta e a coluna valida contra ela.
        $listas = $planilha->getSheetByName('Listas');
        $todas = [];
        foreach ($listas->getCellCollection()->getCoordinates() as $coord) {
            $todas[] = (string) $listas->getCell($coord)->getValue();
        }
        $this->assertContains('Madeira', $todas);
        $this->assertContains('Microfibra', $todas);
        $zip = new \ZipArchive();
        $zip->open($caminho);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertMatchesRegularExpression("#<formula1>'Listas'!\\\$[A-Z]+\\\$2:\\\$[A-Z]+\\\$\\d+</formula1>#", $xml);

        // Sigilo em tudo o que a pessoa lê no arquivo.
        $textos = [];
        foreach ($planilha->getAllSheets() as $aba) {
            $textos[] = $aba->getTitle();
            foreach ($aba->getCellCollection()->getCoordinates() as $coord) {
                $textos[] = (string) $aba->getCell($coord)->getValue();
            }
        }
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, implode("\n", $textos));
        $this->assertStringNotContainsString('Outra Empresa', implode("\n", $textos));
    }

    public function test_sem_categoria_confirmada_a_planilha_explica_o_que_fazer(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->produto($empresa, 'SC', 'Sem categoria', null, 'Cadeira', [['SC-1', null, null]]);

        $r = app(PlanilhaDasFichasTecnicas::class)->gerar($empresa);

        $this->assertSame(0, $r['categorias']);
        $this->assertSame(['Instruções', 'Listas'], $r['planilha']->getSheetNames());
        $this->assertStringContainsString('Confirme a categoria dos produtos', (string) $r['planilha']->getSheetByName('Instruções')->getCell('A2')->getValue());
    }

    // ═══ Volta: prévia e aplicar ════════════════════════════════════════════

    /** A planilha baixada, preenchida como a pessoa faria (inclusive com erro). */
    private function planilhaPreenchida(Company $empresa): string
    {
        $planilha = app(PlanilhaDasFichasTecnicas::class)->gerar($empresa)['planilha'];
        $aba = $planilha->getSheetByName('Cadeiras');

        // Cadeira Alfa: Marca apagada (vazio não apaga), Material pelo nome, altura com outra unidade, Cor (é o eixo: ignorada).
        $this->escrever($aba, 'Marca*', 'CAD', null);
        $this->escrever($aba, 'Material*', 'CAD', 'metal');
        $this->escrever($aba, 'Altura do assento (cm)', 'CAD', '450 mm');
        $this->escrever($aba, 'Cor', 'CAD', 'Preto');

        // Cadeira Beta: sem Marca (obrigatório), Material fora das opções, vários materiais, Sim/Não e "Não se aplica".
        $this->escrever($aba, 'Material*', 'CB', 'Plástico');
        $this->escrever($aba, 'Materiais', 'CB', 'Couro | algodão');
        $this->escrever($aba, 'Com gaveta', 'CB', 'Sim');
        $this->escrever($aba, 'Capacidade', 'CB', 'Não se aplica');
        $this->escrever($aba, 'Cor', 'CB', 'Branco');
        $this->escrever($aba, 'Altura do assento (cm)', 'CB', '46,5');

        // Uma coluna que não é campo, uma linha de produto que não existe e o produto sem categoria.
        $aba->setCellValueExplicit('Z1', 'Coluna estranha', DataType::TYPE_STRING);
        $aba->setCellValueExplicit('Z2', 'qualquer coisa', DataType::TYPE_STRING);
        $aba->setCellValueExplicit('A10', 'NAO-EXISTE', DataType::TYPE_STRING);
        $aba->setCellValueExplicit('A11', 'SC-1', DataType::TYPE_STRING);
        $aba->setCellValueExplicit($this->coluna($aba, 'Marca*').'11', 'Marca Y', DataType::TYPE_STRING);

        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->escrever($mesas, 'Formato', 'MS', 'Redonda');

        return $this->gravar($planilha);
    }

    public function test_previa_diz_o_que_cada_produto_ganha_o_que_nao_entra_e_o_que_falta_sem_gravar(): void
    {
        [$empresa, $alfa, $beta] = $this->cenario();
        $caminho = $this->planilhaPreenchida($empresa);
        $antes = EstruturaProdutoAtributo::count();

        $r = $this->entrarNoPortal($empresa)->post(route('portal.auth.estrutura.produtos.fichas.previa'), ['arquivo' => $this->upload($caminho)], ['Accept' => 'application/json'])
            ->assertOk();

        $porGrupo = collect($r->json('produtos'))->keyBy('grupo');
        // CAD: Marca X (reenviada? não: apagada na célula), Material, Altura → 2 campos; Cor ignorada sem erro.
        $this->assertSame(2, $porGrupo['CAD']['campos']);
        $this->assertSame([], $porGrupo['CAD']['erros']);
        $this->assertSame([], $porGrupo['CAD']['faltam'], 'a Marca já salva continua valendo');
        // CB: Materiais, Com gaveta, Capacidade (N/A), Cor, Altura → 5; Material inválido; faltam Marca e Material.
        $this->assertSame(5, $porGrupo['CB']['campos']);
        $this->assertSame(['Escolha uma das opções de “Material”.'], $porGrupo['CB']['erros']);
        $this->assertSame(['Marca', 'Material'], $porGrupo['CB']['faltam']);
        $this->assertSame(1, $porGrupo['MS']['campos']);
        $this->assertSame(['Não achamos o produto NAO-EXISTE. Não mude a coluna Produto (grupo).'], $porGrupo['NAO-EXISTE']['erros']);
        $this->assertSame(['Escolha a categoria do produto antes de preencher a ficha técnica.'], $porGrupo['SC-1']['erros']);
        $this->assertSame(['produtos' => 3, 'campos' => 8, 'com_erro' => 2, 'com_falta' => 1, 'nao_encontrados' => 1], $r->json('totais'));
        $this->assertContains('Cadeiras: a coluna “Coluna estranha” não é um campo da ficha desta categoria e foi ignorada.', $r->json('avisos'));

        $this->assertSame($antes, EstruturaProdutoAtributo::count(), 'a prévia não grava nada');
        $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $r->getContent());
        $this->assertDoesNotMatchRegularExpression(self::PROIBIDO, $json);
    }

    public function test_aplicar_grava_mesclando_o_valido_mantem_o_que_estava_e_agenda_o_preparo(): void
    {
        [$empresa, $alfa, $beta, $mesa] = $this->cenario();
        $caminho = $this->planilhaPreenchida($empresa);

        Queue::fake();
        $r = $this->entrarNoPortal($empresa)->post(route('portal.auth.estrutura.produtos.fichas.importacao'), ['arquivo' => $this->upload($caminho)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(['produtos' => 3, 'campos' => 8, 'com_erro' => 2, 'com_falta' => 1, 'nao_encontrados' => 1], array_intersect_key($r->json(), array_flip(['produtos', 'campos', 'com_erro', 'com_falta', 'nao_encontrados'])));
        $this->assertContains('CB: Escolha uma das opções de “Material”.', $r->json('nao_entraram'));

        $this->assertSame(['Marca X', null, null], $this->valor($alfa, 'BRAND'), 'célula em branco não apaga');
        $this->assertSame(['Metal', '102', null], $this->valor($alfa, 'MATERIAL'), 'a opção pelo nome, sem caixa');
        $this->assertSame(['450', null, 'mm'], $this->valor($alfa, 'SEAT_HEIGHT'), 'a unidade escrita ao lado do número');
        $this->assertNull($this->valor($alfa, 'COLOR'), 'a cor é o eixo do produto: não vale para ele');

        $this->assertSame(['Couro | Algodão', null, null], $this->valor($beta, 'MATERIALS'));
        $this->assertSame(['Sim', null, null], $this->valor($beta, 'WITH_DRAWER'));
        $this->assertSame([null, '-1', null], $this->valor($beta, 'CAPACITY'), 'Não se aplica');
        $this->assertSame(['Branco', '52055', null], $this->valor($beta, 'COLOR'), 'sem variação por cor, a Cor vale');
        $this->assertSame(['46.5', null, 'cm'], $this->valor($beta, 'SEAT_HEIGHT'));
        $this->assertNull($this->valor($beta, 'MATERIAL'), 'o inválido não grava');
        $this->assertSame(['Redonda', '41', null], $this->valor($mesa, 'SHAPE'));

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $alfa->id);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $beta->id);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $mesa->id);

        // Enviar de novo a mesma planilha não muda nada.
        $de_novo = app(ImportadorFichasTecnicas::class)->aplicar($empresa, $caminho, $this->atorCliente($empresa));
        $this->assertSame(0, $de_novo['campos']);
    }

    public function test_arquivo_que_nao_e_a_planilha_da_ficha_volta_com_erro_claro(): void
    {
        [$empresa] = $this->cenario();
        $sessao = $this->entrarNoPortal($empresa);

        // Texto com nome de .xlsx: a validação do arquivo recusa, com mensagem em português (não a chave crua).
        $falso = tempnam(sys_get_temp_dir(), 'ficha').'.xlsx';
        file_put_contents($falso, 'não é zip');
        $this->temporarios[] = $falso;
        $sessao->post(route('portal.auth.estrutura.produtos.fichas.previa'), ['arquivo' => $this->upload($falso)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.arquivo.0', 'Envie a planilha no formato .xlsx.');
        $sessao->post(route('portal.auth.estrutura.produtos.importacao.previa'), ['arquivo' => $this->upload($falso)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.arquivo.0', 'Envie a planilha no formato .xlsx.');

        // Planilha de verdade, mas sem a coluna Produto (grupo): a leitura recusa com a mensagem da ficha.
        $semColuna = new Spreadsheet();
        $semColuna->getActiveSheet()->setTitle('Cadeiras')->fromArray([['Marca'], ['X']]);
        $sessao->post(route('portal.auth.estrutura.produtos.fichas.previa'), ['arquivo' => $this->upload($this->gravar($semColuna))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('erro_geral', 'Não conseguimos ler este arquivo. Use a planilha da ficha técnica (.xlsx) baixada aqui.');

        $semGrupo = new Spreadsheet();
        $semGrupo->getActiveSheet()->setTitle('Cadeiras')->fromArray([['Marca', 'Material'], ['X', 'Metal']]);
        $sessao->post(route('portal.auth.estrutura.produtos.fichas.importacao'), ['arquivo' => $this->upload($this->gravar($semGrupo))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->assertSame(1, EstruturaProdutoAtributo::count());
    }
}
