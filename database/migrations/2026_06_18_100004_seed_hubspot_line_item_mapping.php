<?php

use App\Models\HubspotLineItemMapping;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 37 Plan 37-02 (REQ-37-02) — seed inicial idempotente.
 *
 * Cobre as familias canonicas de line_item do HubSpot ECF observadas no historico:
 *  - 'MAP' / 'MAP PREMIUM' → Servico 'Gestão' (Mentoria Acelerada Premium)
 *  - 'Polo' / 'POLO'       → Servico 'Polos'
 *  - 'Brigada'             → Servico 'Gestão' (cliente legado, sem servico proprio)
 *  - 'Gestão'              → Servico 'Gestão'
 *  - 'Mentoria'            → Servico 'Mentoria' (fallback 'Gestão' se nao existir)
 *  - 'Publicação'          → Servico 'Publicação'
 *
 * Novos mapeamentos sao criados via UI admin (/sistema/hubspot-line-items, Plan 37-07)
 * sem deploy quando o Comercial cadastra nomes novos no HubSpot.
 *
 * Idempotente por design via firstOrCreate por line_item_name — re-rodar nao duplica
 * e nao sobrescreve mappings criados pelo admin via UI (preserva ajustes manuais).
 *
 * Pula silenciosamente quando o Servico alvo nao existe no catalogo — Phase 14 garante
 * os 6 nomes canonicos (Publicacao/Polos/Assessoria/Incubadora/Publicidade/Gestao) e
 * Phase 37 Plan 37-01 pode adicionar 'Mentoria'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Resolve servicos do catalogo por nome canonico (Title Case — D-02 Phase 14).
            $servicos = DB::table('servicos')
                ->whereIn('nome', ['Gestão', 'Polos', 'Publicação', 'Mentoria'])
                ->pluck('id', 'nome');

            $gestao     = $servicos['Gestão']     ?? null;
            $polos      = $servicos['Polos']      ?? null;
            $publicacao = $servicos['Publicação'] ?? null;
            // Mentoria pode nao existir no catalogo Phase 14 — usar fallback Gestao.
            $mentoria   = $servicos['Mentoria']   ?? $gestao;

            // Mapeamento (line_item_name → servico_id). Pulamos pares sem Servico alvo
            // — re-rodar a migration depois que o catalogo for completado preenche os
            // pares pulados (firstOrCreate).
            $mapeamentos = [
                'MAP'         => $gestao,
                'MAP PREMIUM' => $gestao,
                'Polo'        => $polos,
                'POLO'        => $polos,
                'Brigada'     => $gestao,
                'Gestão'      => $gestao,
                'Mentoria'    => $mentoria,
                'Publicação'  => $publicacao,
            ];

            foreach ($mapeamentos as $nome => $servicoId) {
                if ($servicoId === null) {
                    continue;
                }

                // firstOrCreate (NAO updateOrCreate) — preserva ajustes manuais de
                // ativo/observacoes feitos via UI admin pos-deploy.
                HubspotLineItemMapping::firstOrCreate(
                    ['line_item_name' => $nome],
                    [
                        'servico_id' => $servicoId,
                        'ativo'      => true,
                    ],
                );
            }
        });
    }

    public function down(): void
    {
        // Apaga apenas os mappings seedados — mappings criados via UI admin sao
        // preservados (preservacao de trabalho manual em prod).
        DB::table('hubspot_line_item_mapping')
            ->whereIn('line_item_name', [
                'MAP', 'MAP PREMIUM', 'Polo', 'POLO',
                'Brigada', 'Gestão', 'Mentoria', 'Publicação',
            ])
            ->delete();
    }
};
