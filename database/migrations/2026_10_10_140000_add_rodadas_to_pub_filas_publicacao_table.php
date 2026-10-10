<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10/10/2026 — a fila de publicação em lote passa a andar em RODADAS. Pedido do usuário (10/10): o intervalo
 * existe para não subir anúncio "na porrada" no Mercado Livre, e o jeito dele é "sobe cinco de uma vez (Clássico
 * e Premium), depois de uns 20 minutos mais cinco". Antes a fila publicava UM produto a cada 10 minutos.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes de a migration existir. `pub_filas_publicacao` nasce
 * no MESMO deploy (`2026_10_10_100000`) e não tem dado em produção: três colunas novas, todas com default ou NULL —
 * nenhum backfill, nenhuma linha reescrita.
 *
 * | coluna              | tipo                        | regra                                                         |
 * |---------------------|-----------------------------|---------------------------------------------------------------|
 * | produtos_por_rodada | unsignedSmallInteger def. 1 | quantos produtos começam juntos; o serviço grava o da config (5) |
 * | rodada_iniciada_em  | dateTime NULL               | quando a rodada em curso começou (NULL = nenhuma ainda)        |
 * | rodada_inicios      | unsignedSmallInteger def. 0 | quantos produtos já começaram na rodada em curso               |
 *
 * Default 1 no banco de propósito: linha criada fora do serviço anda como antes (um por vez). O `intervalo_minutos`
 * passa a contar do início de uma RODADA ao da próxima (a coluna é a mesma). `dateTime`, nunca `timestamp()`, como
 * as datas avulsas da `2026_10_10_100000`. `after()` só arruma a ordem no MariaDB (o SQLite ignora). Idempotente
 * (`hasColumn`); o `down()` tira só as três.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_filas_publicacao')) {
            return;
        }

        $faltam = array_values(array_filter(
            ['produtos_por_rodada', 'rodada_iniciada_em', 'rodada_inicios'],
            fn (string $c) => ! Schema::hasColumn('pub_filas_publicacao', $c),
        ));
        if ($faltam === []) {
            return;
        }

        Schema::table('pub_filas_publicacao', function (Blueprint $t) use ($faltam) {
            if (in_array('produtos_por_rodada', $faltam, true)) {
                $t->unsignedSmallInteger('produtos_por_rodada')->default(1)->after('intervalo_minutos');
            }
            if (in_array('rodada_iniciada_em', $faltam, true)) {
                $t->dateTime('rodada_iniciada_em')->nullable()->after('proximo_em');
            }
            if (in_array('rodada_inicios', $faltam, true)) {
                $t->unsignedSmallInteger('rodada_inicios')->default(0)->after('rodada_iniciada_em');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pub_filas_publicacao')) {
            return;
        }

        $existem = array_values(array_filter(
            ['produtos_por_rodada', 'rodada_iniciada_em', 'rodada_inicios'],
            fn (string $c) => Schema::hasColumn('pub_filas_publicacao', $c),
        ));
        if ($existem !== []) {
            Schema::table('pub_filas_publicacao', fn (Blueprint $t) => $t->dropColumn($existem));
        }
    }
};
