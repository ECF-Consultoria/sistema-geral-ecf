<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Quick 260819-guy (ajustes do fluxo de contrato
// administrativo após o teste ponta-a-ponta).
//
// Mesmo racional da migration irmã (razao_social/endereco em `companies`):
// `ContratoVariaveisModeloService::mapa()` já tem `data_primeira_parcela` e
// `dia_vencimento` mapeados para o `.docx` da Clicksign; hoje elas saem "A
// DEFINIR"/placeholder fixo porque `contratos_servico` não tinha onde
// guardar o dado. NÃO renomear nenhuma das duas (T-126-38).
//
// ⚠️ `dia_vencimento` é o DIA DO MÊS (1 a 31) em que as parcelas seguintes
// vencem — não uma data. Num contrato mensal recorrente não existe uma
// "data de vencimento das demais parcelas" única (isso mudaria todo mês);
// o que existe é o dia fixo do mês, que é exatamente o que o placeholder
// `dia_vencimento` do `.docx` já espera. Por isso é `unsignedTinyInteger`
// (cabe 1–31), não `date`. `data_primeira_parcela` é a exceção: essa sim é
// uma data única (só a primeira parcela), por isso é `date`.
//
// NULLABLE de propósito — mesma disciplina da migration irmã: a
// obrigatoriedade entra em `ContratoDadosMinimosService::faltantes()`
// (Tarefa 3), nunca no schema.
//
// Sem índice novo nesta migration — nada aqui é buscado por igualdade, então
// não há necessidade de nomear índice à mão para escapar do limite de 64
// caracteres do MariaDB (erro 1059, ver memória do projeto).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos_servico', function (Blueprint $table) {
            if (! Schema::hasColumn('contratos_servico', 'data_primeira_parcela')) {
                $table->date('data_primeira_parcela')->nullable()->after('data_vencimento');
            }

            if (! Schema::hasColumn('contratos_servico', 'dia_vencimento')) {
                $table->unsignedTinyInteger('dia_vencimento')->nullable()->after('data_primeira_parcela');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contratos_servico', function (Blueprint $table) {
            if (Schema::hasColumn('contratos_servico', 'dia_vencimento')) {
                $table->dropColumn('dia_vencimento');
            }

            if (Schema::hasColumn('contratos_servico', 'data_primeira_parcela')) {
                $table->dropColumn('data_primeira_parcela');
            }
        });
    }
};
