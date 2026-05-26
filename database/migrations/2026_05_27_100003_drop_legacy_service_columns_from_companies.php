<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 14 Plan 14-06.
     *
     * Drop final das colunas antigas de servicos em companies. Antes de aplicar
     * esta migration em um banco com dados reais, o gate obrigatorio e:
     * `php artisan phase14:verificar-cobranca --abort-on-divergence`.
     *
     * O rollback recria apenas a estrutura vazia; dados apagados pelo drop nao
     * sao restaurados.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'service_type',
                'contract_type',
                'contract_start',
                'contract_end',
                'additional_service',
                'additional_service_price',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Tipos exatamente como estavam antes do drop.
            $table->text('service_type')->nullable()->after('notes');
            $table->string('contract_type')->nullable()->after('service_type');
            $table->date('contract_start')->nullable()->after('contract_type');
            $table->date('contract_end')->nullable()->after('contract_start');
            $table->string('additional_service')->nullable()->after('contract_end');
            $table->decimal('additional_service_price', 10, 2)->nullable()->after('additional_service');
        });
    }
};
