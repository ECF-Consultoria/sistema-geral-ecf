<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31 Plan 02 — Torna nps_surveys.generated_by NULLABLE.
 *
 * O comando `nps:disparar-mensal` (Plan 02) cria surveys SEM um usuário
 * humano por trás (são disparos automáticos no aniversário do cadastro).
 * Manter `generated_by` NOT NULL exigiria atribuir um admin "fantasma" a
 * cada survey automática, o que polui o audit log (LogsActivity em
 * NpsSurvey já filtra logOnly por dirty, mas a UI de "Quem gerou" do
 * /nps acabaria mostrando o admin escolhido para cada survey mensal).
 *
 * Schema original: foreignId('generated_by')->constrained('users')->cascadeOnDelete()
 * Aqui: vira foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete()
 *
 * Surveys MANUAIS criadas via /nps.generate continuam recebendo o id do
 * admin/consultor que clicou — `generated_by` preserva o autor humano
 * quando ele existe. Surveys AUTOMATICAS (auto_generated=true) ficam com
 * `generated_by = NULL`, semanticamente correto.
 *
 * Decisão técnica tomada durante execução (não estava no plan): o plan
 * sugeria fallback "primeiro admin", mas tornar nullable é arquitetonicamente
 * mais limpo (zero acoplamento ao usuário específico, integridade FK
 * preservada, audit log limpo).
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Models/NpsSurvey.php
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nps_surveys', function (Blueprint $table) {
            // Drop FK existente antes de alterar a coluna (MySQL exige isso)
            $table->dropForeign(['generated_by']);
            $table->unsignedBigInteger('generated_by')->nullable()->change();
            // Recria FK com nullOnDelete — quando o admin é deletado, mantém survey
            // com generated_by=NULL em vez de cascadear e perder histórico.
            $table->foreign('generated_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Reversão best-effort: surveys com generated_by=NULL ficariam órfãs
        // se o down rodasse em prod, mas testes locais aceitam.
        Schema::table('nps_surveys', function (Blueprint $table) {
            $table->dropForeign(['generated_by']);
            $table->unsignedBigInteger('generated_by')->nullable(false)->change();
            $table->foreign('generated_by')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};
