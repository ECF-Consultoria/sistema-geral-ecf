<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 141 Plano 03 (D-04/D-05) — acrescenta procedência a `empresa_faixas_faturamento`: de onde veio
 * a tabela desta empresa — cadastro manual, confirmação de contrato lido do Clicksign (Fase 140), ou
 * presunção copiada da tabela do serviço na virada desta fase (Tarefa 2).
 *
 * `origem` — `string(20)`, NUNCA `enum()`: enum quebra o SQLite dos testes e engessa a lista de
 * valores (armadilha registrada no aprendizado do projeto). Valores aceitos, definidos como
 * constantes em `EmpresaFaixaFaturamento`: `'manual'` (default — toda linha existente hoje É
 * cadastro humano), `'contrato'`, `'presumida_servico'`.
 *
 * `servico_origem_id` — `unsignedBigInteger` nullable, SEM foreign key de propósito: é um carimbo de
 * procedência (de qual serviço a tabela foi copiada), não uma restrição relacional. FK aqui só
 * traria as armadilhas de MariaDB que o SQLite dos testes não pega — `nullOnDelete()` exige a coluna
 * `nullable()` antes, senão o MariaDB de produção recusa com erro 1830 (não pega no SQLite), e
 * alterar índice usado por FK falha com erro 1553. Nenhuma dessas armadilhas se aplica porque não há
 * FK nenhuma aqui.
 *
 * Sem índice novo: não há consulta por `origem`/`servico_origem_id` em caminho quente — o comando de
 * materialização (Tarefa 2) varre por `company_id`, já indexado pelo unique existente.
 *
 * Migration idempotente: guard `Schema::hasColumn` no `up()` e no `down()` evita erro em rerun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresa_faixas_faturamento', function (Blueprint $table) {
            if (! Schema::hasColumn('empresa_faixas_faturamento', 'origem')) {
                $table->string('origem', 20)->default('manual')->after('valor_e_piso');
            }

            if (! Schema::hasColumn('empresa_faixas_faturamento', 'servico_origem_id')) {
                $table->unsignedBigInteger('servico_origem_id')->nullable()->after('origem');
            }
        });
    }

    public function down(): void
    {
        Schema::table('empresa_faixas_faturamento', function (Blueprint $table) {
            if (Schema::hasColumn('empresa_faixas_faturamento', 'servico_origem_id')) {
                $table->dropColumn('servico_origem_id');
            }

            if (Schema::hasColumn('empresa_faixas_faturamento', 'origem')) {
                $table->dropColumn('origem');
            }
        });
    }
};
