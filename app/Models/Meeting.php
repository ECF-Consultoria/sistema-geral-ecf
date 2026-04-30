<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
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
