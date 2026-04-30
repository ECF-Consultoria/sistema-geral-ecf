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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'consultor', 'mentor'])->default('consultor')->after('email');
            $table->unsignedBigInteger('created_by')->nullable()->after('role');
            $table->boolean('active')->default(true)->after('created_by');
            $table->string('phone')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'created_by', 'active', 'phone']);
        });
    }
};
