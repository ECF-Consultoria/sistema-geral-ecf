<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_monthly_revenues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->decimal('gross_revenue', 15, 2)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year_month']);
            $table->index('year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_monthly_revenues');
    }
};
