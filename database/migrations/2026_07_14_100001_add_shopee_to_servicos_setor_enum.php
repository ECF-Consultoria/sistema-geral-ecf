<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 75 / Plan 75-01 (DEC-1) — adiciona o valor 'shopee' ao enum
 * `servicos.setor`, habilitando "empresa atendida na Shopee" via contrato de
 * serviço (mesma mecânica do ML, que se apoia em contrato de setor
 * 'performance').
 *
 * DESVIO OBRIGATÓRIO do analog de 'polos'
 * (2026_07_03_113103_add_polos_to_servicos_setor_enum.php), documentado no
 * RESEARCH Pitfall 1: aquela migração PULOU o SQLite inteiro. Aqui NÃO
 * podemos pular, porque o SQLite (driver de teste) ENFORÇA o CHECK constraint
 * do enum — comprovado empiricamente (`SQLSTATE[23000] CHECK constraint
 * failed`) — e os Feature tests da Phase 75 PRECISAM persistir Servico com
 * setor='shopee'.
 *
 * Decisão travada do planner (RESEARCH Open Question 1): no branch SQLite a
 * coluna vira `string` puro SEM CHECK. Assim qualquer setor futuro (shopee,
 * próximos marketplaces) nunca mais quebra teste. A paridade de domínio com
 * produção fica garantida pelo enum MySQL + a validação de aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Prod: ALTER direto (padrão da migração de polos), agora incluindo 'shopee'.
            DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','shopee','outros') NOT NULL DEFAULT 'outros'");
        } else {
            // SQLite (tests): CHECK é ENFORÇADO (Pitfall 1). Recria a coluna como
            // string sem CHECK — encerra de vez a classe de bug polos/shopee/etc.
            Schema::table('servicos', function (Blueprint $table) {
                $table->string('setor')->default('outros')->change();
            });
        }
    }

    public function down(): void
    {
        // Reverter os registros 'shopee' para 'outros' ANTES de estreitar o enum,
        // senão o ALTER viola a constraint (padrão do down() da migração de polos).
        DB::table('servicos')->where('setor', 'shopee')->update(['setor' => 'outros']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','outros') NOT NULL DEFAULT 'outros'");
        } else {
            // SQLite: mantém string sem CHECK (não há como reintroduzir o enum
            // estreito de forma útil no driver de teste; o rollback só limpa os dados).
            Schema::table('servicos', function (Blueprint $table) {
                $table->string('setor')->default('outros')->change();
            });
        }
    }
};
