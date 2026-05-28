<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_tokens', function (Blueprint $table) {
            $table->text('scope')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ml_tokens', function (Blueprint $table) {
            $table->string('scope')->nullable()->change();
        });
    }
};
