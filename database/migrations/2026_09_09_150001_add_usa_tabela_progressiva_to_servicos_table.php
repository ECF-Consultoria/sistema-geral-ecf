<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 141 Plano 01 (D-02/D-04): `servicos.usa_tabela_progressiva` passa a
 * ser a resposta OFICIAL para "este serviço é cobrado por tabela
 * progressiva?" — não mais a existência de linhas em
 * `servico_faixas_faturamento` (Fase 137), que era um efeito colateral do
 * cadastro, não uma decisão.
 *
 * `default(false)` é o default SEGURO: na dúvida, um serviço novo NÃO entra
 * na soma de faturamento que define faixa — a leitura errada seguraria (não
 * cobraria) o dinheiro certo, nunca inventaria cobrança maior. É também o
 * único default compatível com o `ADD COLUMN ... NOT NULL` do SQLite (suíte
 * de testes) sem valor explícito por linha.
 *
 * O backfill liga a coluna para todo `servico_id` que já tem pelo menos uma
 * faixa cadastrada — hoje: Gestão (id 6), Gestão de ADS Shopee (id 9) e
 * Brigada (id 10), conforme medido em produção em 2026-09-02 (docblock de
 * `FechamentoFaixaResolver`). Mentoria não tem faixa nenhuma e continua
 * `false`. Guard `Schema::hasTable('servico_faixas_faturamento')` porque em
 * base nova (SQLite dos testes) a ordem de execução das migrations pode
 * rodar esta ANTES da que cria a tabela de faixas — sem o guard o backfill
 * quebraria com "no such table" num ambiente limpo.
 *
 * Nenhuma armadilha de MariaDB se aplica: não é `enum()` (quebra SQLite),
 * não cria FK, não cria índice — só um booleano simples, mesmo molde da
 * migration de `exige_contrato` (2026_08_13_100001).
 *
 * Idempotente: guard `Schema::hasColumn` no up() e no down() — rodar duas
 * vezes não quebra nem duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('servicos', 'usa_tabela_progressiva')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->boolean('usa_tabela_progressiva')->default(false)->after('plataforma');
            });
        }

        if (Schema::hasTable('servico_faixas_faturamento')) {
            $servicoIdsComFaixa = DB::table('servico_faixas_faturamento')
                ->distinct()
                ->pluck('servico_id');

            if ($servicoIdsComFaixa->isNotEmpty()) {
                DB::table('servicos')
                    ->whereIn('id', $servicoIdsComFaixa)
                    ->update(['usa_tabela_progressiva' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicos', 'usa_tabela_progressiva')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->dropColumn('usa_tabela_progressiva');
            });
        }
    }
};
