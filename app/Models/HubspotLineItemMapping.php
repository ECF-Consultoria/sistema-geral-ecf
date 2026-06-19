<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 37 Plan 37-02 (REQ-37-02) — mapeamento line_item.name (HubSpot) → Servico do catálogo.
 *
 * Consumido pelo HubspotWebhookController (Plan 37-04) ao processar line items do deal
 * closedwon: cada line_item.name vindo da API HubSpot eh resolvido para o Servico canonico
 * via paraNome() antes da criacao do ContratoServico.
 *
 * Admin gerencia via /sistema/hubspot-line-items (Plan 37-07) — pode cadastrar mapeamentos
 * novos sem deploy quando o Comercial cria nomes de line item novos no HubSpot, ou desativar
 * mapeamentos obsoletos sem perder a auditoria.
 *
 * Match case-insensitive — HubSpot manda variacoes livres ("MAP PREMIUM", "Map Premium",
 * "map premium"); o LOWER comparison no paraNome() trata todas como equivalentes.
 */
class HubspotLineItemMapping extends Model
{
    protected $table = 'hubspot_line_item_mapping';

    protected $fillable = [
        'line_item_name',
        'servico_id',
        'ativo',
        'observacoes',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    /**
     * Servico canonico do catalogo associado a este mapping.
     *
     * FK cascadeOnDelete na migration garante que deletar o Servico apaga o
     * mapping junto — relacao nunca aponta para null em runtime.
     */
    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class);
    }

    /**
     * Scope: apenas mappings ativos.
     *
     * Usado pelo paraNome() e pela UI admin (Plan 37-07) ao filtrar a listagem.
     */
    public function scopeAtivo($query)
    {
        return $query->where('ativo', true);
    }

    /**
     * Resolve um line_item.name (HubSpot) para o mapping correspondente do catalogo.
     *
     * Matching em 2 camadas (rastreado em prod com deal real 61293024061 / Galiloja):
     *
     *  1. EXATO — comparacao case-insensitive + trim no input. Cobre o caso ideal
     *     em que o Comercial cadastra o line item HubSpot com o nome canonico
     *     ("Gestão", "Mentoria", "MAP PREMIUM").
     *
     *  2. SUBSTRING — quando o exato falha, percorre os mappings ATIVOS em ordem
     *     decrescente de comprimento e retorna o primeiro cujo `line_item_name`
     *     aparece como substring no input. Pega variacoes naturais que o
     *     comercial digita no HubSpot mas que mapeiam para o mesmo Servico:
     *
     *        input "Gestão de Ads "       -> mapping "Gestão"        -> match
     *        input "MAP PREMIUM Trimestre"-> mapping "MAP PREMIUM"   -> match
     *        input "Polo Pleno 12 meses"  -> mapping "Polo"           -> match
     *
     *     A ordem por CHAR_LENGTH(line_item_name) DESC evita que "MAP" bata antes
     *     de "MAP PREMIUM" quando ambos existem no input ("MAP PREMIUM" tem
     *     11 chars, "MAP" tem 3).
     *
     * Eager-load servico para evitar N+1 no consumidor (Plan 37-04). Ignora
     * mappings inativos. Retorna null quando ambas camadas falham — o webhook
     * grava warning em HubspotEvento.payload['line_items_nao_mapeados'] e a
     * empresa fica visivel em /comercial/empresas/listagem com a pendencia
     * "servico_nao_reconhecido" pro Comercial cadastrar via UI admin
     * (/sistema/hubspot-line-items, Plan 37-07).
     */
    public static function paraNome(string $nome): ?self
    {
        $nomeNormalizado = trim($nome);
        if ($nomeNormalizado === '') {
            return null;
        }

        // Camada 1 — match exato (case-insensitive).
        $exato = self::ativo()
            ->whereRaw('LOWER(line_item_name) = LOWER(?)', [$nomeNormalizado])
            ->with('servico')
            ->first();
        if ($exato) {
            return $exato;
        }

        // Camada 2 — fallback substring. Carrega todos os mappings ativos
        // (poucos registros — UI admin) ordenados por especificidade decrescente
        // e retorna o primeiro contido no input. mb_strtolower preserva
        // acentos (importante: "Gestão" tem 'ã').
        $nomeLower = mb_strtolower($nomeNormalizado);
        $mappings = self::ativo()
            ->with('servico')
            ->orderByRaw('LENGTH(line_item_name) DESC')
            ->get();

        foreach ($mappings as $m) {
            $mLower = mb_strtolower(trim($m->line_item_name));
            if ($mLower === '') {
                continue;
            }
            if (mb_strpos($nomeLower, $mLower) !== false) {
                return $m;
            }
        }

        return null;
    }
}
