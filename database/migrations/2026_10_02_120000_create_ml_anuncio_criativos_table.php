<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Criativos de imagem por IA do publicador ML (Fase 160, Creative Engine).
 *
 * UMA tabela só — par "projeto" + "asset" em inglês foi DESCARTADO (Decisão 1
 * do 160-01-PLAN.md): nesta fase existe exatamente UMA imagem por pedido —
 * uma tabela "projeto" teria sempre uma filha só, com colunas de agregação
 * (totais/aprovados/falhos) fixas em 1/0/0 por construção. Nome em pt-BR,
 * prefixo `ml_`, mesma convenção viva de `ml_anuncio_rascunhos` e
 * `ml_anuncio_ia_analises` — nunca um par de tabelas em inglês.
 *
 * Quando a Fase 161 trouxer o kit de 7, a agregação entra como tabela nova
 * (`ml_anuncio_criativo_kits`) + FK anulável aqui — migration aditiva, sem
 * reescrever linha existente.
 *
 * `status`/`etapa` são STRING, nunca `enum`: enum em migration exige branch
 * de SQLite e já quebrou deploy aqui antes (learnings de bonificação §6).
 * Toda FK `nullOnDelete()` tem `->nullable()` EXPLÍCITO (erro 1830 no
 * MariaDB, invisível no SQLite). Índices NOMEADOS à mão (erro 1059 do
 * MariaDB acima de 64 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_anuncio_criativos')) {
            return;
        }

        Schema::create('ml_anuncio_criativos', function (Blueprint $table) {
            $table->id();

            // Token de 32 caracteres: é por ele (não pelo id sequencial) que a
            // foto de referência é lida — FOTO-02 exige URL não adivinhável.
            $table->char('token', 32);

            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas')->cascadeOnDelete();
            $table->foreignId('rascunho_id')->nullable()->constrained('ml_anuncio_rascunhos')->nullOnDelete();
            // Quem pediu. Se a pessoa sair da ECF o criativo sobrevive — é
            // insumo do anúncio, não registro pessoal (mesmo padrão de
            // ml_anuncio_ia_analises.user_id).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Slot único nesta fatia ('hero'); a Fase 161 traz o kit de 7 slots.
            $table->string('slot', 40)->default('hero');

            // ─── Execução ───
            $table->string('status', 20)->default('pendente');  // pendente|rodando|pronto|aprovado|erro
            $table->string('etapa', 30)->nullable();
            $table->text('erro_mensagem')->nullable();
            $table->string('render_mode', 20)->default('full_ai'); // D-03: só FULL_AI nesta milestone
            $table->string('provider', 30)->nullable();
            $table->string('modelo', 120)->nullable();            // qual modelo de fato respondeu
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->unsignedInteger('latencia_ms')->nullable();

            // ─── Contexto e Product Truth (CTX/TRUTH — consumidos a partir da Fase 160-02) ───
            $table->json('contexto')->nullable();
            $table->json('truth')->nullable();
            $table->text('prompt')->nullable();

            // ─── Referências (FOTO-01/02) — path/mime/bytes/hash, NUNCA base64 ───
            $table->json('referencias')->nullable();
            $table->timestamp('referencias_apagadas_em')->nullable();

            // ─── Resultado ───
            $table->string('imagem_path', 255)->nullable();
            $table->string('imagem_mime', 40)->nullable();
            $table->unsignedInteger('imagem_bytes')->nullable();

            // ─── Aprovação humana (APROV-*) ───
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();

            // ─── Publicação no ML (PUB-*) ───
            $table->string('ml_picture_id', 60)->nullable();
            $table->string('ml_picture_url', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'ml_criativos_token_unq');
            $table->index(['company_id', 'status'], 'ml_criativos_company_status_idx');
            $table->index(['rascunho_id', 'status'], 'ml_criativos_rascunho_status_idx');
            $table->index('created_at', 'ml_criativos_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_anuncio_criativos');
    }
};
