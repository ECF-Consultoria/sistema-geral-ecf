<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opção de resposta de uma NpsTemplateQuestion — Phase 68 v15.0.
 *
 * Cada opção tem um `label` visível ao cliente (radio group do form público)
 * e um `peso` numérico 1..5. O peso é a base do cálculo NPS: o
 * `NpsScoreCalculator::compute()` (Phase 69, NPS-B-02) faz
 * `AVG(option_peso_snapshot)` sobre `nps_response_answers` — o snapshot
 * congela o peso vigente no momento da resposta (research §5).
 *
 * Perguntas do tipo `escala` recebem auto-geração de 5 opções (labels
 * `"1"…"5"`, pesos `1..5`) via CRUD admin (Phase 70). Perguntas do tipo
 * `opcoes` deixam o admin definir labels/pesos livres, respeitando o
 * limite 1..5 do peso (UI bloqueia > 5 até nova REQ).
 *
 * Referências:
 *  - .planning/research/v15-nps-templates-schema.md §5 (peso 1..5 hardcoded)
 *  - .planning/research/v15-nps-templates-schema.md §1 (option_peso_snapshot)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 *
 * @property int $question_id
 * @property string $label
 * @property int $peso
 * @property int $ordem
 */
class NpsTemplateOption extends Model
{
    use HasFactory;

    protected $table = 'nps_template_options';

    protected $fillable = [
        'question_id',
        'label',
        'peso',
        'ordem',
    ];

    protected $casts = [
        'peso'  => 'int',
        'ordem' => 'int',
    ];

    /**
     * Pergunta dona desta opção. Cascade on delete — apagar a pergunta
     * apaga a opção junto.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(NpsTemplateQuestion::class, 'question_id');
    }
}
