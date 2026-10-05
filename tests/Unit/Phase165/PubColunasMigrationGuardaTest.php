<?php

namespace Tests\Unit\Phase165;

use Tests\TestCase;

/**
 * Guarda de CONTEÚDO da migration aditiva da Fase 165 (`pub_rascunho_id`/
 * `pub_grupo`/`pub_imagem_id` em `ml_anuncio_criativos` e
 * `ml_anuncio_criativo_kits`) contra os erros de MariaDB que o SQLite dos
 * testes não pega e que já quebraram deploy neste projeto — molde literal
 * de `tests/Unit/Phase161/KitMigrationGuardaTest.php`.
 *
 *   - erro 1059: nome de índice/constraint acima de 64 caracteres.
 *   - erro 1830: `nullOnDelete()` numa coluna sem `nullable()` explícito.
 *   - erro 1553: no `down()`, dropar o índice ANTES da FK que o usa.
 *
 * Lê o ARQUIVO (não executa a migration) — por isso roda em SQLite e ainda
 * assim prova os três erros de MariaDB.
 */
class PubColunasMigrationGuardaTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_04_120000_add_pub_columns_to_ml_anuncio_criativos_and_kits.php';

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

    public function test_nomes_de_indice_tem_no_maximo_64_caracteres(): void
    {
        $conteudo = $this->conteudo();

        preg_match_all(
            '/->(?:index|dropIndex)\(((?:[^()]|\([^()]*\))*)\)/',
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
                "Nome '{$nome}' em " . self::MIGRATION . ' passa de 64 caracteres (erro 1059 do MariaDB).'
            );
        }

        $this->assertNotEmpty(
            $nomesEncontrados,
            self::MIGRATION . ' deveria declarar ao menos um índice nomeado — nada encontrado para conferir.'
        );
    }

    public function test_os_tres_nomes_padrao_de_fk_tem_no_maximo_64_caracteres(): void
    {
        // Nomes computados pelo próprio Laravel para `foreignId('coluna')`
        // sem `->name(...)` explícito: "{tabela}_{coluna}_foreign".
        $combinacoes = [
            ['ml_anuncio_criativo_kits', 'pub_rascunho_id'],
            ['ml_anuncio_criativos', 'pub_rascunho_id'],
            ['ml_anuncio_criativos', 'pub_imagem_id'],
        ];

        foreach ($combinacoes as [$tabela, $coluna]) {
            $nomeFk = "{$tabela}_{$coluna}_foreign";

            $this->assertLessThanOrEqual(
                64,
                strlen($nomeFk),
                "Nome de FK padrão '{$nomeFk}' passa de 64 caracteres (erro 1059 do MariaDB)."
            );
        }
    }

    public function test_toda_coluna_com_nullondelete_tem_nullable_explicito(): void
    {
        $conteudo = $this->conteudo();

        // Cada "statement" de coluna é uma chamada encadeada que termina em
        // ';' — dividir por isso isola a declaração completa mesmo quando o
        // encadeamento quebra em múltiplas linhas.
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
                'Statement com nullOnDelete() sem nullable() explícito em ' . self::MIGRATION . ' (erro 1830 do MariaDB): '
                    . trim(preg_replace('/\s+/', ' ', $statement))
            );
        }

        $this->assertTrue(
            $encontrouNullOnDelete,
            self::MIGRATION . ' deveria declarar ao menos uma FK com nullOnDelete() — nada encontrado para conferir.'
        );
    }

    public function test_pub_grupo_nunca_entra_num_index(): void
    {
        $conteudo = $this->conteudo();

        preg_match_all('/->(?:index|unique)\(((?:[^()]|\([^()]*\))*)\)/', $conteudo, $chamadas);

        foreach ($chamadas[1] as $argumentos) {
            $this->assertStringNotContainsString(
                'pub_grupo',
                $argumentos,
                'pub_grupo não deveria entrar em nenhum índice — string(600) estoura a chave do InnoDB em utf8mb4.'
            );
        }
    }

    public function test_up_contem_exatamente_cinco_schema_hascolumn(): void
    {
        $conteudo = $this->conteudo();

        preg_match('/public function up\(\).*?public function down\(\)/s', $conteudo, $corpoUp);

        $this->assertNotEmpty($corpoUp, 'Não encontrei o corpo do up() na migration.');

        $ocorrencias = preg_match_all('/Schema::hasColumn\(/', $corpoUp[0]);

        $this->assertSame(
            5,
            $ocorrencias,
            'O up() deveria ter exatamente 5 blocos guardados por Schema::hasColumn (um por coluna aditiva).'
        );
    }

    public function test_down_derruba_fk_antes_do_indice_para_cada_coluna_com_fk(): void
    {
        $conteudo = $this->conteudo();

        preg_match('/public function down\(\).*$/s', $conteudo, $corpoDown);
        $this->assertNotEmpty($corpoDown, 'Não encontrei o corpo do down() na migration.');
        $down = $corpoDown[0];

        foreach (['pub_rascunho_id', 'pub_imagem_id'] as $coluna) {
            // Cada coluna com FK aparece em dois blocos (criativos + kits,
            // exceto pub_imagem_id que só existe em criativos) — confere
            // TODO par dropForeign/dropIndex na ordem certa.
            preg_match_all(
                "/dropForeign\(\['{$coluna}'\]\).*?dropIndex\('[a-zA-Z0-9_]+'\)/s",
                $down,
                $paresNaOrdemCerta
            );

            $totalDropForeign = preg_match_all("/dropForeign\(\['{$coluna}'\]\)/", $down);

            $this->assertSame(
                $totalDropForeign,
                count($paresNaOrdemCerta[0]),
                "No down(), dropForeign(['{$coluna}']) precisa vir ANTES do dropIndex correspondente (erro 1553 do MariaDB)."
            );

            $this->assertGreaterThan(
                0,
                $totalDropForeign,
                "Esperava ao menos um dropForeign(['{$coluna}']) no down()."
            );
        }
    }
}
