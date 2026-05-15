<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 200);
            $table->string('cust_id', 50)->nullable();
            $table->string('polo', 100)->nullable();
            $table->string('gmail', 150)->nullable();
            $table->string('estagio', 50)->default('Não Listado');
            $table->string('fase', 50)->nullable();
            $table->string('prioridade', 50)->nullable();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('contexto')->nullable();
            $table->text('skus_estagio1')->nullable(); // JSON: [{sku,ok}, ...]  máx 3
            $table->text('skus_estagio2')->nullable(); // JSON: [{sku,ok}, ...]  máx 4
            $table->text('skus_estagio3')->nullable(); // JSON: [{sku,ok}, ...]  máx 3
            $table->date('prazo_estagio1')->nullable();
            $table->date('prazo_estagio2')->nullable();
            $table->date('prazo_estagio3')->nullable();
            $table->date('encerramento')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('responsavel_id');
            $table->index('estagio');
        });

        // Adiciona FK à tabela de publicações para ligar MLB a uma empresa
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->foreignId('mlb_empresa_id')->nullable()->after('cust_id')
                  ->constrained('mlb_empresas')->nullOnDelete();
            $table->unsignedTinyInteger('sku_stage')->nullable()->after('mlb_empresa_id');
            $table->unsignedTinyInteger('sku_position')->nullable()->after('sku_stage');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropForeign(['mlb_empresa_id']);
            $table->dropColumn(['mlb_empresa_id', 'sku_stage', 'sku_position']);
        });
        Schema::dropIfExists('mlb_empresas');
    }
};
