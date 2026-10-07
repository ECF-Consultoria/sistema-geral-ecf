<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove a coluna `responsavel_id` do módulo Autenticadores 2FA: o conceito de
 * "Responsável" foi retirado do produto. A tabela é nova e estava vazia em
 * produção, então soltar a coluna (e a FK) é seguro.
 *
 * `dropConstrainedForeignId` solta a FK e a coluna — necessário no MariaDB, que
 * não deixa dropar a coluna com a FK ainda presente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autenticadores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_id');
        });
    }

    public function down(): void
    {
        Schema::table('autenticadores', function (Blueprint $table) {
            $table->foreignId('responsavel_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
    }
};
