<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — Status 'auto_resolvido' no módulo Sugadores.
 *
 * A análise diária passa a auto-resolver sugadores 'pendente' antigos
 * que não foram re-detectados na rodada atual (combate ao acúmulo
 * histórico). Esse caminho NÃO deve ser confundido com o 'resolvido'
 * manual feito por um analista — manter auditabilidade separada:
 *
 *  - status 'auto_resolvido' indica baixa automática pelo serviço
 *  - resolvido_em é preenchido, mas resolvido_por fica NULL
 *  - sugador_acoes recebe linha com acao='auto_resolvido' e user_id=NULL
 *
 * Por isso este migration também torna sugador_acoes.user_id NULLABLE
 * (antes era NOT NULL). Sem essa mudança o INSERT do audit log da
 * auto-resolução estoura constraint — pré-requisito do W1-T2 do PLAN.
 *
 * 'auto_resolvido' entra em STATUS_TRAVADOS no model para que rodadas
 * futuras não voltem o registro para 'pendente'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // ALTER ENUM é sintaxe MySQL — em SQLite o ENUM vira CHECK no create.
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN status ENUM('pendente','em_acao','resolvido','ignorado','movido','auto_resolvido') NOT NULL DEFAULT 'pendente'");

            // Auto-resolução grava SugadorAcao com user_id=NULL (não há analista).
            // O schema original (000003_create_sugador_acoes_table) declarou
            // user_id como constrained() — implícito NOT NULL.
            DB::statement("ALTER TABLE sugador_acoes MODIFY COLUMN user_id BIGINT UNSIGNED NULL");
        } else {
            // SQLite (testes): usar schema builder com ->change() para tornar
            // user_id nullable. Laravel 12 não exige doctrine/dbal para mudanças
            // simples de nullability. Sem isso o INSERT do audit log da
            // auto-resolução estoura NOT NULL constraint nos testes.
            Schema::table('sugador_acoes', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
            // Status ENUM em SQLite é um CHECK constraint criado no schema da
            // tabela; como o create original lista apenas 4 valores e migrations
            // posteriores (movido) só atuam em MySQL, o teste já roda sem CHECK
            // efetivo em SQLite. Não há nada a fazer aqui.
        }
    }

    public function down(): void
    {
        // Antes de reverter o ENUM, mover registros 'auto_resolvido' para
        // 'resolvido' — semanticamente o mais próximo (baixa final do item).
        DB::statement("UPDATE sugadores SET status = 'resolvido' WHERE status = 'auto_resolvido'");

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sugadores MODIFY COLUMN status ENUM('pendente','em_acao','resolvido','ignorado','movido') NOT NULL DEFAULT 'pendente'");

            // Atenção: reverter user_id para NOT NULL só é seguro se não houver
            // linhas em sugador_acoes com user_id IS NULL — o caller do down()
            // deve garantir isso (ou rodar UPDATE manual). Mantemos a reversão
            // para simetria com up().
            DB::statement("ALTER TABLE sugador_acoes MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL");
        }
    }
};
