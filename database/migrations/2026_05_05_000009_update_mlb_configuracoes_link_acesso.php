<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_configuracoes', function (Blueprint $table) {
            $table->dropColumn(['plataforma_login', 'plataforma_senha']);
            $table->text('link_acesso')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_configuracoes', function (Blueprint $table) {
            $table->dropColumn('link_acesso');
            $table->string('plataforma_login', 150)->nullable();
            $table->string('plataforma_senha', 150)->nullable();
        });
    }
};
