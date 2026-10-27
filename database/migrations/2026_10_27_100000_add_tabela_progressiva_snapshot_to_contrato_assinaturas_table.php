<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 261027-m1y — tabela progressiva IMPRESSA no contrato, congelada na
 * geração (mesma disciplina de `servicos_snapshot`). Nula em contrato antigo
 * e em serviço que não imprime tabela variável.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contrato_assinaturas') || Schema::hasColumn('contrato_assinaturas', 'tabela_progressiva_snapshot')) {
            return;
        }

        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            $table->json('tabela_progressiva_snapshot')->nullable()->after('servicos_snapshot');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('contrato_assinaturas', 'tabela_progressiva_snapshot')) {
            Schema::table('contrato_assinaturas', function (Blueprint $table) {
                $table->dropColumn('tabela_progressiva_snapshot');
            });
        }
    }
};
