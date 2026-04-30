<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adman_metrics', function (Blueprint $table) {
            $table->decimal('net_billing', 15, 2)->nullable()->after('revenue');
            $table->decimal('sales_fee', 15, 2)->nullable()->after('net_billing');
            $table->decimal('taxes', 15, 2)->nullable()->after('sales_fee');
            $table->decimal('shipping_cost', 15, 2)->nullable()->after('taxes');
            $table->decimal('product_cost', 15, 2)->nullable()->after('shipping_cost');
            $table->decimal('return_cost', 15, 2)->nullable()->after('product_cost');
            $table->decimal('profit_share', 8, 4)->nullable()->after('return_cost');
            $table->integer('sold_quantity')->nullable()->after('profit_share');
        });
    }

    public function down(): void
    {
        Schema::table('adman_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'net_billing', 'sales_fee', 'taxes', 'shipping_cost',
                'product_cost', 'return_cost', 'profit_share', 'sold_quantity',
            ]);
        });
    }
};
