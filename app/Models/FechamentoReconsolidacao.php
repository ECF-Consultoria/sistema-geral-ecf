<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoria de reconsolidação de uma competência do fechamento (Fase 137,
 * D-12 revisado). 1 linha por reconsolidação, gravada ANTES de o writer
 * sobrescrever `fechamento_snapshots` / `fechamento_grupo_snapshots` —
 * preserva quem fez, quando, por quê e o payload anterior completo.
 *
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-02-PLAN.md
 */
class FechamentoReconsolidacao extends Model
{
    // Nome explícito: o pluralizador do Eloquent não conhece pt-BR e
    // geraria 'fechamento_reconsolidacaos' a partir do nome da classe.
    protected $table = 'fechamento_reconsolidacoes';

    protected $fillable = [
        'mes_referencia',
        'reconsolidado_por',
        'motivo',
        'snapshot_anterior',
        'origem',
    ];

    protected $casts = [
        'mes_referencia'    => 'date',
        'snapshot_anterior' => 'array',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconsolidado_por');
    }
}
