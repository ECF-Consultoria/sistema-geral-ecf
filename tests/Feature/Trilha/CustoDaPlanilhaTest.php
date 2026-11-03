<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ModeloCompleto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O custo da planilha por categoria (F7, desenho §17.7–17.8, D25): com o cache quente (o baixar já leu a categoria e a
 * ficha), a prévia e o gravar NÃO chamam nada de fora — nem fora nem dentro das transações — e cada produto custa o
 * MESMO número de consultas (o que é da empresa inteira, como a régua da trava, é lido uma vez por arquivo). E o tempo
 * do arquivo cheio: 500 linhas (125 produtos de 2 cores × 2 tamanhos, com a ficha), medido e impresso.
 */
class CustoDaPlanilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    /**
     * Os tetos de consultas por produto de 2 cores + ficha (medidos em 03/11/2026: 84 e 97). Subir o teto é decisão:
     * alguém pôs uma leitura por produto no caminho da planilha.
     */
    private const TETO_DA_PREVIA = 88;

    private const TETO_DO_GRAVAR = 101;

    private Company $empresa;

    private User $dev;

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::fake(fn (Request $r) => PlanilhaNaTrilhaTest::catalogo($r));

        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_com_o_cache_quente_nada_sai_para_fora_e_cada_produto_custa_as_mesmas_consultas(): void
    {
        $modelo = $this->baixar();
        $chamadas = count(Http::recorded());

        // A prévia: 2, 3 e 5 produtos — o 3º e os seguintes custam o mesmo que o 2º.
        $previa = [];
        foreach ([2, 3, 5] as $n) {
            [$consultas, $r] = $this->consultas(fn () => $this->enviar('/planilha/previa', $this->arquivo($modelo, $n, "P{$n}")));
            $this->assertSame($n, $r->assertOk()->json('totais.novos'));
            $previa[$n] = $consultas;
        }
        $porProduto = $previa[3] - $previa[2];
        $this->assertGreaterThan(0, $porProduto);
        $this->assertSame(2 * $porProduto, $previa[5] - $previa[3], 'consultas da prévia por produto: '.json_encode($previa));
        // O teto (medido: 84). O que é da empresa inteira — a régua da trava — é lido uma vez por ARQUIVO: relido por
        // produto, ele continuaria constante por produto, mas passaria daqui.
        $this->assertLessThanOrEqual(self::TETO_DA_PREVIA, $porProduto, 'consultas da prévia por produto');
        $porProdutoNaPrevia = $porProduto;

        // O gravar: o mesmo, com produtos novos a cada envio (a empresa cresce; o custo por produto não). Um envio antes
        // de medir: com a empresa ainda vazia, a régua da trava (lida uma vez por arquivo) pula consultas — custo do
        // ARQUIVO, não do produto.
        $this->enviar('/planilha', $this->arquivo($modelo, 1, 'G0'))->assertOk();
        $gravar = [];
        foreach ([2, 3, 5] as $n) {
            [$consultas, $r] = $this->consultas(fn () => $this->enviar('/planilha', $this->arquivo($modelo, $n, "G{$n}")));
            $this->assertSame($n, $r->assertOk()->json('totais.novos'));
            $gravar[$n] = $consultas;
        }
        $porProduto = $gravar[3] - $gravar[2];
        $this->assertSame(2 * $porProduto, $gravar[5] - $gravar[3], 'consultas do gravar por produto: '.json_encode($gravar));
        $this->assertLessThanOrEqual(self::TETO_DO_GRAVAR, $porProduto, 'consultas do gravar por produto');

        $this->assertSame($chamadas, count(Http::recorded()), 'a prévia ou o gravar chamou o catálogo com o cache quente');
        $this->assertSame(11, EstruturaProduto::query()->where('company_id', $this->empresa->id)->count());
        fwrite(STDERR, sprintf("
[F7] consultas por produto (2 cores + ficha): prévia %d, gravar %d
", $porProdutoNaPrevia, $porProduto));
    }

    public function test_quinhentas_linhas_gravam_de_uma_vez_e_o_tempo_fica_medido(): void
    {
        $modelo = $this->baixar();
        $chamadas = count(Http::recorded());
        $arquivo = $this->arquivo($modelo, 125, 'Q', 4);

        $inicio = microtime(true);
        $previa = $this->enviar('/planilha/previa', $arquivo)->assertOk()->json();
        $tempoDaPrevia = microtime(true) - $inicio;

        $inicio = microtime(true);
        $gravado = $this->enviar('/planilha', $arquivo)->assertOk()->json();
        $tempoDoGravar = microtime(true) - $inicio;

        fwrite(STDERR, sprintf("\n[F7] 500 linhas (125 produtos × 4 variações + ficha): prévia %.1f s, gravar %.1f s\n", $tempoDaPrevia, $tempoDoGravar));

        $this->assertSame(['novos' => 125, 'atualizados' => 0, 'sem_mudanca' => 0, 'recusados' => 0], $previa['totais']);
        $this->assertSame(['novos' => 125, 'atualizados' => 0, 'sem_mudanca' => 0, 'recusados' => 0], $gravado['totais']);
        $this->assertSame(500, EstruturaProdutoVariacao::query()->where('company_id', $this->empresa->id)->count());
        $this->assertSame($chamadas, count(Http::recorded()));
        // Teto largo, só para pegar um salto (o número de verdade sai no STDERR e está no learnings §35).
        $this->assertLessThan(240, $tempoDoGravar);
    }

    // ─── apoio ───

    private function baixar(): string
    {
        $r = $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/planilha/modelo?categorias[]=MLB1")->assertOk();
        $caminho = tempnam(sys_get_temp_dir(), 'cp').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());

        return $caminho;
    }

    /** `$produtos` camisetas de `$variacoes` linhas cada (2 cores; com 4, também 2 tamanhos), com a ficha na 1ª linha. */
    private function arquivo(string $modelo, int $produtos, string $prefixo, int $variacoes = 2): UploadedFile
    {
        $planilha = IOFactory::load($modelo);
        $aba = $planilha->getSheetByName('Camisetas');
        $colunas = $this->colunas($aba);
        $linha = ModeloCompleto::PRIMEIRA_LINHA;
        for ($p = 1; $p <= $produtos; $p++) {
            for ($v = 0; $v < $variacoes; $v++) {
                $valores = [
                    'Produto (grupo)' => "{$prefixo}-{$p}", 'Ref' => "{$prefixo}-{$p}-{$v}", 'Nome do produto' => "Camiseta {$prefixo} {$p}",
                    'Tipo de variação' => 'Cor', 'Variação' => $v % 2 === 0 ? 'Preto' : 'Branco', 'Custo (R$)' => '39,90',
                    'Volume 1: comprimento (cm)' => '30', 'Volume 1: largura (cm)' => '25', 'Volume 1: altura (cm)' => '3', 'Volume 1: peso (kg)' => '0,3',
                ];
                if ($variacoes > 2) {
                    $valores += ['Tipo da 2ª variação' => 'Tamanho', '2ª variação' => $v < 2 ? 'P' : 'M'];
                }
                if ($v === 0) {
                    $valores += ['Descrição' => 'Uma camiseta.', 'Marca' => 'Bela Malha', 'Material' => 'Algodão', 'Tipo de manga' => 'Curta'];
                }
                foreach ($valores as $cabecalho => $valor) {
                    $aba->setCellValueExplicit($colunas[$cabecalho].$linha, $valor, DataType::TYPE_STRING);
                }
                $linha++;
            }
        }

        return $this->salvar($planilha);
    }

    /** @return array<string, string> cabeçalho → letra da coluna */
    private function colunas(Worksheet $aba): array
    {
        $saida = [];
        for ($c = 1; $c <= 60; $c++) {
            $titulo = (string) $aba->getCell([$c, ModeloCompleto::LINHA_DO_CABECALHO])->getValue();
            if ($titulo !== '') {
                $saida[$titulo] ??= Coordinate::stringFromColumnIndex($c);
            }
        }

        return $saida;
    }

    private function salvar(Spreadsheet $planilha): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cp').'.xlsx';
        $this->temporarios[] = $caminho;
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);

        return new UploadedFile($caminho, 'planilha.xlsx', null, null, true);
    }

    private function enviar(string $caminho, UploadedFile $arquivo): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->dev)->post("/trilha/{$this->empresa->id}{$caminho}", ['arquivo' => $arquivo]);
    }

    /** @return array{0: int, 1: mixed} quantas consultas a função fez, e o que ela devolveu */
    private function consultas(callable $fazer): array
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $antes = $n;
        $r = $fazer();

        // `DB::listen` não se desfaz: cada chamada conta com o seu próprio contador.
        return [$n - $antes, $r];
    }
}
