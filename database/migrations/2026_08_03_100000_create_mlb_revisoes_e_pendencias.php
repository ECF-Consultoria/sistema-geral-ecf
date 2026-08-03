<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reforma do módulo de Revisão MLB.
 *
 * Antes, revisão era um punhado de flags coladas na linha da publicação:
 * `revisado` (bool, sem autor nem data), `problema` + 4 colunas e `comentario`
 * + 4 colunas. Isso impedia três coisas que o time precisa:
 *
 *  1. O gestor saber O QUE o líder revisou (não existia "quem revisou").
 *  2. Distinguir "foi olhado" de "está correto" — revisar não é aprovar.
 *  3. Ter mais de uma pendência por anúncio, com histórico de reabertura.
 *
 * Aqui separamos os dois fatos que estavam embolados no mesmo registro:
 * `mlb_revisoes` = o líder olhou e deu um veredicto (log de transições);
 * `mlb_pendencias` = algo precisa ser corrigido (o que era "problema" e
 * "comentário", agora uma entidade só com severidade).
 *
 * Os status são `string` e NÃO enum de propósito: enum já derrubou o módulo de
 * NPS em produção quando um valor novo entrou antes da migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Log de cada ato de revisão. É o que dá ao gestor a visão de quem
        // revisou o quê e quando — dado que nunca existiu.
        Schema::create('mlb_revisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacao_id')->constrained('mlb_publicacoes')->cascadeOnDelete();
            $table->foreignId('revisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('de_status', 20)->nullable();
            $table->string('para_status', 20);
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['publicacao_id', 'created_at']);
            $table->index(['revisor_id', 'created_at']);
        });

        // Pendência = o que antes era "problema" (bloqueio) e "comentário"
        // (observação). Mesma entidade: texto, autor, abertura, resolução.
        // A diferença real sempre foi severidade.
        Schema::create('mlb_pendencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacao_id')->constrained('mlb_publicacoes')->cascadeOnDelete();
            $table->foreignId('revisao_id')->nullable()->constrained('mlb_revisoes')->nullOnDelete();

            // bloqueio | ajuste | observacao
            $table->string('severidade', 20)->default('ajuste');
            // foto | titulo | ficha | preco | frete | descricao | variacao | outro
            // Permite ao gestor ver ONDE o time erra mais — hoje isso fica
            // enterrado em texto livre.
            $table->string('categoria', 30)->nullable();
            $table->text('texto')->nullable();

            $table->foreignId('aberta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aberta_em')->nullable();

            // aberta (publicador precisa agir) | corrigida (aguarda o líder) | resolvida
            $table->string('status', 20)->default('aberta');
            $table->foreignId('corrigida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrigida_em')->nullable();
            $table->foreignId('resolvida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolvida_em')->nullable();

            $table->timestamps();

            $table->index(['publicacao_id', 'status']);
            $table->index(['status', 'severidade']);
            $table->index(['aberta_por', 'aberta_em']);
        });

        // Cache desnormalizado na publicação: evita agregação em toda listagem
        // e permite filtrar/ordenar a fila direto no banco.
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            // nao_revisado | aprovado | em_ajuste | reconferir
            $table->string('status_revisao', 20)->default('nao_revisado')->after('revisado');
            $table->foreignId('revisado_por')->nullable()->after('status_revisao')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_em')->nullable()->after('revisado_por');
            $table->unsignedSmallInteger('pendencias_abertas')->default(0)->after('revisado_em');

            $table->index('status_revisao');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_publicacoes', function (Blueprint $table) {
            $table->dropIndex(['status_revisao']);
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['status_revisao', 'revisado_em', 'pendencias_abertas']);
        });

        Schema::dropIfExists('mlb_pendencias');
        Schema::dropIfExists('mlb_revisoes');
    }
};
