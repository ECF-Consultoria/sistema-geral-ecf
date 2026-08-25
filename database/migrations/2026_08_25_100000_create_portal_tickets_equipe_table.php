<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets de passagem: como alguém da EQUIPE entra no portal de um cliente.
 *
 * ### Por que um ticket, e não "estar logado"
 * O portal roda em `cliente.ecfconsultoria.com.br` e o sistema interno em
 * `admin.…`. Cookie de sessão não atravessa domínio — o analista logado no
 * admin é um desconhecido no domínio do cliente. O ticket é o que carrega a
 * identidade de um lado para o outro: nasce no admin (onde a pessoa JÁ provou
 * quem é), viaja na URL uma vez, e morre ao ser usado.
 *
 * ### Os quatro limites que fazem um token de URL ser aceitável
 * Token em URL vaza fácil: fica no histórico, no Referer, no print da tela. O
 * que o torna seguro aqui é ele valer quase nada:
 *
 *  1. **60 segundos** de vida — é o tempo de um redirecionamento, não de uma
 *     sessão;
 *  2. **uso único** (`usado_em`) — o segundo uso é recusado;
 *  3. **guardado como hash** — vazamento da tabela não devolve tickets usáveis;
 *  4. **ligado a um par** (quem entra, em qual empresa) — não é uma chave-mestra
 *     do portal, é uma passagem para um lugar só.
 *
 * ### `dateTime`, nunca `timestamp`
 * O MariaDB dá à primeira coluna TIMESTAMP NOT NULL sem default explícito um
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` automático. Foi assim
 * que `portal_codigos_acesso` quebrou: marcar o código como usado reescrevia a
 * validade dele. O SQLite dos testes não reproduz isso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_tickets_equipe', function (Blueprint $table) {
            $table->id();

            // Quem entra: um usuário do sistema INTERNO, não do portal.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Só o hash. O token em claro existe por 60 segundos, na URL.
            $table->string('token_hash', 64)->unique();

            $table->dateTime('expira_em');
            $table->dateTime('usado_em')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            // A limpeza dos vencidos varre por esta coluna.
            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_tickets_equipe');
    }
};
