<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rastreia QUEM resolveu e QUANDO — tanto o problema quanto o comentário.
     * Antes, desmarcar o problema apagava a nota e não deixava histórico:
     * a revisão não conseguia mostrar "resolvido por fulano em tal horário".
     */
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->foreignId('problema_resolvido_por')->nullable()->after('problema_em')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('problema_resolvido_em')->nullable()->after('problema_resolvido_por');

            $table->foreignId('comentario_resolvido_por')->nullable()->after('comentario_resolvido')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('comentario_resolvido_em')->nullable()->after('comentario_resolvido_por');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('problema_resolvido_por');
            $table->dropConstrainedForeignId('comentario_resolvido_por');
            $table->dropColumn(['problema_resolvido_em', 'comentario_resolvido_em']);
        });
    }
};
