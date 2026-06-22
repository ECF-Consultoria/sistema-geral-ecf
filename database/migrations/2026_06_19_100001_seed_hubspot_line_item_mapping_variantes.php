<?php

use App\Models\HubspotLineItemMapping;
use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 37 hotfix 2026-06-19 — adiciona variantes nominais de line items
 * observadas no HubSpot em producao alem do seed canonico do Plan 37-02.
 *
 * Contexto: o seed Plan 37-02 cadastrou apenas nomes canonicos (Gestão,
 * Mentoria, Publicação, MAP, MAP PREMIUM, Polo, Brigada). No teste real
 * do deal 61293024061 (Galiloja teste) o Comercial cadastrou o line item
 * como "Gestão de Ads " — paraNome com match exato retornou NULL e a
 * empresa caiu na pendencia "servico_nao_reconhecido".
 *
 * O `paraNome` ja foi ajustado neste mesmo hotfix para fazer fallback
 * substring (input "Gestão de Ads " contem mapping "Gestão" -> match).
 * Esta migration eh complementar: adiciona variantes EXPLICITAS para
 * deixar a auditoria mais legivel na UI admin /sistema/hubspot-line-items.
 *
 * Idempotente via firstOrCreate por line_item_name (case-sensitive a nivel
 * de unique no banco — collation utf8mb4_unicode_ci normaliza acentos no
 * unique, entao "Gestão de Ads" colapsa com "GESTÃO DE ADS").
 */
return new class extends Migration
{
    public function up(): void
    {
        $servicoGestao    = Servico::where('nome', 'Gestão')->first();
        $servicoMentoria  = Servico::where('nome', 'Mentoria')->first();
        $servicoPublicacao = Servico::where('nome', 'Publicação')->first();
        $servicoPolos     = Servico::where('nome', 'Polos')->first();

        $variantes = [];

        if ($servicoGestao) {
            $variantes[] = ['line_item_name' => 'Gestão de Ads',     'servico_id' => $servicoGestao->id];
            $variantes[] = ['line_item_name' => 'Gestão Anual',      'servico_id' => $servicoGestao->id];
            $variantes[] = ['line_item_name' => 'Gestão Trimestral', 'servico_id' => $servicoGestao->id];
        }

        if ($servicoMentoria) {
            $variantes[] = ['line_item_name' => 'Mentoria Anual',      'servico_id' => $servicoMentoria->id];
            $variantes[] = ['line_item_name' => 'Mentoria Trimestral', 'servico_id' => $servicoMentoria->id];
        }

        if ($servicoPublicacao) {
            $variantes[] = ['line_item_name' => 'Publicação Mensal',  'servico_id' => $servicoPublicacao->id];
            $variantes[] = ['line_item_name' => 'Publicação Anual',   'servico_id' => $servicoPublicacao->id];
        }

        if ($servicoPolos) {
            $variantes[] = ['line_item_name' => 'Polo Pleno',         'servico_id' => $servicoPolos->id];
            $variantes[] = ['line_item_name' => 'Polo Pré-Pleno',     'servico_id' => $servicoPolos->id];
            $variantes[] = ['line_item_name' => 'Polo Iniciante',     'servico_id' => $servicoPolos->id];
        }

        foreach ($variantes as $v) {
            HubspotLineItemMapping::firstOrCreate(
                ['line_item_name' => $v['line_item_name']],
                [
                    'servico_id'  => $v['servico_id'],
                    'ativo'       => true,
                    'observacoes' => 'Adicionado pelo hotfix 2026-06-19 (variantes observadas em prod).',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('hubspot_line_item_mapping')
            ->where('observacoes', 'Adicionado pelo hotfix 2026-06-19 (variantes observadas em prod).')
            ->delete();
    }
};
