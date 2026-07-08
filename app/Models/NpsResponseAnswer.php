<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot per-row de 1 resposta dentro de 1 NpsResponse — Phase 68 v15.0.
 *
 * Cada linha congela o texto/dimensão da pergunta e o label/peso da opção
 * escolhida no momento da submissão (research §1 — replica padrão de
 * `NpsRespostaCustomizada`). Isso protege o histórico contra edições
 * futuras do template: dashboard NPS-E-05 e `NpsScoreCalculator::compute()`
 * leem SEMPRE do snapshot, nunca das FKs vivas.
 *
 * As FKs `template_question_id` e `template_option_id` são nullable com
 * `nullOnDelete` — servem para navegação/audit (drill-down até a pergunta
 * original) mas hard-delete do template não corrompe o histórico. Snapshot
 * é a fonte de verdade; FK viva é opcional.
 *
 * Comparativo com o padrão análogo Phase 33 (`NpsRespostaCustomizada`):
 *  - Snapshot dobrado (texto+dimensao+label+peso, não só texto+tipo)
 *  - `option_peso_snapshot` é a base do AVG do NpsScoreCalculator (NPS-B-02)
 *  - Índice composto `(response_id, question_dimensao_snapshot)` acelera GROUP BY
 *
 * Referências:
 *  - .planning/research/v15-nps-templates-schema.md §1 (snapshot per-row)
 *  - .planning/research/v15-nps-templates-schema.md §5 (peso 1..5 alimentado por option_peso_snapshot)
 *  - database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php
 *
 * @property int $response_id
 * @property int|null $template_question_id
 * @property int|null $template_option_id
 * @property string $question_texto_snapshot
 * @property string $question_dimensao_snapshot
 * @property string $option_label_snapshot
 * @property int $option_peso_snapshot
 * @property string|null $comentario
 */
class NpsResponseAnswer extends Model
{
    use HasFactory;

    protected $table = 'nps_response_answers';

    protected $fillable = [
        'response_id',
        'template_question_id',
        'template_option_id',
        'question_texto_snapshot',
        'question_dimensao_snapshot',
        'option_label_snapshot',
        'option_peso_snapshot',
        'comentario',
    ];

    protected $casts = [
        'option_peso_snapshot' => 'int',
    ];

    /**
     * Resposta principal NPS dona desta linha. Cascade on delete — apagar
     * a NpsResponse apaga todas as answers.
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'response_id');
    }

    /**
     * Pergunta original (pode ser NULL se hard-deleted). O display deve
     * usar `question_texto_snapshot` como fonte canônica.
     */
    public function templateQuestion(): BelongsTo
    {
        return $this->belongsTo(NpsTemplateQuestion::class, 'template_question_id');
    }

    /**
     * Opção original (pode ser NULL se hard-deleted). O cálculo NPS deve
     * usar `option_peso_snapshot` como fonte canônica.
     */
    public function templateOption(): BelongsTo
    {
        return $this->belongsTo(NpsTemplateOption::class, 'template_option_id');
    }
}
