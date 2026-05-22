<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos por setor (ex: "Publicador", "Líder de Publicação", "Consultor", "Mentor").
 * meta_publicacoes substitui users.publication_meta — meta vem do cargo agora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->string('slug', 120);
            $table->unsignedSmallInteger('meta_publicacoes')->nullable(); // só usado em cargos do setor Publicação
            $table->smallInteger('ordem')->default(0); // ordenação por seniority dentro do setor
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['setor_id', 'slug']);
            $table->index(['setor_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos');
    }
};
