<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ml_tokens` passa a aceitar as DUAS âncoras de conta ML.
 *
 * PROBLEMA: `callbackPolos()` autorizava o OAuth mas NÃO guardava o token,
 * porque `company_id` era NOT NULL + FK e empresa de Polos não tem `Company`.
 * Resultado: as empresas autorizavam, o Cust ID era capturado, e nenhuma
 * aparecia em `/mlb/anuncios` — que lista `Company::whereHas('mlToken')`.
 *
 * DESENHO: `mlb_empresa_id` nullable ao lado de `company_id` nullable, com
 * unique em cada. Exatamente uma das duas fica preenchida; a invariante é
 * garantida no `MercadoLivreService::saveToken()`, não no schema — MariaDB e
 * SQLite divergem demais em CHECK constraint para valer o risco.
 *
 * POR QUE O UNIQUE ANTIGO NÃO PRECISA SAIR: em MariaDB (InnoDB) e em SQLite,
 * índice UNIQUE admite VÁRIOS NULL. Com `company_id` nullable, o
 * `ml_tokens_company_id_unique` continua valendo para as Companies e deixa de
 * atrapalhar as empresas de Polos, que gravam NULL ali. Isso evita o erro
 * 1553 de "dropar índice usado por FK" (learnings de bonificação §6) — não
 * dropamos índice nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Coluna primeiro, sozinha: manter FK e UNIQUE em chamadas separadas
        // deixa a ordem das DDL explícita em vez de depender de como o
        // Blueprint resolve `constrained()` junto com `unique()`.
        if (! Schema::hasColumn('ml_tokens', 'mlb_empresa_id')) {
            Schema::table('ml_tokens', function (Blueprint $table) {
                $table->unsignedBigInteger('mlb_empresa_id')->nullable()->after('company_id');
            });

            Schema::table('ml_tokens', function (Blueprint $table) {
                $table->unique('mlb_empresa_id');
                $table->foreign('mlb_empresa_id')
                    ->references('id')->on('mlb_empresas')
                    ->cascadeOnDelete();
            });
        }

        // `company_id` passa a aceitar NULL (token de empresa de Polos).
        // MODIFY COLUMN não encosta em índice nem em FK — o unique sobrevive.
        Schema::table('ml_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // ATENÇÃO — esta reversão APAGA os tokens de empresa de Polos.
        // Eles só existem por causa desta migration: sem `mlb_empresa_id` não
        // há como ancorá-los, e `company_id` volta a ser NOT NULL. Apagar é a
        // única reversão coerente; a empresa reconecta pelo link de OAuth.
        DB::table('ml_tokens')->whereNull('company_id')->delete();

        if (Schema::hasColumn('ml_tokens', 'mlb_empresa_id')) {
            Schema::table('ml_tokens', function (Blueprint $table) {
                // FK antes do UNIQUE: o índice sustenta a FK, e dropá-lo
                // primeiro devolve errno 1553 em MariaDB (learnings §6).
                $table->dropForeign(['mlb_empresa_id']);
                $table->dropUnique(['mlb_empresa_id']);
                $table->dropColumn('mlb_empresa_id');
            });
        }

        Schema::table('ml_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
        });
    }
};
