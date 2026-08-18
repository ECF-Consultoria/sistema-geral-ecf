<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 135 Plano 02 — Cria as tabelas de INSTÂNCIA do motor de Onboarding geral.
 *
 * Molde: database/migrations/2026_05_11_000001_create_mlb_implementacoes_table.php
 * (FK + coluna json + token unique — "1 token por dono").
 *
 * Nasce ao lado do onboarding de Polos (mlb_implementacoes) — D-02: coexistência
 * intocada, nenhuma tabela ou model de Polos é tocado nesta migration.
 *
 * Tabelas criadas (ordem respeita dependência de FK):
 *  1. onboardings        — um por (company_id, servico_id), único por contrato (SC-01)
 *  2. onboarding_passos  — instância de cada template_passo dentro de um onboarding
 *  3. onboarding_links   — 1 token por EMPRESA, agregando os passos dono=cliente
 *                          de todos os onboardings dela (D-06)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────────
        // TABELA 1 — onboardings
        // Âncora Company × Servico (D-01). template_id é a versão CONGELADA em
        // que o onboarding nasceu — restrictOnDelete porque ela não pode sumir
        // debaixo de um onboarding vivo (é o que sustenta SC-09). "Um onboarding
        // por contrato" (SC-01) é imposto pelo unique() em contrato_servico_id,
        // nunca só validado na aplicação.
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('onboardings')) {
            Schema::create('onboardings', function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                $table->foreignId('servico_id')
                    ->constrained('servicos')
                    ->cascadeOnDelete();

                $table->foreignId('contrato_servico_id')
                    ->nullable()
                    ->constrained('contratos_servico')
                    ->nullOnDelete();

                $table->foreignId('template_id')
                    ->constrained('onboarding_templates')
                    ->restrictOnDelete();

                $table->string('status', 20)
                    ->default('rascunho')
                    ->comment('rascunho | andamento | concluido — App\\Models\\Onboarding::STATUSES');

                $table->foreignId('responsavel_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('iniciado_em')
                    ->nullable()
                    ->comment('Momento em que o onboarding saiu de rascunho para andamento — SLA só corre a partir daqui (D-05/D-15)');

                $table->timestamp('concluido_em')->nullable();

                $table->timestamps();

                // Um onboarding por contrato — tradução literal de SC-01 para constraint de banco.
                $table->unique('contrato_servico_id');

                $table->index(['company_id', 'servico_id', 'status']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 2 — onboarding_passos
        // Instância de cada template_passo dentro de um onboarding. `chave` é
        // denormalizada de template_passos.chave (D-10) — sobrevive à troca de
        // versão do template e é a chave de agregação do link único por empresa
        // (D-06). Estado tem SEIS valores (D-11), nunca dois: "tabela vazia"
        // nunca pode ser lida como "zero real".
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('onboarding_passos')) {
            Schema::create('onboarding_passos', function (Blueprint $table) {
                $table->id();

                $table->foreignId('onboarding_id')
                    ->constrained('onboardings')
                    ->cascadeOnDelete();

                $table->foreignId('template_passo_id')
                    ->constrained('template_passos')
                    ->restrictOnDelete();

                $table->string('chave', 60)
                    ->comment('Denormalizada de template_passos.chave; é a chave de agregação do link único por empresa (D-06/D-10) e precisa sobreviver à troca de versão do template.');

                $table->string('status', 24)
                    ->default('bloqueado')
                    ->comment('bloqueado | aberto | aguardando_coleta | indeterminado | concluido | nao_aplicavel — App\\Models\\OnboardingPasso::STATUSES (D-11)');

                $table->json('valor')->nullable();

                $table->foreignId('feito_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('feito_em')->nullable();

                $table->timestamp('auto_em')
                    ->nullable()
                    ->comment('Momento em que um resolver automático concluiu o passo sozinho (distinto de feito_em, que é conclusão manual)');

                $table->timestamp('disponivel_em')
                    ->nullable()
                    ->comment('Momento em que o passo ficou acionável (onboarding saiu de rascunho, para passos sem dependência; ou todas as depende_de concluíram). É a origem da contagem de "há quantos dias" do painel — nunca contar do created_at do onboarding.');

                $table->timestamp('coleta_iniciada_em')
                    ->nullable()
                    ->comment('Quando o resolver disparou uma coleta assíncrona; alimenta o watchdog visual de "coleta demorando mais que o esperado". É timestamp de DISPARO, carimbado uma única vez: reavaliação que apenas reconfirma a coleta em curso não reescreve o valor, senão o relógio nunca envelhece e nem o redisparo nem o watchdog de 30 min chegam a disparar.');

                $table->unsignedSmallInteger('tentativas')->default(0);

                $table->string('ultimo_erro', 255)->nullable();

                $table->timestamps();

                // Nunca duas linhas do mesmo template_passo dentro do mesmo onboarding.
                $table->unique(['onboarding_id', 'template_passo_id']);

                // O comando de reavaliação varre por aqui (status aguardando_coleta/indeterminado).
                $table->index(['status', 'disponivel_em']);

                $table->index(['chave']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 3 — onboarding_links
        // Um token por EMPRESA (D-06), agregando os passos dono=cliente de
        // TODOS os onboardings dela — não um token por onboarding. Mesma forma
        // de "1 token por dono" de mlb_implementacoes.token, trocando empresa_id
        // (MlbEmpresa) por company_id (Company).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('onboarding_links')) {
            Schema::create('onboarding_links', function (Blueprint $table) {
                $table->id();

                $table->foreignId('company_id')
                    ->unique()
                    ->constrained('companies')
                    ->cascadeOnDelete();

                $table->string('token', 64)->unique();

                $table->timestamp('ultimo_acesso')->nullable();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverte na ORDEM REVERSA de dependência FK (idempotente via dropIfExists).
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_links');
        Schema::dropIfExists('onboarding_passos');
        Schema::dropIfExists('onboardings');
    }
};
