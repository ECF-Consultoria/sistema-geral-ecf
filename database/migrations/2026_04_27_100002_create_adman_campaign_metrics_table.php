<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adman_campaign_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->string('campaign_id');
            $table->string('campaign_name')->nullable();
            $table->string('campaign_status')->nullable();
            $table->decimal('investment', 15, 2)->nullable();
            $table->decimal('revenue', 15, 2)->nullable();
            $table->decimal('acos', 8, 4)->nullable();
            $table->decimal('tacos', 8, 4)->nullable();
            $table->decimal('roas', 8, 4)->nullable();
            $table->decimal('cpc', 10, 4)->nullable();
            $table->integer('clicks')->nullable();
            $table->integer('impressions')->nullable();
            $table->integer('sold_quantity')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'reference_date', 'campaign_id'], 'campaign_metrics_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adman_campaign_metrics');
    }
};
