<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_publicacoes', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('empresa', 200);
            $table->string('cust_id', 50)->nullable();
            $table->string('mlb_code', 20)->unique();
            $table->boolean('vendido')->default(false);
            $table->boolean('revisado')->default(false);
            $table->text('comentario')->nullable();
            $table->foreignId('comentario_autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('comentario_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'data']);
            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_publicacoes');
    }
};
