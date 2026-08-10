<?php

namespace Tests\Unit\Phase134;

use Tests\TestCase;

/**
 * Fase 134 (Plano 01) — trava os 3 insumos que a fase inteira consome:
 *   (a) os 7 parâmetros de `config/mlb_acervo.php` não mudam em silêncio;
 *   (b) o comando de sondagem nunca escreve na API do ML (D-11);
 *   (c) as fixtures são payload REAL da API, não shape inventado.
 *
 * Nenhum teste aqui chama a API do ML — só lê fonte/config/fixture do disco.
 *
 * @group phase134
 */
class SondagemSaudeMlTest extends TestCase
{
    private const FIXTURES = [
        'scroll-pagina-1.json',
        'scroll-pagina-2.json',
        'scroll-pagina-3.json',
        'multiget-lote.json',
        'multiget-item-com-variacoes.json',
        'price-to-win.json',
        'visits.json',
        'performance-sondagem.json',
    ];

    /** @test */
    public function config_da_fase_tem_os_parametros_decididos(): void
    {
        $config = config('mlb_acervo');

        $this->assertSame(
            ['rotacao_n', 'chunk_detalhe', 'lote_multiget', 'pagina_scroll', 'retencao_dias', 'defasagem_horas', 'saude_ml_disponivel'],
            array_keys($config),
            'config/mlb_acervo.php precisa ter exatamente estas 7 chaves, nesta ordem — '
            . 'mudar N silenciosamente aqui muda o custo de coleta da fase inteira'
        );

        $this->assertSame(7, $config['rotacao_n']);
        $this->assertSame(500, $config['chunk_detalhe']);
        $this->assertSame(20, $config['lote_multiget']);
        $this->assertSame(50, $config['pagina_scroll']);
        $this->assertSame(90, $config['retencao_dias']);
        $this->assertSame(24, $config['defasagem_horas']);
    }

    /** @test */
    public function comando_de_sondagem_nunca_escreve_na_api_do_ml(): void
    {
        $path = base_path('app/Console/Commands/SondarAcervoMl.php');
        $this->assertFileExists($path);

        $fonte = file_get_contents($path);
        $linhas = explode("\n", $fonte);

        // Remove linhas de comentário — a prosa pt-BR deste projeto cita os
        // próprios identificadores (`->post(`, `Http::`) para explicar a
        // trava do D-11; um grep cru sobre o arquivo inteiro contaria essas
        // linhas de comentário e passaria pelo motivo errado.
        $semComentarios = array_filter($linhas, function (string $linha): bool {
            $semEspacos = ltrim($linha);
            return ! str_starts_with($semEspacos, '//') && ! str_starts_with($semEspacos, '*');
        });

        $fonteFiltrada = implode("\n", $semComentarios);

        foreach (['->post(', '->put(', '->delete(', '->patch(', 'Http::'] as $proibido) {
            $this->assertStringNotContainsString(
                $proibido,
                $fonteFiltrada,
                "D-11 violado: '{$proibido}' encontrado fora de comentário em SondarAcervoMl.php"
            );
        }
    }

    /** @test */
    public function fixtures_da_api_real_existem_e_sao_json_valido(): void
    {
        $dir = base_path('tests/fixtures/phase134');

        foreach (self::FIXTURES as $nome) {
            $path = $dir . DIRECTORY_SEPARATOR . $nome;

            $this->assertFileExists(
                $path,
                "Fixture ausente: {$nome} — a sondagem da Task 2 (134-01) ainda não foi "
                . 'concluída com acesso real à produção. Isto é uma lacuna real da Wave 0, '
                . 'não um detalhe: repetir `php artisan mlb:acervo-sondar --fixtures` na VPS.'
            );

            $conteudo = file_get_contents($path);
            $decodificado = json_decode($conteudo, true);

            $this->assertSame(
                JSON_ERROR_NONE,
                json_last_error(),
                "Fixture {$nome} não é JSON válido: " . json_last_error_msg()
            );
            $this->assertIsArray($decodificado, "Fixture {$nome} não decodificou para array/objeto");
        }

        $itemComVariacoes = json_decode(
            file_get_contents($dir . DIRECTORY_SEPARATOR . 'multiget-item-com-variacoes.json'),
            true
        );

        $this->assertNotEmpty(
            $itemComVariacoes['variations'] ?? null,
            'multiget-item-com-variacoes.json precisa ter variations não vazio'
        );
        $this->assertArrayHasKey(
            'available_quantity',
            $itemComVariacoes,
            'available_quantity precisa estar no nível do item-pai (agregado do D-17)'
        );
    }
}
