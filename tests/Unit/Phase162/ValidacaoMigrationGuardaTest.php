<?php

namespace Tests\Unit\Phase162;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guarda de CONTEÚDO das duas migrations de validação (Fase 162) — molde de
 * `tests/Unit/Phase161/KitMigrationGuardaTest.php`. Lê o ARQUIVO (não só
 * executa a migration): é isto que pega nome de índice/enum/FK mesmo
 * correndo em SQLite, onde o MariaDB de produção quebraria.
 */
class ValidacaoMigrationGuardaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_10_05_100000_add_validacao_columns_to_ml_anuncio_criativos_table.php',
        'database/migrations/2026_10_05_100100_add_validacao_columns_to_ml_anuncio_criativo_kits_table.php',
    ];

    /** Remove linhas de comentário (// , * , /*) antes de qualquer grep de conteúdo. */
    private function semComentarios(string $conteudo): string
    {
        return implode("\n", array_filter(
            explode("\n", $conteudo),
            fn (string $linha) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $linha)
        ));
    }

    public function test_up_e_down_usam_schema_has_column(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            $this->assertStringContainsString(
                'Schema::hasColumn(',
                $conteudo,
                "{$caminho} deveria usar Schema::hasColumn() para ser idempotente."
            );

            // Pelo menos duas ocorrências: uma no up(), outra no down().
            $this->assertGreaterThanOrEqual(
                2,
                substr_count($conteudo, 'Schema::hasColumn('),
                "{$caminho} deveria conferir Schema::hasColumn() no up() E no down()."
            );
        }
    }

    public function test_nenhuma_migration_usa_enum_constrained_foreignid_ou_indice_novo(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $semComentarios = $this->semComentarios(file_get_contents(base_path($caminho)));

            $this->assertDoesNotMatchRegularExpression('/->enum\(/', $semComentarios, "{$caminho} usa ->enum().");
            $this->assertDoesNotMatchRegularExpression('/->constrained\(/', $semComentarios, "{$caminho} usa ->constrained() (FK nova).");
            $this->assertDoesNotMatchRegularExpression('/->foreignId\(/', $semComentarios, "{$caminho} usa ->foreignId() (FK nova).");
            $this->assertDoesNotMatchRegularExpression('/->index\(/', $semComentarios, "{$caminho} cria índice novo.");
        }
    }

    public function test_toda_coluna_nova_declara_nullable_ou_default(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            // Cada "statement" de coluna é uma chamada encadeada terminando em ';'.
            $statements = array_filter(array_map('trim', explode(';', $conteudo)));

            $colunasEncontradas = 0;

            foreach ($statements as $statement) {
                if (! preg_match('/\$table->(?:string|json|unsignedInteger|boolean|timestamp)\(/', $statement)) {
                    continue;
                }

                $colunasEncontradas++;

                $this->assertTrue(
                    str_contains($statement, 'nullable()') || str_contains($statement, 'default('),
                    "Coluna sem nullable()/default() em {$caminho}: " . preg_replace('/\s+/', ' ', $statement)
                );
            }

            $this->assertGreaterThan(0, $colunasEncontradas, "{$caminho} deveria declarar colunas novas para conferir.");
        }
    }

    public function test_nenhum_literal_da_fase_165_aparece(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            foreach (['pub_rascunho_id', 'pub_grupo', 'pub_imagem_id'] as $literalProibido) {
                $this->assertStringNotContainsString(
                    $literalProibido,
                    $conteudo,
                    "{$caminho} colide com coluna da Fase 165 ({$literalProibido})."
                );
            }
        }
    }

    public function test_colunas_existem_no_banco_migrado_com_os_casts_certos(): void
    {
        $this->assertTrue(Schema::hasColumns('ml_anuncio_criativos', [
            'validacao_status', 'validacao', 'validacoes',
            'regeneracao_automatica', 'validacao_pedida_em', 'validacao_em',
        ]));

        $this->assertTrue(Schema::hasColumns('ml_anuncio_criativo_kits', [
            'validacoes', 'regeneracoes_automaticas',
        ]));
    }

    public function test_migrate_pretend_e_deterministico(): void
    {
        $saida1 = Artisan::call('migrate', ['--pretend' => true]);
        $primeiraSaida = Artisan::output();

        $saida2 = Artisan::call('migrate', ['--pretend' => true]);
        $segundaSaida = Artisan::output();

        $this->assertSame(0, $saida1);
        $this->assertSame(0, $saida2);
        $this->assertSame($primeiraSaida, $segundaSaida);
    }
}
