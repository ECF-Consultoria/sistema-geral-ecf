<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resposta a 1 pergunta customizada dentro de 1 NpsResponse — Phase 33 D-01.
 *
 * Cada linha congela o texto e o tipo da pergunta no momento da resposta
 * (pergunta_texto_snapshot, tipo_snapshot) — defesa contra edicao da pergunta
 * depois que a resposta ja foi dada, e permite hard-delete da pergunta sem
 * perder o historico.
 *
 * O campo `valor` armazena string formatada conforme tipo:
 *  - escala_1_5: "1".."5"
 *  - texto:      string livre (max 2000 chars validado no controller)
 *  - sim_nao:    "sim" | "nao"
 *  - multipla:   texto da opcao escolhida (nao o indice — facilita display direto)
 *
 * @property int $response_id
 * @property int|null $pergunta_id
 * @property string $pergunta_texto_snapshot
 * @property string $tipo_snapshot
 * @property string $valor
 */
class NpsRespostaCustomizada extends Model
{
    protected $table = 'nps_respostas_customizadas';

    protected $fillable = [
        'response_id',
        'pergunta_id',
        'pergunta_texto_snapshot',
        'tipo_snapshot',
        'valor',
    ];

    /**
     * Pergunta original (pode ser null se a pergunta foi hard-deleted).
     * O display deve usar `pergunta_texto_snapshot` como fonte canonica do texto.
     */
    public function pergunta(): BelongsTo
    {
        return $this->belongsTo(NpsPerguntaCustomizada::class, 'pergunta_id');
    }

    /**
     * Resposta principal NPS (3 scores fixos + comentario + nome respondente).
     */
    public function response(): BelongsTo
    {
        return $this->belongsTo(NpsResponse::class, 'response_id');
    }
}
