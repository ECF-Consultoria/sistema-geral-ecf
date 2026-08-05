<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anúncio fora da metodologia deixa de contar em vendas, meta e score.
 *
 * Antes só havia apagar o registro — o que destruía a evidência de que o
 * anúncio existiu. O flag preserva o histórico e tira da apuração.
 *
 * `desconsiderado_por` é nullable de propósito: `nullOnDelete` exige coluna
 * nullable (erro 1830 no MariaDB) e a autoria não deve sumir com o usuário —
 * quando ele é removido, resta a data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->boolean('desconsiderado')->default(false)->index();
            $table->foreignId('desconsiderado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('desconsiderado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desconsiderado_por');
            $table->dropColumn(['desconsiderado', 'desconsiderado_em']);
        });
    }
};
