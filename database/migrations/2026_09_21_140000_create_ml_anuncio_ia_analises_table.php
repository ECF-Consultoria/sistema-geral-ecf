<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Análises de anúncio geradas por IA (metodologia MAG T8, Parte 1).
 *
 * Tabela própria, não coluna no rascunho, por dois motivos:
 *  - a análise nasce ANTES do rascunho existir (é ela que preenche o wizard);
 *  - rodar de novo não pode apagar a anterior — o publicador compara e escolhe.
 *
 * Âncora dupla (`company_id` / `mlb_empresa_id`), mesma disciplina de
 * `ml_tokens`: exatamente uma preenchida, garantida no service.
 *
 * `status` é STRING e não `enum` — enum em migration exige branch de SQLite e
 * já quebrou deploy aqui antes (learnings de bonificação §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_anuncio_ia_analises', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas')->cascadeOnDelete();
            // Quem pediu. Se a pessoa sair da ECF a análise sobrevive — ela é
            // insumo do anúncio, não registro pessoal.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // ─── Entrada (o que o publicador digitou) ───
            $table->string('produto', 300);
            $table->string('loja', 200)->nullable();
            $table->text('specs')->nullable();

            // ─── Execução ───
            $table->string('status', 20)->default('pendente');   // pendente|rodando|concluido|erro
            $table->text('erro_mensagem')->nullable();
            $table->string('modelo', 120)->nullable();           // qual modelo de fato respondeu
            $table->unsignedInteger('tokens_entrada')->nullable();
            $table->unsignedInteger('tokens_saida')->nullable();
            $table->unsignedInteger('duracao_ms')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);

            // ─── Saída (analise + titulos + descricao) ───
            $table->json('resultado')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['mlb_empresa_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_anuncio_ia_analises');
    }
};
