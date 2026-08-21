<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que faltavam ao card de tarefa do PPA — área, prioridade, prazo, lado
 * responsável e data de conclusão.
 *
 * ### Por que todos nullable, e por que nenhum é obrigatório
 * `ppa_tasks` tem linhas em produção criadas quando a tarefa era só título +
 * descrição + status. Toda coluna aqui nasce `null`, e a tela trata ausência
 * como ausência: tarefa sem prazo não desenha um espaço vazio de prazo, sem
 * prioridade não desenha bandeira. Nenhuma tarefa antiga fica "incompleta" —
 * ela fica igual ao que sempre foi.
 *
 * Nenhuma delas entra em validação obrigatória no controller pelo mesmo
 * motivo: um campo obrigatório aqui inviabilizaria editar uma tarefa antiga
 * sem preencher dados que ninguém tem.
 *
 * ### `status` NÃO muda
 * O ENUM `('todo','doing','done')` continua sendo a verdade sobre em que
 * etapa a tarefa está — é o que o Portal do Cliente valida
 * (`PortalPpaController::moverTarefa()`) e o que os contadores de progresso
 * usam (`PortalPpaService::visao()`). Nada aqui o toca.
 *
 * ### `concluida_em` existe porque `updated_at` não serve
 * A referência de design pede "Concluída em 12/08" no card. `updated_at` muda
 * a cada edição de título ou descrição — usá-lo faria a data de conclusão
 * andar para frente sozinha, dizendo ao cliente que a tarefa foi concluída num
 * dia em que apenas se corrigiu uma vírgula. O controller carimba
 * `concluida_em` na transição para `done` e o limpa quando a tarefa volta.
 *
 * ### `responsavel_lado` é LADO, não pessoa
 * Guarda 'ecf' ou 'cliente' — de quem é a bola. Não é FK para `users` de
 * propósito: o lado do cliente não tem usuário no sistema (o portal é por
 * token, sem login), então uma FK só conseguiria representar metade dos casos.
 * `created_by` continua registrando quem criou, como sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppa_tasks', function (Blueprint $table) {
            // Rótulo curto exibido como tag no topo do card ("Estratégia",
            // "Conteúdo", "Operacional"...). Texto livre e não um ENUM: a lista
            // de áreas é assunto de negócio e vai mudar mais rápido do que se
            // quer mexer em schema.
            $table->string('area', 40)->nullable()->after('description');

            // 'baixa' | 'media' | 'alta'. Ausente = sem prioridade definida,
            // que é diferente de "baixa".
            $table->string('prioridade', 10)->nullable()->after('area');

            // Prazo DA TAREFA — não confundir com `ppas.due_date`, que é o
            // prazo do plano inteiro e continua existindo e valendo.
            $table->date('prazo')->nullable()->after('prioridade');

            // 'ecf' | 'cliente'.
            $table->string('responsavel_lado', 10)->nullable()->after('prazo');

            $table->timestamp('concluida_em')->nullable()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('ppa_tasks', function (Blueprint $table) {
            $table->dropColumn(['area', 'prioridade', 'prazo', 'responsavel_lado', 'concluida_em']);
        });
    }
};
