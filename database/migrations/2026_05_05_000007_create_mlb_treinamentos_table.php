<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_treinamentos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 200);
            $table->text('descricao')->nullable();
            $table->string('url_video', 500);
            $table->string('login_acesso', 150)->nullable();
            $table->string('senha_acesso', 150)->nullable();
            $table->tinyInteger('ordem')->default(0)->unsigned();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_treinamentos');
    }
};
