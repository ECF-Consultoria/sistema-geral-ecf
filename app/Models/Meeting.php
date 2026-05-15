<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Meeting extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'scheduled_at', 'status', 'consultant_present', 'mentor_present', 'client_present'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'Reunião criada',
                'updated' => 'Reunião atualizada',
                'deleted' => 'Reunião excluída',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'company_id', 'scheduled_at', 'consultant_present', 'mentor_present',
        'client_present', 'status', 'notes', 'created_by',
        'google_event_id', 'google_calendar_owner',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'consultant_present' => 'boolean',
        'mentor_present' => 'boolean',
        'client_present' => 'boolean',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
