<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260824-ot1 (Tarefa 1) — opt-in POR SERVIÇO para a assinatura
 * manuscrita POSICIONADA (`{{~position_sign_ID}}`, doc oficial
 * `docs-modelos` da Clicksign, ligada via `rubric_field` no requisito de
 * rubrica — ver `ClicksignClient::criarRequisitoRubricaPosicionada()`).
 *
 * ⚠️ `default(false)` é a trava que protege os outros 8 serviços: só o
 * modelo `.docx` de Gestão tem as tags `{{~position_sign_contratante}}` /
 * `{{~position_sign_contratada}}`. Os demais usam o modelo global, SEM
 * tags — mandar `rubric_field` apontando pra uma tag que não existe no
 * documento é recusado pela API Clicksign, e isso quebraria a geração de
 * contrato de todo mundo. Sem opt-in explícito, ninguém ganha o requisito
 * novo.
 *
 * ⚠️ Esta migration NÃO liga a flag para nenhum serviço — nem para Gestão.
 * Ligar é passo de PRODUÇÃO, feito depois do deploy (e depois de o usuário
 * subir o `.docx` novo na Clicksign), conferido por reconsulta ao banco.
 *
 * `boolean` simples, sem índice, sem FK — nenhuma das armadilhas de
 * MariaDB do projeto se aplica (mesma conclusão das migrations irmãs desta
 * tabela: `add_clicksign_template_id...`, `add_exige_contrato...`).
 * Idempotente via `hasColumn`, mesmo padrão de outras migrations aditivas
 * do projeto (ex.: `add_status_to_companies`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('servicos', 'clicksign_assinatura_posicionada')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->boolean('clicksign_assinatura_posicionada')->default(false)->after('exige_contrato');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicos', 'clicksign_assinatura_posicionada')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->dropColumn('clicksign_assinatura_posicionada');
            });
        }
    }
};
