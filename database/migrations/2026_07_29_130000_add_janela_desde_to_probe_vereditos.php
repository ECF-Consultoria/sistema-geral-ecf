<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registra no veredito do probe qual recorte temporal foi usado.
 *
 * O gate MPP-04 avalia a cobertura da PIOR rodada. Sem um recorte, uma rodada
 * reprovada permanece para sempre como a pior e o veredito nunca mais poderia
 * virar `aprovado` — nem depois de o problema ser corrigido (foi o caso do fix
 * de resiliência de 2026-07-29, quick 20260729-adman-retry-resiliente).
 *
 * A flag `--desde=` resolve isso, mas só é legítima se ficar AUDITÁVEL: quem
 * ler o veredito precisa ver que a janela foi recortada e a partir de quando.
 * Nulo = avaliou todo o histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adman_probe_margem_prev_vereditos', function (Blueprint $table) {
            $table->timestamp('janela_desde')->nullable()->after('gerado_em');
        });
    }

    public function down(): void
    {
        Schema::table('adman_probe_margem_prev_vereditos', function (Blueprint $table) {
            $table->dropColumn('janela_desde');
        });
    }
};
