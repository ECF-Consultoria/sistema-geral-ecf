<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->string('thumbnail_url', 500)->nullable()->after('mlb_code');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};
