<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN publication_role ENUM('gestor','lider','publicador','analista') NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        // ALTER TABLE ... MODIFY COLUMN é sintaxe MySQL — ignorar em SQLite (testes)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE users MODIFY COLUMN publication_role ENUM('gestor','lider','publicador') NULL DEFAULT NULL");
        }
    }
};
