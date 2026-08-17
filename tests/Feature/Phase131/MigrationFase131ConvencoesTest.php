<?php

namespace Tests\Feature\Phase131;

use App\Models\ContratoAssinatura;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda estática da migration `2026_08_16_100000_add_cancelamento_solicitado_to_contrato_assinaturas_table`
 * (D-13 do 131-CONTEXT.md, CLICK-10) — lê o TEXTO do arquivo, não roda banco, não usa
 * `RefreshDatabase`. Molde replicado de `tests/Feature/Phase126/MigrationFase126ConvencoesTest.php`,
 * com uma diferença relevante: ESTA migration declara uma FK nomeada à mão
 * (`ca_cancel_solic_user_fk`), então a guarda de "FK sem nullable" e a de "índice/chave anônimo"
 * passam pelo caminho POSITIVO (a FK existe e precisa estar correta), não pelo caminho de
 * "zero ocorrências esperadas" do molde da Fase 126.
 *
 * ⚠️ Ponto crítico: o cabeçalho da migration MENCIONA `enum`, `1830` e `1059` dentro de
 * comentários que documentam por que cada armadilha foi evitada. Por isso
 * `migrationSemComentarios()` remove comentários ANTES de qualquer checagem de padrão — sem
 * isso, este teste acusaria a si mesmo.
 */
class MigrationFase131ConvencoesTest extends TestCase
{
    private function caminhoMigration(): string
    {
        return database_path('migrations/2026_08_16_100000_add_cancelamento_solicitado_to_contrato_assinaturas_table.php');
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
            'Migration esperada da Fase 131 (D-13) não encontrada — arquivo renomeado ou movido?'
        );
    }

    #[Test]
    public function a_migration_nao_usa_coluna_de_tipo_restrito(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringNotContainsString(
            '->enum(',
            $codigo,
            'A migration usa `->enum(`. D-13 exige TEXTO LIVRE para `cancelamento_motivo` (o UI-SPEC confirma que não há lista fechada), e o CHECK do `enum` é enforçado pelo SQLite dos testes — quebraria a suíte assim que surgisse um valor novo.'
        );
    }

    #[Test]
    public function a_fk_de_cancelamento_com_nullondelete_tem_nullable_na_mesma_declaracao(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        preg_match_all('/foreignId\(\s*[\'"](\w+)[\'"]\s*\)[^;]*;/s', $codigo, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty(
            $matches,
            'A migration deveria declarar a FK `cancelamento_solicitado_por_user_id` via `foreignId()`, mas nenhuma declaração foi encontrada.'
        );

        foreach ($matches as $match) {
            $instrucao = $match[0];
            $coluna    = $match[1];

            if (! str_contains($instrucao, 'nullOnDelete')) {
                continue;
            }

            $this->assertStringContainsString(
                '->nullable()',
                $instrucao,
                "A migration declara FK \`{$coluna}\` com \`nullOnDelete()\` mas SEM \`->nullable()\` na mesma declaração — estoura o erro 1830 do MariaDB em produção (o SQLite dos testes não pega isso)."
            );
        }
    }

    #[Test]
    public function o_nome_da_fk_e_explicito_e_dentro_do_limite_de_64_caracteres(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $this->assertStringContainsString(
            "'ca_cancel_solic_user_fk'",
            $codigo,
            'A FK de `cancelamento_solicitado_por_user_id` precisa de nome explícito e curto. O nome que o Laravel geraria automaticamente (`contrato_assinaturas_cancelamento_solicitado_por_user_id_foreign`) estoura o limite de 64 caracteres do MariaDB (erro 1059) — falha SILENCIOSA que deixa a migration `Pending` sem erro visível.'
        );

        $this->assertLessThanOrEqual(64, strlen('ca_cancel_solic_user_fk'));
    }

    #[Test]
    public function nenhum_indice_ou_chave_anonimo_alem_da_fk_nomeada(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        preg_match_all('/\$table->(unique|index)\(([^)]*)\)/', $codigo, $matches, PREG_SET_ORDER);

        // Esta migration não declara `unique`/`index` novos — só a FK
        // nomeada (checada no teste anterior). Zero ocorrências aqui é o
        // esperado e correto.
        $this->assertCount(
            0,
            $matches,
            'A migration declara `unique`/`index` não previsto pela D-13 — se isto foi adicionado de propósito, nomear explicitamente e confirmar limite de 64 caracteres.'
        );
    }

    #[Test]
    public function up_e_down_sao_guardados_por_schema_hascolumn(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        $ocorrencias = substr_count($codigo, "Schema::hasColumn('contrato_assinaturas'");

        $this->assertGreaterThanOrEqual(
            6,
            $ocorrencias,
            "A migration precisa guardar tanto o up() (3 colunas) quanto o down() (3 colunas, incluindo a FK) com Schema::hasColumn — encontradas apenas {$ocorrencias} ocorrências. Sem isto, reexecutar a migration numa tabela que já tem as colunas (produção) vira incidente de deploy."
        );
    }

    #[Test]
    public function as_tres_colunas_novas_estao_declaradas(): void
    {
        $codigo = $this->migrationSemComentarios($this->caminhoMigration());

        foreach (['cancelamento_motivo', 'cancelamento_solicitado_por_user_id', 'cancelamento_solicitado_em'] as $coluna) {
            $this->assertStringContainsString(
                "'{$coluna}'",
                $codigo,
                "A migration não declara a coluna \`{$coluna}\` — obrigatória pela D-13."
            );
        }
    }

    #[Test]
    public function as_tres_colunas_estao_no_fillable_do_model(): void
    {
        $model = new ContratoAssinatura();

        foreach (['cancelamento_motivo', 'cancelamento_solicitado_por_user_id', 'cancelamento_solicitado_em'] as $coluna) {
            $this->assertContains(
                $coluna,
                $model->getFillable(),
                "A coluna \`{$coluna}\` não está em \$fillable de ContratoAssinatura — o mass assignment falharia EM SILÊNCIO."
            );
        }
    }

    #[Test]
    public function cancelamento_solicitado_em_tem_cast_datetime(): void
    {
        $model = new ContratoAssinatura();

        $this->assertSame(
            'datetime',
            $model->getCasts()['cancelamento_solicitado_em'] ?? null,
            '`cancelamento_solicitado_em` precisa de cast `datetime` — sem isto, comparações de data no backend tratariam a coluna como string crua.'
        );
    }

    #[Test]
    public function status_todos_continua_com_exatamente_7_elementos(): void
    {
        $this->assertCount(
            7,
            ContratoAssinatura::STATUS_TODOS,
            '"Cancelamento solicitado" (D-13) é DERIVADO da presença de `cancelamento_solicitado_em`, NUNCA um 8º valor de `status`. Se este teste falhar, alguém acrescentou um estado novo a STATUS_TODOS e quebrou o resumo de 7 contagens da D-04.'
        );
    }
}
