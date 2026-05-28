<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ml_user_id')->index();   // user_id retornado pelo ML OAuth (= ml_store_id)
            $table->text('access_token');             // encrypted via cast
            $table->text('refresh_token');            // encrypted via cast — renovar sempre
            $table->string('token_type')->default('bearer');
            $table->string('scope')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_refreshed_at')->nullable();
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active')->index();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique('company_id');             // 1 token ativo por empresa
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_tokens');
    }
};
