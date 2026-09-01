<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260901-gj7 (Tarefa 1) — venda combinada Mercado Livre + Shopee vira
 * UM contrato só.
 *
 * `contrato_junto_com_servico_id`: quando ESTE serviço aparece junto com o
 * serviço X (o "dono") na mesma empresa, os dois compartilham UM contrato,
 * que pertence a X — é X quem define o modelo da Clicksign e o `servico_id`
 * gravado no `ContratoAssinatura`. Auto-referência à própria tabela
 * `servicos` (mesmo padrão de `parent_company_id` em `companies`).
 *
 * `nullable()` + `nullOnDelete()`: a coluna PRECISA ser nullable porque a FK
 * usa `nullOnDelete()` — sem isso o MariaDB recusa com erro 1830 (armadilha
 * já registrada no projeto) e o SQLite dos testes não pega a omissão.
 * Semanticamente também faz sentido: a maioria dos serviços NÃO anda
 * combinada com nenhum outro.
 *
 * ⚠️ Esta migration NÃO preenche a coluna para nenhum serviço. É passo de
 * PRODUÇÃO, pós-deploy (Shopee (9) → Gestão (6)), conferido por reconsulta ao
 * banco — mesma disciplina de `add_plataforma_to_servicos_table` e
 * `add_exige_contrato_to_servicos_table`.
 *
 * Idempotente via `hasColumn`, mesmo padrão das migrations irmãs desta
 * tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('servicos', 'contrato_junto_com_servico_id')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->foreignId('contrato_junto_com_servico_id')
                    ->nullable()
                    ->after('plataforma')
                    ->constrained('servicos')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicos', 'contrato_junto_com_servico_id')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->dropForeign(['contrato_junto_com_servico_id']);
                $table->dropColumn('contrato_junto_com_servico_id');
            });
        }
    }
};
