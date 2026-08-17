<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Fase 129 plano 06 (CLICK-11, D-14).
//
// Divergência DELIBERADA da D-14: a decisão pede um "estado próprio" de
// "assinado, PDF pendente", mas somar um 8º valor a
// `ContratoAssinatura::STATUS_*` colidiria com a D-06 da Fase 125 (um
// contrato tem UM estado, e `assinado` é justamente o que destrava a
// liberação) — e a própria D-14 exige que a falha de download NÃO prenda a
// empresa, ou seja, o contrato precisa CONTINUAR `assinado`. Logo o "estado
// próprio" é a COMBINAÇÃO `status = 'assinado'` + `pdf_assinado_path IS
// NULL`, e esta coluna nova (`pdf_assinado_erro`) distingue "ainda não
// tentou baixar" de "tentou e desistiu depois de esgotar as tentativas". É
// esse par (`status`, `pdf_assinado_path`, `pdf_assinado_erro`) que o alerta
// de contrato preso da Fase 130 (REDE-03) vai varrer.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 125-CONTEXT.md) — nenhuma se aplica nesta migration, motivo registrado:
//   1. `enum` + SQLite (CHECK derruba a suíte) — não usado; coluna `text`
//      livre, sem restrição de valor (é mensagem de erro pruneada, não
//      vocabulário fechado).
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — esta migration
//      não declara nenhuma FK nova.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — esta migration
//      não declara nenhum índice: a coluna é carga (texto de erro), não
//      critério de busca.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_erro')) {
                $table->text('pdf_assinado_erro')->nullable()->after('pdf_assinado_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_erro')) {
                $table->dropColumn('pdf_assinado_erro');
            }
        });
    }
};
