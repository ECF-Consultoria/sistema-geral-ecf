<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 202 (REN-02) — evento de auditoria de um ciclo de renovação.
 */
class ContratoRenovacaoEvento extends Model
{
    protected $table = 'contrato_renovacao_eventos';

    protected $fillable = [
        'contrato_renovacao_id',
        'tipo',
        'origem',
        'decisao',
        'simulacao',
        'user_id',
        'dados',
    ];

    protected $casts = [
        'dados'     => 'array',
        'simulacao' => 'boolean',
    ];

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(ContratoRenovacao::class, 'contrato_renovacao_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
