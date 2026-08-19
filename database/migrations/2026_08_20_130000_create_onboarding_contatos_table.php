<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `onboarding_contatos` — as pessoas do lado do cliente: o ponto de contato
 * (§13.2) e os participantes das reuniões (§16).
 *
 * ─── Por que UMA tabela e não duas ─────────────────────────────────────────
 * Ponto de contato e participante têm shape idêntico: nome, e-mail, função e
 * telefone de uma pessoa da empresa do cliente. Duas tabelas seriam gêmeas, e
 * gêmeas divergem — uma ganharia um campo que a outra não ganha, e a pergunta
 * "o ponto de contato também participa da reunião?" viraria um JOIN entre
 * duas listas de pessoas que não sabem uma da outra. `papel` separa os dois
 * usos sem duplicar estrutura, e é STRING + constante em PHP, nunca `enum` de
 * banco (learnings §6.1 — `enum` em migration não sobrevive a acréscimo de
 * valor no MariaDB).
 *
 * ─── UMA LINHA POR PESSOA, com id próprio ──────────────────────────────────
 * A lista de participantes é uma tabela de LINHAS justamente para nunca mais
 * ser um array reescrito inteiro a cada save. Foi assim — mapa reconstruído
 * por chave a cada tecla digitada — que as 11 linhas de produto da
 * Precificação viraram cópia de uma só e o custo do cliente sumiu sem deixar
 * rastro (`.planning/learnings/precificacao-onboarding-duas-telas.md` §3;
 * custo perdido não volta por código). Ver o docblock de
 * {@see \App\Models\OnboardingContato}, que carrega a regra de escrita.
 *
 * ─── Nome/e-mail COPIADOS na linha, sem FK para `users` ────────────────────
 * Estas pessoas são do CLIENTE, não da ECF: não existe `users.id` para elas.
 * O único vínculo com `users` é `criado_por` — quem da equipe cadastrou —, e
 * ele é `nullable` + `nullOnDelete` porque o contato tem de sobreviver à saída
 * da pessoa que o digitou (mesma decisão D-07 de
 * `contrato_assinatura_signatarios`). `nullOnDelete` sobre coluna NOT NULL dá
 * erro 1830 no MariaDB e o SQLite dos testes não pega — as duas declarações
 * andam juntas de propósito (learnings §6.2).
 *
 * ─── Comprimentos ──────────────────────────────────────────────────────────
 * `email` em 190 é o teto seguro de índice utf8mb4 do MySQL/MariaDB e o mesmo
 * usado no resto do projeto; `papel` em 30 comporta com folga o maior valor do
 * catálogo (`participante_reuniao`, 20 caracteres) — truncamento aqui seria
 * silencioso e transformaria um papel em outro (learnings §6.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_contatos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('onboarding_id')
                ->constrained('onboardings')
                ->cascadeOnDelete();

            // ponto_de_contato | participante_reuniao — catálogo em
            // OnboardingContato::PAPEIS.
            $table->string('papel', 30);

            $table->string('nome', 120);

            // Nullable de propósito: o cadastro nasce incompleto (o cliente
            // dita o nome na call e o e-mail vem depois). É exatamente essa
            // lacuna que o ParticipantesResolver precisa ENXERGAR para manter
            // o passo aberto e cobrar nominalmente quem falta — coluna NOT
            // NULL esconderia o problema atrás de um e-mail vazio ou de uma
            // linha que ninguém consegue salvar.
            $table->string('email', 190)->nullable();

            // Cargo/função na empresa do cliente ("Sócio", "Gerente de
            // e-commerce"). Texto livre: não existe catálogo de cargos do
            // cliente e inventar um só criaria "Outro".
            $table->string('funcao', 80)->nullable();

            $table->string('telefone', 30)->nullable();

            // Qual, entre vários, é o contato principal daquele papel. Não é
            // unique: a exclusividade é decisão de tela, e um UNIQUE aqui
            // impediria o estado intermediário de trocar quem é o principal.
            $table->boolean('principal')->default(false);

            // Nullable é OBRIGATÓRIO — par do `nullOnDelete` abaixo (erro
            // 1830 no MariaDB; ver topo).
            $table->foreignId('criado_por')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Leitura canônica dos dois resolvers: "os contatos deste
            // onboarding com este papel". Nomeado à mão para não depender do
            // gerador de nomes do Laravel, que já estourou o limite de 64
            // caracteres do MariaDB em outras tabelas deste repo — e a falha
            // é SILENCIOSA: cria a tabela sem o índice e deixa a migration
            // pendente (learnings §6.4).
            $table->index(['onboarding_id', 'papel'], 'onb_contatos_onboarding_papel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_contatos');
    }
};
