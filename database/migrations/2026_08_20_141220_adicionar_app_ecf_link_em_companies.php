<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `companies.app_ecf_link` — o link do App ECF que o cliente vê no portal de
 * onboarding, quando ESTA empresa precisa de um diferente do padrão.
 *
 * ### Por que nullable, e por que a coluna não é a fonte principal
 * O precedente é o onboarding de Polos, onde o link do App ECF é GLOBAL de
 * propósito ("configurado nos Padrões Globais — serve todo mundo",
 * `MlbImplementacaoController`). Na prática é o mesmo endereço para todos os
 * clientes; empresa com link próprio é a exceção.
 *
 * Por isso o valor padrão mora em `Configuracao` (chave
 * `onboarding_app_ecf_link`) e esta coluna é só o OVERRIDE. `null` significa
 * "usa o global" — não "sem link". Gravar o global copiado em cada linha
 * pareceria equivalente e não é: no dia em que o endereço mudar, seria preciso
 * caçar todas as cópias, e a que ficasse para trás mandaria um cliente para um
 * link morto sem ninguém notar.
 *
 * ### Por que não existe coluna nova para o e-mail
 * `companies.email_colaborador` já existe e já é o override por empresa. O
 * padrão global dele entra na mesma `Configuracao`
 * (`onboarding_email_colaborador`), sem tocar em schema.
 *
 * Coluna nova e nullable: nada a preencher retroativamente, e `down()` só a
 * remove — nenhum dado além do override se perde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('app_ecf_link', 500)->nullable()->after('email_colaborador');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('app_ecf_link');
        });
    }
};
