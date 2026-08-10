<?php

namespace Tests\Feature\Phase126;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda estática da migration `2026_08_10_120000_add_pdf_paths_to_contrato_assinaturas_table`
 * (D-03) — lê o TEXTO do arquivo, não roda banco, não usa `RefreshDatabase`. Molde replicado de
 * `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php`, com uma adaptação: esta migration
 * não cria índice nenhum (as colunas são carga, não critério de busca), então a checagem de
 * índice/chave anônimo passa por ZERO ocorrências sem falhar — nada aqui usa `assertNotEmpty`
 * para índice.
 *
 * ⚠️ Ponto crítico: o cabeçalho da migration MENCIONA `enum`, `1830` e `1059` dentro de
 * comentários que documentam por que cada armadilha não se aplica aqui. Por isso
 * `migrationSemComentarios()` remove comentários ANTES de qualquer checagem de padrão — sem
 * isso, este teste acusaria a si mesmo.
 */
class MigrationFase126ConvencoesTest extends TestCase
{
    private function caminhoMigration(): string
    {
        return database_path('migrations/2026_08_10_120000_add_pdf_paths_to_contrato_assinaturas_table.php');
    }

    /**
     * Lê o conteúdo da migration com os comentários removidos: primeiro blocos `/* ... *\/`
     * (modificador `s`, cruza linhas), depois linhas `//` (modificador `m`, uma linha por vez).
     */
    private function migrationSemComentarios(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);

        $semBlocos = preg_replace('/\/\*.*?\*\//s', '', $conteudo);
        $semLinhas = preg_replace('/\/\/.*$/m', '', $semBlocos);

        return $semLinhas;
    }

    #[Test]
    public function a_migration_da_fase_existe(): void
    {
        $this->assertFileExists(
            $this->caminhoMigration(),
            'Migration esperada da Fase 126 (D-03) não encontrada — arquivo renomeado ou movido?'
        );
    }

    #[Test]
    public function a_migration_nao_usa_coluna_de_tipo_restrito(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringNotContainsString(
            '->enum(',
            $codigo,
            'A migration usa `->enum(`. D-03/D-04 exigem STRING, nunca `enum` de banco: o CHECK do `enum` é enforçado pelo SQLite dos testes e quebra a suíte assim que surge um valor novo, exigindo migration com branch separado por driver.'
        );
    }

    #[Test]
    public function a_migration_nao_declara_fk_nullondelete_sem_coluna_nullable(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        preg_match_all('/foreign(?:Id)?\(\s*[\'"](\w+)[\'"][^;]*;/', $codigo, $matches, PREG_SET_ORDER);

        // Esta migration não declara NENHUMA FK nova — zero ocorrências é o
        // esperado e correto aqui (diferente da Fase 125, que criava a
        // tabela com FK de `company_id`).
        foreach ($matches as $match) {
            $instrucao = $match[0];
            $coluna    = $match[1];

            $this->assertStringNotContainsString(
                'nullOnDelete',
                $instrucao,
                "A migration declara FK `{$coluna}` com `nullOnDelete()` — inesperado nesta fase (D-03 não previu FK nova). Se isto foi adicionado de propósito, a coluna precisa de `->nullable()` para não estourar o erro 1830 do MariaDB."
            );
        }

        $this->assertTrue(true, 'Guarda passou: nenhuma FK nova nesta migration.');
    }

    #[Test]
    public function nenhum_indice_ou_chave_da_migration_e_anonimo(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        preg_match_all('/\$table->(unique|index|foreign)\(([^)]*)\)/', $codigo, $matches, PREG_SET_ORDER);

        // ⚠️ Diferente do molde da Fase 125: esta migration NÃO cria índice
        // nenhum (as colunas são carga, não critério de busca), então zero
        // ocorrências é o resultado esperado — nada de `assertNotEmpty`
        // aqui, ou a guarda reprovaria uma migration correta.
        foreach ($matches as $match) {
            [$chamadaCompleta, $tipo, $args] = $match;

            $semArray = preg_replace('/\[[^\]]*\]/', 'ARR', $args);
            $partes   = array_map('trim', explode(',', $semArray));

            $temNomeExplicito = count($partes) === 2 && (bool) preg_match('/^[\'"].+[\'"]$/', $partes[1]);

            $this->assertTrue(
                $temNomeExplicito,
                "A migration declara \`->{$tipo}(...)\` sem nome explícito de índice/chave (\`{$chamadaCompleta}\`). É a armadilha do erro 1059 do MariaDB — o nome autogerado pode passar de 64 caracteres, e a falha é SILENCIOSA: a tabela fica SEM o índice e a migration vira \`Pending\` sem erro visível."
            );

            $nome    = trim($partes[1] ?? '', "'\"");
            $tamanho = strlen($nome);

            $this->assertLessThanOrEqual(
                64,
                $tamanho,
                "A migration declara índice/chave \`{$nome}\` com {$tamanho} caracteres e estoura o limite de 64 do MariaDB (erro 1059)."
            );
        }

        $this->assertTrue(true, 'Guarda passou: nenhum índice/chave anônimo (ou nenhum índice declarado, como esperado nesta fase).');
    }

    #[Test]
    public function a_migration_nao_recria_a_tabela_nem_apaga_coluna_alheia(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringNotContainsString(
            'Schema::create(',
            $codigo,
            'A migration usa `Schema::create(`. É uma migration ADITIVA sobre `contrato_assinaturas`, tabela que já existe em produção (batches 110/111) — não pode recriar a tabela.'
        );

        $this->assertStringNotContainsString(
            'dropIfExists',
            $codigo,
            'A migration derruba a tabela. É uma migration ADITIVA — não pode apagar `contrato_assinaturas`.'
        );
    }

    #[Test]
    public function up_e_down_sao_guardados_por_schema_hascolumn(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $ocorrencias = substr_count($codigo, "Schema::hasColumn('contrato_assinaturas'");

        $this->assertGreaterThanOrEqual(
            4,
            $ocorrencias,
            "A migration precisa guardar tanto o up() (2 colunas) quanto o down() (2 colunas) com Schema::hasColumn — encontradas apenas {$ocorrencias} ocorrências. Sem isto, reexecutar a migration numa tabela que já tem as colunas (produção) vira incidente de deploy."
        );
    }

    #[Test]
    public function as_duas_colunas_novas_estao_declaradas(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringContainsString(
            "'pdf_path'",
            $codigo,
            'A migration não declara a coluna `pdf_path` — obrigatória pela D-03.'
        );

        $this->assertStringContainsString(
            "'pdf_assinado_path'",
            $codigo,
            'A migration não declara a coluna `pdf_assinado_path` — obrigatória pela D-03 (a Fase 129 vai preenchê-la).'
        );
    }
}
