<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pendências do Analista de Conta dos Polos (TKT-0035, DEV-58).
 *
 * O analista de Ads (o responsável da empresa no Painel Polos) encaminha, pela fila de revisão,
 * uma pendência que o Analista de Conta resolve na conta do seller: publicar anúncio, pôr produto
 * na central de promoção, ajustar produto ou anúncio. É trabalho em PARALELO, não divisão de
 * carteira.
 *
 * Decisões de schema (escritas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Tabelas próprias; nada alterado em mlb_empresas, mlb_implementacoes, polos_revisoes nem
 *    polos_agenda_revisao.** O responsável, a carteira e a revisão quinzenal moram lá e não
 *    podem mudar por causa de uma pendência (regra do ticket). Aqui só se LÊ a empresa.
 *
 *  - **Uma linha por pendência**, não por empresa: a empresa pode ter várias abertas ao mesmo
 *    tempo, de analistas diferentes. "Empresa na fila do Analista de Conta" = tem ao menos uma
 *    pendência não encerrada (encerrada = concluída ou cancelada).
 *
 *  - `tipo`, `prioridade` e `status` são string, não enum: enum exige migration a cada valor
 *    novo e precisa de ramo próprio no SQLite dos testes (desempenho-bonificacao.md §6). Os
 *    valores válidos moram no model PolosPendenciaConta.
 *
 *  - `prazo` = o prazo PEDIDO por quem encaminhou. `data_planejada` = o dia em que o Analista de
 *    Conta planeja fazer (agenda semanal, fatia 2 da DEV-58). A coluna já nasce aqui para a
 *    fatia 2 não precisar alterar tabela com dado em produção.
 *
 *  - `analista_id` = quem do papel Analista de Conta pegou ou concluiu. O papel é uma
 *    permissão (`mlb.analista_conta`) e pode ter mais de uma pessoa no futuro; nulo enquanto
 *    ninguém pegou. `conclusao` e `mlbs_alterados` = o que foi feito, informado ao concluir.
 *
 *  - `mlbs` e `mlbs_alterados` em JSON: lista de códigos MLB, normalizados em maiúsculas.
 *
 *  - **Histórico em tabela própria** (`polos_pendencias_conta_eventos`), uma linha por ação —
 *    criação, status, comentário, anexo, conclusão — com quem e quando. Linha não se edita.
 *
 *  - Anexos no disco `local` (privado), em `polos-pendencias/{id}/`, como os anexos de ticket;
 *    saem só pela rota que confere a permissão.
 *
 *  - FKs de usuário `nullable + nullOnDelete` (MariaDB 1830): usuário removido não apaga o
 *    histórico. Índices nomeados à mão, curtos (MariaDB 1059). Os nomes automáticos das FKs
 *    ficam abaixo de 64 caracteres (o maior: polos_pendencias_conta_eventos_pendencia_id_foreign,
 *    51).
 *
 * Migration idempotente: re-rodar não recria tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polos_pendencias_conta')) {
            Schema::create('polos_pendencias_conta', function (Blueprint $table) {
                $table->id();

                $table->foreignId('mlb_empresa_id')->constrained('mlb_empresas')->cascadeOnDelete();

                $table->string('tipo', 30);
                $table->text('descricao');
                $table->json('mlbs')->nullable();
                $table->string('prioridade', 10);
                // Prazo pedido por quem encaminhou × dia planejado pelo Analista de Conta.
                $table->date('prazo')->nullable();
                $table->date('data_planejada')->nullable();
                $table->string('status', 20)->default('aberta');

                $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('analista_id')->nullable()->constrained('users')->nullOnDelete();

                $table->text('conclusao')->nullable();
                $table->json('mlbs_alterados')->nullable();
                $table->dateTime('concluida_em')->nullable();

                $table->timestamps();

                // A fila lê as não encerradas, por prazo; a fila de revisão, as de cada empresa.
                $table->index(['status', 'prazo'], 'ppc_status_prazo_idx');
                $table->index(['mlb_empresa_id', 'status'], 'ppc_empresa_status_idx');
            });
        }

        if (! Schema::hasTable('polos_pendencias_conta_eventos')) {
            Schema::create('polos_pendencias_conta_eventos', function (Blueprint $table) {
                $table->id();

                $table->foreignId('pendencia_id')->constrained('polos_pendencias_conta')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                // criada, status, comentario, anexo, concluida
                $table->string('tipo', 20);
                $table->string('de', 30)->nullable();
                $table->string('para', 255)->nullable();
                $table->text('texto')->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('polos_pendencias_conta_anexos')) {
            Schema::create('polos_pendencias_conta_anexos', function (Blueprint $table) {
                $table->id();

                $table->foreignId('pendencia_id')->constrained('polos_pendencias_conta')->cascadeOnDelete();
                $table->foreignId('evento_id')->nullable()->constrained('polos_pendencias_conta_eventos')->nullOnDelete();
                $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();

                // Nome só para exibir; no disco o arquivo tem nome gerado.
                $table->string('nome_original');
                $table->string('caminho');
                $table->string('mime', 120);
                $table->unsignedInteger('tamanho')->default(0);

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_pendencias_conta_anexos');
        Schema::dropIfExists('polos_pendencias_conta_eventos');
        Schema::dropIfExists('polos_pendencias_conta');
    }
};
