<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugador_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('dias_analise')->default(30);

            $table->decimal('gasto_minimo_sem_venda', 10, 2)->nullable()->default(20.00);
            $table->decimal('cpc_maximo', 10, 2)->nullable();
            $table->decimal('acos_maximo_pct', 5, 2)->nullable();
            $table->unsignedInteger('cliques_minimos_sem_venda')->nullable();
            $table->unsignedTinyInteger('pct_anuncios_para_flag_campanha')->default(50);

            $table->boolean('incluir_campanhas')->default(true);
            $table->boolean('incluir_anuncios')->default(true);
            $table->boolean('ativo')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugador_configs');
    }
};
