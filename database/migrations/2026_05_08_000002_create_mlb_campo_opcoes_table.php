<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_campo_opcoes', function (Blueprint $table) {
            $table->id();
            $table->string('campo', 50);
            $table->string('valor', 200);
            $table->unique(['campo', 'valor']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_campo_opcoes');
    }
};
