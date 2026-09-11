<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 152 Plano 02 (D-10) — Cria a tabela de persistência do checklist
 * administrativo: `checklist_administrativo_itens`.
 *
 * Molde: database/migrations/2026_08_11_120100_create_onboardings_tables.php
 * (bloco `onboarding_passos`) — copia o SHAPE (autoria, valor json, timestamps
 * de auto-conclusão), nunca a HOSPEDAGEM: esta tabela é ancorada em
 * `company_id` direto, não na FK do onboarding nem na FK do contrato de
 * serviço (D-10 — o motor de Onboarding é molde, não hospedagem).
 *
 * Diferenças deliberadas em relação ao análogo:
 *  - Sem as FKs de template de passo nem de onboarding do análogo: os 9
 *    itens são um catálogo FECHADO em código (`ChecklistAdministrativoDefinicao`,
 *    planos seguintes), não linhas de uma tabela de template.
 *  - `status` tem só DOIS valores (`aberto` | `concluido`) — D-02 proíbe o
 *    estado "não aplicável"; a isenção de contrato (D-07) é resolvida por
 *    montagem condicional do grupo, nunca por marcação de item.
 *  - Índice unique com NOME EXPLÍCITO (`cai_company_chave_unique`) — nome
 *    autogerado estoura 64 caracteres no MariaDB e falha com erro 1059,
 *    deixando a tabela já criada e a migration `Pending`
 *    (.planning/learnings/desempenho-bonificacao.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checklist_administrativo_itens')) {
            Schema::create('checklist_administrativo_itens', function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                $table->string('chave', 60)
                    ->comment('Slug do item no catálogo fechado em código (App\\Services\\ChecklistAdministrativo\\ChecklistAdministrativoDefinicao, plano 152-03). Renomear o RÓTULO de um item nunca pode trocar a chave.');

                $table->string('status', 24)
                    ->default('aberto')
                    ->comment('aberto | concluido — App\\Models\\ChecklistAdministrativoItem::STATUS_TODOS. Não existe o estado "não aplicável" (D-02): a isenção de contrato é resolvida por montagem condicional do grupo (D-07), nunca por marcação.');

                $table->json('valor')->nullable();

                // nullable() ANTES de constrained()->nullOnDelete() — inverter
                // a ordem dá erro 1830 no MariaDB e passa despercebido no
                // SQLite dos testes.
                $table->foreignId('feito_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('feito_em')->nullable();

                $table->timestamp('auto_em')
                    ->nullable()
                    ->comment('Momento em que um resolver automático fechou o item sozinho, distinto de feito_em (conclusão manual).');

                $table->timestamps();

                // Nome explícito — nome autogerado excede 64 chars no MariaDB
                // (erro 1059). 'cai_company_chave_unique' tem 24 caracteres.
                $table->unique(['company_id', 'chave'], 'cai_company_chave_unique');

                $table->index(['chave']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_administrativo_itens');
    }
};
