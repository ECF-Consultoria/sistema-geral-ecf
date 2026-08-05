<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260805-eqk — remove as 5 colunas comerciais mortas de `companies`.
 *
 * Motivo: as properties correspondentes (`nicho`, `dor`, `vende_ml`,
 * `faturamento_mensal`) NÃO existem na conta HubSpot da ECF — o webhook pedia e
 * o HubSpot ignorava, então nunca chegaram a ser preenchidas pelo handoff. O
 * preenchimento residual em produção (172 empresas) era: nicho=3 (1 "teste"),
 * dor=1 ("teste"), vende_ml=5, faturamento_mensal=2 (um 0.00) e
 * marketplaces_extras=3 não-vazios (2 "teste"). `marketplaces_extras` já foi
 * substituída pela tabela `company_marketplaces` (161 linhas / 145 empresas).
 *
 * ⚠ `email_colaborador` NÃO entra aqui — coluna, dados e a pendência
 * `sem_email_colaborador` permanecem (decisão do usuário: 6 gmails reais de
 * onboarding, sem duplicata em nenhum outro lugar).
 *
 * `down()` recria as 5 colunas com os tipos originais (todas nullable) —
 * reversível (T-EQK-04). Migration DEFENSIVA nos dois sentidos: a árvore de
 * trabalho é compartilhada e o banco local pode estar em estados diferentes.
 */
return new class extends Migration
{
    /** Colunas removidas por esta migration. */
    private const COLUNAS = [
        'nicho',
        'dor',
        'vende_ml',
        'faturamento_mensal',
        'marketplaces_extras',
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (self::COLUNAS as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'nicho')) {
                $table->string('nicho', 255)->nullable();
            }

            if (! Schema::hasColumn('companies', 'dor')) {
                $table->text('dor')->nullable();
            }

            if (! Schema::hasColumn('companies', 'vende_ml')) {
                $table->tinyInteger('vende_ml')->nullable();
            }

            if (! Schema::hasColumn('companies', 'faturamento_mensal')) {
                $table->decimal('faturamento_mensal', 12, 2)->nullable();
            }

            if (! Schema::hasColumn('companies', 'marketplaces_extras')) {
                $table->json('marketplaces_extras')->nullable();
            }
        });
    }
};
