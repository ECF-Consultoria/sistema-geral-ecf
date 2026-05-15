<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN publication_role ENUM('gestor','lider','publicador','analista') NULL DEFAULT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN publication_role ENUM('gestor','lider','publicador') NULL DEFAULT NULL");
    }
};
