<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Quick 260819-guy (ajustes do fluxo de contrato
// administrativo após o teste ponta-a-ponta).
//
// `ContratoVariaveisModeloService::mapa()` já tem as variáveis
// `razao_social`/`endereco` — o `.docx` da Clicksign já as usa. Elas saem
// "A DEFINIR" hoje porque o banco simplesmente não tem onde guardar o dado
// (`razao_social` lia `companies.name`, que é o nome FANTASIA, não a razão
// social de verdade; `endereco` nunca existiu). Esta migration só abre o
// espaço no schema — nullable, sem tocar `.docx`, sem renomear variável
// nenhuma (T-126-38: renomear faz a variável sumir do contrato assinado sem
// erro nenhum da API).
//
// NULLABLE de propósito: a obrigatoriedade destes dois campos entra na
// camada de validação (ContratoDadosMinimosService::faltantes(), Tarefa 3
// deste quick), não no schema. Empresa antiga sem o dado aparece como
// "falta preencher" na tela do Administrativo — é o comportamento desejado,
// não uma falha da migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'razao_social')) {
                $table->string('razao_social', 255)->nullable()->after('name');
            }

            if (! Schema::hasColumn('companies', 'endereco')) {
                $table->string('endereco', 255)->nullable()->after('razao_social');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'endereco')) {
                $table->dropColumn('endereco');
            }

            if (Schema::hasColumn('companies', 'razao_social')) {
                $table->dropColumn('razao_social');
            }
        });
    }
};
