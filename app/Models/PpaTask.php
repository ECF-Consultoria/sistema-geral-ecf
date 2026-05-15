<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PpaTask extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['ppa_id', 'title', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Tarefa PPA criada',
                'updated' => 'Tarefa PPA atualizada',
                'deleted' => 'Tarefa PPA excluída',
                default   => $eventName,
            });
    }

    protected $fillable = ['ppa_id', 'title', 'description', 'status', 'order', 'created_by'];

    public function ppa()  { return $this->belongsTo(Ppa::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
