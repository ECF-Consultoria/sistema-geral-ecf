<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rascunho de anúncio do Mercado Livre.
 *
 * O funcionário monta o anúncio no nosso sistema (wizard) e o rascunho vive
 * aqui — a API do ML só é tocada para validar e publicar. Isso desacopla a UX
 * da API e permite autosave, reuso, aprovação e re-tentativa em caso de erro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_anuncio_rascunhos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();   // conta ML de destino (cliente)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();      // funcionário que está montando
            $table->enum('status', ['rascunho', 'validado', 'publicando', 'publicado', 'erro'])->default('rascunho');
            $table->string('category_id')->nullable();       // categoria folha escolhida (MLBxxxx)
            $table->json('payload')->nullable();             // todos os campos do anúncio (título, preço, atributos, variações, fotos...)
            $table->json('validation_errors')->nullable();   // erros do /items/validate (por campo)
            $table->string('ml_item_id')->nullable();        // MLB id retornado após publicar
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_anuncio_rascunhos');
    }
};
