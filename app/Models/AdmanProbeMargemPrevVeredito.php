<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent do veredito agregado do probe de estabilidade de
 * `percentageMargin.prev` da Adman (Fase 117, gate MPP-04).
 *
 * Cada linha é o resultado de UMA execução do modo `--relatorio` do comando
 * `adman:probe-margem-prev`, agregando as leituras de `AdmanProbeMargemPrevLeitura`
 * de uma competência (`periodo_key`). Insert-only: cada `--relatorio` gera uma
 * linha NOVA (nunca `updateOrCreate`) — o histórico de vereditos é parte da
 * auditoria do gate (D-10 do CONTEXT).
 *
 * Sem `LogsActivity`: mesma filosofia de `AdmanProbeMargemPrevLeitura` — é o
 * próprio registro de diagnóstico, não um modelo de domínio auditável.
 */
class AdmanProbeMargemPrevVeredito extends Model
{
    // ─── Constantes de status ─────────────────────────────────────────────────

    public const VEREDITO_APROVADO               = 'aprovado';
    public const VEREDITO_REPROVADO               = 'reprovado';
    public const VEREDITO_INSTRUMENTACAO_SUSPEITA  = 'instrumentacao_suspeita';

    // ─── Campos preenchíveis ──────────────────────────────────────────────────

    protected $fillable = [
        'periodo_key',
        'gerado_em',
        'total_leituras',
        'total_empresas',
        'total_rodadas',
        'cobertura_prev',
        'empresas_com_flip_count',
        'empresas_com_flip',
        'veredito',
        'motivos',
    ];

    // ─── Casts de tipos ───────────────────────────────────────────────────────

    protected $casts = [
        'gerado_em'         => 'datetime',
        'cobertura_prev'    => 'float',
        'empresas_com_flip' => 'array',
        'motivos'           => 'array',
    ];
}
