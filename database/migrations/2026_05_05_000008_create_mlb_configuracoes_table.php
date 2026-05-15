<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_configuracoes', function (Blueprint $table) {
            $table->id();
            $table->string('plataforma_login', 150)->nullable();
            $table->string('plataforma_senha', 150)->nullable();
            $table->timestamps();
        });

        Schema::table('mlb_treinamentos', function (Blueprint $table) {
            $table->dropColumn(['login_acesso', 'senha_acesso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_configuracoes');

        Schema::table('mlb_treinamentos', function (Blueprint $table) {
            $table->string('login_acesso', 150)->nullable();
            $table->string('senha_acesso', 150)->nullable();
        });
    }
};
