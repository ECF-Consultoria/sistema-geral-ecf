<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260805-eqk — captura de Notes do deal + Origem do lead do contato.
 *
 * `hubspot_notas`: lista JSON das Notes (observações) associadas ao DEAL, cada
 * item com {id, body, timestamp}. Espelho do HubSpot — sempre reescrito pelo
 * webhook, nunca editado à mão. O texto consolidado continua em
 * `hubspot_observacao` (coluna já existente, Fase 111).
 *
 * `origem_lead`: property `origem_do_lead` do CONTATO principal (ex.: "Parceiro
 * de Polos"). Diferente das notas, segue a regra normal de enriquecimento —
 * o Comercial pode corrigir à mão e dado manual é soberano.
 *
 * Sem índice: não há consulta filtrando por essas colunas.
 * Migration DEFENSIVA (Schema::hasColumn nos dois sentidos) — a árvore de
 * trabalho é compartilhada e o banco local pode estar em estados diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'hubspot_notas')) {
                $table->json('hubspot_notas')->nullable();
            }

            if (! Schema::hasColumn('companies', 'origem_lead')) {
                $table->string('origem_lead', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['hubspot_notas', 'origem_lead'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
