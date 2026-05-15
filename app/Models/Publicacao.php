<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Publicacao extends Model
{
    use LogsActivity;

    protected $table = 'mlb_publicacoes';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'empresa', 'mlb_empresa_id', 'tipo', 'mlb_code', 'sku_stage', 'vendido', 'revisado', 'problema', 'problema_nota', 'comentario'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Publicação MLB cadastrada',
                'updated' => 'Publicação MLB atualizada',
                'deleted' => 'Publicação MLB excluída',
                default   => $eventName,
            });
    }


    protected $fillable = [
        'data',
        'user_id',
        'empresa',
        'cust_id',
        'mlb_code',
        'mlb_empresa_id',
        'tipo',
        'sku_stage',
        'sku_position',
        'vendido',
        'vendas_qty',
        'revisado',
        'problema',
        'problema_nota',
        'problema_em',
        'comentario',
        'comentario_autor_id',
        'comentario_em',
        'comentario_resolvido',
    ];

    protected $casts = [
        'data'                 => 'date',
        'vendido'              => 'boolean',
        'vendas_qty'           => 'integer',
        'revisado'             => 'boolean',
        'problema'             => 'boolean',
        'problema_em'          => 'datetime',
        'comentario_em'        => 'datetime',
        'comentario_resolvido' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function comentarioAutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comentario_autor_id')->withTrashed();
    }
}
