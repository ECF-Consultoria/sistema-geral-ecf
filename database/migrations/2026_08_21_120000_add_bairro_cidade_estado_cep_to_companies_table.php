<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Quick 260821-cq0 (endereço volta a ser guardado
// em partes separadas).
//
// O contrato de Gestão elaborado pelo jurídico (`novo-modelo-contrato-gestao-
// COM-VARIAVEIS.docx`) precisa do endereço em CINCO pedaços: "com sede
// {{endereco}}, {{bairro}}, {{cidade}}/{{estado}} - CEP: {{cep}}". A decisão
// de 2026-08-20 (Quick 260820-jc8) de concatenar tudo num `companies.endereco`
// único ficou ERRADA para este documento — o HubSpot já manda os cinco
// campos separados. Esta migration volta atrás e abre espaço para guardar
// cada parte na própria coluna.
//
// ⚠️ `companies.endereco` MUDA DE SIGNIFICADO aqui: deixava de ser "endereço
// completo concatenado" (rua + bairro + cidade/estado + CEP, decisão de
// 2026-08-20) e passa a ser só o LOGRADOURO (rua e número) — o que
// `{{endereco}}` representa no `.docx` do jurídico. Coluna reaproveitada, não
// renomeada (T-126-38: renomear faz a variável do `.docx` sumir do contrato
// assinado sem erro nenhum da API).
//
// ⚠️ NÃO destrói dado. A única empresa em produção com `endereco` preenchido
// hoje (id 429, Maderatto) fica com a STRING CONCATENADA antiga até alguém
// corrigir à mão — não há backfill automático nesta migration (registrado no
// SUMMARY do quick).
//
// NULLABLE de propósito, mesma disciplina da migration 2026_08_19_100000: a
// obrigatoriedade das 4 colunas novas entra na camada de validação
// (`ContratoDadosMinimosService::faltantes()`), não no schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'bairro')) {
                $table->string('bairro', 255)->nullable()->after('endereco');
            }

            if (! Schema::hasColumn('companies', 'cidade')) {
                $table->string('cidade', 255)->nullable()->after('bairro');
            }

            if (! Schema::hasColumn('companies', 'estado')) {
                $table->string('estado', 255)->nullable()->after('cidade');
            }

            if (! Schema::hasColumn('companies', 'cep')) {
                $table->string('cep', 20)->nullable()->after('estado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'cep')) {
                $table->dropColumn('cep');
            }

            if (Schema::hasColumn('companies', 'estado')) {
                $table->dropColumn('estado');
            }

            if (Schema::hasColumn('companies', 'cidade')) {
                $table->dropColumn('cidade');
            }

            if (Schema::hasColumn('companies', 'bairro')) {
                $table->dropColumn('bairro');
            }
        });
    }
};
