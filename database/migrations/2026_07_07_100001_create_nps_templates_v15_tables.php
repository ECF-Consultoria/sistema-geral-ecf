<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 68 Plan 01 — Cria as 5 tabelas novas do schema NPS Templates (v15.0).
 *
 * Fundacao de dados da milestone v15.0: templates NPS configuraveis pelo admin
 * (varias variantes por perfil de servico) + snapshot per-row congelado no
 * momento da resposta (mudancas futuras no template NAO alteram historico).
 *
 * Requisitos atendidos:
 *  - NPS-A-01 — schema base do modulo NPS Templates
 *  - NPS-A-04 — snapshot per-row de perguntas/opcoes em nps_response_answers
 *
 * Referencias:
 *  - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
 *  - .planning/research/v15-nps-templates-schema.md §4 (priority + is_default fallback)
 *  - .planning/research/v15-nps-templates-schema.md §5 (escala como acucar sintatico)
 *  - database/migrations/2026_06_12_100001_create_nps_perguntas_customizadas_table.php (padrao de indice + docblock)
 *  - database/migrations/2026_06_12_100002_create_nps_respostas_customizadas_table.php (padrao snapshot per-row)
 *
 * Tabelas criadas (na ordem — respeita dependencia de FK):
 *  1. nps_templates                — dono da configuracao (nome, active, priority, is_default, envio_automatico_mensal)
 *  2. nps_template_questions       — perguntas do template (texto, tipo escala|opcoes, dimensao, ordem)
 *  3. nps_template_options         — opcoes de cada pergunta (label + peso 1..5)
 *  4. nps_template_service_scopes  — pivot template x servico (define quais servicos aplicam qual template)
 *  5. nps_response_answers         — snapshot per-row das respostas + FK viva
 *
 * Idempotencia: guards `Schema::hasTable` antes de cada `create`.
 * Reversao (down): dropa em ORDEM REVERSA de dependencia FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ────────────────────────────────────────────────────────────────────
        // TABELA 1 — nps_templates
        // Dono da configuracao. Cada template define UM conjunto de perguntas/opcoes.
        // Admin pode ter varios templates ativos e usar priority + service_scopes
        // para resolver qual template aplica a cada empresa (NpsTemplateService, Phase 69).
        // Fallback quando nenhum servico bate: template com is_default=true (unique parcial).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_templates')) {
            Schema::create('nps_templates', function (Blueprint $table) {
                $table->id();

                // Titulo visivel ao admin no CRUD (Phase 70).
                $table->string('nome', 200)
                    ->comment('Titulo do template exibido no CRUD admin');

                // Descricao interna — nao aparece pro cliente respondente.
                $table->text('descricao')
                    ->nullable()
                    ->comment('Notas internas do admin sobre o template');

                // Soft flag — nunca hard-delete templates com historico associado.
                $table->boolean('active')
                    ->default(true)
                    ->comment('true = template disponivel para novos disparos');

                // Fallback quando nenhum servico da empresa bate service_scopes.
                // Unique index parcial abaixo garante que APENAS 1 template pode ser default.
                $table->boolean('is_default')
                    ->default(false)
                    ->comment('true = template usado como fallback (research §4)');

                // Usado em NpsTemplateService::resolveForCompany via ORDER BY priority DESC.
                $table->integer('priority')
                    ->default(0)
                    ->comment('Precedencia entre templates aplicaveis; maior vence');

                // Hint para o comando nps:disparar-mensal (Phase 69/72).
                $table->boolean('envio_automatico_mensal')
                    ->default(false)
                    ->comment('true = incluir template no ciclo mensal automatizado');

                $table->timestamps();

                // Acelera resolveForCompany: WHERE active=true ORDER BY priority DESC.
                $table->index(['active', 'priority'], 'nps_templates_active_priority_idx');
            });

            // Unique parcial em is_default — split por driver (research §4).
            // Semantica: multiplos rows com is_default=false NAO colidem;
            // tentativa de INSERT/UPDATE de 2o row com is_default=true dispara QueryException 23000.
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                // SQLite 3.31+ suporta partial unique index direto.
                DB::statement("CREATE UNIQUE INDEX nps_templates_default_uniq ON nps_templates(is_default) WHERE is_default = 1");
            } else {
                // MySQL 5.7+ / MariaDB 10.2+: coluna virtual gerada + unique nela.
                // CASE retorna NULL quando is_default=0; NULL nao colide com NULL em unique.
                DB::statement("ALTER TABLE nps_templates ADD COLUMN is_default_key TINYINT GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 END) VIRTUAL");
                DB::statement("ALTER TABLE nps_templates ADD UNIQUE INDEX nps_templates_default_uniq (is_default_key)");
            }
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 2 — nps_template_questions
        // Perguntas do template (texto, tipo, dimensao). Tipo `escala` eh acucar
        // sintatico da UI admin — backend uniforme via `options` (research §5).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_template_questions')) {
            Schema::create('nps_template_questions', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar o template apaga todas as perguntas junto.
                $table->foreignId('template_id')
                    ->constrained('nps_templates')
                    ->cascadeOnDelete();

                // Enunciado renderizado no form publico. Limite folgado para textos descritivos.
                $table->string('texto', 500)
                    ->comment('Enunciado da pergunta exibido ao cliente');

                // Tipo `escala` = admin cria 1 clique + 5 options auto-geradas (Phase 70).
                // Backend uniforme: renderiza igual radio group em ambos os casos (research §5).
                $table->enum('tipo', ['escala', 'opcoes'])
                    ->comment('escala = 5 opcoes auto-geradas 1..5; opcoes = livre (research §5)');

                // Dashboard NPS-B-02 filtra por dimensao no NpsScoreCalculator.
                // `geral` cobre perguntas sem eixo especifico (ex: comentario final).
                $table->enum('dimensao', ['estrategista', 'analista', 'empresa', 'geral'])
                    ->comment('Eixo semantico usado no NpsScoreCalculator (Phase 69, NPS-B-02)');

                // Se true, submitResponse exige resposta pra esta pergunta.
                $table->boolean('obrigatoria')
                    ->default(true)
                    ->comment('true = validacao no submit exige resposta');

                // Ordem ASC de renderizacao no form publico.
                $table->integer('ordem')
                    ->default(0)
                    ->comment('Ordem ASC no form publico; empate por id');

                $table->timestamps();

                // Acelera render do form publico + preview no admin.
                $table->index(['template_id', 'ordem']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 3 — nps_template_options
        // Opcoes de cada pergunta com label visivel + peso 1..5 usado no AVG
        // do NpsScoreCalculator (via option_peso_snapshot em nps_response_answers).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_template_options')) {
            Schema::create('nps_template_options', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a pergunta apaga as opcoes.
                $table->foreignId('question_id')
                    ->constrained('nps_template_questions')
                    ->cascadeOnDelete();

                // Texto exibido ao cliente no radio (ex: "1", "Concordo totalmente").
                $table->string('label', 200)
                    ->comment('Label exibido ao cliente no radio group');

                // Peso 1..5 usado em AVG(option_peso_snapshot) — NPS-B-02.
                // UI admin bloqueia > 5 ate nova REQ (research §5).
                $table->unsignedTinyInteger('peso')
                    ->comment('Peso 1-5 usado em AVG do NpsScoreCalculator');

                // Ordem ASC de renderizacao das opcoes no form publico.
                $table->integer('ordem')
                    ->default(0)
                    ->comment('Ordem ASC das opcoes no radio group');

                $table->timestamps();

                // Acelera carregamento das opcoes ordenadas por pergunta.
                $table->index(['question_id', 'ordem']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 4 — nps_template_service_scopes (pivot)
        // Define quais servicos aplicam qual template. Empresa com [Gestao, Mentoria]
        // recebe o template com maior priority entre os aplicaveis (research §4).
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_template_service_scopes')) {
            Schema::create('nps_template_service_scopes', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar template limpa a pivot inteira.
                $table->foreignId('template_id')
                    ->constrained('nps_templates')
                    ->cascadeOnDelete();

                // FK cascade — apagar servico limpa entradas orfas.
                $table->foreignId('servico_id')
                    ->constrained('servicos')
                    ->cascadeOnDelete();

                $table->timestamps();

                // Impede pivot duplicada (mesmo template x servico 2x).
                $table->unique(['template_id', 'servico_id'], 'nps_tpl_scope_uniq');

                // Reverse lookup: WHERE servico_id IN (...) do resolveForCompany.
                $table->index(['servico_id', 'template_id']);
            });
        }

        // ────────────────────────────────────────────────────────────────────
        // TABELA 5 — nps_response_answers
        // Snapshot congelado per-row (research §1 — replica padrao
        // nps_respostas_customizadas). FK viva coexiste para audit mas dashboard
        // le do snapshot; edicoes futuras do template NAO afetam historico.
        // ────────────────────────────────────────────────────────────────────
        if (! Schema::hasTable('nps_response_answers')) {
            Schema::create('nps_response_answers', function (Blueprint $table) {
                $table->id();

                // FK cascade — apagar a NpsResponse apaga as answers junto.
                $table->foreignId('response_id')
                    ->constrained('nps_responses')
                    ->cascadeOnDelete();

                // FK viva pra pergunta — nullOnDelete permite hard-delete
                // da pergunta preservando o snapshot como fonte de verdade historica.
                $table->foreignId('template_question_id')
                    ->nullable()
                    ->constrained('nps_template_questions')
                    ->nullOnDelete();

                // FK viva pra opcao — mesma semantica.
                $table->foreignId('template_option_id')
                    ->nullable()
                    ->constrained('nps_template_options')
                    ->nullOnDelete();

                // ─── Snapshot congelado no submit (research §1) ───
                // Estas colunas sao a FONTE de leitura do dashboard/NpsScoreCalculator.
                $table->string('question_texto_snapshot', 500)
                    ->comment('Texto da pergunta congelado no momento da resposta');

                $table->enum('question_dimensao_snapshot', ['estrategista', 'analista', 'empresa', 'geral'])
                    ->comment('Dimensao congelada — dashboard NPS-E-05 filtra por isso');

                $table->string('option_label_snapshot', 200)
                    ->comment('Label da opcao selecionada congelado');

                $table->unsignedTinyInteger('option_peso_snapshot')
                    ->comment('Peso da opcao congelado — AVG deste campo alimenta o score');

                // Comentario livre opcional (research §1).
                $table->text('comentario')
                    ->nullable()
                    ->comment('Comentario livre opcional do respondente');

                $table->timestamps();

                // Indice composto — acelera NpsScoreCalculator::compute() (NPS-B-02).
                // Query padrao: WHERE response_id IN (...) GROUP BY question_dimensao_snapshot.
                $table->index(
                    ['response_id', 'question_dimensao_snapshot'],
                    'nps_ans_response_dim_idx'
                );
            });
        }
    }

    /**
     * Reverte na ORDEM REVERSA de dependencia FK (idempotente via dropIfExists).
     *
     * Ordem obrigatoria:
     *  1. nps_response_answers          — depende de responses/questions/options
     *  2. nps_template_service_scopes   — depende de templates/servicos
     *  3. nps_template_options          — depende de questions
     *  4. nps_template_questions        — depende de templates
     *  5. nps_templates                 — raiz
     */
    public function down(): void
    {
        Schema::dropIfExists('nps_response_answers');
        Schema::dropIfExists('nps_template_service_scopes');
        Schema::dropIfExists('nps_template_options');
        Schema::dropIfExists('nps_template_questions');
        Schema::dropIfExists('nps_templates');
    }
};
