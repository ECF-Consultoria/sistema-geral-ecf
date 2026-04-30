<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_goals', function (Blueprint $table) {
            $table->decimal('baseline_value', 12, 4)->nullable()->after('description');
            $table->string('baseline_period', 7)->nullable()->after('baseline_value'); // YYYY-MM
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_goals', function (Blueprint $table) {
            $table->dropColumn(['baseline_value', 'baseline_period']);
        });
    }
};
