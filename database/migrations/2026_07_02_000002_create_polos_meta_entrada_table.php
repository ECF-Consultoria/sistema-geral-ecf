<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta de ENTRANTES por região × mês (aba Metas). Cada polo tem um alvo mensal de
     * empresas a entrar (ex.: julho → Arapongas 25, Bento 10). `mes` no formato 'YYYY-MM'
     * (casa com a fatia de data_solicitacao). Alvo editável por admin/gestor no dashboard.
     */
    public function up(): void
    {
        Schema::create('polos_meta_entrada', function (Blueprint $table) {
            $table->id();
            $table->string('polo');            // região (MlbImplementacao::ONB_POLO_OPCOES)
            $table->string('mes', 7);          // 'YYYY-MM'
            $table->unsignedInteger('meta')->default(0);
            $table->timestamps();
            $table->unique(['polo', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_meta_entrada');
    }
};
