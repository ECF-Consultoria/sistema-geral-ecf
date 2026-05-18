<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona o valor 'analista' ao ENUM da coluna company_users.role
 * para permitir vincular analistas MLB à carteira de empresas
 * (necessário para o módulo Sugadores — §9.2 do MODULO_SUGADORES.md).
 *
 * MariaDB/MySQL: alterar ENUM exige ALTER TABLE com a nova lista completa.
 * O Doctrine DBAL (usado pelo Schema::table) não conhece ENUM, então usamos
 * raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE company_users
                MODIFY COLUMN role ENUM('consultor', 'mentor', 'analista') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Cuidado: se houver linhas com role='analista', o ALTER falhará.
        // O down assume que o operador removeu/migrou essas linhas antes.
        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE company_users
                MODIFY COLUMN role ENUM('consultor', 'mentor') NOT NULL
            ");
        }
    }
};
