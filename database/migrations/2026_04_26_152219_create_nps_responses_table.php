<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nps_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('nps_surveys')->cascadeOnDelete();
            $table->string('respondent_name');
            $table->unsignedTinyInteger('score_consultant')->comment('0-10');
            $table->unsignedTinyInteger('score_mentor')->comment('0-10');
            $table->unsignedTinyInteger('score_overall')->comment('0-10');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nps_responses');
    }
};
