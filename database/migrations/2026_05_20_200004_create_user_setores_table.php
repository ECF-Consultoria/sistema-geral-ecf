<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot N:N — um user pode ser membro de vários setores, com 1 cargo por setor.
 * is_principal marca qual setor é exibido como "primário" no perfil (1 por user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_setores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
            $table->foreignId('cargo_id')->nullable()->constrained('cargos')->nullOnDelete();
            $table->boolean('is_principal')->default(false);
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'setor_id']);
            $table->index(['setor_id', 'cargo_id']); // lista de membros + cargo por setor
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_setores');
    }
};
