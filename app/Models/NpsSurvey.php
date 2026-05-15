<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class NpsSurvey extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'generated_by', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => match($eventName) {
                'created' => 'NPS gerado',
                'updated' => 'NPS atualizado',
                'deleted' => 'NPS excluído',
                default   => $eventName,
            });
    }

    protected $fillable = [
        'token', 'company_id', 'generated_by', 'expires_at', 'completed_at', 'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function generatedBy() { return $this->belongsTo(User::class, 'generated_by'); }
    public function response() { return $this->hasOne(NpsResponse::class, 'survey_id'); }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->status === 'pending';
    }
}
