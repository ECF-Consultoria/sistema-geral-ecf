<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dia do ciclo quinzenal em que uma empresa dos Polos é revisada (TKT-0027, DEV-53).
 *
 * Uma linha por empresa; sem linha = "sem agenda". `dia_ciclo` 0–9 (0 = Seg A … 9 = Sex B).
 * Decisões de schema no docblock da migration 2026_10_27_100000_create_polos_agenda_revisao_table.
 */
class PolosAgendaRevisao extends Model
{
    protected $table = 'polos_agenda_revisao';

    protected $fillable = ['mlb_empresa_id', 'dia_ciclo', 'definido_por'];

    protected $casts = [
        'dia_ciclo' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function definidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'definido_por');
    }
}
