<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resposta NPS na escala 1-5 com 3 dimensões (estrategista, analista, empresa)
 * — Phase 31 D-06/D-07.
 *
 * Substitui o antigo schema 0-10 com colunas legacy (consultant/mentor/overall). A
 * categorização promotor/neutro/detrator foi removida porque a escala 1-5
 * não tem semântica NPS clássica; o widget Dashboard será reescrito no
 * Plan 31-05 com nova lógica (provavelmente mapeando `score_empresa`).
 */
class NpsResponse extends Model
{
    protected $fillable = [
        'survey_id',
        'respondent_name',
        'score_estrategista',
        'score_analista',
        'score_empresa',
        'comment',
    ];

    protected $casts = [
        'score_estrategista' => 'integer',
        'score_analista'     => 'integer',
        'score_empresa'      => 'integer',
    ];

    public function survey()
    {
        return $this->belongsTo(NpsSurvey::class);
    }

    /**
     * Respostas das perguntas customizadas (Phase 33). 1 NpsResponse pode ter N
     * respostas extras, conforme as perguntas ativas no momento da submissao.
     */
    public function respostasCustomizadas()
    {
        return $this->hasMany(NpsRespostaCustomizada::class, 'response_id');
    }
}
