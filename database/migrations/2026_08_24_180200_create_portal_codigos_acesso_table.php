<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `portal_codigos_acesso` — os códigos de 6 dígitos enviados por e-mail.
 *
 * ### O código NÃO é guardado em claro
 * `codigo_hash` guarda um hash. Quem tiver `SELECT` no banco não consegue
 * entrar como ninguém — que é exatamente o defeito do token atual do portal,
 * gravado em texto puro e válido para sempre. O mesmo raciocínio de
 * `users.password`, aplicado a um segredo de vida curta.
 *
 * ### `sessao_id` é o que impede o repasse
 * Este é o campo mais importante da tabela. O código é amarrado à sessão do
 * navegador que o PEDIU: se a pessoa encaminhar o e-mail, quem receber está em
 * outra sessão e o código não abre nada.
 *
 * Sem isso, um código de 6 dígitos no e-mail teria o mesmo problema do link
 * atual — quem recebe, entra. Foi a objeção levantada pelo dono do produto em
 * 24/08/2026, e é ela que esta coluna responde.
 *
 * ### Por que 6 dígitos são seguros aqui, e o que os torna seguros
 * Sozinho, um código de 6 dígitos é fraco: um milhão de combinações se quebram
 * por força bruta. O que o protege é a soma de quatro limites, e nenhum deles é
 * opcional:
 *
 *  - vida curta (`expira_em`, 10 minutos);
 *  - uso único (`usado_em`);
 *  - teto de tentativas (`tentativas`, máximo 5 — depois o código morre);
 *  - amarração à sessão que pediu (`sessao_id`).
 *
 * Um atacante precisaria acertar em 5 tentativas, dentro de 10 minutos, a
 * partir da mesma sessão. Se qualquer um desses quatro for afrouxado, a conta
 * muda e o código deixa de ser seguro.
 *
 * ### Por que não reusar `password_reset_tokens`
 * Aquela tabela é do fluxo de senha dos usuários INTERNOS, tem chave por e-mail
 * sem escopo, não guarda tentativas nem sessão, e é limpa por rotina do
 * framework. Misturar os dois fluxos acoplaria a autenticação do cliente à do
 * time da ECF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_codigos_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_usuario_id')->constrained('portal_usuarios')->cascadeOnDelete();

            // Hash do código. Nunca o código.
            $table->string('codigo_hash', 255);

            // A sessão do navegador que pediu o código. Ver o docblock.
            $table->string('sessao_id', 100);

            // `dateTime`, NUNCA `timestamp`. O MariaDB dá à primeira coluna
            // TIMESTAMP NOT NULL sem default explícito um
            // `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` automático
            // — e aí TODO update na linha reescreve a validade para agora.
            //
            // O efeito prático, medido em 24/08/2026: `increment('tentativas')`
            // dentro da validação zerava o `expira_em`, o código morria no
            // primeiro palpite e NENHUM login funcionava. O SQLite dos testes
            // não reproduz isso.
            $table->dateTime('expira_em');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->dateTime('usado_em')->nullable();

            // Para investigar abuso depois. Não participa da validação: IP de
            // celular muda entre 4G e wi-fi, e exigir o mesmo IP derrubaria
            // acesso legítimo.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // A consulta quente: o código válido mais recente de um usuário.
            $table->index(['portal_usuario_id', 'expira_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_codigos_acesso');
    }
};
