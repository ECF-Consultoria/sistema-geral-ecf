<?php

namespace Tests\Unit\Publicador\Concerns;

use App\Support\Publicador\Schema\CategorySchema;

/**
 * Os schemas REAIS da sondagem de 01/10/2026 (`tests/fixtures-ml/sondagem/publico`):
 * MLB193945 cadeira de escritório, MLB189007 furadeira, MLB31447 camiseta,
 * MLB47097 pastilha de freio. Os testes do núcleo rodam sobre o que a API
 * devolveu, não sobre o que a documentação descreve.
 */
trait CarregaSchemas
{
    protected const CADEIRA = 'MLB193945';
    protected const FURADEIRA = 'MLB189007';
    protected const CAMISETA = 'MLB31447';
    protected const PASTILHA = 'MLB47097';

    protected static function schema(string $categoria, ?callable $ajustar = null): CategorySchema
    {
        $base = dirname(__DIR__, 3).'/fixtures-ml/sondagem/publico/categorias/'.$categoria;
        $ler = fn (string $arquivo) => json_decode(file_get_contents("{$base}/{$arquivo}"), true)['resposta'];

        $fontes = [
            'categoria'       => $ler('categoria.json'),
            'atributos'       => $ler('atributos.json'),
            'technical_specs' => $ler('technical_specs_input.json'),
            'sale_terms'      => $ler('sale_terms.json'),
        ];
        if ($ajustar) {
            $fontes = $ajustar($fontes);
        }

        return CategorySchema::dasFontes($categoria, $fontes['categoria'], $fontes['atributos'], $fontes['technical_specs'], $fontes['sale_terms']);
    }
}
