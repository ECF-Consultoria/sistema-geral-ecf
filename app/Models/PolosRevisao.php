<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma revisão feita na fila quinzenal dos Polos (TKT-0027, DEV-53): empresa, quem, quando e,
 * se houve texto, o comentário gravado no Faturamento Polos junto.
 *
 * No máximo uma por empresa por dia (`data`, dia em Brasília). Decisões de schema no
 * docblock da migration 2026_10_27_100100_create_polos_revisoes_table.
 */
class PolosRevisao extends Model
{
    protected $table = 'polos_revisoes';

    protected $fillable = ['mlb_empresa_id', 'user_id', 'data', 'revisado_em', 'polos_comentario_id'];

    protected $casts = [
        'data'        => 'date',
        'revisado_em' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(MlbEmpresa::class, 'mlb_empresa_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comentario(): BelongsTo
    {
        return $this->belongsTo(PolosComentario::class, 'polos_comentario_id');
    }
}
