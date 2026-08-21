<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ppa_colunas` — colunas EXTRAS do quadro, por PPA ("Aguardando Cliente",
 * "Em Revisão", "Bloqueado"...).
 *
 * ### As três colunas atuais NÃO moram aqui
 * `todo`, `doing` e `done` continuam sendo o ENUM `ppa_tasks.status` e
 * continuam fixas, na mesma ordem, em todo quadro. Esta tabela só acrescenta.
 * Migrá-las para cá teria transformado uma mudança visual em uma mudança de
 * contrato: `PortalPpaController::moverTarefa()` valida
 * `in:todo,doing,done`, `PortalPpaService` conta `status != 'done'`, e
 * `PpaController::index()` conta `where('status','done')` para a barra de
 * progresso da listagem. Tudo isso segue intocado.
 *
 * ### `status_base` é o que faz nada quebrar
 * Toda coluna extra declara a qual dos três status ela pertence. Uma tarefa em
 * "Aguardando Cliente" (`status_base = 'doing'`) tem `status = 'doing'` no
 * banco e `coluna_id` apontando para cá. Consequências, todas desejadas:
 *
 *  - o Portal do Cliente, que desenha três colunas, a mostra em "Em andamento";
 *  - o progresso e os contadores não mudam de régua;
 *  - apagar esta linha devolve a tarefa à coluna base sem perder nada.
 *
 * A coluna extra é um REFINAMENTO por cima do status, nunca um substituto.
 *
 * ### Por que por PPA, e não global
 * `ppa_id` é obrigatório. Colunas globais fariam uma etapa criada para um
 * cliente aparecer no quadro de todos os outros — e a etapa extra é, por
 * natureza, específica do plano em que ela faz sentido.
 *
 * `posicao` é a ordem DENTRO do bloco do `status_base`: a coluna extra aparece
 * logo depois da base à qual pertence, e nunca antes de `todo` nem depois de
 * `done`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppa_colunas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppa_id')->constrained('ppas')->cascadeOnDelete();
            $table->string('nome', 60);

            // A qual das três colunas fixas esta se ancora. Sem isto não haveria
            // como responder "esta tarefa está concluída?" para o cliente.
            $table->enum('status_base', ['todo', 'doing', 'done'])->default('doing');

            // Token de cor da paleta da tela (ver `CORES` em `Pages/Ppa/Kanban.jsx`).
            // Nome e não hex: hex livre deixaria o quadro sair da identidade ECF
            // no primeiro roxo neon que alguém escolhesse.
            $table->string('cor', 20)->default('slate');

            $table->smallInteger('posicao')->unsigned()->default(0);
            $table->timestamps();

            $table->index(['ppa_id', 'posicao']);
        });

        Schema::table('ppa_tasks', function (Blueprint $table) {
            // `nullOnDelete`: apagar a coluna extra devolve a tarefa à coluna
            // base do `status` dela. Apagar tarefa junto com a coluna seria
            // perder trabalho por uma decisão de organização visual.
            $table->foreignId('coluna_id')
                ->nullable()
                ->after('status')
                ->constrained('ppa_colunas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ppa_tasks', function (Blueprint $table) {
            $table->dropForeign(['coluna_id']);
            $table->dropColumn('coluna_id');
        });

        Schema::dropIfExists('ppa_colunas');
    }
};
