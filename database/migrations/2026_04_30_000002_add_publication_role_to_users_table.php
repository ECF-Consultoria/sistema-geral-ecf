<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('publication_role', ['gestor', 'lider', 'publicador'])
                  ->nullable()
                  ->default(null)
                  ->after('role');
            $table->unsignedSmallInteger('publication_meta')
                  ->default(220)
                  ->after('publication_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['publication_role', 'publication_meta']);
        });
    }
};
