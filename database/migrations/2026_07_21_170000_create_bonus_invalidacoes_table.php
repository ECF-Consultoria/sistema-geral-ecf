<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invalidação de resultado de empresa para BÔNUS, por competência (item 3/4,
 * 2026-07-21). Caso de uso: empresa sem preço de custo preenchido infla a
 * margem injustamente e distorce a nota do analista/estrategista. O admin
 * invalida a empresa para a competência (mês) e ela some da conta do bônus
 * daquele mês — financeiro E NPS (decisão do usuário: empresa inteira).
 *
 * Chave é (empresa, competência): invalidar junho não afeta julho. A
 * competência é sempre o MÊS FINANCEIRO M (o NPS de M coletado em M+1 é
 * excluído usando a MESMA competência M, não o mês deslocado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_invalidacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Competência = 1º dia do mês financeiro (YYYY-MM-01).
            $table->date('competencia');
            $table->string('motivo')->nullable();
            // Admin que invalidou — nullable + nullOnDelete (FK SET NULL exige
            // coluna nullable no MariaDB, senão o deploy quebra com 1830).
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Uma invalidação por (empresa, competência).
            $table->unique(['company_id', 'competencia']);
            // Consulta quente: "quais empresas invalidadas nesta competência?".
            $table->index('competencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_invalidacoes');
    }
};
