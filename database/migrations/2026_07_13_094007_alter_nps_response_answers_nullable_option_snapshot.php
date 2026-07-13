<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna `option_label_snapshot` e `option_peso_snapshot` NULLABLE em
 * `nps_response_answers` — pré-requisito pro tipo de pergunta `texto_livre`
 * (2026-07-13).
 *
 * Perguntas texto_livre não têm opção — o valor digitado pelo respondente
 * é gravado em `comentario` (já nullable). Para não quebrar a semântica das
 * perguntas escala/opcoes, mantemos os campos como opcional apenas — a
 * validação de "opção obrigatória para tipo escala/opcoes" fica no
 * NpsController::submitResponseV15 (rules dinâmicas por pergunta).
 *
 * Rollback re-obriga os campos MAS restringe o downgrade quando ainda há
 * answers texto_livre gravadas (que não têm opção). Nesse caso é preciso
 * apagar/converter antes do rollback — proteção contra corromper dados
 * históricos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nps_response_answers', function (Blueprint $table) {
            $table->string('option_label_snapshot', 200)
                ->nullable()
                ->comment('Label da opção selecionada — NULL quando tipo=texto_livre')
                ->change();

            $table->unsignedTinyInteger('option_peso_snapshot')
                ->nullable()
                ->comment('Peso da opção — NULL quando tipo=texto_livre; AVG(NOT NULL) alimenta o score')
                ->change();
        });
    }

    public function down(): void
    {
        // Guard: se ainda há answers texto_livre (com peso NULL), rollback
        // quebraria constraint NOT NULL. Aborta com mensagem — admin precisa
        // limpar/converter antes.
        $orfaos = \DB::table('nps_response_answers')
            ->whereNull('option_peso_snapshot')
            ->count();

        if ($orfaos > 0) {
            throw new \RuntimeException(
                "[Migration rollback] {$orfaos} answers com option_peso_snapshot NULL "
                . "(tipo=texto_livre). Apague ou converta antes do rollback."
            );
        }

        Schema::table('nps_response_answers', function (Blueprint $table) {
            $table->string('option_label_snapshot', 200)
                ->nullable(false)
                ->comment('Label da opção selecionada congelado')
                ->change();

            $table->unsignedTinyInteger('option_peso_snapshot')
                ->nullable(false)
                ->comment('Peso da opção congelado — AVG deste campo alimenta o score')
                ->change();
        });
    }
};
