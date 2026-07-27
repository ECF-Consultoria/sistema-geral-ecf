<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 116 Plan 01 — Tabela `nps_imputed_assignments`: fundação de dados da
 * regra "NPS não respondido conta como nota mínima (1)".
 *
 * Grão: survey × serviço × dimensão × responsável. Tabela NOVA (não reusa
 * `nps_score_assignments` — lá as FKs `nps_response_id`/`nps_response_score_id`
 * são NOT NULL, não existe linha sem resposta real; ver decisão de arquitetura
 * #1 do 116-01-PLAN.md).
 *
 * `competencia_nps` é a coluna materializada (sempre `startOfMonth`) que
 * resolve `month_reference` do survey com fallback `created_at` quando NULL
 * (D6 · disparo manual) — evita que cada call-site reimplemente esse fallback.
 *
 * `status` (`provisorio`/`definitivo`) é ESTADO GRAVADO, não cálculo ao vivo:
 * é isso que garante D2/NPSFLOOR-07 — uma resposta tardia não apaga a linha
 * definitiva já congelada.
 *
 * Analog exato (mesmo padrão de FK obrigatória/opcional + unique de
 * idempotência): database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php
 *
 * @see .planning/phases/116-.../116-01-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nps_imputed_assignments')) {
            Schema::create('nps_imputed_assignments', function (Blueprint $table) {
                $table->id();

                // FK obrigatória — survey SEMPRE existe por construção (D3: só
                // vira nota 1 o que foi efetivamente disparado). Cascade: apagar
                // o survey apaga a linha imputada junto.
                $table->foreignId('survey_id')
                    ->constrained('nps_surveys')
                    ->cascadeOnDelete();

                // FK obrigatória — snapshot amarrado à empresa do survey.
                $table->foreignId('company_id')
                    ->constrained('companies')
                    ->cascadeOnDelete();

                // FK viva pro catálogo — nullOnDelete preserva a linha mesmo se
                // o serviço for removido depois. NULL para a dimensão empresa
                // (D7 — linha própria, sem serviço/responsável).
                //
                // ARMADILHA MySQL 1830 (quebrou a Fase 79): toda FK com
                // nullOnDelete() EXIGE ->nullable() na coluna. O SQLite dos
                // testes não acusa o erro — só aparece no ALTER do MariaDB do
                // VPS no deploy. Vale para servico_id e user_id abaixo.
                $table->foreignId('servico_id')
                    ->nullable()
                    ->constrained('servicos')
                    ->nullOnDelete();

                // Setor do serviço congelado no momento da materialização
                // (Servico::SETOR_*) — independe de edições futuras do catálogo.
                $table->string('service_setor')->nullable()
                    ->comment('Setor do serviço congelado; NULL na dimensão empresa');

                // ARMADILHA enum + SQLite: `dimensao` e `status` são string(20),
                // NÃO enum — um valor novo no futuro não exige branch por driver
                // (DB::getDriverName() === 'sqlite') como migrations que fazem
                // ->change() num enum já existente. Nascem corretos desde o
                // Schema::create.
                $table->string('dimensao', 20)
                    ->comment('estrategista | analista | empresa');

                // Role da pivot company_users ('consultor'=Analista,
                // 'estrategista') — NULL na dimensão empresa (D7).
                $table->string('role', 20)->nullable();

                // FK viva pra pessoa responsável — nullOnDelete + nullable()
                // (mesma armadilha 1830). NULL na dimensão empresa.
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // Competência do survey (month_reference com fallback
                // created_at, D6), sempre normalizada para o dia 1 do mês.
                $table->date('competencia_nps')
                    ->comment('Mês de referência do survey, sempre startOfMonth');

                // Nota mínima da escala (D4 — escala real é 1..5, não 0..10).
                $table->decimal('nota', 5, 2)->default(1.00);

                // ESTADO GRAVADO (não cálculo ao vivo) — garante D2/NPSFLOOR-07:
                // resposta tardia não reescreve a nota de uma competência já
                // fechada. 'provisorio' enquanto a competência está aberta;
                // 'definitivo' depois que o mês fechou, para sempre.
                $table->string('status', 20)->default('provisorio');

                // Preenchido no instante em que a linha vira definitivo.
                $table->timestamp('locked_at')->nullable();

                $table->timestamps();

                // Idempotência no banco (best-effort — em MySQL NULLs são
                // distintos entre si, por isso o serviço TAMBÉM faz guard
                // exists() em PHP antes de criar qualquer linha).
                $table->unique(
                    ['survey_id', 'dimensao', 'role', 'user_id', 'servico_id'],
                    'nps_imput_grao_uniq',
                );

                // Acelera a leitura por pessoa (notasDoUsuario).
                $table->index(['user_id', 'competencia_nps', 'status'], 'nps_imput_user_comp_idx');

                // Acelera a leitura por empresa (notasDaEmpresa).
                $table->index(['company_id', 'competencia_nps'], 'nps_imput_company_comp_idx');
            });
        }
    }

    /**
     * Caminho de rollback estrutural da fase — dropIfExists é idempotente.
     */
    public function down(): void
    {
        Schema::dropIfExists('nps_imputed_assignments');
    }
};
