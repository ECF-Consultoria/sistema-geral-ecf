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
     * Match case-insensitive via LOWER comparison — HubSpot manda variacoes livres
     * ("MAP PREMIUM", "Map Premium", "map premium"); a UI admin permite cadastrar em
     * qualquer caixa. Eager-load servico para evitar N+1 no consumidor (Plan 37-04).
     *
     * Ignora mappings com ativo=false (admin pode desativar sem deletar).
     *
     * Retorna null quando:
     *  - nao existe mapping para o nome (admin deve cadastrar via UI Plan 37-07);
     *  - existe mapping mas esta inativo (admin desativou intencionalmente).
     *
     * Em ambos os casos, o webhook (Plan 37-04) registra a empresa com a flag
     * "servico_nao_reconhecido" para aparecer na listagem comercial (Plan 37-05).
     */
    public static function paraNome(string $nome): ?self
    {
        return self::ativo()
            ->whereRaw('LOWER(line_item_name) = LOWER(?)', [trim($nome)])
            ->with('servico')
            ->first();
    }
}
