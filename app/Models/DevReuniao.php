<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Reunião do time de desenvolvimento — gravação, transcrição e decisões,
 * ligada às demandas que ela criou ou alterou.
 */
class DevReuniao extends Model
{
    protected $table = 'dev_reunioes';

    protected $fillable = [
        'data', 'titulo', 'participantes', 'link_gravacao', 'link_transcricao',
        'decisoes', 'duracao', 'criado_por',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function demandas(): BelongsToMany
    {
        return $this->belongsToMany(DevDemanda::class, 'dev_demanda_reuniao', 'dev_reuniao_id', 'dev_demanda_id');
    }
}
