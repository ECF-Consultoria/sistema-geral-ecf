<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('service_type')->nullable()->after('notes');
            $table->date('contract_start')->nullable()->after('service_type');
            $table->date('contract_end')->nullable()->after('contract_start');
            $table->string('additional_service')->nullable()->after('contract_end');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'contract_start', 'contract_end', 'additional_service']);
        });
    }
};
