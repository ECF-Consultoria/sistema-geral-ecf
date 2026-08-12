<?php

namespace Tests\Feature\Phase127;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda estática das convenções de schema desta fase, lendo o TEXTO da
 * migration da D-06 — não roda banco, não usa `RefreshDatabase`. Cópia
 * adaptada de `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php`
 * (mesma disciplina, mesmos helpers, mesmas armadilhas de MariaDB).
 *
 * ⚠️ Ponto crítico: a migration desta fase MENCIONA `enum`, `1553` e `1059`
 * dentro de comentários que documentam justamente essas armadilhas. Por
 * isso o helper `migrationSemComentarios()` remove comentários ANTES de
 * qualquer checagem de padrão — sem isso, o teste acusaria a si mesmo e a
 * guarda seria auto-invalidante.
 */
class MigrationsFase127ConvencoesTest extends TestCase
{
    private function caminhoMigration(): string
    {
        return database_path('migrations/2026_08_12_100000_add_servico_e_prazo_to_contrato_assinaturas_table.php');
    }

    /**
     * Lê o conteúdo da migration com os comentários removidos: primeiro
     * blocos `/* ... *\/` (modificador `s`, cruza linhas), depois linhas
     * `//` (modificador `m`, uma linha por vez).
     */
    private function migrationSemComentarios(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);
        $semLinhas = preg_replace('/\/\/.*$/m', '', $semBlocos);

        return $semLinhas;
    }

    /**
     * Confirma que a coluna `$coluna` está declarada com `->nullable()` na
     * MESMA instrução que a cria.
     */
    private function colunaDeclaradaNullable(string $codigoSemComentarios, string $coluna): bool
    {
        $padrao = '/\$table->\w+\(\s*[\'"]'.preg_quote($coluna, '/').'[\'"][^;]*;/';

        if (! preg_match($padrao, $codigoSemComentarios, $match)) {
            return false;
        }

        return str_contains($match[0], '->nullable()');
    }

    #[Test]
    public function a_migration_desta_fase_existe(): void
    {
        $this->assertFileExists(
            $this->caminhoMigration(),
            'Migration esperada da Fase 127 (D-06) não encontrada — arquivo renomeado ou movido?'
        );
    }

    #[Test]
    public function nenhuma_migration_da_fase_usa_coluna_de_tipo_restrito(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringNotContainsString(
            '->enum(',
            $codigo,
            'Migration usa `->enum(`. D-04 exige STRING + constantes públicas no model: o CHECK do `enum` é enforçado pelo SQLite dos testes e quebra a suíte assim que surge um valor novo.'
        );
    }

    #[Test]
    public function coluna_servico_id_nova_e_nullable_por_causa_do_sqlite(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertTrue(
            $this->colunaDeclaradaNullable($codigo, 'servico_id'),
            'A coluna `servico_id` precisa ser `->nullable()` — o SQLite dos testes recusa `ADD COLUMN ... NOT NULL` sem default, mesmo em tabela vazia. A obrigatoriedade real fica no hook do model (Task 3).'
        );
    }

    #[Test]
    public function todo_nome_de_indice_literal_cabe_em_64_caracteres(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        preg_match_all("/['\"]([a-zA-Z0-9_]*ca_[a-zA-Z0-9_]+)['\"]/", $codigo, $matches);

        $nomes = array_unique($matches[1]);

        $this->assertNotEmpty($nomes, 'Nenhum nome de índice literal (prefixo `ca_`) encontrado na migration — inesperado para esta fase.');

        foreach ($nomes as $nome) {
            $this->assertLessThanOrEqual(
                64,
                strlen($nome),
                "Índice/chave `{$nome}` tem ".strlen($nome)." caracteres e estoura o limite de 64 do MariaDB (erro 1059)."
            );
        }
    }

    #[Test]
    public function indice_composto_novo_e_criado_antes_de_dropar_o_indice_antigo(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $posicaoIndiceNovo = strpos($codigo, "unique(['company_id_em_andamento', 'servico_id_em_andamento'], 'ca_empresa_servico_andamento_uniq')");
        $posicaoDropAntigo = strpos($codigo, "dropUnique('ca_company_andamento_uniq')");

        $this->assertNotFalse($posicaoIndiceNovo, "A migration não declara o índice composto `ca_empresa_servico_andamento_uniq` na forma esperada.");
        $this->assertNotFalse($posicaoDropAntigo, "A migration não declara o `dropUnique('ca_company_andamento_uniq')` esperado.");

        $this->assertLessThan(
            $posicaoDropAntigo,
            $posicaoIndiceNovo,
            'O índice novo `ca_empresa_servico_andamento_uniq` precisa ser criado ANTES de dropar `ca_company_andamento_uniq` — dropar índice usado por FK falha com erro 1553 no MariaDB, e o SQLite dos testes não pega essa ordem errada.'
        );
    }
}
