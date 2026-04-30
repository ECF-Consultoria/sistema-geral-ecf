<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->enum('value_type', ['currency', 'percentage'])
                ->default('percentage')
                ->after('target_value');
        });

        DB::statement("ALTER TABLE goals MODIFY COLUMN metric ENUM(
            'tacos', 'nps', 'absenteeism', 'contribution_margin',
            'revenue_growth', 'products_without_cost', 'ppa_completion',
            'revenue', 'avg_ticket', 'margin', 'net_billing', 'acos'
        ) NOT NULL");
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table) {
            $table->dropColumn('value_type');
        });

        DB::statement("ALTER TABLE goals MODIFY COLUMN metric ENUM(
            'tacos', 'nps', 'absenteeism', 'contribution_margin',
            'revenue_growth', 'products_without_cost', 'ppa_completion'
        ) NOT NULL");
    }
};
