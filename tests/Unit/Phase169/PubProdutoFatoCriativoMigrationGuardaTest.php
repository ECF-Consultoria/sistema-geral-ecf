<?php

namespace Tests\Unit\Phase169;

use Tests\TestCase;

/**
 * Guarda de CONTEÚDO da migration de `pub_produto_fatos_criativo` (Fase 169,
 * Plano 01, Task 1) contra os erros de MariaDB que o SQLite dos testes não
 * pega — molde de `tests/Unit/Phase165/PubColunasMigrationGuardaTest.php`.
 *
 * Lê o ARQUIVO (não executa a migration): roda em SQLite e ainda assim prova
 * a ausência de `->enum(`/`->change(`/`cascade` e o tamanho dos nomes de FK.
 */
class PubProdutoFatoCriativoMigrationGuardaTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_07_150000_create_pub_produto_fatos_criativo_table.php';

    private function conteudo(): string
    {
        return file_get_contents(base_path(self::MIGRATION));
    }

    public function test_a_migration_nao_usa_enum(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/->enum\(/',
            $this->conteudo(),
            self::MIGRATION . ' usa ->enum(), que exige branch de SQLite e já quebrou deploy neste projeto.'
        );
    }

    public function test_a_migration_nao_usa_change(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/->change\(/',
            $this->conteudo(),
            self::MIGRATION . ' usa ->change(), fora da convenção aditiva deste módulo.'
        );
    }

    /**
     * `cascade` NUNCA fora de comentário — a palavra aparece no docblock
     * (explicando por que NÃO é cascade), então a verificação remove as
     * linhas de comentário (`*`, `//`) antes de procurar.
     */
    public function test_a_migration_nao_usa_cascade_fora_de_comentario(): void
    {
        $linhasDeCodigo = collect(explode("\n", $this->conteudo()))
            ->reject(fn ($linha) => preg_match('/^\s*(\*|\/\/|\/\*)/', $linha) === 1)
            ->implode("\n");

        $this->assertDoesNotMatchRegularExpression(
            '/cascade/i',
            $linhasDeCodigo,
            self::MIGRATION . ' usa cascade fora de comentário — a regra T-169-02 é nullOnDelete() sempre.'
        );
    }

    public function test_os_dois_nomes_de_fk_tem_no_maximo_64_caracteres(): void
    {
        foreach (['pubfc_produto_fk', 'pubfc_user_fk'] as $nomeFk) {
            $this->assertLessThanOrEqual(
                64,
                strlen($nomeFk),
                "Nome de FK '{$nomeFk}' passa de 64 caracteres (erro 1059 do MariaDB)."
            );
        }

        $this->assertStringContainsString('pubfc_produto_fk', $this->conteudo());
        $this->assertStringContainsString('pubfc_user_fk', $this->conteudo());
    }

    public function test_toda_coluna_com_nullondelete_tem_nullable_explicito(): void
    {
        // Remove linhas de comentário/docblock ANTES de dividir por ';' — o
        // docblock desta migration menciona "nullOnDelete()" em prosa, e sem
        // isso o primeiro "statement" (guarda `Schema::hasTable` + `return;`)
        // levaria o docblock inteiro junto, fazendo o assert passar por
        // coincidência (ou falhar por falta de "nullable()" na prosa).
        $codigoSemComentarios = collect(explode("\n", $this->conteudo()))
            ->reject(fn ($linha) => preg_match('/^\s*(\*|\/\/|\/\*)/', $linha) === 1)
            ->implode("\n");

        $statements = explode(';', $codigoSemComentarios);

        $encontrouNullOnDelete = false;

        foreach ($statements as $statement) {
            if (! str_contains($statement, 'nullOnDelete()')) {
                continue;
            }

            $encontrouNullOnDelete = true;

            $this->assertStringContainsString(
                'nullable()',
                $statement,
                'Statement com nullOnDelete() sem nullable() explícito em ' . self::MIGRATION . ' (erro 1830 do MariaDB): '
                    . trim(preg_replace('/\s+/', ' ', $statement))
            );
        }

        $this->assertTrue(
            $encontrouNullOnDelete,
            self::MIGRATION . ' deveria declarar ao menos uma FK com nullOnDelete() — nada encontrado para conferir.'
        );
    }
}
