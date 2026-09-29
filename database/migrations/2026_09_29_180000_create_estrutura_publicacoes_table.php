<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anunciar do Mapeamento Estrutural — o par Clássico + Premium de uma oferta,
 * publicado pelo Portal do Cliente. Tabela NOVA; nenhuma tabela com dado em
 * produção é alterada. Decisão de schema:
 * `.planning/adrs/PORTAL-03-anunciar-do-mapeamento.md`.
 *
 * Não reaproveita `ml_anuncio_rascunhos`: `user_id` é NOT NULL com FK para
 * `users`, e o portal usa o guard `portal`.
 *
 * MariaDB: FK e unique com nome curto explícito; nenhum `nullOnDelete`;
 * nenhum `timestamp()` fora do `timestamps()` (`publicando_em` e
 * `publicado_em` são `dateTime`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estrutura_publicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oferta_id')->constrained('estrutura_ofertas', 'id', 'epub_oferta_fk')->cascadeOnDelete();
            $table->string('status', 12)->default('rascunho');
            $table->json('dados')->nullable();
            $table->json('erros')->nullable();
            // sha256 dos dados efetivos que o ML aprovou — o servidor recusa
            // publicar o que mudou depois da conferência.
            $table->string('validado_hash', 64)->nullable();
            // Gravados no instante em que o POST /items devolve o id: é o que
            // impede republicar o que já existe.
            $table->string('ml_item_classico', 20)->nullable();
            $table->string('ml_item_premium', 20)->nullable();
            $table->dateTime('publicando_em')->nullable();
            $table->dateTime('publicado_em')->nullable();
            $table->timestamps();

            $table->unique('oferta_id', 'epub_oferta_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estrutura_publicacoes');
    }
};
