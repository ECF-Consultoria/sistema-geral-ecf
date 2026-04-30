<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ppa extends Model
{
    protected $fillable = [
        'company_id', 'mentor_id', 'title', 'description', 'actions',
        'status', 'trello_board_url', 'workspace_token', 'due_date', 'sent_at', 'completed_at',
    ];

    protected $casts = [
        'actions' => 'array',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function mentor() { return $this->belongsTo(User::class, 'mentor_id'); }
    public function tasks() { return $this->hasMany(PpaTask::class)->orderBy('order'); }
}
