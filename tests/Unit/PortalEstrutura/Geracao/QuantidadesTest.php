<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\Quantidades;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuantidadesTest extends TestCase
{
    public static function leituras(): array
    {
        return [
            'null herda'                     => [null, null],
            'só espaços herda'               => ['  ', null],
            'zero quer dizer nenhuma'        => ['0', []],
            'separadores mistos e repetidos' => ['6, 2;4 4', [2, 4, 6]],
            'um valor'                       => ['8', [8]],
            'limite superior'                => ['999', [999]],
            'oito itens'                     => ['2,3,4,5,6,7,8,9', [2, 3, 4, 5, 6, 7, 8, 9]],
        ];
    }

    #[DataProvider('leituras')]
    public function test_ler(?string $texto, ?array $esperado): void
    {
        $this->assertSame($esperado, Quantidades::ler($texto));
    }

    public static function invalidas(): array
    {
        return [
            'menor que 2'       => ['1'],
            'acima de 999'      => ['2, 1000'],
            'zero misturado'    => ['0, 2'],
            'nove itens'        => ['2,3,4,5,6,7,8,9,10'],
            'não inteiro'       => ['2,x'],
            'decimal'           => ['2.5'],
            'negativo'          => ['-3'],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_ler_invalido_lanca_com_a_mensagem_fixa(string $texto): void
    {
        try {
            Quantidades::ler($texto, 'qtd_combo');
            $this->fail('Deveria lançar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(Quantidades::MENSAGEM, $e->errors()['qtd_combo'][0]);
        }
    }

    public function test_para_texto(): void
    {
        $this->assertSame('0', Quantidades::paraTexto([]));
        $this->assertSame('2, 4, 6', Quantidades::paraTexto([2, 4, 6]));
        $this->assertNull(Quantidades::paraTexto(null));
    }

    public function test_do_tipo(): void
    {
        $this->assertSame([], Quantidades::doTipo(null));
        $this->assertSame([], Quantidades::doTipo(''));
        $this->assertSame([], Quantidades::doTipo('0'));
        $this->assertSame([2, 4, 6], Quantidades::doTipo('2, 4, 6'));
    }

    public function test_efetivas(): void
    {
        $this->assertSame([2, 4, 6], Quantidades::efetivas(null, [2, 4, 6]));
        $this->assertSame([], Quantidades::efetivas([], [2, 4, 6]));
        $this->assertSame([8], Quantidades::efetivas([8], [2, 4, 6]));
    }
}
