<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 202-08 — acervo de contratos ASSINADOS lidos da Clicksign.
 *
 * Fonte da verdade do término do contrato (decisão do usuário, 09/11/2026):
 * prazo escrito no contrato + data da assinatura. O período de cobrança do
 * HubSpot (`P4M` da Brigada) NÃO é vigência.
 *
 * MariaDB: índices nomeados à mão (< 64 chars, erro 1059); FK SET NULL só em
 * coluna nullable (erro 1830); sem enum (o SQLite dos testes enforça CHECK).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contratos_clicksign_assinados')) {
            return;
        }

        Schema::create('contratos_clicksign_assinados', function (Blueprint $table) {
            $table->id();
            $table->string('envelope_id', 64);
            $table->string('envelope_nome', 255);
            // gestao | mentoria (Brigada entra como gestao)
            $table->string('tipo', 20);
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            // certo | provavel | incerto (régua do EmpresaPalpiteService)
            $table->string('confianca', 20)->nullable();
            $table->string('cnpj_lido', 32)->nullable();
            $table->string('razao_social_lida', 255)->nullable();
            $table->dateTime('assinado_em')->nullable();
            $table->unsignedSmallInteger('vigencia_meses')->nullable();
            // texto | nome | null
            $table->string('vigencia_fonte', 10)->nullable();
            // casado | duvidoso | sem_empresa | ilegivel | sem_prazo
            $table->string('situacao', 20);
            $table->text('motivo')->nullable();
            $table->timestamps();

            $table->unique('envelope_id', 'cca_envelope_unico');
            $table->index(['company_id', 'situacao'], 'cca_empresa_situacao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_clicksign_assinados');
    }
};
