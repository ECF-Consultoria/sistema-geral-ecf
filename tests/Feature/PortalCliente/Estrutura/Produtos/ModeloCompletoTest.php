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
            // Some da ficha (cita a plataforma) e nunca pode virar coluna.
            ['id' => 'APARECE', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string', 'tags' => []],
        ];
    }

    private function baixar(Company $empresa, array $categorias): Spreadsheet
    {
        $r = $this->entrarNoPortal($empresa)->get(route('portal.auth.estrutura.produtos.modelo_completo', ['categorias' => $categorias]))
            ->assertOk()->assertDownload('modelo-completo.xlsx');
        $caminho = tempnam(sys_get_temp_dir(), 'mc').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());

        return IOFactory::load($caminho);
    }

    private function coluna(Worksheet $aba, string $cabecalho): string
    {
        for ($c = 1; $c <= 60; $c++) {
            if ((string) $aba->getCell([$c, 1])->getValue() === $cabecalho) {
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
        $this->linha($cadeiras, 2, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-PT', 'Tipo de variação' => 'Cor', 'Variação' => 'Preto',
            'Nome do produto*' => 'Cadeira Alfa', "Volumes (C\u{00D7}L\u{00D7}A cm \u{00B7} kg)" => "60\u{00D7}50\u{00D7}40 \u{00B7} 8,5",
            'Custo (R$)' => 120.5, 'Estoque (un.)' => 10,
            'Marca*' => 'Bela Casa', 'Material*' => 'metal', 'Altura do assento (cm)' => '450 mm', 'Material do estofamento' => 'Bouclé texturizado']);
        $this->linha($cadeiras, 3, ['Produto (grupo)*' => 'ALFA', 'Ref*' => 'ALFA-BR', 'Tipo de variação' => 'Cor', 'Variação' => 'Branco',
            'Nome do produto*' => 'Cadeira Alfa', "Volumes (C\u{00D7}L\u{00D7}A cm \u{00B7} kg)" => "60\u{00D7}50\u{00D7}40 \u{00B7} 8,5", 'Com gaveta' => 'Sim']);
        // Cadeira Beta: Material fora das opções (lista fechada) e sem a Marca obrigatória.
        $this->linha($cadeiras, 5, ['Produto (grupo)*' => 'BETA', 'Ref*' => 'BETA-1', 'Nome do produto*' => 'Cadeira Beta', 'Material*' => 'Plástico']);

        $mesas = $planilha->getSheetByName('Mesas de Jantar');
        $this->linha($mesas, 2, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Forma*' => 'Redonda']);

        return $this->gravar($planilha);
    }

    // ═══ Baixar ═════════════════════════════════════════════════════════════

    public function test_baixar_traz_uma_aba_por_categoria_com_o_produto_e_a_ficha_na_mesma_linha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $planilha = $this->baixar($empresa, ['MLB1', 'MLB2']);

        $this->assertSame(['Cadeiras', 'Mesas de Jantar', 'Instruções', 'Listas', 'Categorias'], $planilha->getSheetNames());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Listas')->getSheetState());
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $planilha->getSheetByName('Categorias')->getSheetState());

        $cadeiras = $planilha->getSheetByName('Cadeiras');
        $cabecalhos = [];
        for ($c = 1; $c <= 30; $c++) {
            $titulo = (string) $cadeiras->getCell([$c, 1])->getValue();
            if ($titulo !== '') {
                $cabecalhos[] = $titulo;
            }
        }
        // As colunas do produto (sem "Categoria": a aba já é a categoria), com o grupo na frente; depois, a ficha.
        $this->assertSame(ModeloCompleto::cabecalhosDoProduto(), array_slice($cabecalhos, 0, 13));
        $this->assertSame('Produto (grupo)*', $cabecalhos[0]);
        $this->assertNotContains('Categoria', $cabecalhos);
        $this->assertSame(['Marca*', 'Material*', 'Altura do assento (cm)', 'Material do estofamento', 'Com gaveta'], array_slice($cabecalhos, 13));
        $this->assertNotContains('Aparece no Mercado Livre', $cabecalhos, 'campo que cita a plataforma nunca vira coluna');

        // As listas da ficha valem como validação; a fechada é estrita, a que aceita valor próprio só sugere.
        $material = $cadeiras->getDataValidation($this->coluna($cadeiras, 'Material*').'2');
        $this->assertSame('list', $material->getType());
        $this->assertTrue($material->getShowErrorMessage());
        $this->assertFalse($cadeiras->getDataValidation($this->coluna($cadeiras, 'Material do estofamento').'2')->getShowErrorMessage());
        $this->assertSame('"Cor,Tamanho,Voltagem,Material,Sabor,Outro"', $cadeiras->getDataValidation($this->coluna($cadeiras, 'Tipo de variação').'2')->getFormula1());

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
        }
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
        // A linha da prévia é a linha da aba enviada (a BETA está na 5, com a 4 em branco), e diz de qual aba é.
        $novos = collect($r->json('grupos.novos'))->keyBy('codigo');
        $this->assertSame([5, 'Cadeiras'], [$novos['BETA-1']['linha'], $novos['BETA-1']['aba']]);
        $this->assertSame([2, 'Mesas de Jantar'], [$novos['MESA-1']['linha'], $novos['MESA-1']['aba']]);

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
        $this->linha($planilha->getSheetByName('Mesas de Jantar'), 2, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Forma*' => 'Redonda']);
        app(ImportadorModeloCompleto::class)->aplicar($empresa, $this->gravar($planilha), $this->atorCliente($empresa));
        $mesa = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Mesa Gama')->firstOrFail();

        // A mesma mesa, agora na aba de Cadeiras, com a ficha de cadeira.
        $outra = $this->baixar($empresa, ['MLB1']);
        $this->linha($outra->getSheetByName('Cadeiras'), 2, ['Produto (grupo)*' => 'MESA', 'Ref*' => 'MESA-1', 'Nome do produto*' => 'Mesa Gama', 'Marca*' => 'Bela Casa']);
        $r = app(ImportadorModeloCompleto::class)->previa($empresa, $this->gravar($outra));

        $doItem = collect($r['ficha']['produtos'])->firstWhere('grupo', 'MESA');
        $this->assertSame(['O produto MESA já está em outra categoria; a ficha técnica desta aba não vale para ele.'], $doItem['erros'] ?: [$doItem['motivo'] ?? null]);
        $this->assertSame('MLB2', $mesa->fresh()->categoria_ml_id, 'a categoria escolhida antes não é trocada');
        $this->assertNull($this->valor($mesa, 'BRAND'));
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
