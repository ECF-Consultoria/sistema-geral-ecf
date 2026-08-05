<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PPA Polos (quick 260805-dzu): mesmo módulo PPA, recortado nas empresas do
     * projeto POLOS. Reaproveita `ppas` + `ppa_tasks` + Kanban + workspace do
     * cliente — só muda o escopo e a entidade alvo.
     *
     * Alvo = `mlb_empresas`, não `companies`: das 285 empresas POLOS ativas,
     * apenas 3 têm `company_id` preenchido, então um PPA Polos amarrado a
     * Company nasceria vazio. Por isso `company_id` passa a ser nullable.
     */
    public function up(): void
    {
        Schema::table('ppas', function (Blueprint $table) {
            $table->string('escopo', 20)->default('geral')->after('id')->index()
                ->comment("'geral' = PPA de carteira; 'polos' = PPA Polos");
            $table->foreignId('mlb_empresa_id')->nullable()->after('company_id')
                ->constrained('mlb_empresas')->nullOnDelete();
        });

        // company_id era NOT NULL com FK. No MySQL/MariaDB a FK precisa sair antes
        // do MODIFY; o SQLite dos testes não suporta dropForeign (recria a tabela
        // inteira no change()), por isso o guard por driver.
        $sqlite = DB::getDriverName() === 'sqlite';

        if (! $sqlite) {
            Schema::table('ppas', fn (Blueprint $table) => $table->dropForeign(['company_id']));
        }

        Schema::table('ppas', function (Blueprint $table) use ($sqlite) {
            $table->unsignedBigInteger('company_id')->nullable()->change();

            if (! $sqlite) {
                $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ppas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mlb_empresa_id');
            $table->dropColumn('escopo');
        });

        // Volta company_id para NOT NULL só se não houver PPA sem empresa cliente
        // (os de Polos não têm) — senão a coluna ficaria inconsistente.
        if (DB::table('ppas')->whereNull('company_id')->doesntExist()) {
            $sqlite = DB::getDriverName() === 'sqlite';

            if (! $sqlite) {
                Schema::table('ppas', fn (Blueprint $table) => $table->dropForeign(['company_id']));
            }

            Schema::table('ppas', function (Blueprint $table) use ($sqlite) {
                $table->unsignedBigInteger('company_id')->nullable(false)->change();

                if (! $sqlite) {
                    $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
                }
            });
        }
    }
};
