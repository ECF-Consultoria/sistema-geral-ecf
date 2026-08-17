<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Fase 126 (D-03). A Fase 125 criou
// `contrato_assinaturas` sem coluna de caminho de arquivo — buraco
// descoberto no discuss desta fase. É a Fase 126 que passa a gerar o PDF,
// então é ela que cria onde anotar:
//   `pdf_path`          — caminho do PDF gerado, o que vai para a Clicksign.
//   `pdf_assinado_path` — nasce SEM uso nesta fase. É a Fase 129 (webhook
//   Clicksign) que a preenche ao baixar o PDF assinado.
//
// ⚠️ Esta é uma migration `Schema::table(...)` sobre tabela que JÁ EXISTE em
// produção (batches 110/111 criaram `contrato_assinaturas`). Uma migration
// aditiva que não pode ser re-executada com segurança vira incidente de
// deploy — por isso `up()` e `down()` são guardados por `Schema::hasColumn`,
// e nada aqui é `Schema::create` nem `dropColumn` de coluna alheia.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 125-CONTEXT.md) — nenhuma se aplica nesta migration, motivo registrado:
//   1. `enum` + SQLite (CHECK derruba a suíte) — não usado; as duas colunas
//      são `string`, sem restrição de valor.
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — esta migration
//      não declara nenhuma FK nova.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — esta migration
//      não declara nenhum índice: as colunas são carga (caminho de arquivo),
//      não critério de busca.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'pdf_path')) {
                $table->string('pdf_path', 255)->nullable()->after('servicos_snapshot');
            }

            if (! Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_path')) {
                $table->string('pdf_assinado_path', 255)->nullable()->after('pdf_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_path')) {
                $table->dropColumn('pdf_assinado_path');
            }

            if (Schema::hasColumn('contrato_assinaturas', 'pdf_path')) {
                $table->dropColumn('pdf_path');
            }
        });
    }
};
