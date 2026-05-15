<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MlbTreinamento extends Model
{
    use LogsActivity;

    protected $table = 'mlb_treinamentos';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['titulo', 'descricao', 'url_video', 'ordem', 'ativo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Treinamento MLB criado',
                'updated' => 'Treinamento MLB atualizado',
                'deleted' => 'Treinamento MLB excluído',
                default   => $eventName,
            });
    }


    protected $fillable = [
        'titulo', 'descricao', 'url_video',
        'ordem', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo'  => 'boolean',
            'ordem'  => 'integer',
        ];
    }
}
