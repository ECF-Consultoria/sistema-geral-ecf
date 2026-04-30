<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ml_grant_token')->nullable()->comment('Token/link enviado ao cliente');
            $table->enum('status', ['pending', 'active', 'expired'])->default('pending');
            $table->date('granted_at')->nullable()->comment('Data que o cliente aceitou');
            $table->date('expires_at')->nullable()->comment('Data de expiração do grant');
            $table->date('regranted_at')->nullable()->comment('Data do último regrant');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_grants');
    }
};
