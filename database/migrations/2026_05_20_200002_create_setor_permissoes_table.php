<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões habilitadas por setor (1:N de permission keys).
 * Catálogo canônico de keys vive em App\Support\Permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setor_permissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
            $table->string('permission_key', 60);
            $table->timestamps();

            $table->unique(['setor_id', 'permission_key']);
            $table->index('permission_key'); // pra busca reversa "quem tem essa perm"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setor_permissoes');
    }
};
