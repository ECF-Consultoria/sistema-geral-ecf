<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `etapa` — o bloco em que o passo aparece (acessos · mapeamento ·
 * agendamento · administrativo), tanto no painel interno quanto no portal do
 * cliente.
 *
 * É ESTRUTURAL, como `dono` e `sla_dias`: por isso vira coluna copiada da
 * definição no nascimento, e não uma consulta ao código na hora de renderizar.
 * Um deploy de definição nova não pode reorganizar a tela debaixo de quem já
 * está no meio do onboarding, e um passo de versão antiga cuja chave saiu do
 * código precisa continuar sabendo a que bloco pertence.
 *
 * `nullable` de propósito: linha órfã de versão futura nunca deve derrubar um
 * INSERT — o front trata `null` como "outros".
 *
 * varchar + constante em PHP, nunca `enum` — enum em migration exige branch de
 * SQLite e quebra a suíte (learnings §6).
 */
return new class extends Migration
{
    /**
     * Mapa de backfill. Cobre as 15 chaves da v6 MAIS as chaves mortas das
     * v1..v4 (`ficha_cliente_recebida`, `ficha_conta_preenchida`), para que os
     * onboardings nascidos antes não fiquem sem bloco na tela.
     */
    private const ETAPA_POR_CHAVE = [
        'grupo_criado'               => 'administrativo',
        'mensagem_boas_vindas'       => 'administrativo',
        'confirmacao_pagamento'      => 'administrativo',

        'grant_sistema_ecf'          => 'acessos',
        'acesso_colaborador_ml'      => 'acessos',
        'planilha_custos_adman'      => 'acessos',
        'grant_consultoria_adman'    => 'acessos',

        'metricas_da_conta'          => 'mapeamento',
        'anuncios_ativos_inativos'   => 'mapeamento',
        'excluir_anuncios_inativos'  => 'mapeamento',
        'custos_app_ecf'             => 'mapeamento',
        'grant_de_ads'               => 'mapeamento',

        'agendar_reuniao_onboarding' => 'agendamento',
        'relatorio_inicial'          => 'agendamento',
        'reuniao_realizada'          => 'agendamento',

        // Chaves mortas (v1..v4) — mantidas só para o backfill não deixar
        // buraco em onboarding antigo.
        'ficha_cliente_recebida'     => 'mapeamento',
        'ficha_conta_preenchida'     => 'mapeamento',
    ];

    public function up(): void
    {
        Schema::table('onboarding_passos', function (Blueprint $table) {
            $table->string('etapa', 20)->nullable()->after('ordem');
        });

        // `DB::table()->where()->update()` puro — `UPDATE <tabela> <alias> SET`
        // derruba a suíte no SQLite (learnings §6).
        foreach (self::ETAPA_POR_CHAVE as $chave => $etapa) {
            DB::table('onboarding_passos')
                ->where('chave', $chave)
                ->update(['etapa' => $etapa]);
        }
    }

    public function down(): void
    {
        Schema::table('onboarding_passos', function (Blueprint $table) {
            $table->dropColumn('etapa');
        });
    }
};
