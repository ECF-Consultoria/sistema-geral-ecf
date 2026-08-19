<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `onboarding_confirmacoes` — as respostas Sim / Não / Pendente dos sete itens
 * de confirmação do checklist (§17 publicidade e §18 ADMAN).
 *
 * ─── Por que a resposta "Não" precisa de tabela própria ────────────────────
 * O catálogo de `onboarding_passos.status` tem SEIS valores e nenhum deles
 * significa "Não". O candidato aparente, `nao_aplicavel`, significa outra
 * coisa (a `condicao` do passo foi avaliada como falsa — D-12) e, pior, CONTA
 * COMO RESOLVIDO em `OnboardingEngineService::avaliarConclusaoDoOnboarding()`:
 * reusá-lo para "Não" faria o onboarding se dar por concluído com a
 * publicidade nunca explicada ao cliente — exatamente o desfecho que este
 * checklist existe para impedir. Então a resposta mora aqui e o passo
 * continua `aberto` até virar "Sim".
 *
 * ─── Por que `pendente` é um valor gravado, e não a ausência da linha ──────
 * O formulário do documento oferece três opções e um espaço de observação em
 * TODAS elas. Sem um valor `pendente` gravável, "vou confirmar na próxima
 * call" não teria onde ser escrito — quem estivesse anotando seria empurrado
 * a marcar Sim ou Não só para não perder o texto. Linha ausente e linha
 * `pendente` significam a mesma coisa para o resolver, então não há duas
 * verdades a reconciliar: só a segunda carrega observação.
 *
 * ─── Armadilhas de MariaDB conferidas (learnings §6) ──────────────────────
 *  - Sem `enum`: `resposta` é varchar + constante em PHP
 *    ({@see \App\Models\OnboardingConfirmacao::RESPOSTAS}).
 *  - `nullOnDelete` só sobre coluna `nullable()` (erro 1830) — vale para
 *    `respondido_por`.
 *  - Limite de 64 chars em nome de índice/FK: o prefixo
 *    `onboarding_confirmacoes` tem 23 chars e o pior nome autogerado aqui é
 *    `onboarding_confirmacoes_onboarding_id_chave_unique`, com 50. Todos os
 *    demais são menores. Nenhum precisa de nome à mão.
 *  - Truncamento silencioso: `chave` é varchar(60) para casar EXATAMENTE com
 *    `onboarding_passos.chave` (também 60) — é por ela que o resolver casa a
 *    resposta com o passo, e uma largura menor cortaria a chave de um lado só.
 *    `resposta` é varchar(20) — mesma largura dos demais campos de catálogo
 *    do módulo (`status`, `etapa`, `natureza`, `periodicidade`). O maior valor
 *    de hoje tem 8 chars; a folga é para que uma quarta opção não entre
 *    truncada em silêncio no MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_confirmacoes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // Espelha `onboarding_passos.chave` (varchar(60)) — é a junção
            // entre a resposta e o passo que ela fecha. Um único resolver
            // atende os sete itens justamente porque se orienta por ela.
            $table->string('chave', 60)
                ->comment('Espelha onboarding_passos.chave — identifica qual dos sete itens de confirmação esta linha responde.');

            // sim | nao | pendente — App\Models\OnboardingConfirmacao::RESPOSTAS.
            // Default `pendente` para que uma linha criada só para guardar
            // observação nasça no estado que não fecha nada.
            $table->string('resposta', 20)
                ->default('pendente')
                ->comment('sim | nao | pendente — App\\Models\\OnboardingConfirmacao::RESPOSTAS. Só `sim` fecha o passo.');

            $table->text('observacoes')->nullable();

            $table->timestamp('respondido_em')->nullable();

            // `nullable()` é par obrigatório do `nullOnDelete()` (erro 1830):
            // a resposta sobrevive à exclusão de quem a deu.
            $table->foreignId('respondido_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Uma resposta por item por onboarding. É constraint de banco, não
            // validação de aplicação: duas linhas para a mesma chave deixariam
            // o resolver escolhendo em silêncio qual das duas vale.
            $table->unique(['onboarding_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_confirmacoes');
    }
};
