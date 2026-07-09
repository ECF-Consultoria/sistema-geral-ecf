<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v15.5 — Semeia chaves de Configuracao para o envio automático de NPS.
 *
 * Chaves inseridas (idempotente via INSERT IGNORE):
 *  - nps_envio_email_ativo         — string '0'|'1' — controla envio via email (default '1' = comportamento atual)
 *  - nps_envio_digisac_ativo       — string '0'|'1' — controla envio via WhatsApp (default '0' = opt-in explicito)
 *  - nps_digisac_service_id        — string — serviceId padrão para envio WhatsApp
 *  - nps_digisac_user_id           — string — userId padrão para origem do envio
 *  - nps_digisac_mensagem_default  — string — texto fallback usado quando o template NÃO define mensagem_whatsapp
 *
 * Persistidas em `configuracoes` (Model App\Models\Configuracao — chave/valor).
 * Consumidas por NpsDigisacDispatchService e pela página /nps/envio-automatico.
 *
 * Segue mesmo padrão da migration
 * `2026_06_11_200002_seed_nps_textos_configuracao.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $mensagemPadrao = <<<TXT
Olá! Este é o link da pesquisa de satisfação ECF referente a *{mes_referencia}*.

Sua opinião ajuda o time a evoluir o serviço da *{nome_empresa}*.

Levem menos de 2 minutos para responder — {link_nps}

Obrigado!
TXT;

        $seeds = [
            ['chave' => 'nps_envio_email_ativo',         'valor' => '1'],
            ['chave' => 'nps_envio_digisac_ativo',       'valor' => '0'],
            ['chave' => 'nps_digisac_service_id',        'valor' => ''],
            ['chave' => 'nps_digisac_user_id',           'valor' => ''],
            ['chave' => 'nps_digisac_mensagem_default',  'valor' => $mensagemPadrao],
        ];

        foreach ($seeds as $row) {
            $existe = DB::table('configuracoes')->where('chave', $row['chave'])->exists();
            if (! $existe) {
                DB::table('configuracoes')->insert([
                    'chave'      => $row['chave'],
                    'valor'      => $row['valor'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('configuracoes')->whereIn('chave', [
            'nps_envio_email_ativo',
            'nps_envio_digisac_ativo',
            'nps_digisac_service_id',
            'nps_digisac_user_id',
            'nps_digisac_mensagem_default',
        ])->delete();
    }
};
