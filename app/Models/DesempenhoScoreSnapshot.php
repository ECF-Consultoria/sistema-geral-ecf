<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 46 — snapshot diário do score de desempenho de um analista/estrategista.
 *
 * Gravado pelo comando `desempenho:snapshot-scores` (cascata D-1 às 13:30 BRT)
 * via updateOrCreate(['user_id','ref_date']). 1 linha/user/dia.
 *
 * O comando faz 2 passos:
 *   1. Para cada user elegível: persiste score/classificacao/breakdown_json/etc
 *   2. Após o lote: popula ranking_pos da data via ORDER BY score DESC
 *
 * Wave 2 (Plan 46-02) lê desta tabela pra:
 *   - delta_vs_ontem / delta_vs_semana_passada no ranking de /performance
 *   - gráfico de evolução individual do score nos últimos N dias
 */
class DesempenhoScoreSnapshot extends Model
{
    protected $table = 'desempenho_score_snapshots';

    protected $fillable = [
        'user_id',
        'ref_date',
        'score',
        'classificacao',
        'ranking_pos',
        'tem_base_comparativa',
        'empresas_carteira',
        'empresas_eligiveis',
        'breakdown_json',
    ];

    protected $casts = [
        'ref_date'             => 'date',
        'breakdown_json'       => 'array',
        'tem_base_comparativa' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
