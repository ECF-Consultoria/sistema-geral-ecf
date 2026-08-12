<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 136 Plano 02 (D-01/D-02/D-07/D-09/D-12) — tabela de lançamento manual
 * de métricas financeiras por (empresa, mês, métrica). Uma linha representa
 * UMA célula; faturamento e margem (CMV) alternam `auto`/`manual` de forma
 * independente porque são linhas distintas (D-07).
 *
 * Molde: `2026_08_11_120100_create_onboardings_tables.php` (guard
 * `Schema::hasTable()` idempotente, `nullable()->constrained()->nullOnDelete()`)
 * e `2026_08_03_120000_create_desempenho_company_score_snapshots_table.php`
 * (índice único nomeado à mão, coluna STRING em vez de enum).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desempenho_metricas_manuais')) {
            Schema::create('desempenho_metricas_manuais', function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                // Sempre o 1º dia do mês da competência (YYYY-MM-01) — mesmo
                // padrão de `desempenho_company_score_snapshots.mes_referencia`.
                $table->date('mes_referencia');

                // STRING sempre — nunca enum: o CHECK é enforçado no SQLite
                // dos testes e quebra ao surgir valor novo (armadilha
                // registrada na memória do projeto). A whitelist real vive em
                // `DesempenhoMetricaManual::METRICAS`.
                $table->string('metrica', 20);

                $table->decimal('valor', 16, 2);
                // Nullable — guarda o valor substituído na última edição;
                // não existe na primeira gravação da célula.
                $table->decimal('valor_anterior', 16, 2)->nullable();

                // false = "revertido para auto". A linha NUNCA é deletada
                // (D-02/D-12) — o rastro é o produto.
                $table->boolean('ativo')->default(true);

                // nullable() ANTES de nullOnDelete() — sem isso o MariaDB de
                // produção recusa com erro 1830 (armadilha da memória do
                // projeto). Usuário removido não apaga o lançamento.
                $table->foreignId('lancado_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('lancado_em');

                $table->timestamps();

                // O nome do índice é EXPLÍCITO e curto de propósito. O nome
                // que o Laravel geraria sozinho
                // (`desempenho_metricas_manuais_company_id_mes_referencia_metrica_unique`)
                // tem 68 caracteres — 4 acima do limite de 64 do MariaDB
                // (erro 1059). O SQLite dos testes aceita, então isso só
                // apareceria em produção, e a falha não é limpa: cria a
                // tabela, morre no índice e deixa a migration `Pending` com a
                // tabela já existente e sem o índice.
                $table->unique(
                    ['company_id', 'mes_referencia', 'metrica'],
                    'dmm_company_mes_metrica_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desempenho_metricas_manuais');
    }
};
