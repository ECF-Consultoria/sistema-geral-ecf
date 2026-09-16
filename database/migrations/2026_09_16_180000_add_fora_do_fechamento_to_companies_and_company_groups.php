<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260916-onn — marcação "não participa do fechamento", em empresa e em
 * grupo.
 *
 * O fechamento existe para descobrir em que faixa da tabela progressiva cada
 * cliente caiu no mês; quem não tem contrato progressivo (ex.: Rações Soldera,
 * contrato de Brigada; grupo Wenus, valor fixo) é marcado caso a caso.
 *
 * ⚠️ Default `false` = regressão zero: ninguém sai de nada até alguém marcar.
 * ⚠️ `_por` é `nullable()` ANTES de `nullOnDelete()` — o MariaDB recusa SET
 *    NULL em coluna NOT NULL (erro 1830, já derrubou deploy).
 * ⚠️ Sem índice próprio: a consulta é por `true` em tabelas de ~200 linhas.
 *    As FKs geram índice com nome automático curto (bem abaixo de 64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('fora_do_fechamento')->default(false);
            $table->text('fora_do_fechamento_motivo')->nullable();
            $table->foreignId('fora_do_fechamento_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fora_do_fechamento_em')->nullable();
        });

        Schema::table('company_groups', function (Blueprint $table) {
            $table->boolean('fora_do_fechamento')->default(false);
            $table->text('fora_do_fechamento_motivo')->nullable();
            $table->foreignId('fora_do_fechamento_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fora_do_fechamento_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_groups', function (Blueprint $table) {
            $table->dropForeign(['fora_do_fechamento_por']);
            $table->dropColumn(['fora_do_fechamento', 'fora_do_fechamento_motivo', 'fora_do_fechamento_por', 'fora_do_fechamento_em']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['fora_do_fechamento_por']);
            $table->dropColumn(['fora_do_fechamento', 'fora_do_fechamento_motivo', 'fora_do_fechamento_por', 'fora_do_fechamento_em']);
        });
    }
};
