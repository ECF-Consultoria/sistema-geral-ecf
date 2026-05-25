<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ação "Mover para campanha SGI" no módulo Sugadores.
 *
 * Quando o analista identifica um adgroup sugador, a ação real no ML é mover
 * o adgroup para uma campanha de Sugadores (nome contém SGI, status paused).
 * O sistema NÃO chama a API do ML — apenas registra a intenção/decisão:
 *
 *  - status 'movido' indica que o analista marcou a ação
 *  - campanha_destino_id/_nome guardam pra onde foi (não-clicável, só log)
 *  - movido_em/_por_id formam o audit trail
 *
 * 'movido' entra em STATUS_TRAVADOS (no model) para não ser sobrescrito por
 * re-análises diárias. O CleanupSugadoresQuarentena já remove os já-movidos
 * em ciclos seguintes via o nome SGI da campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sugadores', function (Blueprint $table) {
            $table->string('campanha_destino_id', 64)->nullable()->after('campaign_status');
            $table->string('campanha_destino_nome', 255)->nullable()->after('campanha_destino_id');
            $table->dateTime('movido_em')->nullable()->after('resolvido_por');
            $table->foreignId('movido_por_id')->nullable()->after('movido_em')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'campanha_destino_id'], 'sugadores_destino_idx');
        });

        // ALTER ENUM é sintaxe MySQL — em SQLite (testes) o enum vira CHECK no
        // create da tabela, então simplesmente pulamos.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN status ENUM('pendente','em_acao','resolvido','ignorado','movido') NOT NULL DEFAULT 'pendente'");
        }
    }

    public function down(): void
    {
        // Reverte status enum primeiro — registros 'movido' viram 'em_acao'
        // (mais próximo semanticamente de "analista agiu mas não finalizou").
        DB::statement("UPDATE sugadores SET status = 'em_acao' WHERE status = 'movido'");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN status ENUM('pendente','em_acao','resolvido','ignorado') NOT NULL DEFAULT 'pendente'");
        }

        Schema::table('sugadores', function (Blueprint $table) {
            $table->dropIndex('sugadores_destino_idx');
            $table->dropConstrainedForeignId('movido_por_id');
            $table->dropColumn(['campanha_destino_id', 'campanha_destino_nome', 'movido_em']);
        });
    }
};
