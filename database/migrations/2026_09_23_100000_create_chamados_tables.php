<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chamados — o canal oficial para pedir ajuda ao time de desenvolvimento.
 *
 * Chamado ≠ demanda: o chamado é o pedido de quem precisa de ajuda; a demanda
 * (dev_demandas) é trabalho técnico aceito no backlog. Um chamado pode ser
 * resolvido direto ou virar UMA demanda (`dev_demanda_id` único).
 *
 * Mensagens, anexos e eventos só crescem — nenhuma rota edita ou apaga.
 * Tabelas novas apenas; nada existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chamados', function (Blueprint $table) {
            $table->id();
            // TKT-0001 — derivado do id na mesma transação da criação (nunca repete).
            $table->string('codigo', 20)->nullable()->unique();

            // Quem abriu: sempre o usuário da sessão. Nome/e-mail copiados na abertura
            // para o histórico continuar legível se o cadastro mudar.
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('solicitante_nome', 255);
            $table->string('solicitante_email', 255)->nullable();

            // Nulo = fila da equipe ("Não sei quem deve atender").
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            // Mesma Área / Projeto das demandas dev (texto da lista compartilhada).
            $table->string('area', 60)->nullable();
            $table->string('tipo', 30);
            $table->string('impacto', 30);
            $table->string('titulo', 255);
            $table->text('descricao');
            $table->text('contexto_tentando')->nullable();
            $table->text('contexto_aconteceu')->nullable();
            $table->text('contexto_esperado')->nullable();

            $table->string('status', 30)->default('aberto');
            // Um chamado gera no máximo uma demanda, e uma demanda vem de no máximo um chamado.
            $table->foreignId('dev_demanda_id')->nullable()->unique()->constrained('dev_demandas')->nullOnDelete();
            $table->text('resolucao')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            // Última movimentação visível (mensagem/status) — ordena a caixa de entrada.
            $table->timestamp('ultima_interacao_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'responsavel_id']);
        });

        Schema::create('chamado_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('chamados')->cascadeOnDelete();
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            // publica = o solicitante vê; interna = só a equipe dev.
            $table->string('visibilidade', 10);
            $table->text('texto');
            $table->timestamps();

            $table->index(['chamado_id', 'id']);
        });

        Schema::create('chamado_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('chamados')->cascadeOnDelete();
            $table->foreignId('mensagem_id')->nullable()->constrained('chamado_mensagens')->cascadeOnDelete();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome_original', 255);
            // Disco `local` (privado) — só sai pela rota de download, que confere a permissão.
            $table->string('caminho', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');
            $table->timestamps();
        });

        Schema::create('chamado_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('chamados')->cascadeOnDelete();
            $table->foreignId('ator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 40);
            $table->string('de', 255)->nullable();
            $table->string('para', 255)->nullable();
            $table->json('meta')->nullable();
            // O solicitante vê só os eventos marcados como públicos (nada de nota interna nem demanda).
            $table->boolean('publico')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['chamado_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamado_eventos');
        Schema::dropIfExists('chamado_anexos');
        Schema::dropIfExists('chamado_mensagens');
        Schema::dropIfExists('chamados');
    }
};
