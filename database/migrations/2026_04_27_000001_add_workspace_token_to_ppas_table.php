<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppas', function (Blueprint $table) {
            $table->uuid('workspace_token')->nullable()->unique()->after('trello_board_url');
        });
    }

    public function down(): void
    {
        Schema::table('ppas', function (Blueprint $table) {
            $table->dropColumn('workspace_token');
        });
    }
};
