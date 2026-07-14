<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 79 Plan 01 — Adiciona colunas de rastreio de id por tier em ml_anuncio_rascunhos.
 *
 * Motivacao:
 *  - ml_item_id_classico: id do anuncio Classico (gold_special) publicado no ML.
 *    Preenchido por MlPublicacaoService::publicar() quando listing_tier='classico'.
 *    NÃO deve ser sobrescrito quando o tier Premium falha (campos independentes — DUP-04).
 *  - ml_item_id_premium: mesmo padrao, para o tier Premium (gold_pro).
 *
 * Retrocompatibilidade: o campo ml_item_id legado permanece intocado.
 * Publicacoes anteriores a esta migration continuam legíveis via ml_item_id.
 * A partir da Phase 79, publicacoes duplas gravam nos dois campos novos alem do legado.
 *
 * Idempotencia: guard Schema::hasColumn antes do ALTER — pode rodar duas vezes
 * sem erro, tanto no local (XAMPP/MariaDB) quanto na VPS (MySQL).
 *
 * GOTCHA: NUNCA rodar migrate completo no local — migration NPS tem GENERATED
 * (date_format) que quebra no MariaDB local (ano 1901). Rodar apenas via --path.
 *
 * @see app/Services/Mlb/Publicacao/MlPublicacaoService.php (publicar() grava $campoTier)
 * @see app/Http/Controllers/MlbAnuncioController.php (publicarDuplo, duplicarTier)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: verifica se a coluna ja existe antes de adicionar
        if (! Schema::hasColumn('ml_anuncio_rascunhos', 'ml_item_id_classico')) {
            Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
                // ml_item_id_classico: id do anuncio Classico (gold_special) publicado no ML.
                // nullable — nasce null; preenchido por MlPublicacaoService quando o tier
                // Classico e publicado com sucesso. Nao deve ser sobrescrito quando o tier
                // Premium falha (campos independentes — DUP-04).
                $table->string('ml_item_id_classico')
                    ->nullable()
                    ->after('ml_item_id')
                    ->comment('ID do anuncio Classico no ML (gold_special) — preenchido na publicacao do tier Classico (DUP-04)');

                // ml_item_id_premium: mesmo padrao, para o tier Premium (gold_pro).
                // A falha do Classico nao sobrescreve este campo, e vice-versa (DUP-04).
                $table->string('ml_item_id_premium')
                    ->nullable()
                    ->after('ml_item_id_classico')
                    ->comment('ID do anuncio Premium no ML (gold_pro) — preenchido na publicacao do tier Premium (DUP-04)');
            });
        }
    }

    /**
     * Reverte apenas o ALTER. Idempotente: so dropa se as colunas existirem.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ml_anuncio_rascunhos', 'ml_item_id_classico')) {
            Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
                $table->dropColumn(['ml_item_id_classico', 'ml_item_id_premium']);
            });
        }
    }
};
