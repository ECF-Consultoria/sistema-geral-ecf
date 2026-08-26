<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piso de nota 1 para link de NPS de GRUPO pendente (2026-08-26).
 *
 * O que estava quebrado: `NpsImputationService::materializar()` recebe um
 * `NpsSurvey`, e o link de grupo só vira `nps_surveys` quando o cliente
 * RESPONDE (`NpsGrupoReplicacaoService::replicar()`). Entre gerar e
 * responder, o link de grupo não existia para a imputação — as empresas
 * cobertas não constavam com o piso de 1 que qualquer link individual já
 * produzia no mesmo instante do disparo. Medido em produção em 26/08/2026:
 * 4 links de grupo pendentes, 9 empresas sem o piso.
 *
 * Por que a coluna nova em vez de criar os espelhos no disparo: espelho
 * nasce com token próprio e viraria link individual na tela (o cliente
 * receberia N links em vez de 1), e inverteria a decisão de recalcular a
 * cobertura NO SUBMIT — que é o que garante que o navegador nunca decide
 * quem recebe a nota (ver docblock de `NpsGrupoReplicacaoService`).
 *
 * `survey_id` vira NULLABLE: a linha passa a ser amarrada OU a um survey
 * individual OU a um link de grupo, nunca aos dois. Quem escreve
 * (`NpsImputationService`) garante a exclusividade.
 *
 * O unique `nps_imput_grao_uniq` NÃO é refeito de propósito: ele já era
 * best-effort desde a Fase 116 (em MySQL/MariaDB NULLs são distintos entre
 * si, e `role`/`user_id`/`servico_id` já nascem nullable), e é por isso que
 * o serviço SEMPRE faz o guard `exists()` em PHP antes de criar linha. O
 * guard foi estendido para o grão de grupo junto com esta migration.
 *
 * @see app/Services/Nps/NpsImputationService.php (única fonte de escrita)
 * @see database/migrations/2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php (mesmo padrão de drop/change/recreate de FK)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Alteração da coluna existente isolada da adição da coluna nova: no
        // SQLite dos testes cada uma dispara uma reconstrução de tabela, e
        // misturar as duas no mesmo Blueprint torna a ordem indefinida.
        Schema::table('nps_imputed_assignments', function (Blueprint $table) {
            // MySQL exige derrubar a FK antes de alterar a coluna.
            $table->dropForeign(['survey_id']);
            $table->unsignedBigInteger('survey_id')->nullable()->change();
            $table->foreign('survey_id')
                ->references('id')->on('nps_surveys')
                ->cascadeOnDelete();
        });

        Schema::table('nps_imputed_assignments', function (Blueprint $table) {
            // ARMADILHA MySQL 1830 (a mesma que quebrou a Fase 79 e está
            // documentada na migration de criação desta tabela): FK com
            // cascadeOnDelete/nullOnDelete EXIGE ->nullable() na coluna.
            $table->foreignId('group_survey_id')
                ->nullable()
                ->constrained('nps_group_surveys')
                ->cascadeOnDelete();

            $table->index(['group_survey_id', 'company_id'], 'nps_imput_group_company_idx');
        });
    }

    public function down(): void
    {
        // Linha de grupo não tem survey — precisa sair ANTES de o NOT NULL
        // voltar, senão o ALTER falha com dado em produção.
        DB::table('nps_imputed_assignments')->whereNull('survey_id')->delete();

        Schema::table('nps_imputed_assignments', function (Blueprint $table) {
            // ORDEM OBRIGATÓRIA no MariaDB: a FK se apoia no índice composto,
            // e derrubar o índice primeiro dá "1553 Cannot drop index ...:
            // needed in a foreign key constraint". O SQLite dos testes aceita
            // as duas ordens — só o rollback no MariaDB acusa.
            $table->dropForeign(['group_survey_id']);
            $table->dropIndex('nps_imput_group_company_idx');
            $table->dropColumn('group_survey_id');
        });

        Schema::table('nps_imputed_assignments', function (Blueprint $table) {
            $table->dropForeign(['survey_id']);
            $table->unsignedBigInteger('survey_id')->nullable(false)->change();
            $table->foreign('survey_id')
                ->references('id')->on('nps_surveys')
                ->cascadeOnDelete();
        });
    }
};
