<?php

namespace Tests\Feature\Phase141;

use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 01 — Tarefa 1: coluna `servicos.usa_tabela_progressiva`.
 *
 * Cobre o schema (existe, nasce boolean false), o backfill (liga só quem já
 * tem faixa cadastrada) e a idempotência da migration (rodar de novo não
 * quebra nem duplica).
 */
class Phase141ServicoTabelaSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function coluna_existe_e_e_booleana(): void
    {
        $this->assertTrue(Schema::hasColumn('servicos', 'usa_tabela_progressiva'));
    }

    #[Test]
    public function servico_sem_faixa_cadastrada_nasce_false(): void
    {
        $mentoria = Servico::create([
            'nome'          => 'Mentoria',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $this->assertFalse($mentoria->fresh()->usa_tabela_progressiva);
    }

    #[Test]
    public function servico_com_faixa_ja_cadastrada_e_ligado_pelo_backfill_ao_rodar_a_migration(): void
    {
        // O RefreshDatabase já roda todas as migrations (incluindo a desta
        // fase) antes do teste — para provar o backfill isoladamente, criamos
        // o serviço + faixa e RE-rodamos só a migration desta fase.
        $gestao = Servico::create([
            'nome'          => 'Gestão',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id'       => $gestao->id,
            'ordem'            => 1,
            'limite_superior'  => 100_000,
            'valor'            => 3_000,
            'valor_e_piso'     => false,
        ]);

        // Desliga a flag (o backfill do teste anterior/create padrão já é
        // false) e re-executa a migration desta fase para provar o UPDATE.
        $gestao->forceFill(['usa_tabela_progressiva' => false])->save();

        $migration = database_path('migrations/2026_09_09_150001_add_usa_tabela_progressiva_to_servicos_table.php');
        (require $migration)->up();

        $this->assertTrue($gestao->fresh()->usa_tabela_progressiva);
    }

    #[Test]
    public function rodar_a_migration_duas_vezes_nao_quebra_nem_duplica(): void
    {
        $migration = database_path('migrations/2026_09_09_150001_add_usa_tabela_progressiva_to_servicos_table.php');

        // Rodar de novo (a coluna já existe, criada pelo RefreshDatabase) não
        // pode lançar exceção — é o guard Schema::hasColumn em ação.
        (require $migration)->up();
        (require $migration)->up();

        $this->assertTrue(Schema::hasColumn('servicos', 'usa_tabela_progressiva'));

        $colunas = collect(Schema::getColumns('servicos'))->pluck('name');
        $this->assertSame(1, $colunas->filter(fn ($nome) => $nome === 'usa_tabela_progressiva')->count());
    }

    #[Test]
    public function servico_aceita_a_coluna_em_mass_assignment_e_devolve_boolean_nativo(): void
    {
        $servico = Servico::create([
            'nome'                    => 'Brigada',
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva'  => true,
        ]);

        $this->assertTrue($servico->fresh()->usa_tabela_progressiva);
        $this->assertIsBool($servico->fresh()->usa_tabela_progressiva);
    }
}
