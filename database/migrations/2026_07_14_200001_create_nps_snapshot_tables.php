<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 79 Plan 01 (DEC-79-C) — Cria as 3 tabelas do SNAPSHOT CONGELADO do NPS
 * multi-modelo (milestone v16.0).
 *
 * Estas tabelas são o CONTRATO que o `NpsSnapshotService` (Plano 79-04) preenche
 * no submit da resposta e que a Fase 80 (bônus/relatórios) consome. Congelam, no
 * momento da resposta e de forma IMUTÁVEL (append-only, sem edição/LogsActivity):
 *
 *  1. nps_response_scores           — a média por dimensão (estrategista/analista/empresa)
 *  2. nps_response_covered_services — quais serviços a empresa tinha ativos naquele NPS
 *  3. nps_score_assignments         — a atribuição média×pessoa×role×serviço (base do bônus)
 *
 * Por que congelar? Mudanças futuras no template, na carteira (`company_users`) ou
 * no catálogo de serviços NÃO podem reescrever o histórico já respondido — o bônus
 * da Fase 80 precisa ler exatamente o que valia no dia da resposta.
 *
 * Padrão cross-driver (SQLite dos testes V16 + MySQL/MariaDB de produção):
 *  - `foreignId()->constrained()` em `Schema::create` funciona nos dois drivers
 *    (o SQLite aceita FK em CREATE TABLE; o gotcha 1553 só aparece em ALTER de
 *    índice usado por FK — não é o caso aqui). Ver 79-RESEARCH Pattern 1.
 *  - `enum` simples é cross-driver (Pitfall 5) — os testes/seed sempre gravam
 *    valores válidos; NÃO usamos virtualAs/índice parcial aqui (desnecessário).
 *
 * ORDEM DE CRIAÇÃO (respeita dependência de FK cross-driver — RESEARCH nota 104):
 *  scores → covered_services → assignments (assignments referencia scores).
 *
 * Referências:
 *  - .planning/phases/79-.../79-CONTEXT.md (DEC-79-C)
 *  - .planning/phases/79-.../79-RESEARCH.md Pattern 1 (migration cross-driver)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php (molde v15)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────────
        // TABELA 1 — nps_response_scores
        // Média congelada por dimensão. 1 linha por (resposta, dimensão).
        // `question_count` = nº de perguntas do template naquela dimensão (NÃO
        // count de answers — ver NpsScoreCalculator); `average_score` = média final.
        // NÃO inclui a dimensão 'geral' (não gera score de pessoa nem atribuição).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_response_scores')) {
            Schema::create('nps_response_scores', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a NpsResponse apaga os scores junto.
                $table->foreignId('nps_response_id')
                    ->constrained('nps_responses')
                    ->cascadeOnDelete();

                // FK cascade — snapshot amarrado à empresa da resposta.
                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                // Só as 3 dimensões que geram score de pessoa (sem 'geral').
                // Deve casar com question_dimensao_snapshot de nps_response_answers.
                $table->enum('dimensao', ['estrategista', 'analista', 'empresa'])
                    ->comment('Dimensão do score congelado (sem geral — não atribui)');

                // SUM(option_peso_snapshot) das answers daquela dimensão.
                $table->decimal('score_sum', 8, 2)
                    ->comment('Soma dos pesos das respostas na dimensão');

                // Nº de perguntas do template na dimensão (denominador do cálculo).
                $table->unsignedInteger('question_count')
                    ->comment('Qtd de perguntas do template na dimensão (denominador)');

                // score_sum / question_count — média final congelada.
                $table->decimal('average_score', 5, 2)
                    ->comment('Média congelada da dimensão (score_sum / question_count)');

                // Instante do cálculo do snapshot (auditoria).
                $table->timestamp('calculated_at')
                    ->comment('Momento em que o snapshot da média foi calculado');

                $table->timestamps();

                // 1 linha por dimensão por resposta.
                $table->unique(['nps_response_id', 'dimensao'], 'nps_resp_scores_dim_uniq');

                // Acelera agregações por empresa+dimensão (dashboards/bônus Fase 80).
                $table->index(['company_id', 'dimensao']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 2 — nps_response_covered_services
        // Serviços que a empresa tinha ATIVOS no momento da resposta. Congela o
        // `service_setor` (Servico::SETOR_*) para não depender de edições futuras
        // do catálogo. 1 linha por (resposta, serviço).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_response_covered_services')) {
            Schema::create('nps_response_covered_services', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a NpsResponse apaga os serviços cobertos.
                $table->foreignId('nps_response_id')
                    ->constrained('nps_responses')
                    ->cascadeOnDelete();

                // FK viva pro catálogo — nullOnDelete preserva o snapshot (service_setor)
                // mesmo se o serviço for removido do catálogo depois.
                // nullable() OBRIGATÓRIO: o MySQL exige coluna NULLABLE quando a FK é
                // ON DELETE SET NULL (erro 1830) — o SQLite dos testes não pega.
                $table->foreignId('servico_id')
                    ->nullable()
                    ->constrained('servicos')
                    ->nullOnDelete();

                // Setor do serviço congelado (performance/shopee/...) — fonte histórica.
                $table->string('service_setor')
                    ->comment('Setor do serviço congelado (Servico::SETOR_*)');

                // Instante da captura do snapshot (auditoria).
                $table->timestamp('captured_at')
                    ->comment('Momento em que a cobertura de serviço foi capturada');

                $table->timestamps();

                // 1 linha por serviço por resposta.
                $table->unique(['nps_response_id', 'servico_id'], 'nps_resp_cov_serv_uniq');
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 3 — nps_score_assignments
        // Atribuição congelada: liga a média (nps_response_scores) à PESSOA
        // responsável (user) naquele serviço/role no dia da resposta. Base direta
        // do cálculo de bônus da Fase 80 (JOIN por user_id + role).
        //
        // DECISÃO TRAVADA (DEC-79-C / A3 do RESEARCH): a coluna `role` PERSISTE o
        // valor da pivot `company_users` — 'consultor' (=Analista) e 'estrategista'
        // — para JOIN direto na Fase 80. NÃO usar analyst/strategist.
        // Criada por ÚLTIMO: referencia nps_response_scores (ordem = FK cross-driver).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_score_assignments')) {
            Schema::create('nps_score_assignments', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a NpsResponse apaga as atribuições.
                $table->foreignId('nps_response_id')
                    ->constrained('nps_responses')
                    ->cascadeOnDelete();

                // FK cascade pro score congelado — apagar o score apaga a atribuição.
                // (belongsTo em NpsScoreAssignment via nps_response_score_id.)
                $table->foreignId('nps_response_score_id')
                    ->constrained('nps_response_scores')
                    ->cascadeOnDelete();

                // FK cascade — snapshot amarrado à empresa.
                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                // FK viva pro catálogo — nullOnDelete preserva o snapshot.
                // nullable() OBRIGATÓRIO (MySQL erro 1830 com ON DELETE SET NULL).
                $table->foreignId('servico_id')
                    ->nullable()
                    ->constrained('servicos')
                    ->nullOnDelete();

                // Setor do serviço congelado (idem covered_services).
                $table->string('service_setor')
                    ->comment('Setor do serviço congelado (Servico::SETOR_*)');

                // Valor da pivot company_users: 'consultor' (=Analista) ou 'estrategista'.
                $table->enum('role', ['consultor', 'estrategista'])
                    ->comment("Role da pivot company_users ('consultor'=Analista, 'estrategista')");

                // FK cascade — a pessoa responsável no momento da resposta.
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                // Média congelada atribuída a esta pessoa (espelha o score da dimensão).
                $table->decimal('average_score', 5, 2)
                    ->comment('Média congelada atribuída à pessoa neste serviço/role');

                // Instante da atribuição (auditoria).
                $table->timestamp('assigned_at')
                    ->comment('Momento em que a atribuição foi congelada');

                $table->timestamps();

                // Acelera o JOIN de bônus da Fase 80 (por pessoa+role).
                $table->index(['user_id', 'role'], 'nps_score_assign_user_role_idx');

                // Acelera agregações por setor.
                $table->index(['service_setor'], 'nps_score_assign_setor_idx');
            });
        }
    }

    /**
     * Reverte na ORDEM REVERSA de dependência de FK (idempotente via dropIfExists):
     *  assignments (→ scores) → covered_services → scores (raiz das FKs internas).
     */
    public function down(): void
    {
        Schema::dropIfExists('nps_score_assignments');
        Schema::dropIfExists('nps_response_covered_services');
        Schema::dropIfExists('nps_response_scores');
    }
};
