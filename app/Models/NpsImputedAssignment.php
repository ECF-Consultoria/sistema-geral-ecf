<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha CONGELADA da regra "NPS não respondido conta como nota mínima (1)" —
 * Fase 116 Plan 01.
 *
 * Grão: survey × serviço × dimensão × responsável. Cada linha representa uma
 * dimensão (estrategista/analista/empresa) de um survey que NÃO foi
 * respondido — a nota é sempre 1.00 (D4, piso da escala real 1..5).
 *
 * Model apenas de CONTRATO (schema + relations) para o `NpsImputationService`
 * (única fonte de escrita — NUNCA criar/editar linha fora dele). Sem
 * LogsActivity: snapshot append-only, não editável por humano.
 *
 * `definitivo` NUNCA é apagado nem reescrito (D2/NPSFLOOR-07) — uma resposta
 * que chegue depois do fechamento da competência não reescreve a nota daquela
 * competência. `provisorio` conta na leitura desde o disparo (D2 "vale desde
 * o disparo") — é o que faz o snapshot mensal do bônus enxergar o não
 * respondido de um mês ainda em curso.
 *
 * Desde 26/08/2026 a linha pode nascer de um link de NPS de GRUPO ainda
 * pendente, e não só de um survey individual: `survey_id` e `group_survey_id`
 * são MUTUAMENTE EXCLUSIVOS (exatamente um dos dois é preenchido). O link de
 * grupo só vira `nps_surveys` quando o cliente responde, então sem essa
 * segunda âncora as empresas cobertas ficavam sem o piso de 1 que qualquer
 * link individual já produzia no instante do disparo.
 *
 * @property int|null $survey_id NULL quando a linha vem de um link de GRUPO
 * @property int|null $group_survey_id NULL quando a linha vem de um survey individual
 * @property int $company_id
 * @property int|null $servico_id
 * @property string|null $service_setor
 * @property string $dimensao estrategista|analista|empresa
 * @property string|null $role role da pivot company_users; NULL na dimensão empresa
 * @property int|null $user_id NULL na dimensão empresa (D7 — linha própria, sem responsável)
 * @property \Illuminate\Support\Carbon $competencia_nps sempre startOfMonth
 * @property float $nota sempre 1.00 (D4)
 * @property string $status provisorio|definitivo — ESTADO GRAVADO, não calculado
 * @property \Illuminate\Support\Carbon|null $locked_at preenchido quando vira definitivo
 */
class NpsImputedAssignment extends Model
{
    use HasFactory;

    protected $table = 'nps_imputed_assignments';

    public const STATUS_PROVISORIO = 'provisorio';
    public const STATUS_DEFINITIVO = 'definitivo';

    protected $fillable = [
        'survey_id',
        'group_survey_id',
        'company_id',
        'servico_id',
        'service_setor',
        'dimensao',
        'role',
        'user_id',
        'competencia_nps',
        'nota',
        'status',
        'locked_at',
    ];

    protected $casts = [
        'nota'            => 'float',
        'competencia_nps' => 'date',
        'locked_at'       => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function groupSurvey(): BelongsTo
    {
        return $this->belongsTo(NpsGroupSurvey::class, 'group_survey_id');
    }

    /**
     * Chave de dedupe da leitura. Para linha de survey individual é o próprio
     * `survey_id` (1 survey = 1 empresa, régua original da Fase 116). Para
     * linha de GRUPO o `survey_id` é NULL e um único link cobre N empresas —
     * usar `survey_id` ali colapsaria todas as empresas do grupo numa nota só.
     */
    public function chaveDeDedupe(): string
    {
        return $this->survey_id !== null
            ? 's' . $this->survey_id
            : 'g' . $this->group_survey_id . '-' . $this->company_id;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(Servico::class, 'servico_id');
    }

    /**
     * Blindagem de leitura (D2/NPSFLOOR-07): retorna linhas `definitivo` OU
     * `provisorio` cujo survey relacionado AINDA NÃO está `completed`.
     *
     * Se a materialização atrasar e sobrar uma linha provisória de um survey
     * já respondido (janela entre a resposta chegar e o próximo
     * `materializarLote` rodar), este scope garante que nenhuma tela conte a
     * nota 1 indevidamente — a limpeza definitiva acontece no próximo
     * `materializar()`, mas a leitura já fica protegida antes disso.
     */
    public function scopeVigentes($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_DEFINITIVO)
                ->orWhere(function ($qq) {
                    // A âncora provisória é o survey individual OU o link de
                    // grupo — nunca os dois. Testar só `survey` descartaria
                    // toda linha de grupo da leitura (ela tem `survey_id`
                    // NULL, e `whereHas` num NULL é sempre falso), que é
                    // exatamente o piso que esta régua precisa enxergar.
                    $qq->where('status', self::STATUS_PROVISORIO)
                        ->where(function ($ancora) {
                            $ancora->whereHas('survey', fn ($s) => $s->where('status', '!=', 'completed'))
                                ->orWhereHas('groupSurvey', fn ($s) => $s->where('status', '!=', 'completed'));
                        });
                });
        });
    }
}
