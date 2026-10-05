<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de acesso do MCP do ECF Admin (`/mcp`) — uma linha por chamada de
 * ferramenta: quem chamou, qual ferramenta, com quais filtros, quando, quanto
 * demorou e se deu certo. É o "log de acesso" que a especificação do MCP exige.
 *
 * Tabela NOVA, sem dado em produção — não altera nenhuma tabela existente.
 *
 * Decisões de desenho:
 *  - `user_id` é `nullable` + `nullOnDelete`: o log sobrevive à exclusão do
 *    usuário (o MariaDB recusa `nullOnDelete` em coluna NOT NULL, erro 1830).
 *  - Sem `updated_at`: linha de log não se edita.
 *  - Índices nomeados à mão e curtos (limite de 64 caracteres do MariaDB).
 *  - `argumentos` guarda os filtros como chegaram; nenhuma ferramenta recebe
 *    dado pessoal como argumento, então não há o que mascarar aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_acessos')) {
            return;
        }

        Schema::create('mcp_acessos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Cliente OAuth que fez a chamada (claude.ai, Inspector, Claude Code...).
            $table->string('cliente', 120)->nullable();
            $table->string('ferramenta', 64);
            $table->json('argumentos')->nullable();
            $table->boolean('sucesso')->default(true);
            $table->string('erro', 500)->nullable();
            $table->unsignedInteger('duracao_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'mcp_acessos_user_criado_idx');
            $table->index(['ferramenta', 'created_at'], 'mcp_acessos_ferr_criado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_acessos');
    }
};
