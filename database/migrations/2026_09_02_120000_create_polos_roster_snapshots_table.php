<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roster CONGELADO dos polos por mês — quem estava ativo e em que fase.
 *
 * Por que existe: o /polos já congela o FATURAMENTO por mês (polos_faturamento_snapshots),
 * mas o ROSTER era sempre inferido — do MlbEmpresa ao vivo no mês corrente, ou do
 * MESES_NO_PROGRAMA do CSV da Comercial no mês fechado. Nenhuma das duas fontes descreve
 * o passado:
 *
 *  - Ao vivo: o time avança TODAS as fases na virada do mês (01/09/2026: M0→M1 100,
 *    M1→M2 82, M2→M3 54, M3→M4 37, M4→Encerrado 43). Agosto passou a ser somado com as
 *    fases de setembro e as 43 empresas que eram M4 sumiram — R$ 4,73 mi viraram R$ 2,74 mi.
 *  - CSV: lista TODA a base de sellers da Comercial, não só o programa. Agosto vinha com
 *    185 "ativos" contra os 133 da planilha; as 40 excedentes nunca tiveram faturamento
 *    medido e entravam no gráfico como "Não vendeu".
 *
 * Com o roster congelado, o mês fechado lê o próprio estado em vez de ser reinterpretado
 * a cada mudança de hoje. Alimentado por `polos:congelar-roster` (diário no mês corrente;
 * `--do-log` reconstrói um mês passado desfazendo o activity_log).
 *
 * Decisões de schema:
 *  - SEM foreign key para mlb_empresas: empresa pode ser removida do cadastro e o
 *    histórico do mês não pode sumir junto (nem virar NULL). A coluna é referência, não
 *    integridade — e evita as armadilhas 1830/1553 de MariaDB documentadas nos learnings.
 *  - `fase` como string(50), não enum: o projeto trata fase como string livre (fases novas
 *    como "Protocolo Churn" nascem sem migration), e enum exigiria branch p/ o SQLite dos
 *    testes.
 *  - Nome do índice único fica em 41 chars, bem abaixo do limite de 64 do MariaDB.
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_roster_snapshots')) {
            return;
        }

        Schema::create('polos_roster_snapshots', function (Blueprint $table) {
            $table->id();

            // 'YYYYMM' — mesmo formato do $mesSel do controller e de polos_faturamento_snapshots.
            $table->string('mes', 6)->index();

            // Normalizado por App\Support\CustId::normaliza — casa com MlbEmpresa.cust_id
            // e com a chave de polos_faturamento_snapshots.
            $table->string('cust_id', 50);

            // Referência ao cadastro (sem FK — ver docblock).
            $table->unsignedBigInteger('mlb_empresa_id')->nullable();

            // Estado da empresa NAQUELE mês (não o de hoje).
            $table->string('nome');
            $table->string('fase', 50);
            $table->string('polo', 120)->nullable();

            // Flags que afetam status/meta, congelados junto: o CSV não os tem, e ler os
            // de hoje repetiria o mesmo erro de reinterpretar o passado.
            $table->boolean('problema')->default(false);
            $table->boolean('problema_desconsidera_meta')->default(false);
            $table->boolean('ads_desligado')->nullable();

            // Quando o congelamento foi feito, e por qual caminho ('vivo' | 'log').
            $table->timestamp('congelado_em')->nullable();
            $table->string('origem', 10)->default('vivo');

            $table->timestamps();

            // 1 linha por empresa por mês — o comando faz updateOrCreate sobre esta chave.
            $table->unique(['mes', 'cust_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_roster_snapshots');
    }
};
