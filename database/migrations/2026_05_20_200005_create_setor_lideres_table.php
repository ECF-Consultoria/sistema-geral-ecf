<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot setor → líderes. Independente de user_setores (líder não precisa ser
 * membro). User vira líder de N setores; setor pode ter N líderes.
 * Quem está aqui ganha permissões `lideranca.*` automaticamente via User::hasPermission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setor_lideres', function (Blueprint $table) {
            $table->foreignId('setor_id')->constrained('setores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->primary(['setor_id', 'user_id']);
            $table->index('user_id'); // "esse user lidera quais setores?"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setor_lideres');
    }
};
