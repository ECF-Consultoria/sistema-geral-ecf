<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decola deixa de ser booleano (Sim/Não) e vira texto.
 *
 * O campo passou a ter um terceiro estado operacional ("Mensagem Enviada") e, como os demais
 * selects do Painel Polos, aceita valor criado inline — nada disso cabe num boolean.
 *
 * Conversão do histórico: 1 → 'Sim', 0 → 'Não', NULL → NULL (nunca preenchido).
 * Feita via coluna temporária + rename (portável MySQL/SQLite) em vez de ALTER TYPE cru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->string('decola_txt', 60)->nullable()->after('decola');
        });

        DB::table('mlb_implementacoes')->where('decola', 1)->update(['decola_txt' => 'Sim']);
        DB::table('mlb_implementacoes')->where('decola', 0)->update(['decola_txt' => 'Não']);

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('decola');
        });

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->renameColumn('decola_txt', 'decola');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->boolean('decola_bool')->nullable()->after('decola');
        });

        // Só 'Sim' volta como true; qualquer outro valor preenchido (inclusive
        // "Mensagem Enviada" e valores criados na mão) colapsa em false.
        DB::table('mlb_implementacoes')->where('decola', 'Sim')->update(['decola_bool' => true]);
        DB::table('mlb_implementacoes')->whereNotNull('decola')->where('decola', '!=', 'Sim')
            ->update(['decola_bool' => false]);

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->dropColumn('decola');
        });

        Schema::table('mlb_implementacoes', function (Blueprint $table) {
            $table->renameColumn('decola_bool', 'decola');
        });
    }
};
