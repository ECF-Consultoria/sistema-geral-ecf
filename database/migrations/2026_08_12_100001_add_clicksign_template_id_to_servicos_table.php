<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano 127-04 (D-21, herdada da Fase 126): cada serviço do catálogo pode
 * apontar para o SEU PRÓPRIO modelo `.docx` na Clicksign — hoje existe um
 * único `CLICKSIGN_TEMPLATE_ID` global, e isso quebra assim que a empresa
 * tem mais de um serviço contratado (a cláusula 2.1 de Gestão de ADS não
 * pode sair num contrato de Shopee).
 *
 * O modelo é DADO (coluna), não `if` hardcoded por nome de serviço no
 * código: cadastrar um serviço novo com modelo próprio não pode exigir
 * deploy. Nullable de propósito — serviço sem modelo próprio cai no
 * padrão de `config('services.clicksign.template_id')` (ver
 * `Servico::clicksignTemplateId()`).
 *
 * Três armadilhas de MariaDB do projeto — nenhuma se aplica aqui, mas fica
 * registrado como as duas migrations anteriores da fase fizeram:
 *  - erro 1059 (nome de índice > 64 caracteres): não há índice novo, não há
 *    leitura por este campo.
 *  - `enum`: não usado, é `string` livre.
 *  - erro 1830 (`nullOnDelete` exige `nullable()`): não há FK aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->string('clicksign_template_id', 64)->nullable()->after('setor');
        });
    }

    public function down(): void
    {
        Schema::table('servicos', function (Blueprint $table) {
            $table->dropColumn('clicksign_template_id');
        });
    }
};
