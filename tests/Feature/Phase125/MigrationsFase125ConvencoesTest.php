<?php

namespace Tests\Feature\Phase125;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda estática das três cicatrizes de schema deste projeto, lendo o
 * TEXTO das duas migrations da Fase 125 — não roda banco, não usa
 * `RefreshDatabase`. As armadilhas (`enum` + CHECK do SQLite, erro 1830 do
 * `nullOnDelete` sem `nullable()`, erro 1059 de nome de índice > 64 chars)
 * passam verdes na suíte e só quebram no MariaDB de produção; este teste
 * existe para que uma regressão futura quebre AQUI, antes do deploy.
 *
 * ⚠️ Ponto crítico: as duas migrations desta fase MENCIONAM `enum`, `1830` e
 * `1059` dentro de comentários que documentam justamente essas armadilhas.
 * Por isso o helper `migrationSemComentarios()` remove comentários ANTES de
 * qualquer checagem de padrão — sem isso, o teste 1 acusaria a si mesmo e a
 * guarda seria auto-invalidante (inútil por construção).
 */
class MigrationsFase125ConvencoesTest extends TestCase
{
    /**
     * Os dois caminhos de migration desta fase. Único ponto a atualizar se
     * um dos arquivos for renomeado.
     *
     * @return array<int, string>
     */
    private function caminhosMigrations(): array
    {
        return [
            database_path('migrations/2026_08_10_100000_create_contrato_assinaturas_table.php'),
            database_path('migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php'),
        ];
    }

    /**
     * Lê o conteúdo de uma migration com os comentários removidos: primeiro
     * blocos `/* ... *\/` (modificador `s`, cruza linhas), depois linhas
     * `//` (modificador `m`, uma linha por vez). Sem este passo as próprias
     * migrations desta fase — que documentam as armadilhas de MariaDB nos
     * comentários — fariam a guarda acusar a si mesma.
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
     * MESMA instrução que a cria (ex.: `$table->unsignedBigInteger('user_id')
     * ->nullable();`). Usado pelo teste da armadilha 1830.
     */
    private function colunaDeclaradaNullable(string $codigoSemComentarios, string $coluna): bool
    {
        $padrao = '/\$table->\w+\(\s*[\'"]' . preg_quote($coluna, '/') . '[\'"][^;]*;/';

        if (! preg_match($padrao, $codigoSemComentarios, $match)) {
            return false;
        }

        return str_contains($match[0], '->nullable()');
    }

    #[Test]
    public function nenhuma_migration_da_fase_usa_coluna_de_tipo_restrito(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $codigo = $this->migrationSemComentarios($caminho);

            $this->assertStringNotContainsString(
                '->enum(',
                $codigo,
                "Migration {$caminho} usa `->enum(`. D-04 exige STRING + constantes públicas no model: o CHECK do `enum` é enforçado pelo SQLite dos testes e quebra a suíte assim que surge um valor novo, exigindo migration com branch separado por driver."
            );
        }
    }

    #[Test]
    public function toda_fk_null_on_delete_tem_coluna_nullable(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $codigo = $this->migrationSemComentarios($caminho);

            preg_match_all('/foreign(?:Id)?\(\s*[\'"](\w+)[\'"][^;]*;/', $codigo, $matches, PREG_SET_ORDER);

            $this->assertNotEmpty($matches, "Migration {$caminho} não declarou nenhuma FK — inesperado, releia o arquivo.");

            foreach ($matches as $match) {
                $instrucao = $match[0];
                $coluna    = $match[1];

                if (! str_contains($instrucao, 'nullOnDelete')) {
                    continue;
                }

                $this->assertTrue(
                    $this->colunaDeclaradaNullable($codigo, $coluna),
                    "Migration {$caminho}: a FK `{$coluna}` usa `nullOnDelete()` mas a coluna não está declarada com `->nullable()`. É a armadilha do erro 1830 do MariaDB — o SQLite dos testes não pega, só o deploy real quebra."
                );
            }
        }
    }

    #[Test]
    public function nenhum_indice_ou_chave_e_anonimo(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $codigo = $this->migrationSemComentarios($caminho);

            preg_match_all('/\$table->(unique|index|foreign)\(([^)]*)\)/', $codigo, $matches, PREG_SET_ORDER);

            $this->assertNotEmpty($matches, "Migration {$caminho} não declarou nenhum unique/index/foreign — inesperado para esta fase.");

            foreach ($matches as $match) {
                [$chamadaCompleta, $tipo, $args] = $match;

                // Neutraliza arrays (ex.: ['company_id', 'status']) para que
                // a vírgula interna não confunda a contagem de argumentos.
                $semArray = preg_replace('/\[[^\]]*\]/', 'ARR', $args);
                $partes   = array_map('trim', explode(',', $semArray));

                $temNomeExplicito = count($partes) === 2 && (bool) preg_match('/^[\'"].+[\'"]$/', $partes[1]);

                $this->assertTrue(
                    $temNomeExplicito,
                    "Migration {$caminho}: `->{$tipo}(...)` sem nome explícito de índice/chave (`{$chamadaCompleta}`). É a armadilha do erro 1059 do MariaDB — o nome autogerado pode passar de 64 caracteres, e a falha é SILENCIOSA: a tabela nasce SEM o índice e a migration fica `Pending` sem erro visível."
                );
            }
        }
    }

    #[Test]
    public function todo_nome_de_indice_cabe_em_64_caracteres(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $codigo = $this->migrationSemComentarios($caminho);

            preg_match_all('/\$table->(unique|index|foreign)\(([^)]*)\)/', $codigo, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $args = $match[2];

                $semArray = preg_replace('/\[[^\]]*\]/', 'ARR', $args);
                $partes   = array_map('trim', explode(',', $semArray));

                if (count($partes) !== 2) {
                    // Já falhou no teste anterior (nome ausente). Aqui só
                    // medimos o que de fato existe.
                    continue;
                }

                $nome     = trim($partes[1], "'\"");
                $tamanho  = strlen($nome);

                $this->assertLessThanOrEqual(
                    64,
                    $tamanho,
                    "Migration {$caminho}: nome de índice/chave `{$nome}` tem {$tamanho} caracteres e estoura o limite de 64 do MariaDB (erro 1059). Margem confortável esperada."
                );
            }
        }
    }

    #[Test]
    public function nenhuma_migration_da_fase_cria_coluna_de_prazo(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $codigo = $this->migrationSemComentarios($caminho);

            $this->assertStringNotContainsString(
                'expira_em',
                $codigo,
                "Migration {$caminho} cria a coluna `expira_em`. Prazo de assinatura é escopo da Fase 127 (DADOS-06, decisão D-03) — não desta fase."
            );

            $this->assertStringNotContainsString(
                'deadline',
                $codigo,
                "Migration {$caminho} referencia `deadline` fora de comentário. Prazo de assinatura é escopo da Fase 127 (DADOS-06, decisão D-03) — não desta fase."
            );
        }
    }

    #[Test]
    public function as_duas_migrations_da_fase_existem(): void
    {
        foreach ($this->caminhosMigrations() as $caminho) {
            $this->assertFileExists(
                $caminho,
                "Migration esperada da Fase 125 não encontrada em {$caminho} — arquivo renomeado ou movido?"
            );
        }
    }
}
