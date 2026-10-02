<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kit de 7 criativos de imagem por IA do publicador ML (Fase 161).
 *
 * A Fase 160 (migration `2026_10_02_120000_...`) já previa isto: "quando a
 * Fase 161 trouxer o kit de 7, a agregação entra como tabela nova + FK
 * anulável aqui — migration aditiva, sem reescrever linha existente." É
 * exatamente isto. **Há 1 linha em produção** (criativo id 1, aprovado, sem
 * kit) — nenhuma coluna nova em qualquer das duas migrations desta fase é
 * NOT NULL sem default.
 *
 * `status`/`etapa`/`plano_origem` são STRING, nunca `enum` (mesma regra da
 * 160-01: enum em migration exige branch de SQLite e já quebrou deploy
 * aqui). Toda FK `nullOnDelete()` tem `->nullable()` EXPLÍCITO (erro 1830 do
 * MariaDB, invisível no SQLite — o guarda `KitMigrationGuardaTest` confere
 * isto por grep no conteúdo do arquivo). Índices NOMEADOS à mão e curtos
 * (erro 1059 do MariaDB acima de 64 chars).
 *
 * ORDEM DAS DUAS MIGRATIONS DESTA FASE: esta cria a tabela nova apontando
 * para `ml_anuncio_criativos` (que já existe); a próxima
 * (`2026_10_03_090100_...`) só então acrescenta a FK de volta
 * (`ml_anuncio_criativos.kit_id`) — nunca o contrário, ou a referência
 * circular não teria como ser criada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_anuncio_criativo_kits')) {
            return;
        }

        Schema::create('ml_anuncio_criativo_kits', function (Blueprint $table) {
            $table->id();

            // Token de 32 caracteres: é por ele (não pelo id sequencial) que
            // a tela consulta o kit — mesma regra de FOTO-02.
            $table->char('token', 32);

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas')->nullOnDelete();
            $table->foreignId('rascunho_id')->nullable()->constrained('ml_anuncio_rascunhos')->nullOnDelete();
            // Quem pediu o kit. Mesmo padrão de ml_anuncio_criativos.user_id:
            // se a pessoa sair da ECF, o kit sobrevive — é insumo do
            // anúncio, não registro pessoal.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // O criativo do upload (160-01) que PORTA a foto de referência
            // em disco (Decisão 1b do 161-01-PLAN.md) — nunca um dos 7 slots.
            $table->foreignId('criativo_referencia_id')->nullable()->constrained('ml_anuncio_criativos')->nullOnDelete();

            // ─── Execução ───
            $table->string('status', 20)->default('planejando'); // planejando|planejado|gerando|parcial|pronto|aprovado|erro
            $table->string('etapa', 30)->nullable();
            $table->text('erro_mensagem')->nullable();

            // ─── Plano (PLAN-01/02/03/04) ───
            $table->json('plano')->nullable();           // estratégia + slots — forma de CreativePlan::paraAuditoria()
            $table->string('plano_origem', 20)->nullable(); // llm|deterministico
            $table->string('planner_provider', 30)->nullable();
            $table->string('planner_modelo', 120)->nullable(); // qual modelo de fato respondeu
            $table->unsignedInteger('planner_latencia_ms')->nullable();
            $table->unsignedTinyInteger('planner_tentativas')->default(0);

            // ─── Agregação do kit ───
            $table->unsignedTinyInteger('total_slots')->default(0);
            // Congelado no kit na hora do planejamento (não lido do config a
            // cada leitura) — mudar o default global não altera kit já criado.
            $table->unsignedTinyInteger('minimo_aprovadas')->default(3);
            $table->unsignedSmallInteger('imagens_geradas')->default(0);
            $table->unsignedSmallInteger('regeneracoes')->default(0);

            // ─── Aprovação do kit ───
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'ml_criativo_kits_token_unq');
            $table->index(['rascunho_id', 'status'], 'ml_criativo_kits_rascunho_status_idx');
            $table->index('created_at', 'ml_criativo_kits_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_anuncio_criativo_kits');
    }
};
