<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpsResponse extends Model
{
    protected $fillable = [
        'survey_id', 'respondent_name', 'score_consultant', 'score_mentor', 'score_overall', 'comment',
    ];

    protected $casts = [
        'score_consultant' => 'integer',
        'score_mentor' => 'integer',
        'score_overall' => 'integer',
    ];

    public function survey() { return $this->belongsTo(NpsSurvey::class); }

    public function getNpsCategory(int $score): string
    {
        if ($score >= 9) return 'promotor';
        if ($score >= 7) return 'neutro';
        return 'detrator';
    }
}
