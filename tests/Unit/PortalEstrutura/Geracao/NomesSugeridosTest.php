<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\NomesSugeridos;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NomesSugeridosTest extends TestCase
{
    private const CADEIRA = ['nome' => 'Cadeira', 'plural' => 'Cadeiras'];

    public static function combos(): array
    {
        return [
            'nome começa pelo tipo'      => ['Cadeira Farmhouse Madeira', 'CF-1', 'Natural', 4, self::CADEIRA, 'Combo 4 Cadeiras Farmhouse Madeira — Natural', 'CF-1-CB4'],
            'começa pelo plural'         => ['Cadeiras Nordic', 'CN-1', null, 2, self::CADEIRA, 'Combo 2 Cadeiras Nordic', 'CN-1-CB2'],
            'prefixo solto não conta'    => ['Cadeirinha Kids', 'CK-1', null, 2, self::CADEIRA, 'Combo 2 Cadeirinha Kids', 'CK-1-CB2'],
            'sem tipo'                   => ['Banqueta Alta', 'BA-1', null, 3, null, 'Combo 3 Banqueta Alta', 'BA-1-CB3'],
            'resto vazio'                => ['Cadeira', 'C-1', null, 2, self::CADEIRA, 'Combo 2 Cadeira', 'C-1-CB2'],
            'sem acento e sem caixa'     => ['CADEIRA Polo', 'CP-1', null, 6, self::CADEIRA, 'Combo 6 Cadeiras Polo', 'CP-1-CB6'],
        ];
    }

    #[DataProvider('combos')]
    public function test_combo(string $nome, string $sku, ?string $valor, int $n, ?array $tipo, string $nomeEsperado, string $skuEsperado): void
    {
        $this->assertSame(
            ['nome' => $nomeEsperado, 'sku' => $skuEsperado],
            NomesSugeridos::combo($nome, $sku, $valor, $n, $tipo),
        );
    }

    public static function kits(): array
    {
        $mesa = fn (?string $v) => ['produto_nome' => 'Mesa Polo', 'sku' => 'MP-1', 'valor' => $v];
        $cad  = fn (?string $v) => ['produto_nome' => 'Cadeira Polo', 'sku' => 'CP-1', 'valor' => $v];

        return [
            'mesmo valor'      => [$mesa('Natural'), $cad('Natural'), 'Mesa Polo + Cadeira Polo — Natural', 'KT-MP-1-CP-1'],
            'valores distintos' => [$mesa('Natural'), $cad('Preto'), 'Mesa Polo + Cadeira Polo — Natural / Preto', 'KT-MP-1-CP-1'],
            'valor de um lado' => [$mesa('Natural'), $cad(null), 'Mesa Polo + Cadeira Polo — Natural', 'KT-MP-1-CP-1'],
            'sem valores'      => [$mesa(null), $cad(null), 'Mesa Polo + Cadeira Polo', 'KT-MP-1-CP-1'],
            'valor igual sem caixa' => [$mesa('Natural'), $cad('natural'), 'Mesa Polo + Cadeira Polo — Natural', 'KT-MP-1-CP-1'],
        ];
    }

    #[DataProvider('kits')]
    public function test_kit(array $a, array $b, string $nome, string $sku): void
    {
        $this->assertSame(['nome' => $nome, 'sku' => $sku], NomesSugeridos::kit($a, $b));
    }

    public function test_combit(): void
    {
        $fixo = ['produto_nome' => 'Mesa Polo', 'sku' => 'MP-1', 'valor' => 'Natural'];
        $rep  = ['produto_nome' => 'Cadeira Polo', 'sku' => 'CP-1', 'valor' => 'Natural'];

        $this->assertSame(
            ['nome' => 'Mesa Polo + 4 Cadeiras — Natural', 'sku' => 'CT4-MP-1-CP-1'],
            NomesSugeridos::combit($fixo, $rep, 4, self::CADEIRA),
        );
    }

    /**
     * Os exemplos do "Como funciona" do Portal (`ComoFunciona.jsx`, 10/10/2026) são o nome e o
     * SKU que esta classe dá às ofertas da aula (cadeira + mesa): se o padrão mudar aqui, o
     * exemplo da tela acusa — antes ele mostrava "MSA-MR+CAD-01-KIT" e "-CBT4".
     */
    public function test_os_exemplos_do_como_funciona_sao_os_desta_classe(): void
    {
        $jsx = (string) file_get_contents(resource_path('js/Components/Portal/Estrutura/ComoFunciona.jsx'));
        $cadeira = ['produto_nome' => 'Cadeira 01', 'sku' => 'CAD-01', 'valor' => null];
        $mesa = ['produto_nome' => 'Mesa Marfim', 'sku' => 'MSA-MR', 'valor' => null];

        foreach ([
            NomesSugeridos::combo('Cadeira 01', 'CAD-01', null, 2, self::CADEIRA),
            NomesSugeridos::kit($mesa, $cadeira),
            NomesSugeridos::combit($mesa, $cadeira, 4, self::CADEIRA),
        ] as $oferta) {
            $this->assertStringContainsString("'{$oferta['nome']}', '{$oferta['sku']}'", $jsx);
        }
    }

    public function test_avisos(): void
    {
        $lim = ['max_titulo' => 60, 'max_sku' => 120];

        $this->assertSame([['codigo' => 'titulo_longo', 'valor' => 61]], NomesSugeridos::avisos(str_repeat('a', 61), 'X', $lim));
        $this->assertSame([], NomesSugeridos::avisos(str_repeat('a', 60), str_repeat('b', 120), $lim));
        $this->assertContains(['codigo' => 'sku_longo', 'valor' => 121], NomesSugeridos::avisos('ok', str_repeat('b', 121), $lim));
        $this->assertSame([['codigo' => 'titulo_longo', 'valor' => 60]], NomesSugeridos::avisos(str_repeat('ã', 60), 'X', ['max_titulo' => 59, 'max_sku' => 120]));
    }

    public function test_porque_combo(): void
    {
        $this->assertSame(
            'Mesmo produto em mais unidades. Tipo: Cadeira (2, 4, 6).',
            NomesSugeridos::porque('combo', ['tipo' => 'Cadeira', 'quantidades' => [2, 4, 6]]),
        );
        $this->assertSame(
            'Mesmo produto em mais unidades. Quantidades do produto: 2, 4.',
            NomesSugeridos::porque('combo', ['tipo' => null, 'quantidades' => [2, 4], 'quantidades_do_produto' => true]),
        );
    }

    public function test_porque_kit(): void
    {
        $this->assertSame(
            'Mesma família: Farmhouse. Ambiente em comum: Sala de jantar. Par: mesa + cadeira.',
            NomesSugeridos::porque('kit', ['familia' => 'Farmhouse', 'ambientes' => ['Sala de jantar'], 'tipo_a' => 'Mesa', 'tipo_b' => 'Cadeira']),
        );
        $this->assertSame(
            'Mesma família: Farmhouse. Ambiente em comum: Sala, Cozinha. Par: mesa + cadeira.',
            NomesSugeridos::porque('kit', ['familia' => 'Farmhouse', 'ambientes' => ['Sala', 'Cozinha'], 'tipo_a' => 'Mesa', 'tipo_b' => 'Cadeira']),
        );
    }

    public static function repeticoes(): array
    {
        return [
            'cadeira' => ['Cadeira', 'Par: mesa + cadeira; a cadeira se repete.'],
            'banco'   => ['Banco', 'Par: mesa + banco; o banco se repete.'],
            'estante' => ['Estante', 'Par: mesa + estante; estante se repete.'],
            'sem artigo' => ['Puff', 'Par: mesa + puff; puff se repete.'],
        ];
    }

    #[DataProvider('repeticoes')]
    public function test_porque_combit(string $repete, string $final): void
    {
        $tipoB = $repete;
        $txt   = NomesSugeridos::porque('combit', ['familia' => 'Farmhouse', 'ambientes' => ['Sala'], 'tipo_a' => 'Mesa', 'tipo_b' => $tipoB, 'repete' => $repete]);

        $this->assertStringEndsWith($final, $txt);
    }
}
