<?php

namespace Tests\Unit\Phase134;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 134 (Plano 02) — trava o contrato executável do schema do acervo:
 *   (a) as colunas contratadas em 134-02-PLAN.md existem nas duas tabelas;
 *   (b) todo índice composto tem nome explícito e cabe no limite do MariaDB
 *       (64 chars) — gate sobre a FONTE da migration, porque o SQLite dos
 *       testes aceita nome longo que o MariaDB de produção recusa (erro
 *       1059, .planning/learnings/desempenho-bonificacao.md item 6);
 *   (c) a chave de upsert (company_id, ml_item_id) é única e não impede o
 *       mesmo ml_item_id em empresas diferentes — primeira linha de defesa
 *       do escopo por empresa (T-134-01).
 *
 * @group phase134
 */
class SchemaAcervoTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function tabela_de_itens_tem_as_colunas_contratadas(): void
    {
        $colunas = [
            'id', 'company_id', 'ml_item_id', 'title', 'category_id', 'status',
            'sub_status', 'listing_type_id', 'price', 'available_quantity',
            'sold_quantity', 'permalink', 'thumbnail', 'fotos_count',
            'has_variations', 'variations', 'catalog_listing', 'catalog_product_id',
            'shipping', 'tags', 'nota_ecf', 'nota_sinais', 'motivos', 'severidade',
            'origem', 'rascunho_id', 'publicacao_vendas_qty', 'publicacao_desconsiderado',
            'health_ml', 'visitas_30d', 'buybox_status', 'performance_score',
            'performance_level', 'performance_acoes', 'detalhe_coletado_em',
            'coletado_em', 'coleta_erro', 'created_at', 'updated_at',
        ];

        $faltando = array_values(array_filter(
            $colunas,
            fn (string $coluna): bool => ! Schema::hasColumn('ml_acervo_itens', $coluna)
        ));

        $this->assertTrue(
            Schema::hasColumns('ml_acervo_itens', $colunas),
            'ml_acervo_itens está sem as colunas: ' . implode(', ', $faltando)
        );
    }

    /** @test */
    public function serie_diaria_tem_as_colunas_contratadas(): void
    {
        $colunas = ['id', 'company_id', 'ml_item_id', 'data', 'visitas', 'sold_quantity', 'nota_ecf', 'health_ml', 'created_at'];

        $faltando = array_values(array_filter(
            $colunas,
            fn (string $coluna): bool => ! Schema::hasColumn('ml_acervo_metricas_diarias', $coluna)
        ));

        $this->assertTrue(
            Schema::hasColumns('ml_acervo_metricas_diarias', $colunas),
            'ml_acervo_metricas_diarias está sem as colunas: ' . implode(', ', $faltando)
        );

        $this->assertFalse(
            Schema::hasColumn('ml_acervo_metricas_diarias', 'updated_at'),
            'a linha da série diária é imutável — updated_at não deveria existir'
        );
    }

    /**
     * @test
     *
     * Gate sobre a FONTE da migration, não sobre o banco: o SQLite dos
     * testes cria índice com nome de 80+ caracteres sem reclamar; o MariaDB
     * de produção recusa (erro 1059) e deixa a migration como `Pending` com
     * a tabela criada e o índice faltando. Se alguém trocar
     * unique(['company_id','ml_item_id'], 'mai_company_item_unq') por
     * unique(['company_id','ml_item_id']) sem nome, este teste cai.
     */
    public function nomes_de_indice_cabem_no_limite_do_mariadb(): void
    {
        $arquivos = [
            base_path('database/migrations/2026_08_11_090000_create_ml_acervo_itens_table.php'),
            base_path('database/migrations/2026_08_11_090100_create_ml_acervo_metricas_diarias_table.php'),
        ];

        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);

            $linhas = explode("\n", file_get_contents($arquivo));
            $semComentarios = implode("\n", array_filter(
                $linhas,
                fn (string $linha): bool => ! str_starts_with(ltrim($linha), '//')
            ));

            $totalChamadas = preg_match_all('/->(index|unique)\(/', $semComentarios);
            $this->assertGreaterThan(0, $totalChamadas, "{$arquivo} não tem nenhuma chamada ->index(/->unique(");

            // Cada chamada precisa ter um segundo argumento string entre
            // aspas — o nome explícito do índice.
            preg_match_all(
                '/->(?:index|unique)\(\s*\[[^\]]*\]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                $semComentarios,
                $comNome
            );

            $this->assertCount(
                $totalChamadas,
                $comNome[1],
                "{$arquivo} tem chamada ->index(/->unique( sem segundo argumento nomeado — "
                . 'nome de índice automático estoura o limite de 64 chars do MariaDB'
            );

            foreach ($comNome[1] as $nome) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($nome),
                    "índice '{$nome}' em {$arquivo} tem " . strlen($nome) . ' caracteres — MariaDB recusa (erro 1059)'
                );
            }
        }
    }

    /**
     * @test
     *
     * Chave de upsert do D-05/D-07 e primeira linha de defesa do escopo por
     * empresa (T-134-01): o mesmo (company_id, ml_item_id) não pode existir
     * duas vezes, mas o mesmo ml_item_id precisa coexistir em empresas
     * diferentes sem colisão.
     */
    public function upsert_por_company_e_item_e_unico(): void
    {
        $companyA = \App\Models\Company::factory()->create();
        $companyB = \App\Models\Company::factory()->create();

        DB::table('ml_acervo_itens')->insert([
            'company_id' => $companyA->id,
            'ml_item_id' => 'MLB1111111111',
            'status'     => 'active',
            'origem'     => 'legado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mesmo par (company_id, ml_item_id) — precisa violar a unique.
        $violou = false;
        try {
            DB::table('ml_acervo_itens')->insert([
                'company_id' => $companyA->id,
                'ml_item_id' => 'MLB1111111111',
                'status'     => 'paused',
                'origem'     => 'legado',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $violou = true;
        }

        $this->assertTrue($violou, 'duplicar (company_id, ml_item_id) deveria violar mai_company_item_unq');

        // Mesmo ml_item_id, empresa diferente — não pode colidir (T-134-01).
        DB::table('ml_acervo_itens')->insert([
            'company_id' => $companyB->id,
            'ml_item_id' => 'MLB1111111111',
            'status'     => 'active',
            'origem'     => 'legado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            2,
            DB::table('ml_acervo_itens')->where('ml_item_id', 'MLB1111111111')->count(),
            'o mesmo ml_item_id precisa existir para duas empresas distintas'
        );
    }
}
