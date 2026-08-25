<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Senha OPCIONAL para o cliente do portal.
 *
 * ### Por que opcional, e não obrigatória
 * O caminho principal continua sendo o código por e-mail, por três razões que
 * não mudaram: não há o que o cliente esqueça, não há o que ele repasse, e o
 * código expira sozinho. A senha entra para quem entra com frequência e acha
 * chato esperar o e-mail.
 *
 * `null` na coluna é o estado NORMAL, não um cadastro pela metade. A maioria
 * dos clientes nunca vai definir senha, e isso está certo.
 *
 * ### Não existe "esqueci minha senha"
 * E não é omissão: pedir um código por e-mail JÁ é o fluxo de recuperação, e
 * ele já está pronto. Construir um segundo caminho de recuperação seria
 * construir uma segunda superfície de ataque para chegar ao mesmo lugar.
 *
 * ### `senha_definida_em`
 * Serve para duas perguntas que a coluna de senha sozinha não responde: "desde
 * quando esta conta tem senha?" (auditoria) e "faz quanto tempo?" (para um dia
 * sugerir troca). Guardar o hash não datado perderia as duas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_usuarios', function (Blueprint $table) {
            // 255 porque é o tamanho que o bcrypt do Laravel usa por padrão em
            // `users.password`, e um dia o algoritmo pode gerar mais que os 60
            // do bcrypt atual.
            $table->string('password')->nullable()->after('cargo');

            // `dateTime`, nunca `timestamp`: no MariaDB a primeira coluna
            // TIMESTAMP sem default explícito ganha um
            // `ON UPDATE CURRENT_TIMESTAMP` invisível, e qualquer UPDATE na
            // linha reescreveria esta data.
            $table->dateTime('senha_definida_em')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('portal_usuarios', function (Blueprint $table) {
            $table->dropColumn(['password', 'senha_definida_em']);
        });
    }
};
