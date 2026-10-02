<?php

namespace Tests\Unit\Phase161;

use Tests\TestCase;

/**
 * Guarda de CONTEÚDO das duas migrations do kit (Fase 161) contra os dois
 * erros de MariaDB que o SQLite dos testes não pega e que já quebraram
 * deploy neste projeto:
 *
 *   - erro 1059: nome de índice/constraint acima de 64 caracteres — o SQLite
 *     aceita e só quebra no deploy, deixando a tabela criada SEM o índice e
 *     a migration como Pending (`project_mariadb_nome_indice_64`).
 *   - erro 1830: `nullOnDelete()` numa coluna sem `nullable()` explícito.
 *
 * Lê o ARQUIVO (não executa a migration) — é isto que torna os dois erros
 * prováveis por teste mesmo correndo em SQLite.
 */
class KitMigrationGuardaTest extends TestCase
{
    private const MIGRATIONS = [
        'database/migrations/2026_10_03_090000_create_ml_anuncio_criativo_kits_table.php',
        'database/migrations/2026_10_03_090100_add_kit_columns_to_ml_anuncio_criativos_table.php',
    ];

    public function test_nenhuma_migration_do_kit_usa_enum(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            $this->assertDoesNotMatchRegularExpression(
                '/->enum\(/',
                $conteudo,
                "{$caminho} usa ->enum(), que exige branch de SQLite e já quebrou deploy neste projeto."
            );
        }
    }

    public function test_nomes_de_indice_e_constraint_tem_no_maximo_64_caracteres(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            // Cada chamada unique()/index()/dropIndex() — o nome do
            // índice/constraint é sempre o ÚLTIMO argumento string (quando
            // há mais de um, o(s) anterior(es) é/são coluna(s)).
            preg_match_all(
                '/->(?:unique|index|dropIndex)\(((?:[^()]|\([^()]*\))*)\)/',
                $conteudo,
                $chamadas
            );

            $nomesEncontrados = [];

            foreach ($chamadas[1] as $argumentos) {
                preg_match_all("/'([a-zA-Z0-9_]+)'/", $argumentos, $strings);

                if ($strings[1] === []) {
                    continue;
                }

                $nomesEncontrados[] = end($strings[1]);
            }

            foreach ($nomesEncontrados as $nome) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($nome),
                    "Nome '{$nome}' em {$caminho} passa de 64 caracteres (erro 1059 do MariaDB)."
                );
            }

            $this->assertNotEmpty(
                $nomesEncontrados,
                "{$caminho} deveria declarar ao menos um índice nomeado — nada encontrado para conferir."
            );
        }
    }

    public function test_toda_coluna_com_nullondelete_tem_nullable_explicito(): void
    {
        foreach (self::MIGRATIONS as $caminho) {
            $conteudo = file_get_contents(base_path($caminho));

            // Cada "statement" de coluna é uma chamada encadeada que termina
            // em ';' — dividir por isso isola a declaração completa mesmo
            // quando o encadeamento quebra em múltiplas linhas.
            $statements = explode(';', $conteudo);

            $encontrouNullOnDelete = false;

            foreach ($statements as $statement) {
                if (! str_contains($statement, 'nullOnDelete()')) {
                    continue;
                }

                $encontrouNullOnDelete = true;

                $this->assertStringContainsString(
                    'nullable()',
                    $statement,
                    "Statement com nullOnDelete() sem nullable() explícito em {$caminho} (erro 1830 do MariaDB): "
                        . trim(preg_replace('/\s+/', ' ', $statement))
                );
            }

            $this->assertTrue(
                $encontrouNullOnDelete,
                "{$caminho} deveria declarar ao menos uma FK com nullOnDelete() — nada encontrado para conferir."
            );
        }
    }
}
