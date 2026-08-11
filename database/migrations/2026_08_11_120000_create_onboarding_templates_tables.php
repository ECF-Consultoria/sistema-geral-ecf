<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 135 Plano 02 — Cria as tabelas de TEMPLATE do motor de Onboarding geral.
 *
 * Molde: database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 * (guarda Schema::hasTable, down() reverso, índice único parcial multi-driver).
 *
 * Nasce ao lado do onboarding de Polos (mlb_implementacoes) — D-02: coexistência
 * intocada, nenhuma tabela ou model de Polos é tocado nesta migration.
 *
 * Tabelas criadas (ordem respeita dependência de FK):
 *  1. onboarding_templates — dono da versão (servico_id, versao, ativo, publicado_em)
 *  2. template_passos      — passos do template (dono, setor, dependências, SLA, automação)
 *
 * Versionamento é IMUTÁVEL POR LINHA NOVA (D-07/SC-09): publicar uma versão N+1 nunca
 * faz UPDATE em template_passos de uma versão já publicada — insere linhas novas com
 * template_id novo. Só uma linha `ativo=true` pode existir por servico_id, garantido
 * pelo banco (índice único parcial composto), não pela aplicação.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────────
        // TABELA 1 — onboarding_templates
        // Uma linha por VERSÃO de template de um serviço. `ativo=true` marca a
        // versão vigente; onboardings em andamento ficam presos ao template_id
        // em que nasceram (D-07) mesmo depois de uma versão nova ser publicada.
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('onboarding_templates')) {
            Schema::create('onboarding_templates', function (Blueprint $table) {
                $table->id();

                $table->foreignId('servico_id')
                    ->constrained('servicos')
                    ->cascadeOnDelete();

                $table->unsignedInteger('versao')
                    ->comment('Número incremental da versão dentro do servico_id — 1, 2, 3... nunca reaproveitado');

                $table->boolean('ativo')
                    ->default(false)
                    ->comment('true = versão vigente do servico_id; só 1 pode ser true por serviço (índice único parcial abaixo)');

                $table->timestamp('publicado_em')
                    ->nullable()
                    ->comment('Momento em que esta versão foi publicada (virou ativa); null = rascunho de template nunca publicado');

                $table->foreignId('publicado_por')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                // Nunca duas linhas com a mesma versão para o mesmo serviço.
                $table->unique(['servico_id', 'versao']);
            });

            // Índice único parcial COMPOSTO: só 1 linha `ativo=1` por `servico_id`
            // (D-07/SC-09) — garantido pelo banco nos dois drivers, nunca só pela
            // aplicação. Molde: nps_templates_default_uniq (linhas 90-99 do arquivo
            // acima), adaptado de "1 default global" para "1 ativo por serviço".
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                // SQLite 3.31+ suporta partial unique index direto.
                DB::statement(
                    'CREATE UNIQUE INDEX onboarding_templates_ativo_uniq ON onboarding_templates(servico_id) WHERE ativo = 1'
                );
            } else {
                // MySQL 5.7+ / MariaDB 10.2+: sem índice único parcial nativo —
                // coluna virtual gerada que devolve servico_id quando ativo=1 e
                // NULL caso contrário (NULL não colide com NULL em unique index),
                // com UNIQUE sobre essa coluna.
                DB::statement(
                    'ALTER TABLE onboarding_templates ADD COLUMN ativo_servico_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN ativo = 1 THEN servico_id END) VIRTUAL'
                );
                DB::statement(
                    'ALTER TABLE onboarding_templates ADD UNIQUE INDEX onboarding_templates_ativo_uniq (ativo_servico_key)'
                );
            }
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 2 — template_passos
        // Passo de um template: quem é o dono, de que setor, quais passos
        // (por CHAVE, não id — D-10) precisam concluir antes, prazo, se resolve
        // sozinho e sob que condição nasce. Catálogos fechados (dono, auto_fonte,
        // condicao) vivem como constantes PHP em App\Models\TemplatePasso — o
        // FormRequest do Plano 07 valida contra elas, nunca aceita texto livre (D-09/D-14).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('template_passos')) {
            Schema::create('template_passos', function (Blueprint $table) {
                $table->id();

                $table->foreignId('template_id')
                    ->constrained('onboarding_templates')
                    ->cascadeOnDelete();

                $table->unsignedSmallInteger('ordem')
                    ->comment('Ordem de exibição do passo dentro do template');

                $table->string('chave', 60)
                    ->comment('Identificador estável do passo — sobrevive ao versionamento; é o que depende_de referencia e o que agrega passos de mesma natureza entre serviços diferentes (D-10)');

                $table->string('titulo', 160);

                $table->text('descricao')->nullable();

                $table->string('dono', 20)
                    ->comment('Quem precisa AGIR: cliente | interno | sistema — catálogo fechado em TemplatePasso::DONOS (D-14)');

                $table->foreignId('setor_id')
                    ->nullable()
                    ->constrained('setores')
                    ->nullOnDelete();

                $table->json('depende_de')
                    ->nullable()
                    ->comment('Array de CHAVES (não ids) de outros passos do mesmo template que precisam concluir antes deste nascer acionável');

                $table->unsignedSmallInteger('sla_dias')
                    ->nullable()
                    ->comment('Prazo em dias corridos a partir de disponivel_em; null = sem SLA próprio');

                $table->string('auto_fonte', 60)
                    ->nullable()
                    ->comment('Chave do resolver automático que sabe COMO o sistema verifica a conclusão — catálogo fechado em TemplatePasso::AUTO_FONTES (D-09); null = passo sem automação, conclusão é manual');

                $table->json('condicao')
                    ->nullable()
                    ->comment('Condição que decide se o passo NASCE nesta instância (D-12) — catálogo fechado em TemplatePasso::CONDICOES; null = passo sempre nasce');

                $table->boolean('obrigatorio')
                    ->default(true);

                $table->timestamps();

                // Nunca duas linhas com a mesma chave dentro do mesmo template.
                $table->unique(['template_id', 'chave']);
            });
        }
    }

    /**
     * Reverte na ORDEM REVERSA de dependência FK (idempotente via dropIfExists).
     * Remove explicitamente o índice/coluna gerada do ramo MySQL antes de
     * derrubar a tabela, espelhando a simetria do up().
     */
    public function down(): void
    {
        Schema::dropIfExists('template_passos');

        if (Schema::hasTable('onboarding_templates')) {
            $driver = DB::connection()->getDriverName();
            if ($driver !== 'sqlite' && Schema::hasColumn('onboarding_templates', 'ativo_servico_key')) {
                DB::statement('ALTER TABLE onboarding_templates DROP INDEX onboarding_templates_ativo_uniq');
                DB::statement('ALTER TABLE onboarding_templates DROP COLUMN ativo_servico_key');
            }
        }

        Schema::dropIfExists('onboarding_templates');
    }
};
