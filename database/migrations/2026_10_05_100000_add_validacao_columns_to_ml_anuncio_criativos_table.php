<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas de validação automática (Fase 162, D-06/VAL-01..06) em
 * `ml_anuncio_criativos`. Molde LITERAL de
 * `2026_10_03_170000_add_regenerar_motivos_to_ml_anuncio_criativos_table.php`:
 * `up()`/`down()` idempotentes por `Schema::hasColumn()`, sem lista fixa de
 * valores no banco (nada de `enum`), sem FK, sem índice novo — evita de saída
 * a armadilha do MariaDB 1059 (nome de índice acima de 64 chars).
 *
 * Há DADO EM PRODUÇÃO (12 criativos, 1 kit) — toda coluna nova é
 * `nullable()` ou tem `default()`. MariaDB não aceita DEFAULT em coluna
 * JSON, por isso `validacao` é só `nullable()`.
 *
 * `validacao_status` NULL significa "nunca passou por validação" — é o
 * estado dos 12 criativos que já existem hoje. Nunca confundir com
 * reprovada: só os 4 valores de `MlAnuncioCriativo::VALIDACAO_*` entram
 * aqui depois do primeiro julgamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativos', 'validacao_status')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            // NULL = nunca validado. aprovada|reprovada|indisponivel depois do juiz.
            $table->string('validacao_status', 20)->nullable()->after('regenerar_motivos');
            // Veredito estruturado completo (VAL-03) — ver CreativeValidacao::paraColuna().
            $table->json('validacao')->nullable()->after('validacao_status');
            // Quantas chamadas de JUIZ este asset já consumiu (OPS-02 + teto do VAL-06) —
            // separada de `tentativas` e `regeneracoes`: contadores de coisas diferentes.
            $table->unsignedInteger('validacoes')->default(0)->after('validacao');
            // VAL-05 acontece UMA vez por asset — esta coluna impede o loop.
            $table->boolean('regeneracao_automatica')->default(false)->after('validacoes');
            // Base da trava de tempo (molde LIMITE_VALIDACAO_MINUTOS).
            $table->timestamp('validacao_pedida_em')->nullable()->after('regeneracao_automatica');
            // Quando o veredito chegou.
            $table->timestamp('validacao_em')->nullable()->after('validacao_pedida_em');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ml_anuncio_criativos', 'validacao_status')) {
            return;
        }

        Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
            $table->dropColumn([
                'validacao_status',
                'validacao',
                'validacoes',
                'regeneracao_automatica',
                'validacao_pedida_em',
                'validacao_em',
            ]);
        });
    }
};
